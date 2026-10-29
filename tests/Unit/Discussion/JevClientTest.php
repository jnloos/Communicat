<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Jev\JevClient;
use App\Discussion\Jev\JevNotConfigured;
use App\Discussion\Jev\JevRejected;
use App\Discussion\Values\CallStatus;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\JevReply;
use App\Discussion\Values\Purpose;
use App\Models\PromptLog;
use Carbon\CarbonInterval;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeJev;
use Tests\TestCase;
use TypeError;

/**
 * What JevClient does with each kind of failure: transient ones are retried and
 * then fall back, rejections and unexpected errors are not swallowed.
 */
class JevClientTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_STATE = 'GEHEIME DISKUSSION UEBER SCHULEN';

    private function ask(): JevReply
    {
        return app(JevClient::class)->ask(
            JevDecisionModel::fromConfig(JevDecides::Selector),
            self::SECRET_STATE,
            ['speaker' => new Choice('Wer?', ['E1' => 'Alice', 'E2' => 'Bob'])],
            Purpose::Select,
            null,
        );
    }

    private function httpError(int $status, string $body = '', array $headers = []): RequestException
    {
        return new RequestException(new HttpResponse(new Psr7Response($status, $headers, $body)));
    }

    private function answerBody(): array
    {
        return ['answers' => ['speaker' => ['type' => 'choice', 'choice' => 'E1', 'probabilities' => ['E1' => 0.7, 'E2' => 0.3], 'confidence' => 0.9]], 'model' => 'jev-1.13.0', 'usage' => ['input_tokens' => 12, 'output_tokens' => 3]];
    }

    private function sleptMilliseconds(): array
    {
        $slept = (new \ReflectionClass(Sleep::class))->getProperty('sequence')->getValue();

        return array_map(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds, $slept);
    }

    public static function rejections(): array
    {
        return [
            '400' => [400, '{"error":{"message":"bad format"}}', 'bad format'],
            '401' => [401, '{"error":{"message":"Invalid API key"}}', 'Invalid API key'],
            '403' => [403, '{"message":"Forbidden for this key"}', 'Forbidden for this key'],
            '422' => [422, '{"error":"criteria too long"}', 'criteria too long'],
            'no readable body' => [401, '<html>nope</html>', ''],
        ];
    }

    #[DataProvider('rejections')]
    public function test_a_rejected_request_throws_a_typed_exception_is_recorded_and_never_retried_or_swallowed(int $status, string $body, string $said): void
    {
        FakeJev::fails($this->httpError($status, $body));

        try {
            $this->ask();
            $this->fail('A rejected request must not become a fallback.');
        } catch (JevRejected $e) {
            $this->assertSame($status, $e->status);
            $this->assertSame($said, $e->detail);
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
        }

        Sleep::assertNeverSlept();
        $this->assertCount(1, FakeJev::prompts(), 'asked once');
        $log = PromptLog::sole();
        $this->assertSame(CallStatus::Failed, $log->status);
        $this->assertStringContainsString("JevRejected: Jev rejected the request (HTTP {$status})", $log->error);
        $this->assertSame(1, $log->config['attempts']);
    }

    public function test_a_rejection_never_echoes_the_request_and_keeps_only_a_short_excerpt_without_the_key(): void
    {
        FakeJev::fails($this->httpError(422, json_encode(['error' => ['message' => "key sk-test-secret-key said\n".str_repeat('x', 500)]])));
        config(['ai.providers.typesafe.key' => 'sk-test-secret-key']);

        try {
            $this->ask();
            $this->fail('Expected a rejection.');
        } catch (JevRejected $e) {
            $this->assertStringNotContainsString(self::SECRET_STATE, $e->getMessage());
            $this->assertStringNotContainsString('sk-test-secret-key', $e->getMessage());
            $this->assertStringContainsString('[key]', $e->detail);
            $this->assertStringNotContainsString("\n", $e->detail);
            $this->assertLessThanOrEqual(JevRejected::DETAIL_LIMIT, mb_strlen($e->detail));
        }

        $this->assertLessThanOrEqual(300, mb_strlen(PromptLog::sole()->error));
        $this->assertStringNotContainsString(self::SECRET_STATE, PromptLog::sole()->error);
    }

    public function test_exhausted_credit_is_a_rejection_not_a_fallback(): void
    {
        FakeJev::fails(InsufficientCreditsException::forProvider('typesafe'));

        try {
            $this->ask();
            $this->fail('Expected a rejection.');
        } catch (JevRejected $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame('insufficient credits or quota', $e->detail);
        }

        Sleep::assertNeverSlept();
    }

    public function test_the_latency_is_the_time_the_call_took_not_zero(): void
    {
        FakeJev::answering(function () {
            usleep(30_000);

            return ['speaker' => new ChoiceAnswer('E1', ['E1' => 0.7, 'E2' => 0.3], 0.9)];
        });

        $reply = $this->ask();

        $this->assertGreaterThanOrEqual(25, $reply->latencyMs);
        $this->assertGreaterThanOrEqual(25, PromptLog::sole()->latency_ms);
    }

    public function test_a_rate_limit_is_retried_after_the_wait_the_api_asked_for_and_then_succeeds(): void
    {
        FakeJev::steps([
            RateLimitedException::forProvider('typesafe', 429, $this->httpError(429, '', ['Retry-After' => '2'])),
            ['speaker' => new ChoiceAnswer('E1', ['E1' => 0.7, 'E2' => 0.3], 0.9)],
        ]);

        $reply = $this->ask();

        $this->assertTrue($reply->succeeded());
        $this->assertSame(2, $reply->attempts);
        $this->assertSame([2000], $this->sleptMilliseconds(), 'Retry-After 2 s is longer than the 500 ms backoff');
        $this->assertSame(2, PromptLog::sole()->config['attempts'], 'one row for the request, however often it was sent');
        $this->assertSame(CallStatus::Ok, PromptLog::sole()->status);
    }

    public function test_the_backoff_doubles_is_capped_and_a_request_that_keeps_failing_falls_back_after_three_attempts(): void
    {
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));

        $reply = $this->ask();

        $this->assertFalse($reply->succeeded());
        $this->assertSame(3, $reply->attempts);
        $this->assertSame([500, 1000], $this->sleptMilliseconds());
        $this->assertCount(3, FakeJev::prompts());
        $this->assertStringContainsString('ProviderOverloadedException', $reply->error);
        $this->assertStringContainsString('after 3 attempts', $reply->error);
        $this->assertSame(CallStatus::Failed, PromptLog::sole()->status);
    }

    public function test_a_retry_after_beyond_the_cap_waits_no_longer_than_the_cap(): void
    {
        config(['ai.jev.retries' => 1]);
        FakeJev::fails(RateLimitedException::forProvider('typesafe', 429, $this->httpError(429, '', ['Retry-After' => '3600'])));

        $this->ask();

        $this->assertSame([30_000], $this->sleptMilliseconds());
    }

    public function test_retries_are_configurable_and_zero_means_one_attempt(): void
    {
        config(['ai.jev.retries' => 0]);
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));

        $reply = $this->ask();

        $this->assertSame(1, $reply->attempts);
        Sleep::assertNeverSlept();
    }

    public function test_a_timeout_or_refused_connection_is_transient_and_falls_back(): void
    {
        FakeJev::fails(ProviderConnectionException::forProvider('typesafe'));

        $this->assertFalse($this->ask()->succeeded());
        $this->assertCount(3, FakeJev::prompts());

        FakeJev::fails(new ConnectionException('cURL error 28: Operation timed out'));

        $reply = $this->ask();

        $this->assertFalse($reply->succeeded());
        $this->assertStringContainsString('ConnectionException', $reply->error);
    }

    public function test_a_server_error_is_transient_even_when_the_sdk_does_not_map_it(): void
    {
        FakeJev::fails($this->httpError(500, 'oops'));

        $reply = $this->ask();

        $this->assertFalse($reply->succeeded());
        $this->assertSame(3, $reply->attempts);
    }

    public function test_an_unexpected_error_is_not_a_fallback_and_is_not_retried(): void
    {
        FakeJev::fails(new TypeError('a bug in our own code'));

        $this->expectException(TypeError::class);

        try {
            $this->ask();
        } finally {
            Sleep::assertNeverSlept();
            $this->assertCount(1, FakeJev::prompts());
        }
    }

    public function test_a_missing_key_throws_before_anything_is_sent(): void
    {
        FakeJev::randomly();
        config(['ai.providers.typesafe.key' => null]);

        $this->expectException(JevNotConfigured::class);

        try {
            $this->ask();
        } finally {
            $this->assertSame([], FakeJev::prompts());
            $this->assertSame(0, PromptLog::count());
        }
    }

    /** Through the SDK's own TypeSafe gateway, so the mapping of real HTTP errors is the one in production. */
    public function test_through_the_real_gateway_a_429_with_retry_after_is_retried_and_succeeds(): void
    {
        FakeJev::key();
        Sleep::fake();
        Http::fake(['api.typesafe.ai/*' => Http::sequence()->push('', 429, ['Retry-After' => '3'])->push($this->answerBody())]);
        Log::spy();

        $reply = $this->ask();

        Http::assertSentCount(2);
        $this->assertTrue($reply->succeeded());
        $this->assertSame([3000], $this->sleptMilliseconds());
        $this->assertSame(12, PromptLog::sole()->tokens_in);
        $this->assertSame('E1', $reply->choice('speaker', ['E1', 'E2'])->leaders[0]);
    }

    public function test_through_the_real_gateway_a_401_is_a_rejection_and_a_timeout_a_fallback(): void
    {
        FakeJev::key();
        Sleep::fake();
        Http::fake(['api.typesafe.ai/*' => Http::response('{"error":{"message":"Invalid API key"}}', 401)]);

        try {
            $this->ask();
            $this->fail('A 401 must not become a fallback.');
        } catch (JevRejected $e) {
            $this->assertSame(401, $e->status);
        }

        Http::assertSentCount(1);

        Http::fake(['api.typesafe.ai/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->assertFalse($this->ask()->succeeded());
        $this->assertSame([500, 1000], $this->sleptMilliseconds(), 'sent three times, so slept twice');
    }
}
