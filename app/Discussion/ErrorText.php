<?php

namespace App\Discussion;

use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * An exception's message for job_logs.error. The SDK turns an HTTP 429 into a RateLimitedException
 * whose message drops the body, so the provider's own error code (OpenAI's `insufficient_quota`)
 * is read from the previous RequestException and appended.
 */
final class ErrorText
{
    public static function of(Throwable $e): string
    {
        $previous = $e->getPrevious();
        $code = $previous instanceof RequestException
            ? ($previous->response->json('error.code') ?? $previous->response->json('error.type'))
            : null;

        return is_string($code) && $code !== '' ? "{$e->getMessage()} [provider error: {$code}]" : $e->getMessage();
    }
}
