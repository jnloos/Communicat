<?php

namespace App\Study\Discord;

/** Discord signs every interaction with Ed25519 over timestamp + the raw body. */
class SignatureVerifier
{
    /** Discord does not demand it; a replay window of five minutes costs nothing. */
    private const MAX_AGE_SECONDS = 300;

    public function valid(string $rawBody, ?string $signature, ?string $timestamp): bool
    {
        $key = (string) config('study.discord.public_key');

        if ($signature === null || $timestamp === null
            || ! $this->hex($signature, 128) || ! $this->hex($key, 64)
            || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp.$rawBody, hex2bin($key));
    }

    private function hex(string $value, int $length): bool
    {
        return strlen($value) === $length && ctype_xdigit($value);
    }
}
