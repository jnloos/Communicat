<?php

namespace App\Services\Transfer;

use App\Models\Expert;
use App\Services\Tags\TagsByName;
use Illuminate\Support\Str;

/**
 * One expert by uuid. An import keeps the original id where it is free: the
 * selector signals and the E{id} tokens in logged prompts and answers name
 * experts by id, and they stay right only if the id does.
 */
final class ExpertTransfer
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    public function export(Expert $expert): array
    {
        return [
            'uuid' => $expert->uuid,
            'id' => $expert->id,
            'name' => $expert->name,
            'role' => $expert->role,
            'description' => $expert->description,
            'avatar_url' => $expert->avatar_url,
            'tags' => $this->tags->export($expert),
            'created_at' => $expert->created_at?->toIso8601String(),
        ];
    }

    public function import(array $data, ImportReport $report): void
    {
        $this->importAll([$data], $report);
    }

    /**
     * Two passes, so a taken id renumbers only its own expert: first every new
     * expert whose original id is free keeps it, then the rest get fresh ids.
     * One pass would hand the fresh id to whoever came first, which is usually
     * a later expert's original id, and renumber every expert after it.
     * The report keeps the order of the file.
     *
     * @param  list<array<string, mixed>>  $experts
     * @param  bool  $withRuns  the bundle brings runs, so a renumbering note says what follows the new id
     */
    public function importAll(array $experts, ImportReport $report, bool $withRuns = false): void
    {
        $outcomes = [];
        $renumbered = [];

        // Reported in finally: when a create throws, the experts already
        // written are still in the report, in file order, before the
        // exception reaches the caller.
        try {
            foreach ($experts as $index => $data) {
                $refusal = $this->refusal($data);

                if ($refusal !== null) {
                    $outcomes[$index] = $refusal;
                } elseif ($this->originalIdIsFree($data)) {
                    $outcomes[$index] = $this->create($data, keepId: true, withRuns: $withRuns);
                } else {
                    $renumbered[] = $index;
                }
            }

            foreach ($renumbered as $index) {
                $outcomes[$index] = $this->refusal($experts[$index]) ?? $this->create($experts[$index], keepId: false, withRuns: $withRuns);
            }
        } finally {
            ksort($outcomes);

            foreach ($outcomes as $index => [$outcome, $note]) {
                $report->add(ImportReport::EXPERTS, EntryFields::label($experts[$index], 'name', 'role'), $outcome, $note);
            }
        }
    }

    /** @return array{string, string}|null outcome and note when the entry is not created */
    private function refusal(mixed $data): ?array
    {
        if (! $this->isReadable($data)) {
            return [ImportReport::FAILED, __('transfer.notes.unreadable_entry')];
        }

        $uuid = (string) ($data['uuid'] ?? '');

        return match (true) {
            $uuid === '' => [ImportReport::FAILED, __('transfer.notes.no_uuid')],
            ! Str::isUuid($uuid) => [ImportReport::FAILED, __('transfer.notes.bad_uuid')],
            Expert::where('uuid', $uuid)->exists() => [ImportReport::SKIPPED, __('transfer.notes.already_there')],
            default => null,
        };
    }

    /** Every field the import reads has its type; tags are checked by TagsByName itself. */
    private function isReadable(mixed $data): bool
    {
        try {
            $entry = EntryFields::entry($data);

            foreach (['uuid', 'id', 'name', 'role', 'description', 'avatar_url'] as $field) {
                EntryFields::text($entry, $field);
            }

            EntryFields::time($entry, 'created_at');

            return true;
        } catch (UnreadableEntry) {
            return false;
        }
    }

    private function originalIdIsFree(array $data): bool
    {
        $originalId = (int) ($data['id'] ?? 0);

        return $originalId > 0 && ! Expert::whereKey($originalId)->exists();
    }

    /** @return array{string, string|null} */
    private function create(array $data, bool $keepId, bool $withRuns): array
    {
        $expert = new Expert;
        $originalId = (int) ($data['id'] ?? 0);

        if ($keepId) {
            $expert->id = $originalId;
        }

        $expert->forceFill([
            'uuid' => (string) $data['uuid'],
            'name' => (string) ($data['name'] ?? ''),
            'role' => (string) ($data['role'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'avatar_url' => $data['avatar_url'] ?? null,
            'created_at' => EntryFields::time($data, 'created_at') ?? now(),
        ])->save();

        $skippedTags = $this->tags->assign($expert, $data['tags'] ?? []);
        $notes = array_filter([
            $keepId || $originalId === 0 ? null : __('transfer.notes.new_expert_id', ['old' => $originalId, 'new' => $expert->id]),
            $keepId || $originalId === 0 || ! $withRuns ? null : __('transfer.notes.new_expert_id_runs', ['old' => $originalId]),
            $skippedTags === [] ? null : __('transfer.notes.tags_skipped', ['tags' => implode(', ', $skippedTags)]),
        ]);

        return [ImportReport::CREATED, $notes === [] ? null : implode(' ', $notes)];
    }
}
