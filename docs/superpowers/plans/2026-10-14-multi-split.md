# Split by two dimensions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A statistics question may split its answer by up to two dimensions (e.g. pipeline and the `seat test` tag), shown as a grid of panels: a row per value of the first dimension, a column per value of the second.

**Architecture:** `QuestionDefinition::$splitBy` becomes a list of at most two `Dimension`s (a stored single key still reads). `QuestionEvaluator` places each run in the panel of its value combination; a `Panel` carries its row and column, `QuestionResult` the columns in dimension order. A small view model `PanelGrid` lays panels out as rows × columns for the point and curve forms; composition bars and the run table use the combined label ("Quality · seat 1"). The split menu becomes a checkbox group.

**Tech Stack:** Laravel 12, Livewire 3, Flux / Flux Pro, Blade, PHPUnit 11 (`php artisan test`), Pint.

**Spec:** No separate spec document. The design was agreed in the session of 14.10.2026 and is summarised here:
- at most two split dimensions; the first gives the rows, the second the columns;
- every value combination with runs is a panel; a combination without runs is an empty, dashed cell in the grid and does not appear elsewhere;
- a run without a value in either split dimension drops out (as today with one split);
- the rules for a single split stay: no persona tag, never the `compare_by` dimension, no dimension twice;
- the sentence reads "split by Pipeline and Seat test";
- all three answer forms work (points and curves as a grid, composition bars and the run table with the combined label);
- a question saved with a single `split_by` string still opens and answers unchanged; bundles from before still import;
- afterwards questions 37 and 38 (seat test per pipeline) become one question.

## Global Constraints

- Every user-facing string exists in `lang/en/questions.php` **and** `lang/de/questions.php`.
- UI uses Flux defaults (`flux:menu.checkbox.group`, `flux:card`, `flux:heading`); clickable elements keep the hand cursor (global rule in `resources/css/app.css`).
- Layout works at phone width: the grid collapses to one column below `sm` (points) / `lg` (curves).
- `QuestionDefinition::fromArray()` stays the single place that normalises a definition; nothing else validates `split_by`.
- Polymorphic and dimension keys are unchanged (`pipeline`, `model`, `group`, `project_tag:{id}`).
- Run `vendor/bin/pint --dirty` before each commit; the full suite (`php artisan test`) must stay green after every task.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Branch: `Studie-JanNiclas-Loosen`.

---

### Task 0: Commit the pending spread band

The working tree holds the uncommitted spread band (± σ) of the point strip from earlier today. Commit it on its own so the split work starts from a clean tree. **Ask the user before this commit** (they have not yet said to commit it).

**Files (already changed):** `app/Discussion/Values/GroupResult.php`, `resources/views/components/statistics/point-panels.blade.php`, `lang/{de,en}/questions.php`, `tests/Unit/Discussion/GroupResultTest.php`, `tests/Feature/Statistics/QuestionEditorTest.php`, `CLAUDE.md`

- [ ] **Step 1:** `php artisan test` → all green.
- [ ] **Step 2:** Commit:

