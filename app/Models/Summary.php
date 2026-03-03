<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Summary extends Model
{
    public $fillable = ['project_id', 'expert_id', 'content'];

    public function expert(): BelongsTo {
        return $this->belongsTo(Expert::class);
    }

    public function project(): BelongsTo {
        return $this->belongsTo(Project::class);
    }
}
