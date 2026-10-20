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
            'url' => $request->url(),
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
        $rebuilt .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $rebuilt .= '?'.http_build_query(self::scrub($query));
        }

        return $rebuilt.(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
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
