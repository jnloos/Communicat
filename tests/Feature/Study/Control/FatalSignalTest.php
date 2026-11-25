<?php

namespace Tests\Feature\Study\Control;

use App\Services\Study\Control\FatalSignal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The texts are what job_logs.error holds after TurnRunner wrote class_basename(e).': '.message. */
class FatalSignalTest extends TestCase
{
    #[DataProvider('errors')]
    public function test_it_reads_the_signal_from_the_error_text(string $error, ?string $signal): void
    {
        $this->assertSame($signal, app(FatalSignal::class)->matches($error));
    }

    public static function errors(): array
    {
        return [
            'parallel task, 401' => ['PromptTaskFailed: RequestException (HTTP 401): Incorrect API key [at PendingRequest.php:1]', 'auth'],
            'parallel task, 403' => ['PromptTaskAborted: RequestException (HTTP 403): forbidden [at X.php:3]', 'auth'],
            'direct call, 401' => ["RequestException: HTTP request returned status code 401:\n{\"error\":{}}", 'auth'],
            'credit, 402' => ['InsufficientCreditsException: AI provider [openai] has insufficient credits or quota.', 'quota'],
            'credit in a parallel task' => ['PromptTaskFailed: InsufficientCreditsException: AI provider [openai] has insufficient credits or quota. [at X.php:1]', 'quota'],
            'quota code in a body' => ['RequestException: HTTP request returned status code 429: {"code":"insufficient_quota"}', 'quota'],
            'server error' => ['PromptTaskFailed: RequestException (HTTP 500): oops [at X.php:1]', null],
            'other status that starts alike' => ['RequestException (HTTP 4010): odd', null],
            'model answer' => ['ParseFailure: empty thought', null],
        ];
    }
}
