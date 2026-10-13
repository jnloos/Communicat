<?php

namespace App\Services\Transfer;

use App\Discussion\Values\QuestionDefinition;
use App\Models\Question;
use App\Models\User;

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
            $label = (string) ($data['name'] ?? __('questions.untitled'));
            $isTemplate = ! empty($data['is_template']) && $user->is_admin;
            $definition = QuestionDefinition::fromArray(DefinitionNames::toIds($data['definition'] ?? [], $user))->toArray();

            $duplicate = $user->questions()
                ->where('is_template', $isTemplate)
                ->where('name', $data['name'] ?? null)
                ->get()
                ->contains(fn (Question $existing) => $existing->notes === ($data['notes'] ?? null) && $existing->definition()->toArray() == $definition);

            if ($duplicate) {
                $report->add(ImportReport::QUESTIONS, $label, ImportReport::SKIPPED, __('transfer.notes.already_there'));

                continue;
            }

            $user->questions()->create([
                'name' => $data['name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'definition' => $definition,
                'is_template' => $isTemplate,
            ]);

            $report->add(
                ImportReport::QUESTIONS,
                $label,
                ImportReport::CREATED,
                ! empty($data['is_template']) && ! $isTemplate ? __('transfer.notes.template_as_question') : null,
            );
        }
    }
}
