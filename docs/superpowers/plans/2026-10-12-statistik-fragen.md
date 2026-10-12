# Statistik-Seite als Frage: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Den Reihen-Editor der Statistik-Seite durch eine Frage als Satz mit fester Antwortform (Punkte, Anteile, Verlauf) und dauerhaft gespeicherte Vorlagen ersetzen.

**Architecture:** Eine unveränderliche `Question` (Values) beschreibt die Frage und normalisiert Formulareingaben. `QuestionEvaluator` (Metrics/Questions) beantwortet sie über die bestehende Rechenschicht (`SpeakingShares`, `ShareTrajectory`) und liefert `QuestionResult` → `Panel` → `GroupResult` → `RunValue`. Livewire `QuestionEditor` bindet die Frage als Array, speichert bei jeder Änderung und zeigt Satz und Antwort; `StatisticsPage` verwaltet eigene Fragen und Vorlagen (`comparisons.is_template`).

**Tech Stack:** Laravel 12, Livewire 3, Flux/Flux Pro (`flux:dropdown`, `flux:popover`, `flux:chart`), PHPUnit 11, SQLite.

**Spec:** `docs/superpowers/specs/2026-10-12-statistik-fragen-design.md`

## Global Constraints

- Rechenschicht bleibt unverändert: `SpokenTurns`, `SpeakingShares`, `ShareTrajectory`, `Gini`. Gezählt wird nur über sie (CLAUDE.md, Metrics).
- Werte-Typen gehören nach `App\Discussion\Values`, Dienste nach `App\Discussion\Metrics\Questions`, Livewire-Darstellungshelfer nach `App\Livewire\Statistics` (CLAUDE.md, „Where a type lives“).
- Ein Filter, der niemanden trifft, darf nie still auf „alle“ ausweiten.
- Keine Kreise auf der Statistik-Seite; der Flyout im Chat bleibt unverändert.
- UI-Texte nur über `lang/{en,de}/questions.php`; jede Kopie in beiden Sprachen.
- Kommentare erklären das Warum, im Stil der umgebenden Dateien (englisch, PHPDoc mit Generics).
- `vendor/bin/pint` vor jedem Commit; Tests: `php artisan test --filter=…`.
- Nach jeder Task: Skill `anthropic-skills:clean-code-review` (Self-Check) auf den geänderten Code (Nutzer-CLAUDE.md).
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Erwartete Fehlschläge bis Task 9:** Ab Task 2 (Labels) bzw. Task 3 (Schema) schlagen die Alt-Tests `tests/Feature/Statistics/{ComparisonEditorTest,ComparisonResultsTest,ComparisonResultTest,ComparisonTemplatesTest,SeriesProjectsTest}.php` und `tests/Unit/Discussion/{AgentReferenceTest,SeriesColorTest,SeriesDefinitionTest,SeriesStatisticsTest}.php` fehl; `StatisticsPageTest` bis Task 8. Sie werden nicht repariert, sondern in Task 8/9 ersetzt bzw. gelöscht. Jede andere rote Zeile in `php artisan test` ist ein echter Fehler.

## Dateistruktur

| Datei | Verantwortung |
|---|---|
| `app/Discussion/Values/Aggregate.php` | zusammen / pro Kopf |
| `app/Discussion/Values/ResultForm.php` | Punkte / Anteile / Verlauf |
| `app/Discussion/Values/DimensionType.php`, `Dimension.php` | wonach verglichen oder aufgeteilt wird; Schlüssel `persona_tag:3` |
| `app/Discussion/Values/ProjectFilter.php`, `PeopleFilter.php` | welche Läufe, welche Personen |
| `app/Discussion/Values/Question.php` | die Frage, Normalisierung, `supports()` |
| `app/Discussion/Values/RunValue.php`, `GroupResult.php`, `Panel.php`, `QuestionResult.php` | die Antwort |
| `app/Discussion/Values/Metric.php` (ändern) | `ofGroup`, `fairShare`, `formatDelta`; Labels aus `questions.metrics` |
| `app/Discussion/Metrics/Questions/QuestionRuns.php` | Läufe des Nutzers nach `ProjectFilter` |
| `app/Discussion/Metrics/Questions/DimensionValues.php` | Labels und Werte einer Dimension für Lauf/Agent |
| `app/Discussion/Metrics/Questions/GroupCollector.php` | sammelt eine Gruppe während der Auswertung |
| `app/Discussion/Metrics/Questions/QuestionEvaluator.php` | beantwortet die Frage |
| `app/Livewire/Statistics/QuestionSentence.php` | die Frage in Worten (Chips, automatischer Name) |
| `app/Livewire/Statistics/GroupColor.php`, `CurveRows.php` | Farbe je Gruppe, Zeilen für `flux:chart` |
| `app/Livewire/Statistics/QuestionEditor.php` + View | Satz, Antwort, Autosave |
| `app/Livewire/Statistics/StatisticsPage.php` + View (ändern) | Liste, Vorlagen |
| `resources/views/components/statistics/{chip,point-panels,composition-bars,curve-panels,run-table}.blade.php` | Darstellung |
| `database/migrations/2026_10_12_000001_turn_comparisons_into_questions.php` | Schema |
| `database/seeders/ThesisTemplatesSeeder.php` | sechs Thesis-Vorlagen |
| `lang/{en,de}/questions.php` | alle Texte |

Gelöscht in Task 9: `ComparisonEditor`, `ComparisonResult`, `ResultCharts`, `SeriesLabels`, `SeriesSummary`, `SeriesDefinition`, `SeriesProjects`, `SeriesStatistics`, `Metrics/References/*`, `Metrics/Templates/*`, `ComparisonView`, `ComparisonDisplay`, `SeriesColor`, `SeriesResult`, `SeriesLine`, `ProjectValue`, `AgentGroup`, Views `comparison-editor`, `range-bars`, `pies`, `lang/*/comparisons.php` und ihre Tests. `TrajectoryMean` bleibt (wird wiederverwendet).

---

### Task 1: Werte der Frage und Sprachdatei

**Files:**
- Create: `app/Discussion/Values/Aggregate.php`, `ResultForm.php`, `DimensionType.php`, `Dimension.php`, `ProjectFilter.php`, `PeopleFilter.php`, `Question.php`
- Create: `lang/en/questions.php`, `lang/de/questions.php`
- Test: `tests/Unit/Discussion/QuestionTest.php`

**Interfaces:**
- Produces:
  - `enum Aggregate: string { Together='together'; PerHead='per_head'; label(): string }`
  - `enum ResultForm: string { Points='points'; Composition='composition'; Trajectory='trajectory'; label(): string }`
  - `enum DimensionType: string { ProjectTag='project_tag'; PersonaTag='persona_tag'; Pipeline='pipeline'; Model='model'; isTag(): bool }`
  - `Dimension(DimensionType $type, ?int $categoryId = null)`; `static fromKey(mixed): ?Dimension`; `key(): string`; `isPersonaLevel(): bool`
  - `ProjectFilter(TagFilter $tags, list<string> $pipelines, list<string> $models, ?int $turnBudget)`; `fromArray(mixed)`, `toArray()`, `isEmpty()`
  - `PeopleFilter(TagFilter $tags, ?string $expertName)`; `fromArray(mixed)`, `toArray()`, `isEveryone()`
  - `Question(Metric $metric, PeopleFilter $people, Aggregate $aggregate, ProjectFilter $projects, ?Dimension $compareBy, ?Dimension $splitBy, ResultForm $form, string $curve)`; `fromArray(array)`, `toArray()`, `supports(ResultForm)`, `withForm(ResultForm)`
  - `toArray()` speichert eine fehlende Dimension als `''` (Selects binden `''` an „nichts“).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Discussion/QuestionTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\Question;
use App\Discussion\Values\ResultForm;
use Tests\TestCase;

/** Question normalises form input into a question that can be answered. */
class QuestionTest extends TestCase
{
    public function test_an_empty_question_asks_for_everyones_turn_share_in_every_run(): void
    {
        $question = Question::fromArray([]);

        $this->assertSame(Metric::TurnShare, $question->metric);
        $this->assertTrue($question->people->isEveryone());
        $this->assertSame(Aggregate::Together, $question->aggregate);
        $this->assertTrue($question->projects->isEmpty());
        $this->assertNull($question->compareBy);
        $this->assertNull($question->splitBy);
        $this->assertSame(ResultForm::Points, $question->form);
    }

    public function test_a_saved_question_reads_back_unchanged(): void
    {
        $saved = [
            'metric' => 'word_share',
            'people' => ['tags' => [3 => [7]], 'expert_name' => 'Alex Brandt'],
            'aggregate' => 'per_head',
            'projects' => ['tags' => [1 => [1, 2]], 'pipelines' => ['ScorePipeline'], 'models' => ['openai-gpt-5'], 'turn_budget' => 60],
            'compare_by' => 'project_tag:1',
            'split_by' => 'pipeline',
            'form' => 'trajectory',
            'curve' => 'rolling',
        ];

        $this->assertSame($saved, Question::fromArray($saved)->toArray());
    }

    public function test_dimension_keys(): void
    {
        $this->assertEquals(new Dimension(DimensionType::PersonaTag, 3), Dimension::fromKey('persona_tag:3'));
        $this->assertEquals(new Dimension(DimensionType::Pipeline), Dimension::fromKey('pipeline'));
        $this->assertNull(Dimension::fromKey(''));
        $this->assertNull(Dimension::fromKey('persona_tag'));
        $this->assertNull(Dimension::fromKey('persona_tag:x'));
        $this->assertNull(Dimension::fromKey('colour'));
        $this->assertNull(Dimension::fromKey(null));
        $this->assertSame('project_tag:1', Dimension::fromKey('project_tag:1')->key());
    }

    public function test_gini_counts_everyone_and_cannot_compare_within_a_run(): void
    {
        $question = Question::fromArray([
            'metric' => 'turn_gini',
            'people' => ['expert_name' => 'Alex Brandt'],
            'aggregate' => 'per_head',
            'compare_by' => 'persona_tag:3',
        ]);

        $this->assertTrue($question->people->isEveryone());
        $this->assertSame(Aggregate::Together, $question->aggregate);
        $this->assertNull($question->compareBy);
    }

    public function test_panels_never_split_agents_nor_repeat_the_comparison(): void
    {
        $this->assertNull(Question::fromArray(['split_by' => 'persona_tag:3'])->splitBy);
        $this->assertNull(Question::fromArray(['compare_by' => 'pipeline', 'split_by' => 'pipeline'])->splitBy);
        $this->assertSame('model', Question::fromArray(['compare_by' => 'pipeline', 'split_by' => 'model'])->splitBy->key());
    }

