<?php

namespace Magpie\Laravel\Listeners;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Magpie\Laravel\Magpie;

/**
 * Cache reads and writes as breadcrumbs, so an error can be read alongside what was cached.
 */
final class CacheSubscriber
{
    public function __construct(private readonly Magpie $magpie) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(CacheHit::class, fn (CacheEvent $e) => $this->crumb('hit', $e));
        $events->listen(CacheMissed::class, fn (CacheEvent $e) => $this->crumb('miss', $e));
        $events->listen(KeyWritten::class, fn (CacheEvent $e) => $this->crumb('write', $e));
        $events->listen(KeyForgotten::class, fn (CacheEvent $e) => $this->crumb('forget', $e));
    }

    private function crumb(string $operation, CacheEvent $event): void
    {
        if (! $this->magpie->option('breadcrumbs.cache', true)) {
            return;
        }

        $this->magpie->addBreadcrumb([
            'type' => 'default',
            'category' => 'cache',
            'message' => $operation.' '.$event->key,
            'data' => array_filter(['store' => $event->storeName ?? null, 'tags' => $event->tags ?: null], fn ($v) => $v !== null),
        ]);
    }
}
