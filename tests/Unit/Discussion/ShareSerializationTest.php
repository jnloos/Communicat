<?php

namespace Tests\Unit\Discussion;

use App\Console\MetricsFormat;
use App\Discussion\Values\CurveMode;
use App\Discussion\Values\Participant;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\ShareUnit;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TrajectoryPoint;
use PHPUnit\Framework\TestCase;

/** The shapes `project:metrics` writes: a CSV header must match the rows under it. */
class ShareSerializationTest extends TestCase
{
    public function test_a_speakers_keys_are_its_csv_columns(): void
    {
        $share = new SpeakerShare(expertId: 7, name: 'Alice', seat: 1, turns: 3, words: 10, turnShare: 2 / 3, wordShare: 0.25);

        $this->assertSame(SpeakerShare::COLUMNS, array_keys($share->toArray()));
        $this->assertSame(0.666667, $share->toArray()['turn_share']);
        $this->assertSame(3.333333, $share->toArray()['words_per_turn']);
    }

    public function test_a_report_carries_its_totals_and_its_speakers(): void
    {
        $report = new ShareReport(speakers: [], spokenTurns: 0, failedTurns: 2, totalWords: 0, turnGini: 1 / 3, wordGini: 0.0, giniCeiling: 0.75);

        $this->assertSame(['run', 'speakers'], array_keys($report->toArray()));
        $this->assertSame(0.333333, $report->toArray()['run']['gini_turns']);
        $this->assertSame(2, $report->toArray()['run']['turns_failed_without_speaking']);
    }

    public function test_a_trajectorys_long_rows_match_its_long_columns(): void
    {
        $trajectory = new Trajectory(
            participants: [new Participant(7, 'Alice', 1), new Participant(8, 'Bob', 2)],
            window: 20,
            points: [new TrajectoryPoint(turn: 1, speakerId: 7, shares: ['cumulative_turns' => [7 => 1.0, 8 => 0.0]])],
        );

        $rows = $trajectory->longRows();

        $this->assertCount(2, $rows);
        $this->assertSame(Trajectory::longColumns(), array_keys($rows[0]));
        $this->assertSame(1.0, $rows[0]['cumulative_turns']);
        $this->assertSame(0.0, $rows[1]['rolling_words'], 'a series the point does not hold reads as zero');
        $this->assertSame(['window', 'participants', 'points'], array_keys($trajectory->toArray()));
    }

    public function test_every_mode_and_unit_name_a_series(): void
    {
        $this->assertSame(ShareSeries::RollingWords, ShareSeries::of(CurveMode::Rolling, ShareUnit::Words));
        $this->assertSame(ShareSeries::CumulativeTurns, ShareSeries::of(CurveMode::Cumulative, ShareUnit::Turns));
    }

    public function test_the_formats_are_listed_for_a_reader(): void
    {
        $this->assertSame('table, json or csv', MetricsFormat::listed());
    }
}
