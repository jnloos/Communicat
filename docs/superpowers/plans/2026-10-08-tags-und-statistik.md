# Globale Tags und Statistik-Seite: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Personas und Projekte bekommen globale Tags aus festen Kategorien (für die Modelle unsichtbar), eine Statistik-Seite vergleicht gespeicherte Reihen über viele Läufe, und die Sidebar lässt sich nach Projekt-Tags filtern.

**Architecture:** Tags sind drei Tabellen (`tag_categories`, `tag_values`, polymorphe `taggables` mit der Kategorie als Spalte, damit die Datenbank „ein Wert je Kategorie“ erzwingt) plus ein Trait `HasTags` auf `Expert` und `Project`. Die Statistik rechnet ausschließlich über die vorhandenen Metriken (`SpokenTurns` → `SpeakingShares`/`ShareTrajectory`, die dafür eine optionale Turn-Grenze bekommen); neu sind nur die Auswahl der Projekte (`SeriesProjects`), die Auswahl der Agenten (Schnittstelle `AgentReference`, eine Klasse je Bezug) und die Aggregation (`SeriesStatistics`), deren Ergebnisse Werte-Objekte unter `App\Discussion\Values` sind. Die UI sind vier Livewire-Komponenten: `Settings\Tags`, `Tags\TagFields`, `Statistics\StatisticsPage` (Liste) und `Statistics\ComparisonEditor` (Reihen, Ansicht, Ergebnis).

**Tech Stack:** Laravel 12.69, PHP ^8.3, Livewire 4.4.5, Flux / Flux Pro 2.20, PHPUnit 11 (SQLite in-memory), Pint, Tailwind 4 / Vite.

**Spec:** `docs/superpowers/specs/2026-10-08-tags-und-statistik-design.md` (vom Nutzer abgenommen; dieser Plan weicht inhaltlich nicht davon ab, er entscheidet nur die offenen Punkte aus Abschnitt 11 und füllt Lücken, siehe „Entscheidungen“).

## Global Constraints

- **Die Spec ist maßgeblich.** Wo dieser Plan und die Spec auseinanderlaufen, gilt die Spec — und die Abweichung wird dem Orchestrator gemeldet, nicht still umgesetzt.
- **Tags sind für die Modelle unsichtbar:** kein Prompt, keine Prompt-Vorlage, kein `run_config` liest Tags. Prompt-Vorlagen unter `resources/views/prompts/**` werden in diesem Plan nicht angefasst.
- **Zählregeln nur über `SpokenTurns`.** Keine neue Abfrage auf `job_logs` außerhalb von `App\Discussion\Metrics\SpokenTurns`; gesprochen heißt `words IS NOT NULL`, nie `status = 'success'`.
- **Namen von Kategorien und Werten:** Pflicht, max. 50 Zeichen, eindeutig im Scope (Kategorie) bzw. in der Kategorie (Wert), vor der Prüfung getrimmt.
- **Morph-Typ:** keine Morph-Map; `taggables.taggable_type` enthält den vollen Klassennamen wie `project_contributors.contributor_type`. Im Code nie Klassennamen-Strings als Morph-Typ, immer `getMorphClass()` bzw. `TagScope::morphType()`. Studiendaten werden von diesem Plan nicht verändert.
- **Lösch-Warnfenster** laufen über `App\Livewire\Concerns\NeedsConfirmation` (`confirmTitle`/`confirmMessage` setzen, dann `needsConfirmation('methode', ...$args)`); gerendert wird es von `<x-confirm-modal />` im App-Layout.
- **Modals** aus `x-modal-header` und `x-modal-footer` (Footer: Abbrechen + Bestätigen, nie mehr als zwei Knöpfe; `padding="dialog"` bei zentrierten Modals).
- **Alle sichtbaren Texte** in `lang/de/*.php` **und** `lang/en/*.php`. Neue Dateien: `tags.php`, `comparisons.php`.
- **Tests fassen nie einen echten Anbieter an** (`phpunit.xml` leert die Keys; Modellantworten über `Tests\Fakes\FakeAgents`).
- **Nicht gegen die echte Datenbank laufen lassen:** kein `php artisan migrate` und kein `php artisan tags:seed-study --apply` auf `database/database.sqlite` innerhalb einer Task. Tests laufen in-memory. Migration und Vorbelegung der echten Daten macht der Nutzer, nachdem er den Trockenlauf gesehen hat.
- **Pint nur über die geänderten Dateien**, nie über Verzeichnisse oder ohne Pfad: `vendor/bin/pint <datei> <datei> …`. Das Repo ist nicht durchgehend Pint-rein.
- **Namentlich stagen.** Kein `git add -A`, kein `git add app`. Im Arbeitsbaum liegt `.claude/worktrees/` (unbeteiligt).
- **`CLAUDE.md` ist gitignored** (`.gitignore:32`) und wird nie gestaget, auch nicht mit `-f`.
- **Commit-Messages** englisch, Imperativ, sprechend wie in `git log` („Add BlindQualityPipeline“, „Move the counting rules into SpokenTurns“), jede endet mit einer Leerzeile und `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Nach jeder Task:** die genannten Tests grün, dann `php artisan test` komplett grün, dann Pint, dann Commit.

## Entscheidungen

### Offener Punkt 1 (Spec §11): Wie `TagFields` die Auswahl an das Elternformular übergibt

**Entscheidung: `#[Modelable]`-Property.** `Tags\TagFields` hat `#[Modelable] public array $selection` (Kategorie-ID → Wert-ID, `''` = nicht gesetzt); das Elternformular bindet sie mit `<livewire:tags.tag-fields scope="…" wire:model="tagSelection" />` an eine eigene `public array $tagSelection` und schreibt sie in seinem `save()` mit `$model->syncTags($this->tagSelection)`.

Begründung aus dem Code:
- Alle drei Formulare (`CreateProject::save()`, `EditProject::save()`, `ExpertEditor::save()`) sind ein einziger Submit, der alle Felder (`wire:model.defer`) in einem Request validiert und speichert. Mit Modelable reisen die Tags in genau diesem Request mit; ein Event-Weg bräuchte beim Anlegen einen zweiten Request mit der neuen ID, ohne gemeinsame Transaktion.
- Die Rechteprüfung bleibt an der einen Stelle, an der sie heute steht: `EditProject::save()` ruft `Gate::authorize('manage-project')` vor dem Schreiben; `ExpertEditor` hat (wie heute) keine. `TagFields` schreibt selbst nie und braucht deshalb **keinen Modell-Prop** — die Spec nennt „Props: Scope, Modell bzw. leer beim Anlegen“; das Modell entfällt, weil das Elternformular die Auswahl lädt (`$model->tagSelection()`) und speichert. Das ist die einzige formale Abweichung von der Spec-Formulierung und folgt aus dieser Entscheidung.
- `ExpertEditor` ist ein wiederverwendetes Flyout (`edit()` lädt jeweils einen anderen Experten). Damit die Kind-Komponente nicht mit dem Zustand des vorigen Experten stehen bleibt, bekommt sie einen `wire:key`, der sich bei jedem `edit()` ändert (`$formKey`).

### Offener Punkt 2 (Spec §11): Spannweiten im Balkendiagramm

**Entscheidung: kein `flux:chart`, sondern eine eigene Blade-Komponente `x-statistics.range-bars`** (horizontale Balken in HTML/CSS: Balken bis zum Mittelwert, darüber eine Linie von Min bis Max, Werte als Text daneben).

Begründung aus `vendor/livewire/flux-pro/dist/flux.module.js` (2.20.0):
- Ein Balken (`template[name="bar"]`) beginnt immer bei 0 (`y = scale(value)`, Höhe bis `scale(0)`); es gibt kein Feld für eine Untergrenze, keine Fehlerbalken.
- Die Farbe hängt am Feld (Template mit `currentColor`), nicht am Datensatz; `datum.color` wertet nur der Kuchen aus (`normalizePieData`). Die Spec verlangt aber eine Farbe je Reihe.
- Die einzige Annäherung wäre ein `flux:chart.stack` aus einem transparenten Balken (Min) und einem sichtbaren (Max − Min) je Reihe als eigenes Feld — das stapelt mit Pixel-Gap, kennt keinen Mittelwert-Strich und braucht ein Feld pro Reihe. Eine CSS-Leiste ist einfacher, testbar über HTML und farbtreu.
- Für den **Verlauf** bleibt `flux:chart` mit mehreren `flux:chart.line` (wie im `StatisticsFlyout`); Lücken sind dort unproblematisch, weil der Linien-Renderer fehlende Werte als Segmentende behandelt (`if (!isDefined(datum[key]))`).

### Weitere Entscheidungen, wo die Spec schweigt oder der Code etwas verlangt

1. **Es gibt keine Morph-Map, und es kommt auch keine.** `project_contributors.contributor_type` speichert volle Klassennamen; `taggables` folgt dem. Eine Map würde die Sitze der Studie unsichtbar machen, solange die vorhandenen Zeilen nicht migriert sind — das ist bewusst nicht Teil dieses Plans.
2. **`failedTurns` bleibt laufweit**, auch mit Turn-Grenze: `SpeakingShares` zählt Fehlschläge ohne Beitrag nicht nach Turn-Position. Die Statistik-Seite liest `failedTurns` nicht.
3. **Ein Projekt ohne gesprochenen Turn fällt aus jeder Reihe heraus** und zählt als „ausgeschlossen“ (sonst ergäbe es Anteile 0 für alle und zöge Mittelwerte künstlich nach unten).
4. **`top` bei Gleichstand:** der Agent auf dem niedrigeren Sitz (Reihenfolge von `SpeakingShares`), deterministisch, ohne Ausgleichslogik.
5. **`each`:** eine Teilreihe je Sitz; ein Projekt, das einen Sitz nicht hat, fehlt in dieser Teilreihe einfach. „Ausgeschlossen“ zählt je Reihe die Projekte, in denen der Bezug **niemanden** trifft.
6. **Gini ignoriert den Bezug** (Lauf-Kennzahl, wie die Spec sagt); für Gini zählt jedes Projekt mit mindestens einem gesprochenen Turn.
7. **Farben:** Palette `indigo, orange, teal, pink, sky, red, purple, lime` (getrennt von den Sitzfarben des Flyouts `blue, violet, emerald, amber, …`; alle acht stehen in `public/build/assets/*.css` als `--color-*-500`). Vergabe der Reihe nach über alle Linien; eine Reihe mit `each` belegt so viele Farben, wie sie Teilreihen hat.
8. **`Settings\Tags`** prüft `Gate::authorize('admin')` in `mount()` **und in jeder Aktion**: die Route-Middleware `admin` gilt nur für den ersten Request, Livewire-Updates laufen über `/livewire/update`.
9. **`tags:seed-study`:** „Sitz 1“ wörtlich (`project_contributors.seat = 1`); eine Status-Zelle ohne Sitz 1 wird gemeldet, nicht geraten. Titel-Erkennung: `/\bwithout status\b/i` → `kontrolle`, sonst `/\bwith status\b/i` → `status`.
10. **Import meldet übersprungene Tags** im Rückgabe-Array (`skipped_tags`) und als Flash (`import_tags_skipped`) — genau wie heute `missing_experts` (`import_warning`). Hinweis: Keiner der beiden Flashes wird heute irgendwo gerendert; das bleibt so (nicht Teil der Spec).
11. **HasTags-Tests** liegen unter `tests/Unit/Tags`, brauchen aber `RefreshDatabase` — wie die vorhandenen Unit-Tests unter `tests/Unit/Discussion`.

---

## Dateistruktur

| Datei | Verantwortung | Task |
|---|---|---|
| `database/migrations/2026_10_08_000001_create_tag_tables.php` | `tag_categories`, `tag_values`, `taggables` | 2 |
| `app/Models/TagScope.php` | Enum Persona/Project, Model-Klasse, Morph-Typ, Label | 2 |
| `app/Models/TagCategory.php`, `TagValue.php`, `Taggable.php` | Eloquent-Modelle | 2 |
| `app/Models/Concerns/HasPosition.php` | Anhängen ans Ende, `moveTo()` für `wire:sort` | 2 |
| `app/Models/Concerns/HasTags.php` | `tagValues`, `setTag`, `tagFor`, `tagSelection`, `syncTags`, `whereTagged`, Aufräumen beim Löschen | 2 |
| `tests/Feature/Tags/TagsInvisibleToModelsTest.php` | kein Tag in Prompt oder `run_config` | 3 |
| `app/Livewire/Settings/Tags.php` + `resources/views/livewire/settings/tags.blade.php` | Verwaltung | 4 |
| `app/Livewire/Tags/TagFields.php` + View | Auswahlfelder in Formularen | 5 |
| `resources/views/components/tags/badges.blade.php` | Badges | 5 |
| `app/Services/ProjectTransfer/ProjectExport.php`, `ProjectImporter.php` | Tags im Export/Import | 6 |
| `app/Services/Tags/StudyTagSeeder.php`, `StudyTagPlan.php`, `app/Console/Commands/SeedStudyTags.php` | Vorbelegung | 7 |
| `app/Discussion/Metrics/{SpokenTurns,SpeakingShares,ShareTrajectory}.php` | Turn-Grenze | 8 |
| `app/Discussion/Values/{Metric,AgentGroup,TagFilter}.php` | Kennzahl, Agentengruppe, Tag-Filter | 9 |
| `app/Discussion/Metrics/References/*` | `AgentReference`, `NamesTagValues`, fünf Bezüge, `AgentReferences` | 9 |
| `app/Discussion/Values/{SeriesDefinition,ComparisonView,ComparisonDisplay,ProjectValue,TrajectoryMean,SeriesLine,SeriesResult}.php` | Reihe, Ansicht, Ergebnis | 10 |
| `app/Discussion/Metrics/{SeriesProjects,SeriesStatistics}.php` | Projektauswahl, Aggregation | 10 |
| `database/migrations/2026_10_08_000002_create_comparisons_table.php`, `app/Models/Comparison.php` | gespeicherte Vergleiche | 11 |
| `app/Livewire/Statistics/StatisticsPage.php` + View | Liste, Anlegen/Umbenennen/Duplizieren/Löschen | 11 |
| `app/Livewire/Statistics/ComparisonEditor.php` + View | Reihen-Editor, Live-Trefferliste, Ansicht, Ergebnis | 12, 13 |
| `resources/views/components/statistics/range-bars.blade.php` | Balken mit Spannweite | 13 |
| `app/Livewire/Projects/ProjectNavigation.php` + View | Sidebar-Filter | 14 |
| `CLAUDE.md` (nicht committen) | Doku | 15 |

`docs/pipeline.puml` ist nicht betroffen: keine Stage ändert sich.

---

### Task 1: entfällt

Ursprünglich: Morph-Map registrieren und `project_contributors` auf Aliasse migrieren. Gestrichen vom Orchestrator, weil das echte Studiendaten umschreibt, ohne dass Tags es brauchen. `taggables.taggable_type` speichert — wie `project_contributors.contributor_type` — den vollen Klassennamen; Code liest ihn weiterhin nur über `getMorphClass()` / `TagScope::morphType()`, sodass eine spätere Morph-Map nur die Daten, nicht den Code berührt. Es wird **keine** Morph-Map registriert.

### Task 2: Tag-Datenmodell, `TagScope`, `HasPosition`, `HasTags`

**Files:**
- Create: `database/migrations/2026_10_08_000001_create_tag_tables.php`
- Create: `app/Models/TagScope.php`, `app/Models/TagCategory.php`, `app/Models/TagValue.php`, `app/Models/Taggable.php`
- Create: `app/Models/Concerns/HasPosition.php`, `app/Models/Concerns/HasTags.php`
- Modify: `app/Models/Expert.php`, `app/Models/Project.php` (`use HasTags;`)
- Create: `lang/de/tags.php`, `lang/en/tags.php` (nur `scopes` in dieser Task)
- Test: `tests/Unit/Tags/HasTagsTest.php`

**Interfaces:**
- Consumes: nichts (Task 1 entfällt).
- Produces:
  - `enum App\Models\TagScope: string { Persona = 'persona'; Project = 'project' }` mit `modelClass(): string`, `morphType(): string`, `static forModel(Model $model): self`, `label(): string`.
  - `App\Models\TagCategory` (`scope` als `TagScope` gecastet, `name`, `position`), `values(): HasMany` (nach `position`, `id`), Scope `ofScope(TagScope $scope)` (sortiert), `assignmentCount(): int`.
  - `App\Models\TagValue` (`tag_category_id`, `name`, `position`), `category(): BelongsTo`, `assignments(): HasMany` (→ `Taggable`).
  - `App\Models\Taggable` (Tabelle `taggables`).
  - Trait `App\Models\Concerns\HasPosition`: neue Zeilen ans Ende, `moveTo(int $index): void` (0-basiert, wie `wire:sort` liefert).
  - Trait `App\Models\Concerns\HasTags`: `tagValues(): MorphToMany` (Pivot `tag_category_id`), `tagScope(): TagScope`, `setTag(TagCategory $category, ?TagValue $value): void` (wirft `InvalidArgumentException`), `tagFor(TagCategory $category): ?TagValue`, `tagSelection(): array<int,int>`, `syncTags(array $selection): void`, Scope `whereTagged(array $valueIds)`.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/Tags/HasTagsTest.php`:

```php
<?php

namespace Tests\Unit\Tags;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class HasTagsTest extends TestCase
{
    use RefreshDatabase;

    private function category(TagScope $scope, string $name, array $values): TagCategory
    {
        $category = TagCategory::create(['scope' => $scope, 'name' => $name]);

        foreach ($values as $value) {
            $category->values()->create(['name' => $value]);
        }

        return $category->load('values');
    }

    private function value(TagCategory $category, string $name): TagValue
    {
        return $category->values->firstWhere('name', $name);
    }

    public function test_a_second_value_of_the_same_category_replaces_the_first(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich', 'männlich']);
        $expert = Expert::factory()->create();

        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($gender, $this->value($gender, 'männlich'));

        $this->assertSame(1, Taggable::count());
        $this->assertSame('männlich', $expert->tagFor($gender)?->name);
        $this->assertSame((new Expert)->getMorphClass(), Taggable::sole()->taggable_type);
    }

    public function test_null_clears_the_category(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $expert = Expert::factory()->create();

        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($gender, null);

        $this->assertNull($expert->tagFor($gender));
        $this->assertSame(0, Taggable::count());
    }

    public function test_a_value_of_another_category_is_refused(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $group = $this->category(TagScope::Persona, 'Gruppe', ['Mehrheit']);

        $this->expectException(InvalidArgumentException::class);

        Expert::factory()->create()->setTag($gender, $this->value($group, 'Mehrheit'));
    }

    public function test_a_category_of_the_other_scope_is_refused(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status']);

        $this->expectException(InvalidArgumentException::class);

        Expert::factory()->create()->setTag($condition, $this->value($condition, 'status'));
    }

