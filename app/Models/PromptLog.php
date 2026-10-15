<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptLog extends Model
{
    protected $fillable = [
        'job_log_id', 'label', 'model', 'prompt', 'response', 'latency_ms',
        'provider', 'checkpoint', 'config', 'purpose', 'expert_id', 'reasoning',
        'tokens_in', 'tokens_out', 'tokens_reasoning', 'status', 'error',
    ];

    protected $casts = ['config' => 'array'];

    public function jobLog(): BelongsTo
    {
        return $this->belongsTo(JobLog::class);
    }

    /** The prompt as the debug report shows it: JSON indented, anything else as sent. */
    protected function readablePrompt(): Attribute
    {
        return Attribute::get(fn (): ?string => self::readable($this->prompt));
    }

    /** The response as the debug report shows it: structured output indented, plain text as is. */
    protected function readableResponse(): Attribute
    {
        return Attribute::get(fn (): ?string => self::readable($this->response));
    }

    /** Structured output arrives as one line of JSON; indented, its fields can be read. */
    private static function readable(?string $text): ?string
    {
        $decoded = json_decode((string) $text, true);

        return is_array($decoded)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $text;
    }
}
