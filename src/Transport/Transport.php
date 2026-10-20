<?php

namespace Magpie\Laravel\Transport;

interface Transport
{
    /**
     * Deliver one event or transaction payload. Must never throw.
     *
     * @param  array<string, mixed>  $payload
     * @return bool True when Magpie accepted the payload (or it was handed to a queue for delivery).
     */
    public function send(array $payload): bool;
}
