<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return [
    // The DSN shown under App settings > DSN keys. Leave empty to disable the SDK.
    'dsn' => env('MAGPIE_LARAVEL_DSN', env('MAGPIE_DSN')),

    'environment' => env('MAGPIE_ENVIRONMENT', env('APP_ENV', 'production')),

    // A version string so issues can be tied to a release. When empty, the git commit of the
    // deployed checkout is used if a .git directory exists.
    'release' => env('MAGPIE_RELEASE'),

    'server_name' => env('MAGPIE_SERVER_NAME', gethostname() ?: null),

    // Fraction (0.0 - 1.0) of error events to send.
    'sample_rate' => (float) env('MAGPIE_SAMPLE_RATE', 1.0),

    // Fraction of requests, jobs and commands to trace for performance monitoring. Null or 0 disables tracing.
    'traces_sample_rate' => env('MAGPIE_TRACES_SAMPLE_RATE') === null ? null : (float) env('MAGPIE_TRACES_SAMPLE_RATE'),

    // Count web requests as sessions so Magpie can show crash-free sessions and adoption per release. A request
    // that ends in an uncaught exception counts as crashed. Counts are kept in the cache and sent as one small
    // summary per minute, never one request per session. Needs a release (MAGPIE_RELEASE or a git checkout).
    // Use a cache store shared by all your servers, or each server reports its own share.
    'sessions' => [
        'enabled' => (bool) env('MAGPIE_SESSIONS', true),
        'store' => env('MAGPIE_SESSIONS_CACHE_STORE'),
    ],

    // Attach the authenticated user and client IP addresses. Credentials and session headers are always removed.
    'send_default_pii' => (bool) env('MAGPIE_SEND_DEFAULT_PII', false),

    // Exception classes that are never reported (subclasses included). Laravel's own "don't report"
    // list already covers the reportable() path; this list also covers the log channel and queue paths.
    'ignore_exceptions' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        HttpResponseException::class,
        ThrottleRequestsException::class,
        TokenMismatchException::class,
        ValidationException::class,
        HttpExceptionInterface::class,
        RequestExceptionInterface::class,
    ],

    // Transaction names (route URIs, job classes or command names) that are never traced.
    'ignore_transactions' => ['GET /up', 'HEAD /up'],

    // Paths counted as your own code ("in app") in stack traces.
    'in_app_include' => [app_path()],
    'in_app_exclude' => [base_path('vendor')],

    // Source lines captured around each frame. Set to 0 to send no source code.
    'context_lines' => 5,

    // Seconds to wait for Magpie before giving up on an event.
    'timeout' => (float) env('MAGPIE_TIMEOUT', 3),

    // Deliver events from a queue worker instead of at the end of the request. Set MAGPIE_QUEUE=true
    // to enable; the connection and queue name default to your application's defaults.
    'queue' => [
        'enabled' => (bool) env('MAGPIE_QUEUE', false),
        'connection' => env('MAGPIE_QUEUE_CONNECTION'),
        'name' => env('MAGPIE_QUEUE_NAME'),
    ],

    // Callable (event array, hint array) => array|null. Return null to drop the event. Use an
    // invokable class or [Class::class, 'method'] rather than a closure so `config:cache` works.
    'before_send' => null,

    // Hosts (or "*") that receive the magpie-trace header on outgoing Laravel HTTP client requests. Defaults
    // to your own APP_URL host so third parties never see your trace ids.
    'trace_propagation_targets' => array_values(array_filter([parse_url((string) env('APP_URL'), PHP_URL_HOST) ?: null])),

    // Continue the sampling decision of an incoming magpie-trace header that says "sampled". Leave off unless
    // every caller is one of your own services; otherwise anyone can force tracing by sending the header.
    'trust_incoming_trace' => (bool) env('MAGPIE_TRUST_INCOMING_TRACE', false),

    'breadcrumbs' => [
        'max' => 100,
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'http_client' => true,
        'cache' => true,
        'queue_info' => true,
        'command_info' => true,
    ],

    // Caps that keep payloads small and memory bounded. Magpie rejects bodies over 1 MB.
    'limits' => [
        'string_length' => 8192,
        'spans' => 100,
        'frames' => 100,
    ],
];
