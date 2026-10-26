<?php

namespace Magpie\Laravel\Tests;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Magpie\Laravel\Facades\Magpie as MagpieFacade;
use Magpie\Laravel\Integration;
use Magpie\Laravel\Magpie;
use Magpie\Laravel\Scope;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

class CaptureTest extends TestCase
{
    public function test_it_captures_an_exception(): void
    {
        $id = $this->magpie()->captureException(new RuntimeException('Deadlock found'));
        $event = $this->lastEvent();

        $this->assertSame($id, $event['event_id']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('php', $event['platform']);
        $this->assertSame('magpie.php.laravel', $event['sdk']['name']);
        $this->assertSame(RuntimeException::class, $event['exception']['values'][0]['type']);
        $this->assertSame('Deadlock found', $event['exception']['values'][0]['value']);
        $this->assertTrue($event['exception']['values'][0]['mechanism']['handled']);
        $this->assertNotEmpty($event['exception']['values'][0]['stacktrace']['frames']);
        $this->assertSame($this->app->version(), $event['contexts']['laravel']['version']);
    }

    public function test_chained_exceptions_list_the_root_cause_first(): void
    {
        $this->magpie()->captureException(new RuntimeException('outer', 0, new InvalidArgumentException('inner')));
        $values = $this->lastEvent()['exception']['values'];

        $this->assertSame(InvalidArgumentException::class, $values[0]['type']);
        $this->assertSame(RuntimeException::class, $values[1]['type']);
    }

    public function test_integration_marks_exceptions_as_unhandled(): void
    {
        Integration::captureUnhandledException(new Exception('crash'));

        $this->assertFalse($this->lastEvent()['exception']['values'][0]['mechanism']['handled']);
    }

    public function test_the_same_exception_is_only_reported_once(): void
    {
        $e = new Exception('once');

        $this->assertNotNull($this->magpie()->captureException($e));
        $this->assertNull($this->magpie()->captureException($e));
    }

    public function test_it_captures_messages(): void
    {
        MagpieFacade::captureMessage('Nightly sync is late', 'warning');
        $event = $this->lastEvent();

        $this->assertSame('Nightly sync is late', $event['message']);
        $this->assertSame('warning', $event['level']);
    }

    public function test_scope_data_is_attached(): void
    {
        $this->magpie()->configureScope(function (Scope $scope) {
            $scope->setTag('tenant', 'acme')->setUser(['id' => 5])->setExtra('order', 'ord_1')->setTransaction('POST /bookings');
        });
        $this->magpie()->captureMessage('hello');
        $event = $this->lastEvent();

        $this->assertSame(['tenant' => 'acme'], $event['tags']);
        $this->assertSame(['id' => 5], $event['user']);
        $this->assertSame('ord_1', $event['extra']['order']);
        $this->assertSame('POST /bookings', $event['transaction']);
    }

    public function test_with_scope_changes_do_not_leak(): void
    {
        $this->magpie()->withScope(function (Scope $scope) {
            $scope->setTag('temp', 'yes');
            $this->magpie()->captureMessage('inside');
        });
        $this->magpie()->captureMessage('outside');
        $this->magpie()->flush();

        $this->assertSame(['temp' => 'yes'], $this->transport->payloads[0]['tags']);
        $this->assertArrayNotHasKey('tags', $this->transport->payloads[1]);
    }

    public function test_ignored_exceptions_and_sample_rate(): void
    {
        config()->set('magpie.ignore_exceptions', [InvalidArgumentException::class]);
        $this->app->forgetInstance(Magpie::class);
        $this->assertNull($this->magpie()->captureException(new InvalidArgumentException('nope')));

        config()->set('magpie.sample_rate', 0.0);
        $this->app->forgetInstance(Magpie::class);
        $this->assertNull($this->magpie()->captureException(new Exception('sampled out')));
    }

    public function test_before_send_can_modify_or_drop_events(): void
    {
        config()->set('magpie.before_send', fn (array $event) => str_contains($event['message'] ?? '', 'drop') ? null : $event + ['tags' => ['seen' => '1']]);
        $this->app->forgetInstance(Magpie::class);

        $this->assertNull($this->magpie()->captureMessage('please drop'));
        $this->magpie()->captureMessage('keep');

        $this->assertSame(['seen' => '1'], $this->lastEvent()['tags']);
    }

    public function test_before_send_also_applies_to_transactions(): void
    {
        config()->set('magpie.before_send', fn (array $event) => ($event['type'] ?? '') === 'transaction' ? null : $event);
        $this->app->forgetInstance(Magpie::class);

        $this->magpie()->startTransaction('GET /x');
        $this->assertNull($this->magpie()->finishTransaction());
    }

    public function test_database_error_values_are_removed_from_exception_messages(): void
    {
        $this->magpie()->captureException(new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.test' for key 'users_email_unique' (Connection: mysql, SQL: insert into users (email) values (a@b.test))"));
        $value = $this->lastEvent()['exception']['values'][0]['value'];

        $this->assertStringNotContainsString('a@b.test', $value);
        $this->assertStringContainsString('users_email_unique', $value);
    }

    protected function withBindings($app): void
    {
        $app['config']->set('magpie.breadcrumbs.sql_bindings', true);
    }

    #[DefineEnvironment('withBindings')]
    public function test_cache_keys_and_sql_bindings_are_scrubbed(): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::select('select ? as a', ['plain']);
        DB::select('select ? as password', ['hunter2']);
        Cache::get('profile:a@b.test');
        Cache::get('reset_token:42');

        $this->magpie()->captureMessage('after');
        $json = json_encode($this->lastEvent()['breadcrumbs']);

        $this->assertStringContainsString('plain', $json);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('a@b.test', $json);
        $this->assertStringNotContainsString('reset_token:42', $json);
    }

    public function test_logs_and_queries_become_breadcrumbs(): void
    {
        Log::info('Slot reserved', ['slot' => 4]);
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::select('select 1');

        $this->magpie()->captureMessage('after');
        $crumbs = $this->lastEvent()['breadcrumbs'];

        $this->assertSame('log', $crumbs[0]['category']);
        $this->assertSame('Slot reserved', $crumbs[0]['message']);
        $this->assertSame('sql.query', $crumbs[1]['category']);
        $this->assertSame('select 1', $crumbs[1]['message']);
    }

    public function test_it_is_disabled_without_a_dsn(): void
    {
        $magpie = new Magpie([], null);

        $this->assertFalse($magpie->enabled());
        $this->assertNull($magpie->captureException(new Exception('x')));
    }
}