    public function test_shares_only_appear_when_the_groups_add_up_to_everyone(): void
    {
        $composition = ['compare_by' => 'persona_tag:3', 'form' => 'composition'];

        $this->assertSame(ResultForm::Composition, Question::fromArray($composition)->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'aggregate' => 'per_head'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'people' => ['expert_name' => 'A']])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'compare_by' => 'project_tag:1'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'metric' => 'words_per_turn'])->form);
    }

    public function test_a_curve_exists_for_shares_only(): void
    {
        $this->assertSame(ResultForm::Trajectory, Question::fromArray(['form' => 'trajectory'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray(['metric' => 'turn_gini', 'form' => 'trajectory'])->form);
    }

    public function test_project_filter_drops_blank_input(): void
    {
        $filter = ProjectFilter::fromArray(['tags' => [1 => ['']], 'pipelines' => ['', 'ScorePipeline', 'ScorePipeline'], 'models' => [null], 'turn_budget' => '0']);

        $this->assertSame(['ScorePipeline'], $filter->pipelines);
        $this->assertSame([], $filter->models);
        $this->assertNull($filter->turnBudget);
        $this->assertTrue($filter->tags->isEmpty());
    }

    public function test_aggregate_only_applies_to_shares(): void
    {
        $this->assertSame(Aggregate::Together, Question::fromArray(['metric' => 'words_per_turn', 'aggregate' => 'per_head'])->aggregate);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuestionTest`
Expected: FAIL, `Class "App\Discussion\Values\Question" not found`.

- [ ] **Step 3: Write the value types**

`app/Discussion/Values/Aggregate.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * How a group of agents counts in a share. Together adds their shares, so the
 * groups of a run add up to 100 %; per head divides by the group's size, so a
 * group of one compares fairly with a group of three.
 */
enum Aggregate: string
{
    case Together = 'together';
    case PerHead = 'per_head';

    public function label(): string
    {
        return __("questions.aggregates.{$this->value}");
    }
}
```

`app/Discussion/Values/ResultForm.php`:

```php
<?php

namespace App\Discussion\Values;

/** The shapes an answer can take. Which ones a question allows is Question::supports()' call. */
enum ResultForm: string
{
    case Points = 'points';
    case Composition = 'composition';
    case Trajectory = 'trajectory';

    public function label(): string
    {
        return __("questions.forms.{$this->value}");
    }
}
```

`app/Discussion/Values/DimensionType.php`:

```php
<?php

namespace App\Discussion\Values;

/** What a question can compare or split by. */
enum DimensionType: string
{
    case ProjectTag = 'project_tag';
    case PersonaTag = 'persona_tag';
    case Pipeline = 'pipeline';
    case Model = 'model';

    public function isTag(): bool
    {
        return $this === self::ProjectTag || $this === self::PersonaTag;
    }
}
```

`app/Discussion/Values/Dimension.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * What a question compares or splits by: a tag category, the pipeline or the
 * model. Its key ("persona_tag:3", "pipeline") is what a select binds and what
 * a saved question stores, so the form needs no translation step.
 */
final readonly class Dimension
{
    public function __construct(
        public DimensionType $type,
        public ?int $categoryId = null,
    ) {}

    /** Null for an empty or malformed key, and for a tag type without its category. */
    public static function fromKey(mixed $key): ?self
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        [$type, $category] = array_pad(explode(':', $key, 2), 2, null);
        $type = DimensionType::tryFrom($type);

        return match (true) {
            $type === null => null,
            ! $type->isTag() => new self($type),
            is_numeric($category) => new self($type, (int) $category),
            default => null,
        };
    }

    public function key(): string
    {
        return $this->type->isTag() ? "{$this->type->value}:{$this->categoryId}" : $this->type->value;
    }

    /** A persona tag sorts the agents of one run; every other dimension sorts runs. */
    public function isPersonaLevel(): bool
    {
        return $this->type === DimensionType::PersonaTag;
    }
}
```

`app/Discussion/Values/ProjectFilter.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * Which runs a question reads: project tags (TagFilter rules), any of the
 * pipelines, any of the models, one turn budget. An empty part filters
 * nothing; an empty filter means every run the user may read, and the
 * sentence says so.
 */
final readonly class ProjectFilter
{
    /**
     * @param  list<string>  $pipelines  short class names, OR
     * @param  list<string>  $models  model keys, OR
     */
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public array $pipelines = [],
        public array $models = [],
        public ?int $turnBudget = null,
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $budget = $data['turn_budget'] ?? null;

        return new self(
            TagFilter::fromArray($data['tags'] ?? []),
            self::names($data['pipelines'] ?? []),
            self::names($data['models'] ?? []),
            is_numeric($budget) && (int) $budget > 0 ? (int) $budget : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags->toArray(),
            'pipelines' => $this->pipelines,
            'models' => $this->models,
            'turn_budget' => $this->turnBudget,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->tags->isEmpty() && $this->pipelines === [] && $this->models === [] && $this->turnBudget === null;
    }

    /** @return list<string> */
    private static function names(mixed $values): array
    {
        return array_values(array_unique(array_filter((array) $values, fn ($value) => is_string($value) && $value !== '')));
    }
}
```

`app/Discussion/Values/PeopleFilter.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * Whose share a question measures: everyone, the agents whose persona carries
 * the tags (TagFilter rules), the agents of one name, or both narrowed
 * together. A run in which the filter matches nobody drops out of the answer;
 * the filter never widens to everyone.
 */
final readonly class PeopleFilter
{
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public ?string $expertName = null,
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $name = trim((string) ($data['expert_name'] ?? ''));

        return new self(TagFilter::fromArray($data['tags'] ?? []), $name === '' ? null : $name);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tags' => $this->tags->toArray(), 'expert_name' => $this->expertName];
    }

    public function isEveryone(): bool
    {
        return $this->tags->isEmpty() && $this->expertName === null;
    }
}
```

`app/Discussion/Values/Question.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * A question to the study's runs, read as one sentence: [metric] of [people]
 * ([together | per head]) in [runs], compared by [dimension], split by
 * [dimension]. fromArray() turns any form input into a question that can be
 * answered, so neither the editor nor the evaluator meets a contradiction:
 *
 * - Gini describes a whole run: it counts everyone and cannot compare agents
 *   within a run.
 * - The aggregate only applies to shares; words per turn is a ratio already.
 * - Panels sort runs, so splitBy is never a persona tag, nor the dimension
 *   compareBy already uses.
 * - A form the question cannot show falls back to points.
 */
final readonly class Question
{
    public function __construct(
        public Metric $metric = Metric::TurnShare,
        public PeopleFilter $people = new PeopleFilter,
        public Aggregate $aggregate = Aggregate::Together,
        public ProjectFilter $projects = new ProjectFilter,
        public ?Dimension $compareBy = null,
        public ?Dimension $splitBy = null,
        public ResultForm $form = ResultForm::Points,
        /** 'cumulative' or 'rolling', as Metric::series() reads it. */
        public string $curve = 'cumulative',
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $metric = Metric::tryFrom((string) ($data['metric'] ?? '')) ?? Metric::TurnShare;
        $compareBy = Dimension::fromKey($data['compare_by'] ?? null);
        $splitBy = Dimension::fromKey($data['split_by'] ?? null);

        if ($metric->isRunLevel() && $compareBy?->isPersonaLevel()) {
            $compareBy = null;
        }

        if ($splitBy?->isPersonaLevel() || ($splitBy !== null && $splitBy == $compareBy)) {
            $splitBy = null;
        }

        $question = new self(
            metric: $metric,
            people: $metric->isRunLevel() ? new PeopleFilter : PeopleFilter::fromArray($data['people'] ?? []),
            aggregate: $metric->isShare() ? (Aggregate::tryFrom((string) ($data['aggregate'] ?? '')) ?? Aggregate::Together) : Aggregate::Together,
            projects: ProjectFilter::fromArray($data['projects'] ?? []),
            compareBy: $compareBy,
            splitBy: $splitBy,
            form: ResultForm::tryFrom((string) ($data['form'] ?? '')) ?? ResultForm::Points,
            curve: ($data['curve'] ?? null) === 'rolling' ? 'rolling' : 'cumulative',
        );

        return $question->supports($question->form) ? $question : $question->withForm(ResultForm::Points);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'people' => $this->people->toArray(),
            'aggregate' => $this->aggregate->value,
            'projects' => $this->projects->toArray(),
            'compare_by' => $this->compareBy?->key() ?? '',
            'split_by' => $this->splitBy?->key() ?? '',
            'form' => $this->form->value,
            'curve' => $this->curve,
        ];
    }

    /**
     * Points always work. A curve exists for shares only. Shares of a whole
     * need groups that add up to it: a share, compared by a persona tag, over
     * everyone, counted together.
     */
    public function supports(ResultForm $form): bool
    {
        return match ($form) {
            ResultForm::Points => true,
            ResultForm::Trajectory => $this->metric->isShare(),
            ResultForm::Composition => $this->metric->isShare()
                && $this->compareBy?->isPersonaLevel() === true
                && $this->people->isEveryone()
                && $this->aggregate === Aggregate::Together,
        };
    }

    public function withForm(ResultForm $form): self
    {
        return new self($this->metric, $this->people, $this->aggregate, $this->projects, $this->compareBy, $this->splitBy, $form, $this->curve);
    }
}
```

Hinweis zum Roundtrip-Test: `toArray()` liefert `compare_by`/`split_by` als Schlüssel bzw. `''`; der Test im Step 1 nutzt gesetzte Dimensionen und erwartet die Schlüssel zurück.

- [ ] **Step 4: Write the language files**

`lang/en/questions.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Statistics page: a question across many runs, answered in one fixed form
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistics',
    'subheading' => 'Ask a question across many runs. Every dot is one run.',
    'none_open' => 'Pick a question on the left, start from a template, or ask a new one.',

    'list' => [
        'questions' => 'My questions',
        'templates' => 'Templates',
        'create' => 'New question',
        'empty' => 'No question yet.',
        'no_templates' => 'No template yet. Save a question as a template to reuse it.',
    ],

    'actions' => [
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'save_as_template' => 'Save as template',
        'delete' => 'Delete',
    ],

    'name' => [
        'rename' => 'Rename',
        'field' => 'Name',
        'hint' => 'Leave empty to name it after its sentence.',
    ],

    'copy_name' => ':name (copy)',

    'confirm' => [
        'delete' => [
            'title' => 'Delete question?',
            'message' => '“:name” will be deleted. Projects and tags stay untouched.',
        ],
        'delete_template' => [
            'title' => 'Delete template?',
            'message' => 'The template “:name” will be deleted for everyone. Questions made from it stay.',
        ],
    ],

    'autosave' => [
        'saved' => 'Saved automatically',
        'saving' => 'Saving …',
    ],

    'sentence' => [
        'of' => 'of',
        'in' => 'in',
        'compared_by' => 'compared by',
        'split_by' => 'split by',
        'everyone' => 'everyone',
        'all_runs' => 'all runs',
        'nothing' => 'nothing',
        'turn_budget' => '{1}runs of 1 turn|[2,*]runs of :count turns',
        'name_compared_by' => 'by :dimension',
        'name_split_by' => 'per :dimension',
        'deleted' => 'deleted category',
    ],

    'controls' => [
        'metric' => 'Measure',
        'expert_name' => 'Expert by name',
        'any_expert' => 'Any expert',
        'aggregate' => 'Count the group',
        'pipelines' => 'Pipelines',
        'models' => 'Models',
        'turn_budget' => 'Turn budget',
        'any_budget' => 'Any budget',
        'compare_by' => 'Compare by',
        'split_by' => 'Split by',
        'none' => 'Nothing',
        'people_hint' => 'Several tags: every category must match, any value within one.',
        'gini_hint' => 'Gini describes the whole run, so it counts everyone.',
        'curve' => 'Curve',
    ],

    'aggregates' => [
        'together' => 'together',
        'per_head' => 'per head',
    ],

    'aggregate_hints' => [
        'together' => 'The group’s shares added up. All groups add up to 100 %.',
        'per_head' => 'The share of an average member, so groups of different size compare fairly.',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Model',
        'persona' => ':category (persona)',
    ],

    'metrics' => [
        'turn_share' => 'Turn share',
        'word_share' => 'Word share',
        'words_per_turn' => 'Words per turn',
        'turn_gini' => 'Gini (turns)',
        'word_gini' => 'Gini (words)',
    ],

    'forms' => [
        'points' => 'Runs',
        'composition' => 'Shares',
        'trajectory' => 'Over the run',
        'unsupported' => [
            'composition' => 'Only when the groups add up to everyone: a share, compared by a persona tag, everyone, counted together.',
            'trajectory' => 'Only for turn share and word share.',
        ],
    ],

    'curves' => [
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
    ],

    'result' => [
        'runs' => '{0}No run matches.|{1}1 run|[2,*]:count runs',
        'excluded' => '{1}1 run has no value here (no spoken turn, or nobody matches).|[2,*]:count runs have no value here (no spoken turn, or nobody matches).',
        'empty' => 'No run answers this question. Widen the runs or the people.',
        'all' => 'All',
        'no_value' => 'no value',
        'fair_share' => 'Dashed line: fair share, what the group would get if everyone spoke equally.',
        'mean' => 'Mean',
        'delta' => ':to minus :from: :delta',
        'too_short' => 'A curve needs at least two spoken turns.',
        'run_table' => '{1}The run behind these numbers|[2,*]The :count runs behind these numbers',
        'columns' => [
            'run' => 'Run',
            'panel' => 'Panel',
            'group' => 'Group',
            'value' => 'Value',
        ],
    ],
];
```

`lang/de/questions.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Statistik-Seite: eine Frage über viele Läufe, in fester Form beantwortet
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistik',
    'subheading' => 'Stelle eine Frage über viele Läufe. Jeder Punkt ist ein Lauf.',
    'none_open' => 'Wähle links eine Frage, starte mit einer Vorlage oder stelle eine neue.',

    'list' => [
        'questions' => 'Meine Fragen',
        'templates' => 'Vorlagen',
        'create' => 'Neue Frage',
        'empty' => 'Noch keine Frage.',
        'no_templates' => 'Noch keine Vorlage. Speichere eine Frage als Vorlage, um sie wiederzuverwenden.',
    ],

    'actions' => [
        'rename' => 'Umbenennen',
        'duplicate' => 'Duplizieren',
        'save_as_template' => 'Als Vorlage speichern',
        'delete' => 'Löschen',
    ],

    'name' => [
        'rename' => 'Umbenennen',
        'field' => 'Name',
        'hint' => 'Leer lassen, dann heißt sie wie ihr Satz.',
    ],

    'copy_name' => ':name (Kopie)',

    'confirm' => [
        'delete' => [
            'title' => 'Frage löschen?',
            'message' => '„:name“ wird gelöscht. Projekte und Tags bleiben unverändert.',
        ],
        'delete_template' => [
            'title' => 'Vorlage löschen?',
            'message' => 'Die Vorlage „:name“ wird für alle gelöscht. Fragen, die daraus entstanden sind, bleiben.',
        ],
    ],

    'autosave' => [
        'saved' => 'Automatisch gespeichert',
        'saving' => 'Speichert …',
    ],

    'sentence' => [
        'of' => 'von',
        'in' => 'in',
        'compared_by' => 'verglichen nach',
        'split_by' => 'aufgeteilt nach',
        'everyone' => 'allen',
        'all_runs' => 'allen Läufen',
        'nothing' => 'nichts',
        'turn_budget' => '{1}Läufen mit 1 Turn|[2,*]Läufen mit :count Turns',
        'name_compared_by' => 'nach :dimension',
        'name_split_by' => 'je :dimension',
        'deleted' => 'gelöschte Kategorie',
    ],

    'controls' => [
        'metric' => 'Messgröße',
        'expert_name' => 'Experte nach Name',
        'any_expert' => 'Jeder Experte',
        'aggregate' => 'Gruppe zählen',
        'pipelines' => 'Pipelines',
        'models' => 'Modelle',
        'turn_budget' => 'Turn-Budget',
        'any_budget' => 'Jedes Budget',
        'compare_by' => 'Verglichen nach',
        'split_by' => 'Aufgeteilt nach',
        'none' => 'Nichts',
        'people_hint' => 'Mehrere Tags: jede Kategorie muss passen, innerhalb einer genügt ein Wert.',
        'gini_hint' => 'Gini beschreibt den ganzen Lauf und zählt daher alle.',
        'curve' => 'Kurve',
    ],

    'aggregates' => [
        'together' => 'zusammen',
        'per_head' => 'pro Kopf',
    ],

    'aggregate_hints' => [
        'together' => 'Die Anteile der Gruppe addiert. Alle Gruppen ergeben zusammen 100 %.',
        'per_head' => 'Der Anteil eines durchschnittlichen Mitglieds, damit verschieden große Gruppen fair vergleichbar sind.',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'persona' => ':category (Persona)',
    ],

    'metrics' => [
        'turn_share' => 'Turn-Anteil',
        'word_share' => 'Wortanteil',
        'words_per_turn' => 'Wörter pro Turn',
        'turn_gini' => 'Gini (Turns)',
        'word_gini' => 'Gini (Wörter)',
    ],

    'forms' => [
        'points' => 'Läufe',
        'composition' => 'Anteile',
        'trajectory' => 'Über den Lauf',
        'unsupported' => [
            'composition' => 'Nur wenn die Gruppen zusammen alle ergeben: ein Anteil, verglichen nach einem Persona-Tag, alle Personen, zusammen gezählt.',
            'trajectory' => 'Nur für Turn-Anteil und Wortanteil.',
        ],
    ],

    'curves' => [
        'cumulative' => 'Kumuliert',
        'rolling' => 'Gleitend',
    ],

    'result' => [
        'runs' => '{0}Kein Lauf passt.|{1}1 Lauf|[2,*]:count Läufe',
        'excluded' => '{1}1 Lauf hat hier keinen Wert (kein gesprochener Turn oder niemand passt).|[2,*]:count Läufe haben hier keinen Wert (kein gesprochener Turn oder niemand passt).',
        'empty' => 'Kein Lauf beantwortet diese Frage. Erweitere die Läufe oder die Personen.',
        'all' => 'Alle',
        'no_value' => 'ohne Angabe',
        'fair_share' => 'Gestrichelt: faire Erwartung, also was die Gruppe bekäme, wenn alle gleich viel sprächen.',
        'mean' => 'Mittelwert',
        'delta' => ':to minus :from: :delta',
        'too_short' => 'Eine Kurve braucht mindestens zwei gesprochene Turns.',
        'run_table' => '{1}Der Lauf hinter diesen Zahlen|[2,*]Die :count Läufe hinter diesen Zahlen',
        'columns' => [
            'run' => 'Lauf',
            'panel' => 'Panel',
            'group' => 'Gruppe',
            'value' => 'Wert',
        ],
    ],
];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=QuestionTest`
Expected: PASS (9 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Discussion/Values tests/Unit/Discussion/QuestionTest.php lang
git add app/Discussion/Values/{Aggregate,ResultForm,DimensionType,Dimension,ProjectFilter,PeopleFilter,Question}.php lang/en/questions.php lang/de/questions.php tests/Unit/Discussion/QuestionTest.php
git commit -m "Describe a statistics question as one normalised value"
```

