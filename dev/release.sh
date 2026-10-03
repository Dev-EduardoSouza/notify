#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(git -C "$root" rev-parse --short HEAD)"
work="$root/build/telegram-api"
archive="$root/build/telegram-api-$version.tar.gz"

rm -rf "$work" "$archive"
mkdir -p "$work"

git -C "$root" archive HEAD | tar -x -C "$work"
rm -rf "$work/dev" "$work/tests" "$work/phpunit.xml" "$work/phpstan.neon" "$work/pint.json" "$work/references"

(cd "$work" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts)

mkdir -p "$work/storage/framework/cache/data" "$work/storage/framework/views" "$work/storage/logs" "$work/storage/app/private"
tar -czf "$archive" -C "$root/build" telegram-api

echo "$archive"
