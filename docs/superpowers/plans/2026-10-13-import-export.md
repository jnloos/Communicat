# Import, Export und Statistik-Export – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein ZIP-„Bundle“-Format für Projekte, Experten und den ganzen Bestand (Export + Import mit Vorschau und Bericht), ein Statistik-Export als drei CSV-Dateien, 24-h-Aufbewahrung der Prompt-Texte, Tokens im Job-Debug-Fenster, Kostenrechnung entfernt.

**Architecture:** Kleine Transfer-Klassen pro Bereich (`TagTransfer`, `ExpertTransfer`, `ProjectTransfer`, `GroupTransfer`, `QuestionTransfer`) unter `App\Services\Transfer`, zusammengehalten von `BundleExporter`/`BundleImporter`; das Format selbst kapseln `BundleWriter`/`BundleReader`. Projekte und Experten bekommen eine öffentliche UUID als instanzübergreifenden Schlüssel. Der Statistik-Export (`App\Services\StudyExport\StudyExport`) liest die Läufe über `QuestionRuns` und zählt über `SpeakingShares`.

**Tech Stack:** Laravel 12, Livewire 3 + Flux/Flux Pro, PHP 8.5 mit `ext-zip` (`ZipArchive`), SQLite, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-13-import-export-design.md`

## Global Constraints

- Bundle-Schema-Version: `1`. Container: ZIP mit `manifest.json`, `tags.json`, `experts.json`, `groups.json`, `questions.json`, `projects/<uuid>.json`.
- Keine Nutzerkonten im Bundle; der Importierende wird Besitzer.
- Vorhandene Projekte/Experten (gleiche UUID) werden beim Import **übersprungen**, nie überschrieben.
- Unbekannte Pipeline oder unbekanntes Modell → Projekt übersprungen, **kein** Fallback auf den Standard.
- Experten behalten beim Import ihre ursprüngliche ID, wenn sie frei ist; sonst neue ID und ein Hinweis im Bericht (Selektor-Signale und `E{id}`-Tokens in Logs verweisen auf die alte ID).
- Fehlende Tag-Kategorien und -Werte werden beim Import **angelegt**.
- Import und globaler Export nur `admin` (Gate in jeder Aktion). Projekt-Export mit `access-project`. Experten-Export für alle angemeldeten Nutzer.
- Statistik-CSV: UTF-8, Komma, Kopfzeile; gesprochen = `words IS NOT NULL`; `NULL` bleibt leer, nie `0`; Länge in Wörtern/Zeichen, nie Tokens; Turn-Fenster und Personen-Filter der Frage gelten **nicht**.
- `PROMPT_RETENTION_HOURS` Default `24`; `0` oder leer = nie kürzen. Hinweistext: `[prompt removed after {hours} h retention]`.
- UI-Texte ohne runde Klammern und ohne „ · “; Flux-Standardkomponenten; Texte in `lang/en` **und** `lang/de`.
- Uploads in der Oberfläche bis 12 MB (Livewire-Standard); größere Bundles über `php artisan bundle:import`.
- Der alte Export (`ProjectExport`, `ProjectImporter`, Route `project.export.json`, Upload in „Neues Projekt“) wird entfernt; alte Dateien müssen nicht importierbar sein.
- Nach jedem Task: `vendor/bin/pint --dirty` und `php artisan test` grün.

## Vorbedingung

Im Arbeitsbaum liegen noch uncommittete Änderungen aus der Sitzung vom 13.10.2026 (Judge-Vorlage, Reihenfolge, Tooltips, Hover-Karten). Sie werden **vor Task 1** vom Menschen freigegeben und committet, damit die Commits dieses Plans nur seine eigenen Änderungen enthalten.

## Dateistruktur

| Datei | Verantwortung |
|---|---|
| `app/Models/Concerns/HasPublicUuid.php` | setzt `uuid` beim Anlegen |
| `database/migrations/2026_10_13_000002_add_uuid_to_experts_and_projects.php` | Spalten + Backfill |
| `app/Console/Commands/PrunePrompts.php` | `prompts:prune` |
| `app/Services/Transfer/Bundle.php` | Konstanten des Formats |
| `app/Services/Transfer/InvalidBundle.php` | Exception für unlesbare Bundles |
| `app/Services/Transfer/BundleWriter.php` | schreibt JSON-Einträge in eine ZIP |
| `app/Services/Transfer/BundleReader.php` | öffnet, prüft und liest eine ZIP |
| `app/Services/Transfer/ImportReport.php` | Ergebnis je Bereich: angelegt / übersprungen / fehlgeschlagen |
| `app/Services/Transfer/TagTransfer.php` | Tag-Kategorien und -Werte |
| `app/Services/Transfer/ExpertTransfer.php` | Experten |
| `app/Services/Transfer/ProjectTransfer.php` | ein Projekt komplett |
| `app/Services/Transfer/DefinitionNames.php` | Frage-Definition: IDs ↔ Namen |
| `app/Services/Transfer/GroupTransfer.php` | Sidebar-Gruppen eines Nutzers |
| `app/Services/Transfer/QuestionTransfer.php` | Fragen und Vorlagen |
| `app/Services/Transfer/BundleExporter.php` | Auswahl → Bundle |
| `app/Services/Transfer/BundleImporter.php` | Bundle → DB, mit Vorschau |
| `app/Console/Commands/ExportBundle.php`, `ImportBundle.php` | `bundle:export`, `bundle:import` |
| `app/Http/Controllers/BundleExportController.php` | Downloads Projekt, Experte, alles |
| `app/Livewire/Settings/Transfer.php` + `resources/views/livewire/settings/transfer.blade.php` | Einstellungen → Import/Export |
| `resources/views/components/transfer/report.blade.php` | Bericht als Karten |
| `app/Services/StudyExport/StudyExport.php` | drei CSV-Dateien als ZIP |
| `app/Console/Commands/ExportStudyTurns.php` | `study:export-turns` |
| `app/Http/Controllers/StudyExportController.php` | Download aus einer Frage |
| `lang/{en,de}/transfer.php` | Texte für Import/Export |

---

### Task 1: Öffentliche UUIDs für Experten und Projekte

**Files:**
- Create: `app/Models/Concerns/HasPublicUuid.php`
- Create: `database/migrations/2026_10_13_000002_add_uuid_to_experts_and_projects.php`
- Modify: `app/Models/Expert.php` (Trait einbinden)
- Modify: `app/Models/Project.php` (Trait einbinden)
- Test: `tests/Feature/Transfer/PublicUuidTest.php`

**Interfaces:**
- Produces: `Expert::$uuid`, `Project::$uuid` (string, eindeutig, gesetzt beim Anlegen, außer er ist schon gesetzt).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_experts_and_projects_get_a_uuid_when_created(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();

        $this->assertTrue(Str::isUuid($expert->uuid));
        $this->assertTrue(Str::isUuid($project->uuid));
        $this->assertNotSame($expert->uuid, Expert::factory()->create()->uuid);
    }

    public function test_a_given_uuid_is_kept(): void
    {
        $uuid = (string) Str::uuid();
        $expert = Expert::factory()->create(['uuid' => $uuid]);

        $this->assertSame($uuid, $expert->fresh()->uuid);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PublicUuidTest`
Expected: FAIL (`no such column: uuid` bzw. `uuid` ist null)

- [ ] **Step 3: Write the trait and migration**

`app/Models/Concerns/HasPublicUuid.php`:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key that stays the same on every instance: the numeric id differs between
 * databases, the uuid travels with an export and lets an import recognise a
 * row it already has. Not the primary key -- nothing else changes.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
