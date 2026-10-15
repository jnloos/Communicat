<?php

namespace App\Services\StudyExport;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\SelectionRecord;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TokenTotal;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 *
 * The files are meant for R or Python, not for a spreadsheet. Text cells
 * (titles, reasoning, errors) are written verbatim: no Excel formula escaping,
 * because a prefixed quote would alter the data the analysis reads. README.txt
 * in the archive says so to whoever opens it.
 */
final class StudyExport
{
    /** runs.csv: the columns before the project tag columns, and those after them. */
    private const RUN_HEAD = ['run_uuid', 'title', 'description', 'pipeline', 'model', 'seed', 'turn_budget', 'group'];

    private const RUN_TAIL = ['turns_spoken', 'turns_failed_without_speaking'];

    /** participants.csv: the columns before the persona tag columns, and those after them. */
    private const PARTICIPANT_HEAD = ['run_uuid', 'expert_uuid', 'expert_token', 'name', 'role', 'seat'];

    private const PARTICIPANT_TAIL = ['turns', 'turn_share', 'words', 'word_share', 'words_per_turn'];

    private const TURN_COLUMNS = ['run_uuid', 'turn_index', 'status', 'spoken', 'expert_uuid', 'seat', 'addressee_expert_uuid', 'words', 'chars', 'thought_words', 'thought_chars', 'tokens_in', 'tokens_out', 'tokens_reasoning', 'selector', 'fallback', 'tie_broken', 'speaker_bid', 'speaker_bid_fallback', 'judge_label', 'selector_reasoning', 'selection_json', 'error'];

    /** Shipped as README.txt, so whoever opens the ZIP reads how before a spreadsheet mangles a cell. */
    private const README = <<<'TXT'
        Communicat study export

        runs.csv          one row per run
        participants.csv  one row per agent of a run, joined to runs by run_uuid
        turns.csv         one row per turn, joined by run_uuid and expert_uuid

        Read the files with R (readr::read_csv) or Python (pandas.read_csv): UTF-8,
        comma separated, quoted where needed. An empty cell means "not recorded",
        never 0.

        Spreadsheets: do not open the files with a double click. Excel reads a cell
        that starts with =, +, - or @ (some model reasoning does) as a formula and
        shows #NAME? or a wrong value. Import them instead (Data > From Text/CSV)
        and set the text columns to type Text.
        TXT;

    public function __construct(private readonly SpeakingShares $shares) {}

    /** @param  Collection<int, Project>  $runs */
    public function write(Collection $runs, User $viewer, string $path): void
    {
        $projectCategories = TagCategory::ofScope(TagScope::Project)->orderBy('position')->orderBy('id')->get();
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->orderBy('position')->orderBy('id')->get();
        $expertUuids = Expert::pluck('uuid', 'id');
        $groups = ProjectGroup::where('user_id', $viewer->id)->pluck('name', 'id');

        $runTags = $this->tagColumns($projectCategories, [...self::RUN_HEAD, ...self::RUN_TAIL]);
        $personaTags = $this->tagColumns($personaCategories, [...self::PARTICIPANT_HEAD, ...self::PARTICIPANT_TAIL]);
        $runs = (new EloquentCollection($runs->values()->all()))->loadMissing('tagValues');
        $reports = $runs->mapWithKeys(fn (Project $run) => [$run->id => $this->shares->forProject($run)]);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        $zip->addFromString('runs.csv', $this->csv(
            [...self::RUN_HEAD, ...array_values($runTags), ...self::RUN_TAIL],
            $this->runRows($runs, $reports, $viewer, $groups, $runTags)));
        $zip->addFromString('participants.csv', $this->csv(
            [...self::PARTICIPANT_HEAD, ...array_values($personaTags), ...self::PARTICIPANT_TAIL],
            $this->participantRows($runs, $reports, $personaTags)));
        $zip->addFromString('turns.csv', $this->csv(self::TURN_COLUMNS, $this->turnRows($runs, $expertUuids)));
        $zip->addFromString('README.txt', self::README."\n");

        if (! $zip->close()) {
            throw new RuntimeException("Could not finish the archive {$path}.");
        }
    }

    /**
     * Header per category id. A category named like a fixed column of the file
     * gets the prefix "tag "; one named like an earlier category (ignoring case
     * and surrounding space, as tag names match everywhere) gets its id as a
     * suffix. No header overwrites another.
     *
     * @param  Collection<int, TagCategory>  $categories
     * @param  list<string>  $fixed
     * @return array<int, string>
     */
    private function tagColumns(Collection $categories, array $fixed): array
    {
        $normal = fn (string $name) => mb_strtolower(trim($name));
        $fixed = array_map($normal, $fixed);
        $taken = $fixed;
        $headers = [];

        foreach ($categories as $category) {
            $header = in_array($normal($category->name), $fixed, true) ? 'tag '.$category->name : $category->name;

            if (in_array($normal($header), $taken, true)) {
                $header = trim($category->name)." #{$category->id}";
            }

            $headers[$category->id] = $header;
            $taken[] = $normal($header);
        }

        return $headers;
    }

