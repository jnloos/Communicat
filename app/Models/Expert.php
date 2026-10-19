<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasTags;
use App\Services\PersonaAttributes\ValueProblem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

class Expert extends Model
{
    use HasFactory, HasPublicUuid, HasTags;

    /**
     * Stable, type-prefixed token used to reference this contributor inside
     * prompts and structured LLM outputs ("E7"). Disambiguates experts from
     * users (and from same-named experts) without ever matching on names.
     */
    protected function promptId(): Attribute
    {
        return Attribute::get(fn () => self::tokenFor($this->id));
    }

    /** The token for an expert id, the one format every prompt, selection signal and export uses. */
    public static function tokenFor(int $id): string
    {
        return 'E'.$id;
    }

    protected $fillable = [
        'name',
        'avatar_url',
        'role',
        'description',
    ];

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    /** The attributes this expert has a value for, in attribute order; the value is `pivot->value`. */
    public function personaAttributes(): BelongsToMany
    {
        return $this->belongsToMany(PersonaAttribute::class, 'expert_persona_attribute')
            ->withPivot('value')
            ->ordered();
    }

    /**
     * Sets (or with null/blank clears) this expert's value for one attribute.
     *
     * @throws InvalidArgumentException for a value over PersonaAttribute::VALUE_MAX or with a line break
     */
    public function setAttributeValue(PersonaAttribute $attribute, ?string $value): void
    {
        $value = self::checkedAttributeValue($value);

        if ($value === '') {
            $this->personaAttributes()->detach($attribute->id);
        } else {
            $this->personaAttributes()->syncWithoutDetaching([$attribute->id => ['value' => $value]]);
        }

        $this->unsetRelation('personaAttributes');
    }

    /**
     * Writes a form's values. Every attribute that appears as a key is set; ''
     * or null clears it, attributes not in the array stay as they are. Unknown ids are ignored.
     * All values are checked first, so a bad one throws before anything is written.
     *
     * @param  array<int|string, string|null>  $valuesByAttributeId  attribute id => value
     *
     * @throws InvalidArgumentException as setAttributeValue()
     */
    public function syncAttributeValues(array $valuesByAttributeId): void
    {
        array_map(self::checkedAttributeValue(...), $valuesByAttributeId);

        foreach (PersonaAttribute::whereKey(array_keys($valuesByAttributeId))->get() as $attribute) {
            $this->setAttributeValue($attribute, $valuesByAttributeId[$attribute->id] ?? null);
        }
    }

    private static function checkedAttributeValue(?string $value): string
    {
        $value = $value === null ? '' : trim($value);

        if ($value !== '' && ($problem = ValueProblem::of($value)) !== null) {
            throw new InvalidArgumentException($problem->message());
        }

        return $value;
    }

    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    /** Experts whose name, role or description contains the term; a blank term matches everyone. */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('role', 'like', $like)
            ->orWhere('description', 'like', $like));
    }
}
