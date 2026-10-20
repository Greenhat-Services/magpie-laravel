<?php

namespace Magpie\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Magpie\Laravel\Magpie as Client;
use Magpie\Laravel\Scope;

/**
 * @method static string|null captureException(\Throwable $exception, array<string, mixed> $hint = [])
 * @method static string|null captureMessage(string $message, string $level = 'info')
 * @method static void addBreadcrumb(array<string, mixed> $breadcrumb)
 * @method static void configureScope(callable $callback)
 * @method static mixed withScope(callable $callback)
 * @method static Scope scope()
 * @method static void reset()
 * @method static bool startTransaction(string $name, string $op = 'http.server', array{trace_id: string, span_id: string, sampled: bool|null}|null $parent = null)
 * @method static bool tracing()
 * @method static void renameTransaction(string $name)
 * @method static void addSpan(string $op, string $description, float $start, float $durationSeconds, array<string, mixed> $data = [])
 * @method static string|null finishTransaction(array<string, mixed> $data = [])
 * @method static string|null traceHeader()
 * @method static bool flush()
 * @method static bool enabled()
 *
 * @see Client
 */
class Magpie extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