    public function test_tag_selection_and_sync_tags_round_trip_a_form(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status', 'kontrolle']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule']);
        $project = Project::factory()->create();

        $project->syncTags([
            $condition->id => (string) $this->value($condition, 'kontrolle')->id,
            $topic->id => '',
        ]);

        $this->assertSame([$condition->id => $this->value($condition, 'kontrolle')->id], $project->tagSelection());
    }

    public function test_sync_tags_refuses_a_value_outside_its_category(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule']);

        $this->expectException(InvalidArgumentException::class);

        Project::factory()->create()->syncTags([$condition->id => $this->value($topic, 'Schule')->id]);
    }

    public function test_where_tagged_is_and_across_categories_and_or_within_one(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status', 'kontrolle']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule', 'Verkehr']);

        $schoolStatus = Project::factory()->create();
        $schoolStatus->setTag($condition, $this->value($condition, 'status'));
        $schoolStatus->setTag($topic, $this->value($topic, 'Schule'));

        $trafficControl = Project::factory()->create();
        $trafficControl->setTag($condition, $this->value($condition, 'kontrolle'));
        $trafficControl->setTag($topic, $this->value($topic, 'Verkehr'));

        $untagged = Project::factory()->create();

        $or = Project::whereTagged([$this->value($condition, 'status')->id, $this->value($condition, 'kontrolle')->id])->pluck('id')->all();
        $and = Project::whereTagged([$this->value($condition, 'status')->id, $this->value($topic, 'Verkehr')->id])->pluck('id')->all();
        $none = Project::whereTagged([])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$schoolStatus->id, $trafficControl->id], $or);
        $this->assertSame([], $and);
        $this->assertEqualsCanonicalizing([$schoolStatus->id, $trafficControl->id, $untagged->id], $none);
    }

    public function test_deleting_a_value_or_a_category_removes_their_assignments(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich', 'männlich']);
        $group = $this->category(TagScope::Persona, 'Gruppe', ['Mehrheit']);
        $expert = Expert::factory()->create();
        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($group, $this->value($group, 'Mehrheit'));

        $this->value($gender, 'weiblich')->delete();
        $this->assertSame(1, Taggable::count());

        $group->delete();
        $this->assertSame(0, Taggable::count());
        $this->assertSame(0, TagValue::where('tag_category_id', $group->id)->count());
    }

    public function test_deleting_a_tagged_model_removes_its_assignments(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $expert = Expert::factory()->create();
        $expert->setTag($gender, $this->value($gender, 'weiblich'));

        $expert->delete();

        $this->assertSame(0, Taggable::count());
    }

    public function test_new_rows_go_to_the_end_and_move_to_renumbers(): void
    {
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        $b = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $c = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'C']);
        $other = TagCategory::create(['scope' => TagScope::Project, 'name' => 'X']);

        $this->assertSame([1, 2, 3], [$a->position, $b->position, $c->position]);
        $this->assertSame(1, $other->position);

        $c->moveTo(0);

        $this->assertSame(['C', 'A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=HasTagsTest`
Expected: FAIL — `Class "App\Models\TagCategory" not found`.

- [ ] **Step 3: Migration**

`database/migrations/2026_10_08_000001_create_tag_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global tags: categories with fixed values, one set for personas and one
     * for projects. They describe a run after the fact and never reach a model.
     *
     * taggables carries the category as well as the value, so the unique key
     * (taggable, category) lets the database itself enforce "at most one value
     * per category". Deleting a value or a category cascades to its assignments.
     * The tagged row itself has no foreign key (it is polymorphic); HasTags
     * removes its assignments when the model is deleted.
     */
    public function up(): void
    {
        Schema::create('tag_categories', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('name', 50);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'name']);
        });

        Schema::create('tag_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_category_id')->constrained('tag_categories')->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tag_category_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_value_id')->constrained('tag_values')->cascadeOnDelete();
            $table->foreignId('tag_category_id')->constrained('tag_categories')->cascadeOnDelete();
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->timestamps();
            $table->unique(['taggable_type', 'taggable_id', 'tag_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tag_values');
        Schema::dropIfExists('tag_categories');
    }
};
```

- [ ] **Step 4: Enum `TagScope`**

`app/Models/TagScope.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which kind of thing a tag category describes. Each scope belongs to exactly
 * one model class, and the morph type its rows carry in taggables comes from
 * getMorphClass() — never spelled out as a class name.
 */
enum TagScope: string
{
    case Persona = 'persona';
    case Project = 'project';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Persona => Expert::class,
            self::Project => Project::class,
        };
    }

    public function morphType(): string
    {
        $class = $this->modelClass();

        return (new $class)->getMorphClass();
    }

    public static function forModel(Model $model): self
    {
        foreach (self::cases() as $scope) {
            $class = $scope->modelClass();

            if ($model instanceof $class) {
                return $scope;
            }
        }

        throw new InvalidArgumentException('No tag scope for '.$model::class.'.');
    }

    public function label(): string
    {
        return __("tags.scopes.{$this->value}");
    }
}
```

- [ ] **Step 5: Trait `HasPosition`**

`app/Models/Concerns/HasPosition.php`:

```php
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
```

- [ ] **Step 6: Modelle**

`app/Models/TagCategory.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A global tag category with fixed values, for personas or for projects. */
class TagCategory extends Model
{
    use HasPosition;

    protected $fillable = ['scope', 'name', 'position'];

    protected $casts = ['scope' => TagScope::class];

    public function values(): HasMany
    {
        return $this->hasMany(TagValue::class)->orderBy('position')->orderBy('id');
    }

    /** The categories of one scope, in their manual order. */
    public function scopeOfScope(Builder $query, TagScope $scope): void
    {
        $query->where('scope', $scope->value)->orderBy('position')->orderBy('id');
    }

    /** How many models carry a value of this category (at most one each). */
    public function assignmentCount(): int
    {
        return Taggable::where('tag_category_id', $this->id)->count();
    }

    protected function siblings(): Builder
    {
        return static::query()->where('scope', $this->scope instanceof TagScope ? $this->scope->value : $this->scope);
    }
}
```

`app/Models/TagValue.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagValue extends Model
{
    use HasPosition;

    protected $fillable = ['tag_category_id', 'name', 'position'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TagCategory::class, 'tag_category_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Taggable::class);
    }

    protected function siblings(): Builder
    {
        return static::query()->where('tag_category_id', $this->tag_category_id);
    }
}
```

`app/Models/Taggable.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One assignment: this model carries this value of this category. */
class Taggable extends Model
{
    protected $fillable = ['tag_value_id', 'tag_category_id', 'taggable_type', 'taggable_id'];
}
```

- [ ] **Step 7: Trait `HasTags`**

`app/Models/Concerns/HasTags.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

/**
 * Global tags on a model: at most one value per category, and only categories
 * of the model's own scope. Tags describe a run after the fact — nothing that
 * builds a prompt or a run_config may read them.
 */
trait HasTags
{
    public static function bootHasTags(): void
    {
        // taggables has no foreign key to the tagged row (it is polymorphic), so
        // the assignments go with the model here. A mass delete bypasses this.
        static::deleting(function (Model $model): void {
            Taggable::where('taggable_type', $model->getMorphClass())
                ->where('taggable_id', $model->getKey())
                ->delete();
        });
    }

    public function tagValues(): MorphToMany
    {
        return $this->morphToMany(TagValue::class, 'taggable', 'taggables')
            ->withPivot('tag_category_id')
            ->withTimestamps();
    }

    public function tagScope(): TagScope
    {
        return TagScope::forModel($this);
    }

    /** Sets (or with null clears) this model's value for one category; replaces, never adds. */
    public function setTag(TagCategory $category, ?TagValue $value): void
    {
        if ($category->scope !== $this->tagScope()) {
            throw new InvalidArgumentException("Category [{$category->name}] belongs to [{$category->scope->value}], not [{$this->tagScope()->value}].");
        }

        if ($value !== null && (int) $value->tag_category_id !== (int) $category->id) {
            throw new InvalidArgumentException("Value [{$value->name}] does not belong to category [{$category->name}].");
        }

        $row = [
            'taggable_type' => $this->getMorphClass(),
            'taggable_id' => $this->getKey(),
            'tag_category_id' => $category->id,
        ];

        if ($value === null) {
            Taggable::where($row)->delete();
        } else {
            Taggable::upsert(
                [$row + ['tag_value_id' => $value->id]],
                ['taggable_type', 'taggable_id', 'tag_category_id'],
                ['tag_value_id'],
            );
        }

        $this->unsetRelation('tagValues');
    }

    public function tagFor(TagCategory $category): ?TagValue
    {
        return $this->tagValues()->wherePivot('tag_category_id', $category->id)->first();
    }

    /**
     * The model's tags as a form holds them.
     *
     * @return array<int, int> category id => value id, only for categories that are set
     */
    public function tagSelection(): array
    {
        return $this->tagValues()->get()
            ->mapWithKeys(fn (TagValue $value) => [(int) $value->pivot->tag_category_id => $value->id])
            ->all();
    }

    /**
     * Writes a form's selection. Every category of this model's scope that
     * appears as a key is set; '' or null clears it. A value that is not one of
     * its category's values is a forged request and throws.
     *
     * @param  array<int|string, int|string|null>  $selection  category id => value id
     */
    public function syncTags(array $selection): void
    {
        $categories = TagCategory::ofScope($this->tagScope())
            ->whereKey(array_keys($selection))
            ->with('values')
            ->get();

        foreach ($categories as $category) {
            $raw = $selection[$category->id] ?? null;
            $value = blank($raw) ? null : $category->values->firstWhere('id', (int) $raw);

            if (! blank($raw) && $value === null) {
                throw new InvalidArgumentException("Value [{$raw}] does not belong to category [{$category->name}].");
            }

            $this->setTag($category, $value);
        }
    }

    /**
     * Only models that carry the given values: AND across categories, OR within
     * one. Ids that no longer exist are ignored; an empty list filters nothing.
     *
     * @param  list<int|string>  $valueIds
     */
    public function scopeWhereTagged(Builder $query, array $valueIds): void
    {
        $byCategory = TagValue::whereKey($valueIds)->get(['id', 'tag_category_id'])->groupBy('tag_category_id');

        foreach ($byCategory as $values) {
            $query->whereHas('tagValues', fn (Builder $tagged) => $tagged->whereIn('tag_values.id', $values->pluck('id')));
        }
    }
}
```

In `app/Models/Expert.php` und `app/Models/Project.php`: `use App\Models\Concerns\HasTags;` importieren und `use HasFactory, HasTags;` setzen.

- [ ] **Step 8: Sprachdateien**

`lang/de/tags.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Globale Tags: Verwaltung, Formulare, Sidebar-Filter
|--------------------------------------------------------------------------
*/

return [
    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projekte',
    ],
];
```

`lang/en/tags.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Global tags: management, forms, sidebar filter
|--------------------------------------------------------------------------
*/

return [
    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projects',
    ],
];
```

- [ ] **Step 9: Tests laufen lassen**

Run: `php artisan test --filter=HasTagsTest` → PASS. Dann `php artisan test` → grün.

- [ ] **Step 10: Pint und Commit**

```bash
vendor/bin/pint database/migrations/2026_10_08_000001_create_tag_tables.php app/Models/TagScope.php app/Models/TagCategory.php app/Models/TagValue.php app/Models/Taggable.php app/Models/Concerns/HasPosition.php app/Models/Concerns/HasTags.php app/Models/Expert.php app/Models/Project.php lang/de/tags.php lang/en/tags.php tests/Unit/Tags/HasTagsTest.php
git add database/migrations/2026_10_08_000001_create_tag_tables.php app/Models/TagScope.php app/Models/TagCategory.php app/Models/TagValue.php app/Models/Taggable.php app/Models/Concerns/HasPosition.php app/Models/Concerns/HasTags.php app/Models/Expert.php app/Models/Project.php lang/de/tags.php lang/en/tags.php tests/Unit/Tags/HasTagsTest.php
git commit -m "$(cat <<'EOF'
Add global tags for personas and projects

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Tags bleiben für die Modelle unsichtbar

**Files:**
- Test: `tests/Feature/Tags/TagsInvisibleToModelsTest.php`

**Interfaces:**
- Consumes: `TagCategory`, `TagScope`, `HasTags::setTag()` (Task 2); `Tests\Feature\Discussion\PipelineSmokeTest::pipelineNames()` (public static, vorhanden).
- Produces: einen Wächter-Test; kein Produktionscode.

Der Test läuft jede gefundene Pipeline drei Turns lang mit gefakten Antworten (so rendert jede Stage ihre Vorlagen über `PromptRenderer`, inklusive `prompts.summarize` dank `summarize_threshold = 2`) und durchsucht jeden geloggten Prompt (`prompt_logs.prompt`) und den eingefrorenen `run_config` nach Markern in Kategorie- und Wertnamen. Er muss sofort grün sein; wird er je rot, hat jemand Tags in einen Prompt gezogen.

- [ ] **Step 1: Test schreiben**

```php
<?php

namespace Tests\Feature\Tags;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAgents;
use Tests\Feature\Discussion\PipelineSmokeTest;
use Tests\TestCase;

/**
 * Tags are the researcher's description of a run, never a condition of it. If
 * a category or value name ever reached a prompt, a model would see its own
 * experimental label — so every pipeline runs here with tagged personas and a
 * tagged project, and no marker may turn up in what the models were sent.
 */
class TagsInvisibleToModelsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKERS = ['ZZKATPERSONA', 'ZZWERTPERSONA', 'ZZKATPROJEKT', 'ZZWERTPROJEKT'];

    public static function pipelineNames(): array
    {
        return PipelineSmokeTest::pipelineNames();
    }

    private function fakeAnswers(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich will etwas beitragen.', 'priority' => 3, 'draft' => 'Ein kurzer Entwurf.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein kurzer Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);
        FakeAgents::always(SelectorAgent::class, ['speaker' => 'E1', 'reasoning' => 'Weil.']);
        FakeAgents::always(JudgeAgent::class, ['speaker' => 'E1', 'label' => 'A', 'reasoning' => 'Weil.']);
    }

    #[DataProvider('pipelineNames')]
    public function test_no_tag_reaches_a_prompt_or_the_run_config(string $name): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeAnswers();

        $personaCategory = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'ZZKATPERSONA']);
        $personaValue = $personaCategory->values()->create(['name' => 'ZZWERTPERSONA']);
        $projectCategory = TagCategory::create(['scope' => TagScope::Project, 'name' => 'ZZKATPROJEKT']);
        $projectValue = $projectCategory->values()->create(['name' => 'ZZWERTPROJEKT']);

        $project = Project::factory()->create(['pipeline' => $name, 'summarize_threshold' => 2, 'summarize_oldest' => 1]);
        $project->setTag($projectCategory, $projectValue);

        foreach (Expert::factory()->count(3)->create() as $expert) {
            $expert->setTag($personaCategory, $personaValue);
            $project->addContributingExpert($expert);
        }

        foreach (range(1, 3) as $turn) {
            app(TurnRunner::class)->run($project->fresh());
        }

        $prompts = PromptLog::pluck('prompt')->implode("\n");
        $runConfig = (string) json_encode($project->fresh()->run_config);

        $this->assertNotSame('', $prompts);
        $this->assertTrue(PromptLog::where('purpose', 'summarize')->exists(), 'The summarize prompt never ran, so this test proves less than it claims.');

        foreach (self::MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $prompts);
            $this->assertStringNotContainsString($marker, $runConfig);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=TagsInvisibleToModelsTest`
Expected: PASS für jede Pipeline (es gibt keinen Code, der Tags liest). Schlägt die `summarize`-Zusicherung fehl, `range(1, 3)` auf `range(1, 4)` erhöhen — nicht die Zusicherung entfernen.

- [ ] **Step 3: Pint und Commit**

```bash
vendor/bin/pint tests/Feature/Tags/TagsInvisibleToModelsTest.php
git add tests/Feature/Tags/TagsInvisibleToModelsTest.php
git commit -m "$(cat <<'EOF'
Guard that no tag ever reaches a prompt or the run config

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Einstellungen → Tags (Verwaltung)

**Files:**
- Create: `app/Livewire/Settings/Tags.php`, `resources/views/livewire/settings/tags.blade.php`
- Modify: `routes/web.php`, `resources/views/components/settings/layout.blade.php`
- Modify: `lang/de/tags.php`, `lang/en/tags.php`, `lang/de/settings.php`, `lang/en/settings.php` (`nav.tags`)
- Test: `tests/Feature/Settings/TagsTest.php`

**Interfaces:**
- Consumes: `TagCategory`, `TagValue`, `TagScope`, `HasPosition::moveTo()`, `TagCategory::assignmentCount()`, `TagValue::assignments()` (Task 2); `NeedsConfirmation`.
- Produces: Route `settings.tags` (`GET settings/tags`, Middleware `auth` + `admin`); Livewire-Komponente `App\Livewire\Settings\Tags` mit den öffentlichen Aktionen `createCategory()`, `createValue(int $categoryId)`, `startRenamingCategory(int)`, `startRenamingValue(int)`, `saveCategoryName()`, `saveValueName()`, `cancelRenaming()`, `confirmDeleteCategory(int)`, `deleteCategory(int)`, `confirmDeleteValue(int)`, `deleteValue(int)`, `sortCategory(int $categoryId, int $position)`, `sortValue(int $valueId, int $position)`; Properties `scope`, `newCategoryName`, `newValueNames`, `renamingCategoryId`, `renamingValueId`, `renameTo`.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Settings/TagsTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Tags;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_reach_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.tags'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('settings.tags'))->assertOk();
    }

    public function test_a_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Tags::class)->assertForbidden();
    }

    public function test_an_admin_creates_a_category_and_a_value_in_the_chosen_scope(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(Tags::class)
            ->set('scope', 'project')
            ->set('newCategoryName', '  bedingung ')
            ->call('createCategory')
            ->assertHasNoErrors();

        $category = TagCategory::sole();
        $this->assertSame(TagScope::Project, $category->scope);
        $this->assertSame('bedingung', $category->name);

        $component->set("newValueNames.{$category->id}", 'status')
            ->call('createValue', $category->id)
            ->assertHasNoErrors();

        $this->assertSame(['status'], $category->values()->pluck('name')->all());
    }

    public function test_names_are_required_short_and_unique_in_their_scope(): void
    {
        $this->actingAs($this->admin());
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);

        Livewire::test(Tags::class)
            ->set('newCategoryName', 'Geschlecht')->call('createCategory')->assertHasErrors(['newCategoryName' => 'unique'])
            ->set('newCategoryName', '')->call('createCategory')->assertHasErrors(['newCategoryName' => 'required'])
            ->set('newCategoryName', str_repeat('x', 51))->call('createCategory')->assertHasErrors(['newCategoryName' => 'max'])
            ->set('scope', 'project')->set('newCategoryName', 'Geschlecht')->call('createCategory')->assertHasNoErrors();

        $this->assertSame(2, TagCategory::count());
    }

    public function test_renaming_inline_keeps_the_assignments(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        $expert = Expert::factory()->create();
        $expert->setTag($category, $value);

        Livewire::test(Tags::class)
            ->call('startRenamingValue', $value->id)
            ->assertSet('renameTo', 'weiblich')
            ->set('renameTo', 'Frau')
            ->call('saveValueName')
            ->assertHasNoErrors()
            ->assertSet('renamingValueId', null)
            ->call('startRenamingCategory', $category->id)
            ->set('renameTo', 'Gender')
            ->call('saveCategoryName');

        $this->assertSame('Frau', $expert->fresh()->tagFor($category->fresh())?->name);
        $this->assertSame('Gender', $category->fresh()->name);
    }

    public function test_deleting_a_value_asks_first_and_names_the_assignments(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        foreach (Expert::factory()->count(7)->create() as $expert) {
            $expert->setTag($category, $value);
        }

        $component = Livewire::test(Tags::class)
            ->call('confirmDeleteValue', $value->id)
            ->assertSet('pendingMethod', 'deleteValue')
            ->assertDispatched('open-confirm');

        $this->assertStringContainsString('7', $component->get('confirmMessage'));
        $this->assertStringContainsString('weiblich', $component->get('confirmMessage'));
        $this->assertSame(1, TagValue::count());

        $component->call('executeConfirmed');

        $this->assertSame(0, TagValue::count());
        $this->assertSame(0, Taggable::count());
    }

    public function test_deleting_a_category_names_its_values_and_takes_them_along(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $category->values()->create(['name' => 'weiblich']);
        $category->values()->create(['name' => 'männlich']);

        $component = Livewire::test(Tags::class)->call('confirmDeleteCategory', $category->id);

        $this->assertStringContainsString('2', $component->get('confirmMessage'));

        $component->call('executeConfirmed');

        $this->assertSame(0, TagCategory::count());
        $this->assertSame(0, TagValue::count());
    }

    public function test_sorting_writes_the_position(): void
    {
        $this->actingAs($this->admin());
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $c = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'C']);
        $first = $a->values()->create(['name' => 'eins']);
        $second = $a->values()->create(['name' => 'zwei']);

        Livewire::test(Tags::class)
            ->call('sortCategory', $c->id, 0)
            ->call('sortValue', $second->id, 0);

        $this->assertSame(['C', 'A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
        $this->assertSame(['zwei', 'eins'], $a->values()->pluck('name')->all());
        $this->assertSame(2, $first->fresh()->position);
    }

    public function test_each_value_shows_its_assignment_count(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        Expert::factory()->create()->setTag($category, $value);

        Livewire::test(Tags::class)->assertSee(trans_choice('tags.assignments', 1, ['count' => 1]));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter='Tests\\Feature\\Settings\\TagsTest'`
Expected: FAIL — Route `settings.tags` und Klasse fehlen.

- [ ] **Step 3: Komponente**

`app/Livewire/Settings/Tags.php`:

```php
<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings → Tags: the global categories and their fixed values, for personas
 * and for projects. Admins only, checked on every action — the route's admin
 * middleware only guards the first request, not Livewire's updates.
 */
class Tags extends Component
{
    use NeedsConfirmation;

    #[Url]
    public string $scope = 'persona';

    public string $newCategoryName = '';

    /** @var array<int|string, string> category id => name typed for a new value */
    public array $newValueNames = [];

    #[Locked]
    public ?int $renamingCategoryId = null;

    #[Locked]
    public ?int $renamingValueId = null;

    public string $renameTo = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    public function createCategory(): void
    {
        Gate::authorize('admin');
        $scope = $this->currentScope();
        $this->newCategoryName = trim($this->newCategoryName);
        $this->validate(['newCategoryName' => $this->categoryNameRules($scope)], [], ['newCategoryName' => __('tags.fields.category')]);

        TagCategory::create(['scope' => $scope, 'name' => $this->newCategoryName]);
        $this->newCategoryName = '';
    }

    public function createValue(int $categoryId): void
    {
        Gate::authorize('admin');
        $category = TagCategory::findOrFail($categoryId);
        $field = "newValueNames.{$categoryId}";
        $this->newValueNames[$categoryId] = trim((string) ($this->newValueNames[$categoryId] ?? ''));
        $this->validate([$field => $this->valueNameRules($category)], [], [$field => __('tags.fields.value')]);

        $category->values()->create(['name' => $this->newValueNames[$categoryId]]);
        $this->newValueNames[$categoryId] = '';
    }

    public function startRenamingCategory(int $categoryId): void
    {
        Gate::authorize('admin');
        $category = TagCategory::findOrFail($categoryId);
        $this->cancelRenaming();
        $this->renamingCategoryId = $category->id;
        $this->renameTo = $category->name;
    }

    public function startRenamingValue(int $valueId): void
    {
        Gate::authorize('admin');
        $value = TagValue::findOrFail($valueId);
        $this->cancelRenaming();
        $this->renamingValueId = $value->id;
        $this->renameTo = $value->name;
    }

    public function saveCategoryName(): void
    {
        Gate::authorize('admin');
        $category = TagCategory::findOrFail((int) $this->renamingCategoryId);
        $this->renameTo = trim($this->renameTo);
        $this->validate(['renameTo' => $this->categoryNameRules($category->scope, $category->id)], [], ['renameTo' => __('tags.fields.category')]);

        $category->update(['name' => $this->renameTo]);
        $this->cancelRenaming();
    }

    public function saveValueName(): void
    {
        Gate::authorize('admin');
        $value = TagValue::with('category')->findOrFail((int) $this->renamingValueId);
        $this->renameTo = trim($this->renameTo);
        $this->validate(['renameTo' => $this->valueNameRules($value->category, $value->id)], [], ['renameTo' => __('tags.fields.value')]);

        $value->update(['name' => $this->renameTo]);
        $this->cancelRenaming();
    }

    public function cancelRenaming(): void
    {
        $this->reset(['renamingCategoryId', 'renamingValueId', 'renameTo']);
        $this->resetValidation();
    }

    public function confirmDeleteCategory(int $categoryId): void
    {
        Gate::authorize('admin');
        $category = TagCategory::withCount('values')->findOrFail($categoryId);
        $assignments = $category->assignmentCount();

        $this->confirmTitle = __('tags.confirm.delete_category.title');
        $this->confirmMessage = trans_choice('tags.confirm.delete_category.values', $category->values_count, ['name' => $category->name, 'count' => $category->values_count])
            .' '.trans_choice("tags.confirm.category_assignments.{$category->scope->value}", $assignments, ['count' => $assignments]);
        $this->needsConfirmation('deleteCategory', $categoryId);
    }

    public function deleteCategory(int $categoryId): void
    {
        Gate::authorize('admin');
        TagCategory::findOrFail($categoryId)->delete();
    }

    public function confirmDeleteValue(int $valueId): void
    {
        Gate::authorize('admin');
        $value = TagValue::with('category')->withCount('assignments')->findOrFail($valueId);

        $this->confirmTitle = __('tags.confirm.delete_value.title');
        $this->confirmMessage = trans_choice(
            "tags.confirm.value_assignments.{$value->category->scope->value}",
            $value->assignments_count,
            ['name' => $value->name, 'count' => $value->assignments_count],
        );
        $this->needsConfirmation('deleteValue', $valueId);
    }

    public function deleteValue(int $valueId): void
    {
        Gate::authorize('admin');
        TagValue::findOrFail($valueId)->delete();
    }

    /** wire:sort hands over the item key and its new 0-based position. */
    public function sortCategory(int $categoryId, int $position): void
    {
        Gate::authorize('admin');
        TagCategory::findOrFail($categoryId)->moveTo($position);
    }

    public function sortValue(int $valueId, int $position): void
    {
        Gate::authorize('admin');
        TagValue::findOrFail($valueId)->moveTo($position);
    }

    private function currentScope(): TagScope
    {
        return TagScope::tryFrom($this->scope) ?? TagScope::Persona;
    }

    /** @return array<int, mixed> */
    private function categoryNameRules(TagScope $scope, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'max:50', Rule::unique('tag_categories', 'name')->where('scope', $scope->value)->ignore($ignoreId)];
    }

    /** @return array<int, mixed> */
    private function valueNameRules(TagCategory $category, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'max:50', Rule::unique('tag_values', 'name')->where('tag_category_id', $category->id)->ignore($ignoreId)];
    }

    public function render(): mixed
    {
        return view('livewire.settings.tags', [
            'scopes' => TagScope::cases(),
            'categories' => TagCategory::ofScope($this->currentScope())
                ->with(['values' => fn ($query) => $query->withCount('assignments')])
                ->get(),
        ])->title(__('tags.heading'));
    }
}
```

- [ ] **Step 4: View**

`resources/views/livewire/settings/tags.blade.php`:

```blade
<section class="mx-auto w-full max-w-6xl">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('tags.heading')" :subheading="__('tags.subheading')" wide>
        <flux:radio.group wire:model.live="scope" variant="segmented" size="sm" class="mb-6">
            @foreach ($scopes as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        @if ($categories->isEmpty())
            <x-empty-state icon="tag">{{ __('tags.empty') }}</x-empty-state>
        @endif

        {{-- Categories and, inside each, its values are both sortable. Each list
             has its own handles; the inner list is a sortable of its own, so a
             value never drags its category along. --}}
        <div class="space-y-4" wire:sort="sortCategory">
            @foreach ($categories as $category)
                <flux:card size="sm" wire:key="tag-category-{{ $category->id }}" wire:sort:item="{{ $category->id }}">
                    <div class="flex items-center gap-2">
                        <span wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('tags.actions.drag') }}">
                            <flux:icon.bars-2 variant="micro" />
                        </span>

                        @if ($renamingCategoryId === $category->id)
                            <form wire:submit="saveCategoryName" class="flex flex-1 items-start gap-2">
                                <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                                <flux:button type="submit" size="sm" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
                            </form>
                        @else
                            <button type="button" class="flex-1 cursor-text text-start font-medium text-zinc-800 dark:text-white"
                                wire:click="startRenamingCategory({{ $category->id }})" title="{{ __('tags.actions.rename') }}">
                                {{ $category->name }}
                            </button>
                            <flux:button variant="ghost" size="sm" icon="trash" class="cursor-pointer text-red-600! dark:text-red-400!"
                                wire:click="confirmDeleteCategory({{ $category->id }})" :aria-label="__('tags.actions.delete_category')" />
                        @endif
                    </div>

                    <ul class="mt-3 space-y-1 ps-6" wire:sort="sortValue">
                        @foreach ($category->values as $value)
                            <li class="flex items-center gap-2" wire:key="tag-value-{{ $value->id }}" wire:sort:item="{{ $value->id }}">
                                <span wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('tags.actions.drag') }}">
                                    <flux:icon.bars-2 variant="micro" />
                                </span>

                                @if ($renamingValueId === $value->id)
                                    <form wire:submit="saveValueName" class="flex flex-1 items-start gap-2">
                                        <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                                        <flux:button type="submit" size="sm" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
                                    </form>
                                @else
                                    <button type="button" class="flex-1 cursor-text text-start text-sm"
                                        wire:click="startRenamingValue({{ $value->id }})" title="{{ __('tags.actions.rename') }}">
                                        {{ $value->name }}
                                    </button>
                                    <flux:badge size="sm" color="zinc">{{ trans_choice('tags.assignments', $value->assignments_count, ['count' => $value->assignments_count]) }}</flux:badge>
                                    <flux:button variant="ghost" size="xs" icon="x-mark" class="cursor-pointer"
                                        wire:click="confirmDeleteValue({{ $value->id }})" :aria-label="__('tags.actions.delete_value')" />
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <form wire:submit="createValue({{ $category->id }})" class="mt-3 flex items-start gap-2 ps-6">
                        <flux:input wire:model="newValueNames.{{ $category->id }}" size="sm" :placeholder="__('tags.placeholders.new_value')" class="flex-1" />
                        <flux:button type="submit" size="sm" icon="plus" class="cursor-pointer">{{ __('tags.actions.add_value') }}</flux:button>
                    </form>
                </flux:card>
            @endforeach
        </div>

        <form wire:submit="createCategory" class="mt-6 flex items-start gap-2">
            <flux:input wire:model="newCategoryName" :placeholder="__('tags.placeholders.new_category')" class="flex-1" />
            <flux:button type="submit" variant="primary" icon="plus" class="cursor-pointer">{{ __('tags.actions.add_category') }}</flux:button>
        </form>
    </x-settings.layout>
</section>
```

- [ ] **Step 5: Route und Reiter**

`routes/web.php`: Import `use App\Livewire\Settings\Tags;` und in der `auth`-Gruppe nach der Users-Route:

```php
    Route::get('settings/tags', Tags::class)->middleware('admin')->name('settings.tags');
```

`resources/views/components/settings/layout.blade.php`: In **beiden** `@if(auth()->user()?->is_admin)`-Blöcken direkt nach dem Users-Eintrag ergänzen:

```blade
                <flux:navbar.item :href="route('settings.tags')" :current="request()->routeIs('settings.tags')" wire:navigate>{{ __('settings.nav.tags') }}</flux:navbar.item>
```

```blade
                <flux:navlist.item :href="route('settings.tags')" :current="request()->routeIs('settings.tags')" wire:navigate>{{ __('settings.nav.tags') }}</flux:navlist.item>
```

- [ ] **Step 6: Sprachdateien**

`lang/de/settings.php`, `'nav'`: `'tags' => 'Tags',` — `lang/en/settings.php`, `'nav'`: `'tags' => 'Tags',`.

`lang/de/tags.php` vollständig ersetzen durch:

```php
<?php

