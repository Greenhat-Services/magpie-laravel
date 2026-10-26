# Contributing

## Setup

```sh
composer install
composer check   # pint --test, phpstan analyse, phpunit
```

CI runs the same three commands plus `composer audit`, across PHP 8.2 to 8.4 and Laravel 12 and 13. To test against one Laravel version locally:

```sh
composer require "laravel/framework:12.*" --no-update && composer update
```

To try the SDK inside a real application, add it as a path repository:

```sh
composer config repositories.magpie path ../magpie-laravel
composer require greenhat-services/magpie-laravel:@dev
```

## Rules the SDK must keep

- **Never break the host application.** Transports, listeners and context resolvers catch everything and return a result instead of throwing. `Magpie::flush()` reports failure with a boolean.
- **Never send credentials.** Any new field that carries request data goes through `RequestContext::scrub()` or `scrubUrl()`, and gets a test in `tests/SecurityTest.php` showing the sensitive value is absent from the payload.
- **Bounded memory and payload size.** Collections are capped at insertion (`limits.*`, `breadcrumbs.max`) and strings are truncated before buffering, because a long-running command may hold a transaction open for minutes.
- **Long-lived processes start clean.** Octane requests and queue jobs call `Magpie::reset()`. Anything that keeps per-request state must be cleared there.
- **No new runtime dependencies** beyond `laravel/framework`, Guzzle and Monolog.

## Wire protocol changes

The SDK and the Magpie server share the contract described in the README under "Wire protocol". A change to the payload needs the README section updated, the server side adjusted in step, and a CHANGELOG note when it affects published config or public methods.

## Tests

- `tests/Unit` covers pure classes (`Dsn`, `Stacktrace`, `Payload`, `Release`, `TraceHeader`, `HttpTransport` with a Guzzle mock handler).
- `tests/*Test.php` boot the package through Orchestra Testbench with `FakeTransport` bound as the `Transport`, so every assertion inspects the exact payload that would be sent.
- `IntegrationTest` and `TracingTest` go through the real HTTP kernel, the sync queue and a database queue worker. Add new behaviour there rather than calling listeners directly.

## Style

Laravel Pint with the `laravel` preset, PHPStan level 6. Keep methods short, prefer early returns, and put the reason for a non-obvious decision in a one-line comment next to it.
