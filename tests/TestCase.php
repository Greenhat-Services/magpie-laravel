<?php

namespace Magpie\Laravel\Tests;

use Magpie\Laravel\Magpie;
use Magpie\Laravel\ServiceProvider;
use Magpie\Laravel\Transport\Transport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->transport = new FakeTransport;
        $app->instance(Transport::class, $this->transport);

        $app['config']->set('magpie.dsn', 'https://abc123@ingest.magpie.example.com/7');
        $app['config']->set('magpie.in_app_include', [__DIR__]);
        $app['config']->set('magpie.traces_sample_rate', 1.0);
        // Off by default so tests that count payloads see only what they trigger. PlatformTest turns it on.
        $app['config']->set('magpie.platform.enabled', false);
    }

    protected function magpie(): Magpie
    {
        return $this->app->make(Magpie::class);
    }

    /** @return array<string, mixed> */
    protected function lastEvent(): array
    {
        $this->magpie()->flush();

        return $this->transport->payloads[array_key_last($this->transport->payloads)] ?? $this->fail('No event was delivered.');
    }
}
