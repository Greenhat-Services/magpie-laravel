<?php

namespace Magpie\Laravel\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Magpie\Laravel\Integration;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * End to end: real requests through the HTTP kernel and real jobs through the queue.
 */
class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Integration::handles(new Exceptions($this->app->make(ExceptionHandler::class)));
    }

    protected function defineRoutes($router): void
    {
        $router->get('/boom', fn () => throw new RuntimeException('boom'));
    }

    public function test_an_exception_thrown_from_a_route_is_reported_with_its_request_and_trace(): void
    {
        $this->get('/boom?page=2&token=abc')->assertStatus(500);
        $this->magpie()->flush();

        [$error, $transaction] = $this->errorAndTransaction();

        $this->assertSame(RuntimeException::class, $error['exception']['values'][0]['type']);
        $this->assertSame('laravel', $error['exception']['values'][0]['mechanism']['type']);
        $this->assertFalse($error['exception']['values'][0]['mechanism']['handled']);
        $this->assertSame('GET /boom', $error['transaction']);
        $this->assertSame('http://localhost/boom', $error['request']['url']);
        $this->assertSame('page=2&token=%5BFiltered%5D', $error['request']['query_string']);

        $this->assertSame('GET /boom', $transaction['transaction']);
        $this->assertSame(500, $transaction['contexts']['trace']['http.status_code']);
        $this->assertSame('internal_error', $transaction['contexts']['trace']['status']);
        $this->assertSame($transaction['contexts']['trace']['trace_id'], $error['contexts']['trace']['trace_id'], 'the error links to its trace');
    }

    public function test_a_failing_job_is_reported_once(): void
    {
        try {
            $this->app->make(Dispatcher::class)->dispatch(new FailingJob);
            $this->fail('The sync queue rethrows.');
        } catch (RuntimeException $e) {
            Integration::captureUnhandledException($e);
        }

        $this->magpie()->flush();
        $events = array_values(array_filter($this->transport->payloads, fn ($p) => isset($p['exception'])));

        $this->assertCount(1, $events, 'the queue and the exception handler report the same throwable once');
        $this->assertSame('queue', $events[0]['exception']['values'][0]['mechanism']['type']);
        $this->assertSame('job failed', $events[0]['exception']['values'][0]['value']);
        $this->assertContains('Processing '.FailingJob::class, array_column($events[0]['breadcrumbs'], 'message'));
    }

    public function test_framework_noise_is_ignored_by_default(): void
    {
        $this->assertNull($this->magpie()->captureException(new ModelNotFoundException));
        $this->assertNull($this->magpie()->captureException(new NotFoundHttpException));
        $this->assertNotNull($this->magpie()->captureException(new RuntimeException('real')));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function errorAndTransaction(): array
    {
        $errors = array_values(array_filter($this->transport->payloads, fn ($p) => ($p['type'] ?? 'event') !== 'transaction'));
        $transactions = array_values(array_filter($this->transport->payloads, fn ($p) => ($p['type'] ?? null) === 'transaction'));

        $this->assertCount(1, $errors);
        $this->assertCount(1, $transactions);

        return [$errors[0], $transactions[0]];
    }
}

class FailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('job failed');
    }
}
