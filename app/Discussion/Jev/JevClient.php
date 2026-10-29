<?php

namespace App\Discussion\Jev;

use App\Discussion\Logging\RecordJevCall;
use App\Discussion\Support\ModelIdentity;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\JevReply;
use App\Discussion\Values\Purpose;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Responses\ClassificationResponse;
use Throwable;

/**
 * The one way to Jev, through the SDK's Classification API (no HTTP client of
 * our own). Exactly one provider is passed -- the run's frozen JevDecisionModel,
 * always TypeSafe -- so the SDK has nothing to fail over to; the ProviderFailedOver
 * event it fires after a failed attempt anyway reaches GuardAgainstFailover.
 *
 * What a failed request means depends on why it failed:
 *
 *  - Transient: a timeout or refused connection, rate limiting (429), an
 *    overloaded or unavailable service (502, 503, 504, 520, 522, 524, 529), any
 *    other 5xx, 408. Retried up to `ai.jev.retries` times with doubling backoff
 *    (`ai.jev.backoff_ms`, a Retry-After header honoured up to `ai.jev.max_wait_ms`).
 *    If it still fails, the reply is a failed JevReply and the selector falls
 *    back; JevOutageGuard ends the run when that goes on for turns.
 *  - Rejected: every other HTTP error (400, 401, 403, 422 ...) and exhausted
 *    credit. A wrong key or a format Jev will not accept would turn the whole
 *    cell into random draws, so it is recorded and thrown as JevRejected, which
 *    ends the run.
 *  - Anything else (a TypeError, a bug) is not caught here at all.
 *
 * A missing key throws JevNotConfigured for the same reason. Every request, the
 * rejected one included, is recorded in prompt_logs (RecordJevCall).
 */
class JevClient
{
    public function __construct(private readonly RecordJevCall $record) {}

    /** @throws JevNotConfigured */
    public function assertConfigured(JevDecisionModel $model): void
    {
        if (blank(config("ai.providers.{$model->provider}.key"))) {
            throw JevNotConfigured::missingKey();
        }
    }

    /**
     * @param  array<string, Question>  $questions
     *
     * @throws JevNotConfigured
     * @throws JevRejected
     */
    public function ask(JevDecisionModel $model, string $state, array $questions, Purpose $purpose, ?int $jobLogId, ?int $expertId = null): JevReply
    {
        $this->assertConfigured($model);

        $record = fn (JevReply $reply) => $this->record->record($reply, $purpose, $jobLogId, $expertId, $state, $questions);
        $context = ['purpose' => $purpose->value, 'job_log_id' => $jobLogId];
        $started = hrtime(true);
        $attempts = 0;

        do {
            $attempts++;

            try {
                // Asked first, measured after: arguments are evaluated left to right, so the clock would stop before the call.
                $response = $this->classify($model, $state, $questions);
                $reply = JevReply::answered($model, $attempts, self::millisecondsSince($started), $response);
            } catch (FailoverableException|ConnectionException|RequestException $e) {
                $reply = $this->retryOrGiveUp($e, $model, $attempts, $started, $record, $context);
            }
        } while ($reply === null);

        $record($reply);
        $this->guardAgainstDrift($reply);

        return $reply;
    }

    /**
     * Null: the request goes out again (after the backoff). A failed reply: out of retries.
     *
     * @param  Closure(JevReply): void  $record
     * @param  array<string, mixed>  $context  for the log lines
     *
     * @throws JevRejected
     */
    private function retryOrGiveUp(Throwable $e, JevDecisionModel $model, int $attempts, int|float $started, Closure $record, array $context): ?JevReply
    {
        if (! self::isTransient($e)) {
            $rejection = self::rejection($e, $model);
            $record(JevReply::failed($model, $attempts, self::millisecondsSince($started), class_basename($rejection).': '.$rejection->getMessage()));

            throw $rejection;
        }

        $context += ['attempt' => $attempts, 'exception' => $e];

        if ($attempts <= (int) config('ai.jev.retries', 2)) {
            Log::warning('A Jev request failed transiently; it is sent again.', $context);
            Sleep::for(self::delayMs($e, $attempts))->milliseconds();

            return null;
        }

        Log::warning('A Jev request failed after every attempt; the selector falls back.', $context);

        return JevReply::failed($model, $attempts, self::millisecondsSince($started), class_basename($e).': '.$e->getMessage()." (after {$attempts} attempts)");
    }

    /** @param  array<string, Question>  $questions */
    private function classify(JevDecisionModel $model, string $state, array $questions): ClassificationResponse
    {
        return Classification::of($state)
            ->questions($questions)
            ->timeout($model->timeout)
            ->classify($model->provider, $model->id);
    }

    /** A timeout, a refused connection, a throttled or overloaded service, a server error. */
    private static function isTransient(Throwable $e): bool
    {
        if ($e instanceof InsufficientCreditsException) {
            return false;
        }

        if ($e instanceof FailoverableException || $e instanceof ConnectionException) {
            return true;
        }

        $status = $e instanceof RequestException ? $e->response?->status() : null;

        return $status === 408 || ($status !== null && $status >= 500);
    }

    /** What the API said, cut and flattened, never the request. */
    private static function rejection(Throwable $e, JevDecisionModel $model): JevRejected
    {
        $response = self::requestOf($e)?->response;
        $said = $response?->json('error.message') ?? $response?->json('message') ?? $response?->json('error');

        $detail = is_string($said) ? trim((string) preg_replace('/\s+/', ' ', $said)) : '';
        $detail = $detail === '' && $e instanceof InsufficientCreditsException ? 'insufficient credits or quota' : $detail;
        $key = (string) config("ai.providers.{$model->provider}.key");

        return new JevRejected($response?->status() ?? 402, Str::limit($key === '' ? $detail : str_replace($key, '[key]', $detail), JevRejected::DETAIL_LIMIT - 3));
    }

    /** The HTTP error behind an SDK exception, when there is one. */
    private static function requestOf(Throwable $e): ?RequestException
    {
        return match (true) {
            $e instanceof RequestException => $e,
            $e->getPrevious() instanceof RequestException => $e->getPrevious(),
            default => null,
        };
    }

    /** Doubling from `ai.jev.backoff_ms`, at least as long as the API's Retry-After, never beyond `ai.jev.max_wait_ms`. */
    private static function delayMs(Throwable $e, int $attempt): int
    {
        $backoff = (int) config('ai.jev.backoff_ms', 500) * 2 ** ($attempt - 1);

        return min(max($backoff, self::retryAfterMs($e)), (int) config('ai.jev.max_wait_ms', 30_000));
    }

    private static function retryAfterMs(Throwable $e): int
    {
        $header = self::requestOf($e)?->response?->header('Retry-After');

        if (! is_string($header) || $header === '') {
            return 0;
        }

        if (is_numeric($header)) {
            return max(0, (int) ceil((float) $header * 1000));
        }

        $date = strtotime($header);

        return $date === false ? 0 : max(0, ($date - time()) * 1000);
    }

    /** Pinned on purpose; a response that names another version makes noise and does not stop the turn. */
    private function guardAgainstDrift(JevReply $reply): void
    {
        $served = $reply->servedModel();

        if ($served !== null && ! ModelIdentity::matches($reply->model(), $served)) {
            Log::error('Jev answered with another model version than the one pinned for this study.', ['requested' => $reply->model(), 'served' => $served]);
        }
    }

    private static function millisecondsSince(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
