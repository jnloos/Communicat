<?php

namespace App\Models;

use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\SeriesDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user's saved comparison: a list of series and one view over all of them. */
class Comparison extends Model
{
    protected $fillable = ['user_id', 'name', 'series', 'view'];

    protected $casts = [
        'series' => 'array',
        'view' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<SeriesDefinition> */
    public function seriesDefinitions(): array
    {
        return array_values(array_map(
            fn (mixed $series) => SeriesDefinition::fromArray(is_array($series) ? $series : []),
            $this->series ?? [],
        ));
    }

    public function comparisonView(): ComparisonView
    {
        return ComparisonView::fromArray($this->view ?? []);
    }
}
