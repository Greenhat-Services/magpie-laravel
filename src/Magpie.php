<?php

namespace Magpie\Laravel;

use Closure;
use Composer\InstalledVersions;
use Magpie\Laravel\Tracing\TraceHeader;
use Magpie\Laravel\Transport\Transport;
use Throwable;
use WeakMap;

/**
 * The SDK client: builds events, applies the scope and options, and hands them to the transport.
 * Events are buffered and delivered by flush(), which the service provider calls when the request,
 * job or command finishes.
 */
class Magpie
{
    public const PACKAGE = 'greenhat-services/magpie-laravel';

    /** Fallback when Composer's installed-package metadata is unavailable, for example a bare path checkout. */
    public const VERSION = 'dev';

    /** The installed version as Composer reports it, without the leading "v". */
    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            return ltrim((string) InstalledVersions::getPrettyVersion(self::PACKAGE), 'v');
        }

        return self::VERSION;
    }

    public const LEVELS = ['debug', 'info', 'warning', 'error', 'fatal'];

    private Scope $scope;

    /** @var list<Scope> */
    private array $scopeStack = [];

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var WeakMap<Throwable, string> */
    private WeakMap $reported;

    /** @var array<string, mixed>|null */
    private ?array $transaction = null;

    /** The outcome so far of the request being counted as a session, or null when none is. */
    private ?string $session = null;

    /**
     * @param  array<string, mixed>  $options  The config/magpie.php array.
     * @param  Closure(): array<string, mixed>|null  $contextResolver  Returns request, user and contexts to merge into each event.
     * @param  Sessions|null  $sessions  Counts requests for crash-free session reporting. Null disables it.
     */
    public function __construct(
        private readonly array $options,
        private readonly ?Transport $transport,
        private readonly ?Closure $contextResolver = null,
        private readonly ?Sessions $sessions = null,
    ) {
        $this->scope = new Scope;
        $this->reported = new WeakMap;
    }

    public function enabled(): bool
    {
        return $this->transport !== null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    /** @param  callable(Scope): mixed  $callback */
    public function configureScope(callable $callback): void
    {
        $callback($this->scope);
    }

    /**
     * Run the callback with a temporary copy of the scope, discarded afterwards.
     *
     * @template T
     *
     * @param  callable(Scope): T  $callback
     * @return T
     */
    public function withScope(callable $callback): mixed
    {
        $this->scopeStack[] = $this->scope;
        $this->scope = clone $this->scope;

        try {
            return $callback($this->scope);
        } finally {
            $this->scope = array_pop($this->scopeStack);
        }
    }

    /**
     * Forget everything bound to the previous unit of work: the scope (tags, user, breadcrumbs)
     * and any unfinished transaction. Long-lived processes call this before each request or job
     * so data from one request never leaks into the next.
     */
    public function reset(): void
    {
        $this->scope = new Scope;
        $this->scopeStack = [];
        $this->transaction = null;
        $this->session = null;
    }

    /** @param  array<string, mixed>  $breadcrumb  message, category, level, type, data */
    public function addBreadcrumb(array $breadcrumb): void
    {
        if ($this->enabled()) {
            $this->scope->addBreadcrumb($breadcrumb, (int) $this->option('breadcrumbs.max', 100));
        }
    }

    /**
     * @param  array<string, mixed>  $hint  Set `handled` to false for an uncaught exception, `mechanism` to name the source.
     * @return string|null The event id, or null when the event was dropped.
     */
    public function captureException(Throwable $exception, array $hint = []): ?string
    {
        if (! $this->enabled() || $this->isIgnored($exception)) {
            return null;
        }

        // The session counts the failure even when the event is sampled out or already reported.
        $this->markSession(($hint['handled'] ?? true) === false ? Sessions::CRASHED : Sessions::ERRORED);

        if (isset($this->reported[$exception]) || ! $this->sampled()) {
            return null;
        }

        $event = [
            'exception' => ['values' => $this->exceptionValues($exception, $hint)],
            'level' => $hint['level'] ?? 'error',
        ];

        $id = $this->queue($event, $hint + ['exception' => $exception]);

        if ($id !== null) {
            $this->reported[$exception] = $id;
        }

        return $id;
    }

    public function captureMessage(string $message, string $level = 'info'): ?string
    {
        if (! $this->enabled() || ! $this->sampled()) {
            return null;
        }

        return $this->queue(['message' => $message, 'level' => $level], ['message' => $message]);
    }

    /**
     * Deliver everything buffered so far.
     *
     * @return bool True when every payload was accepted.
     */
    public function flush(): bool
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $delivered = true;

        foreach ($buffer as $payload) {
            if ($this->transport === null || ! $this->transport->send($payload)) {
                $delivered = false;
            }
        }

        if ($this->transport !== null && $this->sessions !== null && ! $this->sessions->flush($this->transport)) {
            $delivered = false;
        }

        return $delivered;
    }

    // ------------------------------------------------------------------ sessions

    public function sessionsEnabled(): bool
    {
        return $this->enabled() && $this->sessions !== null;
    }

    /**
     * Begin counting a web request as a release-health session. Health checks and anything in
     * `ignore_transactions` are not counted. Returns false when the request is not a session.
     */
    public function startSession(string $name): bool
    {
        $this->session = null;

        if (! $this->enabled() || $this->sessions === null || $this->transactionIgnored($name)) {
            return false;
        }

        $this->session = Sessions::EXITED;

        return true;
    }

    /** Finish the session: it ended clean, with a handled error, or crashed by an uncaught exception. */
    public function endSession(): void
    {
        $outcome = $this->session;
        $this->session = null;

        if ($outcome !== null) {
            $this->sessions?->record($outcome);
        }
    }

    /** An error can only make a session worse: clean, then errored, then crashed. */
    private function markSession(string $outcome): void
    {
        if ($this->session === null || $this->session === Sessions::CRASHED) {
            return;
        }

        if ($outcome === Sessions::CRASHED || $this->session === Sessions::EXITED) {
            $this->session = $outcome;
        }
    }

    // ------------------------------------------------------------------ performance

    /**
     * Begin tracing a request, job or command. Returns false when it is not sampled.
     *
     * @param  array{trace_id: string, span_id: string, sampled: bool|null}|null  $parent  An incoming trace to continue, from TraceHeader::parse().
     */
    public function startTransaction(string $name, string $op = 'http.server', ?array $parent = null): bool
    {
        $rate = (float) ($this->option('traces_sample_rate') ?? 0);
        $this->transaction = null;

        if (! $this->enabled() || $rate <= 0 || $this->transactionIgnored($name)) {
            return false;
        }

        // An upstream sampling decision is inherited so a trace is either complete or absent.
        $sampled = $parent['sampled'] ?? ($rate >= 1 || mt_rand() / mt_getrandmax() <= $rate);

        if (! $sampled) {
            return false;
        }

        $this->transaction = [
            'name' => $name,
            'op' => $op,
            'start' => microtime(true),
            'trace_id' => $parent['trace_id'] ?? bin2hex(random_bytes(16)),
            'span_id' => bin2hex(random_bytes(8)),
            'parent_span_id' => $parent['span_id'] ?? null,
            'spans' => [],
            'dropped_spans' => 0,
        ];

        return true;
    }

    public function tracing(): bool
    {
        return $this->transaction !== null;
    }

    public function renameTransaction(string $name): void
    {
        if ($this->transaction !== null) {
            $this->transaction['name'] = $name;
        }
    }

    /** The `magpie-trace` header value for outgoing requests and jobs, or null when nothing is being traced. */
    public function traceHeader(): ?string
    {
        if ($this->transaction === null) {
            return null;
        }

        return TraceHeader::format($this->transaction['trace_id'], $this->transaction['span_id'], true);
    }

    /**
     * Record a finished child span, for example a database query. Times are Unix seconds.
     *
     * @param  array<string, mixed>  $data
     */
    public function addSpan(string $op, string $description, float $start, float $durationSeconds, array $data = []): void
    {
        if ($this->transaction === null) {
            return;
        }

        if (count($this->transaction['spans']) >= max(1, (int) $this->option('limits.spans', 100))) {
            $this->transaction['dropped_spans']++;

            return;
        }

        $this->transaction['spans'][] = array_filter([
            'span_id' => bin2hex(random_bytes(8)),
            'parent_span_id' => $this->transaction['span_id'],
            'op' => $op,
            'description' => Payload::truncate($description, $this->maxStringLength()),
            'start_timestamp' => $start,
            'timestamp' => $start + $durationSeconds,
            'data' => $data,
        ], fn ($v) => $v !== []);
    }

    /** @param  array<string, mixed>  $data  For example `status` and `http.status_code`. */
    public function finishTransaction(array $data = []): ?string
    {
        $t = $this->transaction;
        $this->transaction = null;

        if ($t === null || $this->transactionIgnored($t['name'])) {
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $base = $this->base($id);
        $trace = array_filter([
            'trace_id' => $t['trace_id'],
            'span_id' => $t['span_id'],
            'parent_span_id' => $t['parent_span_id'],
            'op' => $t['op'],
            'dropped_spans' => $t['dropped_spans'] ?: null,
        ], fn ($v) => $v !== null) + $data;

        $payload = [
            'type' => 'transaction',
            'transaction' => $t['name'],
            'start_timestamp' => $t['start'],
            'timestamp' => microtime(true),
            'contexts' => $base['contexts'] + ['trace' => $trace],
            'spans' => $t['spans'],
        ] + $base;

        $payload = $this->scope->applyTo($this->withContext($payload));
        unset($payload['breadcrumbs']);

        $this->buffer[] = Payload::limit($payload, $this->maxStringLength());

        return $id;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $hint
     */
    private function queue(array $event, array $hint): ?string
    {
        $id = bin2hex(random_bytes(16));

        // An error raised inside a traced request, job or command links to that trace.
        if ($this->transaction !== null) {
            $event['transaction'] ??= $this->transaction['name'];
            $event['contexts']['trace'] ??= ['trace_id' => $this->transaction['trace_id'], 'span_id' => $this->transaction['span_id']];
        }

        // The trace context above must not replace the runtime and OS contexts, so contexts are merged, not added.
        $base = $this->base($id);
        $event['contexts'] = ($event['contexts'] ?? []) + $base['contexts'];

        $payload = $this->scope->applyTo($this->withContext($event + $base));

        if (is_callable($before = $this->option('before_send'))) {
            $payload = $before($payload, $hint);

            if (! is_array($payload)) {
                return null;
            }
        }

        $this->buffer[] = Payload::limit($payload, $this->maxStringLength());

        return $id;
    }

    /** @return array<string, mixed> */
    private function base(string $id): array
    {
        return array_filter([
            'event_id' => $id,
            'timestamp' => microtime(true),
            'platform' => 'php',
            'environment' => $this->option('environment'),
            'release' => $this->option('release'),
            'server_name' => $this->option('server_name'),
            'sdk' => ['name' => 'magpie.php.laravel', 'version' => self::version()],
            'contexts' => [
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
                'os' => ['name' => PHP_OS_FAMILY],
            ],
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withContext(array $payload): array
    {
        $context = $this->resolveContext();

        $payload['contexts'] = array_merge($payload['contexts'] ?? [], $context['contexts'] ?? []);
        unset($context['contexts']);

        return $payload + $context;
    }

    /** @return array<string, mixed> */
    private function resolveContext(): array
    {
        if ($this->contextResolver === null) {
            return [];
        }

        try {
            return ($this->contextResolver)();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Oldest cause first, so the last entry is the exception that was thrown.
     *
     * @param  array<string, mixed>  $hint
     * @return list<array<string, mixed>>
     */
    private function exceptionValues(Throwable $exception, array $hint): array
    {
        $stacktrace = new Stacktrace(
            (array) $this->option('in_app_include', []),
            (array) $this->option('in_app_exclude', []),
            (int) $this->option('context_lines', 5),
            (int) $this->option('limits.frames', 100),
        );

        $values = [];

        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $values[] = [
                'type' => $e::class,
                'value' => $e->getMessage(),
                'mechanism' => ['type' => $hint['mechanism'] ?? 'generic', 'handled' => $hint['handled'] ?? true],
                'stacktrace' => ['frames' => $stacktrace->frames($e)],
            ];
        }

        return array_reverse($values);
    }

    private function isIgnored(Throwable $exception): bool
    {
        foreach ((array) $this->option('ignore_exceptions', []) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function transactionIgnored(string $name): bool
    {
        return in_array($name, (array) $this->option('ignore_transactions', []), true);
    }

    private function sampled(): bool
    {
        $rate = (float) $this->option('sample_rate', 1.0);

        return $rate >= 1 || ($rate > 0 && mt_rand() / mt_getrandmax() <= $rate);
    }

    private function maxStringLength(): int
    {
        return (int) $this->option('limits.string_length', 8192);
    }
}
