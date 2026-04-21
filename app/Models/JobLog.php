<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobLog extends Model
{
    protected $fillable = ['job_class', 'project_id', 'status', 'payload', 'started_at', 'finished_at'];
    protected $casts = ['payload' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function promptLogs(): HasMany
    {
        return $this->hasMany(PromptLog::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function duration(): ?float
    {
        if (!$this->finished_at) return null;
        return $this->started_at->diffInSeconds($this->finished_at);
    }
}
