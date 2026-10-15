<?php

namespace App\Services\Transfer;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Decides whether a project file can go into this database, and finds the
 * experts it names here. A file whose shape ProjectFileShape accepted may
 * still be skipped: no or a bad uuid, a project already here, a pipeline or
 * model this installation does not know, or an expert neither here nor
 * imported before.
 */
final class ProjectImportCheck
{
    /** The note to skip the file with, or null when it can be written. */
    public function reasonToSkip(array $data): ?string
    {
        $uuid = (string) ($data['uuid'] ?? '');

        return match (true) {
            $uuid === '' => __('transfer.notes.no_uuid'),
            ! Str::isUuid($uuid) => __('transfer.notes.bad_uuid'),
            Project::where('uuid', $uuid)->exists() => __('transfer.notes.already_there'),
            ! app(PipelineRegistry::class)->has((string) ($data['pipeline'] ?? '')) => __('transfer.notes.unknown_pipeline', ['pipeline' => $data['pipeline'] ?? '']),
            ! ModelConfig::exists((string) ($data['model'] ?? '')) => __('transfer.notes.unknown_model', ['model' => $data['model'] ?? '']),
            ($missing = $this->missingExperts($data)) !== [] => __('transfer.notes.missing_experts', ['experts' => implode(', ', $missing)]),
            default => null,
        };
    }

    /** @return array<string, int> uuid => id of every expert the file names that is here */
    public function foundExperts(array $data): array
    {
        return Expert::whereIn('uuid', $this->namedExperts($data))->pluck('id', 'uuid')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<string> uuids the file names that are neither here nor imported before */
    private function missingExperts(array $data): array
    {
        $named = $this->namedExperts($data);

        return array_values(array_diff($named, Expert::whereIn('uuid', $named)->pluck('uuid')->all()));
    }

    /**
     * Every expert uuid the file refers to. expert_ids counts too: it also
     * names who sat in the frozen cast without leaving a row, and an id it
     * cannot map here would stay as it was and point at someone else. A
     * nested wildcard comes back collapsed; a missing field reads as null.
     *
     * @return list<string>
     */
    private function namedExperts(array $data): array
    {
        $named = Arr::flatten([
            array_values($data['expert_ids'] ?? []),
            data_get($data, 'seats.*.expert', []),
            data_get($data, 'messages.*.expert', []),
            data_get($data, 'messages.*.addressee', []),
            data_get($data, 'summaries.*.expert', []),
            data_get($data, 'jobs.*.expert', []),
            data_get($data, 'jobs.*.prompts.*.expert', []),
        ]);

        return array_values(array_unique(array_filter($named)));
    }
}
