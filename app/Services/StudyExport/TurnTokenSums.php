<?php

namespace App\Services\StudyExport;

use App\Discussion\Values\TokenTotal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per job, each token figure summed over its calls in prompt_logs, or null
 * unless every call reported it (TokenTotal::exact()). A job without calls is
 * absent from the result.
 */
final class TurnTokenSums
{
    private const FIGURES = ['tokens_in', 'tokens_out', 'tokens_reasoning'];

    /**
     * @param  Collection<int, int>  $jobIds
     * @return array<int, array{tokens_in: ?int, tokens_out: ?int, tokens_reasoning: ?int}>
     */
    public function of(Collection $jobIds): array
    {
        return DB::table('prompt_logs')->whereIn('job_log_id', $jobIds)->groupBy('job_log_id')
            ->selectRaw('job_log_id, count(*) as calls,
                count(tokens_in) as n_tokens_in, sum(tokens_in) as tokens_in,
                count(tokens_out) as n_tokens_out, sum(tokens_out) as tokens_out,
                count(tokens_reasoning) as n_tokens_reasoning, sum(tokens_reasoning) as tokens_reasoning')
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->job_log_id => array_combine(
                self::FIGURES,
                array_map(fn (string $figure) => $this->exactSum($row, $figure), self::FIGURES),
            )])->all();
    }

    private function exactSum(object $row, string $figure): ?int
    {
        $sum = $row->{$figure};

        return (new TokenTotal($sum === null ? null : (int) $sum, (int) $row->calls === (int) $row->{"n_{$figure}"}))->exact();
    }
}
