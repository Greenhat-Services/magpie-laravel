<?php

namespace Magpie\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

/**
 * Request, user and framework details merged into every event.
 */
final class RequestContext
{
    /** Headers whose values are replaced with [Filtered] on every event. */
    private const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'php-auth-user', 'php-auth-pw', 'php-auth-digest',
        'x-xsrf-token', 'x-csrf-token', 'x-api-key', 'x-auth-token', 'x-access-token', 'x-refresh-token',
    ];

    /** Header names matching this are filtered as well, so custom auth headers are covered. */
    private const SENSITIVE_HEADER_PATTERN = '/(auth|token|secret|session|api[_-]?key|signature|credential|password)/i';

    /** Headers that identify the client; only sent with send_default_pii. */
    private const IP_HEADERS = [
        'x-forwarded-for', 'x-real-ip', 'forwarded', 'forwarded-for', 'x-forwarded',
        'cf-connecting-ip', 'true-client-ip', 'x-client-ip', 'x-cluster-client-ip', 'fastly-client-ip',
    ];

    /** Query and log-context keys whose values are replaced with [Filtered]. */
    private const SENSITIVE_PARAMS = '/(pass(word|wd)?|secret|token|api[_-]?key|credential|session|csrf|xsrf|otp|cvv|ssn|signature|private[_-]?key)|^(auth|authorization|sig|pin|code|key|card(_?number)?|credit_?card|cc)$/i';

    /** Path segments after one of these words are single-use secrets (reset links, invitations, signed URLs). */
    private const SECRET_SEGMENT_PREFIX = '/^(token|reset|verify|confirm|invite|invitation|activate|password|signed|unsubscribe|magic|login|auth)s?$/i';

    /** SQL that touches credential-like columns; its bindings are never sent. */
    private const SENSITIVE_SQL = '/(pass(word|wd)?|secret|token|api[_-]?key|credential|session|otp|cvv|ssn|signature|private[_-]?key|card)/i';

    /** @return array<string, mixed> */
    public static function resolve(Application $app, bool $pii): array
    {
        $context = ['contexts' => ['laravel' => ['version' => $app->version()]]];

        // Artisan commands have no meaningful request; the test runner does.
        if (($app->runningInConsole() && ! $app->runningUnitTests()) || ! $app->bound('request')) {
            return $context;
        }

        $request = $app->make('request');

        if (! $request instanceof Request) {
            return $context;
        }

        $context['request'] = self::request($request, $pii);

        if ($pii && ($user = self::user($app, $request))) {
            $context['user'] = $user;
        }

        return $context;
    }

    /** @return array<string, mixed> */
    public static function request(Request $request, bool $pii): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = (string) $name;

            if (in_array($name, self::SENSITIVE_HEADERS, true) || preg_match(self::SENSITIVE_HEADER_PATTERN, $name)) {
                $headers[$name] = '[Filtered]';
            } elseif ($pii || ! in_array($name, self::IP_HEADERS, true)) {
                $headers[$name] = implode(', ', (array) $values);
            }
        }

        if (isset($headers['referer']) && $headers['referer'] !== '[Filtered]') {
            $headers['referer'] = self::scrubUrl($headers['referer']);
        }

        parse_str($request->getQueryString() ?? '', $query);

        return array_filter([
            'url' => self::scrubUrl($request->url()),
            'method' => $request->method(),
            'query_string' => $query ? http_build_query(self::scrub($query)) : null,
            'headers' => $headers,
            'env' => $pii ? ['REMOTE_ADDR' => $request->ip()] : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Replace the values of sensitive keys (passwords, tokens, card numbers) at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            } elseif (is_string($key) && preg_match(self::SENSITIVE_PARAMS, $key)) {
                $data[$key] = '[Filtered]';
            }
        }

        return $data;
    }

    /** Remove credentials and sensitive query parameters from a URL. */
    public static function scrubUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $rebuilt = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $rebuilt .= isset($parts['user']) ? '[Filtered]@' : '';
        $rebuilt .= $parts['host'] ?? '';
        $rebuilt .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $rebuilt .= isset($parts['path']) ? self::scrubPath($parts['path']) : '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $rebuilt .= '?'.http_build_query(self::scrub($query));
        }

        return $rebuilt.(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /** Replace path segments that look like secrets: long opaque strings, or whatever follows `reset`, `token`, `invite` and similar. */
    public static function scrubPath(string $path): string
    {
        $segments = explode('/', $path);

        foreach ($segments as $i => $segment) {
            $afterSecretWord = $i > 0 && preg_match(self::SECRET_SEGMENT_PREFIX, $segments[$i - 1]) && ! ctype_digit($segment) && ! preg_match(self::SECRET_SEGMENT_PREFIX, $segment);

            if ($segment !== '' && ($afterSecretWord || (preg_match('/^[A-Za-z0-9_-]{24,}$/', $segment) && preg_match('/\d/', $segment)))) {
                $segments[$i] = '[Filtered]';
            }
        }

        return implode('/', $segments);
    }

    /** Emails and credential-looking keys are replaced; everything else in an identifier is kept. */
    public static function scrubKey(string $key): string
    {
        if (preg_match(self::SENSITIVE_PARAMS, $key)) {
            return '[Filtered]';
        }

        return (string) preg_replace('/[^\s:|,]+@[^\s:|,]+/', '[Filtered]', $key);
    }

    /** Database errors quote the failing SQL with its values, and unique violations quote the duplicate value. */
    public static function scrubExceptionMessage(string $message): string
    {
        $message = (string) preg_replace('/\bSQL: .*(?=\)\s*$)/s', 'SQL: [Filtered]', $message);
        $message = (string) preg_replace("/Duplicate entry '.*?' for key/s", "Duplicate entry '[Filtered]' for key", $message);

        return (string) preg_replace('/Key \(([^)]*)\)=\(.*?\) (already exists|is not present)/s', 'Key ($1)=([Filtered]) $2', $message);
    }

    /**
     * Bindings are only safe to send for statements that do not touch credential-like columns.
     *
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>|string
     */
    public static function scrubBindings(string $sql, array $bindings): array|string
    {
        return preg_match(self::SENSITIVE_SQL, $sql) ? '[Filtered]' : $bindings;
    }

    /** @return array<string, mixed>|null */
    private static function user(Application $app, Request $request): ?array
    {
        try {
            $user = $request->user() ?? ($app->bound('auth') ? $app->make('auth')->user() : null);
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        return array_filter([
            'id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'username' => $user->username ?? $user->name ?? null,
            'ip_address' => $request->ip(),
        ], fn ($v) => $v !== null);
    }
}
