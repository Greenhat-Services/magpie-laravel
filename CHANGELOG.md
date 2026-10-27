# Changelog

## Unreleased

- Nothing is sent from the `local` environment any more, so errors on a laptop no longer appear in Magpie. Set `MAGPIE_DISABLED_ENVIRONMENTS=` (empty) to send from every environment, or list others, e.g. `local,testing`.

## 0.5.0

- Platform reporting: the app tells Magpie its PHP, Laravel and package versions (Octane, Horizon, Livewire, Inertia, Filament) and the names of its database, cache, queue and session drivers, so Magpie can list what every application runs on. Versions and driver names only, never configuration values. Sent when it changes and at most once a day. Needs a Magpie with platform support. Turn off with `MAGPIE_PLATFORM=false`.

## 0.4.0

- Require `laravel/framework` `^12.69|^13.30`, the first releases with the fixes for three published advisories (CRLF injection in the email rule, signed URL path confusion, XSS in the debug page). Laravel 11 is dropped: it is end of life and was never patched.

## 0.3.0

Security hardening.

- Delivery no longer follows redirects, so the DSN key and event body cannot be forwarded to another host.
- Plain `http://` DSNs are rejected unless the host is local (`localhost`, `127.0.0.1`, `::1`, `*.test`, `*.localhost`).
- Database error messages are redacted: the quoted SQL values and duplicate-entry values are removed.
- `before_send` now also runs for transactions.
- URL paths are scrubbed (long opaque segments and anything after `reset`, `token`, `invite` and similar), as are cache keys containing emails or credential-like names, and SQL bindings for statements touching credential-like columns.
- `trace_propagation_targets` now defaults to your `APP_URL` host instead of `*`. Set it to `['*']` to restore the old behaviour.
- A "sampled" flag in an incoming `magpie-trace` header no longer forces tracing; set `MAGPIE_TRUST_INCOMING_TRACE=true` to trust it.
- Queued delivery jobs are encrypted at rest (`ShouldBeEncrypted`).
- A `.git/HEAD` ref that escapes the git directory is ignored when detecting the release.
## 0.2.0

- Crash-free sessions: web requests are counted as sessions (clean, errored or crashed by an uncaught exception) and sent to Magpie as one summary per minute. Needs a release and Magpie with session support. Turn off with `MAGPIE_SESSIONS=false`.
- Fix: an error raised inside a traced request lost its `runtime` and `os` contexts, so Magpie showed "php" and "Unknown".

## 0.1.1

- Allow Guzzle 8 (`guzzlehttp/guzzle` `^7.8.2|^8.0`) so the package installs in apps that already use it. CI now tests both ends of the range.

## 0.1.0

First release.

### Capture

- Exception and message capture with stack traces, source context, in-app frames and chained causes.
- Request and (opt-in) user context, breadcrumbs for logs, SQL queries, HTTP client calls, cache, queue jobs and console commands.
- `magpie` log channel, `magpie:test` command that reports why Magpie rejected an event.
- Default `ignore_exceptions` list covering Laravel's own "don't report" exceptions, and `GET /up` in `ignore_transactions`.
- Release detection from the git checkout when `MAGPIE_RELEASE` is empty, and the installed package version reported to Magpie.

### Performance

- Sampled requests, jobs and commands become transactions with `db.sql.query` and `http.client` spans. Errors raised during a trace link to it.
- Distributed tracing: incoming and outgoing `magpie-trace` header, propagation into queued jobs, `trace_propagation_targets`.
- Jobs on the `sync` driver become a span of the surrounding transaction; nested Artisan calls keep the outer command's transaction.

### Delivery

- Synchronous delivery at the end of the request, job or command, or queued delivery (`MAGPIE_QUEUE=true`) through the `SendPayload` job.
- Payload limits (`limits.string_length`, `limits.spans`, `limits.frames`), gzip compression and a hard 1 MB body cap.
- `Transport::send()` and `Magpie::flush()` return whether delivery succeeded. Delivery never throws.

### Privacy

- Credentials in headers (`Authorization`, HTTP Basic auth, CSRF tokens, any header named like a token, secret, session or key) are always filtered.
- Client IP headers are dropped unless `send_default_pii` is on.
- Query strings, referers, outgoing URLs, connection error messages and log context are scrubbed by key.
- Scope is reset per request under Octane and per job in queue workers.
