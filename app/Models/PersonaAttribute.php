<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use App\Services\Tags\TagNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A managed attribute of a persona ("origin", "gender"). Two templates turn an
 * expert's free-text value into prompt text: the sentence the expert reads about
 * itself and the phrase the others read in the participants list. Both hold
 * `:value`; nothing but a plain replacement is ever applied to them.
 */
class PersonaAttribute extends Model
{
    use HasFactory, HasPosition;

    public const PLACEHOLDER = ':value';

    /** The one set of limits: the settings page, the expert editor, bundles and init:experts all check these. */
    public const NAME_MAX = 100;

    public const TEMPLATE_MAX = 200;

    public const VALUE_MAX = 100;

    protected $fillable = ['name', 'self_template', 'roster_template', 'position'];

    public function experts(): BelongsToMany
    {
        return $this->belongsToMany(Expert::class, 'expert_persona_attribute')->withPivot('value');
    }

    public function selfLine(string $value): string
    {
        return str_replace(self::PLACEHOLDER, $value, $this->self_template);
    }

    public function rosterText(string $value): string
    {
        return str_replace(self::PLACEHOLDER, $value, $this->roster_template);
    }

    /** Definitions in the order the admin arranged them; the id breaks ties. Qualified, so it also works on the experts' join. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('persona_attributes.position')->orderBy('persona_attributes.id');
    }

    /** Whether another attribute already has this name, ignoring case and surrounding space. */
    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return static::query()
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->pluck('name')
            ->contains(fn (string $existing) => TagNames::same($existing, $name));
    }

    protected function siblings(): Builder
    {
        return static::query();
    }
}