/*
|--------------------------------------------------------------------------
| Globale Tags: Verwaltung, Formulare, Sidebar-Filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Kategorien mit festen Werten für Personas und Projekte. Die Modelle sehen sie nie.',
    'empty' => 'In diesem Bereich gibt es noch keine Kategorie.',

    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projekte',
    ],

    'assignments' => '{0}nicht zugeordnet|{1}1 Zuordnung|[2,*]:count Zuordnungen',

    'fields' => [
        'category' => 'Kategorie',
        'value' => 'Wert',
        'unset' => '—',
    ],

    'placeholders' => [
        'new_category' => 'Neue Kategorie',
        'new_value' => 'Neuer Wert',
    ],

    'actions' => [
        'add_category' => 'Kategorie anlegen',
        'add_value' => 'Hinzufügen',
        'rename' => 'Zum Umbenennen klicken',
        'drag' => 'Zum Sortieren ziehen',
        'delete_category' => 'Kategorie löschen',
        'delete_value' => 'Wert löschen',
    ],

    'confirm' => [
        'delete_category' => [
            'title' => 'Kategorie löschen?',
            'values' => '{0}Die Kategorie „:name“ hat keine Werte.|{1}Die Kategorie „:name“ und ihr einziger Wert werden gelöscht.|[2,*]Die Kategorie „:name“ und ihre :count Werte werden gelöscht.',
        ],
        'delete_value' => [
            'title' => 'Wert löschen?',
        ],
        'category_assignments' => [
            'persona' => '{0}Keiner Persona ist ein Wert davon zugeordnet.|{1}1 Persona verliert damit ihre Zuordnung.|[2,*]:count Personas verlieren damit ihre Zuordnung.',
            'project' => '{0}Keinem Projekt ist ein Wert davon zugeordnet.|{1}1 Projekt verliert damit seine Zuordnung.|[2,*]:count Projekte verlieren damit ihre Zuordnung.',
        ],
        'value_assignments' => [
            'persona' => '{0}Der Wert „:name“ ist keiner Persona zugeordnet.|{1}Der Wert „:name“ ist 1 Persona zugeordnet; diese Zuordnung wird gelöscht.|[2,*]Der Wert „:name“ ist :count Personas zugeordnet; diese Zuordnungen werden gelöscht.',
            'project' => '{0}Der Wert „:name“ ist keinem Projekt zugeordnet.|{1}Der Wert „:name“ ist 1 Projekt zugeordnet; diese Zuordnung wird gelöscht.|[2,*]Der Wert „:name“ ist :count Projekten zugeordnet; diese Zuordnungen werden gelöscht.',
        ],
    ],
];
```

`lang/en/tags.php` vollständig ersetzen durch:

```php
<?php

/*
|--------------------------------------------------------------------------
| Global tags: management, forms, sidebar filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Categories with fixed values for personas and projects. The models never see them.',
    'empty' => 'There is no category in this area yet.',

    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projects',
    ],

    'assignments' => '{0}unassigned|{1}1 assignment|[2,*]:count assignments',

    'fields' => [
        'category' => 'Category',
        'value' => 'Value',
        'unset' => '—',
    ],

    'placeholders' => [
        'new_category' => 'New category',
        'new_value' => 'New value',
    ],

    'actions' => [
        'add_category' => 'Add category',
        'add_value' => 'Add',
        'rename' => 'Click to rename',
        'drag' => 'Drag to reorder',
        'delete_category' => 'Delete category',
        'delete_value' => 'Delete value',
    ],

    'confirm' => [
        'delete_category' => [
            'title' => 'Delete category?',
            'values' => '{0}The category “:name” has no values.|{1}The category “:name” and its only value will be deleted.|[2,*]The category “:name” and its :count values will be deleted.',
        ],
        'delete_value' => [
            'title' => 'Delete value?',
        ],
        'category_assignments' => [
            'persona' => '{0}No persona carries any of its values.|{1}1 persona loses its assignment.|[2,*]:count personas lose their assignment.',
            'project' => '{0}No project carries any of its values.|{1}1 project loses its assignment.|[2,*]:count projects lose their assignment.',
        ],
        'value_assignments' => [
            'persona' => '{0}The value “:name” is not assigned to any persona.|{1}The value “:name” is assigned to 1 persona; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count personas; these assignments will be deleted.',
            'project' => '{0}The value “:name” is not assigned to any project.|{1}The value “:name” is assigned to 1 project; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count projects; these assignments will be deleted.',
        ],
    ],
];
```

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter='Tests\\Feature\\Settings\\TagsTest'` → PASS. Dann `php artisan test` → grün.

- [ ] **Step 8: Pint und Commit**

```bash
vendor/bin/pint app/Livewire/Settings/Tags.php routes/web.php lang/de/tags.php lang/en/tags.php lang/de/settings.php lang/en/settings.php tests/Feature/Settings/TagsTest.php
git add app/Livewire/Settings/Tags.php resources/views/livewire/settings/tags.blade.php routes/web.php resources/views/components/settings/layout.blade.php lang/de/tags.php lang/en/tags.php lang/de/settings.php lang/en/settings.php tests/Feature/Settings/TagsTest.php
git commit -m "$(cat <<'EOF'
Let admins manage tag categories and values in the settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `TagFields` in den drei Formularen, Badges in Liste und Projekteinstellungen

**Files:**
- Create: `app/Livewire/Tags/TagFields.php`, `resources/views/livewire/tags/tag-fields.blade.php`
- Create: `resources/views/components/tags/badges.blade.php`
- Modify: `app/Livewire/Projects/CreateProject.php` + `resources/views/livewire/projects/create-project.blade.php`
- Modify: `app/Livewire/Projects/EditProject.php` + `resources/views/livewire/projects/edit-project.blade.php`
- Modify: `app/Livewire/Experts/ExpertEditor.php` + `resources/views/livewire/experts/expert-editor.blade.php`
- Modify: `app/Livewire/Experts/ExpertList.php` + `resources/views/livewire/experts/expert-list.blade.php`
- Modify: `resources/views/components/contributors/contributors-card.blade.php` (optionaler Slot `badges`)
- Modify: `lang/de/tags.php`, `lang/en/tags.php` (`form`)
- Test: `tests/Feature/Tags/TagFieldsTest.php`

**Interfaces:**
- Consumes: `TagCategory::ofScope()`, `TagScope`, `HasTags::tagSelection()`, `HasTags::syncTags()` (Task 2).
- Produces:
  - Livewire `App\Livewire\Tags\TagFields` (`<livewire:tags.tag-fields scope="project|persona" wire:model="tagSelection" />`), `#[Locked] public string $scope`, `#[Modelable] public array $selection`.
  - In `CreateProject`, `EditProject`, `ExpertEditor`: `public array $tagSelection = []`. `ExpertEditor` zusätzlich `#[Locked] public int $formKey = 0`.
  - Blade-Komponente `<x-tags.badges :values="$model->tagValues" />` (erwartet `tagValues` mit geladenem `category`).

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Tags/TagFieldsTest.php`:

```php
<?php

namespace Tests\Feature\Tags;

use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Experts\ExpertList;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Livewire\Tags\TagFields;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagFieldsTest extends TestCase
{
    use RefreshDatabase;

    private TagCategory $condition;

    private TagValue $status;

    private TagValue $control;

    private TagCategory $gender;

    private TagValue $female;

    protected function setUp(): void
    {
        parent::setUp();

        $this->condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $this->status = $this->condition->values()->create(['name' => 'status']);
        $this->control = $this->condition->values()->create(['name' => 'kontrolle']);
        $this->gender = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $this->female = $this->gender->values()->create(['name' => 'weiblich']);
    }

    public function test_it_shows_one_select_per_category_of_its_own_scope(): void
    {
        $component = Livewire::test(TagFields::class, ['scope' => 'project']);

        $this->assertSame(['bedingung'], $component->viewData('categories')->pluck('name')->all());
        $component->assertSee('kontrolle')->assertDontSee('weiblich');
    }

    public function test_it_renders_nothing_without_categories(): void
    {
        TagCategory::query()->delete();

        Livewire::test(TagFields::class, ['scope' => 'persona'])->assertDontSeeHtml('<select');
    }

    public function test_creating_a_project_stores_the_selection(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('title', 'Schule | with status | 100')
            ->set('description', 'Handyverbot an Schulen?')
            ->set('tagSelection', [$this->condition->id => (string) $this->status->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('status', Project::sole()->tagFor($this->condition)?->name);
    }

    public function test_editing_a_project_loads_and_changes_the_selection(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->setTag($this->condition, $this->status);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('tagSelection', [$this->condition->id => $this->status->id])
            ->set("tagSelection.{$this->condition->id}", (string) $this->control->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('kontrolle', $project->fresh()->tagFor($this->condition)?->name);

        Livewire::test(EditProject::class, ['project' => $project->fresh()])
            ->set("tagSelection.{$this->condition->id}", '')
            ->call('save');

        $this->assertNull($project->fresh()->tagFor($this->condition));
    }

    public function test_only_the_owner_may_change_a_projects_tags(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($reader);
        $this->actingAs($reader);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('tagSelection', [$this->condition->id => (string) $this->status->id])
            ->call('save')
            ->assertForbidden();

        $this->assertNull($project->fresh()->tagFor($this->condition));
    }

    public function test_the_expert_editor_loads_and_saves_the_persona_tags(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet('tagSelection', [])
            ->set('tagSelection', [$this->gender->id => (string) $this->female->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('weiblich', $expert->fresh()->tagFor($this->gender)?->name);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet('tagSelection', [$this->gender->id => $this->female->id]);
    }

    public function test_the_expert_list_shows_the_values_as_badges(): void
    {
        $this->actingAs(User::factory()->create());
        Expert::factory()->create(['name' => 'Ada'])->setTag($this->gender, $this->female);

        Livewire::test(ExpertList::class)->assertSee('weiblich');
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=TagFieldsTest`
Expected: FAIL — `App\Livewire\Tags\TagFields` fehlt.

- [ ] **Step 3: `TagFields`**

`app/Livewire/Tags/TagFields.php`:

```php
<?php

namespace App\Livewire\Tags;

use App\Models\TagCategory;
use App\Models\TagScope;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * One select per tag category of a scope, bound to the parent form through
 * wire:model. It never writes: the parent loads the selection with
 * tagSelection() and stores it in its own save() with syncTags(), so the tags
 * travel in the same request — and under the same authorization — as every
 * other field of the form.
 */
class TagFields extends Component
{
    #[Locked]
    public string $scope;

    /** @var array<int|string, int|string|null> category id => value id; '' means not set */
    #[Modelable]
    public array $selection = [];

    public function mount(string $scope): void
    {
        $this->scope = (TagScope::tryFrom($scope) ?? throw new InvalidArgumentException("Unknown tag scope [{$scope}]."))->value;
    }

    public function render(): mixed
    {
        return view('livewire.tags.tag-fields', [
            'categories' => TagCategory::ofScope(TagScope::from($this->scope))->with('values')->get(),
        ]);
    }
}
```

`resources/views/livewire/tags/tag-fields.blade.php`:

```blade
{{-- Livewire needs a root element even when there is nothing to choose. --}}
<div>
    @if ($categories->isNotEmpty())
        <div class="space-y-4">
            @foreach ($categories as $category)
                <flux:select wire:key="tag-field-{{ $category->id }}" wire:model="selection.{{ $category->id }}" :label="$category->name">
                    <option value="">{{ __('tags.fields.unset') }}</option>
                    @foreach ($category->values as $value)
                        <option value="{{ $value->id }}">{{ $value->name }}</option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 4: Badges**

`resources/views/components/tags/badges.blade.php`:

```blade
@props([
    'values', {{-- Collection<TagValue> with category loaded --}}
])

@if ($values->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach ($values->sortBy(fn ($value) => [$value->category->position, $value->category->id]) as $value)
            <flux:badge size="sm" color="zinc" :title="$value->category->name">{{ $value->name }}</flux:badge>
        @endforeach
    </div>
@endif
```

`resources/views/components/contributors/contributors-card.blade.php`: direkt nach dem `@if (filled($description)) … @endif`-Block, noch innerhalb von `</flux:card>`, einfügen:

```blade
        @isset($badges)
            <div class="mt-3">{{ $badges }}</div>
        @endisset
```

- [ ] **Step 5: `CreateProject`**

`app/Livewire/Projects/CreateProject.php`: Imports `use App\Models\TagCategory;` und `use App\Models\TagScope;`. Property ergänzen:

```php
    /** @var array<int|string, int|string|null> bound to Tags\TagFields; category id => value id */
    public array $tagSelection = [];
```

In `save()` direkt nach `$project->save();`:

```php
        $project->syncTags($this->tagSelection);
```

In `render()` das Array um `'hasTagCategories' => TagCategory::ofScope(TagScope::Project)->exists(),` ergänzen.

`resources/views/livewire/projects/create-project.blade.php`: im `<flux:accordion>` nach dem zweiten `flux:accordion.item` einfügen:

```blade
                @if ($hasTagCategories)
                    <flux:accordion.item transition="true">
                        <flux:accordion.heading>
                            <div class="flex gap-2">
                                <flux:icon.tag class="size-5"/> {{ __('tags.form.heading') }}
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content class="my-4 space-y-4">
                            <flux:text size="sm">{{ __('tags.form.help') }}</flux:text>
                            <livewire:tags.tag-fields scope="project" wire:model="tagSelection" />
                        </flux:accordion.content>
                    </flux:accordion.item>
                @endif
```

- [ ] **Step 6: `EditProject`**

`app/Livewire/Projects/EditProject.php`: Imports `TagCategory`, `TagScope`. Property `public array $tagSelection = [];` (mit demselben Docblock wie in `CreateProject`). In `mount()` am Ende:

```php
        $this->tagSelection = $project->tagSelection();
```

In `save()` direkt nach `$project->save();` (also nach `Gate::authorize`):

```php
        $project->syncTags($this->tagSelection);
```

`render()`:

```php
    public function render(): mixed
    {
        return view('livewire.projects.edit-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'providers' => ModelConfig::providers(),
            'models' => ModelConfig::optionsFor($this->provider),
            'hasTagCategories' => TagCategory::ofScope(TagScope::Project)->exists(),
            'savedTags' => Project::findOrFail($this->forProjectId)->tagValues()->with('category')->get(),
        ]);
    }
```

`resources/views/livewire/projects/edit-project.blade.php`: direkt nach `</x-modal-header>` die gespeicherten Werte als Badges:

```blade
        <x-tags.badges :values="$savedTags" class="-mt-3" />
```

und im `<flux:accordion>` nach dem zweiten Item denselben Tags-Block wie in Step 5 (identisch, `scope="project"`, `wire:model="tagSelection"`).

- [ ] **Step 7: `ExpertEditor`**

`app/Livewire/Experts/ExpertEditor.php`: Imports `TagCategory`, `TagScope`. Properties:

```php
    /** @var array<int|string, int|string|null> bound to Tags\TagFields; category id => value id */
    public array $tagSelection = [];

    /**
     * Changes on every edit(): the flyout is reused for every expert, and the
     * nested TagFields must start from this expert's tags, not the last one's.
     */
    #[Locked]
    public int $formKey = 0;
```

In `edit()` nach `$this->description = …;`:

```php
        $this->tagSelection = $expert?->tagSelection() ?? [];
        $this->formKey++;
```

In `save()` direkt nach dem ersten `$expert->save();`:

```php
        $expert->syncTags($this->tagSelection);
```

In `resetForm()` `'tagSelection'` in die `reset([...])`-Liste aufnehmen. In `render()` `'hasTagCategories' => TagCategory::ofScope(TagScope::Persona)->exists(),` ergänzen.

`resources/views/livewire/experts/expert-editor.blade.php`: nach dem `<flux:textarea … description …/>` und vor `<x-modal-footer>`:

```blade
        @if ($hasTagCategories)
            <div class="space-y-3">
                <div>
                    <flux:heading size="sm">{{ __('tags.form.heading') }}</flux:heading>
                    <flux:text size="sm">{{ __('tags.form.help') }}</flux:text>
                </div>
                <livewire:tags.tag-fields scope="persona" wire:model="tagSelection" wire:key="expert-tags-{{ $formKey }}" />
            </div>
        @endif
```

- [ ] **Step 8: `ExpertList`**

`app/Livewire/Experts/ExpertList.php`: `Expert::query()` → `Expert::query()->with('tagValues.category')`.

`resources/views/livewire/experts/expert-list.blade.php`: `<x-contributors.contributors-card …/>` (selbstschließend) ersetzen durch:

```blade
                    <x-contributors.contributors-card
                        wire:key="expert-{{ $expert->id }}"
                        @click="$wire.dispatch('edit_expert', { id: {{ $expert->id }} })"
                        :name="$expert->name"
                        :role="$expert->role"
                        :avatar-url="$expert->avatar_url ?? null"
                        :description="Str::limit($expert->description, 140)"
                    >
                        @if ($expert->tagValues->isNotEmpty())
                            <x-slot:badges>
                                <x-tags.badges :values="$expert->tagValues" />
                            </x-slot:badges>
                        @endif
                    </x-contributors.contributors-card>
```

- [ ] **Step 9: Sprachdateien**

In `lang/de/tags.php` ergänzen:

```php
    'form' => [
        'heading' => 'Tags',
        'help' => 'Nur für die Auswertung. Die Modelle sehen sie nie.',
    ],
```

In `lang/en/tags.php`:

```php
    'form' => [
        'heading' => 'Tags',
        'help' => 'For the evaluation only. The models never see them.',
    ],
```

- [ ] **Step 10: Tests laufen lassen**

Run: `php artisan test --filter=TagFieldsTest` → PASS. Dann `php artisan test` → grün (`ProjectFormsTest`, `ExpertPersonaTest` unverändert grün).

- [ ] **Step 11: Pint und Commit**

```bash
vendor/bin/pint app/Livewire/Tags/TagFields.php app/Livewire/Projects/CreateProject.php app/Livewire/Projects/EditProject.php app/Livewire/Experts/ExpertEditor.php app/Livewire/Experts/ExpertList.php lang/de/tags.php lang/en/tags.php tests/Feature/Tags/TagFieldsTest.php
git add app/Livewire/Tags/TagFields.php resources/views/livewire/tags/tag-fields.blade.php resources/views/components/tags/badges.blade.php app/Livewire/Projects/CreateProject.php resources/views/livewire/projects/create-project.blade.php app/Livewire/Projects/EditProject.php resources/views/livewire/projects/edit-project.blade.php app/Livewire/Experts/ExpertEditor.php resources/views/livewire/experts/expert-editor.blade.php app/Livewire/Experts/ExpertList.php resources/views/livewire/experts/expert-list.blade.php resources/views/components/contributors/contributors-card.blade.php lang/de/tags.php lang/en/tags.php tests/Feature/Tags/TagFieldsTest.php
git commit -m "$(cat <<'EOF'
Assign tags in the persona and project forms

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Projekt-Tags in Export und Import

**Files:**
- Modify: `app/Services/ProjectTransfer/ProjectExport.php`, `app/Services/ProjectTransfer/ProjectImporter.php`
- Modify: `app/Livewire/Projects/CreateProject.php` (`createFromFile()`)
- Modify: `lang/de/projects.php`, `lang/en/projects.php` (`import.skipped_tags`)
- Test: `tests/Feature/ProjectTransferTest.php` (drei neue Methoden)

**Interfaces:**
- Consumes: `TagCategory::ofScope()`, `TagScope::Project`, `HasTags::setTag()` (Task 2).
- Produces: Export-Schlüssel `tags` (`array<string, string>`, Kategoriename → Wertname); `ProjectExport::SCHEMA_VERSION = 9`; `ProjectImporter::import()` liefert zusätzlich `'skipped_tags' => list<string>` (Einträge `"Kategorie: Wert"`).

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/ProjectTransferTest.php` Imports `use App\Models\TagCategory;`, `use App\Models\TagScope;`, `use App\Models\TagValue;` ergänzen und drei Methoden anhängen:

```php
    public function test_the_export_names_the_project_tags(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $project = Project::factory()->create();
        $project->setTag($condition, $status);

        $this->assertSame(['bedingung' => 'status'], (new ProjectExport)->toArray($project->fresh())['tags']);
        $this->assertSame(9, (new ProjectExport)->toArray($project->fresh())['schema_version']);
    }

    public function test_a_round_trip_keeps_the_tags_by_name(): void
    {
        $owner = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $source = Project::factory()->create();
        $source->setTag($condition, $status);

        $result = app(ProjectImporter::class)->import((new ProjectExport)->toArray($source->fresh()), $owner);

        $this->assertSame('status', $result['project']->fresh()->tagFor($condition)?->name);
        $this->assertSame([], $result['skipped_tags']);
    }

    public function test_unknown_tags_are_skipped_reported_and_never_created(): void
    {
        $owner = User::factory()->create();
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung'])->values()->create(['name' => 'status']);

        $result = app(ProjectImporter::class)->import([
            'project' => ['title' => 'Alt', 'description' => 'x'],
            'tags' => ['bedingung' => 'unbekannt', 'thema' => 'Schule'],
        ], $owner);

        $this->assertSame(['bedingung: unbekannt', 'thema: Schule'], $result['skipped_tags']);
        $this->assertSame(1, TagCategory::count());
        $this->assertSame(1, TagValue::count());
        $this->assertSame([], $result['project']->fresh()->tagSelection());
    }
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=ProjectTransferTest`
Expected: FAIL — Schlüssel `tags` bzw. `skipped_tags` fehlt.

- [ ] **Step 3: Export**

`app/Services/ProjectTransfer/ProjectExport.php`: `use App\Models\TagValue;` importieren, `SCHEMA_VERSION` auf `9`, und im Rückgabe-Array nach `'project' => [...]` einfügen:

```php
            // By name, not id: ids differ between instances. Only project tags —
            // persona tags belong to the experts, which are linked, not copied.
            'tags' => $project->tagValues()->with('category')->get()
                ->sortBy(fn (TagValue $value) => [$value->category->position, $value->category->id])
                ->mapWithKeys(fn (TagValue $value) => [$value->category->name => $value->name])
                ->all(),
```

- [ ] **Step 4: Import**

`app/Services/ProjectTransfer/ProjectImporter.php`: Imports `use App\Models\TagCategory;` und `use App\Models\TagScope;`. In `import()` direkt nach `$project->addContributingUser($owner);`:

```php
            $skippedTags = $this->restoreTags($project, $data['tags'] ?? []);
```

Die `return`-Zeile im Transaktions-Closure wird zu:

```php
            return ['project' => $project, 'missing_experts' => $missing, 'skipped_tags' => $skippedTags];
```

Den Docblock von `import()` auf `@return array{project: Project, missing_experts: array<int, int>, skipped_tags: list<string>}` ändern. Neue private Methode:

```php
    /**
     * Assigns the exported project tags by name. A category or value that does
     * not exist here is skipped and reported, never created: the tag set is
     * curated in the settings, not grown by whatever a file carries.
     *
     * @return list<string> "category: value" for every pair that was skipped
     */
    private function restoreTags(Project $project, mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $categories = TagCategory::ofScope(TagScope::Project)->with('values')->get()->keyBy('name');
        $skipped = [];

        foreach ($tags as $categoryName => $valueName) {
            $category = $categories->get((string) $categoryName);
            $value = $category?->values->firstWhere('name', (string) $valueName);

            if ($value === null) {
                $skipped[] = "{$categoryName}: {$valueName}";

                continue;
            }

            $project->setTag($category, $value);
        }

        return $skipped;
    }
```

- [ ] **Step 5: Meldung beim Import aus der Oberfläche**

`app/Livewire/Projects/CreateProject.php`, `createFromFile()`, nach dem `missing_experts`-Block:

```php
        if (! empty($result['skipped_tags'])) {
            session()->flash('import_tags_skipped', __('projects.import.skipped_tags', ['tags' => implode(', ', $result['skipped_tags'])]));
        }
```

`lang/de/projects.php`, `'import'`: `'skipped_tags' => 'Diese Tags gibt es hier nicht; sie wurden übersprungen: :tags',`
`lang/en/projects.php`, `'import'`: `'skipped_tags' => 'These tags do not exist here and were skipped: :tags',`

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter=ProjectTransferTest` → PASS; `php artisan test --filter=ProjectExportTest` → PASS; dann `php artisan test` → grün.

- [ ] **Step 7: Pint und Commit**

```bash
vendor/bin/pint app/Services/ProjectTransfer/ProjectExport.php app/Services/ProjectTransfer/ProjectImporter.php app/Livewire/Projects/CreateProject.php lang/de/projects.php lang/en/projects.php tests/Feature/ProjectTransferTest.php
git add app/Services/ProjectTransfer/ProjectExport.php app/Services/ProjectTransfer/ProjectImporter.php app/Livewire/Projects/CreateProject.php lang/de/projects.php lang/en/projects.php tests/Feature/ProjectTransferTest.php
git commit -m "$(cat <<'EOF'
Carry project tags through export and import by name

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `tags:seed-study` — Vorbelegung der vorhandenen Zellen

**Files:**
- Create: `app/Services/Tags/StudyTagPlan.php`, `app/Services/Tags/StudyTagSeeder.php`
- Create: `app/Console/Commands/SeedStudyTags.php`
- Test: `tests/Feature/Console/SeedStudyTagsTest.php`

