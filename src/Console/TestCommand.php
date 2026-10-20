<?php

namespace Magpie\Laravel\Console;

use Exception;
use Illuminate\Console\Command;
use Magpie\Laravel\Dsn;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\Transport\HttpTransport;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'magpie:test';

    protected $description = 'Send a test exception to Magpie to verify the SDK is configured';

    public function handle(Magpie $magpie): int
    {
        $dsn = (string) config('magpie.dsn');

        if ($dsn === '') {
            $this->components->error('MAGPIE_LARAVEL_DSN is not set. Copy the DSN from App settings > DSN keys.');

            return self::FAILURE;
        }

        try {
            $host = Dsn::parse($dsn)->host;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Sending a test event to {$host}...");

        $id = $magpie->captureException(new Exception('This is a test exception sent by php artisan magpie:test'));

        if ($id === null) {
            $this->components->error('The event was dropped. Check the sample_rate, ignore_exceptions and before_send options.');

            return self::FAILURE;
        }

        if (! $magpie->flush()) {
            $reason = $this->laravel->bound(HttpTransport::class) ? $this->laravel->make(HttpTransport::class)->lastError() : null;
            $this->components->error('Magpie did not accept the event'.($reason ? ": {$reason}" : '.').' Check the DSN, the key and network access to the ingest host.');

            return self::FAILURE;
        }

        $this->components->info("Sent event {$id}. It should appear in Magpie shortly.");

        return self::SUCCESS;
    }
}
