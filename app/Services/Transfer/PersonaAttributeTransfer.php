<?php

namespace App\Services\Transfer;

use App\Models\PersonaAttribute;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * The persona attribute definitions by name. An import creates what is
 * missing and appends it after the existing ones, in the file's order (the
 * file carries no position: the list order is the order); a definition that
 * is already here is left alone, templates included -- the values of the
 * experts only mean what the local templates make of them. An old file's
 * `position` is ignored.
 */
final class PersonaAttributeTransfer
{
    /** @return list<array{name: string, self_template: string, roster_template: string}> */
    public function export(): array
    {
        return PersonaAttribute::ordered()->get()
            ->map(fn (PersonaAttribute $attribute) => [
                'name' => $attribute->name,
                'self_template' => $attribute->self_template,
                'roster_template' => $attribute->roster_template,
            ])
            ->values()
            ->all();
    }

    public function import(array $definitions, ImportReport $report): void
    {
        $known = PersonaAttribute::pluck('name');

        foreach ($definitions as $data) {
            try {
                $this->importOne(EntryFields::entry($data), $known, $report);
            } catch (UnreadableEntry) {
                $report->add(ImportReport::PERSONA_ATTRIBUTES, EntryFields::label($data, 'name'), ImportReport::FAILED, __('transfer.notes.unreadable_entry'));
            }
        }
    }

    /** @param  Collection<int, string>  $known  every name here, extended as entries create more */
    private function importOne(array $data, Collection $known, ImportReport $report): void
    {
        $name = trim((string) EntryFields::text($data, 'name'));
        $self = (string) EntryFields::text($data, 'self_template');
        $roster = (string) EntryFields::text($data, 'roster_template');

        if ($known->contains(fn (string $existing) => TagNames::same($existing, $name))) {
            $report->add(ImportReport::PERSONA_ATTRIBUTES, $name, ImportReport::SKIPPED, __('transfer.notes.already_there_templates_unchanged'));

            return;
        }

        if ($name === '' || ! str_contains($self, PersonaAttribute::PLACEHOLDER) || ! str_contains($roster, PersonaAttribute::PLACEHOLDER)) {
            $report->add(ImportReport::PERSONA_ATTRIBUTES, $name, ImportReport::FAILED, __('transfer.notes.persona_attribute_unreadable'));

            return;
        }

        if (self::tooLong($name, $self, $roster)) {
            $report->add(ImportReport::PERSONA_ATTRIBUTES, $name, ImportReport::FAILED, __('transfer.notes.persona_attribute_too_long', ['name' => PersonaAttribute::NAME_MAX, 'template' => PersonaAttribute::TEMPLATE_MAX]));

            return;
        }

        if (self::hasLineBreak($name, $self, $roster)) {
            $report->add(ImportReport::PERSONA_ATTRIBUTES, $name, ImportReport::FAILED, __('transfer.notes.persona_attribute_line_break'));

            return;
        }

        PersonaAttribute::create(['name' => $name, 'self_template' => $self, 'roster_template' => $roster]);
        $known->push($name);
        $report->add(ImportReport::PERSONA_ATTRIBUTES, $name, ImportReport::CREATED);
    }

    private static function tooLong(string $name, string $self, string $roster): bool
    {
        return mb_strlen($name) > PersonaAttribute::NAME_MAX
            || mb_strlen($self) > PersonaAttribute::TEMPLATE_MAX
            || mb_strlen($roster) > PersonaAttribute::TEMPLATE_MAX;
    }

    private static function hasLineBreak(string ...$texts): bool
    {
        return preg_match('/[\r\n]/', implode('', $texts)) === 1;
    }
}