**Interfaces:**
- Consumes: `TagCategory`, `TagScope`, `HasTags::setTag()` (Task 2); `Project::experts()` (Pivot `seat`).
- Produces: Artisan-Befehl `tags:seed-study {--apply}`; `App\Services\Tags\StudyTagSeeder` mit `plan(): StudyTagPlan`, `apply(StudyTagPlan $plan): void`, `conditionFor(string $title): ?string` und den Konstanten `CONDITION_CATEGORY = 'bedingung'`, `STATUS = 'status'`, `CONTROL = 'kontrolle'`, `TITLE_CATEGORY = 'titeltraeger'`, `TITLE_YES = 'ja'`.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Console/SeedStudyTagsTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedStudyTagsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<Expert>  $seats  in seat order, seat 1 first */
    private function cell(string $title, array $seats): Project
    {
        $project = Project::factory()->create(['title' => $title]);

        foreach ($seats as $expert) {
            $project->addContributingExpert($expert);
        }

        return $project;
    }

    private function tagOf(Project|Expert $model, TagScope $scope, string $category): ?string
    {
        $category = TagCategory::ofScope($scope)->where('name', $category)->first();

        return $category === null ? null : $model->fresh()->tagFor($category)?->name;
    }

    public function test_the_dry_run_shows_the_plan_and_writes_nothing(): void
    {
        [$ceo, $other] = Expert::factory()->count(2)->create();
        $this->cell('School | with status | 60', [$ceo, $other]);

        $this->artisan('tags:seed-study')
            ->expectsOutputToContain('School | with status | 60')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, TagCategory::count());
        $this->assertSame(0, Taggable::count());
    }

    public function test_apply_tags_condition_and_title_holder_and_is_idempotent(): void
    {
        [$ceo, $peer, $neutral] = Expert::factory()->count(3)->create();
        $status = $this->cell('School | with status | 60', [$ceo, $peer]);
        $control = $this->cell('Traffic | without status | 60', [$neutral, $peer]);
        $untitled = $this->cell('Pilot', [$peer]);

        $this->artisan('tags:seed-study', ['--apply' => true])->assertSuccessful();
        $this->artisan('tags:seed-study', ['--apply' => true])->assertSuccessful();

        $this->assertSame('status', $this->tagOf($status, TagScope::Project, 'bedingung'));
        $this->assertSame('kontrolle', $this->tagOf($control, TagScope::Project, 'bedingung'));
        $this->assertNull($this->tagOf($untitled, TagScope::Project, 'bedingung'));
        $this->assertSame('ja', $this->tagOf($ceo, TagScope::Persona, 'titeltraeger'));
        $this->assertNull($this->tagOf($neutral, TagScope::Persona, 'titeltraeger'));
        $this->assertSame(2, TagCategory::count());
        $this->assertSame(3, Taggable::count());
    }

    public function test_a_title_holder_who_also_sits_in_a_control_cell_is_reported_not_tagged(): void
    {
        [$both, $peer] = Expert::factory()->count(2)->create();
        $this->cell('School | with status | 60', [$both, $peer]);
        $this->cell('School | without status | 60', [$peer, $both]);

        $this->artisan('tags:seed-study', ['--apply' => true])
            ->expectsOutputToContain('Conflict')
            ->assertSuccessful();

        $this->assertNull($this->tagOf($both, TagScope::Persona, 'titeltraeger'));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=SeedStudyTagsTest`
Expected: FAIL — `The command "tags:seed-study" does not exist.`

- [ ] **Step 3: Plan-Objekt**

`app/Services/Tags/StudyTagPlan.php`:

```php
<?php

namespace App\Services\Tags;

use App\Models\Expert;
use App\Models\Project;

/** What tags:seed-study would write, worked out before anything is written. */
final readonly class StudyTagPlan
{
    /**
     * @param  list<array{project: Project, condition: string}>  $projects  cells whose title names a condition
     * @param  list<array{expert: Expert, projects: list<int>}>  $titleHolders  seat-1 personas of status cells
     * @param  list<array{expert: Expert, status: list<int>, control: list<int>}>  $conflicts  seat 1 in a status cell, seated in a control cell too
     * @param  list<Project>  $withoutSeatOne  status cells with nobody on seat 1
     * @param  int  $unmatched  projects whose title names no condition
     */
    public function __construct(
        public array $projects,
        public array $titleHolders,
        public array $conflicts,
        public array $withoutSeatOne,
        public int $unmatched,
    ) {}
}
```

- [ ] **Step 4: Seeder**

`app/Services/Tags/StudyTagSeeder.php`:

```php
<?php

namespace App\Services\Tags;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Support\Facades\DB;

/**
 * Tags the study cells that ran before tags existed: the condition from the
 * title, and the persona on seat 1 of every status cell as the title holder —
 * unless that persona also sits in a control cell, where it held no title, in
 * which case it is reported instead of tagged.
 */
class StudyTagSeeder
{
    public const CONDITION_CATEGORY = 'bedingung';

    public const STATUS = 'status';

    public const CONTROL = 'kontrolle';

    public const TITLE_CATEGORY = 'titeltraeger';

    public const TITLE_YES = 'ja';

    /** "without" first: "with status" is not a substring of it, but the order states the intent. */
    public function conditionFor(string $title): ?string
    {
        if (preg_match('/\bwithout status\b/i', $title)) {
            return self::CONTROL;
        }

        if (preg_match('/\bwith status\b/i', $title)) {
            return self::STATUS;
        }

        return null;
    }

    public function plan(): StudyTagPlan
    {
        $projects = Project::with('experts')->orderBy('id')->get();
        $cells = [];
        $seatedInControl = [];
        $titled = [];
        $withoutSeatOne = [];

        foreach ($projects as $project) {
            $condition = $this->conditionFor($project->title);

            if ($condition === null) {
                continue;
            }

            $cells[] = ['project' => $project, 'condition' => $condition];

            if ($condition === self::CONTROL) {
                foreach ($project->experts as $expert) {
                    $seatedInControl[$expert->id][] = $project->id;
                }

                continue;
            }

            $holder = $project->experts->first(fn (Expert $expert) => (int) $expert->pivot->seat === 1);

            if ($holder === null) {
                $withoutSeatOne[] = $project;

                continue;
            }

            $titled[$holder->id]['expert'] = $holder;
            $titled[$holder->id]['projects'][] = $project->id;
        }

        $titleHolders = [];
        $conflicts = [];

        foreach ($titled as $expertId => $row) {
            if (isset($seatedInControl[$expertId])) {
                $conflicts[] = ['expert' => $row['expert'], 'status' => $row['projects'], 'control' => $seatedInControl[$expertId]];
            } else {
                $titleHolders[] = $row;
            }
        }

        return new StudyTagPlan($cells, $titleHolders, $conflicts, $withoutSeatOne, $projects->count() - count($cells));
    }

    /** Idempotent: categories and values are found or created, and setTag replaces. */
    public function apply(StudyTagPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $condition = $this->category(TagScope::Project, self::CONDITION_CATEGORY);
            $values = [
                self::STATUS => $this->value($condition, self::STATUS),
                self::CONTROL => $this->value($condition, self::CONTROL),
            ];

            foreach ($plan->projects as $row) {
                $row['project']->setTag($condition, $values[$row['condition']]);
            }

            $titleCategory = $this->category(TagScope::Persona, self::TITLE_CATEGORY);
            $yes = $this->value($titleCategory, self::TITLE_YES);

            foreach ($plan->titleHolders as $row) {
                $row['expert']->setTag($titleCategory, $yes);
            }
        });
    }

    private function category(TagScope $scope, string $name): TagCategory
    {
        return TagCategory::firstOrCreate(['scope' => $scope->value, 'name' => $name]);
    }

    private function value(TagCategory $category, string $name): TagValue
    {
        return $category->values()->firstOrCreate(['name' => $name]);
    }
}
```

- [ ] **Step 5: Befehl**

`app/Console/Commands/SeedStudyTags.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Tags\StudyTagSeeder;
use Illuminate\Console\Command;

/**
 * One-off: tags the study cells that ran before tags existed. A dry run by
 * default — the plan has to be read before anything is written.
 */
class SeedStudyTags extends Command
{
    protected $signature = 'tags:seed-study {--apply : Write the planned tags; without it the command only shows them}';

    protected $description = 'Tag the existing study cells: condition from the title, the seat-1 persona of each status cell as title holder.';

    public function handle(StudyTagSeeder $seeder): int
    {
        $plan = $seeder->plan();

        $this->table(
            ['Project', 'Title', StudyTagSeeder::CONDITION_CATEGORY],
            array_map(fn (array $row) => [$row['project']->id, str($row['project']->title)->limit(50), $row['condition']], $plan->projects),
        );

        $this->table(
            ['Expert', 'Name', StudyTagSeeder::TITLE_CATEGORY, 'Status cells'],
            array_map(fn (array $row) => [$row['expert']->id, $row['expert']->name, StudyTagSeeder::TITLE_YES, implode(', ', $row['projects'])], $plan->titleHolders),
        );

        foreach ($plan->conflicts as $conflict) {
            $this->warn(sprintf(
                'Conflict: %s (E%d) holds seat 1 in status cells %s but also sits in control cells %s — not tagged.',
                $conflict['expert']->name, $conflict['expert']->id, implode(', ', $conflict['status']), implode(', ', $conflict['control']),
            ));
        }

        foreach ($plan->withoutSeatOne as $project) {
            $this->warn("Status cell {$project->id} has nobody on seat 1 — no title holder.");
        }

        $this->line("{$plan->unmatched} project(s) name no condition in their title and stay untouched.");

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Run again with --apply to write these tags.');

            return self::SUCCESS;
        }

