<?php

namespace Magpie\Laravel;

/**
 * Size limits applied to every event before it leaves the process.
 */
final class Payload
{
    /** The largest body Magpie accepts. Breadcrumbs, spans and source context are dropped to fit. */
    public const MAX_BYTES = 1_000_000;

    private const ELLIPSIS = "\u{2026}";

    /**
     * Truncate every string in the payload to $maxLength bytes (keeping valid UTF-8).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function limit(array $payload, int $maxLength): array
    {
        if ($maxLength < 1) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::limit($value, $maxLength);
            } elseif (is_string($value) && strlen($value) > $maxLength) {
                $payload[$key] = self::truncate($value, $maxLength);
            }
        }

        return $payload;
    }

    public static function truncate(string $value, int $maxLength): string
    {
        return strlen($value) > $maxLength ? mb_strcut($value, 0, $maxLength - strlen(self::ELLIPSIS)).self::ELLIPSIS : $value;
    }

    /**
     * JSON encode, shedding the bulkiest optional sections when the body would exceed MAX_BYTES.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null Null when the payload cannot be made small enough.
     */
    public static function encode(array $payload): ?string
    {
        $body = self::json($payload);

        if (strlen($body) <= self::MAX_BYTES) {
            return $body;
        }

        unset($payload['breadcrumbs'], $payload['spans'], $payload['extra']);

        foreach ($payload['exception']['values'] ?? [] as $i => $value) {
            foreach ($value['stacktrace']['frames'] ?? [] as $j => $frame) {
                unset($payload['exception']['values'][$i]['stacktrace']['frames'][$j]['pre_context'], $payload['exception']['values'][$i]['stacktrace']['frames'][$j]['post_context']);
            }
        }

        $body = self::json($payload);

        return strlen($body) <= self::MAX_BYTES ? $body : null;
    }

    /** @param  array<string, mixed>  $payload */
    private static function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
