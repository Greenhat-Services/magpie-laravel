<?php

namespace Magpie\Laravel\Tests;

use Magpie\Laravel\Magpie;

class EnvironmentTest extends TestCase
{
    private function withEnvironment(string $environment, array $disabled): Magpie
    {
        $this->app['config']->set('magpie.environment', $environment);
        $this->app['config']->set('magpie.disabled_environments', $disabled);
        $this->app->forgetInstance(Magpie::class);

        return $this->magpie();
    }

    public function test_nothing_is_sent_from_a_disabled_environment(): void
    {
        $magpie = $this->withEnvironment('local', ['local']);

        $this->assertFalse($magpie->enabled());
        $this->assertNull($magpie->captureMessage('hello'));
    }

    public function test_the_match_ignores_case(): void
    {
        $this->assertFalse($this->withEnvironment('Local', ['LOCAL'])->enabled());
    }

    public function test_other_environments_still_send(): void
    {
        $this->assertTrue($this->withEnvironment('staging', ['local'])->enabled());
    }

    public function test_an_empty_list_sends_from_every_environment(): void
    {
        $this->assertTrue($this->withEnvironment('local', [])->enabled());
    }

    public function test_local_is_disabled_by_default(): void
    {
        $this->assertSame(['local'], $this->app['config']->get('magpie.disabled_environments'));
    }
}
