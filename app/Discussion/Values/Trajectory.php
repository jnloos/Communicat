<?php

namespace App\Discussion\Values;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How one run's speaking shares developed, turn by turn — the evidence for how
 * many turns a run needs before its shares settle.
 *
 * The totals in ShareReport are this curve's last point; the curve shows how
 * the run got there.
 */
/** @implements Arrayable<string, mixed> */
final readonly class Trajectory implements Arrayable
{
    /**
     * @param  list<Participant>  $participants  in seat order, including anyone who never spoke
     * @param  int  $window  how many of the latest spoken turns a rolling share spans
     * @param  list<TrajectoryPoint>  $points  one per spoken turn, in turn order
     */
    public function __construct(
        public array $participants,
        public int $window,
        public array $points,
    ) {}

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    /** @return array{window: int, participants: list<array<string, mixed>>, points: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'window' => $this->window,
            'participants' => array_map(fn (Participant $participant) => $participant->toArray(), $this->participants),
            'points' => array_map(fn (TrajectoryPoint $point) => $point->toArray(), $this->points),
        ];
    }

    /**
     * The keys of longRows(), for a table header that has to stand without a row.
     *
     * @return list<string>
     */
    public static function longColumns(): array
    {
        return ['turn', 'speaker_id', 'expert_id', 'name', 'seat', 'window',
            ...array_map(fn (ShareSeries $series) => $series->value, ShareSeries::cases())];
    }

    /**
     * Long format, one row per turn and participant with every curve's share:
     * the shape a plotting or mixed-model script reads directly.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function longRows(): array
    {
        $rows = [];

        foreach ($this->points as $point) {
            foreach ($this->participants as $participant) {
                $row = [
                    'turn' => $point->turn,
                    'speaker_id' => $point->speakerId,
                    'expert_id' => $participant->expertId,
                    'name' => $participant->name,
                    'seat' => $participant->seat,
                    'window' => $this->window,
                ];

                foreach (ShareSeries::cases() as $series) {
                    $row[$series->value] = round($point->share($series, $participant->expertId), 6);
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }
}
