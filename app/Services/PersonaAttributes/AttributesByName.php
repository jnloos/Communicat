<?php

namespace App\Services\PersonaAttributes;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Services\Tags\TagNames;
use Illuminate\Database\Eloquent\Collection;

/**
 * An expert's attribute values as they travel between instances: {attribute
 * name: value}, because ids differ. Writing never creates a definition, it
 * reports what it could not place. Names match as TagNames::same() reads them.
 *
 * The definitions are read once per instance, so use one instance per import:
 * the bundle's persona-attributes.json is written before the first assign().
 */
class AttributesByName
{
    /** @var Collection<int, PersonaAttribute>|null */
    private ?Collection $definitions = null;

    /** @return array<string, string> in attribute order */
    public function export(Expert $expert): array
    {
        return $expert->personaAttributes()->get()
            ->mapWithKeys(fn (PersonaAttribute $attribute) => [$attribute->name => (string) $attribute->pivot->value])
            ->all();
    }

    /** @param  bool  $overwrite  false keeps a value the expert already has for an attribute */
    public function assign(Expert $expert, mixed $values, bool $overwrite = true): SkippedAttributes
    {
        $skipped = new SkippedAttributes;

        if (! is_array($values)) {
            return $skipped;
        }

        $held = $overwrite ? [] : $expert->personaAttributes()->pluck('persona_attributes.id')->flip()->all();

        foreach ($values as $name => $value) {
            $attribute = $this->definitions()->first(fn (PersonaAttribute $definition) => TagNames::same($definition->name, (string) $name));

            if ($attribute === null || ! is_scalar($value)) {
                $skipped->unknown[] = is_scalar($value) ? "{$name}: {$value}" : (string) $name;

                continue;
            }

            $text = trim((string) $value);

            if ($text === '') {
                continue;
            }

            $problem = ValueProblem::of($text);

            if ($problem !== null) {
                $skipped->addInvalid($problem, "{$name}: {$text}");
            } elseif (! isset($held[$attribute->id])) {
                $expert->setAttributeValue($attribute, $text);
            }
        }

        return $skipped;
    }

    /** @return Collection<int, PersonaAttribute> */
    private function definitions(): Collection
    {
        return $this->definitions ??= PersonaAttribute::ordered()->get();
    }
}
