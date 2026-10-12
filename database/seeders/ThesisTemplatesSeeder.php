<?php

namespace Database\Seeders;

use App\Discussion\Values\QuestionDefinition;
use App\Models\Question;
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

    /** What each template shows and how to read it, by template name. */
    private const NOTES = [
        'Status effect on turn share by pipeline' => 'Shows the turn share of the focus persona with and without the CEO title, per pipeline, over the first 60 turns. The delta is no-status minus status, so a negative delta means the title raised the share. A delta near zero means the title changed little.',
        'Status effect on word share by pipeline' => 'The same question as for turn share, but measured in words. Read it next to the turn share: if the title raises words more than turns, the titled agent writes longer contributions rather than speaking more often. The delta is no-status minus status.',
        'Title holder against the others by pipeline (per head)' => 'Compares the turn share of the title holder with the average share of one other agent, in the status runs only, per pipeline. Equal shares would be 25 percent for each of four agents. A title holder far above the others means the title buys floor time.',
        'How the floor divides between title holder and others' => 'Shows how the whole floor splits between the title holder and the three others taken together, in the status runs only, per pipeline. The two parts always add up to 100 percent. Compare the title holder part across pipelines to see where the mechanism lets status through.',
        'Speaking inequality with and without status by pipeline' => 'Shows the Gini coefficient of the turn shares per run, with and without status, per pipeline. Zero means everyone speaks equally and higher values mean fewer agents dominate. If status raises the Gini, it concentrates the floor on fewer speakers.',
        'Seeing against blind judge' => 'Compares the turn share of the focus persona under the two judge pipelines in the status runs. The seeing judge knows the roles and the blind judge only reads anonymous drafts. A lower share under the blind judge means part of the effect comes from the judge seeing the title.',
    ];

    public function run(): void
    {
        $owner = User::where('is_admin', true)->orderBy('id')->first();

        if ($owner === null) {
            $this->command?->warn('No admin user, so no templates.');

            return;
        }

        foreach ($this->templates() as $name => $definition) {
            $notes = self::NOTES[$name];

            if ($definition === null) {
                $this->command?->warn("Skipped “{$name}”: a tag it names does not exist here.");

                continue;
            }

            Question::updateOrCreate(
                ['user_id' => $owner->id, 'name' => $name, 'is_template' => true],
                ['notes' => $notes, 'definition' => QuestionDefinition::fromArray($definition)->toArray()],
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
            'turns' => ['to' => 60],
            'compare_by' => "project_tag:{$condition}",
            'split_by' => 'pipeline',
        ] : null;

        $titleAgainstOthers = fn (string $aggregate, string $form) => $status && $titleHolder ? [
            'metric' => 'turn_share',
            'aggregate' => $aggregate,
            'projects' => ['tags' => $status],
            'turns' => ['to' => 60],
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
                'turns' => ['to' => 60],
                'compare_by' => "project_tag:{$condition}",
                'split_by' => 'pipeline',
            ] : null,
            'Seeing against blind judge' => $status && $focus ? [
                'metric' => 'turn_share',
                'people' => ['tags' => $focus],
                'projects' => ['tags' => $status, 'pipelines' => self::PIPELINES_JUDGE],
                'turns' => ['to' => 60],
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
