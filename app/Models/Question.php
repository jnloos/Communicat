<?php

namespace App\Models;

use App\Discussion\Values\QuestionDefinition;
use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved question of the statistics page: its title, the researcher's
 * notes and its definition (what it asks). A template is the same row with
 * is_template set: every signed-in user may open and copy it, only an admin
 * who owns it renames or deletes it, and nobody edits it in place. Both
 * lists have a manual order (position): the templates one for everyone, set
 * by admins, the questions one per user.
 */
class Question extends Model
{
    use HasPosition;

    protected $fillable = ['user_id', 'name', 'notes', 'definition', 'is_template', 'position'];

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
        $query->where('is_template', true)->orderBy('position')->orderBy('id');
    }

    /** The questions that are not templates, in their owner's order; a new one joins at the end. */
    public function scopeNotTemplates(Builder $query): void
    {
        $query->where('is_template', false)->orderBy('position')->orderBy('id');
    }

    /** The rows this user may open — QuestionPolicy::view() as a query: their own questions and every template. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('is_template', true)->orWhere('user_id', $user->id));
    }

    /** Templates are ordered among all templates, a question among its owner's questions. */
    protected function siblings(): Builder
    {
        return $this->is_template
            ? static::query()->where('is_template', true)
            : static::query()->where('is_template', false)->where('user_id', $this->user_id);
    }
}
