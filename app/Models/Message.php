<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public function contributor(): BelongsTo {
        return $this->belongsTo(Contributor::class);
    }

    public function project(): BelongsTo {
        return $this->belongsTo(Project::class);
    }

    public function toPromptArray(): array {
        $contributor = $this->contributor;
        $expertId = $contributor->isExpert() ? $contributor->expert_id : null;

        return [
            'expert_id' => $expertId,
            'content' => $this->content
        ];
    }
}