        $seeder->apply($plan);
        $this->info('Tags written.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter=SeedStudyTagsTest` → PASS; dann `php artisan test` → grün.

- [ ] **Step 7: Pint und Commit**

**Nicht** `tags:seed-study` gegen die echte Datenbank ausführen; das macht der Nutzer nach Sichtung des Trockenlaufs.

```bash
vendor/bin/pint app/Services/Tags/StudyTagPlan.php app/Services/Tags/StudyTagSeeder.php app/Console/Commands/SeedStudyTags.php tests/Feature/Console/SeedStudyTagsTest.php
git add app/Services/Tags/StudyTagPlan.php app/Services/Tags/StudyTagSeeder.php app/Console/Commands/SeedStudyTags.php tests/Feature/Console/SeedStudyTagsTest.php
git commit -m "$(cat <<'EOF'
Add tags:seed-study to tag the existing study cells

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Optionale Turn-Grenze in `SpokenTurns`, `SpeakingShares`, `ShareTrajectory`

**Files:**
- Modify: `app/Discussion/Metrics/SpokenTurns.php`, `app/Discussion/Metrics/SpeakingShares.php`, `app/Discussion/Metrics/ShareTrajectory.php`
- Test: `tests/Feature/Discussion/SpeakingSharesTest.php`, `tests/Unit/Discussion/ShareTrajectoryTest.php` (je neue Methoden)

**Interfaces:**
- Consumes: nichts Neues.
- Produces:
  - `SpokenTurns::firstTurns(Project $project, ?int $limit = null): Builder` — die ersten `$limit` gesprochenen Turns in Reihenfolge, alle bei `null`; wirft `InvalidArgumentException` bei `$limit < 1`.
  - `SpeakingShares::forProject(Project $project, ?int $turnLimit = null): ShareReport`
  - `ShareTrajectory::forProject(Project $project, ?int $window = null, ?int $turnLimit = null): Trajectory`
  - Standardverhalten (ohne Grenze) unverändert; `ShareReport::$failedTurns` bleibt laufweit.

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/Discussion/SpeakingSharesTest.php` (Import `use InvalidArgumentException;`):

```php
    public function test_a_turn_limit_counts_only_the_first_spoken_turns(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Dave', null, status: 'failed');
        $this->turn('Bob', 20);
        $this->turn('Carol', 30);

        $report = app(SpeakingShares::class)->forProject($this->project->fresh(), turnLimit: 2);

        $this->assertSame(2, $report->spokenTurns);
        $this->assertSame(30, $report->totalWords);
        $this->assertSame(0.5, $report->speakers[0]->turnShare);
        $this->assertSame(0, $report->speakers[2]->turns);
    }

    public function test_a_turn_limit_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SpeakingShares::class)->forProject($this->project->fresh(), turnLimit: 0);
    }
```

In `tests/Unit/Discussion/ShareTrajectoryTest.php`:

```php
    public function test_a_turn_limit_cuts_the_curve_where_the_totals_stop(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Alice', 10);
        $this->turn('Bob', 10);
        $this->turn('Bob', 10);

        $trajectory = app(ShareTrajectory::class)->forProject($this->project->fresh(), turnLimit: 2);
        $report = app(SpeakingShares::class)->forProject($this->project->fresh(), turnLimit: 2);

        $this->assertCount(2, $trajectory->points);
        $this->assertSame(1.0, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
        $this->assertSame($report->speakers[0]->turnShare, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
    }
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter='SpeakingSharesTest|ShareTrajectoryTest'`
Expected: FAIL — `Unknown named parameter $turnLimit`.

- [ ] **Step 3: `SpokenTurns::firstTurns()`**

In `app/Discussion/Metrics/SpokenTurns.php` (Import `use InvalidArgumentException;`), nach `inOrder()`:

```php
    /**
     * The first $limit spoken turns in the order they were taken, or all of
     * them for null. The one place a turn limit is applied, so the totals and
     * the curve of a limited run stop at the same turn.
     *
     * @return Builder<JobLog>
     */
    public function firstTurns(Project $project, ?int $limit = null): Builder
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException("A turn limit must be at least 1, got {$limit}.");
        }

        return $this->inOrder($project)->when($limit !== null, fn (Builder $turns) => $turns->limit($limit));
    }
```

- [ ] **Step 4: `SpeakingShares`**

`app/Discussion/Metrics/SpeakingShares.php` (Import `use Illuminate\Support\Facades\DB;`): Signatur und erste Abfrage ersetzen:

```php
    /**
     * @param  int|null  $turnLimit  count only the first N spoken turns; null counts the whole run.
     *                               failedTurns stays the run's total either way.
     */
    public function forProject(Project $project, ?int $turnLimit = null): ShareReport
    {
        // Aggregated over a subquery, so a limit cuts the turns before they are
        // added up rather than the groups after.
        $counted = DB::query()
            ->fromSub($this->spoken->firstTurns($project, $turnLimit)->select(['expert_id', 'words']), 'spoken')
            ->selectRaw('expert_id, count(*) as turns, sum(words) as words')
            ->groupBy('expert_id')
            ->get()
            ->keyBy('expert_id');
```

Der Rest der Methode bleibt unverändert.

- [ ] **Step 5: `ShareTrajectory`**

`app/Discussion/Metrics/ShareTrajectory.php`: Signatur und Docblock:

```php
    /**
     * @param  int|null  $window  spoken turns a rolling share spans; null reads
     *                            config('discussion.share_window')
     * @param  int|null  $turnLimit  only the first N spoken turns; null for the whole run
     */
    public function forProject(Project $project, ?int $window = null, ?int $turnLimit = null): Trajectory
```

und die Zeile `$turns = $this->spoken->inOrder($project)->get(['expert_id', 'words'])->all();` ersetzen durch:

```php
        $turns = $this->spoken->firstTurns($project, $turnLimit)->get(['expert_id', 'words'])->all();
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter='SpeakingSharesTest|ShareTrajectoryTest|StatisticsFlyoutTest|ProjectCommandsTest'` → PASS; dann `php artisan test` → grün.

- [ ] **Step 7: Pint und Commit**

```bash
vendor/bin/pint app/Discussion/Metrics/SpokenTurns.php app/Discussion/Metrics/SpeakingShares.php app/Discussion/Metrics/ShareTrajectory.php tests/Feature/Discussion/SpeakingSharesTest.php tests/Unit/Discussion/ShareTrajectoryTest.php
git add app/Discussion/Metrics/SpokenTurns.php app/Discussion/Metrics/SpeakingShares.php app/Discussion/Metrics/ShareTrajectory.php tests/Feature/Discussion/SpeakingSharesTest.php tests/Unit/Discussion/ShareTrajectoryTest.php
git commit -m "$(cat <<'EOF'
Let shares and trajectories stop after the first N spoken turns

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: `Metric`, `AgentGroup`, `TagFilter` und die fünf Agenten-Bezüge

**Files:**
- Create: `app/Discussion/Values/Metric.php`, `app/Discussion/Values/AgentGroup.php`, `app/Discussion/Values/TagFilter.php`
- Create: `app/Discussion/Metrics/References/AgentReference.php`, `NamesTagValues.php`, `AgentReferences.php`, `PersonaTagsReference.php`, `SeatReference.php`, `ExpertNameReference.php`, `TopReference.php`, `EachReference.php`
- Test: `tests/Unit/Discussion/AgentReferenceTest.php`

**Interfaces:**
- Consumes: `ShareReport`, `SpeakerShare`, `ShareSeries` (vorhanden); `HasTags::scopeWhereTagged()`, `TagValue` (Task 2).
- Produces:
  - `enum App\Discussion\Values\Metric: string` — `TurnShare='turn_share'`, `WordShare='word_share'`, `WordsPerTurn='words_per_turn'`, `TurnGini='turn_gini'`, `WordGini='word_gini'`; `isRunLevel(): bool`, `isShare(): bool`, `hasTrajectory(): bool`, `series(string $mode): ShareSeries`, `ofAgents(array $speakers): float`, `ofRun(ShareReport $report): float`, `label(): string`, `format(float $value): string`.
  - `final readonly class App\Discussion\Values\AgentGroup(string $key, array $expertIds, ?int $seat = null)` mit `isEmpty(): bool`.
  - `final readonly class App\Discussion\Values\TagFilter(array $byCategory = [])` — `static fromArray(mixed $data): self`, `toArray(): array<int, list<int>>`, `valueIds(): list<int>`, `isEmpty(): bool`, `without(array $ids): self`.
  - `interface App\Discussion\Metrics\References\AgentReference` — `static fromArray(array $data): static`, `toArray(): array`, `pick(Project $project, ShareReport $report, Metric $metric): list<AgentGroup>`.
  - `interface …\NamesTagValues` — `tagValueIds(): list<int>`, `withoutTagValues(array $ids): static`.
  - `final class …\AgentReferences` — `const TYPES` (Typ → Klasse), `static fromArray(array $data): AgentReference` (unbekannt → `EachReference`).
  - Klassen mit `const TYPE`: `PersonaTagsReference` (`persona_tags`, `TagFilter $tags`), `SeatReference` (`seat`, `int $seat`), `ExpertNameReference` (`expert_name`, `string $name`), `TopReference` (`top`), `EachReference` (`each`).

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/Discussion/AgentReferenceTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\ExpertNameReference;
use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Metrics\References\SeatReference;
use App\Discussion\Metrics\References\TopReference;
use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentReferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array{0: int, 1: string, 2: int, 3: int, 4: int}>  $rows  id, name, seat, turns, words */
    private function report(array $rows): ShareReport
    {
        $turns = array_sum(array_column($rows, 3));
        $words = array_sum(array_column($rows, 4));

        return new ShareReport(
            speakers: array_map(fn (array $r) => new SpeakerShare($r[0], $r[1], $r[2], $r[3], $r[4], $turns === 0 ? 0.0 : $r[3] / $turns, $words === 0 ? 0.0 : $r[4] / $words), $rows),
            spokenTurns: $turns, failedTurns: 0, totalWords: $words, turnGini: 0.0, wordGini: 0.0, giniCeiling: 0.75,
        );
    }

    /** @param  list<AgentGroup>  $groups */
    private function ids(array $groups): array
    {
        return array_map(fn (AgentGroup $g) => $g->expertIds, $groups);
    }

    public function test_seat_picks_whoever_sits_there(): void
    {
        $report = $this->report([[11, 'Ada', 1, 3, 30], [12, 'Bo', 2, 1, 10]]);

        $this->assertSame([[12]], $this->ids((new SeatReference(2))->pick(new Project, $report, Metric::TurnShare)));
        $this->assertSame([[]], $this->ids((new SeatReference(5))->pick(new Project, $report, Metric::TurnShare)));
    }

    public function test_expert_name_matches_every_seat_with_that_name(): void
    {
        $report = $this->report([[11, 'Ada', 1, 3, 30], [12, 'Bo', 2, 1, 10]]);

        $this->assertSame([[11]], $this->ids((new ExpertNameReference('Ada'))->pick(new Project, $report, Metric::TurnShare)));
    }

    public function test_top_takes_the_highest_value_and_the_lower_seat_on_a_tie(): void
    {
        $tied = $this->report([[11, 'Ada', 1, 2, 10], [12, 'Bo', 2, 2, 50]]);

        $this->assertSame([[11]], $this->ids((new TopReference)->pick(new Project, $tied, Metric::TurnShare)));
        $this->assertSame([[12]], $this->ids((new TopReference)->pick(new Project, $tied, Metric::WordShare)));
    }

    public function test_each_gives_one_group_per_seat(): void
    {
        $groups = (new EachReference)->pick(new Project, $this->report([[11, 'Ada', 1, 1, 1], [12, 'Bo', 2, 1, 1]]), Metric::TurnShare);

        $this->assertSame(['seat-1', 'seat-2'], array_map(fn (AgentGroup $g) => $g->key, $groups));
        $this->assertSame([1, 2], array_map(fn (AgentGroup $g) => $g->seat, $groups));
    }

    public function test_persona_tags_pick_the_seated_agents_that_carry_them(): void
    {
        $title = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'titeltraeger']);
        $yes = $title->values()->create(['name' => 'ja']);
        [$ceo, $peer] = Expert::factory()->count(2)->create();
        $ceo->setTag($title, $yes);

        $report = $this->report([[$ceo->id, 'Ceo', 1, 1, 1], [$peer->id, 'Peer', 2, 1, 1]]);
        $reference = new PersonaTagsReference(new TagFilter([$title->id => [$yes->id]]));

        $this->assertSame([[$ceo->id]], $this->ids($reference->pick(new Project, $report, Metric::TurnShare)));
        $this->assertSame([[]], $this->ids((new PersonaTagsReference)->pick(new Project, $report, Metric::TurnShare)));
    }

    public function test_the_combined_value_of_a_group_is_a_sum_and_words_per_turn_a_ratio(): void
    {
        $report = $this->report([[11, 'Ada', 1, 1, 10], [12, 'Bo', 2, 2, 80], [13, 'Cy', 3, 1, 10]]);
        $group = [$report->speakers[0], $report->speakers[1]];

        $this->assertSame(0.75, Metric::TurnShare->ofAgents($group));
        $this->assertSame(0.9, Metric::WordShare->ofAgents($group));
        $this->assertSame(30.0, Metric::WordsPerTurn->ofAgents($group));
    }

    public function test_saved_forms_round_trip_and_unknown_types_fall_back_to_each(): void
    {
        foreach ([
            ['type' => 'seat', 'seat' => 3],
            ['type' => 'expert_name', 'name' => 'Ada'],
            ['type' => 'top'],
            ['type' => 'each'],
            ['type' => 'persona_tags', 'tags' => [4 => [7, 9]]],
        ] as $saved) {
            $this->assertSame($saved, AgentReferences::fromArray($saved)->toArray());
        }

        $this->assertInstanceOf(EachReference::class, AgentReferences::fromArray(['type' => 'nope']));
        $this->assertSame(['type' => 'seat', 'seat' => 1], AgentReferences::fromArray(['type' => 'seat', 'seat' => 'x'])->toArray());
    }

    public function test_tag_filter_normalises_form_input(): void
    {
        $filter = TagFilter::fromArray(['3' => ['5', '5', ''], '4' => [], 'x' => ['1']]);

        $this->assertSame([3 => [5]], $filter->toArray());
        $this->assertSame([], $filter->without([5])->toArray());
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=AgentReferenceTest`
Expected: FAIL — Klassen fehlen.

- [ ] **Step 3: `Metric`**

`app/Discussion/Values/Metric.php`:

```php
<?php

namespace App\Discussion\Values;

use LogicException;

/**
 * What the statistics page measures. Two kinds: per-agent measures (shares and
 * words per turn) that a reference narrows down to some agents, and run-level
 * measures (Gini) that describe the whole group and ignore the reference.
 */
enum Metric: string
{
    case TurnShare = 'turn_share';
    case WordShare = 'word_share';
    case WordsPerTurn = 'words_per_turn';
    case TurnGini = 'turn_gini';
    case WordGini = 'word_gini';

    public function isRunLevel(): bool
    {
        return $this === self::TurnGini || $this === self::WordGini;
    }

    public function isShare(): bool
    {
        return $this === self::TurnShare || $this === self::WordShare;
    }

    /** Only the two shares have a curve over the run (ShareTrajectory). */
    public function hasTrajectory(): bool
    {
        return $this->isShare();
    }

    /** The trajectory series holding this metric's curve; $mode is 'cumulative' or 'rolling'. */
    public function series(string $mode): ShareSeries
    {
        $unit = $this === self::WordShare ? 'words' : 'turns';

        return ShareSeries::tryFrom("{$mode}_{$unit}") ?? ShareSeries::from("cumulative_{$unit}");
    }

    /**
     * The value for agents taken together: shares add up, and words per turn is
     * the group's words over the group's turns — never an average of averages.
     *
     * @param  list<SpeakerShare>  $speakers
     */
    public function ofAgents(array $speakers): float
    {
        $sum = fn (callable $of) => (float) array_sum(array_map($of, $speakers));

        return match ($this) {
            self::TurnShare => $sum(fn (SpeakerShare $s) => $s->turnShare),
            self::WordShare => $sum(fn (SpeakerShare $s) => $s->wordShare),
            self::WordsPerTurn => ($turns = $sum(fn (SpeakerShare $s) => $s->turns)) === 0.0
                ? 0.0
                : $sum(fn (SpeakerShare $s) => $s->words) / $turns,
            self::TurnGini, self::WordGini => throw new LogicException("{$this->value} describes the run, not a group of agents."),
        };
    }

    public function ofRun(ShareReport $report): float
    {
        return match ($this) {
            self::TurnGini => $report->turnGini,
            self::WordGini => $report->wordGini,
            default => throw new LogicException("{$this->value} is measured per agent, not per run."),
        };
    }

    public function label(): string
    {
        return __("comparisons.metrics.{$this->value}");
    }

    /** In the locale's notation, as the statistics flyout writes numbers. */
    public function format(float $value): string
    {
        return match (true) {
            $this->isShare() => number_format($value * 100, 1, ',', '.').' %',
            $this === self::WordsPerTurn => number_format($value, 1, ',', '.'),
            default => number_format($value, 2, ',', '.'),
        };
    }
}
```

- [ ] **Step 4: `AgentGroup` und `TagFilter`**

`app/Discussion/Values/AgentGroup.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * The agents of one run a series looks at. Most references yield one group
 * (key ''); `each` yields one per seat. Several agents in a group count
 * together; an empty group means the run falls out of that line.
 */
final readonly class AgentGroup
{
    /** @param  list<int>  $expertIds */
    public function __construct(
        public string $key,
        public array $expertIds,
        public ?int $seat = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->expertIds === [];
    }
}
```

`app/Discussion/Values/TagFilter.php`:

```php
<?php

namespace App\Discussion\Values;

/**
 * Tag values chosen per category: OR within a category, AND across them —
 * the rule HasTags::whereTagged() applies. Normalises form input, where ids
 * arrive as strings and empty choices as ''.
 */
final readonly class TagFilter
{
    /** @param  array<int, list<int>>  $byCategory  category id => value ids */
    public function __construct(public array $byCategory = []) {}

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $map = [];

        foreach ($data as $categoryId => $valueIds) {
            $ids = array_values(array_unique(array_map('intval', array_filter((array) $valueIds, 'is_numeric'))));

            if (is_numeric($categoryId) && $ids !== []) {
                $map[(int) $categoryId] = $ids;
            }
        }

        ksort($map);

        return new self($map);
    }

    /** @return array<int, list<int>> */
    public function toArray(): array
    {
        return $this->byCategory;
    }

    /** @return list<int> */
    public function valueIds(): array
    {
        return array_values(array_merge([], ...array_values($this->byCategory)));
    }

    public function isEmpty(): bool
    {
        return $this->byCategory === [];
    }

    /** @param  list<int>  $ids */
    public function without(array $ids): self
    {
        return self::fromArray(array_map(fn (array $values) => array_values(array_diff($values, $ids)), $this->byCategory));
    }
}
```

- [ ] **Step 5: Schnittstellen und Registry**

`app/Discussion/Metrics/References/AgentReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/**
 * Which agents of a run a series looks at. A new kind of reference is a new
 * class plus one line in AgentReferences::TYPES — never a condition elsewhere.
 */
interface AgentReference
{
    /** Builds the reference from its saved form; missing or broken parameters fall back to safe defaults. */
    public static function fromArray(array $data): static;

    /** The saved form, `type` included. */
    public function toArray(): array;

    /**
     * @param  ShareReport  $report  the run's shares, speakers in seat order
     * @param  Metric  $metric  the page's measure (only `top` reads it)
     * @return list<AgentGroup>
     */
    public function pick(Project $project, ShareReport $report, Metric $metric): array;
}
```

`app/Discussion/Metrics/References/NamesTagValues.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

/** A reference that filters by tag values, so deleted values can be found and dropped. */
interface NamesTagValues
{
    /** @return list<int> */
    public function tagValueIds(): array;

    /** @param  list<int>  $ids */
    public function withoutTagValues(array $ids): static;
}
```

`app/Discussion/Metrics/References/AgentReferences.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

/** The known references by their saved `type`. */
final class AgentReferences
{
    /** @var array<string, class-string<AgentReference>> */
    public const TYPES = [
        PersonaTagsReference::TYPE => PersonaTagsReference::class,
        SeatReference::TYPE => SeatReference::class,
        ExpertNameReference::TYPE => ExpertNameReference::class,
        TopReference::TYPE => TopReference::class,
        EachReference::TYPE => EachReference::class,
    ];

    /** An unknown type reads as `each`: it hides nothing and changes no number silently. */
    public static function fromArray(array $data): AgentReference
    {
        $class = self::TYPES[$data['type'] ?? ''] ?? EachReference::class;

        return $class::fromArray($data);
    }
}
```

- [ ] **Step 6: Die fünf Bezüge**

`app/Discussion/Metrics/References/SeatReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Project;

/** Whoever sits on one seat. */
final class SeatReference implements AgentReference
{
    public const TYPE = 'seat';

    public function __construct(public readonly int $seat) {}

    public static function fromArray(array $data): static
    {
        $seat = $data['seat'] ?? null;

        return new static(is_numeric($seat) && (int) $seat >= 1 ? (int) $seat : 1);
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'seat' => $this->seat];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $ids = array_map(fn (SpeakerShare $s) => $s->expertId, array_filter($report->speakers, fn (SpeakerShare $s) => $s->seat === $this->seat));

        return [new AgentGroup('', array_values($ids))];
    }
}
```

`app/Discussion/Metrics/References/ExpertNameReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Project;

/** An expert by name — across every expert record that carries it. */
final class ExpertNameReference implements AgentReference
{
    public const TYPE = 'expert_name';

    public function __construct(public readonly string $name) {}

    public static function fromArray(array $data): static
    {
        return new static(trim((string) ($data['name'] ?? '')));
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'name' => $this->name];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $ids = array_map(fn (SpeakerShare $s) => $s->expertId, array_filter($report->speakers, fn (SpeakerShare $s) => $this->name !== '' && $s->name === $this->name));

        return [new AgentGroup('', array_values($ids))];
    }
}
```

`app/Discussion/Metrics/References/TopReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/**
 * The agent with the highest value of the page's measure in this run. A tie
 * goes to the lower seat: deterministic, and no balancing in either direction.
 */
final class TopReference implements AgentReference
{
    public const TYPE = 'top';

    public static function fromArray(array $data): static
    {
        return new static;
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $best = null;
        $bestValue = -INF;

        foreach ($report->speakers as $speaker) {
            $value = $metric->ofAgents([$speaker]);

            if ($value > $bestValue) {
                $best = $speaker;
                $bestValue = $value;
            }
        }

        return [new AgentGroup('', $best === null ? [] : [$best->expertId])];
    }
}
```

`app/Discussion/Metrics/References/EachReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/** Every agent on its own: one line per seat. */
final class EachReference implements AgentReference
{
    public const TYPE = 'each';

    public static function fromArray(array $data): static
    {
        return new static;
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $groups = [];

        foreach ($report->speakers as $speaker) {
            $key = $speaker->seat === null ? "expert-{$speaker->expertId}" : "seat-{$speaker->seat}";
            $groups[] = new AgentGroup($key, [$speaker->expertId], $speaker->seat);
        }

        return $groups;
    }
}
```

`app/Discussion/Metrics/References/PersonaTagsReference.php`:

```php
<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagValue;

/**
 * The seated agents whose persona carries the chosen tags (AND across
 * categories, OR within one). A filter without any existing value matches
 * nobody — it must never quietly widen to "everyone".
 */
final class PersonaTagsReference implements AgentReference, NamesTagValues
{
    public const TYPE = 'persona_tags';

    public function __construct(public readonly TagFilter $tags = new TagFilter) {}

    public static function fromArray(array $data): static
    {
        return new static(TagFilter::fromArray($data['tags'] ?? []));
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'tags' => $this->tags->toArray()];
    }

    public function tagValueIds(): array
    {
        return $this->tags->valueIds();
    }

    public function withoutTagValues(array $ids): static
    {
        return new static($this->tags->without($ids));
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $existing = TagValue::whereKey($this->tags->valueIds())->pluck('id')->all();

        if ($existing === []) {
            return [new AgentGroup('', [])];
        }

        $ids = Expert::whereKey(array_map(fn (SpeakerShare $s) => $s->expertId, $report->speakers))
            ->whereTagged($existing)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [new AgentGroup('', $ids)];
    }
}
```

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter=AgentReferenceTest` → PASS; dann `php artisan test` → grün.

- [ ] **Step 8: Pint und Commit**

```bash
vendor/bin/pint app/Discussion/Values/Metric.php app/Discussion/Values/AgentGroup.php app/Discussion/Values/TagFilter.php app/Discussion/Metrics/References tests/Unit/Discussion/AgentReferenceTest.php
git add app/Discussion/Values/Metric.php app/Discussion/Values/AgentGroup.php app/Discussion/Values/TagFilter.php app/Discussion/Metrics/References/AgentReference.php app/Discussion/Metrics/References/NamesTagValues.php app/Discussion/Metrics/References/AgentReferences.php app/Discussion/Metrics/References/PersonaTagsReference.php app/Discussion/Metrics/References/SeatReference.php app/Discussion/Metrics/References/ExpertNameReference.php app/Discussion/Metrics/References/TopReference.php app/Discussion/Metrics/References/EachReference.php tests/Unit/Discussion/AgentReferenceTest.php
git commit -m "$(cat <<'EOF'
Add agent references for comparing runs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

(`app/Discussion/Metrics/References` ist ein neues Verzeichnis, das nur Dateien dieser Task enthält; Pint darüber ist hier unbedenklich.)

---

### Task 10: `SeriesDefinition`, `ComparisonView`, Ergebniswerte, `SeriesProjects`, `SeriesStatistics`

**Files:**
- Create: `app/Discussion/Values/SeriesDefinition.php`, `ComparisonView.php`, `ComparisonDisplay.php`, `ProjectValue.php`, `TrajectoryMean.php`, `SeriesLine.php`, `SeriesResult.php`
- Create: `app/Discussion/Metrics/SeriesProjects.php`, `app/Discussion/Metrics/SeriesStatistics.php`
- Modify: `app/Models/Project.php` (Scope `accessibleBy`)
- Test: `tests/Unit/Discussion/SeriesDefinitionTest.php`, `tests/Unit/Discussion/SeriesStatisticsTest.php`, `tests/Feature/Statistics/SeriesProjectsTest.php`

**Interfaces:**
- Consumes: `SpeakingShares::forProject(Project, ?int $turnLimit)`, `ShareTrajectory::forProject(Project, ?int, ?int)` (Task 8); `Metric`, `AgentGroup`, `TagFilter`, `AgentReference`, `AgentReferences`, `NamesTagValues`, `EachReference` (Task 9); `HasTags::scopeWhereTagged()` (Task 2).
- Produces:
  - `final readonly class SeriesDefinition(string $label, TagFilter $projectTags = new TagFilter, ?string $pipeline = null, ?string $model = null, AgentReference $reference = new EachReference)` — `static fromArray(array $data): self`, `toArray(): array` (Form: `['label' => …, 'projects' => ['tags' => [catId => [ids]], 'pipeline' => ?string, 'model' => ?string], 'reference' => [...]]`), `tagValueIds(): list<int>`, `withoutTagValues(array $ids): self`.
  - `enum ComparisonDisplay: string { Table='table'; Bars='bars'; Trajectory='trajectory' }` mit `label()`.
  - `final readonly class ComparisonView(Metric $metric = Metric::TurnShare, ComparisonDisplay $display = ComparisonDisplay::Table, ?int $turnLimit = null, string $trajectoryMode = 'cumulative')` — `static fromArray(array $data): self`, `toArray(): array` (`metric`, `display`, `turn_limit`, `trajectory_mode`).
  - `final readonly class ProjectValue(int $projectId, string $title, float $value)`.
  - `final readonly class TrajectoryMean(int $turn, float $mean, int $n)`.
  - `final readonly class SeriesLine(string $key, ?int $seat, array $values, array $curve = [])` — `n()`, `mean()`, `min()`, `max()`.
  - `final readonly class SeriesResult(string $label, array $lines, int $projects, int $excluded)`.
  - `Project::scopeAccessibleBy(Builder $query, User $user)` (Eigentümer oder Beteiligter, wie Gate `access-project`).
  - `SeriesProjects::for(User $user, SeriesDefinition $series): Collection<int, Project>` (nach `id`).
  - `SeriesStatistics::compute(SeriesDefinition $series, ComparisonView $view, iterable $projects): SeriesResult`.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/Discussion/SeriesDefinitionTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesDefinition;
use PHPUnit\Framework\TestCase;

class SeriesDefinitionTest extends TestCase
{
    public function test_form_input_is_normalised_and_round_trips(): void
    {
        $series = SeriesDefinition::fromArray([
            'label' => '  Titelträger ',
            'projects' => ['tags' => ['2' => ['5', '6']], 'pipeline' => '', 'model' => 'openai-gpt-5-mini'],
            'reference' => ['type' => 'persona_tags', 'tags' => ['9' => ['11']]],
        ]);

        $this->assertSame([
            'label' => 'Titelträger',
            'projects' => ['tags' => [2 => [5, 6]], 'pipeline' => null, 'model' => 'openai-gpt-5-mini'],
            'reference' => ['type' => 'persona_tags', 'tags' => [9 => [11]]],
        ], $series->toArray());
        $this->assertInstanceOf(PersonaTagsReference::class, $series->reference);
        $this->assertEqualsCanonicalizing([5, 6, 11], $series->tagValueIds());
    }

    public function test_deleted_tag_values_drop_out_of_project_and_persona_filters(): void
    {
        $series = SeriesDefinition::fromArray([
            'label' => 'x',
            'projects' => ['tags' => [2 => [5, 6]]],
            'reference' => ['type' => 'persona_tags', 'tags' => [9 => [11]]],
        ])->withoutTagValues([6, 11]);

        $this->assertSame([2 => [5]], $series->projectTags->toArray());
        $this->assertSame([], $series->reference->tagValueIds());
    }

    public function test_a_broken_view_falls_back_to_the_defaults(): void
    {
        $view = ComparisonView::fromArray(['metric' => 'nope', 'display' => 'bars', 'turn_limit' => '0', 'trajectory_mode' => 'sideways']);

        $this->assertSame(Metric::TurnShare, $view->metric);
        $this->assertSame(ComparisonDisplay::Bars, $view->display);
        $this->assertNull($view->turnLimit);
        $this->assertSame('cumulative', $view->trajectoryMode);
        $this->assertSame(['metric' => 'turn_share', 'display' => 'bars', 'turn_limit' => null, 'trajectory_mode' => 'cumulative'], $view->toArray());
        $this->assertSame(60, ComparisonView::fromArray(['turn_limit' => '60'])->turnLimit);
    }
}
```

`tests/Unit/Discussion/SeriesStatisticsTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesResult;
use App\Discussion\Values\TrajectoryMean;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SeriesStatistics on synthetic extreme cases whose answer can be worked out by
 * hand. It adds nothing to the counting rules; what it adds — combining agents,
 * dropping runs, cutting at a turn, averaging curves of unequal length — is
 * exactly what these cases pin down.
 */
class SeriesStatisticsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Expert> */
    private array $experts = [];

    /**
     * @param  list<string>  $seats  names in seat order
     * @param  list<array{0: string, 1: int}>  $turns  [name, words] in turn order
     */
    private function run(array $seats, array $turns): Project
    {
        $project = Project::factory()->create();

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

    private function compute(array $reference, array $view, Project ...$projects): SeriesResult
    {
        return app(SeriesStatistics::class)->compute(
            SeriesDefinition::fromArray(['label' => 'Reihe', 'reference' => $reference]),
            ComparisonView::fromArray($view),
            collect($projects),
        );
    }

    public function test_several_agents_count_with_their_combined_share(): void
    {
        $tag = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gruppe']);
        $minority = $tag->values()->create(['name' => 'minderheit']);
        $project = $this->run(['Alice', 'Bob', 'Carol', 'Dave'], [['Alice', 10], ['Alice', 10], ['Bob', 10], ['Carol', 10]]);
        $this->experts['Alice']->setTag($tag, $minority);
        $this->experts['Bob']->setTag($tag, $minority);

        $result = $this->compute(['type' => 'persona_tags', 'tags' => [$tag->id => [$minority->id]]], ['metric' => 'turn_share'], $project);

        $this->assertSame(0.75, $result->lines[0]->mean());
        $this->assertSame(0, $result->excluded);
    }

    public function test_a_run_where_the_reference_hits_nobody_drops_out_and_is_counted(): void
    {
        $project = $this->run(['Alice', 'Bob'], [['Alice', 10]]);

        $result = $this->compute(['type' => 'seat', 'seat' => 9], ['metric' => 'turn_share'], $project);

        $this->assertSame([], $result->lines);
        $this->assertSame(1, $result->projects);
        $this->assertSame(1, $result->excluded);
    }

    public function test_a_run_without_a_spoken_turn_drops_out(): void
    {
        $result = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $this->run(['Alice'], []));

        $this->assertSame(1, $result->excluded);
    }

    public function test_the_turn_limit_cuts_every_run(): void
    {
        $project = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Bob', 10], ['Bob', 10]]);

        $this->assertSame(1.0, $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'turn_limit' => 2], $project)->lines[0]->mean());
        $this->assertSame(0.5, $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $project)->lines[0]->mean());
    }

    public function test_mean_min_max_and_n_run_over_the_projects(): void
    {
        $a = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);
        $b = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Alice', 10], ['Bob', 10]]);

        $line = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $a, $b)->lines[0];

        $this->assertSame(2, $line->n());
        $this->assertSame(0.625, $line->mean());
        $this->assertSame(0.5, $line->min());
        $this->assertSame(0.75, $line->max());
    }

    public function test_curves_average_over_the_runs_that_reached_the_turn(): void
    {
        $short = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);
        $long = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Alice', 10], ['Alice', 10]]);

        $curve = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'display' => 'trajectory'], $short, $long)->lines[0]->curve;

        $this->assertEquals([
            new TrajectoryMean(1, 1.0, 2),
            new TrajectoryMean(2, 0.75, 2),
            new TrajectoryMean(3, 1.0, 1),
            new TrajectoryMean(4, 1.0, 1),
        ], $curve);
    }

    public function test_curves_are_only_drawn_for_the_trajectory_display(): void
    {
        $project = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);

        $this->assertSame([], $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'display' => 'table'], $project)->lines[0]->curve);
    }

    public function test_each_gives_one_line_per_seat_in_seat_order(): void
    {
        $project = $this->run(['Alice', 'Bob', 'Carol'], [['Carol', 10], ['Alice', 10], ['Alice', 10], ['Bob', 10]]);

        $lines = $this->compute(['type' => 'each'], ['metric' => 'turn_share'], $project)->lines;

        $this->assertSame([1, 2, 3], array_map(fn ($line) => $line->seat, $lines));
        $this->assertSame([0.5, 0.25, 0.25], array_map(fn ($line) => $line->mean(), $lines));
    }

    public function test_gini_ignores_the_reference(): void
    {
        $project = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10]]);

        $result = $this->compute(['type' => 'seat', 'seat' => 9], ['metric' => 'turn_gini'], $project);

        $this->assertSame(0, $result->excluded);
        $this->assertSame(0.5, round($result->lines[0]->mean(), 10));
    }

    public function test_words_per_turn_of_a_group_is_its_words_over_its_turns(): void
    {
        $tag = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gruppe']);
        $both = $tag->values()->create(['name' => 'beide']);
        $project = $this->run(['Alice', 'Bob'], [['Alice', 10], ['Bob', 30], ['Bob', 50]]);
        $this->experts['Alice']->setTag($tag, $both);
        $this->experts['Bob']->setTag($tag, $both);

        $result = $this->compute(['type' => 'persona_tags', 'tags' => [$tag->id => [$both->id]]], ['metric' => 'words_per_turn'], $project);

        $this->assertSame(30.0, $result->lines[0]->mean());
    }
}
```

`tests/Feature/Statistics/SeriesProjectsTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Values\SeriesDefinition;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_accessible_projects_that_match_tags_pipeline_and_model(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        $own = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline']);
        $shared = Project::factory()->create(['user_id' => $stranger->id, 'pipeline' => 'ScorePipeline']);
        $shared->addContributingUser($user);
        $foreign = Project::factory()->create(['user_id' => $stranger->id, 'pipeline' => 'ScorePipeline']);
        $otherPipeline = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'RoundRobinPipeline']);

        foreach ([$own, $shared, $foreign, $otherPipeline] as $project) {
            $project->setTag($condition, $status);
        }

        $series = SeriesDefinition::fromArray(['label' => 'x', 'projects' => ['tags' => [$condition->id => [$status->id]], 'pipeline' => 'ScorePipeline']]);

        $this->assertSame([$own->id, $shared->id], app(SeriesProjects::class)->for($user, $series)->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter='SeriesDefinitionTest|SeriesStatisticsTest|SeriesProjectsTest'`
Expected: FAIL — Klassen fehlen.

- [ ] **Step 3: Werte-Objekte**

`app/Discussion/Values/ComparisonDisplay.php`:

```php
<?php

namespace App\Discussion\Values;

/** How the statistics page shows a comparison. */
enum ComparisonDisplay: string
{
    case Table = 'table';
    case Bars = 'bars';
    case Trajectory = 'trajectory';

    public function label(): string
    {
        return __("comparisons.displays.{$this->value}");
    }
}
```

`app/Discussion/Values/ComparisonView.php`:

```php
<?php

namespace App\Discussion\Values;

/** The settings that apply to every series of a comparison. */
final readonly class ComparisonView
{
    public function __construct(
        public Metric $metric = Metric::TurnShare,
        public ComparisonDisplay $display = ComparisonDisplay::Table,
        /** Only the first N spoken turns of every run; null for whole runs. */
        public ?int $turnLimit = null,
        /** 'cumulative' or 'rolling', as ShareSeries names them. */
        public string $trajectoryMode = 'cumulative',
    ) {}

    public static function fromArray(array $data): self
    {
        $limit = $data['turn_limit'] ?? null;
        $mode = $data['trajectory_mode'] ?? null;

        return new self(
            metric: Metric::tryFrom((string) ($data['metric'] ?? '')) ?? Metric::TurnShare,
            display: ComparisonDisplay::tryFrom((string) ($data['display'] ?? '')) ?? ComparisonDisplay::Table,
            turnLimit: is_numeric($limit) && (int) $limit >= 1 ? (int) $limit : null,
            trajectoryMode: in_array($mode, ['cumulative', 'rolling'], true) ? $mode : 'cumulative',
        );
    }

    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'display' => $this->display->value,
            'turn_limit' => $this->turnLimit,
            'trajectory_mode' => $this->trajectoryMode,
        ];
    }
}
```

`app/Discussion/Values/SeriesDefinition.php`:

```php
<?php

namespace App\Discussion\Values;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\NamesTagValues;

/**
 * One series of a comparison: which runs (project filter) and which of their
 * agents (reference). Built from form input or saved JSON alike; fromArray()
 * normalises both, so a broken entry degrades to a harmless default.
 */