---

### Task 2: Metrik für Gruppen, faire Erwartung, Differenz

**Files:**
- Modify: `app/Discussion/Values/Metric.php`
- Test: `tests/Unit/Discussion/MetricTest.php` (neu)

**Interfaces:**
- Consumes: `Aggregate` (Task 1)
- Produces:
  - `Metric::ofGroup(list<SpeakerShare> $speakers, Aggregate $aggregate): float`
  - `Metric::fairShare(int $groupSize, int $runSize, Aggregate $aggregate): ?float`
  - `Metric::formatDelta(float $delta): string` („+31,7 pp“ je Locale)
  - `Metric::label()` liest `questions.metrics.*`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Discussion/MetricTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SpeakerShare;
use Tests\TestCase;

class MetricTest extends TestCase
{
    private function speaker(int $turns, int $words, float $turnShare, float $wordShare): SpeakerShare
    {
        return new SpeakerShare(expertId: random_int(1, 1_000_000), name: 'X', seat: 1, turns: $turns, words: $words, turnShare: $turnShare, wordShare: $wordShare);
    }

    public function test_a_group_counts_together_or_per_head(): void
    {
        $group = [$this->speaker(1, 10, 0.25, 0.1), $this->speaker(2, 30, 0.5, 0.3)];

        $this->assertSame(0.75, Metric::TurnShare->ofGroup($group, Aggregate::Together));
        $this->assertSame(0.375, Metric::TurnShare->ofGroup($group, Aggregate::PerHead));
        $this->assertSame(0.2, Metric::WordShare->ofGroup($group, Aggregate::PerHead));
    }

    public function test_words_per_turn_ignores_the_aggregate(): void
    {
        $group = [$this->speaker(1, 10, 0.25, 0.1), $this->speaker(3, 50, 0.5, 0.3)];

        $this->assertSame(15.0, Metric::WordsPerTurn->ofGroup($group, Aggregate::PerHead));
    }

    public function test_the_fair_share_of_a_group(): void
    {
        $this->assertSame(0.75, Metric::TurnShare->fairShare(3, 4, Aggregate::Together));
        $this->assertSame(0.25, Metric::WordShare->fairShare(3, 4, Aggregate::PerHead));
        $this->assertNull(Metric::WordsPerTurn->fairShare(3, 4, Aggregate::Together));
        $this->assertNull(Metric::TurnGini->fairShare(4, 4, Aggregate::Together));
    }

    public function test_a_delta_is_signed_and_in_points_for_shares(): void
    {
        app()->setLocale('en');

        $this->assertSame('+31.7 pp', Metric::TurnShare->formatDelta(0.317));
        $this->assertSame('−5.0 pp', Metric::WordShare->formatDelta(-0.05));
        $this->assertSame('±0.0 pp', Metric::TurnShare->formatDelta(0.0));
        $this->assertSame('+0.12', Metric::TurnGini->formatDelta(0.12));
    }

