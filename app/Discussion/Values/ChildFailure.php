<?php

namespace App\Discussion\Values;

use App\Discussion\AbortsRun;
use App\Discussion\ErrorText;
use App\Discussion\PromptTaskAborted;
use App\Discussion\PromptTaskFailed;
use App\Discussion\TransientFailure;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * A ParallelPrompts task that failed, as a value instead of a thrown exception.
 * Laravel's ProcessDriver rebuilds a child's exception from class and message
 * only, which turns a RequestException into a TypeError and drops its status,
 * so a 5xx counted as interrupted in one pipeline and as failed in another.
 * Classified where the real exception still exists; only scalars cross back.
 */
final readonly class ChildFailure
{
    public function __construct(
        public string $class,
        public string $message,
        public ?int $status,
        public bool $transient,
        public bool $abortsRun,
        public ?string $origin = null,
    ) {}

    public static function of(Throwable $e): self
    {
        return new self(
            class: $e::class,
            message: ErrorText::of($e),
            status: $e instanceof RequestException ? $e->response->status() : null,
            transient: TransientFailure::is($e),
            abortsRun: $e instanceof AbortsRun,
            origin: basename($e->getFile()).':'.$e->getLine(),
        );
    }

    public function toException(): PromptTaskFailed
    {
        return $this->abortsRun ? new PromptTaskAborted($this) : new PromptTaskFailed($this);
    }

    /** "RequestException (HTTP 500): …", what job_logs.error shows after the exception's own class. */
    public function describe(): string
    {
        $status = $this->status === null ? '' : " (HTTP {$this->status})";

        $origin = $this->origin === null ? '' : " [at {$this->origin}]";

        return class_basename($this->class).$status.': '.$this->message.$origin;
    }
}
