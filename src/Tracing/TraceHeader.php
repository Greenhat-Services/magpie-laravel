<?php

namespace Magpie\Laravel\Tracing;

/**
 * The `magpie-trace` header: `<trace id>-<parent span id>-<sampled 0|1>`. Carried on outgoing HTTP
 * client requests and in queued job payloads so a trace continues across services and workers.
 */
final class TraceHeader
{
    public const NAME = 'magpie-trace';

    public const PAYLOAD_KEY = 'magpie_trace';

    public static function format(string $traceId, string $spanId, bool $sampled): string
    {
        return $traceId.'-'.$spanId.'-'.($sampled ? '1' : '0');
    }

    /** @return array{trace_id: string, span_id: string, sampled: bool|null}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null || ! preg_match('/^([0-9a-f]{32})-([0-9a-f]{16})(?:-([01]))?$/i', trim($header), $m)) {
            return null;
        }

        return [
            'trace_id' => strtolower($m[1]),
            'span_id' => strtolower($m[2]),
            'sampled' => isset($m[3]) ? $m[3] === '1' : null,
        ];
    }
}
