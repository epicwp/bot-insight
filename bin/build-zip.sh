#!/usr/bin/env bash
#
# Builds dist/bot-insight-<flavour>.zip.
#
#   direct  Includes src/Updater.php and the plugin-update-checker library (GitHub update check).
#   org     For WordPress.org: no updater, no update-checker library, no external update call.
#
# Everything happens in a copy under build/, so the working tree (vendor/, composer.json) is untouched.
# Usage: bin/build-zip.sh <direct|org>

set -euo pipefail

flavour="${1:-}"
case "$flavour" in
	direct | org) ;;
	*)
		echo "Usage: $0 <direct|org>" >&2
		exit 1
		;;
esac

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build="$root/build"
target="$build/bot-insight"
zip_file="$root/dist/bot-insight-$flavour.zip"

rm -rf "$build"
mkdir -p "$target" "$root/dist"
rm -f "$zip_file"

# Copy the plugin without anything listed in .distignore (and never the dev vendor/).
rsync -a --exclude-from="$root/.distignore" --exclude='/vendor' --exclude='/build' --exclude='/dist' "$root/" "$target/"

# composer.lock is in .distignore; bring it along for reproducible installs, drop it afterwards.
cp "$root/composer.lock" "$target/composer.lock"

if [ "$flavour" = "org" ]; then
	rm -f "$target/src/Updater.php"
	composer remove yahnis-elsts/plugin-update-checker --no-update --no-interaction --working-dir="$target"
	composer update --no-dev --classmap-authoritative --no-interaction --no-scripts --working-dir="$target"
else
	composer install --no-dev --classmap-authoritative --no-interaction --no-scripts --working-dir="$target"
fi

rm -f "$target/composer.lock"

(cd "$build" && zip -qr "$zip_file" bot-insight -x '*.DS_Store')

echo "Built $zip_file"
