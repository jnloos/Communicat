<?php

namespace App\Services\Transfer;

use App\Discussion\Values\QuestionDefinition;
use App\Models\Question;
use App\Models\User;
use TypeError;

/**
 * A user's own questions and all templates, by name. On import they become
 * the importing user's questions; templates stay templates only for an admin.
 * A question with the same title, notes and definition is not added twice.
 * The file lists them in their order (questions, then templates, each by
 * position); an import appends them after the importer's last question or
 * template in that order: HasPosition sets max(position) + 1 on create.
 */
final class QuestionTransfer
{
    public function export(User $user): array
    {
        $own = $user->questions()->notTemplates()->get();
        $templates = Question::templates()->get();

        return $own->concat($templates)
            ->map(fn (Question $question) => [
                'name' => $question->name,
                'notes' => $question->notes,
                'is_template' => (bool) $question->is_template,
                'definition' => DefinitionNames::toNames($question->definition()->toArray(), $user),
            ])
            ->values()->all();
    }

    public function import(array $questions, User $user, ImportReport $report): void
    {
        foreach ($questions as $data) {
            try {
                $entry = EntryFields::entry($data);
                $name = EntryFields::text($entry, 'name');
                $notes = EntryFields::text($entry, 'notes');
                $definition = $this->definition(EntryFields::map($entry, 'definition'), $user);
            } catch (UnreadableEntry) {
                $report->add(ImportReport::QUESTIONS, EntryFields::label($data, 'name'), ImportReport::FAILED, __('transfer.notes.unreadable_entry'));

                continue;
            }

            $this->importOne($name, $notes, ! empty($entry['is_template']), $definition, $user, $report);
        }
    }

    /** The definition as this database's ids, normalised the way the statistics page stores it. */
    private function definition(array $named, User $user): array
    {
        try {
            return QuestionDefinition::fromArray(DefinitionNames::toIds($named, $user))->toArray();
        } catch (TypeError) {
            // A nested part DefinitionNames does not read itself (turns, say) has the wrong type.
            throw new UnreadableEntry('The definition could not be read.');
        }
    }

    private function importOne(?string $name, ?string $notes, bool $wantsTemplate, array $definition, User $user, ImportReport $report): void
    {
        $label = $name ?? __('questions.untitled');
        $isTemplate = $wantsTemplate && $user->is_admin;

        $duplicate = $user->questions()
            ->where('is_template', $isTemplate)
            ->where('name', $name)
            ->get()
            ->contains(fn (Question $existing) => $existing->notes === $notes && $existing->definition()->toArray() == $definition);

        if ($duplicate) {
            $report->add(ImportReport::QUESTIONS, $label, ImportReport::SKIPPED, __('transfer.notes.already_there'));

            return;
        }

        $user->questions()->create([
            'name' => $name,
            'notes' => $notes,
            'definition' => $definition,
            'is_template' => $isTemplate,
        ]);

        $report->add(
            ImportReport::QUESTIONS,
            $label,
            ImportReport::CREATED,
            $wantsTemplate && ! $isTemplate ? __('transfer.notes.template_as_question') : null,
        );
    }
}
