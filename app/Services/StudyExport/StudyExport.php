<?php

namespace App\Services\StudyExport;

use App\Discussion\Metrics\SpeakingShares;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The raw data for an outside analysis: runs, participants and turns as three
 * CSV files joined by uuid, in one ZIP with a README. Tags become one column
 * per category (TagColumns); NULL stays empty and never becomes 0. Each file
 * is its own table class; this one gathers what they share and hands the
 * tables to StudyArchive.
 */
final class StudyExport
{
    public function __construct(
        private readonly SpeakingShares $shares,
        private readonly TurnTable $turns,
        private readonly StudyArchive $archive,
    ) {}

    /** @param  Collection<int, Project>  $runs */
    public function write(Collection $runs, User $viewer, string $path): void
    {
        $runs = (new EloquentCollection($runs->values()->all()))->loadMissing('tagValues');
        $reports = $runs->mapWithKeys(fn (Project $run) => [$run->id => $this->shares->forProject($run)]);
        $runTable = new RunTable($this->categoriesOf(TagScope::Project));
        $participantTable = new ParticipantTable($this->categoriesOf(TagScope::Persona));

        $this->archive->write($path, [
            'runs.csv' => [$runTable->columns(), $runTable->rows($runs, $reports, $viewer)],
            'participants.csv' => [$participantTable->columns(), $participantTable->rows($runs, $reports)],
            'turns.csv' => [$this->turns->columns(), $this->turns->rows($runs)],
        ]);
    }

    public function filename(string $label): string
    {
        return 'communicat-study-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /** @return Collection<int, TagCategory> in column order */
    private function categoriesOf(TagScope $scope): Collection
    {
        return TagCategory::ofScope($scope)->orderBy('position')->orderBy('id')->get();
    }
}
