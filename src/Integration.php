<?php

namespace Magpie\Laravel;

use Illuminate\Foundation\Configuration\Exceptions;
use Throwable;

/**
 * Hooks for bootstrap/app.php and application code.
 */
final class Integration
{
    /**
     * Report every exception Laravel reports to Magpie.
     *
     *     ->withExceptions(function (Exceptions $exceptions) {
     *         Integration::handles($exceptions);
     *     })
     */
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): void {
            self::captureUnhandledException($exception);
        });
    }

    public static function captureUnhandledException(Throwable $exception): ?string
    {
        return self::client()?->captureException($exception, ['handled' => false, 'mechanism' => 'laravel']);
    }

    /** @param  array<string, mixed>  $breadcrumb */
    public static function addBreadcrumb(array $breadcrumb): void
    {
        self::client()?->addBreadcrumb($breadcrumb);
    }

    public static function setTransaction(?string $name): void
    {
        self::client()?->scope()->setTransaction($name);
    }

    private static function client(): ?Magpie
    {
        $app = function_exists('app') ? app() : null;

        return $app && $app->bound(Magpie::class) ? $app->make(Magpie::class) : null;
    }
}
