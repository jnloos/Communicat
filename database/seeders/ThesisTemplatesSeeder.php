<?php

namespace Database\Seeders;

use App\Discussion\Values\Question;
use App\Models\Comparison;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use App\Services\Tags\TagNames;
use Illuminate\Database\Seeder;

/**
 * The thesis's standard questions as templates, owned by the first admin.
 * Tags are named here and resolved to this database's ids; a template whose
 * category or value is missing is skipped and reported, never stored half
 * resolved. Idempotent per name. Run once:
 *
 *     php artisan db:seed --class=ThesisTemplatesSeeder
 */
class ThesisTemplatesSeeder extends Seeder
{
    private const PIPELINES_JUDGE = ['QualityPipeline', 'BlindQualityPipeline'];

    public function run(): void
    {
        $owner = User::where('is_admin', true)->orderBy('id')->first();

        if ($owner === null) {
            $this->command?->warn('No admin user, so no templates.');

            return;
        }

        foreach ($this->templates() as $name => $question) {
            if ($question === null) {
                $this->command?->warn("Skipped “{$name}”: a tag it names does not exist here.");

                continue;
            }

            Comparison::updateOrCreate(
                ['user_id' => $owner->id, 'name' => $name, 'is_template' => true],
                ['question' => Question::fromArray($question)->toArray()],
            );
        }
    }

    /** @return array<string, array<string, mixed>|null> name => question data, null when a tag is missing */
    private function templates(): array
    {
        $condition = $this->category(TagScope::Project, 'status condition');
        $status = $this->value(TagScope::Project, 'status condition', 'status');
        $titleHolder = $this->category(TagScope::Persona, 'title-holder');
        $focus = $this->value(TagScope::Persona, 'focus persona', 'true');

        $statusEffect = fn (string $metric) => $condition && $focus ? [
            'metric' => $metric,
            'people' => ['tags' => $focus],
            'projects' => ['turn_budget' => 60],
            'compare_by' => "project_tag:{$condition}",
            'split_by' => 'pipeline',
        ] : null;

        $titleAgainstOthers = fn (string $aggregate, string $form) => $status && $titleHolder ? [
            'metric' => 'turn_share',
            'aggregate' => $aggregate,
            'projects' => ['tags' => $status, 'turn_budget' => 60],
            'compare_by' => "persona_tag:{$titleHolder}",
            'split_by' => 'pipeline',
            'form' => $form,
        ] : null;

        return [
            'Status effect on turn share by pipeline' => $statusEffect('turn_share'),
            'Status effect on word share by pipeline' => $statusEffect('word_share'),
            'Title holder against the others by pipeline (per head)' => $titleAgainstOthers('per_head', 'points'),
            'How the floor divides between title holder and others' => $titleAgainstOthers('together', 'composition'),
            'Speaking inequality with and without status by pipeline' => $condition ? [
                'metric' => 'turn_gini',
                'projects' => ['turn_budget' => 60],
                'compare_by' => "project_tag:{$condition}",
                'split_by' => 'pipeline',
            ] : null,
            'Seeing against blind judge' => $status && $focus ? [
                'metric' => 'turn_share',
                'people' => ['tags' => $focus],
                'projects' => ['tags' => $status, 'pipelines' => self::PIPELINES_JUDGE, 'turn_budget' => 60],
                'compare_by' => 'pipeline',
            ] : null,
        ];
    }

    private function category(TagScope $scope, string $name): ?int
    {
        return TagCategory::ofScope($scope)->get()->first(fn (TagCategory $category) => TagNames::same($category->name, $name))?->id;
    }

    /** @return array<int, list<int>>|null category id => [value id], as a TagFilter stores it */
    private function value(TagScope $scope, string $category, string $value): ?array
    {
        $categoryId = $this->category($scope, $category);
        $found = $categoryId === null ? null : TagValue::where('tag_category_id', $categoryId)->get()
            ->first(fn (TagValue $candidate) => TagNames::same($candidate->name, $value));

        return $found === null ? null : [$categoryId => [$found->id]];
    }
}
