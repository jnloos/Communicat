<?php

namespace App\Services\StudyExport;

use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * turns.csv: one row per turn, failed ones included so the failure rate can
 * be read. A turn counts as spoken when words is set (SpokenTurns' rule).
 */
final class TurnTable
{
    private const COLUMNS = ['run_uuid', 'turn_index', 'status', 'spoken', 'expert_uuid', 'seat', 'addressee_expert_uuid', 'words', 'chars', 'thought_words', 'thought_chars', 'tokens_in', 'tokens_out', 'tokens_reasoning', 'selector', 'fallback', 'tie_broken', 'speaker_bid', 'speaker_bid_fallback', 'speaker_probability', 'speaker_jev_score', 'judge_label', 'selector_reasoning', 'selection_json', 'error'];

    public function __construct(private readonly TurnTokenSums $tokenSums) {}

    /** @return list<string> */
    public function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * @param  Collection<int, Project>  $runs
     * @return list<array<string, scalar|null>>
     */
    public function rows(Collection $runs): array
    {
        $expertUuids = Expert::pluck('uuid', 'id');

        return $runs->flatMap(fn (Project $run) => $this->rowsOf($run, $expertUuids))->values()->all();
    }

    /**
     * @param  Collection<int, string>  $expertUuids  by expert id
     * @return Collection<int, array<string, scalar|null>>
     */
    private function rowsOf(Project $run, Collection $expertUuids): Collection
    {
        $jobs = JobLog::where('project_id', $run->id)->orderBy('turn_index')->orderBy('id')->get();
        $tokens = $this->tokenSums->of($jobs->pluck('id'));
        $addressees = Message::whereIn('job_log_id', $jobs->pluck('id'))->whereNotNull('expert_id')->pluck('addressee_expert_id', 'job_log_id');

        return $jobs->map(fn (JobLog $job) => $this->row($run, $job, $tokens[$job->id] ?? [], $addressees[$job->id] ?? null, $expertUuids));
    }

    /**
     * @param  array<string, ?int>  $tokens  this job's exact token sums
     * @param  Collection<int, string>  $expertUuids  by expert id
     * @return array<string, scalar|null>
     */
    private function row(Project $run, JobLog $job, array $tokens, mixed $addresseeId, Collection $expertUuids): array
    {
        $selection = SelectionRecord::fromArray($job->selection);
        $speakerId = $selection->speakerId() ?? $job->expert_id;
        $bid = $selection->bidOf($speakerId);

        return [
            'run_uuid' => $run->uuid,
            'turn_index' => $job->turn_index,
            'status' => $job->status->value,
            'spoken' => $job->words === null ? 0 : 1,
            'expert_uuid' => self::uuidOf($expertUuids, $job->expert_id),
            'seat' => $job->seat,
            'addressee_expert_uuid' => self::uuidOf($expertUuids, $addresseeId),
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
            'speaker_probability' => $selection->speakerProbability($speakerId),
            'speaker_jev_score' => $selection->speakerJevScore($speakerId),
            'judge_label' => $selection->label(),
            'selector_reasoning' => $selection->reasoning(),
            'selection_json' => $selection->isEmpty() ? null : json_encode($selection->toArray(), JSON_UNESCAPED_UNICODE),
            'error' => $job->error,
        ];
    }

    /** @param  Collection<int, string>  $expertUuids */
    private static function uuidOf(Collection $expertUuids, mixed $id): ?string
    {
        return $id === null ? null : ($expertUuids[(int) $id] ?? null);
    }

    private static function flag(mixed $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }
}
