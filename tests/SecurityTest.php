<?php

namespace Magpie\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Magpie\Laravel\Logs\Handler;
use Magpie\Laravel\RequestContext;
use Monolog\Logger;

/**
 * What must never leave the application, whatever the configuration.
 */
class SecurityTest extends TestCase
{
    /** @param  array<string, string>  $server */
    private function request(array $server = [], string $url = 'https://app.test/admin?author=7&code=oauth-123&discard=1&page=2'): Request
    {
        return Request::create($url, 'GET', [], [], [], $server);
    }

    public function test_basic_auth_and_custom_credential_headers_are_filtered(): void
    {
        $headers = RequestContext::request($this->request([
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'Sup3rSecret!',
            'HTTP_X_AUTH_TOKEN' => 'tok_live_abc',
            'HTTP_X_ACCESS_TOKEN' => 'at_abc',
            'HTTP_X_SESSION_ID' => 'sess',
            'HTTP_ACCEPT' => 'application/json',
        ]), false)['headers'];

        foreach (['php-auth-user', 'php-auth-pw', 'authorization', 'x-auth-token', 'x-access-token', 'x-session-id'] as $name) {
            $this->assertSame('[Filtered]', $headers[$name], $name);
        }

        $this->assertSame('application/json', $headers['accept']);
        $this->assertStringNotContainsString('Sup3rSecret', json_encode($headers));
    }

    public function test_client_ip_headers_are_only_sent_with_pii(): void
    {
        $server = [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            'HTTP_X_REAL_IP' => '203.0.113.9',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_FORWARDED' => 'for=203.0.113.9',
            'HTTP_TRUE_CLIENT_IP' => '203.0.113.9',
        ];

        $this->assertStringNotContainsString('203.0.113.9', json_encode(RequestContext::request($this->request($server), false)));
        $this->assertSame('203.0.113.9', RequestContext::request($this->request($server), true)['headers']['x-real-ip']);
    }

    public function test_query_strings_and_referers_are_scrubbed_by_key(): void
    {
        $context = RequestContext::request($this->request(['HTTP_REFERER' => 'https://app.test/reset?token=reset-123&page=1']), false);
        parse_str($context['query_string'], $query);

        $this->assertSame('7', $query['author'], 'author is not a credential');
        $this->assertSame('1', $query['discard']);
        $this->assertSame('2', $query['page']);
        $this->assertSame('[Filtered]', $query['code'], 'an OAuth code is a credential');
        $this->assertSame('https://app.test/reset?token=%5BFiltered%5D&page=1', $context['headers']['referer']);
    }

    public function test_scrub_url_removes_userinfo_and_nested_sensitive_values(): void
    {
        $this->assertSame(
            'https://[Filtered]@api.test:8443/v1/x?filter%5Bapi_key%5D=%5BFiltered%5D&q=1#f',
            RequestContext::scrubUrl('https://user:pw@api.test:8443/v1/x?filter[api_key]=k&q=1#f'),
        );
    }

    public function test_secrets_in_url_paths_are_scrubbed(): void
    {
        $this->assertSame('https://app.test/password/reset/[Filtered]', RequestContext::scrubUrl('https://app.test/password/reset/abc'));
        $this->assertSame('https://app.test/files/[Filtered]/view', RequestContext::scrubUrl('https://app.test/files/'.str_repeat('a1', 16).'/view'));
        $this->assertSame('https://app.test/users/42/orders', RequestContext::scrubUrl('https://app.test/users/42/orders'));
    }

    public function test_log_context_is_scrubbed_in_breadcrumbs_and_log_channel_events(): void
    {
        Log::info('Login attempt', ['email' => 'a@b.test', 'password' => 'hunter2']);
        $this->magpie()->captureMessage('after');
        $crumbs = array_values(array_filter($this->lastEvent()['breadcrumbs'], fn ($c) => $c['category'] === 'log'));

        $this->assertSame('[Filtered]', $crumbs[0]['data']['password']);
        $this->assertSame('a@b.test', $crumbs[0]['data']['email']);

        (new Logger('app', [new Handler($this->magpie())]))->error('Card declined', ['card_number' => '4111', 'order' => 1]);
        $extra = $this->lastEvent()['extra']['log.context'];

        $this->assertSame('[Filtered]', $extra['card_number']);
        $this->assertSame(1, $extra['order']);
    }
}
