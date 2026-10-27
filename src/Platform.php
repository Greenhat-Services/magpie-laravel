<?php

namespace Magpie\Laravel;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Repository;
use Magpie\Laravel\Transport\Transport;
use Throwable;

/**
 * Tells Magpie what the app runs on: PHP, Laravel and a few well-known package versions, plus the names of the
 * database, cache, queue and session drivers. Names and versions only, never configuration values, so Magpie can
 * list what every application is built with.
 *
 * A snapshot is sent when it changes and at most once a day otherwise. The cache remembers what was sent, so
 * PHP-FPM requests do not each repeat it.
 */
final class Platform
{
    /** Package => the name Magpie shows. */
    private const PACKAGES = [
        'laravel/octane' => 'octane',
        'laravel/horizon' => 'horizon',
        'livewire/livewire' => 'livewire',
        'inertiajs/inertia-laravel' => 'inertia',
        'filament/filament' => 'filament',
    ];

    private const RESEND_AFTER = 86400;

    private bool $checked = false;

    /**
     * @param  Closure(): array{components: array<string, string>, drivers: array<string, string>}  $snapshot
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly Closure $snapshot,
        private readonly string $environment,
    ) {}

    /**
     * The versions and driver names of a running Laravel application.
     *
     * @param  array<string, mixed>  $config  The application's configuration.
     * @return array{components: array<string, string>, drivers: array<string, string>}
     */
    public static function snapshot(string $laravelVersion, array $config): array
    {
        $components = ['php' => PHP_VERSION, 'laravel' => $laravelVersion, 'os' => PHP_OS_FAMILY, 'sapi' => PHP_SAPI];

        if (class_exists(InstalledVersions::class)) {
            foreach (self::PACKAGES as $package => $name) {
                if (InstalledVersions::isInstalled($package) && ($version = InstalledVersions::getPrettyVersion($package))) {
                    $components[$name] = ltrim($version, 'v');
                }
            }
        }

        $drivers = array_filter([
            'database' => self::driver($config, 'database.connections', 'database.default', 'driver'),
            'cache' => self::driver($config, 'cache.stores', 'cache.default', 'driver'),
            'queue' => self::driver($config, 'queue.connections', 'queue.default', 'driver'),
            'session' => is_string($config['session']['driver'] ?? null) ? $config['session']['driver'] : null,
        ]);

        return ['components' => $components, 'drivers' => $drivers];
    }

    /** Sends the snapshot when it is new or a day old, at most once per process. Never throws. */
    public function flush(Transport $transport): bool
    {
        if ($this->checked) {
            return true;
        }

        $this->checked = true;

        try {
            $snapshot = ($this->snapshot)();
            $key = 'magpie:platform:'.md5($this->environment."\0".json_encode($snapshot));

            // add() only succeeds for the first process to see this snapshot in the last day.
            if (! $this->cache->add($key, 1, self::RESEND_AFTER)) {
                return true;
            }

            if ($transport->send([
                'type' => 'platform',
                'environment' => $this->environment,
                'timestamp' => microtime(true),
                'sdk' => ['name' => 'magpie.php.laravel', 'version' => Magpie::version()],
            ] + $snapshot)) {
                return true;
            }

            $this->cache->forget($key);

            return false;
        } catch (Throwable) {
            // Reporting the platform must never break the application.
            return true;
        }
    }

    /** The driver name of the default connection, e.g. "mysql" or "sqs". Null when it cannot be told. */
    private static function driver(array $config, string $group, string $default, string $field): ?string
    {
        $name = data_get($config, $default);
        $driver = is_string($name) ? data_get($config, $group.'.'.$name.'.'.$field) : null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }
}
