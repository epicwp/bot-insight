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
	# Tell WordPress (5.8+) that this build updates from GitHub, so a WordPress.org plugin with the
	# same slug is never offered as its update. Only in the build copy; the org build has no header.
	perl -0pi -e 's#^( \* License URI:[^\n]*\n)#$1 * Update URI:        https://github.com/epicwp/bot-insight\n#m' "$target/bot-insight.php"
fi

rm -f "$target/composer.lock"

(cd "$build" && zip -qr "$zip_file" bot-insight -x '*.DS_Store')

# Check the zip's contents; a wrong flavour must never be published.
fail() {
	echo "Zip check failed ($flavour): $1" >&2
	rm -f "$zip_file"
	exit 1
}
listing="$(unzip -Z1 "$zip_file")"
header="$(unzip -p "$zip_file" bot-insight/bot-insight.php)"
for required in bot-insight/data/ bot-insight/assets/ bot-insight/vendor/maxmind-db/; do
	grep -q "^$required" <<<"$listing" || fail "missing $required"
done
if [ "$flavour" = "direct" ]; then
	grep -q '^ \* Update URI: *https://github.com/epicwp/bot-insight$' <<<"$header" || fail "no Update URI header"
	grep -q '^bot-insight/src/Updater.php$' <<<"$listing" || fail "missing src/Updater.php"
	grep -q '^bot-insight/vendor/yahnis-elsts/plugin-update-checker/' <<<"$listing" || fail "missing plugin-update-checker"
else
	if grep -qi 'Update URI' <<<"$header"; then fail "Update URI header in the org build"; fi
	if grep -q '^bot-insight/src/Updater.php$' <<<"$listing"; then fail "src/Updater.php in the org build"; fi
	if grep -qi 'plugin-update-checker\|PucFactory' <<<"$listing"; then fail "plugin-update-checker in the org build"; fi
fi

echo "Built $zip_file"
