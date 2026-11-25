<?php

namespace App\Services\Study\Planning;

use Ramsey\Uuid\Uuid;

/**
 * Thesis study, see docs/study.md.
 *
 * One planned persona: name, description and the three attributes, plus the
 * tags the analysis reads (spec 2.4). Its uuid addresses its content (study included, so a probe and the main study never share a record), so the
 * same persona in another status cell or pipeline of its instance is the same
 * record as long as title and description agree, and a record edited by hand no
 * longer matches its plan. With a seat table the description follows the seat,
 * so one person can be up to three records per title in an instance.
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
     * @param  string  $letter  which of the scenario's descriptions: A (focus), B, C (duo); with a seat table, the seat's
     */
    public function __construct(
        DrawnName $drawn,
        public readonly array $attributes,
        public readonly string $letter,
        public readonly string $description,
        public readonly string $study,
        public readonly bool $focus,
    ) {
        $this->name = $drawn->fullName();
        $this->firstName = $drawn->firstName;
        $this->lastName = $drawn->lastName;
        $this->firstNameId = $drawn->firstNameId;
        $this->subgroup = $drawn->subgroup;
        $this->uuid = Uuid::uuid5(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'communicat:study:persona'),
            Manifest::canonicalize([$study, $this->name, $description, $attributes['Role'], $attributes['Gender'], $attributes['Origin']]),
        )->toString();
    }

    /** @return array<string, string> config('study.persona_tags') key => value */
    public function tags(): array
    {
        return ['study' => $this->study, 'first_name' => $this->firstNameId, 'subgroup' => $this->subgroup, 'focus' => $this->focus ? 'true' : 'false'];
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