    public function test_labels_come_from_the_question_texts(): void
    {
        app()->setLocale('en');

        $this->assertSame('Turn share', Metric::TurnShare->label());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MetricTest`
Expected: FAIL, `Call to undefined method … ofGroup()`.

- [ ] **Step 3: Implement in `app/Discussion/Values/Metric.php`**

`label()` ändern:

```php
    public function label(): string
    {
        return __("questions.metrics.{$this->value}");
    }
```

Nach `ofRun()` einfügen:

```php
    /**
     * The value of one group of a run. Per head divides a share by the group's
     * size, the share of its average member; words per turn is a ratio
     * already and ignores the aggregate.
     *
     * @param  non-empty-list<SpeakerShare>  $speakers
     */
    public function ofGroup(array $speakers, Aggregate $aggregate): float
    {
        $value = $this->ofAgents($speakers);

        return $aggregate === Aggregate::PerHead && $this->isShare() ? $value / count($speakers) : $value;
    }

    /**
     * What the group would get if every agent of the run took the floor (or
     * wrote) equally: its size over the run's size, or 1/n per head. The
     * reference of the redezeit-fairness studies (share against presence).
     * Only shares have one.
     */
    public function fairShare(int $groupSize, int $runSize, Aggregate $aggregate): ?float
    {
        if (! $this->isShare() || $runSize === 0) {
            return null;
        }

        return $aggregate === Aggregate::PerHead ? 1 / $runSize : $groupSize / $runSize;
    }
```

Nach `formatRange()` einfügen:

```php
    /** A difference of two values, always signed; percentage points for a share. */
    public function formatDelta(float $delta): string
    {
        $sign = match (true) {
            $delta > 0 => '+',
            $delta < 0 => '−',
            default => '±',
        };

        return $sign.match (true) {
            $this->isShare() => LocaleNumber::decimal(abs($delta) * 100, 1).' pp',
            $this === self::WordsPerTurn => LocaleNumber::decimal(abs($delta), 1),
            default => LocaleNumber::decimal(abs($delta), 2),
        };
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=MetricTest` → PASS. Dann `php artisan test`: außerhalb der unter „Global Constraints“ gelisteten Alt-Tests muss alles grün sein.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Discussion/Values/Metric.php tests/Unit/Discussion/MetricTest.php
git add app/Discussion/Values/Metric.php tests/Unit/Discussion/MetricTest.php
git commit -m "Give a metric a group value, a fair share and a signed difference"
```

---

### Task 3: Schema und Modell

**Files:**
- Create: `database/migrations/2026_10_12_000001_turn_comparisons_into_questions.php`
- Modify: `app/Models/Comparison.php`
- Test: `tests/Feature/Statistics/ComparisonModelTest.php`

**Interfaces:**
- Consumes: `Question` (Task 1)
- Produces: `Comparison` mit `fillable ['user_id','name','question','is_template']`, Casts `question: array`, `is_template: boolean`; `toQuestion(): Question`; `scopeTemplates(Builder)`; `scopeQuestions(Builder)`. `name` ist nullable.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Statistics/ComparisonModelTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Metric;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_comparison_holds_one_question_and_may_have_no_name(): void
    {
        $comparison = User::factory()->create()->comparisons()->create(['question' => ['metric' => 'word_share']]);

        $this->assertNull($comparison->fresh()->name);
        $this->assertSame(Metric::WordShare, $comparison->fresh()->toQuestion()->metric);
        $this->assertFalse($comparison->fresh()->is_template);
    }

    public function test_a_missing_question_reads_as_the_default_one(): void
    {
        $comparison = User::factory()->create()->comparisons()->create([]);

        $this->assertSame(Metric::TurnShare, $comparison->fresh()->toQuestion()->metric);
    }

    public function test_templates_and_questions_are_kept_apart(): void
    {
        $user = User::factory()->create();
        $user->comparisons()->create(['name' => 'B', 'is_template' => true]);
        $user->comparisons()->create(['name' => 'A', 'is_template' => true]);
        $user->comparisons()->create(['name' => 'Q']);

        $this->assertSame(['A', 'B'], Comparison::templates()->pluck('name')->all());
        $this->assertSame(['Q'], Comparison::questions()->pluck('name')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ComparisonModelTest`
Expected: FAIL (Spalte `question` fehlt).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_12_000001_turn_comparisons_into_questions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A comparison becomes one question (Question::toArray()) instead of a
     * list of series and a view. The old rows cannot be translated into a
     * question and are dropped (spec 2026-10-12). A nameless question is
     * called by its sentence; a template is a question everyone may copy.
     */
    public function up(): void
    {
        DB::table('comparisons')->delete();

        Schema::table('comparisons', function (Blueprint $table) {
            $table->dropColumn(['series', 'view']);
        });

        Schema::table('comparisons', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
            $table->json('question')->nullable();
            $table->boolean('is_template')->default(false)->index();
        });
    }

    public function down(): void
    {
        DB::table('comparisons')->delete();

        Schema::table('comparisons', function (Blueprint $table) {
            $table->dropIndex(['is_template']);
            $table->dropColumn(['question', 'is_template']);
        });

        Schema::table('comparisons', function (Blueprint $table) {
            $table->string('name', 100)->nullable(false)->change();
            $table->json('series')->nullable();
            $table->json('view')->nullable();
        });
    }
};
```

- [ ] **Step 4: Rewrite the model**

`app/Models/Comparison.php`:

```php
<?php

namespace App\Models;

use App\Discussion\Values\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved question of the statistics page. A template is the same row with
 * is_template set: every signed-in user may copy it, only its owner renames
 * or deletes it, and nobody edits it in place.
 */
class Comparison extends Model
{
    protected $fillable = ['user_id', 'name', 'question', 'is_template'];

    protected $casts = [
        'question' => 'array',
        'is_template' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toQuestion(): Question
    {
        return Question::fromArray($this->question ?? []);
    }

    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true)->orderBy('name')->orderBy('id');
    }

    /** Most recently changed first: the one being worked on stays on top. */
    public function scopeQuestions(Builder $query): void
    {
        $query->where('is_template', false)->latest('updated_at')->orderByDesc('id');
    }
}
```

In `app/Models/User.php` die Relation ohne Sortierung lassen (Sortierung kommt aus den Scopes):

```php
    public function comparisons(): HasMany
    {
        return $this->hasMany(Comparison::class);
    }
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=ComparisonModelTest` → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Models database/migrations tests/Feature/Statistics/ComparisonModelTest.php
git add app/Models/Comparison.php app/Models/User.php database/migrations/2026_10_12_000001_turn_comparisons_into_questions.php tests/Feature/Statistics/ComparisonModelTest.php
git commit -m "Store one question per comparison and mark templates"
```

Die alte Seite ist ab hier kaputt (Spalten `series`/`view` fehlen); Tasks 4 bis 9 stellen sie wieder her. Die alten Statistik-Tests bis Task 9 nicht reparieren.

---

### Task 4: Läufe und Dimensionswerte

**Files:**
- Create: `app/Discussion/Metrics/Questions/QuestionRuns.php`, `app/Discussion/Metrics/Questions/DimensionValues.php`
- Test: `tests/Unit/Discussion/DimensionValuesTest.php`, `tests/Unit/Discussion/QuestionRunsTest.php`

**Interfaces:**
- Consumes: `ProjectFilter`, `Dimension`, `DimensionType` (Task 1)
- Produces:
  - `QuestionRuns::for(User $user, ProjectFilter $filter): Collection<int, Project>` (nach id sortiert)
  - `DimensionValues::NONE = ''`
  - `DimensionValues::labels(Dimension): array<int|string, string>` in Anzeigereihenfolge (Tag-Position; Registry-Reihenfolge; Persona-Tags zusätzlich `NONE => questions.result.no_value`)
  - `DimensionValues::ofProject(Dimension, Project): int|string|null`
  - `DimensionValues::ofExperts(Dimension, list<int> $expertIds): array<int, int|string>` (fehlender Wert = `NONE`)

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Discussion/QuestionRunsTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\TagFilter;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_users_runs_narrowed_by_every_part_of_the_filter(): void
    {
        $user = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        $match = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $match->setTag($condition, $status);
        $otherBudget = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 100]);
        $otherBudget->setTag($condition, $status);
        Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $foreign = Project::factory()->create(['pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $foreign->setTag($condition, $status);

        $runs = app(QuestionRuns::class)->for($user, new ProjectFilter(
            tags: new TagFilter([$condition->id => [$status->id]]),
            pipelines: ['ScorePipeline', 'QualityPipeline'],
            models: ['openai-gpt-5'],
            turnBudget: 60,
        ));

        $this->assertSame([$match->id], $runs->pluck('id')->all());
    }

    public function test_an_empty_filter_reads_every_run_the_user_may_read(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(2)->create(['user_id' => $user->id]);
        Project::factory()->create();

        $this->assertCount(2, app(QuestionRuns::class)->for($user, new ProjectFilter));
    }
}
```

`tests/Unit/Discussion/DimensionValuesTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DimensionValuesTest extends TestCase
{
    use RefreshDatabase;

    private function values(): DimensionValues
    {
        return app(DimensionValues::class);
    }

    public function test_tag_labels_follow_the_value_order_and_persona_tags_add_no_value(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gender']);
        $f = $category->values()->create(['name' => 'f']);
        $m = $category->values()->create(['name' => 'm']);
        $m->update(['position' => 0]);
        $f->update(['position' => 1]);

        $labels = $this->values()->labels(new Dimension(DimensionType::PersonaTag, $category->id));

        $this->assertSame([$m->id, $f->id, DimensionValues::NONE], array_keys($labels));
        $this->assertSame(__('questions.result.no_value'), $labels[DimensionValues::NONE]);
    }

    public function test_pipeline_labels_come_from_the_registry(): void
    {
        $this->assertSame(
            app(PipelineRegistry::class)->options(),
            $this->values()->labels(new Dimension(DimensionType::Pipeline)),
        );
    }

    public function test_a_run_has_its_tag_pipeline_and_model(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $category->values()->create(['name' => 'status']);
        $project = Project::factory()->create(['pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5']);
        $untagged = Project::factory()->create();
        $project->setTag($category, $status);
        $byTag = new Dimension(DimensionType::ProjectTag, $category->id);

        $this->assertSame($status->id, $this->values()->ofProject($byTag, $project));
        $this->assertNull($this->values()->ofProject($byTag, $untagged));
        $this->assertSame('ScorePipeline', $this->values()->ofProject(new Dimension(DimensionType::Pipeline), $project));
        $this->assertSame('openai-gpt-5', $this->values()->ofProject(new Dimension(DimensionType::Model), $project));
    }

    public function test_agents_without_a_value_have_none(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $true = $category->values()->create(['name' => 'true']);
        [$alex, $kim] = Expert::factory()->count(2)->create();
        $alex->setTag($category, $true);

        $this->assertSame(
            [$alex->id => $true->id, $kim->id => DimensionValues::NONE],
            $this->values()->ofExperts(new Dimension(DimensionType::PersonaTag, $category->id), [$alex->id, $kim->id]),
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='QuestionRunsTest|DimensionValuesTest'`
Expected: FAIL, Klassen fehlen.

- [ ] **Step 3: Implement**

`app/Discussion/Metrics/Questions/QuestionRuns.php`:

```php
<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\ProjectFilter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** The runs a question reads: what the user may access, narrowed by the question's project filter. */
final class QuestionRuns
{
    /** @return Collection<int, Project> */
    public function for(User $user, ProjectFilter $filter): Collection
    {
        return Project::query()
            ->accessibleBy($user)
            ->whereTagFilter($filter->tags)
            ->when($filter->pipelines !== [], fn ($query) => $query->whereIn('pipeline', $filter->pipelines))
            ->when($filter->models !== [], fn ($query) => $query->whereIn('model', $filter->models))
            ->when($filter->turnBudget !== null, fn ($query) => $query->where('turn_budget', $filter->turnBudget))
            ->orderBy('id')
            ->get();
    }
}
```

`app/Discussion/Metrics/Questions/DimensionValues.php`:

```php
<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Taggable;
use App\Models\TagValue;
use LogicException;

/**
 * The values of a dimension: their labels in display order, and the value a
 * run or an agent has. Keys are tag value ids, pipeline names or model keys.
 * An agent without a value in a persona category gets NONE, so the groups of
 * a run still add up to everyone.
 */
final class DimensionValues
{
    public const NONE = '';

    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /** @return array<int|string, string> key => label */
    public function labels(Dimension $dimension): array
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $this->pipelines->options(),
            DimensionType::Model => ModelConfig::options(),
            DimensionType::ProjectTag => $this->tagLabels($dimension),
            DimensionType::PersonaTag => $this->tagLabels($dimension) + [self::NONE => __('questions.result.no_value')],
        };
    }

    /** Null when the run has no value in a project tag category. */
    public function ofProject(Dimension $dimension, Project $project): int|string|null
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $project->pipeline,
            DimensionType::Model => $project->model,
            DimensionType::ProjectTag => $this->tagValuesOf($dimension, $project->getMorphClass(), [$project->id])[$project->id] ?? null,
            DimensionType::PersonaTag => throw new LogicException('A persona tag describes agents, not runs.'),
        };
    }

    /**
     * @param  list<int>  $expertIds
     * @return array<int, int|string> expert id => value id, or NONE
     */
    public function ofExperts(Dimension $dimension, array $expertIds): array
    {
        if (! $dimension->isPersonaLevel()) {
            throw new LogicException('Only a persona tag describes agents.');
        }

        $values = $this->tagValuesOf($dimension, (new Expert)->getMorphClass(), $expertIds);

        return array_combine($expertIds, array_map(fn (int $id) => $values[$id] ?? self::NONE, $expertIds));
    }

    /** @return array<int, string> */
    private function tagLabels(Dimension $dimension): array
    {
        return TagValue::where('tag_category_id', $dimension->categoryId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> model id => value id
     */
    private function tagValuesOf(Dimension $dimension, string $morphClass, array $ids): array
    {
        return Taggable::where('taggable_type', $morphClass)
            ->whereIn('taggable_id', $ids)
            ->where('tag_category_id', $dimension->categoryId)
            ->pluck('tag_value_id', 'taggable_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter='QuestionRunsTest|DimensionValuesTest'` → PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Discussion/Metrics/Questions tests/Unit/Discussion/QuestionRunsTest.php tests/Unit/Discussion/DimensionValuesTest.php
git add app/Discussion/Metrics/Questions tests/Unit/Discussion/QuestionRunsTest.php tests/Unit/Discussion/DimensionValuesTest.php
git commit -m "Find a question's runs and the values of its dimensions"
```

---

### Task 5: Die Antwort berechnen

**Files:**
- Create: `app/Discussion/Values/RunValue.php`, `GroupResult.php`, `Panel.php`, `QuestionResult.php`
- Create: `app/Discussion/Metrics/Questions/GroupCollector.php`, `app/Discussion/Metrics/Questions/QuestionEvaluator.php`
- Test: `tests/Unit/Discussion/QuestionEvaluatorTest.php`

**Interfaces:**
- Consumes: `Question`, `Aggregate`, `ResultForm`, `Dimension` (Task 1); `Metric::ofGroup/fairShare` (Task 2); `QuestionRuns`, `DimensionValues` (Task 4); `TrajectoryMean` (bestehend)
- Produces:
  - `RunValue(int $projectId, string $title, float $value, ?float $fairShare)`
  - `GroupResult(string $key, string $label, list<RunValue> $runs, list<TrajectoryMean> $curve = [])`; `n()`, `mean()`, `min()`, `max()`, `fairShare(): ?float`
  - `Panel(string $key, string $label, list<GroupResult> $groups)`; `delta(): ?float` (zweite − erste, nur bei genau zwei)
  - `QuestionResult(list<Panel> $panels, array<string,string> $groups, int $runs, int $excluded)`; `isEmpty()`, `maxValue()`, `groupIndex(string $key): int`
  - `QuestionEvaluator::evaluate(User $user, Question $question): QuestionResult`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Discussion/QuestionEvaluatorTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QuestionEvaluator on synthetic runs whose answer can be worked out by hand.
 * It adds nothing to the counting rules; what it adds — panels, groups, per
 * head, fair share, dropped runs, curves — is what these cases pin down.
 */
class QuestionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, Expert> */
    private array $experts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  list<string>  $seats  names in seat order
     * @param  list<array{0: string, 1: int}>  $turns  [name, words] in turn order
     * @param  array<string, mixed>  $attributes
     */
    private function makeRun(array $seats, array $turns, array $attributes = []): Project
    {
        $project = Project::factory()->create(['user_id' => $this->user->id, ...$attributes]);

        foreach ($seats as $name) {
            $project->addContributingExpert($this->experts[$name] ??= Expert::factory()->create(['name' => $name]));
        }

        foreach ($turns as $index => [$name, $words]) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner',
                'project_id' => $project->id,
                'status' => 'success',
                'started_at' => now(),
                'turn_index' => $index + 1,
                'expert_id' => $this->experts[$name]->id,
                'words' => $words,
            ]);
        }

        return $project->fresh();
    }

    /** @param  array<string, mixed>  $question */
    private function answer(array $question): QuestionResult
    {
        return app(QuestionEvaluator::class)->evaluate($this->user, Question::fromArray($question));
    }

    public function test_everyone_together_is_the_whole_run(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['B', 10]]);

        $group = $this->answer([])->panels[0]->groups[0];

        $this->assertSame(1.0, $group->mean());
        $this->assertSame(1.0, $group->fairShare());
        $this->assertSame(1, $group->n());
    }

    public function test_a_persona_tag_compares_agents_of_the_same_run(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $titled = $category->values()->create(['name' => 'titel']);
        $rest = $category->values()->create(['name' => 'rest']);
        $this->makeRun(['A', 'B', 'C', 'D'], [['A', 10], ['A', 10], ['B', 10], ['C', 10]]);
        $this->experts['A']->setTag($category, $titled);

        foreach (['B', 'C', 'D'] as $name) {
            $this->experts[$name]->setTag($category, $rest);
        }

        $together = $this->answer(['compare_by' => "persona_tag:{$category->id}"])->panels[0];
        $perHead = $this->answer(['compare_by' => "persona_tag:{$category->id}", 'aggregate' => 'per_head'])->panels[0];

        $this->assertSame(['titel', 'rest'], array_map(fn ($g) => $g->label, $together->groups));
        $this->assertSame([0.5, 0.5], array_map(fn ($g) => $g->mean(), $together->groups));
        $this->assertSame([0.25, 0.75], array_map(fn ($g) => $g->fairShare(), $together->groups));
        $this->assertEqualsWithDelta(0.5 / 3, $perHead->groups[1]->mean(), 1e-9);
        $this->assertSame(0.25, $perHead->groups[1]->fairShare());
    }

    public function test_agents_without_a_value_form_their_own_group(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gender']);
        $f = $category->values()->create(['name' => 'f']);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['B', 10]]);
        $this->experts['A']->setTag($category, $f);

        $groups = $this->answer(['compare_by' => "persona_tag:{$category->id}"])->panels[0]->groups;

        $this->assertSame([(string) $f->id, DimensionValues::NONE], array_map(fn ($g) => $g->key, $groups));
        $this->assertEqualsWithDelta(1.0, $groups[0]->mean() + $groups[1]->mean(), 1e-9);
    }

    public function test_a_project_tag_compares_runs_and_gives_a_delta(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $without = $category->values()->create(['name' => 'ohne']);
        $with = $category->values()->create(['name' => 'mit']);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10]])->setTag($category, $without);
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['A', 10], ['B', 10]])->setTag($category, $with);

        $panel = $this->answer(['people' => ['expert_name' => 'A'], 'compare_by' => "project_tag:{$category->id}"])->panels[0];

        $this->assertSame(['ohne', 'mit'], array_map(fn ($g) => $g->label, $panel->groups));
        $this->assertSame(0.25, $panel->delta());
    }

    public function test_split_by_pipeline_gives_panels_in_registry_order(): void
    {
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'ScorePipeline']);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'RoundRobinPipeline']);

        $keys = array_map(fn ($panel) => $panel->key, $this->answer(['split_by' => 'pipeline'])->panels);
        $expected = array_values(array_intersect(array_keys(app(PipelineRegistry::class)->options()), ['ScorePipeline', 'RoundRobinPipeline']));

        $this->assertSame($expected, $keys);
    }

    public function test_runs_without_an_answer_drop_out_and_are_counted(): void
    {
        $this->makeRun(['A', 'B'], []);
        $this->makeRun(['A', 'B'], [['A', 10]]);

        $result = $this->answer(['people' => ['expert_name' => 'Nobody']]);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(2, $result->runs);
        $this->assertSame(2, $result->excluded);
    }

    public function test_gini_describes_the_whole_run(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10]]);

        $group = $this->answer(['metric' => 'turn_gini', 'people' => ['expert_name' => 'B']])->panels[0]->groups[0];

        $this->assertSame(0.5, $group->mean());
        $this->assertNull($group->fairShare());
    }

    public function test_the_curve_is_the_mean_over_the_runs_that_reached_each_turn(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10]]);
        $this->makeRun(['A', 'B'], [['B', 10], ['B', 10], ['A', 10]]);

        $curve = $this->answer(['people' => ['expert_name' => 'A'], 'form' => 'trajectory'])->panels[0]->groups[0]->curve;

        $this->assertSame([1, 2, 3], array_map(fn ($point) => $point->turn, $curve));
        // Turn 1: A has 1 in the first run, 0 in the second. Turn 2: 1/2 and 0. Turn 3: only the second run, 1/3.
        $this->assertSame([0.5, 0.25, 1 / 3], array_map(fn ($point) => $point->mean, $curve));
        $this->assertSame([2, 2, 1], array_map(fn ($point) => $point->n, $curve));
    }

    public function test_another_users_runs_are_never_read(): void
    {
        Project::factory()->create();

        $this->assertSame(0, $this->answer([])->runs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuestionEvaluatorTest`
Expected: FAIL, Klassen fehlen.

- [ ] **Step 3: Write the result values**

`app/Discussion/Values/RunValue.php`:

```php
<?php

namespace App\Discussion\Values;

/** One run's value for one group: a dot in the answer, a row in the run table. */
final readonly class RunValue
{
    public function __construct(
        public int $projectId,
        public string $title,
        public float $value,
        /** What the group would get if everyone spoke equally; null for a measure without one. */
        public ?float $fairShare,
    ) {}
}
```

`app/Discussion/Values/GroupResult.php`:

```php
<?php

namespace App\Discussion\Values;

/** One group of one panel: its value in every run that has it, and its mean curve. */
final readonly class GroupResult
{
    /**
     * @param  non-empty-list<RunValue>  $runs
     * @param  list<TrajectoryMean>  $curve  empty unless the answer is a curve
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $runs,
        public array $curve = [],
    ) {}

    public function n(): int
    {
        return count($this->runs);
    }

    public function mean(): float
    {
        return array_sum($this->values()) / $this->n();
    }

    public function min(): float
    {
        return min($this->values());
    }

    public function max(): float
    {
        return max($this->values());
    }

    /** The mean fair share over the runs; null for a measure without one. */
    public function fairShare(): ?float
    {
        $shares = array_map(fn (RunValue $run) => $run->fairShare, $this->runs);

        return in_array(null, $shares, true) ? null : array_sum($shares) / $this->n();
    }

    /** @return list<float> */
    private function values(): array
    {
        return array_map(fn (RunValue $run) => $run->value, $this->runs);
    }
}
```

`app/Discussion/Values/Panel.php`:

```php
<?php

namespace App\Discussion\Values;

/** One panel of an answer: a value of the split dimension, or the whole answer when nothing is split. */
final readonly class Panel
{
    /** @param  list<GroupResult>  $groups  in the order of the compare dimension */
    public function __construct(
        public string $key,
        public string $label,
        public array $groups,
    ) {}

    /** The second group's mean minus the first's, when exactly two groups compare. */
    public function delta(): ?float
    {
        return count($this->groups) === 2 ? $this->groups[1]->mean() - $this->groups[0]->mean() : null;
    }
}
```

`app/Discussion/Values/QuestionResult.php`:

```php
<?php

namespace App\Discussion\Values;

/** The answer to a question: its panels, the groups they compare, and how many runs it read. */
final readonly class QuestionResult
{
    /**
     * @param  list<Panel>  $panels
     * @param  array<string, string>  $groups  every group that occurs, key => label, in dimension order
     * @param  int  $runs  runs the project filter matched
     * @param  int  $excluded  of those, runs without a value
     */
    public function __construct(
        public array $panels,
        public array $groups,
        public int $runs,
        public int $excluded,
    ) {}

    public function isEmpty(): bool
    {
        return $this->panels === [];
    }

    /** The largest value of any run, so all panels of a non-share measure share one scale. */
    public function maxValue(): float
    {
        $max = 0.0;

        foreach ($this->panels as $panel) {
            foreach ($panel->groups as $group) {
                $max = max($max, $group->max());
            }
        }

        return $max;
    }

    /** A group's position among all groups: the same group keeps its colour in every panel. */
    public function groupIndex(string $key): int
    {
        return (int) array_search($key, array_map('strval', array_keys($this->groups)), true);
    }
}
```

- [ ] **Step 4: Write the collector and the evaluator**

`app/Discussion/Metrics/Questions/GroupCollector.php`:

```php
<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\TrajectoryMean;

/** Gathers one group's runs while QuestionEvaluator walks them; mutable, and never leaves it. */
final class GroupCollector
{
    /** @var list<RunValue> */
    private array $runs = [];

    /** @var array<int, float> turn => summed share */
    private array $sums = [];

    /** @var array<int, int> turn => runs that reached it */
    private array $counts = [];

    /** @param  array<int, float>  $curve  turn => the group's share after it; empty without a curve */
    public function add(RunValue $run, array $curve): void
    {
        $this->runs[] = $run;

        foreach ($curve as $turn => $share) {
            $this->sums[$turn] = ($this->sums[$turn] ?? 0.0) + $share;
            $this->counts[$turn] = ($this->counts[$turn] ?? 0) + 1;
        }
    }

    public function result(string $key, string $label): GroupResult
    {
        ksort($this->sums);
        $curve = [];

        foreach ($this->sums as $turn => $sum) {
            $curve[] = new TrajectoryMean($turn, $sum / $this->counts[$turn], $this->counts[$turn]);
        }

        return new GroupResult($key, $label, $this->runs, $curve);
    }
}
```

`app/Discussion/Metrics/Questions/QuestionEvaluator.php`:

```php
<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\Metric;
use App\Discussion\Values\Panel;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;

/**
 * Answers a question over the runs a user may read. Every number comes from
 * SpeakingShares and ShareTrajectory, so the counting rules stay in
 * SpokenTurns; this class only sorts runs into panels and agents into groups,
 * and averages.
 *
 * - A run without a spoken turn, without a value in a run dimension, or
 *   without anyone the people filter accepts, drops out and is counted.
 * - Panels and groups come in their dimension's order; one no run reached is
 *   left out.
 * - A curve point is the mean over the runs that reached that turn.
 */
final class QuestionEvaluator
{
    public function __construct(
        private readonly QuestionRuns $runs,
        private readonly DimensionValues $dimensions,
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
    ) {}

    public function evaluate(User $user, Question $question): QuestionResult
    {
        /** @var array<int|string, array<int|string, GroupCollector>> $collected panel => group => collector */
        $collected = [];
        $runs = $excluded = 0;

        foreach ($this->runs->for($user, $question->projects) as $project) {
            $runs++;
            $panel = $question->splitBy === null ? '' : $this->dimensions->ofProject($question->splitBy, $project);
            $report = $this->shares->forProject($project);
            $groups = $panel === null || $report->isEmpty() ? [] : $this->groupsOf($question, $project, $report);

            if ($groups === []) {
                $excluded++;

                continue;
            }

            $trajectory = $question->form === ResultForm::Trajectory ? $this->trajectories->forProject($project) : null;

            foreach ($groups as $group => $speakers) {
                ($collected[$panel][$group] ??= new GroupCollector)->add(
                    $this->runValue($question, $project, $report, $speakers),
                    $trajectory === null ? [] : $this->curveOf($question, $trajectory, $speakers),
                );
            }
        }

        $groupLabels = $this->labels($question->compareBy, array_merge([], ...array_map('array_keys', array_values($collected))));

        return new QuestionResult(
            $this->panels($question, $collected, $groupLabels),
            array_combine(array_map('strval', array_keys($groupLabels)), $groupLabels),
            $runs,
            $excluded,
        );
    }

    /** @return array<int|string, non-empty-list<SpeakerShare>> group key => the group's agents in this run */
    private function groupsOf(Question $question, Project $project, ShareReport $report): array
    {
        $people = $question->metric->isRunLevel() ? $report->speakers : $this->people($question->people, $report->speakers);
        $compareBy = $question->compareBy;

        if ($compareBy === null) {
            $groups = ['' => $people];
        } elseif ($compareBy->isPersonaLevel()) {
            $keys = $this->dimensions->ofExperts($compareBy, array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $people));
            $groups = [];

            foreach ($people as $speaker) {
                $groups[$keys[$speaker->expertId]][] = $speaker;
            }
        } else {
            $key = $this->dimensions->ofProject($compareBy, $project);
            $groups = $key === null ? [] : [$key => $people];
        }

        return array_filter($groups, fn (array $speakers) => $this->isMeasurable($question->metric, $speakers));
    }

    /**
     * @param  list<SpeakerShare>  $speakers
     * @return list<SpeakerShare>
     */
    private function people(PeopleFilter $filter, array $speakers): array
    {
        if ($filter->isEveryone()) {
            return $speakers;
        }

        $ids = Expert::whereKey(array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $speakers))
            ->whereTagFilter($filter->tags)
            ->when($filter->expertName !== null, fn ($query) => $query->where('name', $filter->expertName))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($speakers, fn (SpeakerShare $speaker) => in_array($speaker->expertId, $ids, true)));
    }

    /** No agents, or words per turn of agents who never spoke: no value, not a zero. */
    private function isMeasurable(Metric $metric, array $speakers): bool
    {
        return $speakers !== []
            && ($metric !== Metric::WordsPerTurn || array_sum(array_map(fn (SpeakerShare $speaker) => $speaker->turns, $speakers)) > 0);
    }

    /** @param  non-empty-list<SpeakerShare>  $speakers */
    private function runValue(Question $question, Project $project, ShareReport $report, array $speakers): RunValue
    {
        $metric = $question->metric;

        return new RunValue(
            projectId: $project->id,
            title: $project->title,
            value: $metric->isRunLevel() ? $metric->ofRun($report) : $metric->ofGroup($speakers, $question->aggregate),
            fairShare: $metric->fairShare(count($speakers), count($report->speakers), $question->aggregate),
        );
    }

    /**
     * @param  non-empty-list<SpeakerShare>  $speakers
     * @return array<int, float> turn => the group's share after it
     */
    private function curveOf(Question $question, Trajectory $trajectory, array $speakers): array
    {
        $series = $question->metric->series($question->curve);
        $divisor = $question->aggregate === Aggregate::PerHead ? count($speakers) : 1;
        $curve = [];

        foreach ($trajectory->points as $point) {
            $share = 0.0;

            foreach ($speakers as $speaker) {
                $share += $point->share($series, $speaker->expertId);
            }

            $curve[$point->turn] = $share / $divisor;
        }

        return $curve;
    }

    /**
     * @param  array<int|string, array<int|string, GroupCollector>>  $collected
     * @param  array<int|string, string>  $groupLabels
     * @return list<Panel>
     */
    private function panels(Question $question, array $collected, array $groupLabels): array
    {
        $panels = [];

        foreach ($this->labels($question->splitBy, array_keys($collected)) as $panelKey => $panelLabel) {
            $groups = [];

            foreach ($groupLabels as $groupKey => $groupLabel) {
                if (isset($collected[$panelKey][$groupKey])) {
                    $groups[] = $collected[$panelKey][$groupKey]->result((string) $groupKey, $groupLabel);
                }
            }

            $panels[] = new Panel((string) $panelKey, $panelLabel, $groups);
        }

        return $panels;
    }

    /**
     * Labels for the keys that occur, in the dimension's own order. A key the
     * dimension no longer lists (a pipeline class since removed) keeps its key
     * as label rather than vanishing from the answer.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labels(?Dimension $dimension, array $keys): array
    {
        $present = array_flip($keys);
        $known = $dimension === null ? ['' => ''] : $this->dimensions->labels($dimension);
        $labels = array_intersect_key($known, $present);

        foreach (array_keys(array_diff_key($present, $labels)) as $key) {
            $labels[$key] = (string) $key;
        }

        return $labels;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=QuestionEvaluatorTest` → PASS (9 tests). Falls `array_flip` bei gemischten Schlüsseln stört: Schlüssel sind int (Tag-IDs) oder string (Pipeline, Modell, `''`); PHP normalisiert numerische Strings zu int, was mit den Labels übereinstimmt.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Discussion tests/Unit/Discussion/QuestionEvaluatorTest.php
git add app/Discussion/Values/{RunValue,GroupResult,Panel,QuestionResult}.php app/Discussion/Metrics/Questions/{GroupCollector,QuestionEvaluator}.php tests/Unit/Discussion/QuestionEvaluatorTest.php
git commit -m "Answer a question in panels, groups and runs from the measured shares"
```

---

### Task 6: Die Frage in Worten

**Files:**
- Create: `app/Livewire/Statistics/QuestionSentence.php`
- Test: `tests/Feature/Statistics/QuestionSentenceTest.php`

**Interfaces:**
- Consumes: `Question`, `Dimension`, `DimensionType` (Task 1); `PipelineRegistry`, `ModelConfig`, `TagValue::fullLabel()`, `TagCategory::label()`
- Produces:
  - `QuestionSentence::parts(Question): array{metric: string, people: ?string, aggregate: ?string, projects: string, compare_by: ?string, split_by: ?string}` (`people` null bei Gini)
  - `QuestionSentence::of(Question): string` (automatischer Name)
  - `QuestionSentence::dimension(Dimension): string`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Statistics/QuestionSentenceTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Question;
use App\Livewire\Statistics\QuestionSentence;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionSentenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_an_empty_question_says_everyone_and_all_runs(): void
    {
        $parts = app(QuestionSentence::class)->parts(new Question);

        $this->assertSame('Turn share', $parts['metric']);
        $this->assertSame('everyone', $parts['people']);
        $this->assertSame('together', $parts['aggregate']);
        $this->assertSame('all runs', $parts['projects']);
        $this->assertNull($parts['compare_by']);
        $this->assertNull($parts['split_by']);
    }

    public function test_the_name_reads_the_whole_question(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);

        $name = app(QuestionSentence::class)->of(Question::fromArray([
            'people' => ['tags' => [$focus->id => [$true->id]]],
            'projects' => ['turn_budget' => 60],
            'compare_by' => "project_tag:{$condition->id}",
            'split_by' => 'pipeline',
        ]));

        $this->assertSame('Turn share · Focus persona: true · runs of 60 turns · by Status condition · per Pipeline', $name);
    }

    public function test_gini_names_no_people(): void
    {
        $this->assertNull(app(QuestionSentence::class)->parts(Question::fromArray(['metric' => 'turn_gini']))['people']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuestionSentenceTest` → FAIL, Klasse fehlt.

- [ ] **Step 3: Implement**

`app/Livewire/Statistics/QuestionSentence.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\Question;
use App\Models\TagCategory;
use App\Models\TagValue;

/**
 * A question in words: part by part for the chips of the editor, joined for
 * the name of a question nobody named. Reads labels; decides nothing.
 */
final class QuestionSentence
{
    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /** @return array{metric: string, people: ?string, aggregate: ?string, projects: string, compare_by: ?string, split_by: ?string} */
    public function parts(Question $question): array
    {
        return [
            'metric' => $question->metric->label(),
            'people' => $question->metric->isRunLevel() ? null : $this->people($question->people),
            'aggregate' => $question->metric->isShare() ? $question->aggregate->label() : null,
            'projects' => $this->projects($question->projects),
            'compare_by' => $question->compareBy === null ? null : $this->dimension($question->compareBy),
            'split_by' => $question->splitBy === null ? null : $this->dimension($question->splitBy),
        ];
    }

    public function of(Question $question): string
    {
        $parts = $this->parts($question);

        return implode(' · ', array_filter([
            $parts['metric'],
            $parts['people'],
            $parts['projects'] === __('questions.sentence.all_runs') ? null : $parts['projects'],
            $parts['compare_by'] === null ? null : __('questions.sentence.name_compared_by', ['dimension' => $parts['compare_by']]),
            $parts['split_by'] === null ? null : __('questions.sentence.name_split_by', ['dimension' => $parts['split_by']]),
        ]));
    }

    public function dimension(Dimension $dimension): string
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => __('questions.dimensions.pipeline'),
            DimensionType::Model => __('questions.dimensions.model'),
            DimensionType::ProjectTag => $this->category($dimension->categoryId),
            DimensionType::PersonaTag => __('questions.dimensions.persona', ['category' => $this->category($dimension->categoryId)]),
        };
    }

    private function people(PeopleFilter $people): string
    {
        if ($people->isEveryone()) {
            return __('questions.sentence.everyone');
        }

        return implode(', ', [...$this->tags($people->tags->valueIds()), ...array_filter([$people->expertName])]);
    }

    private function projects(ProjectFilter $projects): string
    {
        if ($projects->isEmpty()) {
            return __('questions.sentence.all_runs');
        }

        $models = ModelConfig::options();

        return implode(', ', [
            ...$this->tags($projects->tags->valueIds()),
            ...array_map(fn (string $name) => $this->pipelines->label($name), $projects->pipelines),
            ...array_map(fn (string $key) => $models[$key] ?? $key, $projects->models),
            ...($projects->turnBudget === null ? [] : [trans_choice('questions.sentence.turn_budget', $projects->turnBudget, ['count' => $projects->turnBudget])]),
        ]);
    }

    /**
     * @param  list<int>  $valueIds
     * @return list<string>
     */
    private function tags(array $valueIds): array
    {
        return TagValue::whereKey($valueIds)->with('category')->orderBy('tag_category_id')->orderBy('position')->get()
            ->map(fn (TagValue $value) => $value->fullLabel())
            ->all();
    }

    private function category(?int $id): string
    {
        return TagCategory::find($id)?->label() ?? __('questions.sentence.deleted');
    }
}
```

- [ ] **Step 4: Run tests** → `php artisan test --filter=QuestionSentenceTest` PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Livewire/Statistics/QuestionSentence.php tests/Feature/Statistics/QuestionSentenceTest.php
git add app/Livewire/Statistics/QuestionSentence.php tests/Feature/Statistics/QuestionSentenceTest.php
git commit -m "Put a question into words for its chips and its name"
```

---

### Task 7: Der Frage-Editor

**Files:**
- Create: `app/Livewire/Statistics/QuestionEditor.php`, `app/Livewire/Statistics/GroupColor.php`, `app/Livewire/Statistics/CurveRows.php`
- Create: `resources/views/livewire/statistics/question-editor.blade.php`
- Create: `resources/views/components/statistics/chip.blade.php`, `point-panels.blade.php`, `composition-bars.blade.php`, `curve-panels.blade.php`, `run-table.blade.php`
- Test: `tests/Feature/Statistics/QuestionEditorTest.php`

**Interfaces:**
- Consumes: Tasks 1–6
- Produces: Livewire `statistics.question-editor` mit `#[Locked] int $comparisonId`, `array $question`; hört auf `comparison-renamed`; ruft im Menü `$parent.startRenaming`, `$parent.duplicate`, `$parent.saveAsTemplate`, `$parent.confirmDelete` (Task 8).
- `GroupColor::of(QuestionResult, GroupResult): string` (Tailwind-Farbname wie `blue-500`)
- `CurveRows::of(Panel): list<array<string, int|float>>` (`turn`, `g{index}` in Prozent)

- [ ] **Step 1: Write the failing test**

`tests/Feature/Statistics/QuestionEditorTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\QuestionEditor;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function spokenRun(User $user): Project
    {
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'School | with status | 60']);
        $expert = Expert::factory()->create(['name' => 'Alex Brandt']);
        $project->addContributingExpert($expert);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $expert->id, 'words' => 12]);

        return $project;
    }

    public function test_every_change_is_normalised_and_saved_at_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->set('question.metric', 'turn_gini')
            ->set('question.compare_by', 'persona_tag:3')
            ->assertSet('question.compare_by', '');

        $this->assertSame('turn_gini', $comparison->fresh()->question['metric']);
        $this->assertSame('', $comparison->fresh()->question['compare_by']);
    }

    public function test_it_shows_the_sentence_the_answer_and_the_runs_behind_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSee('Turn share')
            ->assertSee('everyone')
            ->assertSee('all runs')
            ->assertSee('100.0 %')
            ->assertSee('School | with status | 60')
            ->assertSee(route('project.show', Project::sole()));
    }

    public function test_an_unnamed_question_is_headed_by_its_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])->assertSee('Word share · everyone');
    }

    public function test_a_template_or_another_users_question_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = $user->comparisons()->create(['is_template' => true]);
        $foreign = User::factory()->create()->comparisons()->create([]);

        foreach ([$template, $foreign] as $comparison) {
            try {
                Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id]);
                $this->fail('Expected a 404.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_empty_answer_says_how_to_widen_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])->assertSee(__('questions.result.empty'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuestionEditorTest` → FAIL, Klasse fehlt.

- [ ] **Step 3: Write the helpers**

`app/Livewire/Statistics/GroupColor.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\QuestionResult;

/**
 * The colour of a group: by its place among all groups of the answer, so one
 * group keeps its colour in every panel, form and legend.
 */
final class GroupColor
{
    /** Tailwind colours with enough contrast to each other in both themes. */
    private const PALETTE = ['blue-500', 'amber-500', 'emerald-500', 'rose-500', 'violet-500', 'cyan-500', 'lime-500', 'fuchsia-500'];

    public static function of(QuestionResult $result, GroupResult $group): string
    {
        return self::at($result->groupIndex($group->key));
    }

    public static function at(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }
}
```

`app/Livewire/Statistics/CurveRows.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Panel;

/** One panel's curves as flux:chart reads them: a row per turn, a field per group, in percent. */
final class CurveRows
{
    /** @return list<array<string, int|float>> */
    public static function of(Panel $panel): array
    {
        $rows = [];

        foreach ($panel->groups as $index => $group) {
            foreach ($group->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]["g{$index}"] = round($point->mean * 100, 1);
            }
        }

        ksort($rows);

        return array_values($rows);
    }
}
```

- [ ] **Step 4: Write the component**

`app/Livewire/Statistics/QuestionEditor.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Question;
use App\Discussion\Values\ResultForm;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One question, edited in place: its sentence on top, its answer below. Every
 * change is normalised through Question and saved at once; there is no draft.
 * Only the user's own questions open here; a template is copied, never edited.
 */
class QuestionEditor extends Component
{
    /** The page renamed this question; render again so the heading follows. */
    protected $listeners = ['comparison-renamed' => '$refresh'];

    #[Locked]
    public int $comparisonId;

    /** @var array<string, mixed> Question::toArray() */
    public array $question = [];

    /** The comparison of this request; not a property Livewire keeps between requests. */
    private ?Comparison $loaded = null;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $this->comparisonId = $comparison->id;
        $this->question = $comparison->toQuestion()->toArray();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'question')) {
            $this->question = Question::fromArray($this->question)->toArray();
            $this->comparison($this->comparisonId)->update(['question' => $this->question]);
        }
    }

    private function comparison(int $comparisonId): Comparison
    {
        if ($this->loaded?->id !== $comparisonId) {
            $this->loaded = auth()->user()->comparisons()->where('is_template', false)->findOrFail($comparisonId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $question = Question::fromArray($this->question);
        $sentence = app(QuestionSentence::class);
        $user = auth()->user();

        return view('livewire.statistics.question-editor', [
            'name' => $this->comparison($this->comparisonId)->name ?? $sentence->of($question),
            'current' => $question,
            'parts' => $sentence->parts($question),
            'result' => app(QuestionEvaluator::class)->evaluate($user, $question),
            'metrics' => Metric::cases(),
            'aggregates' => Aggregate::cases(),
            'forms' => ResultForm::cases(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'turnBudgets' => Project::query()->accessibleBy($user)->whereNotNull('turn_budget')->distinct()->orderBy('turn_budget')->pluck('turn_budget'),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'compareOptions' => $this->dimensionOptions($sentence, withPersona: ! $question->metric->isRunLevel()),
            'splitOptions' => $this->dimensionOptions($sentence, withPersona: false),
            'rollingWindow' => (int) config('discussion.share_window'),
        ]);
    }

    /** @return array<string, string> dimension key => label: project tags, persona tags, pipeline, model */
    private function dimensionOptions(QuestionSentence $sentence, bool $withPersona): array
    {
        $dimensions = [
            ...TagCategory::ofScope(TagScope::Project)->get()->map(fn (TagCategory $c) => new Dimension(DimensionType::ProjectTag, $c->id)),
            ...($withPersona ? TagCategory::ofScope(TagScope::Persona)->get()->map(fn (TagCategory $c) => new Dimension(DimensionType::PersonaTag, $c->id)) : []),
            new Dimension(DimensionType::Pipeline),
            new Dimension(DimensionType::Model),
        ];

        $options = [];

        foreach ($dimensions as $dimension) {
            $options[$dimension->key()] = $sentence->dimension($dimension);
        }

        return $options;
    }
}
```

- [ ] **Step 5: Write the views**

`resources/views/components/statistics/chip.blade.php`:

```blade
@props([
    'label',          {{-- what the sentence says for this part --}}
    'muted' => false, {{-- the part filters nothing ("everyone", "all runs", "nothing") --}}
])

{{-- One part of the question's sentence: the words as a button, its controls in a popover. --}}
<flux:dropdown position="bottom" align="start" class="inline-block">
    <button type="button" @class([
        'inline-flex max-w-full items-center gap-1 rounded-md border px-2 py-0.5 align-baseline text-sm',
        'border-[var(--color-accent)] bg-[color-mix(in_oklab,var(--color-accent)_12%,transparent)] text-zinc-900 dark:text-white' => ! $muted,
        'border-dashed border-zinc-300 text-zinc-600 dark:border-zinc-600 dark:text-zinc-300' => $muted,
    ])>
        <span class="truncate">{{ $label }}</span>
        <flux:icon.chevron-down variant="micro" class="shrink-0" />
    </button>

    <flux:popover class="w-80 max-w-[calc(100vw-2rem)] space-y-4">
        {{ $slot }}
    </flux:popover>
</flux:dropdown>
```

`resources/views/components/statistics/point-panels.blade.php`:

```blade
@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    Small multiples: a panel per value of the split, a row per group. Every
    run is a dot, the mean a bar across the track, the fair share a dashed
    mark. All panels share one scale, so they compare at a glance.
--}}
@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))
@php($at = fn (float $value) => max(0, min(100, $value / $scale * 100)))

<div {{ $attributes->class('grid gap-4 sm:grid-cols-2 xl:grid-cols-3') }}>
    @foreach ($result->panels as $panel)
        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10" wire:key="points-{{ $panel->key }}">
            @if ($panel->label !== '')
                <flux:heading size="sm">{{ $panel->label }}</flux:heading>
            @endif

            <div class="mt-2 space-y-3">
                @foreach ($panel->groups as $group)
                    @php($color = GroupColor::of($result, $group))

                    <div wire:key="points-{{ $panel->key }}-{{ $group->key }}">
                        <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="truncate">{{ $group->label !== '' ? $group->label : __('questions.result.all') }}</span>
                            <span class="shrink-0 tabular-nums">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $metric->format($group->mean()) }}</span>
                                · n = {{ $group->n() }}
                            </span>
                        </div>

                        <div class="relative mt-1 h-5 rounded bg-zinc-100 dark:bg-white/10" role="img"
                            aria-label="{{ $group->label }}: {{ __('questions.result.mean') }} {{ $metric->format($group->mean()) }}, n = {{ $group->n() }}">
                            @if ($group->fairShare() !== null)
                                <span class="absolute inset-y-0 border-s border-dashed border-zinc-500 dark:border-zinc-300" style="left: {{ $at($group->fairShare()) }}%"></span>
                            @endif
                            @foreach ($group->runs as $run)
                                <span class="absolute top-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-80"
                                    style="left: {{ $at($run->value) }}%; background-color: var(--color-{{ $color }})"
                                    title="{{ $run->title }}: {{ $metric->format($run->value) }}"></span>
                            @endforeach
                            <span class="absolute inset-y-0.5 w-0.5 -translate-x-1/2 bg-zinc-900 dark:bg-white" style="left: {{ $at($group->mean()) }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($panel->delta() !== null)
                <flux:text size="sm" class="mt-3 tabular-nums">
                    {{ __('questions.result.delta', ['to' => $panel->groups[1]->label, 'from' => $panel->groups[0]->label, 'delta' => $metric->formatDelta($panel->delta())]) }}
                </flux:text>
            @endif
        </div>
    @endforeach
</div>
```

`resources/views/components/statistics/composition-bars.blade.php`:

```blade
@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    One 100 % bar per panel, a segment per group: how the floor divides. Only
    offered when the groups add up to everyone (Question::supports), so the
    segments always fill the bar. Bars and not pies: several compositions are
    compared side by side, and lengths on one axis read better than angles.
--}}
<div {{ $attributes->class('space-y-3') }}>
    @foreach ($result->panels as $panel)
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,10rem)_1fr] sm:items-center sm:gap-4" wire:key="composition-{{ $panel->key }}">
            <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $panel->label !== '' ? $panel->label : __('questions.result.all') }}</span>

            <div class="flex h-7 overflow-hidden rounded" role="img"
                aria-label="{{ collect($panel->groups)->map(fn ($group) => $group->label.': '.$metric->format($group->mean()))->implode(', ') }}">
                @foreach ($panel->groups as $group)
                    <div class="flex min-w-0 items-center justify-center overflow-hidden text-xs font-medium text-white"
                        style="width: {{ $group->mean() * 100 }}%; background-color: var(--color-{{ GroupColor::of($result, $group) }})"
                        title="{{ $group->label }}: {{ $metric->format($group->mean()) }}">
                        <span class="truncate px-1">{{ $metric->format($group->mean()) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
```

`resources/views/components/statistics/curve-panels.blade.php`:

```blade
@use('App\Livewire\Statistics\CurveRows')
@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
])

{{-- A chart per panel, a line per group: the mean share after each turn over the runs that reached it. --}}
<div {{ $attributes->class('grid gap-4 lg:grid-cols-2') }}>
    @foreach ($result->panels as $panel)
        @php($rows = CurveRows::of($panel))

        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10" wire:key="curve-{{ $panel->key }}">
            @if ($panel->label !== '')
                <flux:heading size="sm">{{ $panel->label }}</flux:heading>
            @endif

            @if (count($rows) < 2)
                <flux:text size="sm" class="mt-2">{{ __('questions.result.too_short') }}</flux:text>
            @else
                {{-- Keyed on the data: the chart's children are wire:ignore'd, so new rows need a fresh element. --}}
                <flux:chart wire:key="curve-{{ $panel->key }}-{{ md5(json_encode($rows)) }}" :value="$rows" class="mt-2 aspect-[2/1]">
                    <flux:chart.svg>
                        @foreach ($panel->groups as $index => $group)
                            <flux:chart.line :field="'g'.$index" curve="none" style="color: var(--color-{{ GroupColor::of($result, $group) }})" />
                        @endforeach

                        <flux:chart.axis axis="x" field="turn" scale="linear">
                            <flux:chart.axis.line />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-start="0" tick-end="100" tick-suffix=" %">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.cursor />
                    </flux:chart.svg>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="turn" />
                        @foreach ($panel->groups as $index => $group)
                            <flux:chart.tooltip.value :field="'g'.$index" :label="$group->label !== '' ? $group->label : __('questions.result.all')" suffix=" %" />
                        @endforeach
                    </flux:chart.tooltip>
                </flux:chart>
            @endif
        </div>
    @endforeach
</div>
```

`resources/views/components/statistics/run-table.blade.php`:

```blade
@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{-- The evidence under every number: each run's value, with the way back to its chat. --}}
@php($rows = collect($result->panels)->flatMap(fn ($panel) => collect($panel->groups)->flatMap(fn ($group) => collect($group->runs)->map(fn ($run) => compact('panel', 'group', 'run')))))

<details {{ $attributes->class('group') }}>
    <summary class="flex cursor-pointer list-none items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300 [&::-webkit-details-marker]:hidden">
        <flux:icon.chevron-right variant="micro" class="transition-transform group-open:rotate-90" />
        {{ trans_choice('questions.result.run_table', $rows->pluck('run.projectId')->unique()->count(), ['count' => $rows->pluck('run.projectId')->unique()->count()]) }}
    </summary>

    <flux:table class="mt-2">
        <flux:table.columns>
            <flux:table.column>{{ __('questions.result.columns.run') }}</flux:table.column>
            <flux:table.column>{{ __('questions.result.columns.panel') }}</flux:table.column>
            <flux:table.column>{{ __('questions.result.columns.group') }}</flux:table.column>
            <flux:table.column align="end">{{ __('questions.result.columns.value') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($rows as $row)
                <flux:table.row wire:key="run-{{ $row['panel']->key }}-{{ $row['group']->key }}-{{ $row['run']->projectId }}">
                    <flux:table.cell><flux:link :href="route('project.show', $row['run']->projectId)">{{ $row['run']->title }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ $row['panel']->label }}</flux:table.cell>
                    <flux:table.cell>{{ $row['group']->label }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $metric->format($row['run']->value) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</details>
```

`resources/views/livewire/statistics/question-editor.blade.php`:

```blade
@use('App\Discussion\Values\ResultForm')
@use('App\Livewire\Statistics\GroupColor')

<div class="space-y-6">
    {{-- The question's name heads it, with the autosave state and the page's actions. --}}
    <div class="flex items-center gap-3">
        <flux:heading size="xl" level="2" class="min-w-0 truncate">{{ $name }}</flux:heading>

        <flux:text size="sm" class="flex shrink-0 items-center gap-1" wire:loading.remove>
            <flux:icon.check variant="micro" />{{ __('questions.autosave.saved') }}
        </flux:text>
        <flux:text size="sm" class="shrink-0" wire:loading>{{ __('questions.autosave.saving') }}</flux:text>

        <flux:dropdown position="bottom" align="end" class="ms-auto">
            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
            <flux:menu>
                <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $comparisonId }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $comparisonId }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                <flux:menu.item icon="rectangle-stack" wire:click="$parent.saveAsTemplate({{ $comparisonId }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $comparisonId }})">{{ __('questions.actions.delete') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- The question as one sentence. Each chip opens the controls of its part. --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 text-base leading-9 dark:border-white/10 dark:bg-white/5">
        <x-statistics.chip :label="$parts['metric']">
            <flux:radio.group wire:model.live="question.metric" :label="__('questions.controls.metric')">
                @foreach ($metrics as $metric)
                    <flux:radio :value="$metric->value" :label="$metric->label()" />
                @endforeach
            </flux:radio.group>
        </x-statistics.chip>

        @if ($parts['people'] !== null)
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.of') }}</span>
            <x-statistics.chip :label="$parts['people']" :muted="$current->people->isEveryone()">
                @foreach ($personaCategories as $category)
                    <flux:checkbox.group variant="pills" wire:key="people-{{ $category->id }}"
                        wire:model.live="question.people.tags.{{ $category->id }}" :label="$category->label()" class="gap-1.5!">
                        @foreach ($category->values as $value)
                            <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                        @endforeach
                    </flux:checkbox.group>
                @endforeach
                <flux:select variant="listbox" searchable wire:model.live="question.people.expert_name"
                    :label="__('questions.controls.expert_name')" :placeholder="__('questions.controls.any_expert')">
                    <flux:select.option value="">{{ __('questions.controls.any_expert') }}</flux:select.option>
                    @foreach ($expertNames as $expertName)
                        <flux:select.option :value="$expertName">{{ $expertName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text size="sm">{{ __('questions.controls.people_hint') }}</flux:text>
            </x-statistics.chip>
        @endif

        @if ($parts['aggregate'] !== null)
            <x-statistics.chip :label="$parts['aggregate']" :muted="true">
                <flux:radio.group wire:model.live="question.aggregate" :label="__('questions.controls.aggregate')">
                    @foreach ($aggregates as $aggregate)
                        <flux:radio :value="$aggregate->value" :label="$aggregate->label()" :description="__('questions.aggregate_hints.'.$aggregate->value)" />
                    @endforeach
                </flux:radio.group>
            </x-statistics.chip>
        @endif

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.in') }}</span>
        <x-statistics.chip :label="$parts['projects']" :muted="$current->projects->isEmpty()">
            @foreach ($projectCategories as $category)
                <flux:checkbox.group variant="pills" wire:key="projects-{{ $category->id }}"
                    wire:model.live="question.projects.tags.{{ $category->id }}" :label="$category->label()" class="gap-1.5!">
                    @foreach ($category->values as $value)
                        <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                    @endforeach
                </flux:checkbox.group>
            @endforeach
            <flux:checkbox.group variant="pills" wire:model.live="question.projects.pipelines" :label="__('questions.controls.pipelines')" class="gap-1.5!">
                @foreach ($pipelines as $pipelineName => $label)
                    <flux:checkbox variant="pills" :value="$pipelineName" :label="$label" />
                @endforeach
            </flux:checkbox.group>
            <flux:checkbox.group variant="pills" wire:model.live="question.projects.models" :label="__('questions.controls.models')" class="gap-1.5!">
                @foreach ($models as $key => $label)
                    <flux:checkbox variant="pills" :value="$key" :label="$label" />
                @endforeach
            </flux:checkbox.group>
            <flux:select variant="listbox" wire:model.live="question.projects.turn_budget"
                :label="__('questions.controls.turn_budget')" :placeholder="__('questions.controls.any_budget')">
                <flux:select.option value="">{{ __('questions.controls.any_budget') }}</flux:select.option>
                @foreach ($turnBudgets as $budget)
                    <flux:select.option :value="$budget">{{ $budget }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-statistics.chip>

        <br class="max-sm:hidden">

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.compared_by') }}</span>
        <x-statistics.chip :label="$parts['compare_by'] ?? __('questions.sentence.nothing')" :muted="$parts['compare_by'] === null">
            <flux:select variant="listbox" wire:model.live="question.compare_by" :label="__('questions.controls.compare_by')">
                <flux:select.option value="">{{ __('questions.controls.none') }}</flux:select.option>
                @foreach ($compareOptions as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($current->metric->isRunLevel())
                <flux:text size="sm">{{ __('questions.controls.gini_hint') }}</flux:text>
            @endif
        </x-statistics.chip>

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.split_by') }}</span>
        <x-statistics.chip :label="$parts['split_by'] ?? __('questions.sentence.nothing')" :muted="$parts['split_by'] === null">
            <flux:select variant="listbox" wire:model.live="question.split_by" :label="__('questions.controls.split_by')">
                <flux:select.option value="">{{ __('questions.controls.none') }}</flux:select.option>
                @foreach ($splitOptions as $key => $label)
                    @unless ($key === ($question['compare_by'] ?? ''))
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endunless
                @endforeach
            </flux:select>
        </x-statistics.chip>
    </div>

    {{-- How many runs answer, and in which form. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text size="sm">
            {{ trans_choice('questions.result.runs', $result->runs, ['count' => $result->runs]) }}
            @if ($result->excluded > 0)
                · {{ trans_choice('questions.result.excluded', $result->excluded, ['count' => $result->excluded]) }}
            @endif
        </flux:text>

        <div class="flex items-center gap-2">
            @if ($current->form === ResultForm::Trajectory)
                <flux:radio.group wire:model.live="question.curve" variant="segmented" size="sm">
                    <flux:radio value="cumulative" :label="__('questions.curves.cumulative')" />
                    <flux:radio value="rolling" :label="__('questions.curves.rolling')" />
                </flux:radio.group>
            @endif

            <flux:radio.group wire:model.live="question.form" variant="segmented" size="sm">
                @foreach ($forms as $form)
                    @if ($current->supports($form))
                        <flux:radio :value="$form->value" :label="$form->label()" />
                    @else
                        <flux:radio :value="$form->value" :label="$form->label()" disabled :title="__('questions.forms.unsupported.'.$form->value)" />
                    @endif
                @endforeach
            </flux:radio.group>
        </div>
    </div>

    @if ($result->isEmpty())
        <x-empty-state icon="chart-bar">{{ __('questions.result.empty') }}</x-empty-state>
    @else
        {{-- Legend: one colour per group, the same in every panel. --}}
        @if (count($result->groups) > 1)
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                @foreach ($result->groups as $key => $label)
                    <span class="flex items-center gap-1.5" wire:key="legend-{{ $key }}">
                        <span class="size-2.5 rounded-full" style="background-color: var(--color-{{ GroupColor::at($loop->index) }})"></span>{{ $label }}
                    </span>
                @endforeach
            </div>
        @endif

        @switch($current->form)
            @case(ResultForm::Composition)
                <x-statistics.composition-bars :result="$result" :metric="$current->metric" />
                @break
            @case(ResultForm::Trajectory)
                <x-statistics.curve-panels :result="$result" />
                @if ($current->curve === 'rolling')
                    <flux:text size="sm">{{ trans_choice('statistics.trajectory.rolling_hint', $rollingWindow, ['count' => $rollingWindow]) }}</flux:text>
                @endif
                @break
            @default
                <x-statistics.point-panels :result="$result" :metric="$current->metric" />
                @if ($current->metric->isShare())
                    <flux:text size="sm">{{ __('questions.result.fair_share') }}</flux:text>
                @endif
        @endswitch

        <x-statistics.run-table :result="$result" :metric="$current->metric" />
    @endif
</div>
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=QuestionEditorTest` → PASS. Wenn `assertSee('100.0 %')` am Format scheitert: `LocaleNumber::percent(1.0)` im Tinker prüfen und die erwartete Zeichenkette anpassen (nicht den Code).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Livewire/Statistics tests/Feature/Statistics/QuestionEditorTest.php
git add app/Livewire/Statistics/{QuestionEditor,GroupColor,CurveRows}.php resources/views/livewire/statistics/question-editor.blade.php resources/views/components/statistics/{chip,point-panels,composition-bars,curve-panels,run-table}.blade.php tests/Feature/Statistics/QuestionEditorTest.php
git commit -m "Edit a question as a sentence and show its answer in a fixed form"
```

---

### Task 8: Seite mit eigenen Fragen und Vorlagen

**Files:**
- Modify: `app/Livewire/Statistics/StatisticsPage.php`, `resources/views/livewire/statistics/statistics-page.blade.php`
- Test: `tests/Feature/Statistics/StatisticsPageTest.php` (ersetzen)

**Interfaces:**
- Consumes: `Comparison` scopes (Task 3), `QuestionSentence` (Task 6), `QuestionEditor` (Task 7)
- Produces: öffentliche Aktionen `open(int)`, `create()`, `useTemplate(int)`, `saveAsTemplate(int)`, `startRenaming(int)`, `saveName()`, `duplicate(int)`, `confirmDelete(int)`, `delete(int)`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Statistics/StatisticsPageTest.php` vollständig ersetzen:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('statistics'))->assertRedirect(route('login'));
    }

    public function test_every_signed_in_user_reaches_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('statistics'))->assertOk()->assertSee(__('questions.heading'));
    }

