<?php

namespace Magpie\Laravel\Tests;

use Magpie\Laravel\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public bool $accepts = true;

    public function send(array $payload): bool
    {
        $this->payloads[] = $payload;

        return $this->accepts;
    }
}
