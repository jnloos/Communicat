<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptLog extends Model
{
    protected $fillable = ['job_log_id', 'label', 'model', 'prompt', 'response', 'latency_ms'];

    public function jobLog(): BelongsTo
    {
        return $this->belongsTo(JobLog::class);
    }
}
