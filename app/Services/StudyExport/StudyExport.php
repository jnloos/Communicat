<?php

namespace App\Services\StudyExport;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\SpeakerShare;
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
use RuntimeException;
use ZipArchive;

/**
 * The raw data for an outside analysis: runs, participants and turns as three
 * CSV files joined by uuid. Tags become one column per category. A turn
 * counts as spoken when words is set (SpokenTurns' rule); failed turns stay in
 * so the failure rate can be read; NULL stays empty and never becomes 0.
 */
final class StudyExport
{
    private const RUN_COLUMNS = ['run_uuid', 'title', 'description', 'pipeline', 'model', 'seed', 'turn_budget', 'group', 'turns_spoken', 'turns_failed_without_speaking'];

    private const PARTICIPANT_COLUMNS = ['run_uuid', 'expert_uuid', 'expert_token', 'name', 'role', 'seat', 'turns', 'turn_share', 'words', 'word_share', 'words_per_turn'];

    private const TURN_COLUMNS = ['run_uuid', 'turn_index', 'status', 'spoken', 'expert_uuid', 'seat', 'addressee_expert_uuid', 'words', 'chars', 'thought_words', 'thought_chars', 'tokens_in', 'tokens_out', 'tokens_reasoning', 'selector', 'fallback', 'tie_broken', 'speaker_bid', 'speaker_bid_fallback', 'judge_label', 'selector_reasoning', 'selection_json', 'error'];

    public function __construct(private readonly SpeakingShares $shares) {}

    /** @param  Collection<int, Project>  $runs */
    public function write(Collection $runs, User $viewer, string $path): void
    {
        $projectCategories = TagCategory::ofScope(TagScope::Project)->orderBy('position')->orderBy('id')->get();
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->orderBy('position')->orderBy('id')->get();
        $expertUuids = Expert::pluck('uuid', 'id');
        $groups = ProjectGroup::where('user_id', $viewer->id)->pluck('name', 'id');

        $runTags = $this->tagColumns($projectCategories, self::RUN_COLUMNS);
        $personaTags = $this->tagColumns($personaCategories, self::PARTICIPANT_COLUMNS);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        $zip->addFromString('runs.csv', $this->csv(
            [...array_slice(self::RUN_COLUMNS, 0, 8), ...array_values($runTags), ...array_slice(self::RUN_COLUMNS, 8)],
            $this->runRows($runs, $viewer, $groups, $runTags)));
        $zip->addFromString('participants.csv', $this->csv(
            [...array_slice(self::PARTICIPANT_COLUMNS, 0, 6), ...array_values($personaTags), ...array_slice(self::PARTICIPANT_COLUMNS, 6)],
            $this->participantRows($runs, $personaTags)));
        $zip->addFromString('turns.csv', $this->csv(self::TURN_COLUMNS, $this->turnRows($runs, $expertUuids)));

        if (! $zip->close()) {
            throw new RuntimeException("Could not finish the archive {$path}.");
        }
    }

    /**
     * Header per category id. A category named like a fixed column of the file
     * gets the prefix "tag ", so neither overwrites the other.
     *
     * @param  Collection<int, TagCategory>  $categories
     * @param  list<string>  $fixed
     * @return array<int, string>
     */
    private function tagColumns(Collection $categories, array $fixed): array
    {
        $taken = array_map(fn (string $name) => mb_strtolower($name), $fixed);

        return $categories->mapWithKeys(fn (TagCategory $category) => [
            $category->id => in_array(mb_strtolower(trim($category->name)), $taken, true) ? 'tag '.$category->name : $category->name,
        ])->all();
    }

