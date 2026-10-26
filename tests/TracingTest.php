<?php

namespace Magpie\Laravel\Tests;

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\Scope;
use Magpie\Laravel\Tracing\TraceHeader;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class TracingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/ok', fn () => 'ok');
    }

    public function test_an_incoming_trace_header_is_continued(): void
    {
        $traceId = str_repeat('a', 32);
        $this->withHeader(TraceHeader::NAME, $traceId.'-'.str_repeat('b', 16).'-1')->get('/ok')->assertOk();
        $transaction = $this->transactions()[0];

        $this->assertSame($traceId, $transaction['contexts']['trace']['trace_id']);
        $this->assertSame(str_repeat('b', 16), $transaction['contexts']['trace']['parent_span_id']);
        $this->assertSame('GET /ok', $transaction['transaction']);
    }

    public function test_an_error_inside_a_trace_keeps_the_runtime_and_os_contexts(): void
    {
        $this->magpie()->startTransaction('GET /ok');
        $this->magpie()->captureMessage('during');
        $event = $this->lastEvent();

        $this->assertArrayHasKey('trace', $event['contexts']);
        $this->assertSame(PHP_VERSION, $event['contexts']['runtime']['version']);
        $this->assertSame(PHP_OS_FAMILY, $event['contexts']['os']['name']);
    }

    public function test_the_trace_header_is_not_sent_to_third_parties_by_default(): void
    {
        Http::fake();

        $this->magpie()->startTransaction('GET /x');
        Http::get('https://third-party.test/b');

        Http::assertSent(fn (ClientRequest $r) => ! $r->hasHeader(TraceHeader::NAME));
    }

    public function test_an_incoming_sampled_flag_cannot_force_tracing_unless_trusted(): void
    {
        config()->set('magpie.traces_sample_rate', 0.0000001);
        $this->app->forgetInstance(Magpie::class);

        $this->assertFalse($this->magpie()->startTransaction('GET /x', 'http.server', ['trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'sampled' => null]));

        $this->withHeader(TraceHeader::NAME, str_repeat('a', 32).'-'.str_repeat('b', 16).'-1')->get('/ok')->assertOk();
        $this->assertSame([], $this->transactions());
    }

    public function test_an_upstream_unsampled_decision_is_respected(): void
    {
        $this->withHeader(TraceHeader::NAME, str_repeat('a', 32).'-'.str_repeat('b', 16).'-0')->get('/ok')->assertOk();

        $this->assertSame([], $this->transactions());
    }

    protected function propagateEverywhere($app): void
    {
        $app['config']->set('magpie.trace_propagation_targets', ['*']);
    }

    #[DefineEnvironment('propagateEverywhere')]
    public function test_http_client_calls_become_spans_and_breadcrumbs_and_carry_the_trace(): void
    {
        Http::fake(['api.example.com/*' => Http::response('created', 201)]);

        $this->magpie()->startTransaction('POST /orders');
        $header = $this->magpie()->traceHeader();
        Http::get('https://api.example.com/orders?api_key=secret&page=1');
        $this->magpie()->captureMessage('during');
        $this->magpie()->finishTransaction();

        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader(TraceHeader::NAME, $header));

        $span = $this->transactions()[0]['spans'][0];
        $this->assertSame('http.client', $span['op']);
        $this->assertSame('GET https://api.example.com/orders?api_key=%5BFiltered%5D&page=1', $span['description']);
        $this->assertSame(201, $span['data']['http.status_code']);

        $event = array_values(array_filter($this->transport->payloads, fn ($p) => ($p['message'] ?? null) === 'during'))[0];
        $crumb = array_values(array_filter($event['breadcrumbs'], fn ($c) => $c['category'] === 'http.client'))[0];
        $this->assertSame($span['description'], $crumb['message']);
        $this->assertStringNotContainsString('secret', json_encode($event));
    }

    public function test_connection_failure_messages_do_not_leak_the_url_credentials(): void
    {
        $psr = new PsrRequest('GET', 'https://api.example.com/x?api_key=secret');
        $exception = new ConnectionException('cURL error 7: Failed to connect for https://api.example.com/x?api_key=secret');

        Event::dispatch(new ConnectionFailed(new ClientRequest($psr), $exception));
        $this->magpie()->captureMessage('after');
        $crumb = array_values(array_filter($this->lastEvent()['breadcrumbs'], fn ($c) => $c['category'] === 'http.client'))[0];

        $this->assertSame('error', $crumb['level']);
        $this->assertStringContainsString('cURL error 7', $crumb['data']['error']);
        $this->assertStringNotContainsString('secret', json_encode($crumb));
    }

    protected function restrictPropagationTargets($app): void
    {
        $app['config']->set('magpie.trace_propagation_targets', ['internal.test']);
    }

    #[DefineEnvironment('restrictPropagationTargets')]
    public function test_the_trace_header_only_goes_to_configured_hosts(): void
    {
        Http::fake();

        $this->magpie()->startTransaction('GET /x');
        Http::get('https://api.internal.test/a');
        Http::get('https://third-party.test/b');

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.internal.test/a' && $r->hasHeader(TraceHeader::NAME));
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://third-party.test/b' && ! $r->hasHeader(TraceHeader::NAME));
    }

    public function test_jobs_dispatched_during_a_trace_continue_it_in_the_worker(): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->magpie()->startTransaction('POST /orders');
        $traceId = TraceHeader::parse($this->magpie()->traceHeader())['trace_id'];
        $this->app->make(Dispatcher::class)->dispatch(new NoopJob);
        $this->magpie()->finishTransaction();

        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

        $job = array_values(array_filter($this->transactions(), fn ($t) => $t['transaction'] === NoopJob::class))[0];
        $this->assertSame($traceId, $job['contexts']['trace']['trace_id']);
        $this->assertSame('queue.process', $job['contexts']['trace']['op']);
        $this->assertNotEmpty($job['contexts']['trace']['parent_span_id']);
        $this->assertSame('default', $job['tags']['queue']);
    }

    public function test_a_sync_job_is_a_span_of_the_current_transaction_not_a_new_one(): void
    {
        $this->magpie()->configureScope(fn (Scope $scope) => $scope->setTag('tenant', 'acme'));
        $this->magpie()->startTransaction('POST /orders');
        $this->app->make(Dispatcher::class)->dispatch(new NoopJob);
        $this->magpie()->captureMessage('after job');
        $this->magpie()->finishTransaction();

        $transactions = $this->transactions();
        $this->assertCount(1, $transactions);
        $this->assertSame('queue.process', $transactions[0]['spans'][0]['op']);
        $this->assertSame(NoopJob::class, $transactions[0]['spans'][0]['description']);
        $this->assertSame(['tenant' => 'acme'], $this->transport->payloads[0]['tags'], 'the request scope survives a sync job');
    }

    public function test_a_command_called_from_another_keeps_the_outer_transaction(): void
    {
        // `php artisan` reroutes Symfony's console events to CommandStarting/CommandFinished; the test runner does not.
        $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
        Artisan::command('outer', fn () => Artisan::call('inner'));
        Artisan::command('inner', fn () => 0);

        $this->artisan('outer')->assertSuccessful();

        $this->assertSame(['outer'], array_column($this->transactions(), 'transaction'));
    }

    public function test_octane_requests_start_with_a_clean_scope(): void
    {
        $this->magpie()->configureScope(fn (Scope $scope) => $scope->setTag('tenant', 'acme')->setUser(['id' => 1]));
        $this->magpie()->startTransaction('GET /first');

        Event::dispatch('Laravel\Octane\Events\RequestReceived');

        $this->assertFalse($this->magpie()->tracing());
        $this->magpie()->captureMessage('second request');
        $event = $this->lastEvent();

        $this->assertArrayNotHasKey('tags', $event);
        $this->assertArrayNotHasKey('user', $event);
    }

    public function test_cache_operations_become_breadcrumbs(): void
    {
        Cache::put('settings', 'x', 60);
        Cache::get('settings');
        Cache::get('missing');
        $this->magpie()->captureMessage('after');

        $crumbs = array_filter($this->lastEvent()['breadcrumbs'], fn ($c) => $c['category'] === 'cache');

        $this->assertSame(['write settings', 'hit settings', 'miss missing'], array_values(array_column($crumbs, 'message')));
    }

    public function test_long_strings_spans_and_frames_are_capped(): void
    {
        config()->set('magpie.limits', ['string_length' => 64, 'spans' => 2, 'frames' => 3]);
        $this->app->forgetInstance(Magpie::class);

        $this->magpie()->startTransaction('GET /big');
        foreach (range(1, 5) as $i) {
            $this->magpie()->addSpan('db.sql.query', str_repeat('x', 500), microtime(true), 0.001);
        }
        $this->magpie()->finishTransaction();
        $transaction = $this->lastEvent();

        $this->assertCount(2, $transaction['spans']);
        $this->assertSame(3, $transaction['contexts']['trace']['dropped_spans']);
        $this->assertLessThanOrEqual(64, strlen($transaction['spans'][0]['description']));

        $this->magpie()->captureException(new \RuntimeException(str_repeat('m', 500)));
        $error = $this->lastEvent();

        $this->assertLessThanOrEqual(64, strlen($error['exception']['values'][0]['value']));
        $this->assertLessThanOrEqual(3, count($error['exception']['values'][0]['stacktrace']['frames']));
    }

    /** @return list<array<string, mixed>> */
    private function transactions(): array
    {
        $this->magpie()->flush();

        return array_values(array_filter($this->transport->payloads, fn ($p) => ($p['type'] ?? null) === 'transaction'));
    }
}

class NoopJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
