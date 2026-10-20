<?php

namespace Magpie\Laravel;

use Throwable;

/**
 * Turns a Throwable into the frame list Magpie stores, oldest call first.
 */
final class Stacktrace
{
    /** @var array<string, list<string>|null> */
    private array $files = [];

    /**
     * @param  list<string>  $inAppInclude
     * @param  list<string>  $inAppExclude
     * @param  int  $maxFrames  Frames nearest the throw site to keep; 0 keeps every frame.
     */
    public function __construct(
        private readonly array $inAppInclude = [],
        private readonly array $inAppExclude = [],
        private readonly int $contextLines = 5,
        private readonly int $maxFrames = 100,
    ) {}

    /** @return list<array<string, mixed>> */
    public function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        $locations = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$trace];

        if ($this->maxFrames > 0) {
            $locations = array_slice($locations, 0, $this->maxFrames);
        }

        $frames = [];

        foreach ($locations as $i => $location) {
            // A call site lives inside the function of the next trace entry up the stack.
            $enclosing = $trace[$i] ?? null;
            $file = $location['file'] ?? '[internal]';
            $line = (int) ($location['line'] ?? 0);

            $frame = [
                'filename' => $file,
                'lineno' => $line,
                'function' => $enclosing ? $this->functionName($enclosing) : null,
                'in_app' => $this->inApp($file),
            ];

            if ($file !== '[internal]' && $line > 0) {
                $frame += $this->sourceContext($file, $line);
            }

            $frames[] = array_filter($frame, fn ($v) => $v !== null);
        }

        return array_reverse($frames);
    }

    /** @param  array<string, mixed>  $entry */
    private function functionName(array $entry): string
    {
        return ($entry['class'] ?? '').($entry['type'] ?? '').($entry['function'] ?? '');
    }

    private function inApp(string $file): bool
    {
        if ($file === '[internal]') {
            return false;
        }

        $path = self::normalise($file);

        foreach ($this->inAppExclude as $excluded) {
            if (str_starts_with($path, self::normalise($excluded))) {
                return false;
            }
        }
        foreach ($this->inAppInclude as $included) {
            if (str_starts_with($path, self::normalise($included))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function sourceContext(string $file, int $line): array
    {
        if ($this->contextLines < 1) {
            return [];
        }

        $lines = $this->files[$file] ??= (is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: null) : null);

        if ($lines === null || ! isset($lines[$line - 1])) {
            return [];
        }

        $start = max(0, $line - 1 - $this->contextLines);

        return [
            'pre_context' => array_slice($lines, $start, $line - 1 - $start),
            'context_line' => $lines[$line - 1],
            'post_context' => array_slice($lines, $line, $this->contextLines),
        ];
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
