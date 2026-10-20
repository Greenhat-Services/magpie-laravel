# Changelog

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