    public function filename(string $label): string
    {
        return 'communicat-study-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /**
     * @param  Collection<int, ShareReport>  $reports  by project id
     * @return list<array<string, scalar|null>>
     */
    private function runRows(Collection $runs, Collection $reports, User $viewer, Collection $groups, array $tagColumns): array
    {
        return $runs->map(function (Project $run) use ($reports, $viewer, $groups, $tagColumns) {
            $report = $reports[$run->id];
            $tags = $run->tagValues->keyBy('tag_category_id');
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
     * @param  Collection<int, ShareReport>  $reports  by project id
     * @return list<array<string, scalar|null>>
     */
    private function participantRows(Collection $runs, Collection $reports, array $tagColumns): array
    {
        return $runs->flatMap(function (Project $run) use ($reports, $tagColumns) {
            $report = $reports[$run->id];
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
        return $runs->flatMap(fn (Project $run) => $this->turnRowsOf($run, $expertUuids))->values()->all();
    }

    /** @return Collection<int, array<string, scalar|null>> */
    private function turnRowsOf(Project $run, Collection $expertUuids): Collection
    {
        $jobs = JobLog::where('project_id', $run->id)->orderBy('turn_index')->orderBy('id')->get();
        $tokens = $this->exactTokenSums($jobs->pluck('id'));
        $addressees = Message::whereIn('job_log_id', $jobs->pluck('id'))->whereNotNull('expert_id')->pluck('addressee_expert_id', 'job_log_id');

        return $jobs->map(fn (JobLog $job) => $this->turnRow($run, $job, $tokens[$job->id] ?? [], $addressees[$job->id] ?? null, $expertUuids));
    }

    /**
     * Per job, each token figure summed over its calls, or null unless every
     * call reported it (TokenTotal::exact()).
     *
     * @param  Collection<int, int>  $jobIds
     * @return array<int, array<string, ?int>>
     */
    private function exactTokenSums(Collection $jobIds): array
    {
        return DB::table('prompt_logs')->whereIn('job_log_id', $jobIds)->groupBy('job_log_id')
            ->selectRaw('job_log_id, count(*) as calls,
                count(tokens_in) as n_in, sum(tokens_in) as tokens_in,
                count(tokens_out) as n_out, sum(tokens_out) as tokens_out,
                count(tokens_reasoning) as n_reasoning, sum(tokens_reasoning) as tokens_reasoning')
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->job_log_id => [
                'tokens_in' => $this->exactSum($row, 'tokens_in', 'n_in'),
                'tokens_out' => $this->exactSum($row, 'tokens_out', 'n_out'),
                'tokens_reasoning' => $this->exactSum($row, 'tokens_reasoning', 'n_reasoning'),
            ]])->all();
    }

    private function exactSum(object $row, string $sum, string $reported): ?int
    {
        return (new TokenTotal($row->{$sum} === null ? null : (int) $row->{$sum}, (int) $row->calls === (int) $row->{$reported}))->exact();
    }

    /**
     * @param  array<string, ?int>  $tokens  this job's exact token sums
     * @return array<string, scalar|null>
     */
    private function turnRow(Project $run, JobLog $job, array $tokens, mixed $addresseeId, Collection $expertUuids): array
    {
        $selection = SelectionRecord::fromArray($job->selection);
        $bid = $selection->bidOf($selection->speakerId() ?? $job->expert_id);

        return [
            'run_uuid' => $run->uuid,
            'turn_index' => $job->turn_index,
            'status' => $job->status->value,
            'spoken' => $job->words === null ? 0 : 1,
            'expert_uuid' => $this->uuidOf($expertUuids, $job->expert_id),
            'seat' => $job->seat,
            'addressee_expert_uuid' => $this->uuidOf($expertUuids, $addresseeId),
            'words' => $job->words,
            'chars' => $job->chars,
            'thought_words' => $job->thought_words,
            'thought_chars' => $job->thought_chars,
            'tokens_in' => $tokens['tokens_in'] ?? null,
            'tokens_out' => $tokens['tokens_out'] ?? null,
            'tokens_reasoning' => $tokens['tokens_reasoning'] ?? null,
            'selector' => $selection->selector(),
            'fallback' => self::flag($selection->fallback()),
            'tie_broken' => self::flag($selection->tieBroken()),
            'speaker_bid' => $bid['bid'],
            'speaker_bid_fallback' => self::flag($bid['imputed']),
            'judge_label' => $selection->label(),
            'selector_reasoning' => $selection->reasoning(),
            'selection_json' => $selection->isEmpty() ? null : json_encode($selection->toArray(), JSON_UNESCAPED_UNICODE),
            'error' => $job->error,
        ];
    }

    private function uuidOf(Collection $expertUuids, mixed $id): ?string
    {
        return $id === null ? null : ($expertUuids[(int) $id] ?? null);
    }

    private static function flag(mixed $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
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
