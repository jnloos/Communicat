<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A row with a manual order among its siblings: new rows go to the end, and
 * moveTo() takes the index wire:sort reports and renumbers all siblings 1..n.
 */
trait HasPosition
{
    /** The rows this one is ordered among, itself included. */
    abstract protected function siblings(): Builder;

    public static function bootHasPosition(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->isDirty('position')) {
                $model->position = (int) $model->siblings()->max('position') + 1;
            }
        });
    }

    /** @param  int  $index  0-based target position, as wire:sort's $position */
    public function moveTo(int $index): void
    {
        $others = $this->siblings()
            ->whereKeyNot($this->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        array_splice($others, max(0, min($index, count($others))), 0, [$this]);

        foreach ($others as $offset => $row) {
            $row->newQuery()->whereKey($row->getKey())->update(['position' => $offset + 1]);
        }

        $this->position = array_search($this, $others, true) + 1;
    }
}
