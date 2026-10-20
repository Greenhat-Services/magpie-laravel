<?php

namespace Magpie\Laravel\Tests\Unit;

use Magpie\Laravel\Payload;
use PHPUnit\Framework\TestCase;

class PayloadTest extends TestCase
{
    public function test_strings_are_truncated_at_any_depth_without_breaking_utf8(): void
    {
        $limited = Payload::limit(['a' => str_repeat('é', 100), 'nested' => ['b' => 'short', 'c' => str_repeat('x', 100)], 'n' => 5], 20);

        $this->assertLessThanOrEqual(20, strlen($limited['a']));
        $this->assertTrue(mb_check_encoding($limited['a'], 'UTF-8'));
        $this->assertStringEndsWith("\u{2026}", $limited['a']);
        $this->assertSame('short', $limited['nested']['b']);
        $this->assertLessThanOrEqual(20, strlen($limited['nested']['c']));
        $this->assertSame(5, $limited['n']);
    }

    public function test_oversized_bodies_shed_breadcrumbs_spans_and_source_context(): void
    {
        $payload = [
            'event_id' => 'e1',
            'breadcrumbs' => array_fill(0, 2000, ['message' => str_repeat('b', 1000)]),
            'exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => [['filename' => 'a.php', 'pre_context' => [str_repeat('p', 100)], 'context_line' => 'x']]]]]],
        ];

        $decoded = json_decode(Payload::encode($payload), true);

        $this->assertArrayNotHasKey('breadcrumbs', $decoded);
        $this->assertArrayNotHasKey('pre_context', $decoded['exception']['values'][0]['stacktrace']['frames'][0]);
        $this->assertSame('x', $decoded['exception']['values'][0]['stacktrace']['frames'][0]['context_line']);
        $this->assertNull(Payload::encode(['message' => str_repeat('m', Payload::MAX_BYTES + 1)]));
    }
}
