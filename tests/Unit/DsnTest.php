<?php

namespace Magpie\Laravel\Tests\Unit;

use InvalidArgumentException;
use Magpie\Laravel\Dsn;
use PHPUnit\Framework\TestCase;

class DsnTest extends TestCase
{
    public function test_it_parses_a_dsn(): void
    {
        $dsn = Dsn::parse('https://abc123@ingest.magpie.example.com/42');

        $this->assertSame('abc123', $dsn->publicKey);
        $this->assertSame('42', $dsn->projectId);
        $this->assertSame('https://ingest.magpie.example.com/api/42/events', $dsn->endpoint());
        $this->assertSame('https://ingest.magpie.example.com/api/42/transactions', $dsn->endpoint('transactions'));
    }

    public function test_it_keeps_port_and_path_prefix(): void
    {
        $dsn = Dsn::parse('http://key@localhost:8080/ingest/9');

        $this->assertSame('http://localhost:8080/ingest/api/9/events', $dsn->endpoint());
    }

    public function test_it_rejects_invalid_dsns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::parse('https://ingest.magpie.example.com/42');
    }

    public function test_it_requires_a_numeric_project(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::parse('https://key@ingest.magpie.example.com/web-app');
    }
}