```bash
git add app/Discussion/Values/GroupResult.php resources/views/components/statistics/point-panels.blade.php lang/de/questions.php lang/en/questions.php tests/Unit/Discussion/GroupResultTest.php tests/Feature/Statistics/QuestionEditorTest.php CLAUDE.md
git commit -m "Show a band of one standard deviation around each group's mean in the point strip

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1: `split_by` becomes a list of up to two dimensions

Everything that reads `splitBy` moves to the list in one task, so the suite stays green: the definition, the evaluator (one panel per combination, combined label), the sentence and the bundle translation.

**Files:**
- Modify: `app/Discussion/Values/QuestionDefinition.php`
- Modify: `app/Discussion/Values/Panel.php` (two constants)
- Modify: `app/Discussion/Metrics/Questions/QuestionEvaluator.php` (`evaluate`, `placeOf`, `panels`; new `panelOf`, `splitLabels`, `combinations`)
- Modify: `app/Livewire/Statistics/QuestionSentence.php` (`prime`, `parts`; new `dimensions`)
- Modify: `app/Services/Transfer/DefinitionNames.php` (`toNames`, `toIds`; new `storedSplits`, `splitNames`)
- Modify: `lang/en/questions.php`, `lang/de/questions.php` (`sentence.and`)
- Test: `tests/Unit/Discussion/QuestionDefinitionTest.php`, `tests/Unit/Discussion/QuestionEvaluatorTest.php`, `tests/Feature/Statistics/QuestionSentenceTest.php`, `tests/Feature/Transfer/GroupAndQuestionTransferTest.php`, `tests/Feature/Statistics/QuestionEditorTest.php`

**Interfaces:**
- Produces:
  - `QuestionDefinition::MAX_SPLITS = 2`
  - `QuestionDefinition::$splitBy` — `list<Dimension>` (was `?Dimension`); `toArray()['split_by']` is `list<string>` (was `string`)
  - `Panel::KEY_SEPARATOR = '|'`, `Panel::LABEL_SEPARATOR = ' · '`
  - `Panel::$key` of a combination: the value keys joined with `|` (`"QualityPipeline|10"`); with one split exactly the value key as today, without a split `''`
  - private `QuestionEvaluator::splitLabels(DimensionValues, list<Dimension>, array<string, list<int|string>>): list<array<int|string, string>>` — per split dimension the labels of the values that occur, in that dimension's order (Task 2 reads index 1 for the columns)
  - private `QuestionEvaluator::combinations(list<array<int|string, string>>): list<list<array{0: int|string, 1: string}>>`

- [ ] **Step 1: Write the failing definition tests**

In `tests/Unit/Discussion/QuestionDefinitionTest.php`:

Line ~28: `$this->assertNull($question->splitBy);` → `$this->assertSame([], $question->splitBy);`

In `test_a_saved_question_reads_back_unchanged` change the fixture line `'split_by' => 'pipeline',` to `'split_by' => ['pipeline', 'project_tag:4'],`.

Replace `test_panels_never_split_agents_nor_repeat_the_compare_dimension` with:

```php
    public function test_panels_never_split_agents_nor_repeat_the_compare_dimension(): void
    {
        $this->assertSame([], QuestionDefinition::fromArray(['split_by' => 'persona_tag:3'])->splitBy);
        $this->assertSame([], QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'split_by' => 'pipeline'])->splitBy);
        $this->assertSame(['model'], QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'split_by' => 'model'])->toArray()['split_by']);
    }

    public function test_split_by_reads_a_single_saved_key_or_a_list_of_up_to_two(): void
    {
        $this->assertSame(['pipeline'], QuestionDefinition::fromArray(['split_by' => 'pipeline'])->toArray()['split_by']);
        $this->assertSame([], QuestionDefinition::fromArray(['split_by' => ''])->toArray()['split_by']);
        $this->assertSame(['project_tag:6', 'pipeline'], QuestionDefinition::fromArray(['split_by' => ['project_tag:6', 'pipeline']])->toArray()['split_by']);
        $this->assertSame(['project_tag:6', 'pipeline'], QuestionDefinition::fromArray(['split_by' => ['project_tag:6', 'pipeline', 'model']])->toArray()['split_by']);
    }

    public function test_split_by_drops_persona_tags_the_compare_dimension_repeats_and_junk(): void
    {
        $question = QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'split_by' => ['persona_tag:3', 'pipeline', 7, 'model', 'model', 'group']]);

        $this->assertSame(['model', 'group'], $question->toArray()['split_by']);
    }

    public function test_without_groups_keeps_the_other_split(): void
    {
        $this->assertSame(['pipeline'], QuestionDefinition::fromArray(['split_by' => ['group', 'pipeline']])->withoutGroups()->toArray()['split_by']);
    }
```

In `test_a_group_is_a_dimension_to_compare_or_split_by_and_a_filter` replace the two `splitBy` lines with:

```php
        $this->assertSame(['group'], QuestionDefinition::fromArray(['split_by' => 'group'])->toArray()['split_by']);
        $this->assertSame([], QuestionDefinition::fromArray(['compare_by' => 'group', 'split_by' => 'group'])->splitBy);
```

- [ ] **Step 2: Run and see them fail**

Run: `php artisan test --filter=QuestionDefinitionTest`
Expected: FAIL (`split_by` is a string, `splitBy` null/object).

- [ ] **Step 3: Implement the list in `QuestionDefinition`**

Class docblock: `split by [dimension]` → `split by [up to two dimensions]`, and the bullet becomes:

```php
 * - Panels sort runs, so a split dimension is never a persona tag, nor the
 *   dimension compareBy already uses, nor named twice; at most MAX_SPLITS. A
 *   question saved before splits were lists names one key, which still reads.
```

Constructor parameter (replace `public ?Dimension $splitBy = null,`):

```php
        /** @var list<Dimension> the panels' dimensions: the first gives the rows, the second the columns */
        public array $splitBy = [],
```

Above the constructor:

```php
    /** At most this many dimensions split an answer: the rows and the columns of its panels. */
    public const MAX_SPLITS = 2;
```

In `fromArray()` delete `$splitBy = Dimension::fromKey($data['split_by'] ?? null);` and the `if ($splitBy?->isPersonaLevel() ...)` block; after the Gini block add:

```php
        $splitBy = self::splits($data['split_by'] ?? [], $compareBy);
```

New private static method:

```php
    /**
     * The split dimensions as asked: a list of keys, or one key as saved
     * before splits were lists.
     *
     * @return list<Dimension>
     */
    private static function splits(mixed $keys, ?Dimension $compareBy): array
    {
        $splits = [];

        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            $dimension = Dimension::fromKey($key);

            if ($dimension !== null && ! $dimension->isPersonaLevel() && $dimension != $compareBy && ! in_array($dimension, $splits)) {
                $splits[] = $dimension;
            }
        }

        return array_slice($splits, 0, self::MAX_SPLITS);
    }
