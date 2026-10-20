<?php

namespace Magpie\Laravel\Tests;

use Exception;
use Illuminate\Http\Request;
use Magpie\Laravel\Logs\Handler;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\RequestContext;
use Monolog\Level;
use Monolog\Logger;

class FeaturesTest extends TestCase
{
    public function test_request_context_filters_sensitive_data(): void
    {
        $request = Request::create('https://app.test/bookings?page=2&api_key=secret', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer abc',
            'HTTP_COOKIE' => 'session=xyz',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $context = RequestContext::request($request, false);

        $this->assertSame('POST', $context['method']);
        $this->assertSame('[Filtered]', $context['headers']['authorization']);
        $this->assertSame('[Filtered]', $context['headers']['cookie']);
        $this->assertSame('application/json', $context['headers']['accept']);
        $this->assertStringContainsString('page=2', $context['query_string']);
        $this->assertStringNotContainsString('secret', $context['query_string']);
        $this->assertArrayNotHasKey('env', $context);
    }

    public function test_monolog_handler_reports_exceptions_and_messages(): void
    {
        $logger = new Logger('app', [new Handler($this->magpie(), Level::Error)]);

        $logger->info('ignored below the level');
        $logger->error('Payment failed', ['order' => 12]);
        $logger->critical('Gateway down', ['exception' => new Exception('timeout')]);
        $this->magpie()->flush();

        $this->assertCount(2, $this->transport->payloads);
        [$message, $exception] = $this->transport->payloads;

        $this->assertSame('Payment failed', $message['message']);
        $this->assertSame(['order' => 12], $message['extra']['log.context']);
        $this->assertSame('app', $message['tags']['log.channel']);
        $this->assertSame('fatal', $exception['level']);
        $this->assertSame('timeout', $exception['exception']['values'][0]['value']);
    }

    public function test_requests_are_traced_with_query_spans(): void
    {
        $this->assertTrue($this->magpie()->startTransaction('GET /bookings'));
        $this->magpie()->addSpan('db.sql.query', 'select * from bookings', microtime(true) - 0.01, 0.01);
        $this->magpie()->finishTransaction(['status' => 'ok', 'http.status_code' => 200]);
        $transaction = $this->lastEvent();

        $this->assertSame('transaction', $transaction['type']);
        $this->assertSame('GET /bookings', $transaction['transaction']);
        $this->assertSame('http.server', $transaction['contexts']['trace']['op']);
        $this->assertSame(200, $transaction['contexts']['trace']['http.status_code']);
        $this->assertCount(1, $transaction['spans']);
        $this->assertSame('db.sql.query', $transaction['spans'][0]['op']);
    }

    public function test_errors_inherit_the_active_transaction_name(): void
    {
        $this->magpie()->startTransaction('POST /bookings');
        $this->magpie()->captureMessage('during request');

        $this->assertSame('POST /bookings', $this->lastEvent()['transaction']);
    }

    public function test_tracing_respects_the_sample_rate_and_ignore_list(): void
    {
        config()->set('magpie.traces_sample_rate', 0.0);
        $this->app->forgetInstance(Magpie::class);
        $this->assertFalse($this->magpie()->startTransaction('GET /'));

        config()->set('magpie.traces_sample_rate', 1.0);
        config()->set('magpie.ignore_transactions', ['GET /up']);
        $this->app->forgetInstance(Magpie::class);
        $this->assertFalse($this->magpie()->startTransaction('GET /up'));
    }

    public function test_magpie_test_command_sends_an_event(): void
    {
        $this->artisan('magpie:test')->assertSuccessful();

        $this->assertCount(1, $this->transport->payloads);
        $this->assertStringContainsString('magpie:test', $this->transport->payloads[0]['exception']['values'][0]['value']);
    }

    public function test_magpie_test_command_fails_without_a_dsn(): void
    {
        config()->set('magpie.dsn', '');

        $this->artisan('magpie:test')->assertFailed();
    }
}
