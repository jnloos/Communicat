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
| One queue job per turn, re-queues its run (spec `2026-11-15-studie-steuerung-design.md`) | `Jobs/RunStudyProject.php` |
| `php artisan study:queue [ids…] [--group=ID] [--order-seed=N]` (skips `superseded` runs) | `Console/QueueStudyRuns.php` |
| `php artisan study:pause {reason?}`, `php artisan study:resume` | `Console/PauseStudy.php`, `Console/ResumeStudy.php` |
| Pause flag, circuit breaker, per-run health, giving up a run | `Control/` |
| `BreakerTripped` (fired once per breaker pause, for alerting) | `Events/BreakerTripped.php` |
| Planning: manifest, pure planner, idempotent writer, check (spec `2026-11-15-studie-planung-design.md`) | `Planning/` |
| `php artisan study:plan {manifest} --wave=N --scenarios=FROM-TO [--user=ID] (--dry-run \| --apply)` | `Console/PlanStudyWave.php` |
| `php artisan study:check [--study=main]` (exit FAILURE on any deviation) | `Console/CheckStudyPlan.php` |
| Planning page `/study/plan` (admins: upload, preview, apply) | `Livewire/PlanWavePage.php`, `views/plan.blade.php` |
| Writes a confirmed wave, on the app's **default** queue | `Jobs/ApplyWave.php` |
| Table `study_waves` (one row per wave: range, manifest copy, hashes, group, `applied_at`) | `Models/StudyWave.php`, `database/migrations/` (always loaded) |
| Table `study_events` (monitoring log: kind, level, message; the dashboard's "Latest messages") | `Models/StudyEvent.php`, `database/migrations/` (always loaded) |
| Read model for dashboard, watcher and `/status` (`StudyProgress` + pause, worker signal, last 10 events) and its text form | `Progress/StudySnapshot.php`, `Progress/StatusText.php` |
| `php artisan study:watch` (scheduler, every minute): standstill, pause, finished wave, report, then the healthchecks ping | `Console/WatchStudy.php`, `Watch/StudyWatcher.php`, `Watch/DeadMansSwitch.php` |
| Alert dedup/reminder/all clear in the cache; the way out (`study_events` + Discord + mail, each channel isolated) | `Watch/AlertBook.php`, `Watch/Notifier.php`, `Watch/Alert*.php` |
| Instant message on `BreakerTripped`; worker heartbeat on `Queue\Events\Looping` | `Watch/AnnounceHalt.php`, `Watch/WorkerHeartbeat.php` |
| Discord over REST (429 handling), Ed25519 interactions endpoint `POST /discord/interactions` (PING, `/status`), `php artisan study:discord-register` | `Discord/`, `Console/RegisterDiscordCommands.php` |
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
hash, unchanged earlier scenarios, and the next scenario
(`WaveRules`). Tags it sets: project `study`, `wave`, `scenario`, `block`,
`focus profile`, `duo profile`, `focus seat`; persona `study`, `first name`,
`subgroup`. Attributes Role, Gender, Origin are shown `all`, every other
attribute `none`. Each wave files its runs in the owner's sidebar group
`Studie {study} · Welle {n}`, so `study:queue --group=<id>` takes them; queue
only after `applied_at` is set (the page shows it). It shows operations only, never a share or an effect: looking at it
during a batch must not be able to steer when to stop.

**Running (control).** `RunStudyProject` runs **one turn** under a cache lock
(`study-run:{id}`) and then queues the run's next turn itself; it is unique per
project until it starts, so `study:queue` may run twice. Delays: 0 after a spoken
or failed turn, `60 s · 2^(n−1)` up to 900 s after `n` silent turns in a row
(interrupted, `jev_outage`). `Control/RunHealth` reads those chains from
`job_logs` (spoken = `words` not null). A run is given up after 10 failed turns in
a row (no words, status failed) or 3 abandoned turns in a row (a dead worker or a
timeout: the next job closes the leftover `running` row with `RunHealth::ABANDONED`
and checks this before the next turn): project tag `superseded: true` plus a
`failed_jobs` row; a replacement is the planner's job. **Never while the study is
paused.** Infrastructure interruptions and `jev_outage` never give a run up; silent
turns only set the backoff. A deleted project ends the chain quietly. A transient
exception outside the turn retries with backoff 10/60/300 s; a bug (any other
exception) fails the job and ends the chain; `study:queue` restores it and prints
"Queued" / "Already queued" / "Already finished".

**Pause.** `storage/app/study/pause.json` (`config('study.pause_file')`) pauses the
whole study; a file because `cache:clear`/`optimize:clear` must not lift it. A
`Queue::looping` hook keeps workers on the `study` connection from taking a job
while it exists. `study:pause "reason"` sets it, `study:resume` prints and removes
it and resets the breaker's streak (use case `CircuitBreaker::resume()`, also behind
the dashboard button; `PauseFlag` only handles the file). Both commands and the
dashboard fail with the file's path when the pause file cannot be written or removed
(the dashboard shows it in a callout). The circuit breaker sets it with source
`breaker` on any `AbortsRun` (model drift, Jev rejected), on an error text matching
`study.breaker.fatal_patterns` (HTTP 401/402/403, exhausted credit, `insufficient_quota`: core's `ErrorText` appends the provider's error code
to the SDK's body-less 429 message), or after 8
turns in a row without a word over all runs (cache, 6 h TTL). It never lifts
itself. Workers and web app must share the host and be able to write
`storage/app/study`.

**Workers.** Six units (`systemctl enable --now communicat-study@{1..6}`),
`KillMode=mixed`, `TimeoutStopSec=400` above the turn timeout of 360 s, `MemoryMax=550M` per unit
(children included). The turn timeout needs `ext-pcntl`. Order the tests pin:
timeout 360 < lock 420 < `retry_after` 480. After a manual `queue:clear study` the
unique locks block `study:queue` for up to an hour: run `php artisan cache:clear`
(the pause file survives it) or wait.

**Monitoring** (spec `2026-11-15-studie-ueberwachung-design.md`). The bot reports and
answers `/status`; it controls nothing (pause and resume: dashboard buttons, which go
through the confirm modal, and `study:pause`/`study:resume`). **Blind:** nothing here
reads a share, Gini or effect; `StudyBlindnessTest` greps `app/Study` for the metric
classes. `StudySnapshot` is the one source; the standstill rule exists once, in
`StudyProgress` (`stale`). `study:watch` runs on the scheduler (`schedule:work` in
`composer dev`, the scheduler unit in production; the cache must be persistent, not
`array`). Alert states live in the cache (`study.alert.*`): send on start, remind after
`STUDY_ALERT_REPEAT_MINUTES` (180), one all clear; `wave_finished` once, no reminder. A
pause suppresses the standstill alert (the pause is the cause), and so does the
`study.stale_after_minutes` after the last watch that saw it (the last turn predates the pause). Reports
(`STUDY_REPORT_EVERY_HOURS`, 6, 0 = off) go to Discord only, as a text-less `report`
row in `study_events`. A failing channel is logged and stops neither the other nor the
checks; the ping to `STUDY_HEALTHCHECK_URL` comes last and only when the checks did not
throw (healthchecks.io: period 1 min, grace 10 min). The worker signal
(`study.worker.seen`, cache) is written from the queue loop for the `study` queue, at
most every 15 s per process, **one shared signal**: it shows that some worker lives, not
how many.

**Discord setup.** Developer Portal: create application, add bot, copy token; invite the
bot (scopes `bot` + `applications.commands`, right "Send messages" in the channel); fill
`DISCORD_BOT_TOKEN`, `DISCORD_CHANNEL_ID`, `DISCORD_APPLICATION_ID`, `DISCORD_GUILD_ID`
(optional, makes the command appear at once) and `DISCORD_PUBLIC_KEY` in `.env`;
`php artisan study:discord-register` (prints the URL); enter that URL as "Interactions
Endpoint URL" (Discord pings it and sends bad signatures, expecting 401); try `/status`
in the channel. The endpoint needs public HTTPS and exists only while
`DISCORD_PUBLIC_KEY` is set (route list read at boot: `optimize:clear` after changing
it); it is outside the `web` stack, so the signature is its only protection, and it
answers `/status` only in `DISCORD_CHANNEL_ID`. Without it, messages and reports still work.
`STUDY_ALERT_MAIL` adds mail for alerts and all clears (the app's `MAIL_*` settings).

Stays in core on purpose (useful for every run): `App\Discussion\TransientFailure`
and `JobStatus::Interrupted`.

## Switch off (keep the files)

`STUDY_MODULE=false` in `.env`, then `php artisan optimize:clear`. Page, sidebar
link and commands disappear (so does `study:watch`, and with it the alerts); the `study` queue connection, the pause hook, the worker heartbeat and the `study_events` table stay
registered so jobs already queued do not break.

## Remove

1. Let the study queue run empty, then clear what is left:
   `php artisan queue:clear study` and `php artisan queue:flush` (or delete the
   `failed_jobs` rows with `queue = 'study'`). Their payloads name
   `App\Study\Jobs\RunStudyProject` and would fail once the class is gone;
   the same holds for a pending `App\Study\Jobs\ApplyWave` on the default queue.
2. Stop and remove the workers: `systemctl disable --now 'communicat-study@*'`,
   delete `/etc/systemd/system/communicat-study@.service`.
3. Drop the tables `study_waves` and `study_events` (`php artisan tinker --execute "Schema::dropIfExists('study_waves'); Schema::dropIfExists('study_events')"`
   and delete their rows from `migrations`), delete `storage/app/study/`, then delete
   `app/Study/` and `tests/Feature/Study/`.
4. Delete the three hooks, which do nothing without the folder but would read
   as loose ends: the `StudyServiceProvider` line in `bootstrap/providers.php`,
   `@includeIf('study::nav-item')` in
   `resources/views/components/layouts/app/sidebar.blade.php`, the
   `@source '../../app/Study/views'` line in `resources/css/app.css`; plus
   `STUDY_MODULE` and the `DISCORD_*`/`STUDY_ALERT_MAIL`/`STUDY_HEALTHCHECK_URL` keys in the `.env*` files and `phpunit.xml`, and
   `StudyModuleBoundaryTest.php`.
5. `rm -f bootstrap/cache/*.php && composer dump-autoload -o && php artisan optimize:clear && npm run build && php artisan test`.

The study data itself (projects, tags, job logs) is ordinary app data and stays.
