#!/usr/bin/env bash
# Builds a study manifest from its folder (thesis study, see docs/study.md).
#
#   resources/study/manifests/build.sh <folder>        e.g. resources/study/manifests/main
#
# The folder holds
#   study.json            study, schema, master_seed, model, turn_budget, pipelines,
#                         attributes, genders, optional seating
#   names.json            subgroups, first_names, last_names, excluded_full_names
#   scenarios/sNN.json    one scenario each: id, topic, titles, descriptions; id = NN
# and gets <folder>/manifest.json, the single file study:plan and the plan page read.
# The planner validates everything else; check the result with
#   php artisan study:plan <folder>/manifest.json --wave=N --scenarios=FROM-TO --dry-run
set -euo pipefail

dir="${1:?usage: build.sh <folder>}"
dir="${dir%/}"
command -v jq >/dev/null || { echo "build.sh needs jq." >&2; exit 1; }

for part in study.json names.json; do
    [[ -f "$dir/$part" ]] || { echo "Missing $dir/$part." >&2; exit 1; }
done

mapfile -t scenarios < <(find "$dir/scenarios" -maxdepth 1 -name 's*.json' 2>/dev/null | sort -V)
[[ ${#scenarios[@]} -gt 0 ]] || { echo "No scenario in $dir/scenarios (files sNN.json)." >&2; exit 1; }

# The file name is the scenario's place: s01, s02, … without gaps, and the id inside says the same.
expected=1
for file in "${scenarios[@]}"; do
    name="$(basename "$file" .json)"
    [[ "$name" =~ ^s([0-9]+)$ ]] || { echo "$file: name it sNN.json." >&2; exit 1; }
    number=$((10#${BASH_REMATCH[1]}))
    [[ $number -eq $expected ]] || { echo "$file: expected s$(printf %02d "$expected").json, scenarios run 1, 2, 3 … without gaps." >&2; exit 1; }
    id="$(jq '.id' "$file")"
    [[ "$id" == "$number" ]] || { echo "$file: \"id\" is $id, the file name says $number." >&2; exit 1; }
    expected=$((expected + 1))
done

# A key in both parts would silently win from one side.
overlap="$(jq -rn --slurpfile s "$dir/study.json" --slurpfile n "$dir/names.json" '[($s[0] | keys)[] | select(. as $k | $n[0] | has($k))] | join(", ")')"
[[ -z "$overlap" ]] || { echo "study.json and names.json both define: $overlap." >&2; exit 1; }

jq -n --slurpfile study "$dir/study.json" --slurpfile names "$dir/names.json" --slurpfile scenarios <(cat "${scenarios[@]}") \
    '$study[0] + $names[0] + {scenarios: $scenarios}' > "$dir/manifest.json"

echo "Wrote $dir/manifest.json"
jq -r '"Study \(.study): \(.model), \(.pipelines | join(", ")), \(.turn_budget) turns, seating \(.seating // "drawn"), master seed \(.master_seed)"' "$dir/manifest.json"
jq -r '.scenarios[] | "  S\(.id | tostring | if length < 2 then "0" + . else . end)  \(.titles.high) / \(.titles.low)  \(.topic)"' "$dir/manifest.json"
