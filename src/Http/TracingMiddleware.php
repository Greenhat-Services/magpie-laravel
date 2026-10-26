<?php

namespace Magpie\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\Tracing\TraceHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traces sampled requests as `http.server` transactions, continuing an incoming `magpie-trace`
 * header when one is present. Registered by the service provider.
 */
class TracingMiddleware
{
    public function __construct(private readonly Magpie $magpie) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->magpie->startTransaction(
            $request->method().' /'.ltrim($request->path(), '/'),
            'http.server',
            $this->parent($request),
        );

        return $next($request);
    }

    /**
     * An unsampled upstream decision is always honoured. A sampled one is only trusted with `trust_incoming_trace`,
     * since anyone can send the header and force tracing.
     *
     * @return array{trace_id: string, span_id: string, sampled: bool|null}|null
     */
    private function parent(Request $request): ?array
    {
        $parent = TraceHeader::parse($request->header(TraceHeader::NAME));

        if ($parent !== null && $parent['sampled'] === true && ! $this->magpie->option('trust_incoming_trace', false)) {
            $parent['sampled'] = null;
        }

        return $parent;
    }

    public function terminate(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();

        $this->magpie->finishTransaction([
            'status' => $status >= 500 ? 'internal_error' : 'ok',
            'http.status_code' => $status,
        ]);
    }
}
