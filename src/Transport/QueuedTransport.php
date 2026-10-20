<?php

namespace Magpie\Laravel\Transport;

use Illuminate\Contracts\Bus\Dispatcher;
use Magpie\Laravel\Jobs\SendPayload;
use Throwable;

/**
 * Hands payloads to a queue so the request does not wait for Magpie. A worker then delivers them
 * through the inner transport.
 */
final class QueuedTransport implements Transport
{
    public function __construct(
        private readonly Transport $inner,
        private readonly Dispatcher $bus,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    public function send(array $payload): bool
    {
        $job = new SendPayload($payload);

        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        try {
            $this->bus->dispatch($job);

            return true;
        } catch (Throwable) {
            // The queue is unavailable; deliver inline rather than lose the event.
            return $this->inner->send($payload);
        }
    }

    public function inner(): Transport
    {
        return $this->inner;
    }
}
