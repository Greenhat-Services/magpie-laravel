<?php

namespace Magpie\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use InvalidArgumentException;
use Magpie\Laravel\Console\TestCommand;
use Magpie\Laravel\Http\SessionMiddleware;
use Magpie\Laravel\Http\TracingMiddleware;
use Magpie\Laravel\Listeners\CacheSubscriber;
use Magpie\Laravel\Listeners\EventsSubscriber;
use Magpie\Laravel\Listeners\HttpClientSubscriber;
use Magpie\Laravel\Listeners\OctaneSubscriber;
use Magpie\Laravel\Tracing\TraceHeader;
use Magpie\Laravel\Transport\HttpTransport;
use Magpie\Laravel\Transport\QueuedTransport;
use Magpie\Laravel\Transport\Transport;
use Psr\Http\Message\RequestInterface;
use Throwable;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/magpie.php', 'magpie');

        $this->app->singleton(HttpTransport::class, function (Application $app) {
            $options = (array) $app['config']->get('magpie', []);

            return new HttpTransport(Dsn::parse((string) ($options['dsn'] ?? '')), (float) ($options['timeout'] ?? 3));
        });

        $this->app->singleton(Magpie::class, function (Application $app) {
            $options = (array) $app['config']->get('magpie', []);
            $options['release'] = ($options['release'] ?? null) ?: Release::detect($app->basePath());
            $pii = (bool) ($options['send_default_pii'] ?? false);

            // Octane swaps the container per request, so always read the current one.
            $current = fn (): Application => ($instance = Container::getInstance()) instanceof Application ? $instance : $app;

            return new Magpie($options, $this->transport($app, $options), fn () => RequestContext::resolve($current(), $pii), $this->sessions($app, $options), $this->platform($app, $options));
        });

        $this->app->alias(Magpie::class, 'magpie');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/magpie.php' => config_path('magpie.php')], 'magpie-config');
            $this->commands([TestCommand::class]);
        }

        $magpie = $this->app->make(Magpie::class);

        if (! $magpie->enabled()) {
            return;
        }

        Event::subscribe(new EventsSubscriber($magpie));
        Event::subscribe(new HttpClientSubscriber($magpie));
        Event::subscribe(new CacheSubscriber($magpie));
        Event::subscribe(new OctaneSubscriber($magpie));

        $this->app->terminating(fn () => $magpie->flush());
        register_shutdown_function(fn () => $magpie->flush());

        $this->prependMiddleware(SessionMiddleware::class, $magpie->sessionsEnabled());

        if ((float) $magpie->option('traces_sample_rate', 0) > 0) {
            $this->registerTracing($magpie);
        }
    }

    private function prependMiddleware(string $middleware, bool $when = true): void
    {
        if ($when && $this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware($middleware);
            }
        }
    }

    private function registerTracing(Magpie $magpie): void
    {
        $this->prependMiddleware(TracingMiddleware::class);

        // Jobs dispatched during a traced request continue its trace in the worker.
        if (class_exists(Queue::class)) {
            Queue::createPayloadUsing(fn () => ($header = $magpie->traceHeader()) ? [TraceHeader::PAYLOAD_KEY => $header] : []);
        }

        // Outgoing Laravel HTTP client requests carry the trace to other services.
        $propagate = function (HttpClientFactory $http) use ($magpie): void {
            $http->globalRequestMiddleware(function (RequestInterface $request) use ($magpie) {
                $header = $magpie->traceHeader();

                return $header !== null && $this->propagatesTo($magpie, $request->getUri()->getHost()) ? $request->withHeader(TraceHeader::NAME, $header) : $request;
            });
        };

        if ($this->app->resolved(HttpClientFactory::class)) {
            $propagate($this->app->make(HttpClientFactory::class));
        } else {
            $this->app->afterResolving(HttpClientFactory::class, $propagate);
        }
    }

    private function propagatesTo(Magpie $magpie, string $host): bool
    {
        foreach ((array) $magpie->option('trace_propagation_targets', ['*']) as $target) {
            if ($target === '*' || strcasecmp($target, $host) === 0 || str_ends_with(strtolower($host), '.'.strtolower(ltrim($target, '.')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts web requests for crash-free sessions. Off when disabled, or when there is no release to count them against.
     *
     * @param  array<string, mixed>  $options
     */
    private function sessions(Application $app, array $options): ?Sessions
    {
        $release = trim((string) ($options['release'] ?? ''));

        if (! ($options['sessions']['enabled'] ?? true) || $release === '') {
            return null;
        }

        try {
            $cache = $app['cache']->store($options['sessions']['store'] ?? null);
        } catch (Throwable) {
            return null;
        }

        return new Sessions($cache, $release, (string) ($options['environment'] ?? 'production'));
    }

    /**
     * Reports the versions and driver names the app runs on. Off when disabled.
     *
     * @param  array<string, mixed>  $options
     */
    private function platform(Application $app, array $options): ?Platform
    {
        if (! ($options['platform']['enabled'] ?? true)) {
            return null;
        }

        try {
            $cache = $app['cache']->store($options['platform']['store'] ?? null);
        } catch (Throwable) {
            return null;
        }

        return new Platform($cache, fn () => Platform::snapshot($app->version(), (array) $app['config']->all()), (string) ($options['environment'] ?? 'production'));
    }

    /** @param  array<string, mixed>  $options */
    private function transport(Application $app, array $options): ?Transport
    {
        // A bound transport wins, which is how tests (and custom delivery) replace HTTP.
        if ($app->bound(Transport::class)) {
            return $app->make(Transport::class);
        }

        $dsn = trim((string) ($options['dsn'] ?? ''));

        if ($dsn === '') {
            return null;
        }

        try {
            Dsn::parse($dsn);
        } catch (InvalidArgumentException) {
            // An invalid DSN disables the SDK rather than breaking the application.
            return null;
        }

        $transport = $app->make(HttpTransport::class);

        if (! empty($options['queue']['enabled']) && $app->bound(BusDispatcher::class)) {
            return new QueuedTransport($transport, $app->make(BusDispatcher::class), $options['queue']['connection'] ?? null, $options['queue']['name'] ?? null);
        }

        return $transport;
    }
}
