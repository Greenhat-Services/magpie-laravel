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
            TraceHeader::parse($request->header(TraceHeader::NAME)),
        );

        return $next($request);
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
