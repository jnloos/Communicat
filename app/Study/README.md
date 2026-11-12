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
| Worker unit | `systemd/communicat-study@.service` |
| Tests | `tests/Feature/Study/` |

The progress page reads the project tag categories named in `config.php`
(`focus profile`, `duo profile`, `scenario`, `wave`), which the cell generator
sets. It shows operations only, never a share or an effect: looking at it
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
   `App\Study\Jobs\RunStudyProject` and would fail once the class is gone.
2. Stop and remove the workers: `systemctl disable --now 'communicat-study@*'`,
   delete `/etc/systemd/system/communicat-study@.service`.
3. Delete `app/Study/` and `tests/Feature/Study/`.
4. Delete the three hooks, which do nothing without the folder but would read
   as loose ends: the `StudyServiceProvider` line in `bootstrap/providers.php`,
   `@includeIf('study::nav-item')` in
   `resources/views/components/layouts/app/sidebar.blade.php`, the
   `@source '../../app/Study/views'` line in `resources/css/app.css`; plus
   `STUDY_MODULE` in the `.env*` files and `phpunit.xml`, and
   `StudyModuleBoundaryTest.php`.
5. `rm -f bootstrap/cache/*.php && composer dump-autoload -o && php artisan optimize:clear && npm run build && php artisan test`.

The study data itself (projects, tags, job logs) is ordinary app data and stays.
