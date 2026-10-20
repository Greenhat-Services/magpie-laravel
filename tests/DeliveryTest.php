<?php

namespace Magpie\Laravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher;
use Magpie\Laravel\Transport\QueuedTransport;

class DeliveryTest extends TestCase
{
    public function test_flush_reports_rejected_payloads_and_the_test_command_fails(): void
    {
        $this->transport->accepts = false;
        $this->magpie()->captureMessage('x');

        $this->assertFalse($this->magpie()->flush());
        $this->assertTrue($this->magpie()->flush(), 'nothing left to deliver');

        $this->artisan('magpie:test')->assertFailed();
    }

    public function test_queued_delivery_sends_from_a_job_without_tracing_itself(): void
    {
        $transport = new QueuedTransport($this->transport, $this->app->make(Dispatcher::class));

        $this->assertTrue($transport->send(['event_id' => 'e1', 'message' => 'queued']));
        $this->assertSame('queued', $this->transport->payloads[0]['message']);

        $this->magpie()->captureMessage('after');
        $event = $this->lastEvent();

        $this->assertCount(2, $this->transport->payloads, 'the delivery job produced no transaction of its own');
        $this->assertNotContains('queue.job', array_column($event['breadcrumbs'] ?? [], 'category'));
    }
}