final readonly class SeriesDefinition
{
    public function __construct(
        public string $label,
        public TagFilter $projectTags = new TagFilter,
        public ?string $pipeline = null,
        public ?string $model = null,
        public AgentReference $reference = new EachReference,
    ) {}

    public static function fromArray(array $data): self
    {
        $projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];

        return new self(
            label: trim((string) ($data['label'] ?? '')),
            projectTags: TagFilter::fromArray($projects['tags'] ?? []),
            pipeline: self::optional($projects['pipeline'] ?? null),
            model: self::optional($projects['model'] ?? null),
            reference: AgentReferences::fromArray(is_array($data['reference'] ?? null) ? $data['reference'] : []),
        );
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'projects' => ['tags' => $this->projectTags->toArray(), 'pipeline' => $this->pipeline, 'model' => $this->model],
            'reference' => $this->reference->toArray(),
        ];
    }

    /** Every tag value this series names, in the project filter and the reference. @return list<int> */
    public function tagValueIds(): array
    {
        $ids = $this->projectTags->valueIds();

        if ($this->reference instanceof NamesTagValues) {
            $ids = [...$ids, ...$this->reference->tagValueIds()];
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<int>  $ids  values that no longer exist */
    public function withoutTagValues(array $ids): self
    {
        $reference = $this->reference instanceof NamesTagValues ? $this->reference->withoutTagValues($ids) : $this->reference;

        return new self($this->label, $this->projectTags->without($ids), $this->pipeline, $this->model, $reference);
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
```

`app/Discussion/Values/ProjectValue.php`:

```php
<?php

namespace App\Discussion\Values;

/** One run's value in a series line. */
final readonly class ProjectValue
{
    public function __construct(
        public int $projectId,
        public string $title,
        public float $value,
    ) {}
}
```

`app/Discussion/Values/TrajectoryMean.php`:

```php
<?php

namespace App\Discussion\Values;

/** A line's mean share after one spoken turn, over the $n runs that reached it. */
final readonly class TrajectoryMean
{
    public function __construct(
        public int $turn,
        public float $mean,
        public int $n,
    ) {}
}
```

`app/Discussion/Values/SeriesLine.php`:

```php
<?php

namespace App\Discussion\Values;

/** One line of a series: one value per run, plus the averaged curve if one was asked for. */
final readonly class SeriesLine
{
    /**
     * @param  string  $key  '' for a single line, 'seat-N' for the lines of `each`
     * @param  list<ProjectValue>  $values
     * @param  list<TrajectoryMean>  $curve
     */
    public function __construct(
        public string $key,
        public ?int $seat,
        public array $values,
        public array $curve = [],
    ) {}

    public function n(): int
    {
        return count($this->values);
    }

    public function mean(): float
    {
        return $this->n() === 0 ? 0.0 : array_sum($this->numbers()) / $this->n();
    }

    public function min(): float
    {
        return $this->n() === 0 ? 0.0 : min($this->numbers());
    }

    public function max(): float
    {
        return $this->n() === 0 ? 0.0 : max($this->numbers());
    }

    /** @return list<float> */
    private function numbers(): array
    {
        return array_map(fn (ProjectValue $value) => $value->value, $this->values);
    }
}
```

`app/Discussion/Values/SeriesResult.php`:

```php
<?php

namespace App\Discussion\Values;

/** A series computed: its lines, how many runs matched the filter, and how many of those dropped out. */
final readonly class SeriesResult
{
    /** @param  list<SeriesLine>  $lines */
    public function __construct(
        public string $label,
        public array $lines,
        public int $projects,
        public int $excluded,
    ) {}
}
```

- [ ] **Step 4: `Project::scopeAccessibleBy()`**

In `app/Models/Project.php` (Import `use Illuminate\Database\Eloquent\Builder;`):

```php
    /** The projects this user may read — the access-project gate's rule (owner or contributor) as a query. */
    public function scopeAccessibleBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhereHas('users', fn (Builder $users) => $users->whereKey($user->id)));
    }
```

- [ ] **Step 5: `SeriesProjects`**

`app/Discussion/Metrics/SeriesProjects.php`:

```php
<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\SeriesDefinition;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** The runs a series covers: what the user may read, narrowed by the series' project filter. */
class SeriesProjects
{
    /** @return Collection<int, Project> */
    public function for(User $user, SeriesDefinition $series): Collection
    {
        return Project::query()
            ->accessibleBy($user)
            ->whereTagged($series->projectTags->valueIds())
            ->when($series->pipeline !== null, fn ($query) => $query->where('pipeline', $series->pipeline))
            ->when($series->model !== null, fn ($query) => $query->where('model', $series->model))
            ->orderBy('id')
            ->get();
    }
}
```

- [ ] **Step 6: `SeriesStatistics`**

`app/Discussion/Metrics/SeriesStatistics.php`:

```php
<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ProjectValue;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TrajectoryMean;
use App\Models\Project;

/**
 * One series of a comparison, computed over its runs. Every number comes from
 * SpeakingShares and ShareTrajectory, so the counting rules stay in SpokenTurns;
 * this class only picks agents, combines them and averages across runs.
 *
 * - Several agents of a run count with their combined value.
 * - A run whose reference picks nobody, or that has no spoken turn, drops out
 *   of the series and is counted in `excluded`.
 * - A curve point is the mean over the runs that reached that turn, with n.
 */
class SeriesStatistics
{
    public function __construct(
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
    ) {}

    /** @param  iterable<Project>  $projects  already filtered and access-checked (SeriesProjects) */
    public function compute(SeriesDefinition $series, ComparisonView $view, iterable $projects): SeriesResult
    {
        $metric = $view->metric;
        $withCurve = $view->display === ComparisonDisplay::Trajectory && $metric->hasTrajectory();
        $values = $seats = $sums = $counts = [];
        $matched = $excluded = 0;

        foreach ($projects as $project) {
            $matched++;
            $report = $this->shares->forProject($project, $view->turnLimit);
            $groups = $report->isEmpty() ? [] : $this->groupsFor($series, $project, $report, $metric);

            if ($groups === []) {
                $excluded++;

                continue;
            }

            $trajectory = $withCurve ? $this->trajectories->forProject($project, null, $view->turnLimit) : null;

            foreach ($groups as $group) {
                $seats[$group->key] = $group->seat;
                $values[$group->key][] = new ProjectValue($project->id, $project->title, $this->valueOf($metric, $report, $group));

                if ($trajectory !== null) {
                    $this->addCurve($sums, $counts, $group, $trajectory, $metric->series($view->trajectoryMode));
                }
            }
        }

        $lines = [];

        foreach ($values as $key => $projectValues) {
            $lines[] = new SeriesLine((string) $key, $seats[$key], $projectValues, $this->means($sums[$key] ?? [], $counts[$key] ?? []));
        }

        usort($lines, fn (SeriesLine $a, SeriesLine $b) => [$a->seat ?? PHP_INT_MAX, $a->key] <=> [$b->seat ?? PHP_INT_MAX, $b->key]);

        return new SeriesResult($series->label, $lines, $matched, $excluded);
    }

    /**
     * The groups that count in this run. A run-level measure describes the
     * whole group and ignores the reference.
     *
     * @return list<AgentGroup>
     */
    private function groupsFor(SeriesDefinition $series, Project $project, ShareReport $report, Metric $metric): array
    {
        if ($metric->isRunLevel()) {
            return [new AgentGroup('', [])];
        }

        return array_values(array_filter(
            $series->reference->pick($project, $report, $metric),
            fn (AgentGroup $group) => ! $group->isEmpty(),
        ));
    }

    private function valueOf(Metric $metric, ShareReport $report, AgentGroup $group): float
    {
        if ($metric->isRunLevel()) {
            return $metric->ofRun($report);
        }

        return $metric->ofAgents(array_values(array_filter(
            $report->speakers,
            fn (SpeakerShare $speaker) => in_array($speaker->expertId, $group->expertIds, true),
        )));
    }

    /**
     * @param  array<string, array<int, float>>  $sums
     * @param  array<string, array<int, int>>  $counts
     */
    private function addCurve(array &$sums, array &$counts, AgentGroup $group, Trajectory $trajectory, ShareSeries $series): void
    {
        foreach ($trajectory->points as $point) {
            $share = 0.0;

            foreach ($group->expertIds as $expertId) {
                $share += $point->share($series, $expertId);
            }

            $sums[$group->key][$point->turn] = ($sums[$group->key][$point->turn] ?? 0.0) + $share;
            $counts[$group->key][$point->turn] = ($counts[$group->key][$point->turn] ?? 0) + 1;
        }
    }

    /**
     * @param  array<int, float>  $sums
     * @param  array<int, int>  $counts
     * @return list<TrajectoryMean>
     */
    private function means(array $sums, array $counts): array
    {
        ksort($sums);
        $points = [];

        foreach ($sums as $turn => $sum) {
            $points[] = new TrajectoryMean((int) $turn, $sum / $counts[$turn], $counts[$turn]);
        }

        return $points;
    }
}
```

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter='SeriesDefinitionTest|SeriesStatisticsTest|SeriesProjectsTest'` → PASS. Ein Fließkomma-Vergleich, der an der letzten Stelle scheitert (z. B. `0.625`), wird mit `round(…, 10)` auf beiden Seiten verglichen — die erwarteten Werte selbst bleiben. Dann `php artisan test` → grün.

- [ ] **Step 8: Pint und Commit**

```bash
vendor/bin/pint app/Discussion/Values/SeriesDefinition.php app/Discussion/Values/ComparisonView.php app/Discussion/Values/ComparisonDisplay.php app/Discussion/Values/ProjectValue.php app/Discussion/Values/TrajectoryMean.php app/Discussion/Values/SeriesLine.php app/Discussion/Values/SeriesResult.php app/Discussion/Metrics/SeriesProjects.php app/Discussion/Metrics/SeriesStatistics.php app/Models/Project.php tests/Unit/Discussion/SeriesDefinitionTest.php tests/Unit/Discussion/SeriesStatisticsTest.php tests/Feature/Statistics/SeriesProjectsTest.php
git add app/Discussion/Values/SeriesDefinition.php app/Discussion/Values/ComparisonView.php app/Discussion/Values/ComparisonDisplay.php app/Discussion/Values/ProjectValue.php app/Discussion/Values/TrajectoryMean.php app/Discussion/Values/SeriesLine.php app/Discussion/Values/SeriesResult.php app/Discussion/Metrics/SeriesProjects.php app/Discussion/Metrics/SeriesStatistics.php app/Models/Project.php tests/Unit/Discussion/SeriesDefinitionTest.php tests/Unit/Discussion/SeriesStatisticsTest.php tests/Feature/Statistics/SeriesProjectsTest.php
git commit -m "$(cat <<'EOF'
Compute comparison series over many runs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Gespeicherte Vergleiche und die Statistik-Seite (Liste, Anlegen, Umbenennen, Duplizieren, Löschen)

**Files:**
- Create: `database/migrations/2026_10_08_000002_create_comparisons_table.php`, `app/Models/Comparison.php`
- Modify: `app/Models/User.php` (`comparisons()`)
- Create: `app/Livewire/Statistics/StatisticsPage.php`, `resources/views/livewire/statistics/statistics-page.blade.php`
- Modify: `routes/web.php`, `resources/views/components/layouts/app/sidebar.blade.php`
- Create: `lang/de/comparisons.php`, `lang/en/comparisons.php` (vollständig, auch für Task 12/13)
- Modify: `lang/de/navigation.php`, `lang/en/navigation.php` (`statistics`)
- Test: `tests/Feature/Statistics/StatisticsPageTest.php`

**Interfaces:**
- Consumes: `SeriesDefinition::fromArray()`, `ComparisonView` (Task 10); `NeedsConfirmation`.
- Produces:
  - Tabelle `comparisons` (`id`, `user_id` → cascade, `name` string(100), `series` json, `view` json, timestamps).
  - `App\Models\Comparison` (`fillable`: `user_id`, `name`, `series`, `view`; Casts `array`), `user(): BelongsTo`, `seriesDefinitions(): list<SeriesDefinition>`, `comparisonView(): ComparisonView`.
  - `User::comparisons(): HasMany` (nach `name`, `id`).
  - Route `statistics` (`GET /statistics`, `auth` + `verified`).
  - Livewire `App\Livewire\Statistics\StatisticsPage`: `#[Url(as: 'c')] public ?int $comparisonId`, `#[Locked] public ?int $renamingId`, `public string $name`; Aktionen `open(int)`, `startCreating()`, `startRenaming(int)`, `saveName()`, `duplicate(int)`, `confirmDelete(int)`, `delete(int)`. Modal-Name `comparison-name`. Rechte Spalte enthält den Platzhalter-Kommentar `{{-- comparison-editor --}}`, den Task 12 ersetzt.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Statistics/StatisticsPageTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    private function comparisonOf(User $user, string $name = 'Status-Effekt'): Comparison
    {
        return $user->comparisons()->create(['name' => $name, 'series' => [], 'view' => []]);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('statistics'))->assertRedirect(route('login'));
    }

    public function test_every_signed_in_user_reaches_the_page_and_the_sidebar_links_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('statistics'))
            ->assertOk()
            ->assertSee(__('comparisons.heading'))
            ->assertSee(route('statistics'));
    }

    public function test_creating_names_and_opens_a_new_comparison(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)
            ->call('startCreating')
            ->set('name', '  Titelträger ')
            ->call('saveName')
            ->assertHasNoErrors()
            ->assertSet('comparisonId', Comparison::sole()->id);

        $this->assertSame('Titelträger', Comparison::sole()->name);
        $this->assertSame($user->id, Comparison::sole()->user_id);
    }

    public function test_renaming_and_duplicating(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $user->comparisons()->create(['name' => 'A', 'series' => [['label' => 'Reihe 1']], 'view' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $source->id)
            ->assertSet('name', 'A')
            ->set('name', 'B')
            ->call('saveName')
            ->call('duplicate', $source->id);

        $this->assertSame('B', $source->fresh()->name);
        $copy = Comparison::whereKeyNot($source->id)->sole();
        $this->assertSame(__('comparisons.copy_name', ['name' => 'B']), $copy->name);
        $this->assertSame($source->fresh()->series, $copy->series);
        $this->assertSame($source->fresh()->view, $copy->view);
    }

    public function test_deleting_asks_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $this->comparisonOf($user);

        Livewire::test(StatisticsPage::class, ['comparisonId' => $comparison->id])
            ->call('confirmDelete', $comparison->id)
            ->assertDispatched('open-confirm')
            ->call('executeConfirmed')
            ->assertSet('comparisonId', null);

        $this->assertSame(0, Comparison::count());
    }

    public function test_someone_elses_comparison_does_not_exist_for_you(): void
    {
        $stranger = User::factory()->create();
        $foreign = $this->comparisonOf($stranger);
        $this->actingAs(User::factory()->create());

        Livewire::test(StatisticsPage::class)->call('open', $foreign->id)->assertNotFound();
        Livewire::test(StatisticsPage::class)->call('confirmDelete', $foreign->id)->assertNotFound();
        Livewire::withQueryParams(['c' => $foreign->id])->test(StatisticsPage::class)->assertSet('comparisonId', null);
    }

    public function test_a_users_comparisons_go_with_the_user(): void
    {
        $user = User::factory()->create();
        $this->comparisonOf($user);

        $user->delete();

        $this->assertSame(0, Comparison::count());
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=StatisticsPageTest`
Expected: FAIL — Route `statistics` fehlt.

- [ ] **Step 3: Migration und Modell**

`database/migrations/2026_10_08_000002_create_comparisons_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved comparison on the statistics page: personal, like a sidebar
     * group. series holds SeriesDefinition::toArray() per series, view
     * ComparisonView::toArray(). Tag values are referenced by id, so renaming
     * a value never breaks a saved filter; a deleted one drops out on load.
     */
    public function up(): void
    {
        Schema::create('comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('series');
            $table->json('view');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparisons');
    }
};
```

`app/Models/Comparison.php`:

```php
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
```

`app/Models/User.php`, nach `ownedProjects()`:

```php
    /** This user's saved comparisons on the statistics page. */
    public function comparisons(): HasMany {
        return $this->hasMany(Comparison::class)->orderBy('name')->orderBy('id');
    }
```

- [ ] **Step 4: Komponente**

`app/Livewire/Statistics/StatisticsPage.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\ComparisonView;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: the user's own comparisons on the left, the open one on
 * the right. Every comparison id from the request is resolved through the
 * user's own comparisons — someone else's does not exist here (404, not 403).
 */
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'c')]
    public ?int $comparisonId = null;

    /** The comparison being renamed; null while the name modal creates a new one. */
    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    public function mount(): void
    {
        if ($this->comparisonId !== null && $this->user()->comparisons()->find($this->comparisonId) === null) {
            $this->comparisonId = null;
        }
    }

    public function open(int $comparisonId): void
    {
        $this->comparisonId = $this->own($comparisonId)->id;
    }

    public function startCreating(): void
    {
        $this->reset(['renamingId', 'name']);
        $this->resetValidation();
        Flux::modal('comparison-name')->show();
    }

    public function startRenaming(int $comparisonId): void
    {
        $comparison = $this->own($comparisonId);
        $this->renamingId = $comparison->id;
        $this->name = $comparison->name;
        $this->resetValidation();
        Flux::modal('comparison-name')->show();
    }

    public function saveName(): void
    {
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => __('comparisons.name.field')]);

        if ($this->renamingId !== null) {
            $this->own($this->renamingId)->update(['name' => $this->name]);
        } else {
            $comparison = $this->user()->comparisons()->create([
                'name' => $this->name,
                'series' => [],
                'view' => (new ComparisonView)->toArray(),
            ]);
            $this->comparisonId = $comparison->id;
        }

        $this->reset(['renamingId', 'name']);
        Flux::modal('comparison-name')->close();
    }

    public function duplicate(int $comparisonId): void
    {
        $source = $this->own($comparisonId);
        $copy = $source->replicate();
        $copy->name = Str::limit(__('comparisons.copy_name', ['name' => $source->name]), 100, '');
        $copy->save();

        $this->comparisonId = $copy->id;
    }

    public function confirmDelete(int $comparisonId): void
    {
        $comparison = $this->own($comparisonId);

        $this->confirmTitle = __('comparisons.confirm.delete.title');
        $this->confirmMessage = __('comparisons.confirm.delete.message', ['name' => $comparison->name]);
        $this->needsConfirmation('delete', $comparisonId);
    }

    public function delete(int $comparisonId): void
    {
        $this->own($comparisonId)->delete();

        if ($this->comparisonId === $comparisonId) {
            $this->comparisonId = null;
        }
    }

    private function own(int $comparisonId): Comparison
    {
        $comparison = $this->user()->comparisons()->find($comparisonId);
        abort_if($comparison === null, 404);

        return $comparison;
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        return view('livewire.statistics.statistics-page', [
            'comparisons' => $this->user()->comparisons()->get(),
            'current' => $this->comparisonId === null ? null : $this->user()->comparisons()->find($this->comparisonId),
        ])->title(__('comparisons.heading'));
    }
}
```

- [ ] **Step 5: View**

`resources/views/livewire/statistics/statistics-page.blade.php`:

```blade
<section class="mx-auto w-full max-w-6xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('comparisons.heading') }}</flux:heading>
        <flux:text class="mt-1">{{ __('comparisons.subheading') }}</flux:text>
    </div>

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 lg:w-64">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="sm">{{ __('comparisons.list.heading') }}</flux:heading>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreating" class="cursor-pointer">
                    {{ __('comparisons.list.create') }}
                </flux:button>
            </div>

            @if ($comparisons->isEmpty())
                <x-empty-state icon="chart-bar" class="mt-3">{{ __('comparisons.list.empty') }}</x-empty-state>
            @else
                <flux:navlist class="mt-3">
                    @foreach ($comparisons as $comparison)
                        <div class="group/comparison relative" wire:key="comparison-item-{{ $comparison->id }}">
                            <flux:navlist.item wire:click="open({{ $comparison->id }})" :current="$comparisonId === $comparison->id" class="cursor-pointer pe-9">
                                {{ $comparison->name }}
                            </flux:navlist.item>

                            <div class="absolute end-1 top-0 flex h-10 items-center lg:h-8">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                        class="cursor-pointer text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $comparison->id }})">{{ __('comparisons.actions.rename') }}</flux:menu.item>
                                        <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $comparison->id }})">{{ __('comparisons.actions.duplicate') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $comparison->id }})">{{ __('comparisons.actions.delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>
                    @endforeach
                </flux:navlist>
            @endif
        </aside>

        <div class="min-w-0 flex-1">
            @if ($current)
                <flux:heading size="lg" class="mb-4">{{ $current->name }}</flux:heading>
                {{-- comparison-editor --}}
            @else
                <x-empty-state icon="chart-bar">{{ __('comparisons.none_open') }}</x-empty-state>
            @endif
        </div>
    </div>

    <flux:modal name="comparison-name" class="md:w-96">
        <form wire:submit.prevent="saveName" class="space-y-6">
            <x-modal-header :heading="$renamingId ? __('comparisons.name.rename') : __('comparisons.name.create')" />

            <flux:input wire:model="name" :label="__('comparisons.name.field')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 6: Route und Sidebar**

`routes/web.php`: Import `use App\Livewire\Statistics\StatisticsPage;`; in der `Route::middleware(['auth'])->group(...)` nach der Tags-Route:

```php
    Route::get('statistics', StatisticsPage::class)->middleware('verified')->name('statistics');
```

`resources/views/components/layouts/app/sidebar.blade.php`: im unteren `<flux:sidebar.nav>` direkt **vor** dem Einstellungen-Eintrag (`icon="cog"`):

```blade
                <flux:sidebar.item icon="chart-bar" :href="route('statistics')" :current="request()->routeIs('statistics')" wire:navigate>
                    {{ __('navigation.statistics') }}
                </flux:sidebar.item>
```

`lang/de/navigation.php`: `'statistics' => 'Statistik',` — `lang/en/navigation.php`: `'statistics' => 'Statistics',`.

- [ ] **Step 7: Sprachdateien `comparisons.php`**

`lang/de/comparisons.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Statistik-Seite: gespeicherte Vergleiche über viele Läufe
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistik',
    'subheading' => 'Vergleiche über viele Läufe. Eine Reihe ist ein Projekt-Filter plus ein Bezug auf Agenten.',
    'none_open' => 'Wähle links einen Vergleich oder lege einen neuen an.',

    'list' => [
        'heading' => 'Vergleiche',
        'create' => 'Neu',
        'empty' => 'Noch kein Vergleich.',
    ],

    'actions' => [
        'rename' => 'Umbenennen',
        'duplicate' => 'Duplizieren',
        'delete' => 'Löschen',
    ],

    'name' => [
        'create' => 'Neuer Vergleich',
        'rename' => 'Vergleich umbenennen',
        'field' => 'Name',
    ],

    'copy_name' => ':name (Kopie)',

    'confirm' => [
        'delete' => [
            'title' => 'Vergleich löschen?',
            'message' => 'Der Vergleich „:name“ und seine Reihen werden gelöscht. Projekte und Tags bleiben unberührt.',
        ],
    ],

    'dropped_tags' => '{1}Ein Tag-Wert dieses Vergleichs wurde inzwischen gelöscht und ist aus den Filtern herausgefallen.|[2,*]:count Tag-Werte dieses Vergleichs wurden inzwischen gelöscht und sind aus den Filtern herausgefallen.',

    'series' => [
        'heading' => 'Reihen',
        'add' => 'Reihe hinzufügen',
        'remove' => 'Reihe entfernen',
        'default_label' => 'Reihe :n',
        'label' => 'Bezeichnung',
        'empty' => 'Noch keine Reihe.',
        'projects' => 'Projekte',
        'any' => 'beliebig',
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'matches' => '{0}Kein Projekt passt.|{1}1 Projekt passt:|[2,*]:count Projekte passen:',
    ],

    'reference' => [
        'heading' => 'Bezug',
        'type' => 'Welche Agenten',
        'types' => [
            'persona_tags' => 'Personas mit Tags',
            'seat' => 'Ein Sitz',
            'expert_name' => 'Ein Experte nach Name',
            'top' => 'Der stärkste Agent des Laufs',
            'each' => 'Jeder Agent einzeln',
        ],
        'seat' => 'Sitz',
        'expert_name' => 'Name',
        'shared_hint' => 'Trifft der Bezug mehrere Agenten eines Laufs, zählt ihr gemeinsamer Anteil.',
        'run_level_hint' => 'Der Gini beschreibt den ganzen Lauf; der Bezug wird dafür nicht gebraucht.',
    ],

    'view' => [
        'heading' => 'Ansicht',
        'metric' => 'Kennzahl',
        'display' => 'Darstellung',
        'turn_limit' => 'Nur die ersten N Turns',
        'all_turns' => 'alle',
        'mode' => 'Verlauf',
    ],

    'metrics' => [
        'turn_share' => 'Turn-Anteil',
        'word_share' => 'Wortanteil',
        'words_per_turn' => 'Wörter pro Turn',
        'turn_gini' => 'Gini (Turns)',
        'word_gini' => 'Gini (Wörter)',
    ],

    'displays' => [
        'table' => 'Tabelle',
        'bars' => 'Balken',
        'trajectory' => 'Verlauf',
    ],

    'modes' => [
        'cumulative' => 'Kumulativ',
        'rolling' => 'Gleitend',
    ],

    'line_with_seat' => ':label · Sitz :seat',

    'results' => [
        'empty' => 'Lege eine Reihe an, um Werte zu sehen.',
        'line' => 'Reihe',
        'n' => 'n',
        'mean' => 'Mittel',
        'min' => 'Min',
        'max' => 'Max',
        'per_project' => 'Werte je Projekt',
        'excluded' => '{1}„:label“: 1 Projekt fällt heraus (Bezug trifft niemanden oder kein gesprochener Turn).|[2,*]„:label“: :count Projekte fallen heraus (Bezug trifft niemanden oder kein gesprochener Turn).',
        'bar_text' => 'Ø :mean · :min–:max · n = :n',
        'no_trajectory' => 'Den Verlauf gibt es nur für Turn- und Wortanteil.',
        'too_short' => 'Für einen Verlauf braucht es mindestens zwei gesprochene Turns.',
    ],
];
```

`lang/en/comparisons.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Statistics page: saved comparisons across many runs
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistics',
    'subheading' => 'Comparisons across many runs. A series is a project filter plus a reference to agents.',
    'none_open' => 'Pick a comparison on the left or create a new one.',

    'list' => [
        'heading' => 'Comparisons',
        'create' => 'New',
        'empty' => 'No comparison yet.',
    ],

    'actions' => [
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
    ],

    'name' => [
        'create' => 'New comparison',
        'rename' => 'Rename comparison',
        'field' => 'Name',
    ],

    'copy_name' => ':name (copy)',

    'confirm' => [
        'delete' => [
            'title' => 'Delete comparison?',
            'message' => 'The comparison “:name” and its series will be deleted. Projects and tags stay untouched.',
        ],
    ],

    'dropped_tags' => '{1}A tag value of this comparison has since been deleted and dropped out of its filters.|[2,*]:count tag values of this comparison have since been deleted and dropped out of its filters.',

    'series' => [
        'heading' => 'Series',
        'add' => 'Add series',
        'remove' => 'Remove series',
        'default_label' => 'Series :n',
        'label' => 'Label',
        'empty' => 'No series yet.',
        'projects' => 'Projects',
        'any' => 'any',
        'pipeline' => 'Pipeline',
        'model' => 'Model',
        'matches' => '{0}No project matches.|{1}1 project matches:|[2,*]:count projects match:',
    ],

    'reference' => [
        'heading' => 'Reference',
        'type' => 'Which agents',
        'types' => [
            'persona_tags' => 'Personas with tags',
            'seat' => 'One seat',
            'expert_name' => 'One expert by name',
            'top' => 'The run’s strongest agent',
            'each' => 'Every agent on its own',
        ],
        'seat' => 'Seat',
        'expert_name' => 'Name',
        'shared_hint' => 'If the reference hits several agents of a run, their combined share counts.',
        'run_level_hint' => 'Gini describes the whole run; it needs no reference.',
    ],

    'view' => [
        'heading' => 'View',
        'metric' => 'Measure',
        'display' => 'Display',
        'turn_limit' => 'Only the first N turns',
        'all_turns' => 'all',
        'mode' => 'Curve',
    ],

    'metrics' => [
        'turn_share' => 'Turn share',
        'word_share' => 'Word share',
        'words_per_turn' => 'Words per turn',
        'turn_gini' => 'Gini (turns)',
        'word_gini' => 'Gini (words)',
    ],

    'displays' => [
        'table' => 'Table',
        'bars' => 'Bars',
        'trajectory' => 'Over the run',
    ],

    'modes' => [
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
    ],

    'line_with_seat' => ':label · seat :seat',

    'results' => [
        'empty' => 'Add a series to see values.',
        'line' => 'Series',
        'n' => 'n',
        'mean' => 'Mean',
        'min' => 'Min',
        'max' => 'Max',
        'per_project' => 'Values per project',
        'excluded' => '{1}“:label”: 1 project drops out (the reference hits nobody, or no turn was spoken).|[2,*]“:label”: :count projects drop out (the reference hits nobody, or no turn was spoken).',
        'bar_text' => 'Ø :mean · :min–:max · n = :n',
        'no_trajectory' => 'The curve exists for turn share and word share only.',
        'too_short' => 'A curve needs at least two spoken turns.',
    ],
];
```

- [ ] **Step 8: Tests laufen lassen**

Run: `php artisan test --filter=StatisticsPageTest` → PASS; dann `php artisan test` → grün.

- [ ] **Step 9: Pint und Commit**

```bash
vendor/bin/pint database/migrations/2026_10_08_000002_create_comparisons_table.php app/Models/Comparison.php app/Models/User.php app/Livewire/Statistics/StatisticsPage.php routes/web.php lang/de/comparisons.php lang/en/comparisons.php lang/de/navigation.php lang/en/navigation.php tests/Feature/Statistics/StatisticsPageTest.php
git add database/migrations/2026_10_08_000002_create_comparisons_table.php app/Models/Comparison.php app/Models/User.php app/Livewire/Statistics/StatisticsPage.php resources/views/livewire/statistics/statistics-page.blade.php routes/web.php resources/views/components/layouts/app/sidebar.blade.php lang/de/comparisons.php lang/en/comparisons.php lang/de/navigation.php lang/en/navigation.php tests/Feature/Statistics/StatisticsPageTest.php
git commit -m "$(cat <<'EOF'
Add the statistics page with saved comparisons

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Reihen-Editor mit Live-Trefferliste

**Files:**
- Create: `app/Livewire/Statistics/ComparisonEditor.php`, `resources/views/livewire/statistics/comparison-editor.blade.php`
- Modify: `resources/views/livewire/statistics/statistics-page.blade.php` (Platzhalter `{{-- comparison-editor --}}` ersetzen)
- Test: `tests/Feature/Statistics/ComparisonEditorTest.php`

**Interfaces:**
- Consumes: `Comparison`, `User::comparisons()` (Task 11); `SeriesDefinition`, `ComparisonView`, `SeriesProjects::for()` (Task 10); `AgentReferences::TYPES` (Task 9); `TagCategory::ofScope()`, `TagValue` (Task 2); `PipelineRegistry::options()`, `ModelConfig::options()` (vorhanden).
- Produces: Livewire `App\Livewire\Statistics\ComparisonEditor` (`<livewire:statistics.comparison-editor :comparison-id="…" />`) mit `#[Locked] public int $comparisonId`, `public array $series` (je Reihe `SeriesDefinition::toArray()`), `public array $viewSettings` (`ComparisonView::toArray()`), `#[Locked] public int $droppedTagValues`; Aktionen `addSeries()`, `removeSeries(int $index)`; Hook `updated(string $property)` speichert sofort; View-Daten `definitions`, `matches` (je Reihe `Collection<Project>`). Im View der Platzhalter `{{-- results --}}` und `{{-- series-dot --}}` für Task 13.

Der öffentliche Name ist bewusst `viewSettings`, nicht `view`: `view` ist im Livewire-Umfeld als Methodenname belegt, ein gleichnamiges Property wäre eine Falle.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Statistics/ComparisonEditorTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\ComparisonEditor;
use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Comparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->comparison = $this->user->comparisons()->create(['name' => 'Status', 'series' => [], 'view' => []]);
    }

    private function editor(): mixed
    {
        return Livewire::test(ComparisonEditor::class, ['comparisonId' => $this->comparison->id]);
    }

    public function test_the_page_shows_the_editor_of_the_open_comparison(): void
    {
        Livewire::test(StatisticsPage::class, ['comparisonId' => $this->comparison->id])
            ->assertSeeLivewire(ComparisonEditor::class);
    }

    public function test_someone_elses_comparison_cannot_be_edited(): void
    {
        $foreign = User::factory()->create()->comparisons()->create(['name' => 'X', 'series' => [], 'view' => []]);

        Livewire::test(ComparisonEditor::class, ['comparisonId' => $foreign->id])->assertNotFound();
    }

    public function test_every_change_is_normalised_and_saved_at_once(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id])
            ->set('series.0.reference.type', 'seat')
            ->set('viewSettings.metric', 'word_share');

        $saved = $this->comparison->fresh();
        $this->assertSame([$condition->id => [$status->id]], $saved->series[0]['projects']['tags']);
        $this->assertSame(['type' => 'seat', 'seat' => 1], $saved->series[0]['reference']);
        $this->assertSame('word_share', $saved->view['metric']);
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $saved->series[0]['label']);
    }

    public function test_removing_a_series_saves_too(): void
    {
        $this->editor()->call('addSeries')->call('addSeries')->call('removeSeries', 0);

        $this->assertCount(1, $this->comparison->fresh()->series);
        $this->assertSame(__('comparisons.series.default_label', ['n' => 2]), $this->comparison->fresh()->series[0]['label']);
    }

    public function test_the_live_match_list_shows_only_readable_projects(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $mine = Project::factory()->create(['user_id' => $this->user->id, 'title' => 'Meine Zelle']);
        $foreign = Project::factory()->create(['title' => 'Fremde Zelle']);
        $mine->setTag($condition, $status);
        $foreign->setTag($condition, $status);

        $component = $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id]);

        $this->assertSame([$mine->id], $component->viewData('matches')[0]->pluck('id')->all());
        $component->assertSee('Meine Zelle')->assertDontSee('Fremde Zelle');
    }

    public function test_deleted_tag_values_drop_out_on_load_with_a_notice(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'kontrolle']);
        $this->comparison->update(['series' => [[
            'label' => 'Reihe',
            'projects' => ['tags' => [$condition->id => [$status->id, $control->id]]],
            'reference' => ['type' => 'each'],
        ]]]);
        $control->delete();

        $this->editor()
            ->assertSet('droppedTagValues', 1)
            ->assertSet('series.0.projects.tags', [$condition->id => [$status->id]])
            ->assertSee(trans_choice('comparisons.dropped_tags', 1, ['count' => 1]));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=ComparisonEditorTest`
Expected: FAIL — `ComparisonEditor` fehlt.

- [ ] **Step 3: Komponente**

`app/Livewire/Statistics/ComparisonEditor.php`:

```php
<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\SeriesDefinition;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One comparison, edited in place: its series (project filter + reference),
 * the view that applies to all of them, and — below — the result. Every edit
 * is normalised through SeriesDefinition/ComparisonView and saved at once; a
 * comparison has no draft state.
 */
