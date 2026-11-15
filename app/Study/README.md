# Study module — study-only, not part of a release

Everything here exists only to run the master thesis study (see the handout
`2026-11-11_Handout-Studiendesign.pdf`, §3 and §6). Core code never names
this module, so it can be switched off or deleted without touching the app.
`tests/Feature/StudyModuleBoundaryTest.php` fails the moment core code starts
to depend on it.

| What | Where |
|---|---|
| Registration (config, views, translations, Livewire, routes, commands, queue connection) | `StudyServiceProvider.php` |
| Switch, tag names, queue connection `study` | `config.php` (`STUDY_MODULE`, default off) |
| Progress page `/study` (admins, read-only, polls 30 s) | `Livewire/ProgressPage.php`, `Progress/StudyProgress.php`, `views/` |
| One queue job per run | `Jobs/RunStudyProject.php` |
| `php artisan study:queue [ids…] [--group=ID] [--order-seed=N]` | `Console/QueueStudyRuns.php` |
| Planning: manifest, pure planner, idempotent writer, check (spec `2026-11-15-studie-planung-design.md`) | `Planning/` |
| `php artisan study:plan {manifest} --wave=N --scenarios=FROM-TO [--decision=…] [--user=ID] (--dry-run \| --apply)` | `Console/PlanStudyWave.php` |
| `php artisan study:check [--study=main]` (exit FAILURE on any deviation) | `Console/CheckStudyPlan.php` |
| Planning page `/study/plan` (admins: upload, preview, apply) | `Livewire/PlanWavePage.php`, `views/plan.blade.php` |
| Writes a confirmed wave, on the app's **default** queue | `Jobs/ApplyWave.php` |
| Table `study_waves` (one row per wave: range, decision, manifest copy, hashes, group, `applied_at`) | `Models/StudyWave.php`, `database/migrations/` (always loaded) |
| Worker unit | `systemd/communicat-study@.service` |
| Tests | `tests/Feature/Study/` |

The progress page reads the project tag categories named in `config.php`
(`focus profile`, `duo profile`, `scenario`, `wave`), which the planner sets.

**Planning.** A manifest (JSON, `schema: 1`) holds model, pipelines, attribute
templates, name pools and all scenarios, but no waves. `Planner::plan()` is a
pure function (no DB, no global RNG; pinned by `PlannerGoldenTest`): run key
`{study}:s03:b07:hl:ScorePipeline` → seed, seats, three personas. Projects and
experts get uuid5s from run key and persona content, so `WaveWriter` finds them
again: writing twice changes nothing, an abort resumes, one transaction per
instance (scenario × block). Wave N > 1 needs wave N−1 applied, the same core
hash, unchanged earlier scenarios, the next scenario and a decision
(`WaveRules`). Tags it sets: project `study`, `wave`, `scenario`, `block`,
`focus profile`, `duo profile`, `focus seat`; persona `study`, `first name`,
`subgroup`. Attributes Role, Gender, Origin are shown `all`, every other
attribute `none`. Each wave files its runs in the owner's sidebar group
`Studie {study} · Welle {n}`, so `study:queue --group=<id>` takes them; queue
only after `applied_at` is set (the page shows it). It shows operations only, never a share or an effect: looking at it
during a batch must not be able to steer when to stop.

Stays in core on purpose (useful for every run): `App\Discussion\TransientFailure`
and `JobStatus::Interrupted`.

## Switch off (keep the files)

`STUDY_MODULE=false` in `.env`, then `php artisan optimize:clear`. Page, sidebar
link and command disappear; the `study` queue connection stays registered so
jobs already queued do not break.

## Remove

1. Let the study queue run empty, then clear what is left:
   `php artisan queue:clear study` and `php artisan queue:flush` (or delete the
   `failed_jobs` rows with `queue = 'study'`). Their payloads name
   `App\Study\Jobs\RunStudyProject` and would fail once the class is gone;
   the same holds for a pending `App\Study\Jobs\ApplyWave` on the default queue.
2. Stop and remove the workers: `systemctl disable --now 'communicat-study@*'`,
   delete `/etc/systemd/system/communicat-study@.service`.
3. Drop the table `study_waves` (`php artisan tinker --execute "Schema::dropIfExists('study_waves')"`
   and delete its row from `migrations`), then delete `app/Study/` and `tests/Feature/Study/`.
4. Delete the three hooks, which do nothing without the folder but would read
   as loose ends: the `StudyServiceProvider` line in `bootstrap/providers.php`,
   `@includeIf('study::nav-item')` in
   `resources/views/components/layouts/app/sidebar.blade.php`, the
   `@source '../../app/Study/views'` line in `resources/css/app.css`; plus
   `STUDY_MODULE` in the `.env*` files and `phpunit.xml`, and
   `StudyModuleBoundaryTest.php`.
5. `rm -f bootstrap/cache/*.php && composer dump-autoload -o && php artisan optimize:clear && npm run build && php artisan test`.

The study data itself (projects, tags, job logs) is ordinary app data and stays.