```

`toArray()`: `'split_by' => array_map(fn (Dimension $dimension) => $dimension->key(), $this->splitBy),`

`withoutGroups()`: `splitBy: array_values(array_filter($this->splitBy, fn (Dimension $dimension) => $dimension->type !== DimensionType::Group)),`

- [ ] **Step 4: Run the definition tests**

Run: `php artisan test --filter=QuestionDefinitionTest`
Expected: PASS. (Other suites fail until Steps 5–12.)

- [ ] **Step 5: Write the failing evaluator test**

Add to `tests/Unit/Discussion/QuestionEvaluatorTest.php`:

```php
    public function test_two_splits_give_a_panel_per_combination_in_dimension_order(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $one = $seat->values()->create(['name' => 'seat 1', 'position' => 1]);
        $three = $seat->values()->create(['name' => 'seat 3', 'position' => 2]);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $three);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $one);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline'])->setTag($seat, $one);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline']); // no seat: drops out

        $result = $this->answer(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]);

        $pipelines = app(PipelineRegistry::class)->options();
        $byPipeline = [
            'OrchestratorPipeline' => ["OrchestratorPipeline|{$one->id}" => $pipelines['OrchestratorPipeline'].' · seat 1'],
            'QualityPipeline' => [
                "QualityPipeline|{$one->id}" => $pipelines['QualityPipeline'].' · seat 1',
                "QualityPipeline|{$three->id}" => $pipelines['QualityPipeline'].' · seat 3',
            ],
        ];
        $expected = array_merge(...array_values(array_intersect_key(array_replace(array_flip(array_keys($pipelines)), $byPipeline), $byPipeline)));

        $this->assertSame($expected, array_column(array_map(fn ($panel) => ['key' => $panel->key, 'label' => $panel->label], $result->panels), 'label', 'key'));
        $this->assertSame(1, $result->excluded);
        $this->assertSame([1, 1, 1], array_map(fn ($panel) => $panel->runs, $result->panels));
    }
```

(`$expected` lists the panels in the registry's pipeline order, seat 1 before seat 3 inside each pipeline.)

- [ ] **Step 6: Run it to see it fail**

Run: `php artisan test --filter=test_two_splits_give_a_panel_per_combination_in_dimension_order`
Expected: FAIL (type error on `splitBy` or wrong panels).

- [ ] **Step 7: Implement combinations in `QuestionEvaluator`**

In `Panel` add:

```php
    /** Joins a panel's value keys, one per split dimension: "QualityPipeline|10". */
    public const KEY_SEPARATOR = '|';

    /** Joins a panel's value labels: "Quality · seat 1". */
    public const LABEL_SEPARATOR = ' · ';
```

and change the class docblock to `/** One panel of an answer: a combination of the split dimensions' values, or the whole answer when nothing is split. */`

In `placeOf()` replace the first line with:

```php
        $parts = $this->panelOf($question->splitBy, $project, $values);
        $report = $parts === null ? null : $this->shares->forProject($project);
```

and return

```php
        return $groups === [] ? null : [
            'panel' => implode(Panel::KEY_SEPARATOR, $parts),
            'parts' => $parts,
            'groups' => array_map(fn (array $speakers) => array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $speakers), $groups),
        ];
```

Update its `@return` to `array{panel: string, parts: list<int|string>, groups: array<int|string, list<int>>}|null`.

New method:

```php
    /**
     * The run's value in every split dimension, in their order; empty without
     * a split. Null when the run lacks a value in one of them.
     *
     * @param  list<Dimension>  $splitBy
     * @return list<int|string>|null
     */
    private function panelOf(array $splitBy, Project $project, DimensionValues $values): ?array
    {
        $parts = [];

        foreach ($splitBy as $dimension) {
            $key = $values->ofProject($dimension, $project);

            if ($key === null) {
                return null;
            }

            $parts[] = $key;
        }

        return $parts;
    }