    public function test_a_new_question_opens_at_once_without_a_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->call('create')->assertSet('comparisonId', Comparison::sole()->id);

        $this->assertNull(Comparison::sole()->name);
        $this->assertFalse(Comparison::sole()->is_template);
    }

    public function test_anyone_copies_a_template_into_their_own_questions(): void
    {
        $template = User::factory()->create()->comparisons()->create(['name' => 'Status effect', 'question' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->assertSee('Status effect')->call('useTemplate', $template->id);

        $copy = $user->comparisons()->sole();
        $this->assertFalse($copy->is_template);
        $this->assertSame('Status effect', $copy->name);
        $this->assertSame('word_share', $copy->question['metric']);
    }

    public function test_saving_as_template_keeps_the_question_and_names_the_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)->call('saveAsTemplate', $question->id);

        $template = Comparison::templates()->sole();
        $this->assertSame('Word share · everyone', $template->name);
        $this->assertFalse($question->fresh()->is_template);
    }

    public function test_renaming_to_nothing_returns_to_the_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $question->id)
            ->set('name', '   ')
            ->call('saveName')
            ->assertHasNoErrors();

        $this->assertNull($question->fresh()->name);
    }

    public function test_only_the_owner_deletes_a_template(): void
    {
        $owner = User::factory()->create();
        $template = $owner->comparisons()->create(['name' => 'T', 'is_template' => true]);
        $this->actingAs(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(StatisticsPage::class)->call('delete', $template->id);
    }

    public function test_someone_elses_question_does_not_exist_here(): void
    {
        $foreign = User::factory()->create()->comparisons()->create([]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['c' => $foreign->id])->test(StatisticsPage::class)->assertSet('comparisonId', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsPageTest` → FAIL (`create`, `useTemplate` fehlen).

- [ ] **Step 3: Rewrite the component**

`app/Livewire/Statistics/StatisticsPage.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Question;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: the user's questions and everyone's templates on the
 * left, the open question on the right. A question id is always resolved
 * through the user's own rows; someone else's question does not exist here.
 * Templates are read by everyone, copied to be used, renamed or deleted only
 * by their owner.
 */
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'c')]
    public ?int $comparisonId = null;

    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    public function mount(): void
    {
        if ($this->comparisonId !== null && $this->user()->comparisons()->questions()->find($this->comparisonId) === null) {
            $this->comparisonId = null;
        }
    }

    public function open(int $comparisonId): void
    {
        $this->comparisonId = $this->user()->comparisons()->questions()->findOrFail($comparisonId)->id;
    }

    public function create(): void
    {
        $this->comparisonId = $this->user()->comparisons()->create(['question' => (new Question)->toArray()])->id;
    }

    /** A template becomes the user's own question; the template itself stays as it is. */
    public function useTemplate(int $templateId): void
    {
        $template = Comparison::templates()->findOrFail($templateId);

        $this->comparisonId = $this->user()->comparisons()->create([
            'name' => $template->name,
            'question' => $template->question,
        ])->id;
    }

    /** A snapshot of the question as a template, named after it; the question stays the user's own. */
    public function saveAsTemplate(int $comparisonId): void
    {
        $question = $this->owned($comparisonId);

        $this->user()->comparisons()->create([
            'name' => Str::limit($question->name ?? app(QuestionSentence::class)->of($question->toQuestion()), 100, ''),
            'question' => $question->question,
            'is_template' => true,
        ]);
    }

    public function startRenaming(int $comparisonId): void
    {
        $comparison = $this->owned($comparisonId);
        $this->renamingId = $comparison->id;
        $this->name = (string) $comparison->name;
        $this->resetValidation();
        Flux::modal('question-name')->show();
    }

    /** An empty name is allowed: the question is then called by its sentence again. */
    public function saveName(): void
    {
        $this->name = trim($this->name);
        $this->validate(['name' => ['nullable', 'string', 'max:100']], [], ['name' => __('questions.name.field')]);

        $this->owned((int) $this->renamingId)->update(['name' => $this->name === '' ? null : $this->name]);
        $this->dispatch('comparison-renamed');

        $this->reset(['renamingId', 'name']);
        Flux::modal('question-name')->close();
    }

    public function duplicate(int $comparisonId): void
    {
        $source = $this->user()->comparisons()->questions()->findOrFail($comparisonId);
        $copy = $source->replicate();
        $copy->name = $source->name === null ? null : Str::limit(__('questions.copy_name', ['name' => $source->name]), 100, '');
        $copy->save();

        $this->comparisonId = $copy->id;
    }

    public function confirmDelete(int $comparisonId): void
    {
        $comparison = $this->owned($comparisonId);
        $kind = $comparison->is_template ? 'delete_template' : 'delete';

        $this->confirmTitle = __("questions.confirm.{$kind}.title");
        $this->confirmMessage = __("questions.confirm.{$kind}.message", ['name' => $this->nameOf($comparison)]);
        $this->needsConfirmation('delete', $comparisonId);
    }

    public function delete(int $comparisonId): void
    {
        $this->owned($comparisonId)->delete();

        if ($this->comparisonId === $comparisonId) {
            $this->comparisonId = null;
        }
    }

    /** One of the user's own rows, question or template. */
    private function owned(int $comparisonId): Comparison
    {
        return $this->user()->comparisons()->findOrFail($comparisonId);
    }

    private function nameOf(Comparison $comparison): string
    {
        return $comparison->name ?? app(QuestionSentence::class)->of($comparison->toQuestion());
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        $questions = $this->user()->comparisons()->questions()->get();

        return view('livewire.statistics.statistics-page', [
            'questions' => $questions,
            'templates' => Comparison::templates()->get(),
            'names' => $questions->mapWithKeys(fn (Comparison $comparison) => [$comparison->id => $this->nameOf($comparison)]),
            'current' => $this->comparisonId === null ? null : $questions->firstWhere('id', $this->comparisonId),
        ])->title(__('questions.heading'));
    }
}
```

- [ ] **Step 4: Rewrite the view**

`resources/views/livewire/statistics/statistics-page.blade.php`:

```blade
<section class="mx-auto w-full max-w-6xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('questions.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('questions.subheading') }}</flux:text>
        </div>

        <flux:button size="sm" :variant="$questions->isEmpty() ? 'primary' : 'outline'" icon="plus" wire:click="create">
            {{ __('questions.list.create') }}
        </flux:button>
    </div>

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 space-y-6 lg:w-64">
            <div>
                <x-statistics.section-label class="px-3">{{ __('questions.list.templates') }}</x-statistics.section-label>

                @if ($templates->isEmpty())
                    <flux:text size="sm" class="mt-2 px-3">{{ __('questions.list.no_templates') }}</flux:text>
                @else
                    <flux:navlist class="mt-2">
                        @foreach ($templates as $template)
                            <div class="group/template relative" wire:key="template-{{ $template->id }}">
                                <flux:navlist.item icon="rectangle-stack" wire:click="useTemplate({{ $template->id }})" :title="$template->name"
                                    @class(['min-w-0 [&_[data-content]]:min-w-0', 'pe-9' => $template->user_id === auth()->id()])>
                                    <span class="block truncate">{{ $template->name }}</span>
                                </flux:navlist.item>

                                @if ($template->user_id === auth()->id())
                                    <div class="absolute end-1 top-0 flex h-10 items-center lg:h-8">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                                class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $template->id }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $template->id }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </flux:navlist>
                @endif
            </div>

            <div>
                <x-statistics.section-label class="px-3">{{ __('questions.list.questions') }}</x-statistics.section-label>

                @if ($questions->isEmpty())
                    <flux:text size="sm" class="mt-2 px-3">{{ __('questions.list.empty') }}</flux:text>
                @else
                    <flux:navlist class="mt-2">
                        @foreach ($questions as $question)
                            <div class="relative" wire:key="question-{{ $question->id }}">
                                <flux:navlist.item wire:click="open({{ $question->id }})" :current="$comparisonId === $question->id" :title="$names[$question->id]"
                                    class="min-w-0 pe-9 [&_[data-content]]:min-w-0">
                                    <span class="block truncate">{{ $names[$question->id] }}</span>
                                </flux:navlist.item>

                                <div class="absolute end-1 top-0 flex h-10 items-center lg:h-8">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                            class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $question->id }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                                            <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $question->id }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                                            <flux:menu.item icon="rectangle-stack" wire:click="saveAsTemplate({{ $question->id }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $question->id }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
                        @endforeach
                    </flux:navlist>
                @endif
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            @if ($current)
                <livewire:statistics.question-editor :comparison-id="$current->id" wire:key="question-editor-{{ $current->id }}" />
            @else
                <x-empty-state icon="chart-bar">{{ __('questions.none_open') }}</x-empty-state>
            @endif
        </div>
    </div>

    <flux:modal name="question-name" class="md:w-96">
        <form wire:submit.prevent="saveName" class="space-y-6">
            <x-modal-header :heading="__('questions.name.rename')" />

            <flux:input wire:model="name" :label="__('questions.name.field')" :description="__('questions.name.hint')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
