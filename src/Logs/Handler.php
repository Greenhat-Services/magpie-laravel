<?php

namespace Magpie\Laravel\Logs;

use Magpie\Laravel\Magpie;
use Magpie\Laravel\RequestContext;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Sends log records to Magpie: records carrying an `exception` become exceptions, the rest messages.
 */
class Handler extends AbstractProcessingHandler
{
    private const LEVELS = [
        'debug' => 'debug', 'info' => 'info', 'notice' => 'info', 'warning' => 'warning',
        'error' => 'error', 'critical' => 'fatal', 'alert' => 'fatal', 'emergency' => 'fatal',
    ];

    public function __construct(private readonly Magpie $magpie, int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $level = self::LEVELS[strtolower($record->level->getName())] ?? 'error';
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $this->magpie->withScope(function ($scope) use ($record, $context, $exception, $level) {
            $scope->setTag('log.channel', $record->channel);
            $scope->setLevel($level);

            if ($context) {
                $scope->setExtra('log.context', RequestContext::scrub($context));
            }

            if ($exception instanceof Throwable) {
                $this->magpie->captureException($exception, ['mechanism' => 'monolog', 'level' => $level]);
            } else {
                $this->magpie->captureMessage($record->message, $level);
            }
        });
    }
}