```

In `evaluate()` add `/** @var array<string, list<int|string>> $panelParts panel => its value in each split dimension */ $panelParts = [];` next to `$panelRuns`, and directly after `$panelRuns[$place['panel']] = ...;` add `$panelParts[$place['panel']] = $place['parts'];`. Change the `panels(...)` call to `$this->panels($values, $question, $collected, $panelParts, $groupLabels, $panelRuns)`.

Replace `panels()` with:

```php
    /**
     * @param  array<int|string, array<int|string, GroupCollector>>  $collected
     * @param  array<string, list<int|string>>  $panelParts
     * @param  array<int|string, string>  $groupLabels
     * @param  array<int|string, int>  $panelRuns
     * @return list<Panel>
     */
    private function panels(DimensionValues $values, QuestionDefinition $question, array $collected, array $panelParts, array $groupLabels, array $panelRuns): array
    {
        $panels = [];

        foreach ($this->combinations($this->splitLabels($values, $question->splitBy, $panelParts)) as $combination) {
            $panelKey = implode(Panel::KEY_SEPARATOR, array_column($combination, 0));

            if (! isset($collected[$panelKey])) {
                continue;
            }

            $groups = [];

            foreach ($groupLabels as $groupKey => $groupLabel) {
                if (isset($collected[$panelKey][$groupKey])) {
                    $groups[] = $collected[$panelKey][$groupKey]->result((string) $groupKey, $groupLabel);
                }
            }

            $panels[] = new Panel($panelKey, implode(Panel::LABEL_SEPARATOR, array_column($combination, 1)), $groups, $panelRuns[$panelKey], count($groupLabels));
        }

        return $panels;
    }

    /**
     * Per split dimension the labels of its values that occur in a panel, in
     * the dimension's own order.
     *
     * @param  list<Dimension>  $splitBy
     * @param  array<string, list<int|string>>  $panelParts
     * @return list<array<int|string, string>>
     */
    private function splitLabels(DimensionValues $values, array $splitBy, array $panelParts): array
    {
        return array_map(
            fn (Dimension $dimension, int $index) => $this->labels($values, $dimension, array_values(array_unique(array_column($panelParts, $index)))),
            $splitBy,
            array_keys($splitBy),
        );
    }

    /**
     * Every combination of the dimensions' values, the first dimension
     * varying slowest; one empty combination without a split.
     *
     * @param  list<array<int|string, string>>  $splitLabels
     * @return list<list<array{0: int|string, 1: string}>> per combination its [value key, label] per dimension
     */
    private function combinations(array $splitLabels): array
    {
        $combinations = [[]];

        foreach ($splitLabels as $labels) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($labels as $key => $label) {
                    $next[] = [...$combination, [$key, $label]];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }
```

`labels()` keeps its signature but takes a `Dimension` (no longer `?Dimension` from the split side); the compare side still passes `?Dimension`, so leave the parameter nullable.

- [ ] **Step 8: Run the evaluator tests**

Run: `php artisan test --filter=QuestionEvaluatorTest`
Expected: PASS, including the existing single-split tests (`test_split_by_pipeline_gives_panels_in_registry_order`, `test_split_by_group_gives_a_panel_per_group`), whose panel keys are unchanged.

- [ ] **Step 9: Sentence — failing test, then implementation**

In `tests/Feature/Statistics/QuestionSentenceTest.php` add:

```php
    public function test_two_splits_read_as_one_phrase_in_their_order(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);

        $parts = app(QuestionSentence::class)->parts(QuestionDefinition::fromArray(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]));

        $this->assertSame('Pipeline and Seat test', $parts['split_by']);
    }
