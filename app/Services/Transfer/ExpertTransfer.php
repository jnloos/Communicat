<?php

namespace App\Services\Transfer;

use App\Models\Expert;
use App\Services\Tags\TagsByName;
use Illuminate\Support\Carbon;

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
        $label = trim(($data['name'] ?? '').' '.($data['role'] ?? ''));
        $uuid = (string) ($data['uuid'] ?? '');

        if ($uuid === '') {
            $report->add(ImportReport::EXPERTS, $label, ImportReport::FAILED, __('transfer.notes.no_uuid'));

            return;
        }

        if (Expert::where('uuid', $uuid)->exists()) {
            $report->add(ImportReport::EXPERTS, $label, ImportReport::SKIPPED, __('transfer.notes.already_there'));

            return;
        }

        $expert = new Expert;
        $originalId = (int) ($data['id'] ?? 0);
        $keepsId = $originalId > 0 && ! Expert::whereKey($originalId)->exists();

        if ($keepsId) {
            $expert->id = $originalId;
        }

        $expert->forceFill([
            'uuid' => $uuid,
            'name' => (string) ($data['name'] ?? ''),
            'role' => (string) ($data['role'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'avatar_url' => $data['avatar_url'] ?? null,
            'created_at' => isset($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
        ])->save();

        $skippedTags = $this->tags->assign($expert, $data['tags'] ?? []);
        $notes = array_filter([
            $keepsId || $originalId === 0 ? null : __('transfer.notes.new_expert_id', ['old' => $originalId, 'new' => $expert->id]),
            $skippedTags === [] ? null : __('transfer.notes.tags_skipped', ['tags' => implode(', ', $skippedTags)]),
        ]);

        $report->add(ImportReport::EXPERTS, $label, ImportReport::CREATED, $notes === [] ? null : implode(' ', $notes));
    }
}
