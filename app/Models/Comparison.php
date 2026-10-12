<?php

namespace App\Models;

use App\Discussion\Values\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved question of the statistics page. A template is the same row with
 * is_template set: every signed-in user may copy it, only its owner renames
 * or deletes it, and nobody edits it in place.
 */
class Comparison extends Model
{
    protected $fillable = ['user_id', 'name', 'question', 'is_template'];

    protected $casts = [
        'question' => 'array',
        'is_template' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toQuestion(): Question
    {
        return Question::fromArray($this->question ?? []);
    }

    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true)->orderBy('name')->orderBy('id');
    }

    /** Most recently changed first: the one being worked on stays on top. */
    public function scopeQuestions(Builder $query): void
    {
        $query->where('is_template', false)->latest('updated_at')->orderByDesc('id');
    }
}
