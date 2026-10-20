<?php

namespace Magpie\Laravel\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\RequestContext;
use Throwable;
use WeakMap;

/**
 * Breadcrumbs and `http.client` spans for requests made with Laravel's HTTP client.
 */
final class HttpClientSubscriber
{
    /** @var WeakMap<Request, float> */
    private WeakMap $started;

    public function __construct(private readonly Magpie $magpie)
    {
        $this->started = new WeakMap;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, $this->sending(...));
        $events->listen(ResponseReceived::class, $this->received(...));
        $events->listen(ConnectionFailed::class, $this->failed(...));
    }

    private function sending(RequestSending $event): void
    {
        $this->started[$event->request] = microtime(true);
    }

    private function received(ResponseReceived $event): void
    {
        $this->record($event->request, $event->response->status(), null);
    }

    private function failed(ConnectionFailed $event): void
    {
        // The exception property arrived in Laravel 11.x; older releases only carry the request.
        $exception = get_object_vars($event)['exception'] ?? null;

        $this->record($event->request, null, $exception instanceof Throwable ? self::scrubMessage($exception->getMessage()) : 'Connection failed');
    }

    /** Guzzle error messages embed the full request URL, query string included. */
    private static function scrubMessage(string $message): string
    {
        return (string) preg_replace_callback('~https?://[^\s"\'<>]+~i', fn ($m) => RequestContext::scrubUrl($m[0]), $message);
    }

    private function record(Request $request, ?int $status, ?string $error): void
    {
        $start = $this->started[$request] ?? microtime(true);
        unset($this->started[$request]);

        $url = RequestContext::scrubUrl($request->url());
        $description = $request->method().' '.$url;
        $data = array_filter(['http.method' => $request->method(), 'http.status_code' => $status, 'url' => $url, 'error' => $error], fn ($v) => $v !== null);

        if ($this->magpie->option('breadcrumbs.http_client', true)) {
            $this->magpie->addBreadcrumb([
                'type' => 'http',
                'category' => 'http.client',
                'level' => $error !== null || $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'),
                'message' => $description,
                'timestamp' => $start,
                'data' => $data,
            ]);
        }

        $this->magpie->addSpan('http.client', $description, $start, microtime(true) - $start, $data);
    }
}
