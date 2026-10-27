<?php

namespace Magpie\Laravel\Tests;

class PlatformTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magpie.platform.enabled', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'main');
        $app['config']->set('database.connections.main', ['driver' => 'mysql', 'host' => 'db.internal', 'password' => 'secret']);
        $app['config']->set('queue.default', 'jobs');
        $app['config']->set('queue.connections.jobs', ['driver' => 'sqs', 'key' => 'AKIA-SECRET']);
    }

    /** @return list<array<string, mixed>> */
    private function platformPayloads(): array
    {
        return array_values(array_filter($this->transport->payloads, fn ($p) => ($p['type'] ?? null) === 'platform'));
    }

    public function test_it_reports_versions_and_driver_names_only(): void
    {
        $this->magpie()->flush();

        [$payload] = $this->platformPayloads();

        $this->assertSame(PHP_VERSION, $payload['components']['php']);
        $this->assertSame($this->app->version(), $payload['components']['laravel']);
        $this->assertSame('mysql', $payload['drivers']['database']);
        $this->assertSame('sqs', $payload['drivers']['queue']);
        $this->assertSame('array', $payload['drivers']['cache']);
        $this->assertSame('magpie.php.laravel', $payload['sdk']['name']);

        $json = json_encode($payload);
        foreach (['db.internal', 'secret', 'AKIA-SECRET'] as $value) {
            $this->assertStringNotContainsString($value, $json);
        }
    }

    public function test_it_is_sent_once_until_it_changes(): void
    {
        $this->magpie()->flush();
        $this->magpie()->flush();
        $this->assertCount(1, $this->platformPayloads());
    }

    public function test_it_is_sent_again_when_delivery_failed(): void
    {
        $this->transport->accepts = false;
        $this->assertFalse($this->magpie()->flush());

        $this->transport->accepts = true;
        $this->app->forgetInstance(\Magpie\Laravel\Magpie::class);
        $this->assertTrue($this->magpie()->flush());
        $this->assertCount(2, $this->platformPayloads());
    }

    public function test_it_can_be_turned_off(): void
    {
        $this->app['config']->set('magpie.platform.enabled', false);
        $this->app->forgetInstance(\Magpie\Laravel\Magpie::class);

        $this->magpie()->flush();

        $this->assertSame([], $this->platformPayloads());
    }
}
