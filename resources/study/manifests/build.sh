#!/usr/bin/env bash
# Builds manifest.json from a study folder and lists its scenarios (thesis study, see docs/study.md).
#
#   resources/study/manifests/build.sh main          a folder here, by name
#   resources/study/manifests/build.sh path/to/dir   or any path
#
# The rules live in one place, App\Services\Study\Planning\ManifestFolder; this only calls
# `php artisan study:manifest`. study:plan and the plan page read a folder directly.
set -euo pipefail
exec php "$(dirname "$0")/../../../artisan" study:manifest "$@"
