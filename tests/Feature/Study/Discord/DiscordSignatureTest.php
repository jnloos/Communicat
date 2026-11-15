<?php

namespace Tests\Feature\Study\Discord;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscordSignatureTest extends TestCase
{
    use SignsInteractions;

    protected function setUp(): void
    {
        $this->enableInteractions();
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->disableInteractions();
    }

    public function test_a_valid_signature_is_accepted(): void
    {
        $this->interact(['type' => 1])->assertOk()->assertExactJson(['type' => 1]);
    }

    public function test_a_body_changed_after_signing_is_refused(): void
    {
        $body = '{"type":1}';
        $headers = $this->signed($body);

        $this->call('POST', '/discord/interactions', server: $this->server($headers), content: '{"type":2}')
            ->assertUnauthorized()->assertContent('');
    }

    public function test_a_foreign_key_is_refused(): void
    {
        $body = '{"type":1}';
        $other = sodium_crypto_sign_keypair();
        $timestamp = (string) time();
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey($other)));

        $this->call('POST', '/discord/interactions', server: $this->server(['signature' => $signature, 'timestamp' => $timestamp]), content: $body)
            ->assertUnauthorized();
    }

    public function test_missing_or_broken_headers_are_refused(): void
    {
        $body = '{"type":1}';
        $good = $this->signed($body);

        foreach ([[], ['signature' => 'abcd', 'timestamp' => $good['timestamp']], ['signature' => str_repeat('z', 128), 'timestamp' => $good['timestamp']], ['signature' => $good['signature']]] as $headers) {
            $this->call('POST', '/discord/interactions', server: $this->server($headers), content: $body)->assertUnauthorized();
        }
    }

    public function test_an_old_timestamp_is_refused(): void
    {
        $body = '{"type":1}';

        $this->call('POST', '/discord/interactions', server: $this->server($this->signed($body, time() - 301)), content: $body)
            ->assertUnauthorized();
    }

    public function test_it_needs_no_session_csrf_or_login(): void
    {
        $this->interact(['type' => 1])->assertOk();
        $this->assertGuest();
    }
}
