<?php

namespace Magpie\Laravel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Magpie\Laravel\Transport\Transport;
use Throwable;

/**
 * Counts web requests as release-health sessions: one request is one session, which ends clean, with a handled
 * error, or crashed by an uncaught exception.
 *
 * PHP-FPM starts every request with empty memory, so the counts live in the application's cache, one counter per
 * minute and outcome. Each finished minute is sent to Magpie once as a small aggregate, never one payload per request.
 */
final class Sessions
{
    public const EXITED = 'exited';

    public const ERRORED = 'errored';

    public const CRASHED = 'crashed';

    public const OUTCOMES = [self::EXITED, self::ERRORED, self::CRASHED];

    /** Counters outlive the longest catch-up, so a quiet host still reports the minutes it missed. */
    private const COUNTER_TTL = 7200;

    /** The furthest back, in minutes, one delivery looks. */
    private const MAX_MINUTES = 60;

    private ?int $checkedMinute = null;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $release,
        private readonly string $environment,
    ) {}

    /** Counts one finished session in the current minute. Never throws. */
    public function record(string $outcome): void
    {
        try {
            $minute = $this->minute();

            // The pointer marks the last minute already sent. It starts just behind the first session we ever count.
            $this->cache->add($this->key('sent'), $minute - 1, 86400);

            $counter = $this->key($minute, $outcome);
            $this->cache->add($counter, 0, self::COUNTER_TTL);
            $this->cache->increment($counter);
        } catch (Throwable) {
            // Monitoring must never break the application.
        }
    }

    /**
     * Sends every finished minute that has not gone out yet, at most once per minute per process. Never throws.
     *
     * @return bool False only when Magpie did not accept a delivery that was attempted.
     */
    public function flush(Transport $transport): bool
    {
        $current = $this->minute();

        if ($this->checkedMinute === $current) {
            return true;
        }

        $this->checkedMinute = $current;

        try {
            $sent = $this->cache->get($this->key('sent'));

            // No pointer means no session was ever counted here. Nothing to send.
            if (! is_numeric($sent) || (int) $sent >= $current - 1) {
                return true;
            }

            $lock = $this->cache->getStore() instanceof LockProvider ? $this->cache->getStore()->lock($this->key('lock'), 15) : null;

            // Without a lock, two requests could send the same minute twice. Better to hold the data back.
            if ($lock === null || ! $lock->get()) {
                return true;
            }

            try {
                return $this->deliver($transport, max((int) $sent + 1, $current - self::MAX_MINUTES), $current - 1);
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            return false;
        }
    }

    private function deliver(Transport $transport, int $from, int $to): bool
    {
        $aggregates = [];
        $keys = [];

        for ($minute = $from; $minute <= $to; $minute++) {
            $row = ['minute' => $minute * 60];

            foreach (self::OUTCOMES as $outcome) {
                $row[$outcome] = (int) $this->cache->get($this->key($minute, $outcome), 0);
                $keys[] = $this->key($minute, $outcome);
            }

            if (array_sum(array_intersect_key($row, array_flip(self::OUTCOMES))) > 0) {
                $aggregates[] = $row;
            }
        }

        if ($aggregates !== [] && ! $transport->send([
            'type' => 'sessions',
            'release' => $this->release,
            'environment' => $this->environment,
            'timestamp' => microtime(true),
            'sdk' => ['name' => 'magpie.php.laravel', 'version' => Magpie::version()],
            'aggregates' => $aggregates,
        ])) {
            return false;
        }

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        $this->cache->put($this->key('sent'), $to, 86400);

        return true;
    }

    private function minute(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }

    private function key(int|string ...$parts): string
    {
        return 'magpie:sessions:'.md5($this->release."\0".$this->environment).':'.implode(':', $parts);
    }
}
