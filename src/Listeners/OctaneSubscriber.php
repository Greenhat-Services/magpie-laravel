<?php

namespace Magpie\Laravel\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Magpie\Laravel\Magpie;

/**
 * Under Octane one process serves many requests, so the scope is reset before each one. The
 * listeners are registered by class name and do nothing when Octane is not installed.
 */
final class OctaneSubscriber
{
    private const EVENTS = [
        'Laravel\Octane\Events\RequestReceived',
        'Laravel\Octane\Events\TaskReceived',
        'Laravel\Octane\Events\TickReceived',
    ];

    public function __construct(private readonly Magpie $magpie) {}

    public function subscribe(Dispatcher $events): void
    {
        foreach (self::EVENTS as $event) {
            $events->listen($event, fn () => $this->magpie->reset());
        }
    }
}
