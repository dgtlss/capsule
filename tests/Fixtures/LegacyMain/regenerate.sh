#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../../.." && pwd)"
revision=543a2ea9534eaf6df6d0e5710e972f518adc2306
source="$(mktemp -d "${TMPDIR:-/tmp}/capsule-main-source.XXXXXX")"
trap 'rm -rf "$source"' EXIT
cd "$root"
git archive "$revision" | tar -x -C "$source"
export CAPSULE_LEGACY_SOURCE="$source"
export CAPSULE_LEGACY_FIXTURES="$root/tests/Fixtures/LegacyMain"
php vendor/bin/phpunit --no-configuration \
    --bootstrap tests/Fixtures/LegacyMain/generate-bootstrap.php \
    tests/Fixtures/LegacyMain/GenerateLegacyFixturesTest.php