    public function filename(string $label): string
    {
        return 'communicat-study-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /** @return list<array<string, scalar|null>> */
    private function runRows(Collection $runs, User $viewer, Collection $groups, array $tagColumns): array
    {
        return $runs->map(function (Project $run) use ($viewer, $groups, $tagColumns) {
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

            foreach ($tagColumns as $categoryId => $header) {
                $row[$header] = $tags[$categoryId]->name ?? null;
            }

            return $row + ['turns_spoken' => $report->spokenTurns, 'turns_failed_without_speaking' => $report->failedTurns];
        })->values()->all();
    }

    /**
     * One row per participant as SpeakingShares reports them: the seated in
     * seat order, then everyone who spoke and has left the run, seat empty.
     *
     * @return list<array<string, scalar|null>>
     */
    private function participantRows(Collection $runs, array $tagColumns): array
    {
        return $runs->flatMap(function (Project $run) use ($tagColumns) {
            $report = $this->shares->forProject($run);
            $experts = Expert::with('tagValues')->whereIn('id', array_map(fn (SpeakerShare $share) => $share->expertId, $report->speakers))->get()->keyBy('id');

            return array_map(function (SpeakerShare $share) use ($run, $experts, $tagColumns) {
                $expert = $experts[$share->expertId];
                $tags = $expert->tagValues->keyBy('tag_category_id');
                $row = [
                    'run_uuid' => $run->uuid,
                    'expert_uuid' => $expert->uuid,
                    'expert_token' => $expert->promptId,
                    'name' => $expert->name,
                    'role' => $expert->role,
                    'seat' => $share->seat,
                ];

                foreach ($tagColumns as $categoryId => $header) {
                    $row[$header] = $tags[$categoryId]->name ?? null;
                }

                return $row + [
                    'turns' => $share->turns,
                    'turn_share' => $share->turnShare,
                    'words' => $share->words,
                    'word_share' => $share->wordShare,
                    'words_per_turn' => $share->turns === 0 ? null : $share->wordsPerTurn(),
                ];
            }, $report->speakers);
        })->values()->all();
    }

    /** @return list<array<string, scalar|null>> */
    private function turnRows(Collection $runs, Collection $expertUuids): array
    {
        $uuid = fn ($id) => $id === null ? null : ($expertUuids[(int) $id] ?? null);
        // A sum only counts when every call of the turn reported the figure.
        $sum = fn ($row, string $field, string $reported) => $row !== null && (int) $row->calls === (int) $row->{$reported} ? $row->{$field} : null;
        $flag = fn ($value) => $value === null ? null : ($value ? 1 : 0);

        return $runs->flatMap(function (Project $run) use ($uuid, $flag, $sum) {
            $jobs = JobLog::where('project_id', $run->id)->orderBy('turn_index')->orderBy('id')->get();
            $tokens = DB::table('prompt_logs')->whereIn('job_log_id', $jobs->pluck('id'))->groupBy('job_log_id')
                ->selectRaw('job_log_id, count(*) as calls,
                    count(tokens_in) as n_in, sum(tokens_in) as tokens_in,
                    count(tokens_out) as n_out, sum(tokens_out) as tokens_out,
                    count(tokens_reasoning) as n_reasoning, sum(tokens_reasoning) as tokens_reasoning')
                ->get()->keyBy('job_log_id');
            $addressees = Message::whereIn('job_log_id', $jobs->pluck('id'))->whereNotNull('expert_id')->pluck('addressee_expert_id', 'job_log_id');

            return $jobs->map(function (JobLog $job) use ($run, $tokens, $addressees, $uuid, $flag, $sum) {
                $selection = $job->selection ?? [];
                $signals = $selection['signals'] ?? [];
                $speaker = $selection['speaker_id'] ?? $job->expert_id;
                $fallback = array_key_exists('fallback', $signals) ? $signals['fallback'] : (array_key_exists('fallbacks', $signals) ? $signals['fallbacks'] !== [] : null);
                $bid = $this->speakerBid($signals, $speaker);

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
                    'tokens_in' => $sum($tokens[$job->id] ?? null, 'tokens_in', 'n_in'),
                    'tokens_out' => $sum($tokens[$job->id] ?? null, 'tokens_out', 'n_out'),
                    'tokens_reasoning' => $sum($tokens[$job->id] ?? null, 'tokens_reasoning', 'n_reasoning'),
                    'selector' => $selection['selector'] ?? null,
                    'fallback' => $flag($fallback),
                    'tie_broken' => $flag($selection['tie_broken'] ?? null),
                    'speaker_bid' => $bid['bid'],
                    'speaker_bid_fallback' => $flag($bid['imputed']),
                    'judge_label' => $signals['label'] ?? null,
                    'selector_reasoning' => $selection['reasoning'] ?? null,
                    'selection_json' => $selection === [] ? null : json_encode($selection, JSON_UNESCAPED_UNICODE),
                    'error' => $job->error,
                ];
            });
        })->values()->all();
    }

    /**
     * The speaker's bid in a Score turn. An unreadable bid is stored as the
     * lowest priority and its token listed in fallbacks: that 1 was never
     * said, so it stays empty and is flagged as imputed. Not a Score turn:
     * both null.
     *
     * @return array{bid: int|null, imputed: bool|null}
     */
    private function speakerBid(array $signals, mixed $speaker): array
    {
        if (! is_array($signals['bids'] ?? null) || $speaker === null) {
            return ['bid' => null, 'imputed' => null];
        }

        $token = "E{$speaker}";

        if (in_array($token, $signals['fallbacks'] ?? [], true)) {
            return ['bid' => null, 'imputed' => true];
        }

        return ['bid' => $signals['bids'][$token] ?? null, 'imputed' => false];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(function (string $column) use ($row) {
                $value = $row[$column] ?? null;

                return $value === null ? '' : (is_float($value) ? round($value, 6) : $value);
            }, $columns), escape: '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
