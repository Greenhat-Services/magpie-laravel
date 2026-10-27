# Magpie for Laravel

Error tracking and performance monitoring for Laravel apps, reporting to Magpie. Supports Laravel 12 (12.69+) and 13 (13.30+) on PHP 8.2+.

## Install

```sh
composer require greenhat-services/magpie-laravel
```

Add the DSN from **App settings > DSN keys** to `.env`:

```dotenv
MAGPIE_LARAVEL_DSN=https://<key>@ingest.magpie.example.com/<project>
MAGPIE_TRACES_SAMPLE_RATE=0.2
```

Report exceptions in `bootstrap/app.php`:

```php
use Magpie\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
```

Verify the setup:

```sh
php artisan magpie:test
```

The command fails with the reason when Magpie rejects the event (unknown key, unreachable host, oversized payload).

Publish the config if you need to change defaults: `php artisan vendor:publish --tag=magpie-config`.

## What is captured

- **Exceptions** reported by Laravel, with stack traces, source context, in-app frames, chained causes, request details and (opt-in) the signed-in user. Framework noise such as 404s, validation and authentication failures is ignored by default (`ignore_exceptions`).
- **Breadcrumbs**: log messages, SQL queries, outgoing HTTP client calls, cache hits and misses, queue jobs and console commands leading up to an error.
- **Performance**: sampled requests, jobs and commands become transactions with a span per SQL query and per Laravel HTTP client call (`MAGPIE_TRACES_SAMPLE_RATE`). An error raised during a transaction links to it.
- **Distributed tracing**: traced requests send a `magpie-trace` header on outgoing HTTP client calls and embed it in queued job payloads, so a job or another Magpie-instrumented service continues the same trace. Incoming `magpie-trace` headers are honoured.
- **Release health**: every web request counts as a session, and one that ends in an uncaught exception counts as crashed, so Magpie can show crash-free sessions and adoption per release. Counts are kept in your cache and sent as one small summary per minute, never one request per session. Needs a release, and a cache store shared by your servers (`sessions.store`). Health checks and `ignore_transactions` are not counted; jobs and commands are not sessions.
- **Release**: `MAGPIE_RELEASE`, or the git commit of the checkout when a `.git` directory is present.
- **Queue workers, Octane and commands**: events are flushed after every job, request or command, and each unit of work starts with a clean scope. Jobs run on the `sync` driver stay inside the surrounding request as a span.

### What is never sent

- Request bodies, cookies and session data.
- Credentials in headers: `Authorization`, `Proxy-Authorization`, HTTP Basic auth (`php-auth-*`), CSRF tokens, and any header whose name contains `auth`, `token`, `secret`, `session`, `api-key`, `signature`, `credential` or `password`.
- Client IP addresses (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `Forwarded` and similar) unless `send_default_pii` is on.
- Values of query, referer, outgoing-URL and log-context keys that look like credentials: `password`, `secret`, `token`, `api_key`, `credential`, `session`, `csrf`, `otp`, `cvv`, `ssn`, `signature`, `private_key`, `auth`, `code`, `pin`, `card_number` and similar are replaced with `[Filtered]`.
- SQL bindings, unless `breadcrumbs.sql_bindings` is on.

Source lines around each frame are sent unless `context_lines` is `0`. Use `before_send` to scrub anything specific to your application.

## Usage

```php
use Magpie\Laravel\Facades\Magpie;
use Magpie\Laravel\Scope;

Magpie::captureException($e);
Magpie::captureMessage('Nightly sync is late', 'warning');

Magpie::addBreadcrumb(['category' => 'billing', 'message' => 'Invoice created']);

Magpie::configureScope(fn (Scope $scope) => $scope
    ->setTag('tenant', 'acme')
    ->setUser(['id' => $user->id])
    ->setExtra('order', $order->id));

Magpie::withScope(function (Scope $scope) {
    $scope->setTag('import', 'bookings');
    Magpie::captureMessage('Row skipped');   // tag applies here only
});

Magpie::traceHeader();   // "magpie-trace" value for a hand-made outgoing request, or null
```

### Log channel

Send log records to Magpie by adding a channel to `config/logging.php`:

```php
'magpie' => [
    'driver' => 'custom',
    'via' => Magpie\Laravel\Logs\LogChannel::class,
    'level' => 'error',
],
```

then use it alone (`LOG_CHANNEL=magpie`) or in a stack. Records with an `exception` context become exceptions; others become messages.

### Queued delivery

By default events are sent over HTTP when the request, job or command finishes, with a 3 second timeout. To keep that off the web process:

```dotenv
MAGPIE_QUEUE=true
MAGPIE_QUEUE_CONNECTION=redis   # optional, defaults to your queue default
MAGPIE_QUEUE_NAME=monitoring    # optional
```

Each event becomes a `Magpie\Laravel\Jobs\SendPayload` job. The SDK never traces or reports its own job, and falls back to inline delivery when the queue cannot accept it.

## Configuration

