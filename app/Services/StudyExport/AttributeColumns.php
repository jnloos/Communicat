<?php

namespace App\Services\StudyExport;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * One CSV column per persona attribute definition, named like the attribute.
 * A name that collides with a fixed column or a tag column (ignoring case and
 * surrounding space) gets the prefix "attribute "; one that still collides
 * gets its id as a suffix. No header overwrites another.
 */
final class AttributeColumns
{
    /** @var array<int, string> header by attribute id, in attribute order */
    private readonly array $headers;

    /**
     * @param  Collection<int, PersonaAttribute>  $attributes  in column order
     * @param  list<string>  $taken  the file's other columns, tag columns included
     */
    public function __construct(Collection $attributes, array $taken)
    {
        $taken = array_map(TagNames::normal(...), $taken);
        $headers = [];

        foreach ($attributes as $attribute) {
            $header = $attribute->name;

            if (in_array(TagNames::normal($header), $taken, true)) {
                $header = 'attribute '.trim($attribute->name);
            }

            if (in_array(TagNames::normal($header), $taken, true)) {
                $header = trim($attribute->name)." #{$attribute->id}";
            }

            $headers[$attribute->id] = $header;
            $taken[] = TagNames::normal($header);
        }

        $this->headers = $headers;
    }

    /** @return list<string> */
    public function headers(): array
    {
        return array_values($this->headers);
    }

    /**
     * The cells of one expert: its value per column, null where it has none.
     * Reads the expert's loaded personaAttributes.
     *
     * @return array<string, ?string>
     */
    public function cells(Expert $expert): array
    {
        $values = $expert->personaAttributes->mapWithKeys(fn (PersonaAttribute $attribute) => [$attribute->id => $attribute->pivot->value]);

        return array_map(fn (int $id) => $values[$id] ?? null, array_flip($this->headers));
    }
}
