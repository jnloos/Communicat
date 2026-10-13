<?php

namespace App\Services\StudyExport;

use App\Discussion\Metrics\SpeakingShares;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The raw data for an outside analysis: runs, participants and turns as three
 * CSV files joined by uuid. Tags become one column per category. A turn
 * counts as spoken when words is set (SpokenTurns' rule); failed turns stay in
 * so the failure rate can be read; NULL stays empty and never becomes 0.
 */
final class StudyExport
{
    public function __construct(private readonly SpeakingShares $shares) {}

    /** @param  Collection<int, Project>  $runs */
    public function write(Collection $runs, User $viewer, string $path): void
    {
        $projectCategories = TagCategory::ofScope(TagScope::Project)->orderBy('position')->orderBy('id')->get();
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->orderBy('position')->orderBy('id')->get();
        $expertUuids = Expert::pluck('uuid', 'id');
        $groups = ProjectGroup::where('user_id', $viewer->id)->pluck('name', 'id');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('runs.csv', $this->csv($this->runRows($runs, $viewer, $groups, $projectCategories)));
        $zip->addFromString('participants.csv', $this->csv($this->participantRows($runs, $personaCategories)));
        $zip->addFromString('turns.csv', $this->csv($this->turnRows($runs, $expertUuids)));
        $zip->close();
    }

    public function filename(string $label): string
    {
        return 'communicat-study-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /** @return list<array<string, scalar|null>> */
    private function runRows(Collection $runs, User $viewer, Collection $groups, Collection $categories): array
    {
        return $runs->map(function (Project $run) use ($viewer, $groups, $categories) {
            $report = $this->shares->forProject($run);
            $tags = $run->tagValues()->get()->keyBy('tag_category_id');
            $groupId = $run->groupIdFor($viewer);
            $row = [
                'run_uuid' => $run->uuid,
                'title' => $run->title,
                'description' => $run->description,
                'pipeline' => $run->pipeline,
                'model' => $run->model,
                'seed' => $run->seed,
                'turn_budget' => $run->turn_budget,
                'group' => $groupId === null ? null : ($groups[$groupId] ?? null),
            ];

            foreach ($categories as $category) {
                $row[$category->name] = $tags[$category->id]->name ?? null;
            }

            return $row + ['turns_spoken' => $report->spokenTurns, 'turns_failed_without_speaking' => $report->failedTurns];
        })->values()->all();
    }

    /** @return list<array<string, scalar|null>> */
    private function participantRows(Collection $runs, Collection $categories): array
    {
        return $runs->flatMap(function (Project $run) use ($categories) {
            $speakers = collect($this->shares->forProject($run)->speakers)->keyBy('expertId');

            return $run->experts()->get()->map(function (Expert $expert) use ($run, $speakers, $categories) {
                $share = $speakers[$expert->id] ?? null;
                $tags = $expert->tagValues()->get()->keyBy('tag_category_id');
                $row = [
                    'run_uuid' => $run->uuid,
                    'expert_uuid' => $expert->uuid,
                    'name' => $expert->name,
                    'role' => $expert->role,
                    'seat' => $expert->pivot->seat,
                ];

                foreach ($categories as $category) {
                    $row[$category->name] = $tags[$category->id]->name ?? null;
                }

                return $row + [
                    'turns' => $share?->turns ?? 0,
                    'turn_share' => $share?->turnShare ?? 0,
                    'words' => $share?->words ?? 0,
                    'word_share' => $share?->wordShare ?? 0,
                    'words_per_turn' => $share === null ? null : $share->wordsPerTurn(),
                ];
            });
        })->values()->all();
    }

    /** @return list<array<string, scalar|null>> */
    private function turnRows(Collection $runs, Collection $expertUuids): array
    {
        $uuid = fn ($id) => $id === null ? null : ($expertUuids[(int) $id] ?? null);
        $flag = fn ($value) => $value === null ? null : ($value ? 1 : 0);

        return $runs->flatMap(function (Project $run) use ($uuid, $flag) {
            $jobs = JobLog::where('project_id', $run->id)->orderBy('turn_index')->orderBy('id')->get();
            $tokens = DB::table('prompt_logs')->whereIn('job_log_id', $jobs->pluck('id'))->groupBy('job_log_id')
                ->selectRaw('job_log_id, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(tokens_reasoning) as tokens_reasoning')
                ->get()->keyBy('job_log_id');
            $addressees = Message::whereIn('job_log_id', $jobs->pluck('id'))->whereNotNull('expert_id')->pluck('addressee_expert_id', 'job_log_id');

            return $jobs->map(function (JobLog $job) use ($run, $tokens, $addressees, $uuid, $flag) {
                $selection = $job->selection ?? [];
                $signals = $selection['signals'] ?? [];
                $speaker = $selection['speaker_id'] ?? $job->expert_id;
                $fallback = array_key_exists('fallback', $signals) ? $signals['fallback'] : (array_key_exists('fallbacks', $signals) ? $signals['fallbacks'] !== [] : null);

                return [
                    'run_uuid' => $run->uuid,
                    'turn_index' => $job->turn_index,
                    'status' => $job->status,
                    'spoken' => $job->words === null ? 0 : 1,
                    'expert_uuid' => $uuid($job->expert_id),
                    'seat' => $job->seat,
                    'addressee_expert_uuid' => $uuid($addressees[$job->id] ?? null),
                    'words' => $job->words,
                    'chars' => $job->chars,
                    'thought_words' => $job->thought_words,
                    'thought_chars' => $job->thought_chars,
                    'tokens_in' => $tokens[$job->id]->tokens_in ?? null,
                    'tokens_out' => $tokens[$job->id]->tokens_out ?? null,
                    'tokens_reasoning' => $tokens[$job->id]->tokens_reasoning ?? null,
                    'selector' => $selection['selector'] ?? null,
                    'fallback' => $flag($fallback),
                    'tie_broken' => $flag($selection['tie_broken'] ?? null),
                    'speaker_bid' => $speaker === null ? null : ($signals['bids']["E{$speaker}"] ?? null),
                    'judge_label' => $signals['label'] ?? null,
                    'selector_reasoning' => $selection['reasoning'] ?? null,
                    'selection_json' => $selection === [] ? null : json_encode($selection, JSON_UNESCAPED_UNICODE),
                    'error' => $job->error,
                ];
            });
        })->values()->all();
    }

    /** @param  list<array<string, scalar|null>>  $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => $value === null ? '' : (is_float($value) ? round($value, 6) : $value), $row), escape: '');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
