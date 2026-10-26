<?php

namespace Magpie\Laravel\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\Events\RouteMatched;
use Magpie\Laravel\Jobs\SendPayload;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\RequestContext;
use Magpie\Laravel\Tracing\TraceHeader;
use Throwable;

/**
 * Breadcrumbs, spans and the queue and console lifecycle.
 */
final class EventsSubscriber
{
    /** Long-running commands are not traced as a single transaction. */
    private const UNTRACED_COMMANDS = ['queue:work', 'queue:listen', 'schedule:work', 'horizon', 'octane:start', 'serve', 'reverb:start', 'pail', 'tinker'];

    private const LOG_LEVELS = [
        'debug' => 'debug', 'info' => 'info', 'notice' => 'info', 'warning' => 'warning',
        'error' => 'error', 'critical' => 'fatal', 'alert' => 'fatal', 'emergency' => 'fatal',
    ];

    /** Nesting depth of Artisan commands, so a command called from another keeps the outer transaction. */
    private int $commandDepth = 0;

    /**
     * Start times of jobs running inline on the sync driver, keyed by job object id.
     *
     * @var array<int, float>
     */
    private array $syncJobs = [];

    public function __construct(private readonly Magpie $magpie) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RouteMatched::class, $this->routeMatched(...));
        $events->listen(MessageLogged::class, $this->messageLogged(...));
        $events->listen(QueryExecuted::class, $this->queryExecuted(...));
        $events->listen(JobProcessing::class, $this->jobProcessing(...));
        $events->listen(JobProcessed::class, $this->jobProcessed(...));
        $events->listen(JobExceptionOccurred::class, $this->jobException(...));
        $events->listen(JobFailed::class, $this->jobFailed(...));
        $events->listen(CommandStarting::class, $this->commandStarting(...));
        $events->listen(CommandFinished::class, $this->commandFinished(...));
    }

    private function routeMatched(RouteMatched $event): void
    {
        $name = $event->request->method().' /'.ltrim($event->route->uri(), '/');

        $this->magpie->scope()->setTransaction($name);
        $this->magpie->renameTransaction($name);
    }

    private function messageLogged(MessageLogged $event): void
    {
        if (! $this->magpie->option('breadcrumbs.logs', true) || ($event->context['exception'] ?? null) instanceof Throwable) {
            return;
        }

        $this->magpie->addBreadcrumb([
            'type' => 'default',
            'category' => 'log',
            'level' => self::LOG_LEVELS[$event->level] ?? 'info',
            'message' => (string) $event->message,
            'data' => $event->context ? RequestContext::scrub($event->context) : null,
        ]);
    }

    private function queryExecuted(QueryExecuted $event): void
    {
        $start = microtime(true) - $event->time / 1000;

        if ($this->magpie->option('breadcrumbs.sql_queries', true)) {
            $this->magpie->addBreadcrumb(array_filter([
                'type' => 'query',
                'category' => 'sql.query',
                'message' => $event->sql,
                'timestamp' => $start,
                'data' => array_filter([
                    'connection' => $event->connectionName,
                    'time_ms' => round($event->time, 2),
                    'bindings' => $this->magpie->option('breadcrumbs.sql_bindings', false) ? RequestContext::scrubBindings($event->sql, $event->bindings) : null,
                ], fn ($v) => $v !== null),
            ]));
        }

        $this->magpie->addSpan('db.sql.query', $event->sql, $start, $event->time / 1000, ['db.connection' => $event->connectionName]);
    }

    private function jobProcessing(JobProcessing $event): void
    {
        $name = $event->job->resolveName();

        if ($this->isOwnJob($name)) {
            return;
        }

        // A sync job runs inside the current request, so it becomes a span rather than a new unit of work.
        if ($event->job instanceof SyncJob) {
            $this->syncJobs[spl_object_id($event->job)] = microtime(true);
            $this->magpie->addBreadcrumb(['category' => 'queue.job', 'message' => 'Processing '.$name, 'data' => ['connection' => 'sync']]);

            return;
        }

        // Workers handle many jobs in one process, so every job starts with a clean scope.
        $this->magpie->reset();
        $this->magpie->scope()->setTransaction($name);

        if ($this->magpie->option('breadcrumbs.queue_info', true)) {
            $this->magpie->scope()->setTags(['queue' => (string) $event->job->getQueue(), 'queue.connection' => (string) $event->connectionName]);
            $this->magpie->addBreadcrumb(['category' => 'queue.job', 'message' => 'Processing '.$name, 'data' => ['attempts' => $event->job->attempts()]]);
        }

        $parent = TraceHeader::parse($event->job->payload()[TraceHeader::PAYLOAD_KEY] ?? null);

        $this->magpie->startTransaction($name, 'queue.process', $parent);
    }

    private function jobProcessed(JobProcessed $event): void
    {
        if ($this->isOwnJob($event->job->resolveName())) {
            return;
        }

        if ($this->finishSyncJob($event->job, 'ok')) {
            return;
        }

        $this->magpie->finishTransaction(['status' => 'ok']);
        $this->magpie->flush();
    }

    private function jobException(JobExceptionOccurred $event): void
    {
        if ($this->isOwnJob($event->job->resolveName())) {
            return;
        }

        $this->magpie->captureException($event->exception, ['handled' => false, 'mechanism' => 'queue']);

        if ($this->finishSyncJob($event->job, 'internal_error')) {
            return;
        }

        $this->magpie->finishTransaction(['status' => 'internal_error']);
        $this->magpie->flush();
    }

    /** Record a sync job as a span on the surrounding transaction. Returns false for real queue jobs. */
    private function finishSyncJob(object $job, string $status): bool
    {
        if (! $job instanceof SyncJob) {
            return false;
        }

        $start = $this->syncJobs[spl_object_id($job)] ?? microtime(true);
        unset($this->syncJobs[spl_object_id($job)]);

        $this->magpie->addSpan('queue.process', $job->resolveName(), $start, microtime(true) - $start, ['status' => $status]);

        return true;
    }

    private function jobFailed(JobFailed $event): void
    {
        if (! $this->isOwnJob($event->job->resolveName())) {
            $this->magpie->flush();
        }
    }

    private function commandStarting(CommandStarting $event): void
    {
        $command = (string) $event->command;

        if ($this->commandDepth++ > 0) {
            return;
        }

        $this->magpie->scope()->setTransaction($command);

        if ($this->magpie->option('breadcrumbs.command_info', true)) {
            $this->magpie->addBreadcrumb(['category' => 'console.command', 'message' => 'Running '.$command]);
        }

        if (! in_array($command, self::UNTRACED_COMMANDS, true)) {
            $this->magpie->startTransaction($command, 'console.command');
        }
    }

    private function commandFinished(CommandFinished $event): void
    {
        if (--$this->commandDepth > 0) {
            return;
        }

        $this->commandDepth = 0;
        $this->magpie->finishTransaction(['status' => $event->exitCode === 0 ? 'ok' : 'internal_error']);
        $this->magpie->flush();
    }

    /** The SDK's own delivery job is never traced or reported, or a failing Magpie would feed itself. */
    private function isOwnJob(string $name): bool
    {
        return $name === SendPayload::class;
    }
}
