<?php

namespace App\Models;

use App\Discussion\Values\QuestionDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved question of the statistics page: its title, the researcher's
 * notes and its definition (what it asks). A template is the same row with
 * is_template set: every signed-in user may open and copy it, only an admin
 * who owns it renames or deletes it, and nobody edits it in place.
 */
class Question extends Model
{
    protected $fillable = ['user_id', 'name', 'notes', 'definition', 'is_template'];

    protected $casts = [
        'definition' => 'array',
        'is_template' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What the question asks, normalised: a stored definition from an older shape still reads. */
    public function definition(): QuestionDefinition
    {
        return QuestionDefinition::fromArray($this->getAttribute('definition') ?? []);
    }

    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true)->orderBy('name')->orderBy('id');
    }

    /** The questions that are not templates, most recently changed first: the one being worked on stays on top. */
    public function scopeNotTemplates(Builder $query): void
    {
        $query->where('is_template', false)->latest('updated_at')->orderByDesc('id');
    }
}
