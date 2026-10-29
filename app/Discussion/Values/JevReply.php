<?php

namespace App\Discussion\Values;

use Illuminate\Support\Str;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;

/**
 * One request to Jev and what came back: the answers, or the reason there are
 * none. A request that failed for a transient reason is a value like any other,
 * because a Jev selector never ends a run over a hiccup -- it reads the reply,
 * finds nothing, and falls back. (A request Jev rejected is not a reply at all:
 * JevClient throws JevRejected.)
 */
final readonly class JevReply
{
    /** Values and probabilities are rounded to this many places, so a tie is a tie to the sixth place. */
    public const PRECISION = 6;

    /** What prompt_logs.error keeps of a failure. */
    public const ERROR_LIMIT = 300;

    private function __construct(
        /** The decision model the request was made with (the pinned id, as frozen for the run). */
        public JevDecisionModel $decision,
        /** How many times the request was sent: 1 plus the retries. */
        public int $attempts,
        /** From the first attempt to the end of the last, backoff included. */
        public int $latencyMs,
        public ?ClassificationResponse $response,
        public ?string $error,
    ) {}

    public static function answered(JevDecisionModel $decision, int $attempts, int $latencyMs, ClassificationResponse $response): self
    {
        return new self($decision, $attempts, $latencyMs, $response, null);
    }

    public static function failed(JevDecisionModel $decision, int $attempts, int $latencyMs, string $error): self
    {
        return new self($decision, $attempts, $latencyMs, null, Str::limit($error, self::ERROR_LIMIT - 3));
    }

    /** The model id that was asked for. */
    public function model(): string
    {
        return $this->decision->id;
    }

    public function succeeded(): bool
    {
        return $this->response !== null;
    }

    /**
     * The model the response names. The SDK fills in the requested id when the
     * API names none, so this cannot tell "served" from "echoed"; it can only
     * show a difference.
     */
    public function servedModel(): ?string
    {
        $served = $this->response?->meta->model;

        return filled($served) ? $served : null;
    }

    /** @param  list<string>  $options */
    public function choice(string $key, array $options): ?JevChoice
    {
        return JevChoice::read($this->answer($key), $options);
    }

    public function score(string $key, int $levels): ?JevScore
    {
        return JevScore::read($this->answer($key), $levels);
    }

    private function answer(string $key): ?Answer
    {
        return $this->response?->answers[$key] ?? null;
    }
}
