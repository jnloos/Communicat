<?php

namespace Tests\Unit\Discussion;

use App\Discussion\ErrorText;
use App\Discussion\Values\ChildFailure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\RateLimitedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorTextTest extends TestCase
{
    private function rateLimited(string $body): RateLimitedException
    {
        $previous = new RequestException(new Response(new Psr7Response(429, [], $body)));

        return RateLimitedException::forProvider('openai', 429, $previous);
    }

    public function test_the_providers_error_code_survives_the_sdks_rate_limit_exception(): void
    {
        $e = $this->rateLimited('{"error":{"message":"You exceeded your current quota","type":"insufficient_quota","code":"insufficient_quota"}}');

        $this->assertStringContainsString('insufficient_quota', ErrorText::of($e));
        $this->assertStringContainsString('rate limited', ErrorText::of($e));
        $this->assertStringContainsString('insufficient_quota', ChildFailure::of($e)->describe());
    }

    public function test_a_plain_rate_limit_and_other_errors_keep_their_message(): void
    {
        $this->assertStringNotContainsString('provider error', ErrorText::of($this->rateLimited('not json')));
        $this->assertSame('boom', ErrorText::of(new RuntimeException('boom')));
        $this->assertSame('boom', ErrorText::of(new RuntimeException('boom', 0, new RuntimeException('inner'))));
    }
}