class ComparisonEditor extends Component
{
    #[Locked]
    public int $comparisonId;

    /** @var list<array<string, mixed>> SeriesDefinition::toArray() per series */
    public array $series = [];

    /** @var array<string, mixed> ComparisonView::toArray() */
    public array $viewSettings = [];

    /** Tag values this comparison named that have since been deleted. */
    #[Locked]
    public int $droppedTagValues = 0;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $definitions = $comparison->seriesDefinitions();

        $named = array_values(array_unique(array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->tagValueIds(), $definitions))));
        $existing = TagValue::whereKey($named)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($named, $existing));

        $this->comparisonId = $comparison->id;
        $this->droppedTagValues = count($missing);
        $this->series = array_map(fn (SeriesDefinition $d) => $d->withoutTagValues($missing)->toArray(), $definitions);
        $this->viewSettings = $comparison->comparisonView()->toArray();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'series') || str_starts_with($property, 'viewSettings')) {
            $this->persist();
        }
    }

    public function addSeries(): void
    {
        $label = __('comparisons.series.default_label', ['n' => count($this->series) + 1]);
        $this->series[] = (new SeriesDefinition($label))->toArray();
        $this->persist();
    }

    public function removeSeries(int $index): void
    {
        unset($this->series[$index]);
        $this->persist();
    }

    /** Normalises the form state, writes it back so the form shows what is stored, and saves it. */
    private function persist(): void
    {
        $this->series = array_map(fn (SeriesDefinition $d) => $d->toArray(), $this->definitions());
        $this->viewSettings = ComparisonView::fromArray($this->viewSettings)->toArray();

        $this->comparison($this->comparisonId)->update(['series' => $this->series, 'view' => $this->viewSettings]);
    }

    /** @return list<SeriesDefinition> */
    private function definitions(): array
    {
        return array_map(
            fn (mixed $series) => SeriesDefinition::fromArray(is_array($series) ? $series : []),
            array_values($this->series),
        );
    }

    /** Through the user's own comparisons: someone else's does not exist here. */
    private function comparison(int $comparisonId): Comparison
    {
        $comparison = auth()->user()->comparisons()->find($comparisonId);
        abort_if($comparison === null, 404);

        return $comparison;
    }

    public function render(): mixed
    {
        $definitions = $this->definitions();

        return view('livewire.statistics.comparison-editor', [
            'definitions' => $definitions,
            'matches' => array_map(fn (SeriesDefinition $d) => app(SeriesProjects::class)->for(auth()->user(), $d), $definitions),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'referenceTypes' => array_keys(AgentReferences::TYPES),
        ]);
    }
}
```

- [ ] **Step 4: View**

`resources/views/livewire/statistics/comparison-editor.blade.php`:

```blade
<div class="space-y-6">
    @if ($droppedTagValues > 0)
        <flux:callout variant="warning" icon="exclamation-triangle"
            :heading="trans_choice('comparisons.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues])" />
    @endif

    <section class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('comparisons.series.heading') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addSeries" class="cursor-pointer">{{ __('comparisons.series.add') }}</flux:button>
        </div>

        @if ($definitions === [])
            <x-empty-state icon="queue-list">{{ __('comparisons.series.empty') }}</x-empty-state>
        @endif

        @foreach ($definitions as $index => $definition)
            @php($referenceType = $series[$index]['reference']['type'] ?? 'each')

            <flux:card wire:key="series-{{ $index }}" class="space-y-5">
                <div class="flex items-end gap-3">
                    {{-- series-dot --}}
                    <flux:input wire:model.blur="series.{{ $index }}.label" :label="__('comparisons.series.label')" class="flex-1" />
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeSeries({{ $index }})"
                        class="cursor-pointer" :aria-label="__('comparisons.series.remove')" />
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    {{-- Which runs --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.series.projects') }}</flux:heading>

                        @foreach ($projectCategories as $category)
                            <flux:select variant="listbox" multiple
                                wire:key="series-{{ $index }}-project-tags-{{ $category->id }}"
                                wire:model.live="series.{{ $index }}.projects.tags.{{ $category->id }}"
                                :label="$category->name" :placeholder="__('comparisons.series.any')">
                                @foreach ($category->values as $value)
                                    <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach

                        <flux:select wire:model.live="series.{{ $index }}.projects.pipeline" :label="__('comparisons.series.pipeline')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="series.{{ $index }}.projects.model" :label="__('comparisons.series.model')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($models as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <div>
                            <flux:text size="sm">{{ trans_choice('comparisons.series.matches', $matches[$index]->count(), ['count' => $matches[$index]->count()]) }}</flux:text>
                            @if ($matches[$index]->isNotEmpty())
                                <ul class="mt-1 max-h-32 overflow-y-auto text-xs text-zinc-500 dark:text-zinc-400">
                                    @foreach ($matches[$index] as $project)
                                        <li wire:key="series-{{ $index }}-match-{{ $project->id }}" class="truncate">{{ $project->title }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    {{-- Which agents --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.reference.heading') }}</flux:heading>

                        <flux:select wire:model.live="series.{{ $index }}.reference.type" :label="__('comparisons.reference.type')">
                            @foreach ($referenceTypes as $type)
                                <option value="{{ $type }}">{{ __("comparisons.reference.types.{$type}") }}</option>
                            @endforeach
                        </flux:select>

                        @switch($referenceType)
                            @case('persona_tags')
                                @foreach ($personaCategories as $category)
                                    <flux:select variant="listbox" multiple
                                        wire:key="series-{{ $index }}-persona-tags-{{ $category->id }}"
                                        wire:model.live="series.{{ $index }}.reference.tags.{{ $category->id }}"
                                        :label="$category->name" :placeholder="__('comparisons.series.any')">
                                        @foreach ($category->values as $value)
                                            <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endforeach
                                @break

                            @case('seat')
                                <flux:input type="number" min="1" wire:model.live.debounce.500ms="series.{{ $index }}.reference.seat" :label="__('comparisons.reference.seat')" />
                                @break

                            @case('expert_name')
                                <flux:select wire:model.live="series.{{ $index }}.reference.name" :label="__('comparisons.reference.expert_name')">
                                    <option value="">—</option>
                                    @foreach ($expertNames as $expertName)
                                        <option value="{{ $expertName }}">{{ $expertName }}</option>
                                    @endforeach
                                </flux:select>
                                @break
                        @endswitch

                        <flux:text size="sm">{{ __('comparisons.reference.shared_hint') }}</flux:text>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </section>

    {{-- results --}}
</div>
```

In `resources/views/livewire/statistics/statistics-page.blade.php` die Zeile `{{-- comparison-editor --}}` ersetzen durch:

```blade
                <livewire:statistics.comparison-editor :comparison-id="$current->id" wire:key="comparison-editor-{{ $current->id }}" />
```

- [ ] **Step 5: Tests laufen lassen**

Run: `php artisan test --filter='ComparisonEditorTest|StatisticsPageTest'` → PASS; dann `php artisan test` → grün.

- [ ] **Step 6: Pint und Commit**

```bash
vendor/bin/pint app/Livewire/Statistics/ComparisonEditor.php tests/Feature/Statistics/ComparisonEditorTest.php
git add app/Livewire/Statistics/ComparisonEditor.php resources/views/livewire/statistics/comparison-editor.blade.php resources/views/livewire/statistics/statistics-page.blade.php tests/Feature/Statistics/ComparisonEditorTest.php
git commit -m "$(cat <<'EOF'
Edit a comparison's series with a live list of matching runs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: Ansicht und Ergebnis — Tabelle, Balken mit Spannweite, Verlauf

**Files:**
- Modify: `app/Livewire/Statistics/ComparisonEditor.php` (Konstante `PALETTE`, `render()`, drei private Methoden)
- Modify: `resources/views/livewire/statistics/comparison-editor.blade.php` (Platzhalter `{{-- series-dot --}}` und `{{-- results --}}`)
- Create: `resources/views/components/statistics/range-bars.blade.php`
- Test: `tests/Feature/Statistics/ComparisonResultsTest.php`

**Interfaces:**
- Consumes: `SeriesStatistics::compute()`, `SeriesResult`, `SeriesLine`, `ComparisonView`, `ComparisonDisplay`, `Metric` (Task 9/10); `ComparisonEditor` (Task 12).
- Produces: `ComparisonEditor::PALETTE` (`['indigo', 'orange', 'teal', 'pink', 'sky', 'red', 'purple', 'lime']`); View-Daten `results` (`list<SeriesResult>`), `lines` (`list<array{label: string, color: string, series: int, line: SeriesLine}>`), `seriesColors` (`array<int, string>`), `bars`, `barScale`, `trajectoryRows`, `comparisonView`, `metrics`, `displays`; Blade-Komponente `<x-statistics.range-bars :bars="…" :scale="…" />`.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Feature/Statistics/ComparisonResultsTest.php`:

```php
<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\ComparisonEditor;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Comparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // One run of four turns: seat 1 speaks three of them.
        $project = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline', 'title' => 'Zelle 1']);
        $first = Expert::factory()->create();
        $second = Expert::factory()->create();
        $project->addContributingExpert($first);
        $project->addContributingExpert($second);

        foreach ([$first, $first, $second, $first] as $index => $speaker) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
                'started_at' => now(), 'turn_index' => $index + 1, 'expert_id' => $speaker->id, 'words' => 10,
            ]);
        }

        $this->comparison = $this->user->comparisons()->create([
            'name' => 'Sitz 1',
            'series' => [['label' => 'Sitz eins', 'projects' => ['pipeline' => 'ScorePipeline'], 'reference' => ['type' => 'seat', 'seat' => 1]]],
            'view' => ['metric' => 'turn_share', 'display' => 'table'],
        ]);
    }

    private function editor(): mixed
    {
        return Livewire::test(ComparisonEditor::class, ['comparisonId' => $this->comparison->id]);
    }

    public function test_the_table_shows_mean_min_max_and_n(): void
    {
        $this->editor()
            ->assertSee('Sitz eins')
            ->assertSee('75,0 %')
            ->assertSee('Zelle 1');
    }

    public function test_the_bars_carry_mean_and_span(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'bars');

        $bar = $component->viewData('bars')[0];
        $this->assertSame(0.75, $bar['mean']);
        $this->assertSame(0.75, $bar['min']);
        $this->assertSame(0.75, $bar['max']);
        $this->assertSame(1.0, $component->viewData('barScale'));
        $this->assertSame('indigo', $bar['color']);
        $component->assertSee(__('comparisons.results.bar_text', ['mean' => '75,0 %', 'min' => '75,0 %', 'max' => '75,0 %', 'n' => 1]));
    }

    public function test_the_curve_has_one_row_per_turn_with_n(): void
    {
        $rows = $this->editor()->set('viewSettings.display', 'trajectory')->viewData('trajectoryRows');

        $this->assertCount(4, $rows);
        $this->assertSame(1, $rows[0]['turn']);
        $this->assertSame(100.0, $rows[0]['l0']);
        $this->assertSame(75.0, $rows[3]['l0']);
        $this->assertSame(1, $rows[3]['n0']);
    }

    public function test_gini_has_no_curve_and_says_so(): void
    {
        $this->editor()
            ->set('viewSettings.metric', 'turn_gini')
            ->set('viewSettings.display', 'trajectory')
            ->assertSee(__('comparisons.results.no_trajectory'));
    }

    public function test_the_turn_limit_reaches_the_result(): void
    {
        $this->editor()->set('viewSettings.turn_limit', '2')->assertSee('100,0 %');

        $this->assertSame(2, $this->comparison->fresh()->view['turn_limit']);
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=ComparisonResultsTest`
Expected: FAIL — `viewData('bars')` fehlt, `75,0 %` erscheint nicht.

- [ ] **Step 3: Komponente erweitern**

In `app/Livewire/Statistics/ComparisonEditor.php` Imports ergänzen:

```php
use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;
```

Konstante direkt nach `class ComparisonEditor extends Component {`:

```php
    /**
     * Series colours, handed out in line order. Deliberately not the seat
     * colours of the statistics flyout (blue, violet, emerald, …): a series is
     * not a participant. All eight exist as --color-*-500 in the built CSS.
     */
    public const PALETTE = ['indigo', 'orange', 'teal', 'pink', 'sky', 'red', 'purple', 'lime'];
```

`render()` vollständig ersetzen und drei Methoden ergänzen:

```php
    public function render(): mixed
    {
        $definitions = $this->definitions();
        $view = ComparisonView::fromArray($this->viewSettings);
        $matches = array_map(fn (SeriesDefinition $d) => app(SeriesProjects::class)->for(auth()->user(), $d), $definitions);
        $results = array_map(
            fn (SeriesDefinition $d, $projects) => app(SeriesStatistics::class)->compute($d, $view, $projects),
            $definitions,
            $matches,
        );
        $lines = $this->displayLines($results);
        [$bars, $barScale] = $this->bars($lines, $view->metric);

        return view('livewire.statistics.comparison-editor', [
            'definitions' => $definitions,
            'matches' => $matches,
            'results' => $results,
            'lines' => $lines,
            'seriesColors' => $this->seriesColors($lines, count($definitions)),
            'bars' => $bars,
            'barScale' => $barScale,
            'trajectoryRows' => $this->trajectoryRows($lines),
            'comparisonView' => $view,
            'metrics' => Metric::cases(),
            'displays' => ComparisonDisplay::cases(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'referenceTypes' => array_keys(AgentReferences::TYPES),
        ]);
    }

    /**
     * Every line of every series, flat and in series order, each with the
     * colour it keeps in the table, the bars and the curve.
     *
     * @param  list<SeriesResult>  $results
     * @return list<array{label: string, color: string, series: int, line: SeriesLine}>
     */
    private function displayLines(array $results): array
    {
        $lines = [];

        foreach ($results as $seriesIndex => $result) {
            foreach ($result->lines as $line) {
                $lines[] = [
                    'label' => $line->seat === null || $line->key === ''
                        ? $result->label
                        : __('comparisons.line_with_seat', ['label' => $result->label, 'seat' => $line->seat]),
                    'color' => self::PALETTE[count($lines) % count(self::PALETTE)],
                    'series' => $seriesIndex,
                    'line' => $line,
                ];
            }
        }

        return $lines;
    }

    /**
     * The colour of each series' first line, for the dot in the editor; a
     * series without a line yet falls back to its index.
     *
     * @return array<int, string>
     */
    private function seriesColors(array $lines, int $seriesCount): array
    {
        $colors = [];

        foreach (range(0, max(0, $seriesCount - 1)) as $index) {
            $colors[$index] = self::PALETTE[$index % count(self::PALETTE)];
        }

        foreach (array_reverse($lines) as $entry) {
            $colors[$entry['series']] = $entry['color'];
        }

        return $colors;
    }

    /**
     * One bar per line: mean as the bar, min to max as the span. Shares fill
     * against 100 %; other measures against the largest value shown.
     *
     * @return array{0: list<array{label: string, color: string, mean: float, min: float, max: float, text: string}>, 1: float}
     */
    private function bars(array $lines, Metric $metric): array
    {
        $scale = $metric->isShare() ? 1.0 : max([1.0, ...array_map(fn (array $entry) => $entry['line']->max(), $lines)]);

        $bars = array_map(fn (array $entry) => [
            'label' => $entry['label'],
            'color' => $entry['color'],
            'mean' => $entry['line']->mean(),
            'min' => $entry['line']->min(),
            'max' => $entry['line']->max(),
            'text' => __('comparisons.results.bar_text', [
                'mean' => $metric->format($entry['line']->mean()),
                'min' => $metric->format($entry['line']->min()),
                'max' => $metric->format($entry['line']->max()),
                'n' => $entry['line']->n(),
            ]),
        ], $lines);

        return [$bars, $scale];
    }

    /**
     * One row per spoken turn for flux:chart: `l{i}` is line i's mean share in
     * percent, `n{i}` how many runs reached that turn. A line that ends early
     * simply has no field in later rows, which the chart draws as its end.
     *
     * @return list<array<string, int|float|string>>
     */
    private function trajectoryRows(array $lines): array
    {
        $rows = [];

        foreach ($lines as $index => $entry) {
            foreach ($entry['line']->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]['label'] = __('statistics.trajectory.turn', ['turn' => $point->turn]);
                $rows[$point->turn]["l{$index}"] = round($point->mean * 100, 1);
                $rows[$point->turn]["n{$index}"] = $point->n;
            }
        }

        ksort($rows);

        return array_values($rows);
    }
```

- [ ] **Step 4: Balken-Komponente**

`resources/views/components/statistics/range-bars.blade.php`:

```blade
@props([
    'bars',  {{-- list<array{label, color, mean, min, max, text}> --}}
    'scale', {{-- the value the full width stands for --}}
])

{{--
    Mean as a bar, min to max as a whisker across it. flux:chart cannot draw
    this: its bars always start at zero and take their colour from the field,
    not from the row (see the plan, open point 2).
--}}
@php($at = fn (float $value) => $scale > 0 ? max(0, min(100, $value / $scale * 100)) : 0)

<div {{ $attributes->class('space-y-3') }}>
    @foreach ($bars as $bar)
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,12rem)_1fr] sm:items-center sm:gap-3" wire:key="bar-{{ $loop->index }}">
            <span class="flex min-w-0 items-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $bar['color'] }}-500)"></span>
                <span class="truncate">{{ $bar['label'] }}</span>
            </span>

            <div>
                <div class="relative h-6 rounded bg-zinc-100 dark:bg-zinc-800" role="img" aria-label="{{ $bar['label'] }}: {{ $bar['text'] }}">
                    <div class="absolute inset-y-1 left-0 rounded-sm opacity-80"
                        style="width: {{ $at($bar['mean']) }}%; background-color: var(--color-{{ $bar['color'] }}-500)"></div>
                    <div class="absolute top-1/2 h-0.5 -translate-y-1/2 bg-zinc-800 dark:bg-zinc-100"
                        style="left: {{ $at($bar['min']) }}%; width: {{ $at($bar['max']) - $at($bar['min']) }}%"></div>
                    <div class="absolute inset-y-1 w-0.5 bg-zinc-800 dark:bg-zinc-100" style="left: {{ $at($bar['min']) }}%"></div>
                    <div class="absolute inset-y-1 w-0.5 -translate-x-full bg-zinc-800 dark:bg-zinc-100" style="left: {{ $at($bar['max']) }}%"></div>
                </div>
                <div class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $bar['text'] }}</div>
            </div>
        </div>
    @endforeach
</div>
```

- [ ] **Step 5: View — Farbpunkt und Ergebnisbereich**

In `resources/views/livewire/statistics/comparison-editor.blade.php` die Zeile `{{-- series-dot --}}` ersetzen durch:

```blade
                    <span class="mb-3 size-3 shrink-0 rounded-full" style="background-color: var(--color-{{ $seriesColors[$index] }}-500)"></span>