```

Das geteilte `x-confirm-modal` sitzt im Layout (`resources/views/components/layouts/app/sidebar.blade.php:102`); die Seite braucht kein eigenes.

- [ ] **Step 5: Run tests** → `php artisan test --filter='StatisticsPageTest|QuestionEditorTest'` PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Livewire/Statistics/StatisticsPage.php tests/Feature/Statistics/StatisticsPageTest.php
git add app/Livewire/Statistics/StatisticsPage.php resources/views/livewire/statistics/statistics-page.blade.php tests/Feature/Statistics/StatisticsPageTest.php
git commit -m "List own questions and shared templates; copy a template to use it"
```

---

### Task 9: Thesis-Vorlagen, Altcode entfernen, Doku

**Files:**
- Create: `database/seeders/ThesisTemplatesSeeder.php`
- Test: `tests/Feature/Statistics/ThesisTemplatesSeederTest.php`
- Delete: alle unter „Gelöscht in Task 9“ genannten Dateien (siehe Dateistruktur) und ihre Tests: `tests/Feature/Statistics/{ComparisonEditorTest,ComparisonResultsTest,ComparisonResultTest,ComparisonTemplatesTest,SeriesProjectsTest}.php`, `tests/Unit/Discussion/{AgentReferenceTest,SeriesColorTest,SeriesDefinitionTest,SeriesStatisticsTest}.php`, `lang/{en,de}/comparisons.php`
- Modify: `app/Discussion/Values/Metric.php` (`formatRange()` entfernen, falls danach unbenutzt), `CLAUDE.md` (Abschnitt „Statistics page“ und „Where a type lives“)

