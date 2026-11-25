# Study manifests

One folder per study. Edit the parts, then build the single `manifest.json` that
`study:plan` and the plan page (`/study/plan`) read:

```bash
resources/study/manifests/build.sh resources/study/manifests/main
php artisan study:plan resources/study/manifests/main/manifest.json --wave=1 --scenarios=1-3 --dry-run
```

```
<study>/
  study.json            study (a-z), schema, master_seed, model, turn_budget, pipelines,
                        attributes (Role, Gender, Origin templates), genders, optional "seating": "table"
  names.json            subgroups, first_names, last_names, excluded_full_names
  scenarios/s01.json    id 1: topic, titles (high, low), descriptions (A, B, C)
  scenarios/s02.json    id 2 …, numbered without gaps; the file name and "id" must agree
  manifest.json         built, not committed
```

`build.sh` (needs `jq`) checks only the folder: both parts present, scenario
files `s01…` without gaps, ids matching, no key in both parts. Everything else
(model, pipelines, name pools, templates against the database) the planner
checks on `--dry-run`. It prints the study's head and one line per scenario.

People, seats and seeds are not in the manifest: the planner derives them from
`master_seed`, so the same parts always give the same study. A later wave may only
append scenarios; changing one an earlier wave planned is refused (`WaveRules`).
Keep the role template `You are :value.` as it is.

`probegptsix/` is the probe run of 24.11.2026, split into parts; building it gives
byte for byte the manifest its wave was applied with.