| Option | Env | Default | |
|---|---|---|---|
| `dsn` | `MAGPIE_LARAVEL_DSN` | none | Empty or invalid disables the SDK. |
| `environment` | `MAGPIE_ENVIRONMENT` | `APP_ENV` | |
| `disabled_environments` | `MAGPIE_DISABLED_ENVIRONMENTS` | `local` | Comma separated environments that send nothing. Set it empty to send from `local` too. |
| `release` | `MAGPIE_RELEASE` | git commit | Version string; falls back to the checkout's commit. |
| `sample_rate` | `MAGPIE_SAMPLE_RATE` | `1.0` | Fraction of errors sent. |
| `sessions.*` | `MAGPIE_SESSIONS`, `MAGPIE_SESSIONS_CACHE_STORE` | on | Count requests for crash-free sessions. `store` is the cache store holding the counts (default: your default store). |
| `platform.*` | `MAGPIE_PLATFORM`, `MAGPIE_PLATFORM_CACHE_STORE` | on | Report PHP, Laravel and package versions and the database, cache, queue and session driver names. Never configuration values. `store` is the cache store that remembers what was sent. |
| `traces_sample_rate` | `MAGPIE_TRACES_SAMPLE_RATE` | none | Fraction of requests, jobs and commands traced. |
| `send_default_pii` | `MAGPIE_SEND_DEFAULT_PII` | `false` | Attach the user, client IP headers and `REMOTE_ADDR`. |
| `ignore_exceptions` | | framework noise | Classes and interfaces never reported, subclasses included. |
| `ignore_transactions` | | `GET /up`, `HEAD /up` | Transaction names never traced. |
| `context_lines` | | `5` | Source lines around each frame; `0` sends no source. |
| `timeout` | `MAGPIE_TIMEOUT` | `3` | Seconds to wait for Magpie. |
| `queue.*` | `MAGPIE_QUEUE`, `MAGPIE_QUEUE_CONNECTION`, `MAGPIE_QUEUE_NAME` | off | Deliver from a queue worker. |
| `before_send` | | `null` | `callable(array $event, array $hint): ?array`; return null to drop. Use an invokable class or `[Class::class, 'method']` so `config:cache` works. |
| `trace_propagation_targets` | | your `APP_URL` host | Hosts (exact or parent domain, or `*`) that receive the `magpie-trace` header. |
| `trust_incoming_trace` | `MAGPIE_TRUST_INCOMING_TRACE` | `false` | Honour a "sampled" decision in an incoming `magpie-trace` header. Only enable when every caller is your own service. |
| `breadcrumbs.*` | | on | `logs`, `sql_queries`, `sql_bindings` (off), `http_client`, `cache`, `queue_info`, `command_info`, `max` (100). |
| `limits.*` | | `8192`, `100`, `100` | `string_length` in bytes, `spans` per transaction, `frames` per exception. |

## Wire protocol

This is the contract between the SDK and a Magpie server.

- `POST {scheme}://{host}/api/{project}/events` for errors and `.../transactions` for traces, taken from the DSN `https://<key>@<host>/<project>`.
- Header `X-Magpie-Auth: Magpie magpie_version=1, magpie_key=<key>, magpie_client=magpie-laravel/<version>`.
- JSON body, gzip-compressed (`Content-Encoding: gzip`) above 4 KB. Bodies are kept under 1 MB: breadcrumbs, spans, extra and source context are dropped to fit, and an event that is still too large is not sent.
- Errors carry `event_id`, `timestamp`, `platform`, `level`, `environment`, `release`, `server_name`, `exception.values[]` (`type`, `value`, `mechanism`, `stacktrace.frames[]` oldest first), `message`, `tags`, `extra`, `user`, `request`, `contexts`, `breadcrumbs`, `transaction` and `sdk`. An error raised inside a trace also carries `contexts.trace.trace_id` and `span_id`.
- Transactions carry `type: "transaction"`, `transaction`, `start_timestamp`, `timestamp`, `contexts.trace` (`trace_id`, `span_id`, `parent_span_id` when continuing a trace, `op`, `status`, `http.status_code`, `dropped_spans` when the span cap was hit) and `spans[]` (`span_id`, `parent_span_id`, `op`, `description`, `start_timestamp`, `timestamp`, `data`).
- Sessions are `POST .../sessions` with `type: "sessions"`, `release`, `environment`, `sdk` and `aggregates[]`: one `{minute, exited, errored, crashed}` per finished minute, where `minute` is the Unix time of the minute and the three counts are sessions that ended clean, with a handled error, or crashed by an uncaught exception. A minute is sent once.
- The platform is `POST .../platform` with `type: "platform"`, `environment`, `sdk`, `components` (name to version, e.g. `php`, `laravel`, `octane`) and `drivers` (name to driver, e.g. `database: mysql`). It is sent when it changes and at most once a day.
- Distributed tracing header `magpie-trace: <32 hex trace id>-<16 hex span id>-<0|1 sampled>`. The same value travels in queued job payloads under `magpie_trace`.

Delivery never throws. `Magpie::flush()` returns false when any payload was rejected.

## Testing

```sh
composer install
composer check        # Pint, PHPStan and PHPUnit
vendor/bin/phpunit
```

Bind your own `Magpie\Laravel\Transport\Transport` in the container to replace HTTP delivery, as the test suite does. See `CONTRIBUTING.md` for conventions.
