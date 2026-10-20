<?php

namespace Magpie\Laravel\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magpie\Laravel\Dsn;
use Magpie\Laravel\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    public function test_it_posts_events_and_transactions_with_the_auth_header(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $stack->push(Middleware::history($history));

        $transport = new HttpTransport(Dsn::parse('https://abc123@ingest.magpie.example.com/7'), 3.0, new Client(['handler' => $stack]));
        $transport->send(['event_id' => 'e1', 'message' => 'hi']);
        $transport->send(['event_id' => 't1', 'type' => 'transaction']);

        $this->assertSame('https://ingest.magpie.example.com/api/7/events', (string) $history[0]['request']->getUri());
        $this->assertSame('https://ingest.magpie.example.com/api/7/transactions', (string) $history[1]['request']->getUri());
        $this->assertStringContainsString('magpie_key=abc123', $history[0]['request']->getHeaderLine('X-Magpie-Auth'));
        $this->assertSame('hi', json_decode((string) $history[0]['request']->getBody(), true)['message']);
    }

    public function test_network_failures_never_throw(): void
    {
        $stack = HandlerStack::create(new MockHandler([new ConnectException('down', new Request('POST', 'https://x'))]));
        $transport = new HttpTransport(Dsn::parse('https://abc123@ingest.magpie.example.com/7'), 3.0, new Client(['handler' => $stack]));

        $this->assertFalse($transport->send(['event_id' => 'e1']));
        $this->assertSame('down', $transport->lastError());
    }

    public function test_rejections_are_reported_to_the_caller(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(401, [], '{"error":"Unknown DSN key."}'), new Response(202)]));
        $transport = new HttpTransport(Dsn::parse('https://abc123@ingest.magpie.example.com/7'), 3.0, new Client(['handler' => $stack]));

        $this->assertFalse($transport->send(['event_id' => 'e1']));
        $this->assertSame('Magpie responded with HTTP 401.', $transport->lastError());
        $this->assertTrue($transport->send(['event_id' => 'e2']));
        $this->assertNull($transport->lastError());
    }

    public function test_large_bodies_are_gzipped(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(Middleware::history($history));
        $transport = new HttpTransport(Dsn::parse('https://abc123@ingest.magpie.example.com/7'), 3.0, new Client(['handler' => $stack]));

        $transport->send(['event_id' => 'e1', 'message' => str_repeat('m', 10_000)]);

        $request = $history[0]['request'];
        $this->assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame(10_000, strlen(json_decode(gzdecode((string) $request->getBody()), true)['message']));
    }
}
