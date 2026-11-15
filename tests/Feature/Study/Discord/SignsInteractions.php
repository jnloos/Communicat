<?php

namespace Tests\Feature\Study\Discord;

use Illuminate\Testing\TestResponse;

/**
 * Signs interaction requests like Discord does. The route exists only when the key is in the
 * environment at boot, so it is set before the application is created and removed after.
 */
trait SignsInteractions
{
    private string $secretKey;

    protected function enableInteractions(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $_ENV['DISCORD_PUBLIC_KEY'] = $_SERVER['DISCORD_PUBLIC_KEY'] = bin2hex(sodium_crypto_sign_publickey($pair));
        $_ENV['DISCORD_CHANNEL_ID'] = $_SERVER['DISCORD_CHANNEL_ID'] = 'study-channel';
    }

    protected function disableInteractions(): void
    {
        $_ENV['DISCORD_PUBLIC_KEY'] = $_SERVER['DISCORD_PUBLIC_KEY'] = '';
        $_ENV['DISCORD_CHANNEL_ID'] = $_SERVER['DISCORD_CHANNEL_ID'] = '';
    }

    /** @return array{signature: string, timestamp: string} */
    protected function signed(string $body, ?int $at = null): array
    {
        $timestamp = (string) ($at ?? time());

        return ['timestamp' => $timestamp, 'signature' => bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey))];
    }

    protected function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_X_SIGNATURE_'.($name === 'signature' ? 'ED25519' : 'TIMESTAMP')] = $value;
        }

        return $server;
    }

    protected function interact(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/discord/interactions', server: $this->server($this->signed($body)), content: $body);
    }
}
