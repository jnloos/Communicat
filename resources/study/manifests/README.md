# Study manifests

One folder per study. `study:plan` and the plan page (`/study/plan`, source "Study folder")
read a folder directly; there is no build step before planning:

```bash
php artisan study:plan main --wave=1 --scenarios=1-3 --dry-run     # a folder here, by name
php artisan study:manifest main                                     # optional: write main/manifest.json, list the scenarios
resources/study/manifests/build.sh main                             # the same, as a script
```

```
<study>/
  study.json            study (a-z), schema, master_seed, model, turn_budget, pipelines,
                        attributes (Role, Gender, Origin templates), genders, optional "seating": "block" and "name_draw": "reuse"
  names.json            subgroups, first_names, last_names, excluded_full_names
  scenarios/s01.json    id 1: topic, titles (high, low), descriptions (A, B, C)
  scenarios/s02.json    id 2 …, numbered without gaps; the file name and "id" must agree
  manifest.json         written by study:manifest only, not committed
```

`App\Services\Study\Planning\ManifestFolder` joins the parts and checks the folder (both parts
present and valid JSON, scenario files `s01…` without gaps, ids matching, no key in both parts);
the planner checks the content. The hashes are over the canonical form, so how the parts are laid
out never matters.

People, seats and seeds are not in the manifest: the planner derives them from `master_seed`, so
the same parts always give the same study. A later wave may only append scenarios; changing one an
earlier wave planned is refused (`WaveRules`). Keep the role template `You are :value.` as it is.

`probegptsix/` is the probe run of 24.11.2026; it assembles to the manifest its wave was applied
with (pinned by `ManifestFolderTest`).
