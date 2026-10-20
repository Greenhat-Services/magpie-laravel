<?php

namespace Magpie\Laravel\Tests\Unit;

use Magpie\Laravel\Tracing\TraceHeader;
use PHPUnit\Framework\TestCase;

class TraceHeaderTest extends TestCase
{
    public function test_it_round_trips(): void
    {
        $header = TraceHeader::format(str_repeat('a', 32), str_repeat('b', 16), false);

        $this->assertSame(str_repeat('a', 32).'-'.str_repeat('b', 16).'-0', $header);
        $this->assertSame(['trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'sampled' => false], TraceHeader::parse($header));
    }

    public function test_the_sampled_flag_is_optional_and_garbage_is_ignored(): void
    {
        $this->assertNull(TraceHeader::parse(str_repeat('A', 32).'-'.str_repeat('B', 16))['sampled']);
        $this->assertNull(TraceHeader::parse(null));
        $this->assertNull(TraceHeader::parse('not-a-trace'));
        $this->assertNull(TraceHeader::parse(str_repeat('z', 32).'-'.str_repeat('b', 16).'-1'));
    }
}
