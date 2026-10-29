<?php

namespace App\Discussion\Values;

use App\Discussion\Support\LocaleNumber;
use Closure;

/**
 * One run's shares shaped for the Flux charts of the statistics flyout: the
 * two pies (turn share, word share) and the trajectory's lines and rows.
 *
 * It computes no share itself — those come from SpeakingShares and
 * ShareTrajectory — it only rearranges a report and a trajectory into the
 * rows Flux reads, formatted for the reader's locale. It needs nothing beyond
 * translations and that formatting, so it lives with the values it presents.
 *
 * A participant's colour comes from its seat index, the same index the pies,
 * the lines and the table use, so one participant keeps one colour throughout.
 */
final readonly class ShareChartData
{
    /** The palette Flux allows for pie sectors, in the order seats are handed out. */
    private const COLOURS = ['blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'fuchsia', 'lime'];

    public function __construct(
        public ShareReport $report,
        public Trajectory $trajectory,
        /** Which of the trajectory's four curves the rows carry. */
        public ShareSeries $series,
    ) {}

    /** Colour by seat, not by row order, so it holds across both charts and the table. */
    public static function colorFor(int $index): string
    {
        return self::COLOURS[$index % count(self::COLOURS)];
    }

    /**
     * Both pies with what their card prints around them.
     *
     * `shareLabel` names the share in the tooltip, `legend` lists every participant
     * (a silent one at 0 %) so a slice is never identified by its colour alone, and
     * `giniTitle` spells the coefficient out with the ceiling of this group size.
     *
     * @return list<array{sectors: list<array{name: string, value: int, share: string, color: string}>, legend: list<array{name: string, share: string, color: string}>, label: string, shareLabel: string, unit: string, gini: float, giniTitle: string, total: string}>
     */
    public function pies(): array
    {
        $report = $this->report;

        return [
            [
                'sectors' => $this->turnSectors(),
                'legend' => $this->legend(fn (SpeakerShare $s) => $s->turnShare),
                'label' => __('statistics.turn_share'),
                'shareLabel' => __('statistics.columns.turn_share'),
                'unit' => __('statistics.columns.turns'),
                'gini' => $report->turnGini,
                'giniTitle' => $this->giniTitle($report->turnGini),
                'total' => trans_choice('statistics.turns_total', $report->spokenTurns, ['count' => $report->spokenTurns]),
            ],
            [
                'sectors' => $this->wordSectors(),
                'legend' => $this->legend(fn (SpeakerShare $s) => $s->wordShare),
                'label' => __('statistics.word_share'),
                'shareLabel' => __('statistics.columns.word_share'),
                'unit' => __('statistics.columns.words'),
                'gini' => $report->wordGini,
                'giniTitle' => $this->giniTitle($report->wordGini),
                'total' => trans_choice('statistics.words_total', $report->totalWords, ['count' => LocaleNumber::decimal($report->totalWords, 0)]),
            ],
        ];
    }

    /**
     * One line per participant under a pie: colour, name, share. Unlike the
     * sectors it keeps someone who never spoke, at 0 %.
     *
     * @param  Closure(SpeakerShare): float  $fraction
     * @return list<array{name: string, share: string, color: string}>
     */
    public function legend(Closure $fraction): array
    {
        $rows = [];

        foreach ($this->report->speakers as $index => $share) {
            $rows[] = [
                'name' => $share->name,
                'share' => LocaleNumber::percent($fraction($share)),
                'color' => self::colorFor($index),
            ];
        }

        return $rows;
    }

    /** The Gini with its meaning and the ceiling of this group size, e.g. "Gini 0.18 (0 = equal, max 0.75 with 4 people)". */
    private function giniTitle(float $gini): string
    {
        $people = count($this->report->speakers);

        return trans_choice('statistics.gini_title', $people, [
            'gini' => LocaleNumber::decimal($gini, 2),
            'max' => LocaleNumber::decimal($this->report->giniCeiling, 2),
            'count' => $people,
        ]);
    }

    /** @return list<array{name: string, value: int, share: string, color: string}> */
    public function turnSectors(): array
    {
        return $this->sectors(fn (SpeakerShare $s) => $s->turns, fn (SpeakerShare $s) => $s->turnShare);
    }

    /** @return list<array{name: string, value: int, share: string, color: string}> */
    public function wordSectors(): array
    {
        return $this->sectors(fn (SpeakerShare $s) => $s->words, fn (SpeakerShare $s) => $s->wordShare);
    }

    /**
     * One row per participant for a Flux pie: the value, the label, and the
     * colour.
     *
     * The key must be spelled `color`: Flux reads `datum.color` and paints
     * `var(--color-<name>-500)`, and an unknown name silently falls back to a
     * hash of the label — which would give the same participant two different
     * colours in the two charts.
     *
     * `share` is pre-formatted here rather than in the chart: the tooltip prints
     * whatever the datum holds, and the locale's separators are a server-side
     * concern.
     *
     * @param  Closure(SpeakerShare): int  $amount  the unit's absolute value
     * @param  Closure(SpeakerShare): float  $fraction  the same unit as a share, 0..1
     * @return list<array{name: string, value: int, share: string, color: string}>
     */
    private function sectors(Closure $amount, Closure $fraction): array
    {
        $rows = [];

        foreach ($this->report->speakers as $index => $share) {
            $value = $amount($share);

            // Flux drops a zero sector anyway; leaving it out keeps the payload
            // honest. A participant who never spoke stays visible in the table.
            if ($value > 0) {
                $rows[] = [
                    'name' => $share->name,
                    'value' => $value,
                    'share' => LocaleNumber::percent($fraction($share)),
                    'color' => self::colorFor($index),
                ];
            }
        }

        return $rows;
    }

    /**
     * One row per spoken turn for a Flux line chart: the turn on the x axis, its
     * tooltip heading, and one percentage per participant under the key
     * `e{expert id}` that the participant's line reads.
     *
     * @return list<array<string, int|float|string>>
     */
    public function trajectoryRows(): array
    {
        $rows = [];

        foreach ($this->trajectory->points as $point) {
            $row = ['turn' => $point->turn, 'label' => __('statistics.trajectory.turn', ['turn' => $point->turn])];

            foreach ($this->trajectory->participants as $participant) {
                $row[self::fieldFor($participant)] = round($point->share($this->series, $participant->expertId) * 100, 1);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One line per participant, including one who never spoke: unlike a pie
     * sector, a line flat at zero says something.
     *
     * @return list<array{field: string, name: string, color: string}>
     */
    public function trajectoryLines(): array
    {
        $lines = [];

        foreach ($this->trajectory->participants as $index => $participant) {
            $lines[] = [
                'field' => self::fieldFor($participant),
                'name' => $participant->name,
                'color' => self::colorFor($index),
            ];
        }

        return $lines;
    }

    private static function fieldFor(Participant $participant): string
    {
        return 'e'.$participant->expertId;
    }
}
