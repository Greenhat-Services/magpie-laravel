<?php

namespace Magpie\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Magpie\Laravel\Transport\HttpTransport;
use Magpie\Laravel\Transport\QueuedTransport;
use Magpie\Laravel\Transport\Transport;

/**
 * Delivers one buffered payload from a queue worker. The SDK never traces or reports this job.
 */
final class SendPayload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public readonly array $payload) {}

    public function handle(Application $app): void
    {
        $transport = $app->bound(Transport::class) ? $app->make(Transport::class) : ($app->bound(HttpTransport::class) ? $app->make(HttpTransport::class) : null);

        if ($transport instanceof QueuedTransport) {
            $transport = $transport->inner();
        }

        $transport?->send($this->payload);
    }
}
