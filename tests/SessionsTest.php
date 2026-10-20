<?php

namespace Magpie\Laravel\Tests;

use Magpie\Laravel\Integration;
use Magpie\Laravel\Magpie;
use RuntimeException;

class SessionsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magpie.release', 'web@1.2.3');
        $app['config']->set('magpie.environment', 'production');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/ok', fn () => 'ok');
        $router->get('/up', fn () => 'up');
        $router->get('/handled', function () {
            $this->magpie()->captureException(new RuntimeException('swallowed'));

            return 'ok';
        });
        $router->get('/crash', function () {
            Integration::captureUnhandledException(new RuntimeException('boom'));

            return response('oops', 500);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute()->addSeconds(10));
    }

    /** @return list<array<string, mixed>> */
    private function sessionPayloads(): array
    {
        return array_values(array_filter($this->transport->payloads, fn ($p) => ($p['type'] ?? null) === 'sessions'));
    }

    /** Moves into the next minute and sends the one that just finished. */
    private function nextMinute(): void
    {
        $this->travel(60)->seconds();
        $this->magpie()->flush();
    }

    public function test_requests_are_counted_by_outcome_and_sent_once_the_minute_is_over(): void
    {
        $this->get('/ok')->assertOk();
        $this->get('/ok')->assertOk();
        $this->get('/handled')->assertOk();
        $this->get('/crash')->assertStatus(500);

        $this->assertSame([], $this->sessionPayloads(), 'The minute is not over yet.');

        $this->nextMinute();
        $payloads = $this->sessionPayloads();

        $this->assertCount(1, $payloads);
        $this->assertSame('web@1.2.3', $payloads[0]['release']);
        $this->assertSame('production', $payloads[0]['environment']);
        $this->assertCount(1, $payloads[0]['aggregates']);
        $this->assertSame(['exited' => 2, 'errored' => 1, 'crashed' => 1], array_diff_key($payloads[0]['aggregates'][0], ['minute' => 0]));
        $this->assertSame(now()->startOfMinute()->subMinute()->getTimestamp(), $payloads[0]['aggregates'][0]['minute']);
    }

    public function test_a_minute_is_sent_only_once(): void
    {
        $this->get('/ok');
        $this->nextMinute();
        $this->nextMinute();

        $this->assertCount(1, $this->sessionPayloads());
    }

    public function test_health_checks_are_not_sessions(): void
    {
        $this->get('/up')->assertOk();
        $this->nextMinute();

        $this->assertSame([], $this->sessionPayloads());
    }

    public function test_a_crash_is_counted_even_when_the_event_is_sampled_out(): void
    {
        $this->app['config']->set('magpie.sample_rate', 0.0);
        $this->app->forgetInstance(Magpie::class);

        $this->get('/crash');
        $this->nextMinute();

        $this->assertSame(1, $this->sessionPayloads()[0]['aggregates'][0]['crashed']);
    }

    public function test_a_failed_delivery_is_retried_with_the_same_counts(): void
    {
        $this->get('/ok');
        $this->transport->accepts = false;
        $this->nextMinute();
        $this->transport->accepts = true;
        $this->nextMinute();

        $payloads = $this->sessionPayloads();
        $this->assertCount(2, $payloads);
        $this->assertSame($payloads[0]['aggregates'], $payloads[1]['aggregates']);
    }

    public function test_sessions_can_be_turned_off(): void
    {
        $this->app['config']->set('magpie.sessions.enabled', false);
        $this->app->forgetInstance(Magpie::class);

        $this->get('/ok');
        $this->nextMinute();

        $this->assertSame([], $this->sessionPayloads());
        $this->assertFalse($this->magpie()->sessionsEnabled());
    }

    public function test_nothing_is_counted_without_a_release(): void
    {
        $this->app['config']->set('magpie.release', null);
        $this->app->forgetInstance(Magpie::class);

        $this->assertFalse($this->magpie()->sessionsEnabled());
    }

    public function test_the_session_counters_leave_no_cache_breadcrumbs(): void
    {
        $this->get('/ok');
        $this->magpie()->captureMessage('after');

        $this->assertSame([], array_filter($this->lastEvent()['breadcrumbs'] ?? [], fn ($b) => str_contains($b['message'] ?? '', 'magpie:')));
    }

    public function test_work_outside_a_request_is_not_a_session(): void
    {
        $this->magpie()->captureException(new RuntimeException('in a job'));
        $this->magpie()->endSession();
        $this->nextMinute();

        $this->assertSame([], $this->sessionPayloads());
    }
}