```

`database/migrations/2026_10_13_000002_add_uuid_to_experts_and_projects.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Experts and projects get a uuid that travels with an export (see HasPublicUuid). */
    public function up(): void
    {
        foreach (['experts', 'projects'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable();
            });

            DB::table($table)->whereNull('uuid')->pluck('id')
                ->each(fn (int $id) => DB::table($table)->where('id', $id)->update(['uuid' => (string) Str::uuid()]));

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (['experts', 'projects'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['uuid']);
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
```

In `app/Models/Expert.php`: `use App\Models\Concerns\HasPublicUuid;` und `use HasFactory, HasPublicUuid, HasTags;`; `'uuid'` in `$fillable` ergänzen.
In `app/Models/Project.php`: ebenso `use HasFactory, HasPublicUuid, HasTags;`; `'uuid'` in `$fillable` ergänzen.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=PublicUuidTest` → PASS. Dann `php artisan test` → alles grün.

- [ ] **Step 5: Migrate the real database**

Run: `php artisan migrate` und prüfen: `sqlite3 database/database.sqlite "select count(*) from experts where uuid is null; select count(*) from projects where uuid is null;"` → `0` und `0`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Concerns/HasPublicUuid.php database/migrations/2026_10_13_000002_add_uuid_to_experts_and_projects.php app/Models/Expert.php app/Models/Project.php tests/Feature/Transfer/PublicUuidTest.php
git commit -m "Give experts and projects a public uuid for transfers"
```

---

### Task 2: Prompt-Texte nur 24 Stunden aufbewahren

**Files:**
- Create: `app/Console/Commands/PrunePrompts.php`
- Modify: `config/discussion.php` (neuer Schlüssel)
- Modify: `routes/console.php` (Scheduler)
- Modify: `composer.json` (Skript `dev`: `schedule:work`)
- Modify: `service.example` (Scheduler-Unit)
- Test: `tests/Feature/Console/PrunePromptsTest.php`

**Interfaces:**
- Produces: `PrunePrompts::marker(int $hours): string` → `"[prompt removed after {$hours} h retention]"`; Konfig `discussion.prompt_retention_hours` (int).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PrunePrompts;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrunePromptsTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $age): PromptLog
    {
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        $log = PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'Long prompt', 'response' => 'Answer', 'tokens_in' => 10, 'tokens_out' => 5]);
        $log->forceFill(['created_at' => now()->sub($age)])->save();

        return $log;
    }

    public function test_it_removes_old_prompt_texts_and_keeps_everything_else(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $fresh = $this->log('1 hour');

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(PrunePrompts::marker(24), $old->fresh()->prompt);
        $this->assertSame('Answer', $old->fresh()->response);
        $this->assertSame(10, $old->fresh()->tokens_in);
        $this->assertSame('Long prompt', $fresh->fresh()->prompt);
    }

    public function test_zero_hours_keeps_every_prompt(): void
    {
        config(['discussion.prompt_retention_hours' => 0]);
        $old = $this->log('30 days');

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame('Long prompt', $old->fresh()->prompt);
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('2 days');

        $this->artisan('prompts:prune');
        $updatedAt = $old->fresh()->updated_at;
        $this->artisan('prompts:prune')->expectsOutputToContain('0 prompt');

        $this->assertEquals($updatedAt, $old->fresh()->updated_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PrunePromptsTest`
Expected: FAIL (`The command "prompts:prune" does not exist.`)

- [ ] **Step 3: Implement**

`config/discussion.php`, neuer Eintrag im Rückgabe-Array:

```php
    /*
    | How long prompt_logs.prompt keeps the full prompt text, in hours. After that
    | prompts:prune replaces it with a short note; response, reasoning, tokens,
    | status and error stay. 0 or empty keeps every prompt. The texts are debugging
    | material and were 95 % of the database (709 of 815 MB on 13.10.2026).
    */
    'prompt_retention_hours' => (int) env('PROMPT_RETENTION_HOURS', 24),
```

`app/Console/Commands/PrunePrompts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\PromptLog;
use Illuminate\Console\Command;

/** Replaces prompt texts older than the retention window with a note; runs hourly. */
class PrunePrompts extends Command
{
    protected $signature = 'prompts:prune';

    protected $description = 'Remove prompt texts older than PROMPT_RETENTION_HOURS, keeping responses and tokens.';

    private const MARKER_PREFIX = '[prompt removed after ';

    public static function marker(int $hours): string
    {
        return self::MARKER_PREFIX."{$hours} h retention]";
    }

    public function handle(): int
    {
        $hours = (int) config('discussion.prompt_retention_hours');

        if ($hours <= 0) {
            $this->info('Prompt retention is off, nothing removed.');

            return self::SUCCESS;
        }

        $removed = PromptLog::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->where('prompt', 'not like', self::MARKER_PREFIX.'%')
            ->update(['prompt' => self::marker($hours)]);

        $this->info("{$removed} prompt texts removed.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` ergänzen:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('prompts:prune')->hourly();
```

`composer.json`, Skript `dev`: in den `concurrently`-Aufruf `\"php artisan schedule:work\"` aufnehmen, eine sechste Farbe `#f9a8d4` und den Namen `schedule` anhängen:

```json
"npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74,#86efac,#f9a8d4\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=300\" \"php artisan pail --timeout=0\" \"npm run dev\" \"php artisan reverb:start\" \"php artisan schedule:work\" --names=server,queue,logs,vite,reverb,schedule"
```

`service.example` am Ende anhängen:

```
---

[Unit]
Description=Laravel Scheduler (prompts:prune and other scheduled commands)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/communicat
ExecStart=php artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=PrunePromptsTest` → PASS; `php artisan schedule:list` zeigt `prompts:prune` stündlich; `php artisan test` grün.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PrunePrompts.php config/discussion.php routes/console.php composer.json service.example tests/Feature/Console/PrunePromptsTest.php
git commit -m "Keep prompt texts for 24 hours only"
```

---

### Task 3: Kostenrechnung entfernen

**Files:**
- Delete: `app/Discussion/Metrics/RunCosts.php`, `app/Discussion/Values/CostReport.php`, `app/Discussion/Values/CostLine.php`, `app/Console/Commands/ShowProjectCost.php`, `tests/Unit/Discussion/RunCostsTest.php`
- Modify: `app/Console/Commands/RunProject.php` (Kostenzeile und `RunCosts` raus)
- Modify: `config/ai.php` (alle `'price' => …`-Zeilen und der Kommentarabsatz zu „price“)
- Modify: Tests, die `Cost of this run` erwarten (finden per grep)

**Interfaces:**
- Consumes: nichts. Produces: nichts – reine Entfernung.

- [ ] **Step 1: Find every use**

Run: `grep -rn "RunCosts\|CostReport\|CostLine\|project:cost\|Cost of this run\|'price'" app tests config resources lang routes`
Expected: genau die oben gelisteten Dateien plus ggf. Tests von `RunProject`.

- [ ] **Step 2: Remove**

```bash
git rm app/Discussion/Metrics/RunCosts.php app/Discussion/Values/CostReport.php app/Discussion/Values/CostLine.php app/Console/Commands/ShowProjectCost.php tests/Unit/Discussion/RunCostsTest.php
```

In `app/Console/Commands/RunProject.php`: `use App\Discussion\Metrics\RunCosts;` entfernen, Signatur zu `public function handle(TurnRunner $runner): int`, die Zeilen um `$mark = $costs->mark();` samt Kommentar, die Tabellenzeile `['Cost of this run', …]` und die private Methode `cost()` löschen.

In `config/ai.php`: in jedem Modell-Eintrag die Zeile `'price' => [...]` löschen; im Kommentarblock über `'models'` den Absatz ab `"price" is US dollars per million tokens` bis `reconcile against the invoice before quoting a figure.` löschen.

In jedem Test, der `Cost of this run` erwartet: diese Erwartung entfernen.

- [ ] **Step 3: Verify**

Run: `grep -rn "RunCosts\|CostReport\|CostLine\|project:cost\|'price'" app tests config` → keine Treffer.
Run: `php artisan test` → grün; `php artisan list | grep project:` → kein `project:cost`.

- [ ] **Step 4: Commit**

```bash
git add -A app/Console/Commands/RunProject.php config/ai.php tests
git commit -m "Drop the cost calculation; tokens stay in prompt_logs"
```

---

### Task 4: Tokens im Job-Debug-Fenster

**Files:**
- Modify: `resources/views/components/debug/job-report.blade.php` (Kopfzeile je Prompt-Log und Summe je Job)
- Modify: `lang/en/debug.php`, `lang/de/debug.php`
- Test: `tests/Feature/Debug/JobReportTokensTest.php`

**Interfaces:**
- Consumes: `JobLog::promptLogs` mit `tokens_in`, `tokens_out`, `tokens_reasoning` (bereits eager geladen in `ShowsJobReport::jobReportFor()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Debug;

use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobReportTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_call_and_the_job_show_their_tokens(): void
    {
        app()->setLocale('en');
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => null]);

        $html = view('components.debug.job-report', [
            'logs' => collect([$job]),
            'selected' => $job->load('project', 'promptLogs', 'messages'),
            'selectedJobId' => $job->id,
        ])->render();

        $this->assertStringContainsString('In 1,200', $html);
        $this->assertStringContainsString('Out 300', $html);
        $this->assertStringContainsString('Reasoning 64', $html);
        $this->assertStringContainsString('Reasoning –', $html);
        $this->assertStringContainsString('In 2,000', $html);
        $this->assertStringContainsString('Out 400', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JobReportTokensTest` → FAIL (`In 1,200` fehlt).

- [ ] **Step 3: Implement**

`lang/en/debug.php`, im Array `detail` ergänzen:

```php
        'tokens_in' => 'In :count',
        'tokens_out' => 'Out :count',
        'tokens_reasoning' => 'Reasoning :count',
```

`lang/de/debug.php` ebenso:

```php
        'tokens_in' => 'Ein :count',
        'tokens_out' => 'Aus :count',
        'tokens_reasoning' => 'Reasoning :count',
```

In `resources/views/components/debug/job-report.blade.php` am Anfang der Datei (nach den `@props`) eine Formatierung definieren:

```blade
@php($tokens = fn (?int $count) => $count === null ? '–' : number_format($count))
```

Direkt über `<flux:tab.group>` die Summe des Jobs:

```blade
            <div class="mb-3 flex flex-wrap gap-2 text-xs">
                <flux:badge size="sm">{{ __('debug.detail.tokens_in', ['count' => $tokens($selected->promptLogs->whereNotNull('tokens_in')->isEmpty() ? null : $selected->promptLogs->sum('tokens_in'))]) }}</flux:badge>
                <flux:badge size="sm">{{ __('debug.detail.tokens_out', ['count' => $tokens($selected->promptLogs->whereNotNull('tokens_out')->isEmpty() ? null : $selected->promptLogs->sum('tokens_out'))]) }}</flux:badge>
                <flux:badge size="sm">{{ __('debug.detail.tokens_reasoning', ['count' => $tokens($selected->promptLogs->whereNotNull('tokens_reasoning')->isEmpty() ? null : $selected->promptLogs->sum('tokens_reasoning'))]) }}</flux:badge>
            </div>
```

In der Kopfzeile jedes Prompt-Logs direkt nach der Latenz-`<span>`:

```blade
                                <span class="font-mono text-zinc-500">{{ __('debug.detail.tokens_in', ['count' => $tokens($plog->tokens_in)]) }}</span>
                                <span class="font-mono text-zinc-500">{{ __('debug.detail.tokens_out', ['count' => $tokens($plog->tokens_out)]) }}</span>
                                <span class="font-mono text-zinc-500">{{ __('debug.detail.tokens_reasoning', ['count' => $tokens($plog->tokens_reasoning)]) }}</span>
```

- [ ] **Step 4: Run tests and look at it**

Run: `php artisan test --filter=JobReportTokensTest` → PASS; `php artisan test` grün. Im Browser `/debug/jobs` öffnen, einen Job wählen, Tokens sichtbar.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/debug/job-report.blade.php lang/en/debug.php lang/de/debug.php tests/Feature/Debug/JobReportTokensTest.php
git commit -m "Show input, output and reasoning tokens in the job debug report"
```

---

### Task 5: Bundle-Container, Leser und Bericht

**Files:**
- Create: `app/Services/Transfer/Bundle.php`, `InvalidBundle.php`, `BundleWriter.php`, `BundleReader.php`, `ImportReport.php`
- Test: `tests/Unit/Transfer/BundleReaderTest.php`, `tests/Unit/Transfer/ImportReportTest.php`

**Interfaces:**
- Produces:
  - `Bundle::SCHEMA_VERSION = 1`, `Bundle::MAX_UNPACKED_BYTES = 524_288_000`, `Bundle::KIND_PROJECT|KIND_EXPERT|KIND_GLOBAL`, `Bundle::projectEntry(string $uuid): string` → `"projects/{$uuid}.json"`
  - `new BundleWriter(string $path)`, `->put(string $entry, array $data): void`, `->close(): void`
  - `BundleReader::open(string $path): BundleReader` (wirft `InvalidBundle`), `->manifest(): array`, `->json(string $entry): array` (fehlender Eintrag → `[]`), `->projectEntries(): list<string>`
  - `ImportReport`: Konstanten `CREATED`, `SKIPPED`, `FAILED`; Bereiche `TAGS`, `EXPERTS`, `PROJECTS`, `GROUPS`, `QUESTIONS`; `add(string $section, string $name, string $outcome, ?string $note = null): void`, `entries(string $section): list<array{name: string, outcome: string, note: ?string}>`, `count(string $section, string $outcome): int`, `sections(): list<string>` (nur die mit Einträgen, in fester Reihenfolge), `hasFailures(): bool`, `toArray(): array`, `static fromArray(array): self`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Transfer/BundleReaderTest.php`:

```php
<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleReader;
use App\Services\Transfer\BundleWriter;
use App\Services\Transfer\InvalidBundle;
use Tests\TestCase;
use ZipArchive;

class BundleReaderTest extends TestCase
{
    private function path(): string
    {
        return tempnam(sys_get_temp_dir(), 'bundle').'.zip';
    }

    private function zip(array $entries): string
    {
        $path = $this->path();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_what_the_writer_puts_the_reader_reads(): void
    {
        $path = $this->path();
        $writer = new BundleWriter($path);
        $writer->put('manifest.json', ['schema_version' => Bundle::SCHEMA_VERSION, 'kind' => Bundle::KIND_GLOBAL]);
        $writer->put('experts.json', [['name' => 'Ä']]);
        $writer->put(Bundle::projectEntry('3f1c0a5e-8d2b-4e7a-9c11-2b6f0d4e8a77'), ['title' => 'x']);
        $writer->close();

        $reader = BundleReader::open($path);

        $this->assertSame(Bundle::KIND_GLOBAL, $reader->manifest()['kind']);
        $this->assertSame([['name' => 'Ä']], $reader->json('experts.json'));
        $this->assertSame([], $reader->json('groups.json'));
        $this->assertSame(['projects/3f1c0a5e-8d2b-4e7a-9c11-2b6f0d4e8a77.json'], $reader->projectEntries());
    }

    public function test_a_file_that_is_no_zip_is_refused(): void
    {
        $path = $this->path();
        file_put_contents($path, '{"project": {}}');

        $this->expectException(InvalidBundle::class);
        BundleReader::open($path);
    }

    public function test_a_bundle_without_manifest_is_refused(): void
    {
        $this->expectException(InvalidBundle::class);
        BundleReader::open($this->zip(['experts.json' => '[]']));
    }

    public function test_an_unknown_schema_version_is_refused(): void
    {
        $this->expectException(InvalidBundle::class);
        BundleReader::open($this->zip(['manifest.json' => '{"schema_version": 99}']));
    }

    public function test_an_unexpected_or_escaping_path_is_refused(): void
    {
        $manifest = json_encode(['schema_version' => Bundle::SCHEMA_VERSION]);

        foreach (['../evil.json', '/etc/passwd', 'projects/../x.json', 'notes.txt'] as $name) {
            try {
                BundleReader::open($this->zip(['manifest.json' => $manifest, $name => '{}']));
                $this->fail("{$name} was accepted");
            } catch (InvalidBundle) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

`tests/Unit/Transfer/ImportReportTest.php`:

```php
<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\ImportReport;
use PHPUnit\Framework\TestCase;

class ImportReportTest extends TestCase
{
    public function test_it_counts_per_section_and_outcome_and_survives_an_array_round_trip(): void
    {
        $report = new ImportReport;
        $report->add(ImportReport::PROJECTS, 'School', ImportReport::CREATED);
        $report->add(ImportReport::PROJECTS, 'Office', ImportReport::SKIPPED, 'already there');
        $report->add(ImportReport::EXPERTS, 'Alex', ImportReport::FAILED, 'broken');

        $copy = ImportReport::fromArray($report->toArray());

        $this->assertSame([ImportReport::EXPERTS, ImportReport::PROJECTS], $copy->sections());
        $this->assertSame(1, $copy->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
        $this->assertSame('already there', $copy->entries(ImportReport::PROJECTS)[1]['note']);
        $this->assertTrue($copy->hasFailures());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='BundleReaderTest|ImportReportTest'` → FAIL (Klassen fehlen).

- [ ] **Step 3: Implement**

`app/Services/Transfer/Bundle.php`:

```php
<?php

namespace App\Services\Transfer;

/**
 * The bundle format: a ZIP of JSON files. One format for a single project, a
 * single expert and the whole study, so one importer reads all three.
 */
final class Bundle
{
    public const SCHEMA_VERSION = 1;

    /** Guard against zip bombs: what an import may unpack in total. */
    public const MAX_UNPACKED_BYTES = 524_288_000;

    public const KIND_PROJECT = 'project';

    public const KIND_EXPERT = 'expert';

    public const KIND_GLOBAL = 'global';

    public const MANIFEST = 'manifest.json';

    public const TAGS = 'tags.json';

    public const EXPERTS = 'experts.json';

    public const GROUPS = 'groups.json';

    public const QUESTIONS = 'questions.json';

    /** Entry names a bundle may contain; anything else is refused. */
    public const ENTRY_PATTERN = '#^(?:(?:manifest|tags|experts|groups|questions)\.json|projects/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.json)$#';

    public static function projectEntry(string $uuid): string
    {
        return "projects/{$uuid}.json";
    }
}
```

`app/Services/Transfer/InvalidBundle.php`:

```php
<?php

namespace App\Services\Transfer;

use RuntimeException;

/** A file that is not a bundle this app can read; thrown before anything is written. */
final class InvalidBundle extends RuntimeException {}
```

`app/Services/Transfer/BundleWriter.php`:

```php
<?php

namespace App\Services\Transfer;

use RuntimeException;
use ZipArchive;

/** Writes JSON entries into a new ZIP at $path. */
final class BundleWriter
{
    private ZipArchive $zip;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write bundle to {$path}");
        }
    }

    public function put(string $entry, array $data): void
    {
        $this->zip->addFromString($entry, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
```

`app/Services/Transfer/BundleReader.php`:

```php
<?php

namespace App\Services\Transfer;

use JsonException;
use ZipArchive;

/**
 * Opens a bundle and checks it before anything reads it: a ZIP, a manifest of
 * this schema version, only the expected entry names (no paths that could
 * escape) and a bounded unpacked size.
 */
final class BundleReader
{
    private function __construct(private readonly ZipArchive $zip, private readonly array $manifest) {}

    public static function open(string $path): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidBundle(__('transfer.errors.not_a_zip'));
        }

        $unpacked = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! preg_match(Bundle::ENTRY_PATTERN, $stat['name'])) {
                throw new InvalidBundle(__('transfer.errors.unexpected_entry', ['name' => $stat['name']]));
            }

            $unpacked += $stat['size'];
        }

        if ($unpacked > Bundle::MAX_UNPACKED_BYTES) {
            throw new InvalidBundle(__('transfer.errors.too_large'));
        }

        $reader = new self($zip, []);
        $manifest = $reader->json(Bundle::MANIFEST);

        if (($manifest['schema_version'] ?? null) !== Bundle::SCHEMA_VERSION) {
            throw new InvalidBundle(__('transfer.errors.unknown_schema'));
        }

        return new self($zip, $manifest);
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    /** An entry's JSON; [] when the entry is absent. */
    public function json(string $entry): array
    {
        $content = $this->zip->getFromName($entry);

        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidBundle(__('transfer.errors.broken_entry', ['name' => $entry]));
        }

        return is_array($data) ? $data : [];
    }

    /** @return list<string> */
    public function projectEntries(): array
    {
        $entries = [];

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if (str_starts_with($name, 'projects/')) {
                $entries[] = $name;
            }
        }

        sort($entries);

        return $entries;
    }
}
```

`app/Services/Transfer/ImportReport.php`:

```php
<?php

namespace App\Services\Transfer;

/** What an import did, per section: each entry created, skipped or failed, with a note why. */
final class ImportReport
{
    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const TAGS = 'tags';

    public const EXPERTS = 'experts';

    public const PROJECTS = 'projects';

    public const GROUPS = 'groups';

    public const QUESTIONS = 'questions';

    private const ORDER = [self::TAGS, self::EXPERTS, self::PROJECTS, self::GROUPS, self::QUESTIONS];

    /** @var array<string, list<array{name: string, outcome: string, note: ?string}>> */
    private array $entries = [];

    public function add(string $section, string $name, string $outcome, ?string $note = null): void
    {
        $this->entries[$section][] = ['name' => $name, 'outcome' => $outcome, 'note' => $note];
    }

    /** @return list<array{name: string, outcome: string, note: ?string}> */
    public function entries(string $section): array
    {
        return $this->entries[$section] ?? [];
    }

    public function count(string $section, string $outcome): int
    {
        return count(array_filter($this->entries($section), fn (array $entry) => $entry['outcome'] === $outcome));
    }

    /** @return list<string> */
    public function sections(): array
    {
        return array_values(array_filter(self::ORDER, fn (string $section) => $this->entries($section) !== []));
    }

    public function hasFailures(): bool
    {
        foreach ($this->sections() as $section) {
            if ($this->count($section, self::FAILED) > 0) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return $this->entries;
    }

    public static function fromArray(array $data): self
    {
        $report = new self;

        foreach ($data as $section => $entries) {
            foreach ($entries as $entry) {
                $report->add((string) $section, (string) $entry['name'], (string) $entry['outcome'], $entry['note'] ?? null);
            }
        }

        return $report;
    }
}
```

`lang/en/transfer.php` anlegen (wird in späteren Tasks ergänzt):

```php
<?php

return [
    'errors' => [
        'not_a_zip' => 'This file is not a bundle. Export one from this app and try again.',
        'unexpected_entry' => 'The bundle contains an unexpected file: :name',
        'too_large' => 'The bundle unpacks to more than 500 MB.',
        'unknown_schema' => 'The bundle was written by an incompatible version of this app.',
        'broken_entry' => 'The bundle file :name is not valid JSON.',
    ],
];
```

`lang/de/transfer.php`:

```php
<?php

return [
    'errors' => [
        'not_a_zip' => 'Diese Datei ist kein Bundle. Exportiere eines aus dieser App und versuche es erneut.',
        'unexpected_entry' => 'Das Bundle enthält eine unerwartete Datei: :name',
        'too_large' => 'Das Bundle ist entpackt größer als 500 MB.',
        'unknown_schema' => 'Das Bundle stammt aus einer inkompatiblen Version dieser App.',
        'broken_entry' => 'Die Datei :name im Bundle ist kein gültiges JSON.',
    ],
];
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter='BundleReaderTest|ImportReportTest'` → PASS; `php artisan test` grün.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Transfer lang/en/transfer.php lang/de/transfer.php tests/Unit/Transfer
git commit -m "Add the bundle container, its checked reader and the import report"
```

---

### Task 6: Tags und Experten übertragen

**Files:**
- Create: `app/Services/Transfer/TagTransfer.php`, `app/Services/Transfer/ExpertTransfer.php`
- Modify: `lang/en/transfer.php`, `lang/de/transfer.php` (Hinweise)
- Test: `tests/Feature/Transfer/TagAndExpertTransferTest.php`

**Interfaces:**
- Consumes: `ImportReport` (Task 5), `TagsByName::export()/assign()`, `TagNames::same()`, `Expert::$uuid` (Task 1)
- Produces:
  - `TagTransfer::export(): list<array{scope: string, name: string, values: list<string>}>` (alle Kategorien, nach Scope und Position)
  - `TagTransfer::import(array $categories, ImportReport $report): void` (legt Fehlendes an)
  - `ExpertTransfer::export(Expert $expert): array` mit Schlüsseln `uuid`, `id`, `name`, `role`, `description`, `avatar_url`, `tags`, `created_at`
  - `ExpertTransfer::import(array $data, ImportReport $report): void` (per UUID; behält `id`, wenn frei)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Transfer\ExpertTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\TagTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagAndExpertTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_are_exported_by_name_and_missing_ones_are_created_on_import(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $category->values()->create(['name' => 'true']);
        $category->values()->create(['name' => 'false']);
        $exported = (new TagTransfer)->export();

        $this->assertSame([['scope' => 'persona', 'name' => 'title-holder', 'values' => ['true', 'false']]], $exported);

        $exported[] = ['scope' => 'project', 'name' => 'status condition', 'values' => ['status']];
        $report = new ImportReport;
        (new TagTransfer)->import($exported, $report);

        $this->assertSame(2, TagCategory::count());
        $this->assertSame(['status'], TagCategory::where('name', 'status condition')->sole()->values()->pluck('name')->all());
        $this->assertSame(1, $report->count(ImportReport::TAGS, ImportReport::CREATED));
        $this->assertSame(1, $report->count(ImportReport::TAGS, ImportReport::SKIPPED));
    }

    public function test_an_expert_round_trips_with_its_tags_and_keeps_a_free_id(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $value = $category->values()->create(['name' => 'true']);
        $expert = Expert::factory()->create(['name' => 'Alex Brandt', 'role' => 'CEO']);
        $expert->setTag($category, $value);
        $data = (new ExpertTransfer)->export($expert);
        $expert->delete();

        $report = new ImportReport;
        (new ExpertTransfer)->import($data, $report);

        $copy = Expert::where('uuid', $data['uuid'])->sole();
        $this->assertSame($data['id'], $copy->id);
        $this->assertSame('CEO', $copy->role);
        $this->assertSame(['title-holder' => 'true'], $data['tags']);
        $this->assertTrue($copy->tagValues()->whereKey($value->id)->exists());
        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::CREATED));
    }

    public function test_an_existing_expert_is_skipped_and_a_taken_id_gets_a_new_one_with_a_note(): void
    {
        $expert = Expert::factory()->create();
        $data = (new ExpertTransfer)->export($expert);
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);
        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::SKIPPED));

        $data['uuid'] = '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d';
        (new ExpertTransfer)->import($data, $report);
        $copy = Expert::where('uuid', $data['uuid'])->sole();

        $this->assertNotSame($expert->id, $copy->id);
        $this->assertNotNull($report->entries(ImportReport::EXPERTS)[1]['note']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TagAndExpertTransferTest` → FAIL (Klassen fehlen).

- [ ] **Step 3: Implement**

`app/Services/Transfer/TagTransfer.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;

/**
 * All tag categories and values by name. An import creates what is missing:
 * an archive whose tags were dropped would lose what its runs meant.
 */
final class TagTransfer
{
    /** @return list<array{scope: string, name: string, values: list<string>}> */
    public function export(): array
    {
        return TagCategory::with(['values' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('scope')->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (TagCategory $category) => [
                'scope' => $category->scope->value,
                'name' => $category->name,
                'values' => $category->values->pluck('name')->all(),
            ])
            ->values()
            ->all();
    }

    public function import(array $categories, ImportReport $report): void
    {
        foreach ($categories as $data) {
            $scope = TagScope::tryFrom((string) ($data['scope'] ?? ''));
            $name = trim((string) ($data['name'] ?? ''));

            if ($scope === null || $name === '') {
                $report->add(ImportReport::TAGS, $name, ImportReport::FAILED, __('transfer.notes.tag_unreadable'));

                continue;
            }

            $category = TagCategory::ofScope($scope)->get()->first(fn (TagCategory $existing) => TagNames::same($existing->name, $name));
            $created = $category === null;
            $category ??= TagCategory::create(['scope' => $scope, 'name' => $name]);
            $newValues = [];

            foreach ($data['values'] ?? [] as $valueName) {
                $valueName = trim((string) $valueName);

                if ($valueName === '' || $category->values()->get()->contains(fn (TagValue $value) => TagNames::same($value->name, $valueName))) {
                    continue;
                }

                $category->values()->create(['name' => $valueName]);
                $newValues[] = $valueName;
            }

            $report->add(
                ImportReport::TAGS,
                $name,
                $created ? ImportReport::CREATED : ImportReport::SKIPPED,
                $newValues === [] ? null : __('transfer.notes.tag_values_added', ['values' => implode(', ', $newValues)]),
            );
        }
    }
}
```

`app/Services/Transfer/ExpertTransfer.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Models\Expert;
use App\Services\Tags\TagsByName;
use Illuminate\Support\Carbon;

/**
 * One expert by uuid. An import keeps the original id where it is free: the
 * selector signals and the E{id} tokens in logged prompts and answers name
 * experts by id, and they stay right only if the id does.
 */
final class ExpertTransfer
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    public function export(Expert $expert): array
    {
        return [
            'uuid' => $expert->uuid,
            'id' => $expert->id,
            'name' => $expert->name,
            'role' => $expert->role,
            'description' => $expert->description,
            'avatar_url' => $expert->avatar_url,
            'tags' => $this->tags->export($expert),
            'created_at' => $expert->created_at?->toIso8601String(),
        ];
    }

    public function import(array $data, ImportReport $report): void
    {
        $label = trim(($data['name'] ?? '').' '.($data['role'] ?? ''));
        $uuid = (string) ($data['uuid'] ?? '');

        if ($uuid === '') {
            $report->add(ImportReport::EXPERTS, $label, ImportReport::FAILED, __('transfer.notes.no_uuid'));

            return;
        }

        if (Expert::where('uuid', $uuid)->exists()) {
            $report->add(ImportReport::EXPERTS, $label, ImportReport::SKIPPED, __('transfer.notes.already_there'));

            return;
        }

        $expert = new Expert;
        $originalId = (int) ($data['id'] ?? 0);
        $keepsId = $originalId > 0 && ! Expert::whereKey($originalId)->exists();

        if ($keepsId) {
            $expert->id = $originalId;
        }

        $expert->forceFill([
            'uuid' => $uuid,
            'name' => (string) ($data['name'] ?? ''),
            'role' => (string) ($data['role'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'avatar_url' => $data['avatar_url'] ?? null,
            'created_at' => isset($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
        ])->save();

        $skippedTags = $this->tags->assign($expert, $data['tags'] ?? []);
        $notes = array_filter([
            $keepsId || $originalId === 0 ? null : __('transfer.notes.new_expert_id', ['old' => $originalId, 'new' => $expert->id]),
            $skippedTags === [] ? null : __('transfer.notes.tags_skipped', ['tags' => implode(', ', $skippedTags)]),
        ]);

        $report->add(ImportReport::EXPERTS, $label, ImportReport::CREATED, $notes === [] ? null : implode(' ', $notes));
    }
}
```

`lang/en/transfer.php`, neues Array `notes`:

```php
    'notes' => [
        'already_there' => 'Already in the database.',
        'no_uuid' => 'The entry has no uuid.',
        'tag_unreadable' => 'The tag category has no name or an unknown scope.',
        'tag_values_added' => 'Values added: :values',
        'tags_skipped' => 'Tags not set: :tags',
        'new_expert_id' => 'Id :old was taken, so the expert got id :new. Selector signals and E-tokens in its logs still name :old.',
    ],
```

`lang/de/transfer.php`:

```php
    'notes' => [
        'already_there' => 'Schon in der Datenbank.',
        'no_uuid' => 'Der Eintrag hat keine UUID.',
        'tag_unreadable' => 'Die Tag-Kategorie hat keinen Namen oder einen unbekannten Bereich.',
        'tag_values_added' => 'Werte ergänzt: :values',
        'tags_skipped' => 'Tags nicht gesetzt: :tags',
        'new_expert_id' => 'ID :old war vergeben, der Experte hat jetzt ID :new. Selektor-Signale und E-Tokens in seinen Logs nennen weiter :old.',
    ],
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=TagAndExpertTransferTest` → PASS; `php artisan test` grün.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Transfer/TagTransfer.php app/Services/Transfer/ExpertTransfer.php lang/en/transfer.php lang/de/transfer.php tests/Feature/Transfer/TagAndExpertTransferTest.php
git commit -m "Transfer tags and experts by name and uuid"
```

---

### Task 7: Ein Projekt komplett übertragen

**Files:**
- Create: `app/Services/Transfer/ProjectTransfer.php`
- Modify: `lang/en/transfer.php`, `lang/de/transfer.php`
- Test: `tests/Feature/Transfer/ProjectTransferTest.php`

**Interfaces:**
- Consumes: `ImportReport`, `ExpertTransfer` nicht direkt; Experten werden per UUID aus der DB aufgelöst (sie werden vorher importiert). `PipelineRegistry::has()`, `config('ai.models')`, `TagsByName`, `Project::$uuid`, `Expert::$uuid`.
- Produces:
  - `ProjectTransfer::export(Project $project): array` (Struktur siehe Spec 1; Schlüssel `uuid`, `title`, `description`, `pipeline`, `model`, `seed`, `turn_budget`, `run_config`, `summarize_threshold`, `summarize_oldest`, `long_term_memory`, `summarized_until_message`, `created_at`, `tags`, `seats`, `messages`, `summaries`, `jobs`)
  - `ProjectTransfer::expertIds(Project $project): list<int>` – alle Experten, auf die das Projekt verweist (Sitze, Nachrichten, Adressaten, Turns, Prompt-Logs, Gedächtnis)
  - `ProjectTransfer::import(array $data, User $owner, ImportReport $report): ?Project` (null, wenn übersprungen oder fehlgeschlagen)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Discussion\Metrics\SpeakingShares;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use App\Models\User;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTransferTest extends TestCase
{
    use RefreshDatabase;

    private function studyRun(User $owner): Project
    {
        [$alex, $kim] = Expert::factory()->count(2)->create()->all();
        $project = Project::factory()->create(['user_id' => $owner->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini', 'seed' => 42, 'turn_budget' => 60, 'run_config' => ['pipeline' => 'ScorePipeline']]);
        $project->addContributingExpert($alex);
        $project->addContributingExpert($kim);
        $job = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'turn_index' => 1, 'expert_id' => $alex->id, 'seat' => 1, 'words' => 3, 'chars' => 15, 'selection' => ['selector' => 'HighestBidSelector', 'speaker_id' => $alex->id, 'signals' => ['bids' => ["E{$alex->id}" => 5]]]]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'gpt-5-mini', 'prompt' => 'p', 'response' => 'r', 'purpose' => 'think', 'expert_id' => $kim->id, 'tokens_in' => 10, 'tokens_out' => 5]);
        $first = new Message;
        $first->forceFill(['project_id' => $project->id, 'expert_id' => $alex->id, 'addressee_expert_id' => $kim->id, 'job_log_id' => $job->id, 'content' => 'Hello Kim'])->save();
        Summary::create(['project_id' => $project->id, 'expert_id' => $kim->id, 'content' => 'Kim thinks.']);
        $project->update(['long_term_memory' => 'So far.', 'summarized_until_message_id' => $first->id]);

        return $project->fresh();
    }

    public function test_a_run_survives_export_and_import_into_an_empty_project_table(): void
    {
        $owner = User::factory()->create();
        $source = $this->studyRun($owner);
        $sharesBefore = app(SpeakingShares::class)->forProject($source);
        $data = (new ProjectTransfer)->export($source);
        $source->delete();

        $report = new ImportReport;
        $copy = (new ProjectTransfer)->import($data, $owner, $report);

        $this->assertNotNull($copy);
        $this->assertSame($data['uuid'], $copy->uuid);
        $this->assertSame(42, (int) $copy->seed);
        $this->assertSame(['pipeline' => 'ScorePipeline'], $copy->run_config);
        $this->assertSame([1, 2], $copy->experts()->get()->pluck('pivot.seat')->map(fn ($seat) => (int) $seat)->all());
        $this->assertSame('Hello Kim', $copy->messages()->sole()->content);
        $this->assertSame($copy->messages()->sole()->id, (int) $copy->summarized_until_message_id);
        $this->assertSame(1, JobLog::where('project_id', $copy->id)->count());
        $this->assertSame(10, PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copy->id))->sole()->tokens_in);
        $this->assertSame(JobLog::where('project_id', $copy->id)->sole()->id, $copy->messages()->sole()->job_log_id);
        $this->assertSame('Kim thinks.', Summary::where('project_id', $copy->id)->sole()->content);
        $this->assertEquals($sharesBefore->speakers, app(SpeakingShares::class)->forProject($copy)->speakers);
        $this->assertTrue($copy->hasContributor($owner));
    }

    public function test_an_existing_project_is_skipped(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
    }

    public function test_an_unknown_pipeline_or_a_missing_expert_skips_the_project_with_a_reason(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        $report = new ImportReport;

        $unknownPipeline = ['pipeline' => 'GonePipeline'] + $data;
        $this->assertNull((new ProjectTransfer)->import($unknownPipeline, $owner, $report));

        $missingExpert = $data;
        $missingExpert['seats'][0]['expert'] = '9d1e2f3a-4b5c-4d6e-8f7a-0b1c2d3e4f5a';
        $this->assertNull((new ProjectTransfer)->import($missingExpert, $owner, $report));

        $this->assertSame(2, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
        $this->assertSame(0, Project::count());
    }

    public function test_it_names_every_expert_the_project_refers_to(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $gone = Expert::factory()->create();
        PromptLog::create(['job_log_id' => JobLog::sole()->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'expert_id' => $gone->id]);

        $this->assertContains($gone->id, (new ProjectTransfer)->expertIds($project));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter='Transfer\\ProjectTransferTest'` → FAIL (Klasse fehlt).

- [ ] **Step 3: Implement**

`app/Services/Transfer/ProjectTransfer.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use App\Models\User;
use App\Services\Tags\TagsByName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One run, complete: settings, frozen run_config, seats, messages, both memory
 * layers, the per-turn measurement and every logged call. Experts are named by
 * uuid; inside the file, messages and turns refer to each other by their
 * original ids, which are local keys and never written back as ids.
 */
final class ProjectTransfer
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    /** @return list<int> */
    public function expertIds(Project $project): array
    {
        $jobIds = JobLog::where('project_id', $project->id)->pluck('id');

        return collect()
            ->merge($project->experts()->pluck('experts.id'))
            ->merge(Message::where('project_id', $project->id)->pluck('expert_id'))
            ->merge(Message::where('project_id', $project->id)->pluck('addressee_expert_id'))
            ->merge(JobLog::where('project_id', $project->id)->pluck('expert_id'))
            ->merge(PromptLog::whereIn('job_log_id', $jobIds)->pluck('expert_id'))
            ->merge(Summary::where('project_id', $project->id)->pluck('expert_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public function export(Project $project): array
    {
        $uuids = Expert::whereIn('id', $this->expertIds($project))->pluck('uuid', 'id');
        $uuid = fn ($id) => $id === null ? null : ($uuids[(int) $id] ?? null);
        $time = fn ($value) => $value === null ? null : Carbon::parse($value)->toIso8601String();

        return [
            'uuid' => $project->uuid,
            'title' => $project->title,
            'description' => $project->description,
            'pipeline' => $project->pipeline,
            'model' => $project->model,
            'seed' => $project->seed,
            'turn_budget' => $project->turn_budget,
            'run_config' => $project->run_config,
            'summarize_threshold' => $project->summarize_threshold,
            'summarize_oldest' => $project->summarize_oldest,
            'long_term_memory' => $project->long_term_memory,
            'summarized_until_message' => $project->summarized_until_message_id,
            'created_at' => $time($project->created_at),
            'tags' => $this->tags->export($project),
            'seats' => $project->experts()->get()
                ->map(fn (Expert $expert) => ['expert' => $expert->uuid, 'seat' => $expert->pivot->seat === null ? null : (int) $expert->pivot->seat])
                ->values()->all(),
            'messages' => Message::where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (Message $message) => [
                    'key' => $message->id,
                    'content' => $message->content,
                    'expert' => $uuid($message->expert_id),
                    'is_user' => $message->user_id !== null,
                    'addressee' => $uuid($message->addressee_expert_id),
                    'job' => $message->job_log_id,
                    'created_at' => $time($message->created_at),
                ])->values()->all(),
            'summaries' => Summary::where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (Summary $summary) => [
                    'expert' => $uuid($summary->expert_id),
                    'content' => $summary->content,
                    'updated_at' => $time($summary->updated_at),
                ])->values()->all(),
            'jobs' => JobLog::with(['promptLogs' => fn ($query) => $query->orderBy('id')])
                ->where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (JobLog $job) => [
                    'key' => $job->id,
                    'job_class' => $job->job_class,
                    'status' => $job->status,
                    'payload' => $job->payload,
                    'turn_index' => $job->turn_index,
                    'expert' => $uuid($job->expert_id),
                    'seat' => $job->seat,
                    'words' => $job->words,
                    'chars' => $job->chars,
                    'thought_words' => $job->thought_words,
                    'thought_chars' => $job->thought_chars,
                    'reasoning_tokens' => $job->reasoning_tokens,
                    'selection' => $job->selection,
                    'error' => $job->error,
                    'started_at' => $time($job->started_at),
                    'finished_at' => $time($job->finished_at),
                    'prompts' => $job->promptLogs->map(fn (PromptLog $log) => [
                        'label' => $log->label,
                        'model' => $log->model,
                        'prompt' => $log->prompt,
                        'response' => $log->response,
                        'latency_ms' => $log->latency_ms,
                        'provider' => $log->provider,
                        'checkpoint' => $log->checkpoint,
                        'config' => $log->config,
                        'purpose' => $log->purpose,
                        'expert' => $uuid($log->expert_id),
                        'reasoning' => $log->reasoning,
                        'tokens_in' => $log->tokens_in,
                        'tokens_out' => $log->tokens_out,
                        'tokens_reasoning' => $log->tokens_reasoning,
                        'status' => $log->status,
                        'error' => $log->error,
                        'created_at' => $time($log->created_at),
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    public function import(array $data, User $owner, ImportReport $report): ?Project
    {
        $title = (string) ($data['title'] ?? '');
        $reason = $this->reasonToSkip($data);

        if ($reason !== null) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::SKIPPED, $reason);

            return null;
        }

        try {
            $project = DB::transaction(fn () => $this->write($data, $owner, $this->expertIdsByUuid($data)));
        } catch (Throwable $exception) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::FAILED, $exception->getMessage());

            return null;
        }

        $report->add(ImportReport::PROJECTS, $title, ImportReport::CREATED);

        return $project;
    }

    private function reasonToSkip(array $data): ?string
    {
        $uuid = (string) ($data['uuid'] ?? '');

        return match (true) {
            $uuid === '' => __('transfer.notes.no_uuid'),
            Project::where('uuid', $uuid)->exists() => __('transfer.notes.already_there'),
            ! app(PipelineRegistry::class)->has((string) ($data['pipeline'] ?? '')) => __('transfer.notes.unknown_pipeline', ['pipeline' => $data['pipeline'] ?? '']),
            ! isset(config('ai.models')[(string) ($data['model'] ?? '')]) => __('transfer.notes.unknown_model', ['model' => $data['model'] ?? '']),
            ($missing = $this->missingExperts($data)) !== [] => __('transfer.notes.missing_experts', ['experts' => implode(', ', $missing)]),
            default => null,
        };
    }

    /** @return list<string> uuids the file names that are neither here nor imported before */
    private function missingExperts(array $data): array
    {
        $named = $this->namedExperts($data);

        return array_values(array_diff($named, Expert::whereIn('uuid', $named)->pluck('uuid')->all()));
    }

    /** @return list<string> */
    private function namedExperts(array $data): array
    {
        $named = [];

        foreach ($data['seats'] ?? [] as $seat) {
            $named[] = $seat['expert'] ?? null;
        }

        foreach ($data['messages'] ?? [] as $message) {
            $named[] = $message['expert'] ?? null;
            $named[] = $message['addressee'] ?? null;
        }

        foreach ($data['summaries'] ?? [] as $summary) {
            $named[] = $summary['expert'] ?? null;
        }

        foreach ($data['jobs'] ?? [] as $job) {
            $named[] = $job['expert'] ?? null;

            foreach ($job['prompts'] ?? [] as $prompt) {
                $named[] = $prompt['expert'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($named)));
    }

    /** @return array<string, int> */
    private function expertIdsByUuid(array $data): array
    {
        return Expert::whereIn('uuid', $this->namedExperts($data))->pluck('id', 'uuid')->map(fn ($id) => (int) $id)->all();
    }

    /** @param  array<string, int>  $experts  uuid => id */
    private function write(array $data, User $owner, array $experts): Project
    {
        $expert = fn (?string $uuid) => $uuid === null ? null : $experts[$uuid];
        $time = fn ($value) => $value === null ? null : Carbon::parse($value);

        $project = new Project;
        $project->forceFill([
            'uuid' => $data['uuid'],
            'user_id' => $owner->id,
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'pipeline' => $data['pipeline'],
            'model' => $data['model'],
            'seed' => $data['seed'] ?? null,
            'turn_budget' => $data['turn_budget'] ?? null,
            'run_config' => $data['run_config'] ?? null,
            'summarize_threshold' => $data['summarize_threshold'] ?? null,
            'summarize_oldest' => $data['summarize_oldest'] ?? null,
            'long_term_memory' => $data['long_term_memory'] ?? null,
            'created_at' => $time($data['created_at'] ?? null) ?? now(),
        ])->save();

        $project->addContributingUser($owner);
        $this->tags->assign($project, $data['tags'] ?? []);

        foreach ($data['seats'] ?? [] as $seat) {
            $project->experts()->attach($expert($seat['expert']), ['seat' => $seat['seat']]);
        }

        $jobIds = [];

        foreach ($data['jobs'] ?? [] as $job) {
            $log = new JobLog;
            $log->forceFill([
                'project_id' => $project->id,
                'job_class' => (string) ($job['job_class'] ?? ''),
                'status' => (string) ($job['status'] ?? 'success'),
                'payload' => $job['payload'] ?? null,
                'turn_index' => $job['turn_index'] ?? null,
                'expert_id' => $expert($job['expert'] ?? null),
                'seat' => $job['seat'] ?? null,
                'words' => $job['words'] ?? null,
                'chars' => $job['chars'] ?? null,
                'thought_words' => $job['thought_words'] ?? null,
                'thought_chars' => $job['thought_chars'] ?? null,
                'reasoning_tokens' => $job['reasoning_tokens'] ?? null,
                'selection' => $job['selection'] ?? null,
                'error' => $job['error'] ?? null,
                'started_at' => $time($job['started_at'] ?? null) ?? now(),
                'finished_at' => $time($job['finished_at'] ?? null),
                'created_at' => $time($job['started_at'] ?? null) ?? now(),
            ])->save();
            $jobIds[(int) $job['key']] = $log->id;

            foreach ($job['prompts'] ?? [] as $prompt) {
                (new PromptLog)->forceFill([
                    'job_log_id' => $log->id,
                    'label' => $prompt['label'] ?? null,
                    'model' => (string) ($prompt['model'] ?? ''),
                    'prompt' => (string) ($prompt['prompt'] ?? ''),
                    'response' => (string) ($prompt['response'] ?? ''),
                    'latency_ms' => $prompt['latency_ms'] ?? null,
                    'provider' => $prompt['provider'] ?? null,
                    'checkpoint' => $prompt['checkpoint'] ?? null,
                    'config' => $prompt['config'] ?? null,
                    'purpose' => $prompt['purpose'] ?? null,
                    'expert_id' => $expert($prompt['expert'] ?? null),
                    'reasoning' => $prompt['reasoning'] ?? null,
                    'tokens_in' => $prompt['tokens_in'] ?? null,
                    'tokens_out' => $prompt['tokens_out'] ?? null,
                    'tokens_reasoning' => $prompt['tokens_reasoning'] ?? null,
                    'status' => (string) ($prompt['status'] ?? 'ok'),
                    'error' => $prompt['error'] ?? null,
                    'created_at' => $time($prompt['created_at'] ?? null) ?? now(),
                ])->save();
            }
        }

        $messageIds = [];

        foreach ($data['messages'] ?? [] as $message) {
            $row = new Message;
            $row->forceFill([
                'project_id' => $project->id,
                'content' => (string) ($message['content'] ?? ''),
                'expert_id' => $expert($message['expert'] ?? null),
                'user_id' => ! empty($message['is_user']) ? $owner->id : null,
                'addressee_expert_id' => $expert($message['addressee'] ?? null),
                'job_log_id' => isset($message['job']) ? ($jobIds[(int) $message['job']] ?? null) : null,
                'created_at' => $time($message['created_at'] ?? null) ?? now(),
            ])->save();
            $messageIds[(int) $message['key']] = $row->id;
        }

        foreach ($data['summaries'] ?? [] as $summary) {
            (new Summary)->forceFill([
                'project_id' => $project->id,
                'expert_id' => $expert($summary['expert']),
                'content' => (string) ($summary['content'] ?? ''),
                'updated_at' => $time($summary['updated_at'] ?? null) ?? now(),
            ])->save();
        }

        $watermark = $data['summarized_until_message'] ?? null;
        $project->forceFill(['summarized_until_message_id' => $watermark === null ? null : ($messageIds[(int) $watermark] ?? null)])->save();

        return $project->fresh();
    }
}
```

Hinweis: `PipelineRegistry::has()` existiert bereits (vom alten Importer genutzt).

`lang/en/transfer.php`, im Array `notes` ergänzen:

```php
        'unknown_pipeline' => 'Unknown pipeline :pipeline.',
        'unknown_model' => 'Unknown model :model.',
        'missing_experts' => 'Experts missing from the bundle and the database: :experts',
```

`lang/de/transfer.php`:

```php
        'unknown_pipeline' => 'Unbekannte Pipeline :pipeline.',
        'unknown_model' => 'Unbekanntes Modell :model.',
        'missing_experts' => 'Experten fehlen in Bundle und Datenbank: :experts',
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter='Transfer\\ProjectTransferTest'` → PASS; `php artisan test` grün.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Transfer/ProjectTransfer.php lang/en/transfer.php lang/de/transfer.php tests/Feature/Transfer/ProjectTransferTest.php
git commit -m "Transfer a whole run with its turns and logged calls"
```

---

### Task 8: Gruppen und Fragen übertragen

**Files:**
- Create: `app/Services/Transfer/DefinitionNames.php`, `app/Services/Transfer/GroupTransfer.php`, `app/Services/Transfer/QuestionTransfer.php`
- Modify: `lang/en/transfer.php`, `lang/de/transfer.php`
- Test: `tests/Feature/Transfer/GroupAndQuestionTransferTest.php`

**Interfaces:**
- Consumes: `ImportReport`, `ProjectGroup`, `Question`, `QuestionDefinition::fromArray()/toArray()`, `Dimension::fromKey()`, `DimensionType`, `DimensionValues::NONE`, `TagCategory`, `TagValue`
- Produces:
  - `DefinitionNames::toNames(array $definition, User $user): array` und `DefinitionNames::toIds(array $named, User $user): array` (Rückgabe ist ein Array für `QuestionDefinition::fromArray()`)
  - `GroupTransfer::export(User $user): list<array{name: string, projects: list<string>}>`
  - `GroupTransfer::import(array $groups, User $user, ImportReport $report): void`
  - `QuestionTransfer::export(User $user): list<array{name: ?string, notes: ?string, is_template: bool, definition: array}>` (eigene Fragen + alle Vorlagen, je in Reihenfolge)
  - `QuestionTransfer::import(array $questions, User $user, ImportReport $report): void`

Benannte Definition: `people.tags` und `projects.tags` als `{Kategoriename: [Wertnamen]}`, `projects.groups` als `[Gruppennamen]`, `compare_by`/`split_by` bei Tags als `persona_tag:<Kategoriename>`/`project_tag:<Kategoriename>`, `baseline` bei Tag-Dimensionen als Wertname (`''` bleibt `''`), bei Gruppen als Gruppenname, sonst unverändert. Nicht auflösbare Namen fallen heraus.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Transfer\DefinitionNames;
use App\Services\Transfer\GroupTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\QuestionTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupAndQuestionTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_definition_travels_by_names_and_comes_back_as_this_databases_ids(): void
    {
        $user = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $noStatus = $condition->values()->create(['name' => 'no-status']);
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $group = ProjectGroup::create(['user_id' => $user->id, 'name' => 'Quality', 'position' => 1]);
        $definition = [
            'metric' => 'turn_share',
            'people' => ['tags' => [$focus->id => [$true->id]]],
            'projects' => ['groups' => [$group->id]],
            'compare_by' => "project_tag:{$condition->id}",
            'baseline' => (string) $noStatus->id,
            'split_by' => 'pipeline',
        ];

        $named = DefinitionNames::toNames($definition, $user);

        $this->assertSame(['focus persona' => ['true']], $named['people']['tags']);
        $this->assertSame(['Quality'], $named['projects']['groups']);
        $this->assertSame('project_tag:status condition', $named['compare_by']);
        $this->assertSame('no-status', $named['baseline']);
        $this->assertSame($definition['compare_by'], DefinitionNames::toIds($named, $user)['compare_by']);
        $this->assertSame($definition['baseline'], DefinitionNames::toIds($named, $user)['baseline']);
        $this->assertSame([$focus->id => [$true->id]], DefinitionNames::toIds($named, $user)['people']['tags']);
        $this->assertSame([$group->id], DefinitionNames::toIds($named, $user)['projects']['groups']);
    }

    public function test_groups_are_recreated_for_the_importing_user_and_file_its_projects(): void
    {
        $source = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $source->id]);
        $project->addContributingUser($source);
        $group = ProjectGroup::create(['user_id' => $source->id, 'name' => 'Quality', 'position' => 1]);
        $project->fileInGroupFor($source, $group->id);
        $exported = (new GroupTransfer)->export($source);

        $reader = User::factory()->create();
        $project->addContributingUser($reader);
        $report = new ImportReport;
        (new GroupTransfer)->import($exported, $reader, $report);

        $copy = ProjectGroup::where('user_id', $reader->id)->sole();
        $this->assertSame('Quality', $copy->name);
        $this->assertSame($copy->id, $project->groupIdFor($reader));
        $this->assertSame(1, $report->count(ImportReport::GROUPS, ImportReport::CREATED));
    }

    public function test_questions_come_back_as_own_questions_and_templates_only_for_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->questions()->create(['name' => 'Mine', 'notes' => 'n', 'definition' => ['metric' => 'word_share']]);
        $admin->questions()->create(['name' => 'Template', 'definition' => [], 'is_template' => true]);
        $exported = (new QuestionTransfer)->export($admin);
        Question::query()->delete();

        $reader = User::factory()->create();
        $report = new ImportReport;
        (new QuestionTransfer)->import($exported, $reader, $report);

        $this->assertSame(['Mine', 'Template'], $reader->questions()->orderBy('position')->pluck('name')->all());
        $this->assertSame(0, Question::templates()->count());
        $this->assertSame('word_share', $reader->questions()->where('name', 'Mine')->sole()->definition()->metric->value);

        (new QuestionTransfer)->import($exported, $reader, $report);
        $this->assertSame(2, $reader->questions()->count());
        $this->assertSame(2, $report->count(ImportReport::QUESTIONS, ImportReport::SKIPPED));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GroupAndQuestionTransferTest` → FAIL.

- [ ] **Step 3: Implement**

`app/Services/Transfer/DefinitionNames.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Tags\TagNames;

/**
 * A question's definition names tag categories, tag values and the user's
 * groups by id. Ids differ between databases, so a definition travels by
 * names and is read back into this database's ids; a name that does not
 * resolve falls out, as a deleted tag value does on the statistics page.
 */
final class DefinitionNames
{
    public static function toNames(array $definition, User $user): array
    {
        $categories = TagCategory::with('values')->get()->keyBy('id');
        $groups = ProjectGroup::where('user_id', $user->id)->pluck('name', 'id');
        $tagsToNames = function (array $tags) use ($categories): array {
            $named = [];

            foreach ($tags as $categoryId => $valueIds) {
                $category = $categories[(int) $categoryId] ?? null;

                if ($category !== null) {
                    $named[$category->name] = $category->values->whereIn('id', array_map('intval', (array) $valueIds))->pluck('name')->values()->all();
                }
            }

            return $named;
        };
        $dimensionToName = function (?string $key) use ($categories): string {
            $dimension = Dimension::fromKey($key);

            if ($dimension === null) {
                return '';
            }

            return $dimension->type->isTag()
                ? $dimension->type->value.':'.($categories[$dimension->categoryId]?->name ?? '')
                : $dimension->key();
        };

        $named = $definition;
        $named['people']['tags'] = $tagsToNames($definition['people']['tags'] ?? []);
        $named['projects']['tags'] = $tagsToNames($definition['projects']['tags'] ?? []);
        $named['projects']['groups'] = collect($definition['projects']['groups'] ?? [])->map(fn ($id) => $groups[(int) $id] ?? null)->filter()->values()->all();
        $named['compare_by'] = $dimensionToName($definition['compare_by'] ?? null);
        $named['split_by'] = $dimensionToName($definition['split_by'] ?? null);
        $named['baseline'] = self::baselineToName($definition, $categories, $groups);

        return $named;
    }

    public static function toIds(array $named, User $user): array
    {
        $categories = TagCategory::with('values')->get();
        $groups = ProjectGroup::where('user_id', $user->id)->get();
        $category = fn (?TagScope $scope, string $name) => $categories->first(fn (TagCategory $candidate) => ($scope === null || $candidate->scope === $scope) && TagNames::same($candidate->name, $name));
        $tagsToIds = function (array $tags, TagScope $scope) use ($category): array {
            $ids = [];

            foreach ($tags as $categoryName => $valueNames) {
                $found = $category($scope, (string) $categoryName);
                $valueIds = $found === null ? [] : $found->values
                    ->filter(fn ($value) => collect((array) $valueNames)->contains(fn ($name) => TagNames::same($value->name, (string) $name)))
                    ->pluck('id')->values()->all();

                if ($valueIds !== []) {
                    $ids[$found->id] = $valueIds;
                }
            }

            return $ids;
        };
        $dimensionToKey = function (?string $key) use ($category): string {
            [$type, $name] = array_pad(explode(':', (string) $key, 2), 2, null);
            $type = DimensionType::tryFrom((string) $type);

            if ($type === null) {
                return '';
            }

            if (! $type->isTag()) {
                return $type->value;
            }

            $found = $category($type === DimensionType::PersonaTag ? TagScope::Persona : TagScope::Project, (string) $name);

            return $found === null ? '' : "{$type->value}:{$found->id}";
        };

        $definition = $named;
        $definition['people']['tags'] = $tagsToIds($named['people']['tags'] ?? [], TagScope::Persona);
        $definition['projects']['tags'] = $tagsToIds($named['projects']['tags'] ?? [], TagScope::Project);
        $definition['projects']['groups'] = $groups->filter(fn (ProjectGroup $group) => in_array($group->name, $named['projects']['groups'] ?? [], true))->pluck('id')->values()->all();
        $definition['compare_by'] = $dimensionToKey($named['compare_by'] ?? null);
        $definition['split_by'] = $dimensionToKey($named['split_by'] ?? null);
        $definition['baseline'] = self::baselineToId($named, $definition['compare_by'], $categories, $groups);

        return $definition;
    }

    private static function baselineToName(array $definition, $categories, $groups): ?string
    {
        $baseline = $definition['baseline'] ?? null;
        $dimension = Dimension::fromKey($definition['compare_by'] ?? null);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        return match (true) {
            $dimension->type->isTag() => $categories[$dimension->categoryId]?->values->firstWhere('id', (int) $baseline)?->name,
            $dimension->type === DimensionType::Group => $groups[(int) $baseline] ?? null,
            default => (string) $baseline,
        };
    }

    private static function baselineToId(array $named, string $compareBy, $categories, $groups): ?string
    {
        $baseline = $named['baseline'] ?? null;
        $dimension = Dimension::fromKey($compareBy);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        $id = match (true) {
            $dimension->type->isTag() => $categories->firstWhere('id', $dimension->categoryId)?->values->first(fn ($value) => TagNames::same($value->name, (string) $baseline))?->id,
            $dimension->type === DimensionType::Group => $groups->firstWhere('name', $baseline)?->id,
            default => $baseline,
        };

        return $id === null ? null : (string) $id;
    }
}
```

`app/Services/Transfer/GroupTransfer.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One user's sidebar groups and which projects they filed there, by project uuid. */
final class GroupTransfer
{
    /** @return list<array{name: string, projects: list<string>}> */
    public function export(User $user): array
    {
        $filed = DB::table('project_contributors')
            ->join('projects', 'projects.id', '=', 'project_contributors.project_id')
            ->where('contributor_type', $user->getMorphClass())
            ->where('contributor_id', $user->id)
            ->whereNotNull('project_group_id')
            ->get(['project_group_id', 'projects.uuid'])
            ->groupBy('project_group_id');

        return ProjectGroup::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProjectGroup $group) => [
                'name' => $group->name,
                'projects' => ($filed[$group->id] ?? collect())->pluck('uuid')->sort()->values()->all(),
            ])
            ->values()->all();
    }

    public function import(array $groups, User $user, ImportReport $report): void
    {
        foreach ($groups as $data) {
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $group = ProjectGroup::where('user_id', $user->id)->where('name', $name)->first();
            $created = $group === null;
            $group ??= ProjectGroup::create([
                'user_id' => $user->id,
                'name' => $name,
                'position' => (int) ProjectGroup::where('user_id', $user->id)->max('position') + 1,
            ]);
            $filed = 0;

            foreach (Project::whereIn('uuid', $data['projects'] ?? [])->get() as $project) {
                if ($project->hasContributor($user)) {
                    $project->fileInGroupFor($user, $group->id);
                    $filed++;
                }
            }

            $report->add(ImportReport::GROUPS, $name, $created ? ImportReport::CREATED : ImportReport::SKIPPED, trans_choice('transfer.notes.projects_filed', $filed, ['count' => $filed]));
        }
    }
}
```

`app/Services/Transfer/QuestionTransfer.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Discussion\Values\QuestionDefinition;
use App\Models\Question;
use App\Models\User;

/**
 * A user's own questions and all templates, by name. On import they become
 * the importing user's questions; templates stay templates only for an admin.
 * A question with the same title, notes and definition is not added twice.
 */
final class QuestionTransfer
{
    public function export(User $user): array
    {
        $own = $user->questions()->notTemplates()->get();
        $templates = Question::templates()->get();

        return $own->concat($templates)
            ->map(fn (Question $question) => [
                'name' => $question->name,
                'notes' => $question->notes,
                'is_template' => (bool) $question->is_template,
                'definition' => DefinitionNames::toNames($question->definition()->toArray(), $user),
            ])
            ->values()->all();
    }

    public function import(array $questions, User $user, ImportReport $report): void
    {
        foreach ($questions as $data) {
            $label = (string) ($data['name'] ?? __('questions.untitled'));
            $isTemplate = ! empty($data['is_template']) && $user->is_admin;
            $definition = QuestionDefinition::fromArray(DefinitionNames::toIds($data['definition'] ?? [], $user))->toArray();

            $duplicate = $user->questions()
                ->where('is_template', $isTemplate)
                ->where('name', $data['name'] ?? null)
                ->get()
                ->contains(fn (Question $existing) => $existing->notes === ($data['notes'] ?? null) && $existing->definition()->toArray() == $definition);

            if ($duplicate) {
                $report->add(ImportReport::QUESTIONS, $label, ImportReport::SKIPPED, __('transfer.notes.already_there'));

                continue;
            }

            $user->questions()->create([
                'name' => $data['name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'definition' => $definition,
                'is_template' => $isTemplate,
            ]);

            $report->add(
                ImportReport::QUESTIONS,
                $label,
                ImportReport::CREATED,
                ! empty($data['is_template']) && ! $isTemplate ? __('transfer.notes.template_as_question') : null,
            );
        }
    }
}
```

`lang/en/transfer.php`, `notes` ergänzen:

```php
        'projects_filed' => '{0}No project filed.|{1}1 project filed.|[2,*]:count projects filed.',
        'template_as_question' => 'Imported as your own question: only admins keep templates.',
```

`lang/de/transfer.php`:

```php
        'projects_filed' => '{0}Kein Projekt einsortiert.|{1}1 Projekt einsortiert.|[2,*]:count Projekte einsortiert.',
        'template_as_question' => 'Als eigene Frage importiert: Vorlagen behalten nur Admins.',
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=GroupAndQuestionTransferTest` → PASS; `php artisan test` grün.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Transfer/DefinitionNames.php app/Services/Transfer/GroupTransfer.php app/Services/Transfer/QuestionTransfer.php lang/en/transfer.php lang/de/transfer.php tests/Feature/Transfer/GroupAndQuestionTransferTest.php
git commit -m "Transfer sidebar groups and questions by name"
```

---

### Task 9: Exporter, Importer mit Vorschau und Konsolenbefehle

**Files:**
- Create: `app/Services/Transfer/BundleExporter.php`, `app/Services/Transfer/BundleImporter.php`
- Create: `app/Console/Commands/ExportBundle.php`, `app/Console/Commands/ImportBundle.php`
- Test: `tests/Feature/Transfer/BundleRoundTripTest.php`

**Interfaces:**
- Consumes: alle Transfer-Klassen (Tasks 5–8)
- Produces:
  - `BundleExporter::project(Project $project, string $path): void`
  - `BundleExporter::expert(Expert $expert, string $path): void`
  - `BundleExporter::everything(User $user, string $path): void` (alle Projekte und Experten; Gruppen und Fragen von `$user`)
  - `BundleExporter::filename(string $kind, string $label): string` → z. B. `communicat-project-school-with-status-60-2026-10-13.zip`
  - `BundleImporter::import(string $path, User $owner): ImportReport`
  - `BundleImporter::preview(string $path, User $owner): ImportReport` (alles in einer Transaktion, die zurückgerollt wird)
  - Befehle `bundle:export {path} {--project=*} {--expert=*} {--user=}`, `bundle:import {path} {--user=} {--dry-run}`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BundleRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function path(): string
    {
        return tempnam(sys_get_temp_dir(), 'bundle').'.zip';
    }

    private function study(User $admin): Project
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $expert = Expert::factory()->create();
        $expert->setTag($focus, $true);
        $project = Project::factory()->create(['user_id' => $admin->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'openai-gpt-5-mini']);
        $project->addContributingUser($admin);
        $project->addContributingExpert($expert);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $expert->id, 'words' => 4]);
        $group = ProjectGroup::create(['user_id' => $admin->id, 'name' => 'Round Robin', 'position' => 1]);
        $project->fileInGroupFor($admin, $group->id);
        $admin->questions()->create(['name' => 'Q', 'definition' => ['people' => ['tags' => [$focus->id => [$true->id]]]]]);

        return $project;
    }

    private function wipe(): void
    {
        foreach (['questions', 'project_groups', 'prompt_logs', 'messages', 'job_logs', 'summaries', 'project_contributors', 'projects', 'taggables', 'experts', 'tag_values', 'tag_categories'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_a_global_bundle_restores_everything_into_an_empty_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertFalse($report->hasFailures());
        $copy = Project::where('uuid', $project->uuid)->sole();
        $this->assertSame(1, JobLog::where('project_id', $copy->id)->count());
        $this->assertSame('Round Robin', ProjectGroup::find($copy->groupIdFor($admin))->name);
        $question = Question::sole();
        $this->assertSame([TagCategory::sole()->id => [TagCategory::sole()->values()->sole()->id]], $question->definition()->people->tags->toArray());
        $this->assertTrue(Expert::sole()->tagValues()->exists());
    }

    public function test_importing_the_same_bundle_twice_adds_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Expert::count());
        $this->assertSame(1, Question::count());
        $this->assertSame(0, $report->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
    }

    public function test_a_project_bundle_brings_its_experts_and_tags_but_no_groups_or_questions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->project($project, $path);
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Expert::count());
        $this->assertSame(1, TagCategory::count());
        $this->assertSame(0, ProjectGroup::count());
        $this->assertSame([ImportReport::TAGS, ImportReport::EXPERTS, ImportReport::PROJECTS], $report->sections());
    }

    public function test_a_preview_reports_like_the_import_and_writes_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();

        $report = app(BundleImporter::class)->preview($path, $admin);

        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Expert::count());
    }

    public function test_the_console_commands_export_and_import(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();

        $this->artisan('bundle:export', ['path' => $path, '--user' => $admin->id])->assertSuccessful();
        $this->wipe();
        $this->artisan('bundle:import', ['path' => $path, '--user' => $admin->id])->assertSuccessful();

        $this->assertSame(1, Project::count());
    }

    public function test_a_broken_file_is_refused_before_anything_is_written(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $path = $this->path();
        file_put_contents($path, 'no zip');

        $this->expectException(InvalidBundle::class);
        app(BundleImporter::class)->import($path, $admin);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BundleRoundTripTest` → FAIL.

- [ ] **Step 3: Implement**

`app/Services/Transfer/BundleExporter.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Writes a bundle: one project, one expert or everything. Dependencies always come along. */
final class BundleExporter
{
    public function __construct(
        private readonly TagTransfer $tags,
        private readonly ExpertTransfer $experts,
        private readonly ProjectTransfer $projects,
        private readonly GroupTransfer $groups,
        private readonly QuestionTransfer $questions,
    ) {}

    public function project(Project $project, string $path): void
    {
        $this->write(Bundle::KIND_PROJECT, collect([$project]), Expert::whereIn('id', $this->projects->expertIds($project))->get(), null, $path);
    }

    public function expert(Expert $expert, string $path): void
    {
        $this->write(Bundle::KIND_EXPERT, collect(), collect([$expert]), null, $path);
    }

    /** Every project and expert; the groups and questions of $user. */
    public function everything(User $user, string $path): void
    {
        $this->write(Bundle::KIND_GLOBAL, Project::orderBy('id')->get(), Expert::orderBy('id')->get(), $user, $path);
    }

    public function filename(string $kind, string $label): string
    {
        return 'communicat-'.$kind.'-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Expert>  $experts
     */
    private function write(string $kind, Collection $projects, Collection $experts, ?User $user, string $path): void
    {
        $writer = new BundleWriter($path);
        $writer->put(Bundle::MANIFEST, [
            'schema_version' => Bundle::SCHEMA_VERSION,
            'kind' => $kind,
            'exported_at' => now()->toIso8601String(),
            'counts' => ['projects' => $projects->count(), 'experts' => $experts->count()],
        ]);
        $writer->put(Bundle::TAGS, $this->tags->export());
        $writer->put(Bundle::EXPERTS, $experts->map(fn (Expert $expert) => $this->experts->export($expert))->values()->all());

        foreach ($projects as $project) {
            $writer->put(Bundle::projectEntry($project->uuid), $this->projects->export($project));
        }

        if ($user !== null) {
            $writer->put(Bundle::GROUPS, $this->groups->export($user));
            $writer->put(Bundle::QUESTIONS, $this->questions->export($user));
        }

        $writer->close();
    }
}
```

`app/Services/Transfer/BundleImporter.php`:

```php
<?php

namespace App\Services\Transfer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads a bundle in a fixed order -- tags, experts, projects, groups, questions --
 * so every part finds what it refers to. The preview runs the same import in a
 * transaction that is rolled back: what it reports is what an import would do.
 */
final class BundleImporter
{
    public function __construct(
        private readonly TagTransfer $tags,
        private readonly ExpertTransfer $experts,
        private readonly ProjectTransfer $projects,
        private readonly GroupTransfer $groups,
        private readonly QuestionTransfer $questions,
    ) {}

    public function import(string $path, User $owner): ImportReport
    {
        return $this->read(BundleReader::open($path), $owner);
    }

    public function preview(string $path, User $owner): ImportReport
    {
        $reader = BundleReader::open($path);
        DB::beginTransaction();

        try {
            return $this->read($reader, $owner);
        } finally {
            DB::rollBack();
        }
    }

    private function read(BundleReader $reader, User $owner): ImportReport
    {
        $report = new ImportReport;
        $this->tags->import($reader->json(Bundle::TAGS), $report);

        foreach ($reader->json(Bundle::EXPERTS) as $expert) {
            $this->experts->import($expert, $report);
        }

        foreach ($reader->projectEntries() as $entry) {
            // A broken project file fails alone; ProjectTransfer already rolls back a failed write.
            try {
                $this->projects->import($reader->json($entry), $owner, $report);
            } catch (Throwable $exception) {
                $report->add(ImportReport::PROJECTS, $entry, ImportReport::FAILED, $exception->getMessage());
            }
        }

        $this->groups->import($reader->json(Bundle::GROUPS), $owner, $report);
        $this->questions->import($reader->json(Bundle::QUESTIONS), $owner, $report);

        return $report;
    }
}
```

`app/Console/Commands/ExportBundle.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
use Illuminate\Console\Command;

class ExportBundle extends Command
{
    protected $signature = 'bundle:export
        {path : Where to write the .zip}
        {--project=* : Export only these projects}
        {--expert=* : Export only these experts}
        {--user= : Whose groups and questions go into a global export; default the first admin}';

    protected $description = 'Export a project, an expert or everything as a bundle.';

    public function handle(BundleExporter $exporter): int
    {
        $path = (string) $this->argument('path');
        $projects = Project::whereIn('id', $this->option('project'))->get();
        $experts = Expert::whereIn('id', $this->option('expert'))->get();

        if ($projects->count() === 1 && $experts->isEmpty()) {
            $exporter->project($projects->sole(), $path);
        } elseif ($experts->count() === 1 && $projects->isEmpty()) {
            $exporter->expert($experts->sole(), $path);
        } elseif ($projects->isEmpty() && $experts->isEmpty() && $this->option('project') === [] && $this->option('expert') === []) {
            $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();
            $exporter->everything($user, $path);
        } else {
            $this->error('Give exactly one --project, exactly one --expert, or neither for everything.');

            return self::FAILURE;
        }

        $this->info("Bundle written to {$path} (".number_format(filesize($path) / 1024 / 1024, 1).' MB).');

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/ImportBundle.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Console\Command;

class ImportBundle extends Command
{
    protected $signature = 'bundle:import
        {path : The .zip to read}
        {--user= : Who becomes owner; default the first admin}
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import a bundle: new projects and experts are added, existing ones skipped.';

    public function handle(BundleImporter $importer): int
    {
        $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();

        try {
            $report = $this->option('dry-run')
                ? $importer->preview((string) $this->argument('path'), $user)
                : $importer->import((string) $this->argument('path'), $user);
        } catch (InvalidBundle $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Section', 'Name', 'Outcome', 'Note'], collect($report->sections())
            ->flatMap(fn (string $section) => collect($report->entries($section))->map(fn (array $entry) => [$section, $entry['name'], $entry['outcome'], $entry['note'] ?? '']))
            ->all());

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=BundleRoundTripTest` → PASS; `php artisan test` grün.

- [ ] **Step 5: Real-data smoke test**

```bash
php artisan bundle:export /tmp/claude-1000/communicat-all.zip
php artisan bundle:import /tmp/claude-1000/communicat-all.zip --dry-run
```

Expected: Export ohne Fehler, Größe notieren; Vorschau meldet alle Projekte/Experten als „skipped“ (schon da), keine `failed`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Transfer/BundleExporter.php app/Services/Transfer/BundleImporter.php app/Console/Commands/ExportBundle.php app/Console/Commands/ImportBundle.php tests/Feature/Transfer/BundleRoundTripTest.php
git commit -m "Export and import bundles, with a preview and console commands"
```

---

### Task 10: Downloads und Menüeinträge, alten Export entfernen

**Files:**
- Create: `app/Http/Controllers/BundleExportController.php`
- Modify: `routes/web.php` (neue Routen, alte `project.export.json` raus)
- Modify: `resources/views/livewire/projects/edit-project.blade.php` (Menüeintrag)
- Modify: `resources/views/livewire/experts/expert-editor.blade.php` (Menüeintrag)
- Modify: `app/Livewire/Projects/CreateProject.php`, `resources/views/livewire/projects/create-project.blade.php` (Upload raus)
- Modify: `app/Livewire/Projects/ProjectChat.php`, `resources/views/livewire/projects/project-chat.blade.php` (`importNotices` raus)
- Modify: `lang/{en,de}/projects.php` (Schlüssel `import.*`, `edit.export_json` raus), `lang/{en,de}/experts.php` (`editor.export`), `lang/{en,de}/transfer.php`
- Delete: `app/Http/Controllers/ProjectExportController.php`, `app/Services/ProjectTransfer/ProjectExport.php`, `app/Services/ProjectTransfer/ProjectImporter.php`, `tests/Feature/ProjectExportTest.php`, `tests/Feature/ProjectTransferTest.php`
- Test: `tests/Feature/Transfer/BundleDownloadTest.php`

**Interfaces:**
- Consumes: `BundleExporter` (Task 9)
- Produces: Routen `project.export` (`GET projects/{project}/export`), `experts.export` (`GET experts/{expert}/export`), `settings.export` (`GET settings/export`, Middleware `admin`)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundleDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_downloads_for_whoever_may_access_it(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'School']);

        $this->actingAs($owner)->get(route('project.export', $project))
            ->assertOk()
            ->assertDownload('communicat-project-school-'.now()->format('Y-m-d').'.zip');
        $this->actingAs(User::factory()->create())->get(route('project.export', $project))->assertForbidden();
    }

    public function test_an_expert_downloads_for_every_signed_in_user(): void
    {
        $expert = Expert::factory()->create(['name' => 'Alex Brandt']);

        $this->actingAs(User::factory()->create())->get(route('experts.export', $expert))->assertOk()->assertDownload();
    }

    public function test_everything_downloads_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.export'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.export'))->assertOk()->assertDownload();
    }

    public function test_the_old_export_and_upload_are_gone(): void
    {
        $this->assertFalse(app('router')->has('project.export.json'));
        $this->assertFalse(class_exists(\App\Services\ProjectTransfer\ProjectImporter::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BundleDownloadTest` → FAIL (Routen fehlen).

- [ ] **Step 3: Implement controller and routes**

`app/Http/Controllers/BundleExportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BundleExportController extends Controller
{
    public function __construct(private readonly BundleExporter $exporter) {}

    public function project(Project $project): BinaryFileResponse
    {
        abort_unless(Gate::allows('access-project', $project), 403);

        return $this->download(fn (string $path) => $this->exporter->project($project, $path), $this->exporter->filename(Bundle::KIND_PROJECT, $project->title));
    }

    public function expert(Expert $expert): BinaryFileResponse
    {
        return $this->download(fn (string $path) => $this->exporter->expert($expert, $path), $this->exporter->filename(Bundle::KIND_EXPERT, $expert->name));
    }

    public function everything(): BinaryFileResponse
    {
        Gate::authorize('admin');

        return $this->download(fn (string $path) => $this->exporter->everything(auth()->user(), $path), $this->exporter->filename(Bundle::KIND_GLOBAL, 'all'));
    }

    private function download(callable $write, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        $write($path);

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
```

In `routes/web.php`: `use App\Http\Controllers\BundleExportController;` statt `ProjectExportController`; im Projekt-Block die Route `{project}/export/json` ersetzen durch:

```php
        Route::get('{project}/export', [BundleExportController::class, 'project'])->name('export');
```

Nach der `experts`-Route:

```php
Route::get('experts/{expert}/export', [BundleExportController::class, 'expert'])
    ->middleware(['auth', 'verified'])->name('experts.export');
```

Im `auth`-Block bei den Einstellungen:

```php
    Route::get('settings/export', [BundleExportController::class, 'everything'])->middleware('admin')->name('settings.export');
```

Prüfen: der Projekt-Block hat den Namenspräfix `project.` – die Route heißt damit `project.export`. Falls der Präfix anders lautet (`php artisan route:list --name=project`), den Testnamen anpassen.

- [ ] **Step 4: Menu entries**

In `resources/views/livewire/projects/edit-project.blade.php` den Menüeintrag ersetzen:

```blade
                        <flux:menu.item icon="arrow-down-tray" href="{{ route('project.export', $forProjectId) }}">
                            {{ __('transfer.actions.export') }}
                        </flux:menu.item>
```

In `resources/views/livewire/experts/expert-editor.blade.php` im `<flux:menu>` vor dem Löschen-Eintrag:

```blade
                            <flux:menu.item icon="arrow-down-tray" href="{{ route('experts.export', $expertId) }}">
                                {{ __('transfer.actions.export') }}
                            </flux:menu.item>
                            <flux:menu.separator/>
```

`lang/en/transfer.php` neues Array:

```php
    'actions' => [
        'export' => 'Export',
    ],
```

`lang/de/transfer.php`:

```php
    'actions' => [
        'export' => 'Exportieren',
    ],
```

- [ ] **Step 5: Remove the old export and upload**

```bash
git rm app/Http/Controllers/ProjectExportController.php app/Services/ProjectTransfer/ProjectExport.php app/Services/ProjectTransfer/ProjectImporter.php tests/Feature/ProjectExportTest.php tests/Feature/ProjectTransferTest.php
```

In `app/Livewire/Projects/CreateProject.php`: `use App\Services\ProjectTransfer\ProjectImporter;`, `use WithFileUploads` samt Import, die Eigenschaft `$importFile` und die Methode `createFromFile()` entfernen.
In `resources/views/livewire/projects/create-project.blade.php`: die ganze zweite `<flux:card>` mit der Überschrift `projects.import.heading` entfernen.
In `app/Livewire/Projects/ProjectChat.php`: Eigenschaft `$importNotices` samt Docblock und die Zeile in `mount()` entfernen.
In `resources/views/livewire/projects/project-chat.blade.php`: den Block `@if ($importNotices !== []) … @endif` entfernen.
In `lang/{en,de}/projects.php`: das Array `import` und den Schlüssel `edit.export_json` entfernen; in `lang/{en,de}/validation.php` Einträge zu `importFile` entfernen.

Run: `grep -rn "importFile\|importNotices\|createFromFile\|ProjectTransfer\\\\\|export_json\|projects.import" app resources lang tests routes` → keine Treffer.

- [ ] **Step 6: Run tests and look at it**

Run: `php artisan test` → grün. Im Browser: „Projekt bearbeiten“ → „…“ → „Exportieren“ lädt eine ZIP; Experte bearbeiten → „…“ → „Exportieren“ lädt eine ZIP; „Neues Projekt“ zeigt keinen Upload mehr.

- [ ] **Step 7: Commit**

```bash
git add -A app/Http/Controllers routes/web.php resources/views/livewire/projects resources/views/livewire/experts app/Livewire/Projects lang tests/Feature/Transfer/BundleDownloadTest.php
git commit -m "Download bundles for a project, an expert or everything; drop the old JSON export"
```

---

### Task 11: Einstellungen → Import/Export mit Vorschau und Bericht

**Files:**
- Create: `app/Livewire/Settings/Transfer.php`, `resources/views/livewire/settings/transfer.blade.php`, `resources/views/components/transfer/report.blade.php`
- Modify: `routes/web.php`, `resources/views/components/settings/layout.blade.php`, `lang/{en,de}/settings.php`, `lang/{en,de}/transfer.php`
- Test: `tests/Feature/Transfer/TransferSettingsTest.php`

**Interfaces:**
- Consumes: `BundleImporter::preview()/import()`, `ImportReport::toArray()/fromArray()`, Route `settings.export`
- Produces: Route `settings.transfer` (`GET settings/transfer`, Middleware `admin`); Livewire `Settings\Transfer` mit `$bundle` (Upload), `$stage` (`'upload'|'preview'|'done'`), `$report` (array), Methoden `import()`, `startOver()`

**Ablauf der Oberfläche:**
1. **Upload:** Karte „Exportieren“ mit Knopf „Alles exportieren“ (Link auf `settings.export`, Hinweis, dass Prompt-Texte älter als 24 h nicht enthalten sind). Karte „Importieren“ mit Dropzone für `.zip`.
2. **Vorschau:** Sobald die Datei hochgeladen ist, läuft automatisch `preview()`. Darüber steht „Das würde importiert“, darunter der Bericht (Schritt 4), unten zwei Knöpfe: „Abbrechen“ und „Importieren“.
3. **Fertig:** Nach „Importieren“ derselbe Bericht mit Überschrift „Importiert“, ein Callout (grün ohne Fehler, gelb mit Fehlern) und der Knopf „Weitere Datei importieren“.
4. **Bericht (`x-transfer.report`):** pro Bereich (Tags, Experten, Projekte, Gruppen, Fragen) eine `flux:card` mit Überschrift und drei Zahlen als `flux:badge` (angelegt grün, übersprungen zinc, fehlgeschlagen rot, nur wenn > 0), darunter `flux:accordion` mit einer Zeile pro Eintrag: Name, Ergebnis-Badge, Hinweis in kleinem grauen Text. Fehlgeschlagene Einträge sind im Akkordeon zuerst und das Akkordeon ist dann standardmäßig geöffnet.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Livewire\Settings\Transfer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class TransferSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function bundle(User $admin): UploadedFile
    {
        $project = Project::factory()->create(['user_id' => $admin->id, 'title' => 'School']);
        $project->addContributingExpert(Expert::factory()->create(['name' => 'Alex']));
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        app(BundleExporter::class)->project($project, $path);
        Project::query()->delete();
        Expert::query()->delete();

        return new UploadedFile($path, 'school.zip', 'application/zip', null, true);
    }

    public function test_only_admins_reach_the_page(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create())->get(route('settings.transfer'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.transfer'))->assertOk()->assertSee('Export everything');
    }

    public function test_an_upload_previews_then_imports_and_reports(): void
    {
        app()->setLocale('en');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = Livewire::test(Transfer::class)
            ->set('bundle', $this->bundle($admin))
            ->assertSet('stage', 'preview')
            ->assertSee('What would be imported')
            ->assertSee('School')
            ->assertSee('Alex');
        $this->assertSame(0, Project::count());

        $component->call('import')
            ->assertSet('stage', 'done')
            ->assertSee('Imported');
        $this->assertSame(1, Project::count());

        $component->call('startOver')->assertSet('stage', 'upload')->assertSet('report', []);
    }

    public function test_a_broken_file_shows_its_error_and_stays_on_upload(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        file_put_contents($path, 'nope');

        Livewire::test(Transfer::class)
            ->set('bundle', new UploadedFile($path, 'x.zip', 'application/zip', null, true))
            ->assertSet('stage', 'upload')
            ->assertHasErrors('bundle');
    }

    public function test_a_non_admin_cannot_import_even_by_calling_the_action(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Transfer::class)->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransferSettingsTest` → FAIL.

- [ ] **Step 3: Implement the component**

`app/Livewire/Settings/Transfer.php`:

```php
<?php

namespace App\Livewire\Settings;

use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Settings → Import/Export: export everything, or upload a bundle, see what
 * it would do, then import it and read what it did. Admins only, checked in
 * boot() so every request is covered, not only the first.
 */
class Transfer extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $bundle = null;

    #[Locked]
    public string $stage = 'upload';

    /** ImportReport::toArray() of the preview or the import. */
    #[Locked]
    public array $report = [];

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function updatedBundle(): void
    {
        $this->validate(['bundle' => 'required|file|max:12288']);

        try {
            $this->report = app(BundleImporter::class)->preview($this->bundle->getRealPath(), auth()->user())->toArray();
            $this->stage = 'preview';
        } catch (InvalidBundle $exception) {
            $this->addError('bundle', $exception->getMessage());
        }
    }

    public function import(): void
    {
        if ($this->stage !== 'preview' || $this->bundle === null) {
            return;
        }

        $this->report = app(BundleImporter::class)->import($this->bundle->getRealPath(), auth()->user())->toArray();
        $this->stage = 'done';
        $this->bundle = null;
    }

    public function startOver(): void
    {
        $this->reset(['bundle', 'stage', 'report']);
    }

    public function render(): mixed
    {
        return view('livewire.settings.transfer', ['importReport' => ImportReport::fromArray($this->report)]);
    }
}
```

- [ ] **Step 4: Implement the views**

`resources/views/livewire/settings/transfer.blade.php`:

```blade
<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('transfer.heading')" :subheading="__('transfer.subheading')">
        <div class="space-y-6">
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('transfer.export.heading') }}</flux:heading>
                <flux:text size="sm">{{ __('transfer.export.description') }}</flux:text>
                <flux:button icon="arrow-down-tray" :href="route('settings.export')">{{ __('transfer.export.everything') }}</flux:button>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('transfer.import.heading') }}</flux:heading>

                @if ($stage === 'upload')
                    <flux:text size="sm">{{ __('transfer.import.description') }}</flux:text>
                    <flux:file-upload wire:model="bundle" accept=".zip,application/zip">
                        <flux:file-upload.dropzone inline :heading="__('transfer.import.dropzone_heading')" :text="__('transfer.import.dropzone_text')" />
                    </flux:file-upload>
                    <flux:text size="sm" wire:loading wire:target="bundle">{{ __('transfer.import.checking') }}</flux:text>
                    <flux:error name="bundle" />
                @else
                    @if ($stage === 'done')
                        <flux:callout :variant="$importReport->hasFailures() ? 'warning' : 'success'" :icon="$importReport->hasFailures() ? 'exclamation-triangle' : 'check-circle'"
                            :heading="$importReport->hasFailures() ? __('transfer.import.done_with_failures') : __('transfer.import.done')" />
                    @endif

                    <flux:heading>{{ $stage === 'preview' ? __('transfer.import.preview_heading') : __('transfer.import.done_heading') }}</flux:heading>
                    <x-transfer.report :report="$importReport" />

                    <div class="flex justify-end gap-2">
                        @if ($stage === 'preview')
                            <flux:button variant="filled" wire:click="startOver">{{ __('common.actions.cancel') }}</flux:button>
                            <flux:button variant="primary" icon="arrow-up-tray" wire:click="import" wire:loading.attr="disabled" wire:target="import">{{ __('transfer.import.submit') }}</flux:button>
                        @else
                            <flux:button wire:click="startOver">{{ __('transfer.import.again') }}</flux:button>
                        @endif
                    </div>
                @endif
            </flux:card>
        </div>
    </x-settings.layout>
</section>
```

Prüfen, wie `tags.blade.php` die Einstellungsseite einrahmt (`@include('partials.settings-heading')` und `x-settings.layout`), und genau dasselbe Gerüst verwenden.

`resources/views/components/transfer/report.blade.php`:

```blade
@use('App\Services\Transfer\ImportReport')

@props([
    'report', {{-- ImportReport --}}
])

{{-- What an import would do or did: a card per section with its counts, the entries in an accordion, failures first and open. --}}
<div class="space-y-3">
    @forelse ($report->sections() as $section)
        @php($entries = collect($report->entries($section))->sortBy(fn (array $entry) => $entry['outcome'] === ImportReport::FAILED ? 0 : 1)->values())
        @php($failed = $report->count($section, ImportReport::FAILED))

        <flux:card size="sm" wire:key="report-{{ $section }}">
            <flux:accordion>
                <flux:accordion.item :expanded="$failed > 0">
                    <flux:accordion.heading>
                        <div class="flex flex-wrap items-center gap-2">
                            <span>{{ __('transfer.sections.'.$section) }}</span>
                            @foreach ([ImportReport::CREATED => 'green', ImportReport::SKIPPED => 'zinc', ImportReport::FAILED => 'red'] as $outcome => $color)
                                @if ($report->count($section, $outcome) > 0)
                                    <flux:badge size="sm" :color="$color">{{ trans_choice('transfer.outcomes.'.$outcome, $report->count($section, $outcome), ['count' => $report->count($section, $outcome)]) }}</flux:badge>
                                @endif
                            @endforeach
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <ul class="divide-y divide-zinc-100 dark:divide-white/10">
                            @foreach ($entries as $entry)
                                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-1.5 text-sm">
                                    <span class="font-medium text-zinc-800 dark:text-white">{{ $entry['name'] }}</span>
                                    <flux:badge size="sm" :color="['created' => 'green', 'skipped' => 'zinc', 'failed' => 'red'][$entry['outcome']]">{{ __('transfer.outcome.'.$entry['outcome']) }}</flux:badge>
                                    @if ($entry['note'])
                                        <span class="w-full text-xs text-zinc-500 dark:text-zinc-400">{{ $entry['note'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>
    @empty
        <flux:text size="sm">{{ __('transfer.import.empty') }}</flux:text>
    @endforelse
</div>
```

- [ ] **Step 5: Route, navigation, texts**

`routes/web.php` im `auth`-Block: `use App\Livewire\Settings\Transfer;` und

```php
    Route::get('settings/transfer', Transfer::class)->middleware('admin')->name('settings.transfer');
```

`resources/views/components/settings/layout.blade.php`: in beiden `@if(auth()->user()?->is_admin)`-Blöcken nach dem Tags-Eintrag je einen Eintrag ergänzen, mobil:

```blade
                <flux:navbar.item :href="route('settings.transfer')" :current="$isCurrent('settings.transfer')" wire:navigate>{{ __('settings.nav.transfer') }}</flux:navbar.item>
```

und Desktop:

```blade
                <flux:navlist.item :href="route('settings.transfer')" :current="$isCurrent('settings.transfer')" wire:navigate>{{ __('settings.nav.transfer') }}</flux:navlist.item>
```

`lang/en/settings.php`, `nav`: `'transfer' => 'Import/Export',`; `lang/de/settings.php`: `'transfer' => 'Import/Export',`.

`lang/en/transfer.php` ergänzen:

```php
    'heading' => 'Import/Export',
    'subheading' => 'Save the study as an archive, or bring projects and experts in from one.',
    'export' => [
        'heading' => 'Export',
        'description' => 'Every project with its turns and logged calls, every expert, the tags, your sidebar groups and your questions, as one .zip. Prompt texts older than 24 hours are no longer stored and so are not included.',
        'everything' => 'Export everything',
    ],
    'import' => [
        'heading' => 'Import',
        'description' => 'A .zip exported from this app: one project, one expert or everything. You see what it would do before anything is written. Projects and experts that are already here are skipped.',
        'dropzone_heading' => 'Drop a bundle here or click to choose',
        'dropzone_text' => 'A .zip up to 12 MB; for larger ones use php artisan bundle:import',
        'checking' => 'Checking the bundle …',
        'preview_heading' => 'What would be imported',
        'done_heading' => 'Imported',
        'done' => 'Import finished.',
        'done_with_failures' => 'Import finished, but some entries failed.',
        'submit' => 'Import',
        'again' => 'Import another file',
        'empty' => 'The bundle is empty.',
    ],
    'sections' => [
        'tags' => 'Tags',
        'experts' => 'Experts',
        'projects' => 'Projects',
        'groups' => 'Groups',
        'questions' => 'Questions',
    ],
    'outcomes' => [
        'created' => '{1}1 new|[2,*]:count new',
        'skipped' => '{1}1 skipped|[2,*]:count skipped',
        'failed' => '{1}1 failed|[2,*]:count failed',
    ],
    'outcome' => [
        'created' => 'new',
        'skipped' => 'skipped',
        'failed' => 'failed',
    ],
```

`lang/de/transfer.php`:

```php
    'heading' => 'Import/Export',
    'subheading' => 'Sichere die Studie als Archiv oder hole Projekte und Experten aus einem herein.',
    'export' => [
        'heading' => 'Exportieren',
        'description' => 'Alle Projekte mit Turns und protokollierten Aufrufen, alle Experten, die Tags, deine Sidebar-Gruppen und deine Fragen als eine .zip. Prompt-Texte älter als 24 Stunden sind nicht mehr gespeichert und deshalb nicht enthalten.',
        'everything' => 'Alles exportieren',
    ],
    'import' => [
        'heading' => 'Importieren',
        'description' => 'Eine aus dieser App exportierte .zip: ein Projekt, ein Experte oder alles. Du siehst, was passieren würde, bevor etwas geschrieben wird. Projekte und Experten, die schon da sind, werden übersprungen.',
        'dropzone_heading' => 'Bundle hierher ziehen oder klicken',
        'dropzone_text' => 'Eine .zip bis 12 MB; größere mit php artisan bundle:import',
        'checking' => 'Bundle wird geprüft …',
        'preview_heading' => 'Das würde importiert',
        'done_heading' => 'Importiert',
        'done' => 'Import abgeschlossen.',
        'done_with_failures' => 'Import abgeschlossen, aber einige Einträge sind fehlgeschlagen.',
        'submit' => 'Importieren',
        'again' => 'Weitere Datei importieren',
        'empty' => 'Das Bundle ist leer.',
    ],
    'sections' => [
        'tags' => 'Tags',
        'experts' => 'Experten',
        'projects' => 'Projekte',
        'groups' => 'Gruppen',
        'questions' => 'Fragen',
    ],
    'outcomes' => [
        'created' => '{1}1 neu|[2,*]:count neu',
        'skipped' => '{1}1 übersprungen|[2,*]:count übersprungen',
        'failed' => '{1}1 fehlgeschlagen|[2,*]:count fehlgeschlagen',
    ],
    'outcome' => [
        'created' => 'neu',
        'skipped' => 'übersprungen',
        'failed' => 'fehlgeschlagen',
    ],
```

- [ ] **Step 6: Run tests and look at it**

Run: `php artisan test --filter=TransferSettingsTest` → PASS; `php artisan test` grün.
Im Browser `/settings/transfer`: „Alles exportieren“ lädt die ZIP; dieselbe ZIP hochladen → Vorschau mit allen Projekten als „übersprungen“; bei 390 px Breite und im Dark Mode ansehen.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Settings/Transfer.php resources/views/livewire/settings/transfer.blade.php resources/views/components/transfer/report.blade.php routes/web.php resources/views/components/settings/layout.blade.php lang tests/Feature/Transfer/TransferSettingsTest.php
git commit -m "Add Settings Import/Export with a preview and a per-section report"
```

---

### Task 12: Statistik-Export als drei CSV-Dateien

**Files:**
- Create: `app/Services/StudyExport/StudyExport.php`, `app/Console/Commands/ExportStudyTurns.php`, `app/Http/Controllers/StudyExportController.php`
- Modify: `routes/web.php`, `resources/views/livewire/statistics/question-editor.blade.php` (Menü auch für Vorlagen und Nicht-Besitzer), `lang/{en,de}/questions.php`
- Test: `tests/Feature/StudyExport/StudyExportTest.php`

**Interfaces:**
- Consumes: `QuestionRuns::for(User, ProjectFilter): Collection<Project>`, `SpeakingShares::forProject(Project): ShareReport` (`speakers` als `list<SpeakerShare>` mit `expertId`, `seat`, `turns`, `turnShare`, `words`, `wordShare`, `wordsPerTurn()`; `spokenTurns`, `failedTurns`), `Project::groupIdFor(User)`, `Project::$uuid`, `Expert::$uuid`
- Produces:
  - `StudyExport::write(Collection $runs, User $viewer, string $path): void` – ZIP mit `runs.csv`, `participants.csv`, `turns.csv`
  - `StudyExport::filename(string $label): string` → `communicat-study-{slug}-{Y-m-d}.zip`
  - Befehl `study:export-turns {path} {--project=*} {--group=*} {--pipeline=*} {--user=}`
  - Route `statistics.export` (`GET statistics/{question}/export`)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\StudyExport;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\PromptLog;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\StudyExport\StudyExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class StudyExportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, list<array<string, string>>> file => rows keyed by header */
    private function read(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];

        foreach (['runs.csv', 'participants.csv', 'turns.csv'] as $file) {
            $lines = array_map('str_getcsv', explode("\n", trim($zip->getFromName($file))));
            $header = array_shift($lines);
            $files[$file] = array_map(fn (array $line) => array_combine($header, $line), $lines);
        }

        return $files;
    }

    public function test_three_tables_with_a_column_per_tag_category_and_honest_gaps(): void
    {
        $viewer = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $holder = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $true = $holder->values()->create(['name' => 'true']);
        [$alex, $kim] = Expert::factory()->count(2)->create()->all();
        $alex->setTag($holder, $true);
        $run = Project::factory()->create(['user_id' => $viewer->id, 'pipeline' => 'ScorePipeline', 'title' => 'School']);
        $run->addContributingUser($viewer);
        $run->addContributingExpert($alex);
        $run->addContributingExpert($kim);
        $run->setTag($condition, $status);
        $group = ProjectGroup::create(['user_id' => $viewer->id, 'name' => 'Score', 'position' => 1]);
        $run->fileInGroupFor($viewer, $group->id);
        $spoken = JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $alex->id, 'seat' => 1, 'words' => 10, 'chars' => 50,
            'selection' => ['selector' => 'HighestBidSelector', 'speaker_id' => $alex->id, 'signals' => ['bids' => ["E{$alex->id}" => 5, "E{$kim->id}" => 2], 'fallbacks' => []], 'tie_broken' => false]]);
        PromptLog::create(['job_log_id' => $spoken->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 100, 'tokens_out' => 20, 'tokens_reasoning' => null]);
        JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'failed', 'started_at' => now(), 'turn_index' => 2, 'error' => 'HTTP 500']);
        $path = tempnam(sys_get_temp_dir(), 'study');

        app(StudyExport::class)->write(collect([$run]), $viewer, $path);
        $files = $this->read($path);

        $this->assertSame('status', $files['runs.csv'][0]['status condition']);
        $this->assertSame('Score', $files['runs.csv'][0]['group']);
        $this->assertSame('1', $files['runs.csv'][0]['turns_spoken']);
        $this->assertSame('1', $files['runs.csv'][0]['turns_failed_without_speaking']);

        $participants = collect($files['participants.csv'])->keyBy('expert_uuid');
        $this->assertSame('true', $participants[$alex->uuid]['title-holder']);
        $this->assertSame('', $participants[$kim->uuid]['title-holder']);
        $this->assertSame('0', $participants[$kim->uuid]['turns']);
        $this->assertSame('1', $participants[$alex->uuid]['turn_share']);

        [$first, $failed] = $files['turns.csv'];
        $this->assertSame('1', $first['spoken']);
        $this->assertSame('5', $first['speaker_bid']);
        $this->assertSame('100', $first['tokens_in']);
        $this->assertSame('', $first['tokens_reasoning']);
        $this->assertSame('0', $failed['spoken']);
        $this->assertSame('', $failed['words']);
        $this->assertSame('HTTP 500', $failed['error']);
    }

    public function test_the_question_route_exports_its_runs_and_the_command_writes_a_file(): void
    {
        $viewer = User::factory()->create();
        $run = Project::factory()->create(['user_id' => $viewer->id, 'title' => 'School']);
        $run->addContributingUser($viewer);
        $question = $viewer->questions()->create(['name' => 'Status effect', 'definition' => []]);

        $this->actingAs($viewer)->get(route('statistics.export', $question))->assertOk()->assertDownload();
        $this->actingAs(User::factory()->create())->get(route('statistics.export', $question))->assertNotFound();

        $path = tempnam(sys_get_temp_dir(), 'study');
        $this->artisan('study:export-turns', ['path' => $path, '--user' => $viewer->id])->assertSuccessful();
        $this->assertCount(1, $this->read($path)['runs.csv']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StudyExportTest` → FAIL.

- [ ] **Step 3: Implement the export**

`app/Services/StudyExport/StudyExport.php`:

```php
<?php

namespace App\Services\StudyExport;

use App\Discussion\Metrics\SpeakingShares;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The raw data for an outside analysis: runs, participants and turns as three
 * CSV files joined by uuid. Tags become one column per category. A turn
 * counts as spoken when words is set (SpokenTurns' rule); failed turns stay in
 * so the failure rate can be read; NULL stays empty and never becomes 0.
 */
final class StudyExport
{
    public function __construct(private readonly SpeakingShares $shares) {}

    /** @param  Collection<int, Project>  $runs */
    public function write(Collection $runs, User $viewer, string $path): void
    {
        $projectCategories = TagCategory::ofScope(TagScope::Project)->orderBy('position')->orderBy('id')->get();
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->orderBy('position')->orderBy('id')->get();
        $expertUuids = Expert::pluck('uuid', 'id');
        $groups = ProjectGroup::where('user_id', $viewer->id)->pluck('name', 'id');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('runs.csv', $this->csv($this->runRows($runs, $viewer, $groups, $projectCategories)));
        $zip->addFromString('participants.csv', $this->csv($this->participantRows($runs, $personaCategories)));
        $zip->addFromString('turns.csv', $this->csv($this->turnRows($runs, $expertUuids)));
        $zip->close();
    }

    public function filename(string $label): string
    {
        return 'communicat-study-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /** @return list<array<string, scalar|null>> */
    private function runRows(Collection $runs, User $viewer, Collection $groups, Collection $categories): array
    {
        return $runs->map(function (Project $run) use ($viewer, $groups, $categories) {
            $report = $this->shares->forProject($run);
            $tags = $run->tagValues()->get()->keyBy('tag_category_id');
            $groupId = $run->groupIdFor($viewer);
            $row = [
                'run_uuid' => $run->uuid,
                'title' => $run->title,
                'description' => $run->description,
                'pipeline' => $run->pipeline,
                'model' => $run->model,
                'seed' => $run->seed,
                'turn_budget' => $run->turn_budget,
                'group' => $groupId === null ? null : ($groups[$groupId] ?? null),
            ];

            foreach ($categories as $category) {
                $row[$category->name] = $tags[$category->id]->name ?? null;
            }

            return $row + ['turns_spoken' => $report->spokenTurns, 'turns_failed_without_speaking' => $report->failedTurns];
        })->values()->all();
    }

    /** @return list<array<string, scalar|null>> */
    private function participantRows(Collection $runs, Collection $categories): array
    {
        return $runs->flatMap(function (Project $run) use ($categories) {
            $speakers = collect($this->shares->forProject($run)->speakers)->keyBy('expertId');

            return $run->experts()->get()->map(function (Expert $expert) use ($run, $speakers, $categories) {
                $share = $speakers[$expert->id] ?? null;
                $tags = $expert->tagValues()->get()->keyBy('tag_category_id');
                $row = [
                    'run_uuid' => $run->uuid,
                    'expert_uuid' => $expert->uuid,
                    'name' => $expert->name,
                    'role' => $expert->role,
                    'seat' => $expert->pivot->seat,
                ];

                foreach ($categories as $category) {
                    $row[$category->name] = $tags[$category->id]->name ?? null;
                }

                return $row + [
                    'turns' => $share?->turns ?? 0,
                    'turn_share' => $share?->turnShare ?? 0,
                    'words' => $share?->words ?? 0,
                    'word_share' => $share?->wordShare ?? 0,
                    'words_per_turn' => $share === null ? null : $share->wordsPerTurn(),
                ];
            });
        })->values()->all();
    }

    /** @return list<array<string, scalar|null>> */
    private function turnRows(Collection $runs, Collection $expertUuids): array
    {
        $uuid = fn ($id) => $id === null ? null : ($expertUuids[(int) $id] ?? null);
        $flag = fn ($value) => $value === null ? null : ($value ? 1 : 0);

        return $runs->flatMap(function (Project $run) use ($uuid, $flag) {
            $jobs = JobLog::where('project_id', $run->id)->orderBy('turn_index')->orderBy('id')->get();
            $tokens = DB::table('prompt_logs')->whereIn('job_log_id', $jobs->pluck('id'))->groupBy('job_log_id')
                ->selectRaw('job_log_id, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(tokens_reasoning) as tokens_reasoning')
                ->get()->keyBy('job_log_id');
            $addressees = Message::whereIn('job_log_id', $jobs->pluck('id'))->whereNotNull('expert_id')->pluck('addressee_expert_id', 'job_log_id');

            return $jobs->map(function (JobLog $job) use ($run, $tokens, $addressees, $uuid, $flag) {
                $selection = $job->selection ?? [];
                $signals = $selection['signals'] ?? [];
                $speaker = $selection['speaker_id'] ?? $job->expert_id;
                $fallback = array_key_exists('fallback', $signals) ? $signals['fallback'] : (array_key_exists('fallbacks', $signals) ? $signals['fallbacks'] !== [] : null);

                return [
                    'run_uuid' => $run->uuid,
                    'turn_index' => $job->turn_index,
                    'status' => $job->status,
                    'spoken' => $job->words === null ? 0 : 1,
                    'expert_uuid' => $uuid($job->expert_id),
                    'seat' => $job->seat,
                    'addressee_expert_uuid' => $uuid($addressees[$job->id] ?? null),
                    'words' => $job->words,
                    'chars' => $job->chars,
                    'thought_words' => $job->thought_words,
                    'thought_chars' => $job->thought_chars,
                    'tokens_in' => $tokens[$job->id]->tokens_in ?? null,
                    'tokens_out' => $tokens[$job->id]->tokens_out ?? null,
                    'tokens_reasoning' => $tokens[$job->id]->tokens_reasoning ?? null,
                    'selector' => $selection['selector'] ?? null,
                    'fallback' => $flag($fallback),
                    'tie_broken' => $flag($selection['tie_broken'] ?? null),
                    'speaker_bid' => $speaker === null ? null : ($signals['bids']["E{$speaker}"] ?? null),
                    'judge_label' => $signals['label'] ?? null,
                    'selector_reasoning' => $selection['reasoning'] ?? null,
                    'selection_json' => $selection === [] ? null : json_encode($selection, JSON_UNESCAPED_UNICODE),
                    'error' => $job->error,
                ];
            });
        })->values()->all();
    }

    /** @param  list<array<string, scalar|null>>  $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => $value === null ? '' : (is_float($value) ? round($value, 6) : $value), $row), escape: '');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
```

- [ ] **Step 4: Command, route and menu**

`app/Console/Commands/ExportStudyTurns.php`:

```php
<?php

namespace App\Console\Commands;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Discussion\Values\ProjectFilter;
use App\Models\Project;
use App\Models\User;
use App\Services\StudyExport\StudyExport;
use Illuminate\Console\Command;

class ExportStudyTurns extends Command
{
    protected $signature = 'study:export-turns
        {path : Where to write the .zip with runs.csv, participants.csv and turns.csv}
        {--project=* : Only these projects}
        {--group=* : Only runs in these sidebar groups of --user}
        {--pipeline=* : Only runs of these pipelines}
        {--user= : Whose access and groups count; default the first admin}';

    protected $description = 'Export the raw per-turn data of the runs as three CSV files.';

    public function handle(QuestionRuns $runs, StudyExport $export): int
    {
        $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();
        $filter = ProjectFilter::fromArray(['groups' => $this->option('group'), 'pipelines' => $this->option('pipeline')]);
        $selected = $runs->for($user, $filter)
            ->when($this->option('project') !== [], fn ($all) => $all->whereIn('id', array_map('intval', $this->option('project'))))
            ->values();

        $export->write($selected, $user, (string) $this->argument('path'));
        $this->info("{$selected->count()} runs written to {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
```

`app/Http/Controllers/StudyExportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Models\Question;
use App\Services\StudyExport\StudyExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudyExportController extends Controller
{
    /** The raw data of exactly the runs a question selects; an own question or any template. */
    public function __invoke(int $question, QuestionRuns $runs, StudyExport $export): BinaryFileResponse
    {
        $user = auth()->user();
        $found = $user->questions()->notTemplates()->find($question) ?? Question::templates()->find($question) ?? abort(404);
        $path = tempnam(sys_get_temp_dir(), 'study');

        $export->write($runs->for($user, $found->definition()->projects), $user, $path);

        return response()->download($path, $export->filename($found->name ?? __('questions.untitled')), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
```

`routes/web.php` im `auth`-Block bei `statistics`:

```php
    Route::get('statistics/{question}/export', StudyExportController::class)->middleware('verified')->name('statistics.export');
```

In `resources/views/livewire/statistics/question-editor.blade.php` das Menü für jeden zeigen und nur die Verwaltungsaktionen an `$mayManage` binden; `@if ($mayManage)` um den ganzen `<flux:dropdown>` entfernen und die Einträge so ordnen:

```blade
            <flux:dropdown position="bottom" align="end" class="shrink-0">
                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
                <flux:menu>
                    <flux:menu.item icon="arrow-down-tray" href="{{ route('statistics.export', $questionId) }}">{{ __('questions.actions.export_raw') }}</flux:menu.item>
                    @if ($mayManage)
                        <flux:menu.separator />
                        <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $questionId }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                        @unless ($readOnly)
                            <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $questionId }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                            @if ($mayShare)
                                <flux:menu.item icon="rectangle-stack" wire:click="$parent.saveAsTemplate({{ $questionId }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                            @endif
                        @endunless
                        <flux:menu.separator />
                        <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $questionId }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
```

`lang/en/questions.php`, `actions`: `'export_raw' => 'Export raw data as CSV',`; `lang/de/questions.php`: `'export_raw' => 'Rohdaten als CSV exportieren',`.

- [ ] **Step 5: Run tests and look at it**

Run: `php artisan test --filter=StudyExportTest` → PASS; `php artisan test` grün.
Real data: `php artisan study:export-turns /tmp/claude-1000/study.zip --group=8` (Gruppe „Quality“), entpacken, `turns.csv` in einer Tabellenkalkulation öffnen und prüfen, dass Spalten `status condition` und `title-holder` gefüllt sind.
Im Browser: eine Vorlage öffnen → „…“ → „Rohdaten als CSV exportieren“ lädt eine ZIP.

- [ ] **Step 6: Commit**

```bash
git add app/Services/StudyExport app/Console/Commands/ExportStudyTurns.php app/Http/Controllers/StudyExportController.php routes/web.php resources/views/livewire/statistics/question-editor.blade.php lang/en/questions.php lang/de/questions.php tests/Feature/StudyExport/StudyExportTest.php
git commit -m "Export the raw study data of a question's runs as three CSV files"
```

---

### Task 13: Dokumentation

**Files:**
- Modify: `CLAUDE.md` (nicht versioniert, aber die Arbeitsdoku)

- [ ] **Step 1: Update CLAUDE.md**

- Abschnitt **Commands**: `project:cost` entfernen; ergänzen:

```bash
php artisan bundle:export {path} [--project=ID | --expert=ID] [--user=ID]   # ohne Auswahl: alles
php artisan bundle:import {path} [--user=ID] [--dry-run]
php artisan study:export-turns {path} [--project=…] [--group=…] [--pipeline=…] [--user=ID]  # runs/participants/turns.csv
php artisan prompts:prune                     # stündlich per Scheduler; PROMPT_RETENTION_HOURS (Default 24, 0 = aus)
```

- `composer dev` startet zusätzlich `schedule:work`.
- Abschnitt **Metrics**: den Satz zu `RunCosts` ersetzen durch: „Kosten werden nicht mehr berechnet; `prompt_logs` behält `tokens_in`, `tokens_out`, `tokens_reasoning`, das Job-Debug-Fenster zeigt sie pro Aufruf und als Summe pro Turn.“
- Abschnitt **Other integrations**: `ProjectTransfer\*` ersetzen durch eine Beschreibung von `App\Services\Transfer` (Bundle-ZIP, Schema 1, UUID-Abgleich, Experten behalten ihre ID wenn frei, Tags werden angelegt, Vorschau per Rollback, Einstellungen → Import/Export nur Admins) und `App\Services\StudyExport\StudyExport` (drei CSV, Tag-Spalten je Kategorie, Zählregel `words IS NOT NULL`, Menü „Rohdaten als CSV exportieren“ jeder Frage).
- Abschnitt **Data model notes**: `experts.uuid`, `projects.uuid` erwähnen; `prompt_logs.prompt` älter als `PROMPT_RETENTION_HOURS` enthält nur `[prompt removed after N h retention]`.
- Abschnitt **Failure diagnosis**: ergänzen, dass `prompt_logs.error` erhalten bleibt, der Prompt-Text aber nur 24 h.

- [ ] **Step 2: Final verification**

Run: `vendor/bin/pint --dirty`, `php artisan test` → grün; `php artisan route:list | grep -E "export|transfer"` zeigt `project.export`, `experts.export`, `settings.export`, `settings.transfer`, `statistics.export`.

---

## Self-Review

- **Spec-Abdeckung:** UUIDs → Task 1; Bundle-Format → Task 5; Tags/Experten → Task 6; Projekt inkl. Turns und Prompt-Logs → Task 7; Gruppen/Fragen → Task 8; Export/Import/Vorschau/Befehle → Task 9; Einzel-Downloads, Rechte, alter Export raus → Task 10; Import-UI mit Feedback → Task 11; Statistik-CSV → Task 12; Prompt-Aufbewahrung → Task 2; Tokens im Debug → Task 4; Kosten raus → Task 3; Doku → Task 13.
- **Ergänzung gegenüber dem Spec:** Experten-IDs bleiben beim Import erhalten, wenn frei (Selektor-Signale und `E{id}`-Tokens); Spalte `selector_reasoning` in `turns.csv`; Import-Vorschau per Transaktions-Rollback.
- **Bekannte Grenze:** Avatar-Bilddateien wandern nicht mit, nur `avatar_url`.