```

Run `php artisan test --filter=test_two_splits_read_as_one_phrase_in_their_order` → FAIL.

`lang/en/questions.php`, inside `'sentence' => [`: `'and' => 'and',` — `lang/de/questions.php`: `'and' => 'und',`

In `QuestionSentence::prime()`: `foreach ([$definition->compareBy, ...$definition->splitBy] as $dimension) {`

In `parts()`: `'split_by' => $question->splitBy === [] ? null : $this->dimensions($question->splitBy),`

New method:

```php
    /** @param  non-empty-list<Dimension>  $dimensions */
    private function dimensions(array $dimensions): string
    {
        return implode(' '.__('questions.sentence.and').' ', array_map(fn (Dimension $dimension) => $this->dimension($dimension), $dimensions));
    }
```

Run `php artisan test --filter=QuestionSentenceTest` → PASS.

- [ ] **Step 10: Bundle translation — failing tests, then implementation**

In `tests/Feature/Transfer/GroupAndQuestionTransferTest.php`, `test_split_by_and_the_other_baseline_kinds_round_trip`: the two `split_by` assertions become lists:

```php
        $this->assertSame(['persona_tag:focus persona'], $tagged['split_by']);
        ...
        $this->assertSame(["persona_tag:{$persona->id}"], $back['split_by']);
```

and add:

```php
    public function test_two_splits_travel_by_name_and_a_single_old_one_still_reads(): void
    {
        $user = User::factory()->create();
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $names = new DefinitionNames($user);

        $named = $names->toNames(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]);
        $this->assertSame(['pipeline', 'project_tag:seat test'], $named['split_by']);
        $this->assertSame(['pipeline', "project_tag:{$seat->id}"], $names->toIds($named)['split_by']);

        $this->assertSame(["project_tag:{$seat->id}"], $names->toIds(['split_by' => 'project_tag:seat test'])['split_by']);
        $this->assertSame(['pipeline'], $names->toNames(['split_by' => 'pipeline'])['split_by']);
        $this->assertSame([], $names->toIds(['split_by' => ['project_tag:gone']])['split_by']);
    }
```

If the fixture near line 63 (`'split_by' => 'pipeline'`) feeds a round trip that compares the result with the stored definition, change the expectation to `['pipeline']` (a re-imported definition is stored normalised).

Run `php artisan test --filter=GroupAndQuestionTransferTest` → FAIL.

In `DefinitionNames::toNames()`:

```php
        $named['split_by'] = array_values(array_filter(array_map(fn ($key) => $this->dimensionToName((string) $key), self::storedSplits($definition['split_by'] ?? [])), fn (string $name) => $name !== ''));
```

In `toIds()`:

```php
        $definition['split_by'] = array_values(array_filter(array_map(fn (string $name) => $this->dimensionToKey($name), $this->splitNames($named)), fn (string $key) => $key !== ''));
```

New methods:

```php
    /**
     * A stored definition's split keys: a list, or one key as saved before
     * splits were lists.
     *
     * @return list<mixed>
     */
    private static function storedSplits(mixed $splitBy): array
    {
        return is_array($splitBy) ? array_values($splitBy) : array_values(array_filter([$splitBy], fn ($key) => $key !== null && $key !== ''));
    }

    /**
     * A file's split names: a list of texts, or one text from a bundle written
     * before splits were lists. Anything else is UnreadableEntry.
     *
     * @return list<string>
     */
    private function splitNames(array $named): array
    {
        return is_array($named['split_by'] ?? null)
            ? EntryFields::texts($named, 'split_by')
            : array_values(array_filter([EntryFields::text($named, 'split_by') ?? ''], fn (string $name) => $name !== ''));
    }
```

Run `php artisan test --filter=GroupAndQuestionTransferTest` → PASS.

- [ ] **Step 11: Editor test follows the list**

In `tests/Feature/Statistics/QuestionEditorTest.php` around line 452: `->set('definition.split_by', 'pipeline')` → `->set('definition.split_by', ['pipeline'])`, and `$this->assertSame('pipeline', $saved['split_by']);` → `$this->assertSame(['pipeline'], $saved['split_by']);`

The menu in `question-editor.blade.php` still binds a radio group to `definition.split_by`; it renders but cannot pick two yet — Task 4 replaces it. Leave it for now.

- [ ] **Step 12: Full suite, Pint, commit**

Run: `php artisan test` → all green. `vendor/bin/pint --dirty`.

```bash
git add app/Discussion/Values/QuestionDefinition.php app/Discussion/Values/Panel.php app/Discussion/Metrics/Questions/QuestionEvaluator.php app/Livewire/Statistics/QuestionSentence.php app/Services/Transfer/DefinitionNames.php lang/en/questions.php lang/de/questions.php tests/Unit/Discussion/QuestionDefinitionTest.php tests/Unit/Discussion/QuestionEvaluatorTest.php tests/Feature/Statistics/QuestionSentenceTest.php tests/Feature/Transfer/GroupAndQuestionTransferTest.php tests/Feature/Statistics/QuestionEditorTest.php
git commit -m "Let a question split by up to two dimensions, a panel per value combination

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Panels know their row and column

**Files:**
- Modify: `app/Discussion/Values/Panel.php`
- Modify: `app/Discussion/Values/QuestionResult.php`
- Modify: `app/Discussion/Metrics/Questions/QuestionEvaluator.php` (`evaluate`, `panels`)
- Test: `tests/Unit/Discussion/QuestionEvaluatorTest.php`

**Interfaces:**
- Consumes: `splitLabels()`, `combinations()`, `Panel::KEY_SEPARATOR` from Task 1.
- Produces:
  - `Panel` gains four trailing constructor parameters, all `string`, default `''`: `$row`, `$rowLabel`, `$column`, `$columnLabel` — set only with two splits (`row`/`column` are the value keys as strings).
  - `QuestionResult` gains a trailing `public array $columns = []` — `array<int|string, string>`, the second split dimension's values that occur (key => label) in its order; `[]` with fewer than two splits.

- [ ] **Step 1: Write the failing test**

```php
    public function test_two_splits_place_each_panel_in_a_row_and_a_column(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $one = $seat->values()->create(['name' => 'seat 1', 'position' => 1]);
        $three = $seat->values()->create(['name' => 'seat 3', 'position' => 2]);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $three);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline'])->setTag($seat, $one);

        $result = $this->answer(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]);
        $quality = collect($result->panels)->firstWhere('key', "QualityPipeline|{$three->id}");

        $this->assertSame('QualityPipeline', $quality->row);
        $this->assertSame(app(PipelineRegistry::class)->options()['QualityPipeline'], $quality->rowLabel);
        $this->assertSame((string) $three->id, $quality->column);
        $this->assertSame('seat 3', $quality->columnLabel);
        $this->assertSame([$one->id => 'seat 1', $three->id => 'seat 3'], $result->columns);

        $single = $this->answer(['split_by' => 'pipeline']);
        $this->assertSame([], $single->columns);
        $this->assertSame('', $single->panels[0]->row);
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --filter=test_two_splits_place_each_panel_in_a_row_and_a_column`
Expected: FAIL (`Undefined property: Panel::$row`).

- [ ] **Step 3: Implement**

`Panel` constructor, after `public int $compareCount = 0,`:

```php
        /** With two splits: the value key and label of the first dimension (the panel's row) and of the second (its column); '' otherwise. */
        public string $row = '',
        public string $rowLabel = '',
        public string $column = '',
        public string $columnLabel = '',
```

`QuestionResult` constructor, after `public ?string $baseline = null,`:

```php
        /** With two splits: the second dimension's values that occur, key => label, in its order — the columns of the panel grid. */
        public array $columns = [],
```

In `QuestionEvaluator::evaluate()` compute the split labels once and hand them to `panels()`:

```php
        $splitLabels = $this->splitLabels($values, $question->splitBy, $panelParts);
```

call `$this->panels($collected, $splitLabels, $groupLabels, $panelRuns)` with the new signature `private function panels(array $collected, array $splitLabels, array $groupLabels, array $panelRuns): array` (`@param list<array<int|string, string>> $splitLabels`; `$values`, `$question` and `$panelParts` are no longer needed there), and add the named argument `columns: count($splitLabels) === 2 ? $splitLabels[1] : [],` to `new QuestionResult(...)`.

In `panels()` iterate `$this->combinations($splitLabels)` and build:

```php
            $grid = count($combination) === 2
                ? ['row' => (string) $combination[0][0], 'rowLabel' => $combination[0][1], 'column' => (string) $combination[1][0], 'columnLabel' => $combination[1][1]]
                : [];

            $panels[] = new Panel($panelKey, implode(Panel::LABEL_SEPARATOR, array_column($combination, 1)), $groups, $panelRuns[$panelKey], count($groupLabels), ...$grid);
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=QuestionEvaluatorTest` → PASS; `php artisan test` → green.

- [ ] **Step 5: Commit**

```bash
git add app/Discussion/Values/Panel.php app/Discussion/Values/QuestionResult.php app/Discussion/Metrics/Questions/QuestionEvaluator.php tests/Unit/Discussion/QuestionEvaluatorTest.php
git commit -m "Give each panel of a two-way split its row and column

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Points and curves as a grid

**Files:**
- Create: `app/Livewire/Statistics/PanelGrid.php`
- Create: `resources/views/components/statistics/point-card.blade.php`
- Create: `resources/views/components/statistics/curve-card.blade.php`
- Modify: `resources/views/components/statistics/point-panels.blade.php`
- Modify: `resources/views/components/statistics/curve-panels.blade.php`
- Test: `tests/Feature/Statistics/PanelGridTest.php`, `tests/Feature/Statistics/QuestionEditorTest.php`

**Interfaces:**
- Consumes: `Panel::$row/$rowLabel/$column/$columnLabel`, `QuestionResult::$columns` from Task 2.
- Produces: `PanelGrid::rows(QuestionResult $result): list<array{key: string, label: string, cells: list<?Panel>}>` — one entry per row in panel order, `cells` in the order of `$result->columns`, `null` where no run has that combination.

- [ ] **Step 1: Write the failing `PanelGrid` test**

`tests/Feature/Statistics/PanelGridTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\RunValue;
use App\Livewire\Statistics\PanelGrid;
use Tests\TestCase;

class PanelGridTest extends TestCase
{
    public function test_a_row_per_first_value_a_cell_per_column_and_a_gap_where_no_run_is(): void
    {
        $group = new GroupResult('', '', [new RunValue(1, 'One', 0.5, null)]);
        $a1 = new Panel('a|1', 'A · one', [$group], 1, 1, 'a', 'A', '1', 'one');
        $b2 = new Panel('b|2', 'B · two', [$group], 1, 1, 'b', 'B', '2', 'two');
        $result = new QuestionResult([$a1, $b2], ['' => ''], 2, 0, columns: [1 => 'one', 2 => 'two']);

        $rows = PanelGrid::rows($result);

        $this->assertSame(['a', 'b'], array_column($rows, 'key'));
        $this->assertSame(['A', 'B'], array_column($rows, 'label'));
        $this->assertSame([$a1, null], $rows[0]['cells']);
        $this->assertSame([null, $b2], $rows[1]['cells']);
    }
}
```

Run `php artisan test --filter=PanelGridTest` → FAIL (class missing).

- [ ] **Step 2: Implement `PanelGrid`**

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionResult;

/**
 * The panels of an answer split by two dimensions, as a grid: a row per value
 * of the first, a cell per value of the second, null where no run has that
 * combination, so every column lines up. Rows and columns keep their
 * dimensions' order.
 */
final class PanelGrid
{
    /** @return list<array{key: string, label: string, cells: list<?Panel>}> */
    public static function rows(QuestionResult $result): array
    {
        $empty = array_fill_keys(array_map('strval', array_keys($result->columns)), null);
        $rows = [];

        foreach ($result->panels as $panel) {
            $rows[$panel->row] ??= ['key' => $panel->row, 'label' => $panel->rowLabel, 'cells' => $empty];
            $rows[$panel->row]['cells'][$panel->column] = $panel;
        }

        return array_values(array_map(fn (array $row) => [...$row, 'cells' => array_values($row['cells'])], $rows));
    }
}
```

Run `php artisan test --filter=PanelGridTest` → PASS.

- [ ] **Step 3: Extract the point card**

Create `resources/views/components/statistics/point-card.blade.php` holding exactly the current `<flux:card …>…</flux:card>` block from `point-panels.blade.php` (everything inside `@foreach ($result->panels as $panel)`), with these props and changes:

```blade
@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
    'panel', {{-- Panel --}}
    'heading', {{-- string: the panel's label, or in a grid only its column --}}
    'scale', {{-- float: the value at the right end of every track; shared by all panels --}}
])

@php($at = fn (float $value) => max(0, min(100, $value / $scale * 100)))

<flux:card size="sm" class="p-3" wire:key="points-{{ $panel->key }}">
    @if ($heading !== '')
        <flux:heading size="sm">{{ $heading }}</flux:heading>
    @endif
    {{-- … the unchanged group rows, band, fair share, mean, dots and delta … --}}
</flux:card>
```

(Move the group loop and the delta text over verbatim; only `$panel->label` in the heading becomes `$heading`.)

- [ ] **Step 4: Point panels: flat list or grid**

Replace the body of `point-panels.blade.php` after its docblock with:

```blade
@use('App\Livewire\Statistics\PanelGrid')

@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))

@if ($result->columns === [])
    <div {{ $attributes->class('grid gap-4 sm:grid-cols-2 xl:grid-cols-3') }}>
        @foreach ($result->panels as $panel)
            <x-statistics.point-card :result="$result" :metric="$metric" :panel="$panel" :heading="$panel->label" :scale="$scale" />
        @endforeach
    </div>
@else
    {{-- Two splits: a row per value of the first, a column per value of the second; a dashed cell where no run has the combination. One column on phones. --}}
    <div {{ $attributes->class('space-y-4') }} style="--columns: {{ count($result->columns) }}">
        @foreach (PanelGrid::rows($result) as $row)
            <section wire:key="points-row-{{ $row['key'] }}">
                <flux:heading size="sm" class="mb-2">{{ $row['label'] }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-[repeat(var(--columns),minmax(0,1fr))]">
                    @foreach ($row['cells'] as $panel)
                        @if ($panel === null)
                            <div class="hidden rounded-lg border border-dashed border-zinc-200 sm:block dark:border-zinc-700" aria-hidden="true"></div>
                        @else
                            <x-statistics.point-card :result="$result" :metric="$metric" :panel="$panel" :heading="$panel->columnLabel" :scale="$scale" />
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
```

Keep the `@props` and the docblock at the top; extend the docblock with one sentence: `With two splits the panels form a grid: rows are the first dimension's values, columns the second's.`

- [ ] **Step 5: Curve card and curve panels**

Create `resources/views/components/statistics/curve-card.blade.php` with the current `<flux:card …>` block from `curve-panels.blade.php` (including `@php($rows = CurveRows::of($panel))`), props `result`, `panel`, `heading`; the heading uses `$heading` instead of `$panel->label`. Then `curve-panels.blade.php` becomes the same flat/grid switch as in Step 4, with `lg:` instead of `sm:` (`lg:grid-cols-2` for the flat list, `lg:grid-cols-[repeat(var(--columns),minmax(0,1fr))]` and `hidden … lg:block` for the grid), `wire:key="curves-row-{{ $row['key'] }}"`, and `<x-statistics.curve-card :result="$result" :panel="$panel" :heading="…" />`.

- [ ] **Step 6: Feature test for the grid**

Add to `tests/Feature/Statistics/QuestionEditorTest.php`:

```php
    public function test_two_splits_show_the_panels_as_rows_and_columns(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $one = $seat->values()->create(['name' => 'seat 1', 'position' => 1]);
        $three = $seat->values()->create(['name' => 'seat 3', 'position' => 2]);
        $this->spokenRun($user)->setTag($seat, $one);
        $this->spokenRun($user)->setTag($seat, $three);
        $question = $user->questions()->create(['definition' => ['split_by' => ['pipeline', "project_tag:{$seat->id}"]]]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSeeHtml('--columns: 2')
            ->assertSeeHtml('points-row-')
            ->assertSee('seat 1')
            ->assertSee('seat 3');
    }
```

(`spokenRun()` creates projects with the factory's pipeline, so both runs share one row.)

Run: `php artisan test --filter='PanelGridTest|QuestionEditorTest'` → PASS; `php artisan test` → green.

- [ ] **Step 7: Check in the browser**

`npm run build`, open `http://127.0.0.1:8000/statistics?q=37` after setting its `split_by` to `['pipeline', 'project_tag:6']` and its pipelines filter to `['QualityPipeline', 'OrchestratorPipeline']` (tinker), and check: two rows with headings, two columns "seat 1"/"seat 3", band tooltips still work, "Over the run" shows the same grid, "Shares" stays disabled for this question; at 390 px width the cells stack. (Task 6 makes this change for good.)

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Statistics/PanelGrid.php resources/views/components/statistics/point-card.blade.php resources/views/components/statistics/curve-card.blade.php resources/views/components/statistics/point-panels.blade.php resources/views/components/statistics/curve-panels.blade.php tests/Feature/Statistics/PanelGridTest.php tests/Feature/Statistics/QuestionEditorTest.php
git commit -m "Lay out a two-way split as rows and columns of panels

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Pick two dimensions in the split menu

**Files:**
- Modify: `resources/views/livewire/statistics/question-editor.blade.php:203-216`
- Modify: `lang/en/questions.php`, `lang/de/questions.php` (`controls.split_hint`)
- Test: `tests/Feature/Statistics/QuestionEditorTest.php`

**Interfaces:**
- Consumes: `QuestionDefinition::MAX_SPLITS`, `toArray()['split_by']` as a list (Task 1).

- [ ] **Step 1: Write the failing test**

```php
    public function test_the_split_menu_takes_two_dimensions_and_names_both(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.split_by', ['pipeline', 'model'])
            ->assertSet('definition.split_by', ['pipeline', 'model'])
            ->assertSee('Pipeline and Model')
            ->assertSee('Up to two: the first gives the rows, the second the columns.');

        $this->assertSame(['pipeline', 'model'], $question->fresh()->definition['split_by']);
    }
```

Run → FAIL (hint text missing).

- [ ] **Step 2: Replace the radio group by a checkbox group**

Replace the `<flux:menu>…</flux:menu>` inside the split chip with:

```blade
            <flux:menu keep-open>
                {{-- Up to MAX_SPLITS: once two are ticked the others are disabled. Unticking all splits nothing. --}}
                @php($splits = $definition['split_by'] ?? [])
                <flux:menu.checkbox.group wire:model.live="definition.split_by">
                    @foreach ($splitOptions as $key => $label)
                        @unless ($key === ($definition['compare_by'] ?? ''))
                            <flux:menu.checkbox :value="$key" wire:key="split-{{ $key }}"
                                :disabled="count($splits) >= \App\Discussion\Values\QuestionDefinition::MAX_SPLITS && ! in_array($key, $splits, true)">{{ $label }}</flux:menu.checkbox>
                        @endunless
                    @endforeach
                </flux:menu.checkbox.group>
                <flux:menu.separator />
                <flux:text size="sm" class="px-2 py-1">{{ __('questions.controls.split_hint') }}</flux:text>
            </flux:menu>
```

`lang/en/questions.php`, in `'controls' => [`: `'split_hint' => 'Up to two: the first gives the rows, the second the columns.',`
`lang/de/questions.php`: `'split_hint' => 'Bis zu zwei: die erste ergibt die Zeilen, die zweite die Spalten.',`

- [ ] **Step 3: Run tests**

Run: `php artisan test --filter=QuestionEditorTest` → PASS; `php artisan test` → green.

- [ ] **Step 4: Check in the browser**

On an own question: tick Pipeline, then Seat test → sentence "split by Pipeline and Seat test", grid appears; the remaining options are disabled; untick Pipeline → "split by Seat test", flat panels; choose Pipeline as "compared by" → it disappears from the split menu and from `split_by`. Note which order Flux keeps for the ticked values (tick order or menu order) and, if it is menu order, change the hint to "the upper one in this list gives the rows" in both languages.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/statistics/question-editor.blade.php lang/en/questions.php lang/de/questions.php tests/Feature/Statistics/QuestionEditorTest.php
git commit -m "Choose up to two split dimensions in the split menu

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Documentation and reviews

**Files:**
- Modify: `CLAUDE.md` (Statistics page section)

- [ ] **Step 1: Update `CLAUDE.md`**

In the Statistics page paragraph:
- the sentence template `…, split by [dimension]` → `…, split by [up to two dimensions]`;
- after "`split_by` is never a persona tag nor the `compare_by` dimension" add: "and holds at most `QuestionDefinition::MAX_SPLITS` (2) dimensions, stored as a list (a question or bundle saved with a single key still reads); each value combination with runs is a panel (`Panel::$key` joins the value keys with `|`, the label the labels with ` · `), and with two splits `Panel::$row/$column` and `QuestionResult::$columns` lay points and curves out as a grid (`Statistics\PanelGrid`, cards `x-statistics.point-card`/`curve-card`, a dashed cell for a combination without runs); composition bars and the run table use the combined label";
- in the UI description: "`menu.radio.group` for metric, aggregate, compare/split" → "`menu.radio.group` for metric, aggregate and compare, `menu.checkbox.group` for split (the third choice disabled once two are ticked)".

- [ ] **Step 2: Full suite and Pint**

Run: `php artisan test` → green; `vendor/bin/pint --dirty`.

- [ ] **Step 3: Reviews**

Run the clean-code review (`anthropic-skills:clean-code-review`, full) over the diff of Tasks 1–4 and the UX review (`anthropic-skills:ux-review`, quick, in the browser on the grid question). Fix Critical/Major findings, re-run the suite.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Document splitting by two dimensions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Add review fixes to this commit or a separate one, as fits.)

---

### Task 6: One seat-test question instead of two

Data, not code: no commit. Questions 37 and 38 belong to user 1 and were created today.

- [ ] **Step 1: Merge 37, delete 38**

```bash
php artisan tinker --execute='
use App\Models\Question;
use App\Discussion\Values\QuestionDefinition;
$q = Question::findOrFail(37);
$definition = $q->definition;
$definition["split_by"] = ["pipeline", "project_tag:6"];
$definition["projects"]["pipelines"] = ["QualityPipeline", "OrchestratorPipeline"];
$q->update([
    "name" => "Seat test: status effect with the title on seat 1 vs seat 3",
    "notes" => "Turn share of the focus persona with and without the CEO title, title holder on seat 1 vs seat 3 (others keep their order), per pipeline, turns 1-60. Baseline no-status: the delta is with status minus without. All gpt-5-mini, seed 42, 60 turns.\n\nQuality: seat 1 = cells 41-48 (01.10.) plus their unchanged rerun 87-94 (14.10.), n = 8 per condition; seat 3 = 71-78. Orchestrator: seat 1 = the rerun 95-102 only, because 25-32 ran on 29.09. with older prompts and carry no seat-test tag; seat 3 = 79-86. Without a title the orchestrator picks the middle seats most often, so seat 3 starts from a higher share.\n\nRead the band (mean ± σ) next to the delta: reruns of the same cell differ by up to 45 pp per topic.",
    "definition" => QuestionDefinition::fromArray($definition)->toArray(),
]);
Question::findOrFail(38)->delete();
echo "ok\n";'
```

- [ ] **Step 2: Check**

Open `http://127.0.0.1:8000/statistics?q=37`: rows Orchestrator/Quality (registry order), columns seat 1/seat 3, deltas +29.6/+16.2 pp and +49.4/+45.0 pp; question 38 is gone from the list.
