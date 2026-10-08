<?php

namespace App\Discussion\Values;

/**
 * How one run's speaking shares developed, turn by turn — the evidence for how
 * many turns a run needs before its shares settle.
 *
 * The totals in ShareReport are this curve's last point; the curve shows how
 * the run got there.
 */
final readonly class Trajectory
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
}
