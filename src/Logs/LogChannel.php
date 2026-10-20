<?php

namespace Magpie\Laravel\Logs;

use Magpie\Laravel\Magpie;
use Monolog\Logger;

/**
 * Laravel log channel:
 *
 *     'magpie' => ['driver' => 'custom', 'via' => \Magpie\Laravel\Logs\LogChannel::class, 'level' => 'error'],
 */
class LogChannel
{
    /** @param  array<string, mixed>  $config */
    public function __invoke(array $config): Logger
    {
        $handler = new Handler(app(Magpie::class), $config['level'] ?? 'error', $config['bubble'] ?? true);

        return new Logger('magpie', [$handler]);
    }
}
