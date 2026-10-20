<?php

namespace Magpie\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Magpie\Laravel\Magpie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts each web request as a release-health session, which is what crash-free sessions are measured
 * against. Registered by the service provider.
 */
class SessionMiddleware
{
    public function __construct(private readonly Magpie $magpie) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->magpie->startSession($request->method().' /'.ltrim($request->path(), '/'));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->magpie->endSession();
    }
}
