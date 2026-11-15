<?php

namespace App\Study\Planning;

use Ramsey\Uuid\Uuid;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * One planned persona: name, description and the three attributes, plus the
 * tags the analysis reads (spec 2.4). Its uuid addresses its content, so the
 * same persona in another status cell or pipeline of its instance is the same
 * record, and a record edited by hand no longer matches its plan.
 */
final class PersonaPlan
{
    public readonly string $uuid;

    public readonly string $name;

    public readonly string $firstName;

    public readonly string $lastName;

    public readonly string $firstNameId;

    public readonly string $subgroup;

    /**
     * @param  array{Role: string, Gender: string, Origin: string}  $attributes
     * @param  string  $letter  which of the scenario's descriptions: A (focus), B, C (duo)
     */
    public function __construct(
        DrawnName $drawn,
        public readonly array $attributes,
        public readonly string $letter,
        public readonly string $description,
        public readonly string $study,
    ) {
        $this->name = $drawn->fullName();
        $this->firstName = $drawn->firstName;
        $this->lastName = $drawn->lastName;
        $this->firstNameId = $drawn->firstNameId;
        $this->subgroup = $drawn->subgroup;
        $this->uuid = Uuid::uuid5(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'communicat:study:persona'),
            Manifest::canonicalize([$this->name, $description, $attributes['Role'], $attributes['Gender'], $attributes['Origin']]),
        )->toString();
    }

    /** @return array<string, string> config('study.persona_tags') key => value */
    public function tags(): array
    {
        return ['study' => $this->study, 'first_name' => $this->firstNameId, 'subgroup' => $this->subgroup];
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'letter' => $this->letter,
            'attributes' => $this->attributes,
            'tags' => $this->tags(),
        ];
    }
}