```

und die Zeile `{{-- results --}}` ersetzen durch:

```blade
    <section class="space-y-4">
        <flux:heading size="lg">{{ __('comparisons.view.heading') }}</flux:heading>

        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="viewSettings.metric" :label="__('comparisons.view.metric')" class="max-w-56">
                @foreach ($metrics as $metric)
                    <option value="{{ $metric->value }}">{{ $metric->label() }}</option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="viewSettings.display" :label="__('comparisons.view.display')" variant="segmented" size="sm">
                @foreach ($displays as $display)
                    <flux:radio :value="$display->value" :label="$display->label()" />
                @endforeach
            </flux:radio.group>

            <flux:input type="number" min="1" class="max-w-40"
                wire:model.live.debounce.500ms="viewSettings.turn_limit"
                :label="__('comparisons.view.turn_limit')" :placeholder="__('comparisons.view.all_turns')" />

            @if ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Trajectory && $comparisonView->metric->hasTrajectory())
                <flux:radio.group wire:model.live="viewSettings.trajectory_mode" :label="__('comparisons.view.mode')" variant="segmented" size="sm">
                    <flux:radio value="cumulative" :label="__('comparisons.modes.cumulative')" />
                    <flux:radio value="rolling" :label="__('comparisons.modes.rolling')" />
                </flux:radio.group>
            @endif
        </div>

        @if ($comparisonView->metric->isRunLevel())
            <flux:text size="sm">{{ __('comparisons.reference.run_level_hint') }}</flux:text>
        @endif

        @foreach ($results as $result)
            @if ($result->excluded > 0)
                <flux:text size="sm" class="text-amber-600 dark:text-amber-400">
                    {{ trans_choice('comparisons.results.excluded', $result->excluded, ['count' => $result->excluded, 'label' => $result->label]) }}
                </flux:text>
            @endif
        @endforeach

        @if ($lines === [])
            <x-empty-state icon="chart-bar">{{ __('comparisons.results.empty') }}</x-empty-state>
        @elseif ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Table)
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-100 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ __('comparisons.results.line') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.n') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.mean') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.min') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.max') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($lines as $entry)
                            <tr wire:key="result-row-{{ $loop->index }}">
                                <td class="px-3 py-2">
                                    <span class="flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $entry['color'] }}-500)"></span>
                                        <span class="truncate">{{ $entry['label'] }}</span>
                                    </span>
                                    <details class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        <summary class="cursor-pointer">{{ __('comparisons.results.per_project') }}</summary>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($entry['line']->values as $value)
                                                <li class="flex justify-between gap-3">
                                                    <span class="truncate">{{ $value->title }}</span>
                                                    <span class="font-mono">{{ $comparisonView->metric->format($value->value) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $entry['line']->n() }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->mean()) }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->min()) }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->max()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Bars)
            <x-statistics.range-bars :bars="$bars" :scale="$barScale" />
        @elseif (! $comparisonView->metric->hasTrajectory())
            <x-empty-state icon="presentation-chart-line">{{ __('comparisons.results.no_trajectory') }}</x-empty-state>
        @elseif (count($trajectoryRows) < 2)
            <x-empty-state icon="presentation-chart-line">{{ __('comparisons.results.too_short') }}</x-empty-state>
        @else
            {{-- Keyed on the data: the chart's children are wire:ignore'd, so new
                 rows need a fresh element rather than a morphed one. --}}
            <flux:chart wire:key="comparison-curve-{{ md5(json_encode($trajectoryRows)) }}" :value="$trajectoryRows" class="aspect-[2/1]">
                <flux:chart.svg>
                    @foreach ($lines as $index => $entry)
                        <flux:chart.line :field="'l'.$index" curve="none" style="color: var(--color-{{ $entry['color'] }}-500)" />
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
                    <flux:chart.tooltip.heading field="label" />
                    @foreach ($lines as $index => $entry)
                        <flux:chart.tooltip.value :field="'l'.$index" :label="$entry['label']" suffix=" %" />
                        <flux:chart.tooltip.value :field="'n'.$index" :label="__('comparisons.results.n')" />
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <div class="flex flex-wrap justify-center gap-x-4 gap-y-1">
                @foreach ($lines as $entry)
                    <flux:chart.legend :label="$entry['label']" class="p-0!">
                        <flux:chart.legend.indicator style="background-color: var(--color-{{ $entry['color'] }}-500)" />
                    </flux:chart.legend>
                @endforeach
            </div>
        @endif
    </section>
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter='ComparisonResultsTest|ComparisonEditorTest'` → PASS; dann `php artisan test` → grün.

- [ ] **Step 7: Pint und Commit**

```bash
vendor/bin/pint app/Livewire/Statistics/ComparisonEditor.php tests/Feature/Statistics/ComparisonResultsTest.php
git add app/Livewire/Statistics/ComparisonEditor.php resources/views/livewire/statistics/comparison-editor.blade.php resources/views/components/statistics/range-bars.blade.php tests/Feature/Statistics/ComparisonResultsTest.php
git commit -m "$(cat <<'EOF'
Show comparison results as table, span bars and averaged curves

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: Sidebar-Filter nach Projekt-Tags

**Files:**
- Modify: `app/Livewire/Projects/ProjectNavigation.php`, `resources/views/livewire/projects/project-navigation.blade.php`
- Modify: `lang/de/tags.php`, `lang/en/tags.php` (`filter`)
- Test: `tests/Feature/Projects/ProjectNavigationTest.php` (neue Methoden)

**Interfaces:**
- Consumes: `HasTags::scopeWhereTagged()`, `TagCategory::ofScope()`, `TagScope`, `TagValue` (Task 2).
- Produces: in `ProjectNavigation` `#[Session] public array $tagFilter = []` (Wert-IDs), Aktion `clearTagFilter()`; View-Daten zusätzlich `tagCategories`, `activeFilterCount`.

- [ ] **Step 1: Failing Tests ergänzen**

In `tests/Feature/Projects/ProjectNavigationTest.php` Imports `use App\Models\TagCategory;` und `use App\Models\TagScope;` ergänzen und anhängen:

```php
    public function test_the_tag_filter_hides_projects_and_drawers_without_a_match(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        $tagged = $this->projectOwnedBy($user, 'Mit Status');
        $tagged->setTag($condition, $status);
        $untagged = $this->projectOwnedBy($user, 'Ohne');
        $full = $this->groupFor($user, 'Voll');
        $empty = $this->groupFor($user, 'Leer');
        $tagged->fileInGroupFor($user, $full->id);

        $unfiltered = Livewire::test(ProjectNavigation::class);
        $this->assertSame([$full->id, $empty->id], $unfiltered->viewData('groups')->pluck('id')->sort()->values()->all());
        $this->assertSame([$untagged->id], $unfiltered->viewData('ungrouped')->pluck('id')->all());

        $filtered = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id]);
        $this->assertSame([$full->id], $filtered->viewData('groups')->pluck('id')->all());
        $this->assertSame([$tagged->id], $this->projectsInGroup($filtered, $full)->pluck('id')->all());
        $this->assertSame([], $filtered->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame(1, $filtered->viewData('activeFilterCount'));
    }

    public function test_the_tag_filter_is_and_across_categories_and_or_within_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'kontrolle']);
        $topic = TagCategory::create(['scope' => TagScope::Project, 'name' => 'thema']);
        $school = $topic->values()->create(['name' => 'Schule']);

        $a = $this->projectOwnedBy($user, 'A');
        $a->setTag($condition, $status);
        $a->setTag($topic, $school);
        $b = $this->projectOwnedBy($user, 'B');
        $b->setTag($condition, $control);

        $or = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id, (string) $control->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $or->viewData('ungrouped')->pluck('id')->all());

        $and = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $control->id, (string) $school->id]);
        $this->assertSame([], $and->viewData('ungrouped')->pluck('id')->all());
    }

    public function test_the_tag_filter_survives_the_next_page_and_resets_in_one_click(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id]);

        Livewire::test(ProjectNavigation::class)
            ->assertSet('tagFilter', [(string) $status->id])
            ->call('clearTagFilter')
            ->assertSet('tagFilter', [])
            ->assertViewHas('activeFilterCount', 0);
    }

    public function test_a_deleted_value_in_the_filter_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = $this->projectOwnedBy($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $gone = $condition->values()->create(['name' => 'weg']);
        $goneId = $gone->id;
        $gone->delete();

        $component = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $goneId]);

        $this->assertSame([$project->id], $component->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame(0, $component->viewData('activeFilterCount'));
    }
```

- [ ] **Step 2: Tests laufen lassen, sie müssen fehlschlagen**

Run: `php artisan test --filter=ProjectNavigationTest`
Expected: FAIL — `Property [$tagFilter] not found`.

- [ ] **Step 3: Komponente**

`app/Livewire/Projects/ProjectNavigation.php`: Imports `use App\Models\TagCategory;`, `use App\Models\TagScope;`, `use App\Models\TagValue;`, `use Livewire\Attributes\Session;`. Property nach `$projectTitle`:

```php
    /**
     * Project tag values the list is narrowed to: AND across categories, OR
     * within one. Personal and kept in the session, so it survives navigating
     * from project to project.
     *
     * @var list<int|string>
     */
    #[Session]
    public array $tagFilter = [];
```

Neue öffentliche Methode (z. B. nach `refreshList()`):

```php
    public function clearTagFilter(): void
    {
        $this->tagFilter = [];
    }
```

Neue private Methode (bei den anderen privaten Helfern):

```php
    /**
     * The chosen values that still exist and describe projects. A value deleted
     * since it was chosen must not leave the list filtered by nothing.
     *
     * @return list<int>
     */
    private function activeTagFilter(): array
    {
        if ($this->tagFilter === []) {
            return [];
        }

        return TagValue::whereKey($this->tagFilter)
            ->whereHas('category', fn ($query) => $query->where('scope', TagScope::Project->value))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
```

`render()` vollständig ersetzen:

```php
    public function render(): mixed
    {
        $filter = $this->activeTagFilter();

        [$filed, $ungrouped] = $this->user()->projects()
            ->whereTagged($filter)
            ->orderByDesc('projects.updated_at')
            ->get()
            ->partition(fn (Project $project) => $project->pivot->project_group_id !== null);

        $byGroup = $filed->groupBy(fn (Project $project) => (int) $project->pivot->project_group_id);
        $groups = $this->user()->projectGroups()->get();

        // Unfiltered, an empty drawer stays visible so it can be filled; while a
        // filter is on, a drawer without a match is only noise.
        if ($filter !== []) {
            $groups = $groups->filter(fn (ProjectGroup $group) => $byGroup->has($group->id))->values();
        }

        return view('livewire.projects.project-navigation', [
            'groups' => $groups,
            'grouped' => $groups
                ->mapWithKeys(fn (ProjectGroup $group) => [$group->id => $byGroup->get($group->id, new Collection)])
                ->all(),
            'ungrouped' => $ungrouped,
            'tagCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'activeFilterCount' => count($filter),
        ]);
    }
```

- [ ] **Step 4: View**

`resources/views/livewire/projects/project-navigation.blade.php`: direkt nach dem öffnenden Wurzel-`<div …overflow-y-auto px-2">` und vor dem Kommentar `{{-- The list is its own flex column …` einfügen:

```blade
    {{-- Filter by project tags. Shown only once project categories exist; an
         active filter shows its count on the button and can be cleared in one
         click beside it. --}}
    @if ($tagCategories->isNotEmpty())
        <div class="mb-1 flex items-center gap-1">
            <flux:dropdown position="bottom" align="start">
                <flux:button size="sm" variant="ghost" icon="funnel" class="cursor-pointer">
                    {{ __('tags.filter.button') }}
                    @if ($activeFilterCount > 0)
                        <flux:badge size="sm" color="blue" class="ms-1">{{ $activeFilterCount }}</flux:badge>
                    @endif
                </flux:button>

                <flux:menu keep-open>
                    <flux:menu.checkbox.group wire:model.live="tagFilter">
                        @foreach ($tagCategories as $category)
                            <flux:menu.heading>{{ $category->name }}</flux:menu.heading>
                            @foreach ($category->values as $value)
                                <flux:menu.checkbox :value="$value->id" wire:key="tag-filter-{{ $value->id }}">{{ $value->name }}</flux:menu.checkbox>
                            @endforeach
                        @endforeach
                    </flux:menu.checkbox.group>
                </flux:menu>
            </flux:dropdown>

            @if ($activeFilterCount > 0)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearTagFilter" class="cursor-pointer"
                    :aria-label="__('tags.filter.clear')" :tooltip="__('tags.filter.clear')" />
            @endif
        </div>
    @endif
```

und direkt vor dem schließenden `</div>` der Liste (also nach dem `@if ($ungrouped->isNotEmpty()) … @endif`-Block):

```blade
        @if ($activeFilterCount > 0 && $groups->isEmpty() && $ungrouped->isEmpty())
            <div class="px-3 py-1 text-sm text-zinc-400">{{ __('tags.filter.no_matches') }}</div>
        @endif
```

- [ ] **Step 5: Sprachdateien**

`lang/de/tags.php` ergänzen:

```php
    'filter' => [
        'button' => 'Filter',
        'clear' => 'Filter zurücksetzen',
        'no_matches' => 'Kein Projekt passt zum Filter.',
    ],
```

`lang/en/tags.php` ergänzen:

```php
    'filter' => [
        'button' => 'Filter',
        'clear' => 'Clear filter',
        'no_matches' => 'No project matches the filter.',
    ],
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter=ProjectNavigationTest` → PASS (alte und neue Methoden); dann `php artisan test` → grün.

- [ ] **Step 7: Pint und Commit**

```bash
vendor/bin/pint app/Livewire/Projects/ProjectNavigation.php lang/de/tags.php lang/en/tags.php tests/Feature/Projects/ProjectNavigationTest.php
git add app/Livewire/Projects/ProjectNavigation.php resources/views/livewire/projects/project-navigation.blade.php lang/de/tags.php lang/en/tags.php tests/Feature/Projects/ProjectNavigationTest.php
git commit -m "$(cat <<'EOF'
Filter the sidebar by project tags

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: `CLAUDE.md` nachziehen (nicht committen)

**Files:**
- Modify: `CLAUDE.md` (gitignored — nur auf der Platte ändern)

**Interfaces:**
- Consumes: alle vorigen Tasks.
- Produces: Doku. `docs/pipeline.puml` bleibt unverändert (keine Stage berührt) — das wird geprüft, nicht angenommen: `git diff --stat HEAD~14 -- app/Discussion/Stages app/Discussion/Pipelines` muss leer sein.

- [ ] **Step 1: Commands-Block ergänzen**

Im Bash-Block unter „## Commands“, nach der Zeile `php artisan project:cost …`:

```bash
php artisan tags:seed-study [--apply]                            # tag the existing cells (condition from title, seat-1 title holder); dry run by default
```

- [ ] **Step 2: „Where a type lives“ aktualisieren**

Unter „### Data model notes“ den ersten Punkt so ändern, dass die Liste der Values die neuen Typen nennt — den Satzteil „— `Completion`, `Contribution`, `ModelConfig`, `MemoryView`, `Purpose`, `Selection`, `Thought`, `TurnResult`.“ ersetzen durch:

```
— the turn's own (`Completion`, `Contribution`, `MemoryView`, `ModelConfig`, `Purpose`, `Selection`, `Thought`, `TurnResult`), the metrics' (`ShareReport`, `SpeakerShare`, `ShareSeries`, `Trajectory`, `TrajectoryPoint`, `Participant`, `CostReport`, `CostLine`) and the statistics page's (`Metric`, `AgentGroup`, `TagFilter`, `SeriesDefinition`, `ComparisonView`, `ComparisonDisplay`, `ProjectValue`, `TrajectoryMean`, `SeriesLine`, `SeriesResult`). `SeriesDefinition` holds an `AgentReference` object, so it reaches into `App\Discussion\Metrics\References` — a value that carries a strategy, like `Selection` carries an `Expert`.
```

und den Satz „Two of the eight stretch …“ zu „Two of them stretch …“ ändern.

- [ ] **Step 3: Neuer Abschnitt „Tags“ nach „### Metrics“**

```markdown
### Tags (`App\Models\Concerns\HasTags`)
Global categories with fixed values, scoped to personas or projects (`App\Models\TagScope`, morph types `expert`/`project`). `taggables` carries the category as well as the value, so the unique key enforces **at most one value per category**; `setTag()` replaces, `syncTags()` writes a form's selection, `whereTagged($valueIds)` is AND across categories and OR within one (unknown ids are ignored). Deleting a value or a category cascades to its assignments; deleting a tagged model removes its rows in the trait's `deleting` hook (a mass delete bypasses it). **Tags are invisible to the models**: no prompt template, no stage, no `run_config` reads them — `tests/Feature/Tags/TagsInvisibleToModelsTest.php` runs every pipeline with marker-named tags and fails if one reaches a prompt. Admins manage them under Settings → Tags (`Settings\Tags`, `admin` gate checked on every action, `wire:sort` writes `position`). Forms embed `Tags\TagFields` via `#[Modelable]`: the parent loads `tagSelection()` and saves with `syncTags()` in its own `save()`, so tags share the form's request and authorization. Export/import carry project tags by name (`tags`, schema 9); unknown names are skipped and reported, never created. `tags:seed-study` tagged the cells that ran before tags existed.

**No morph map.** Polymorphic columns (`project_contributors.contributor_type`, `taggables.taggable_type`) hold full class names. Code reads them only through `getMorphClass()` / `TagScope::morphType()`. Registering a map later would hide every existing row until a data migration rewrites them.
```

- [ ] **Step 4: Neuer Abschnitt „Statistics page“ direkt danach**

```markdown
### Statistics page (`Statistics\StatisticsPage`, `Statistics\ComparisonEditor`)
`/statistics`, for every signed-in user; a comparison (`comparisons`: `series` + `view` as JSON) belongs to its user and is resolved only through `User::comparisons()` (404 otherwise). A series is a project filter (project tags, optional pipeline and model; `SeriesProjects` also limits to projects the user may read — `Project::scopeAccessibleBy`, the `access-project` rule) plus an **`AgentReference`** (`App\Discussion\Metrics\References`: `persona_tags`, `seat`, `expert_name`, `top`, `each`; a new reference is a new class plus one line in `AgentReferences::TYPES`). `SeriesStatistics` computes only through `SpeakingShares`/`ShareTrajectory` (which take an optional turn limit, applied in `SpokenTurns::firstTurns()`): several agents of a run count with their **combined** share; a run where the reference hits nobody, or with no spoken turn, drops out and is counted; Gini ignores the reference; `top` breaks ties by the lower seat; curves average over the runs that reached each turn, with n. Bars are `x-statistics.range-bars` (CSS), not `flux:chart` — Flux bars start at zero and are coloured per field, so they cannot show a span per series. Series colours (`ComparisonEditor::PALETTE`) are deliberately not the flyout's seat colours. Deleted tag values in a saved filter drop out on load with a notice.

**Sidebar filter.** `ProjectNavigation::$tagFilter` (`#[Session]`) narrows the list by project tags; while it is on, drawers without a match are hidden.
```

- [ ] **Step 5: Authorization-Abschnitt ergänzen**

Am Ende des Absatzes unter „### Authorization“ anfügen:

```
Settings → Tags (`settings.tags`) uses the `admin` middleware and re-checks the gate in every Livewire action, because route middleware only guards the first request. The statistics page needs no gate: it only ever reads the user's own comparisons and projects the user may access.
```

- [ ] **Step 6: Prüfen, nicht stagen**

Run: `git status --short CLAUDE.md` → keine Ausgabe (gitignored). Run: `git diff --stat HEAD~14 -- app/Discussion/Stages app/Discussion/Pipelines docs/pipeline.puml` → leer. Kein Commit in dieser Task.

---

### Task 16: Abschluss — volle Suite und Build

**Files:** keine Änderungen, nur Prüfung.

**Interfaces:**
- Consumes: alles.
- Produces: grüne Suite, gebaute Assets, Liste der manuell zu prüfenden Stellen.

- [ ] **Step 1: Volle Suite**

Run: `php artisan test`
Expected: alle Tests grün, inklusive `PipelineSmokeTest` und `TagsInvisibleToModelsTest` für jede Pipeline.

- [ ] **Step 2: Pint über alle in diesem Plan geänderten Dateien prüfen**

Run: `vendor/bin/pint --test $(git diff --name-only HEAD~14 -- '*.php' | tr '\n' ' ')`
Expected: keine Beanstandung. (Blade-Dateien prüft Pint nicht.)

- [ ] **Step 3: Assets bauen**

Run: `npm run build`
Expected: Build ohne Fehler. Danach prüfen, dass die Palette im CSS steht: `grep -o -- '--color-\(indigo\|orange\|teal\|pink\|sky\|red\|purple\|lime\)-500' public/build/assets/*.css | sort -u` → acht Treffer. Fehlt eine Farbe, in `ComparisonEditor::PALETTE` durch eine vorhandene ersetzen und Task 13 neu committen.

- [ ] **Step 4: Für die Sichtprüfung notieren (nicht automatisiert testbar)**

Diese Punkte gehen an den Orchestrator für den Browser-Check, nicht als Code-Änderung:
1. `Settings → Tags`: Kategorien **und** Werte per Griff ziehen; ein Wert darf beim Ziehen nicht seine Kategorie mitnehmen (verschachtelte `wire:sort`-Listen).
2. `Tags\TagFields`: im Experten-Flyout nacheinander zwei Experten öffnen — die Auswahl muss jeweils zum geöffneten Experten passen (`wire:key` mit `formKey`); im Projekt-Anlegen Tags wählen und speichern (Modelable-Bindung reist im Submit mit).
3. Statistik-Seite: Mehrfachauswahl (`flux:select variant="listbox" multiple`) zeigt nach dem Speichern die gewählten Werte weiter an; Balken mit Spannweite und Verlauf in Hell und Dunkel; Breite bis 375 px.
4. Sidebar-Filter: Häkchen bleiben beim Wechsel auf ein anderes Projekt gesetzt; das X setzt zurück.

- [ ] **Step 5: Kein Commit** — es gibt nichts Neues zu committen. `git status --short` zeigt nur `.claude/worktrees/` (und nicht `CLAUDE.md`, das ignoriert ist).

**Danach führt der Orchestrator `/clean-code-review` (Skill `anthropic-skills:clean-code-review`) über die Änderungen dieses Plans und `/ux-review` (Skill `anthropic-skills:ux-review`) über die neuen Oberflächen aus** (Settings → Tags, Formulare mit Tags, Statistik-Seite, Sidebar-Filter). Diese Reviews sind nicht Teil der Tasks.

---

## Self-Review

**Spec-Abdeckung:**

| Spec | Task |
|---|---|
| §2 Datenmodell, `TagScope`, Morph-Typ (Klassenname), ein Wert je Kategorie, `HasTags` (`tagValues`, `setTag`, `tagFor`, `whereTagged`), Prüfung von Wert/Kategorie/Scope, Cascade, Aufräumen beim Löschen | 2 |
| §2 Löschen nur nach Warnfenster mit Zahl der Zuordnungen, Umbenennen jederzeit | 4 |
| §3 Unsichtbarkeit (Prompts, `run_config`, Test mit Markern) | 3 |
| §4 `Settings\Tags`, `settings/tags`, `admin`, Reiter nur für Admins, zwei Bereiche, Anlegen/Umbenennen inline/Löschen, `wire:sort`, Zuordnungszahl, Validierung | 4 |
| §5 `Tags\TagFields` mit „—“, nichts ohne Kategorie, Übergabe beim Anlegen (entschieden: Modelable), drei Formulare, Rechte, Badges in Liste und Projekteinstellungen | 5 |
| §6 Sidebar-Eintrag, `/statistics`, `StatisticsPage`, alle Angemeldeten, nur zugängliche Projekte, eigene Vergleiche mit Anlegen/Umbenennen/Duplizieren/Löschen | 11, 10 |
| §6 Reihe (Label, Projekt-Filter mit Tags/Pipeline/Modell, Live-Treffer), Bezüge (fünf), gemeinsamer Anteil, Ausschluss mit Zahl, Farben aus fester Palette | 9, 10, 12, 13 |
| §6 Ansicht (Kennzahlen, Tabelle/Balken mit Spannweite/Verlauf mit n, Verlauf nur für Anteile, Turn-Grenze) | 10, 13 |
| §6 Rechnen (`SeriesStatistics`, Werte unter `Values`, Turn-Grenze ohne Verhaltensänderung, `AgentReference` je Klasse, gelöschte Werte mit Hinweis) | 8, 9, 10, 12 |
| §7 Sidebar-Filter (Knopf, Mehrfachauswahl, UND/ODER, leere Gruppen aus, sichtbar aktiv, ein Klick zurück, `#[Session]`) | 14 |
| §8 `tags:seed-study` (Trockenlauf, `--apply`, idempotent, Kategorien anlegen, Konflikt) | 7 |
| §9 Export/Import nach Namen, Unbekanntes übersprungen und gemeldet | 6 |
| §10 Tests (alle genannten Unit- und Feature-Fälle) | 2–14 |
| §11 Offene Punkte | „Entscheidungen“ oben |

**Typ-Konsistenz (gegengeprüft):** `SpeakingShares::forProject(Project, ?int $turnLimit)` und `ShareTrajectory::forProject(Project, ?int $window, ?int $turnLimit)` (Task 8) werden in Task 10 genau so aufgerufen; `AgentReference::pick(Project, ShareReport, Metric): list<AgentGroup>` (Task 9) in `SeriesStatistics` (Task 10); `SeriesStatistics::compute(SeriesDefinition, ComparisonView, iterable): SeriesResult` (Task 10) in `ComparisonEditor::render()` (Task 13); `ComparisonView::toArray()`-Schlüssel `metric`, `display`, `turn_limit`, `trajectory_mode` (Task 10) sind die `viewSettings.*`-Pfade in Task 12/13; `SeriesDefinition::toArray()`-Pfade `projects.tags.{id}`, `projects.pipeline`, `projects.model`, `reference.type`, `reference.tags.{id}`, `reference.seat`, `reference.name` (Task 9/10) sind die `series.{i}.*`-Pfade in Task 12; `HasTags::tagSelection()`/`syncTags()` (Task 2) in Task 5; `TagCategory::ofScope()` überall gleich benannt.