**Interfaces:**
- Consumes: `Question` (Task 1), `Comparison` (Task 3)
- Produces: `php artisan db:seed --class=ThesisTemplatesSeeder` legt sechs Vorlagen an (idempotent per Name).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Statistics/ThesisTemplatesSeederTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Models\Comparison;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Database\Seeders\ThesisTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThesisTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function studyTags(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $condition->values()->create(['name' => 'status']);
        $condition->values()->create(['name' => 'no-status']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder'])->values()->create(['name' => 'true']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona'])->values()->create(['name' => 'true']);
    }

    public function test_six_templates_owned_by_the_first_admin_and_idempotent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->studyTags();

        $this->seed(ThesisTemplatesSeeder::class);
        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(6, Comparison::templates()->count());
        $this->assertTrue(Comparison::templates()->get()->every(fn (Comparison $template) => $template->user_id === $admin->id));
        $statusEffect = Comparison::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->toQuestion();
        $this->assertSame('pipeline', $statusEffect->splitBy->key());
        $this->assertSame(60, $statusEffect->projects->turnBudget);
    }

    public function test_a_template_whose_tags_are_missing_is_skipped(): void
    {
        User::factory()->create(['is_admin' => true]);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition'])->values()->create(['name' => 'status']);

        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(['Speaking inequality with and without status by pipeline'], Comparison::templates()->pluck('name')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails** → `php artisan test --filter=ThesisTemplatesSeederTest` FAIL.

- [ ] **Step 3: Implement the seeder**

`database/seeders/ThesisTemplatesSeeder.php`:

```php
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
```

Vor dem Implementieren `app/Services/Tags/TagNames.php` lesen und die Signatur von `same()` bestätigen (statisch, zwei Strings). Ist sie eine Instanzmethode, `app(TagNames::class)->same(…)` verwenden.

- [ ] **Step 4: Run test** → `php artisan test --filter=ThesisTemplatesSeederTest` PASS.

- [ ] **Step 5: Delete the old code**

```bash
git rm app/Livewire/Statistics/{ComparisonEditor,ComparisonResult,ResultCharts,SeriesLabels,SeriesSummary}.php \
  app/Discussion/Metrics/{SeriesProjects,SeriesStatistics}.php \
  app/Discussion/Metrics/References/*.php app/Discussion/Metrics/Templates/*.php \
  app/Discussion/Values/{SeriesDefinition,ComparisonView,ComparisonDisplay,SeriesColor,SeriesResult,SeriesLine,ProjectValue,AgentGroup}.php \
  resources/views/livewire/statistics/comparison-editor.blade.php \
  resources/views/components/statistics/{range-bars,pies}.blade.php \
  lang/en/comparisons.php lang/de/comparisons.php \
  tests/Feature/Statistics/{ComparisonEditorTest,ComparisonResultsTest,ComparisonResultTest,ComparisonTemplatesTest,SeriesProjectsTest}.php \
  tests/Unit/Discussion/{AgentReferenceTest,SeriesColorTest,SeriesDefinitionTest,SeriesStatisticsTest}.php
```

Dann prüfen, dass nichts mehr darauf zeigt:

```bash
grep -rnE "comparisons\.|SeriesDefinition|ComparisonView|ComparisonDisplay|AgentReference|SeriesColor|formatRange|pie-fill|tint-" app resources lang tests routes
```

Expected: keine Treffer außer den `[data-pie-tints]`-Regeln samt Kommentar in `resources/css/app.css` (ab etwa Zeile 250). Diese entfernen: nur das gelöschte `pies.blade.php` setzte `data-pie-tints`; der Chat-Flyout zeichnet seine Kreise ohne dieses Attribut (geprüft am 12.10.2026). Danach `npm run build`. `Metric::formatRange()` entfernen, wenn grep keinen Aufrufer mehr findet; `Metric::isShare()`-Kommentar („parts of a whole for a pie“) auf „a curve and parts of a whole“ anpassen.

- [ ] **Step 6: Update CLAUDE.md**

Den Abschnitt `### Statistics page (…)` vollständig ersetzen durch:

```markdown
### Statistics page (`Statistics\StatisticsPage`, `Statistics\QuestionEditor`)
`/statistics`, for every verified user. A **question** is one sentence: `[metric] of [people] ([together | per head]) in [runs], compared by [dimension], split by [dimension]` (`App\Discussion\Values\Question`, stored as JSON in `comparisons.question`). `Question::fromArray()` normalises any input into an answerable question: Gini counts everyone and never compares within a run; the aggregate applies to shares only; `split_by` is never a persona tag nor the `compare_by` dimension; a form the question cannot show falls back to points (`Question::supports()`). Dimensions (`Dimension`, key `persona_tag:3`, `project_tag:1`, `pipeline`, `model`) are bound by key. `QuestionEvaluator` (`App\Discussion\Metrics\Questions`) answers it only through `SpeakingShares`/`ShareTrajectory`: panels per value of the split, groups per value of the comparison (a persona tag sorts agents of the same run, every other dimension sorts runs; agents without a value form the "no value" group), one `RunValue` per run with its **fair share** (group size ÷ run size together, 1 ÷ run size per head; none for non-shares). A run without a spoken turn, without a value in a run dimension or without a matching person drops out and is counted. The answer has three forms: points (every run a dot, mean, fair share dashed, delta when exactly two groups), shares (100 % stacked bars, only when the groups add up to everyone), over the run (curves). No pies on this page. Under every answer the runs table links to the chats. A question without a name is called by its sentence (`Statistics\QuestionSentence`). **Templates** are rows with `is_template`: everyone sees them, a click copies one into the user's own questions, only the owner renames or deletes one, nobody edits one in place; "Save as template" snapshots a question. The thesis templates come from `ThesisTemplatesSeeder` (first admin owns them; a template naming a missing tag is skipped). The status-effect templates read the persona tag **`focus persona: true`**, set on *both* expert records of each titled persona, because in no-status runs the same persona is a separate record with `title-holder: false`.
```

Unter „Where a type lives“ die Statistik-Werte ersetzen durch: `… the statistics page's (`Question`, `ProjectFilter`, `PeopleFilter`, `Dimension`, `DimensionType`, `Aggregate`, `ResultForm`, `QuestionResult`, `Panel`, `GroupResult`, `RunValue`, `TrajectoryMean`, `TagFilter`)` und den Satz über `SeriesDefinition`/`AgentReference` streichen.

- [ ] **Step 7: Full suite and style**

Run: `vendor/bin/pint && php artisan test`
Expected: alle Tests grün. Fehlschläge mit Ausgabe festhalten und beheben, bevor committet wird.

- [ ] **Step 8: Commit**

```bash
git add -A app database lang resources tests CLAUDE.md
git commit -m "Seed the thesis questions as templates and remove the series editor"
```

---

### Task 10: Echte Daten, Browser-Prüfung, Reviews

Diese Task ändert die echte Datenbank. **Jeden Schritt mit Seiteneffekt erst nach ausdrücklicher Zustimmung des Nutzers ausführen.**

- [ ] **Step 1: Backup**

```bash
cp database/database.sqlite "database/database.sqlite.bak-$(date +%Y%m%d-%H%M%S)"
```

- [ ] **Step 2: Migrate** (verwirft die gespeicherten Vergleiche, siehe Spec)

```bash
php artisan migrate
```

- [ ] **Step 3: Tag the focus persona** (Tinker, kein Code im Repo)

```bash
php artisan tinker --execute='
$title = App\Models\TagCategory::where("scope", "persona")->where("name", "title-holder")->sole();
$titled = $title->values()->where("name", "true")->sole();
$names = App\Models\Expert::whereTagFilter(new App\Discussion\Values\TagFilter([$title->id => [$titled->id]]))->pluck("name")->unique();
$focus = App\Models\TagCategory::firstOrCreate(["scope" => "persona", "name" => "focus persona"]);
$true = $focus->values()->firstOrCreate(["name" => "true"]);
App\Models\Expert::whereIn("name", $names)->get()->each(fn ($e) => $e->setTag($focus, $true));
echo $names->implode(", "), " → ", App\Models\Expert::whereIn("name", $names)->count(), " records\n";
'
```

Expected: `Alex Brandt, Sam Delius, Mika Sommer, Nikita Falk → 8 records` (je Persona der Teacher/Manager/Parent/Coach- und der Student-Datensatz). Abweichung dem Nutzer melden, nicht korrigieren.

- [ ] **Step 4: Seed the templates**

```bash
php artisan db:seed --class=ThesisTemplatesSeeder
```

Expected: keine „Skipped“-Warnung.

- [ ] **Step 5: Check the numbers against the hand count**

Vorlage „Status effect on turn share by pipeline“ im Browser öffnen (Desktop 1280 px und 375 px). Erwartete Mittelwerte (aus `job_logs`, 12.10.2026, erste 60 Turns = ganzer Lauf bei Budget 60):

| Pipeline | ohne Titel | mit Titel |
|---|---|---|
| Score | 25,0 % | 56,7 % |
| Orchestrator | 7,9 % | 42,5 % |
| Quality | 36,3 % | 85,0 % |
| Round Robin | 25,0 % | 25,0 % |
| Quality blind | 22,5 % | 66,3 % |

Die Reihenfolge der Gruppen folgt der Position der Tag-Werte (`status` vor `no-status` heißt Delta = no-status − status, also negativ). Wenn das Delta „mit minus ohne“ lauten soll, die Werte unter Settings → Tags umsortieren; dem Nutzer die Wahl lassen.

- [ ] **Step 6: Reviews**

Skill `anthropic-skills:ux-review` (quick) auf `/statistics` im Browser; Skill `anthropic-skills:clean-code-review` (full) auf den Diff `git diff 683775e..HEAD -- app resources database`. Befunde mit Schweregrad Hoch/Major beheben, je Fix ein Commit; den Rest dem Nutzer berichten.

- [ ] **Step 7: Abschluss**

`php artisan test` und `vendor/bin/pint --test` grün; Ergebnis mit Ausgabe berichten. Merge-Entscheidung bleibt beim Nutzer (superpowers:finishing-a-development-branch).
