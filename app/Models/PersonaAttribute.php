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

    /** The attribute that carries what used to be the fixed role field ("Teacher", "CEO"). */
    public const ROLE = 'Role';

    /** The one definition of the Role's default templates: role(), the legacy-run bridge and the tests read these. */
    public const ROLE_SELF_TEMPLATE = 'Your role is :value.';

    public const ROLE_ROSTER_TEMPLATE = ':value';

    /** The one set of limits: the settings page, the expert editor, bundles and init:experts all check these. */
    public const NAME_MAX = 100;

    public const TEMPLATE_MAX = 200;

    public const VALUE_MAX = 100;

    protected $fillable = ['name', 'self_template', 'roster_template', 'position'];

    public function experts(): BelongsToMany
    {
        return $this->belongsToMany(Expert::class, 'expert_persona_attribute')->withPivot('value');
    }

    /** The Role definition if this database has one (created on demand by role()); never writes. */
    public static function findRole(): ?self
    {
        return static::query()->get()->first(fn (self $attribute) => $attribute->isRole());
    }

    /**
     * The role definition, created in front of the others the first time a role is
     * written on a database that does not have it yet.
     */
    public static function role(): self
    {
        $existing = static::findRole();

        if ($existing !== null) {
            return $existing;
        }

        static::query()->increment('position');

        return static::create([
            'name' => self::ROLE,
            'self_template' => self::ROLE_SELF_TEMPLATE,
            'roster_template' => self::ROLE_ROSTER_TEMPLATE,
            'position' => 0,
        ]);
    }

    public function isRole(): bool
    {
        return TagNames::same($this->name, self::ROLE);
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
