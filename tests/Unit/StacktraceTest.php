<?php

namespace Magpie\Laravel\Tests\Unit;

use Exception;
use Magpie\Laravel\Stacktrace;
use PHPUnit\Framework\TestCase;

class StacktraceTest extends TestCase
{
    private function thrower(): never
    {
        throw new Exception('boom');
    }

    public function test_frames_run_oldest_first_and_end_at_the_throw_site(): void
    {
        try {
            $this->thrower();
        } catch (Exception $e) {
            $frames = (new Stacktrace([__DIR__], [], 2))->frames($e);
        }

        $last = end($frames);

        $this->assertSame(__FILE__, $last['filename']);
        $this->assertSame(self::class.'->thrower', $last['function']);
        $this->assertTrue($last['in_app']);
        $this->assertSame("        throw new Exception('boom');", $last['context_line']);
        $this->assertCount(2, $last['pre_context']);
        $this->assertCount(2, $last['post_context']);
    }

    public function test_excluded_paths_are_not_in_app(): void
    {
        try {
            $this->thrower();
        } catch (Exception $e) {
            $frames = (new Stacktrace([__DIR__], [__DIR__], 0))->frames($e);
        }

        $this->assertFalse(end($frames)['in_app']);
        $this->assertArrayNotHasKey('context_line', end($frames));
    }
}
