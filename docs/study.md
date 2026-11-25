# The thesis study: where it lives and how to run it

Everything here exists only to run the master thesis study (see the handout
`2026-11-11_Handout-Studiendesign.pdf`, §3 and §6). Since 25.11.2026 it sits in
Laravel's own places instead of one module folder; `STUDY_MODULE` still switches
its pages off.

| What | Where |
|---|---|
| What Laravel does not find by itself: queue connection `study`, worker hooks, and while on: pages, Discord endpoint, alert listener | `app/Providers/StudyServiceProvider.php` |
| Switch, tag names, queue connection `study` | `config/study.php` (`STUDY_MODULE`, default off) |
| Progress page `/study` (admins, read-only, polls 30 s) | `app/Livewire/Study/ProgressPage.php`, `app/Services/Study/Progress/StudyProgress.php`, `resources/views/study/` |
| Routes `/study`, `/study/plan` (named `study.*`, admins) | `routes/study.php` |
| Texts | `lang/{de,en}/study.php` (`study.plan.…`, `study.progress.…`, `study.watch.…`) |
| One queue job per turn, re-queues its run (spec `2026-11-15-studie-steuerung-design.md`) | `app/Jobs/RunStudyProject.php` |
| `php artisan study:queue [ids…] [--group=ID] [--order-seed=N]` (skips `superseded` runs) | `app/Console/Commands/Study/QueueStudyRuns.php` |
| `php artisan study:pause {reason?}`, `php artisan study:resume` | `app/Console/Commands/Study/PauseStudy.php`, `ResumeStudy.php` |
| Pause flag, circuit breaker, per-run health, giving up a run, queued runs | `app/Services/Study/Control/` |
| `BreakerTripped` (fired once per breaker pause, for alerting) | `app/Events/BreakerTripped.php` |
| Planning: manifest, pure planner, seat table, idempotent writer, check (spec `2026-11-15-studie-planung-design.md`) | `app/Services/Study/Planning/` |
| Manifests: one folder per study, `build.sh` assembles `manifest.json` | `resources/study/manifests/` |
| `php artisan study:plan {manifest} --wave=N --scenarios=FROM-TO [--user=ID] (--dry-run \| --apply)` | `app/Console/Commands/Study/PlanStudyWave.php` |
| `php artisan study:check [--study=main]` (exit FAILURE on any deviation) | `app/Console/Commands/Study/CheckStudyPlan.php` |
| Planning page `/study/plan` (admins: upload, preview, apply) | `app/Livewire/Study/PlanWavePage.php`, `resources/views/study/plan.blade.php` |
| Writes a confirmed wave, on the app's **default** queue | `app/Jobs/ApplyWave.php` |
| Table `study_waves` (one row per wave: range, manifest copy, hashes, group, `applied_at`) | `app/Models/StudyWave.php`, `database/migrations/2026_11_15_*` |
| Table `study_events` (monitoring log: kind, level, message; the dashboard's "Latest messages") | `app/Models/StudyEvent.php`, `database/migrations/2026_11_15_*` |
| Read model for dashboard, watcher and `/status` and its text form | `app/Services/Study/Progress/StudySnapshot.php`, `StatusText.php` |
| `php artisan study:watch` (scheduled every minute in `routes/console.php` while on): standstill, pause, finished wave, report, then the healthchecks ping | `app/Console/Commands/Study/WatchStudy.php`, `app/Services/Study/Watch/` |
| Discord over REST, Ed25519 interactions endpoint `POST /discord/interactions` (PING, `/status`), `php artisan study:discord-register` | `app/Services/Discord/`, `app/Http/Controllers/DiscordInteractionController.php`, `app/Console/Commands/Study/RegisterDiscordCommands.php` |
| Worker unit | `deploy/systemd/communicat-study@.service` |
| Tests | `tests/Feature/Study/` |

The progress page reads the project tag categories named in `config/study.php`
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
`subgroup`, `focus persona` (`true` on the focus place, `false` on the duo; a wave
applied before 25.11.2026 gets it by applying it again). Attributes Role, Gender, Origin are shown `all`, every other
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

**Manifests.** One folder per study under `resources/study/manifests/` (`study.json`, `names.json`,
`scenarios/sNN.json`); `resources/study/manifests/build.sh <folder>` builds the `manifest.json` that
`study:plan` reads (see the README there).

**Workers.** Six units (`systemctl enable --now communicat-study@{1..6}`),
`KillMode=mixed`, `TimeoutStopSec=400` above the turn timeout of 360 s, `MemoryMax=550M` per unit
(children included). The turn timeout needs `ext-pcntl`. Order the tests pin:
timeout 360 < lock 420 < `retry_after` 480. A unique lock without a job row (after
`queue:clear study`, or a job failed while being reserved) is released by `study:queue`
itself, which reads the queue table (`Control\QueuedRuns`); never `cache:clear`, it also
drops the `study-run:{id}` locks of running turns. On SQLite the connection uses
`transaction_mode` IMMEDIATE (PHP >= 8.4), so a worker that finds the database locked
while reserving a job waits instead of failing it; should one still fail that way,
`RunStudyProject::failed()` releases the lock and queues the run again. The progress page
counts a run as given up by its `superseded` tag, not by a `failed_jobs` row. Study
workers write no Pail file (`StudyServiceProvider::silencePail()`): concurrent workers raced
on unlinking a stale one and failed turns.

**Monitoring** (spec `2026-11-15-studie-ueberwachung-design.md`). The bot reports and
answers `/status`; it controls nothing (pause and resume: dashboard buttons, which go
through the confirm modal, and `study:pause`/`study:resume`). **Blind:** nothing here
reads a share, Gini or effect; `StudyBlindnessTest` greps every study path (its `STUDY_CODE` list) for the metric
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

## Switch off

`STUDY_MODULE=false` in `.env`, then `php artisan optimize:clear`. Pages, sidebar
link, Discord endpoint and the `study:watch` schedule (and with it the alerts) are
gone; the `study:*` commands stay available. The `study` queue connection, the pause
hook, the worker heartbeat and the tables stay, so jobs already queued do not break.

The study data itself (projects, tags, job logs) is ordinary app data.
