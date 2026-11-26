#!/usr/bin/env bash
#
# Run every tests/integration-*.php against a WordPress install.
#
#   scripts/integration-tests.sh [wp-path]
#
# The plugin must be active in that install (it boots like on a real site:
# MediaCounters and other hooks register on plugins_loaded). Each test runs
# with the offload engine off and Safe mode off, so it wires its own mock
# provider and never touches a real bucket.
#
# Fails when a test reports a failed check, ends without a RESULT line (a
# fatal error), or prints a PHP warning, notice or deprecation from the
# plugin's own code (includes/ or omnioffloader.php).
#
# Skipped: tests that need real credentials or a configured provider
# (integration-bulk-rest.php, integration-stale-status.php).
#
# Also fails when no check passed at all (nothing really ran).
#
# Environment:
#   WP_CLI   WP-CLI command (default: wp)
#   WP_URL   Site to run on, e.g. a multisite sub-site (default: the main site)
#   ONLY     Run just these test files, space separated (e.g. "integration-offload.php").
#
# On GitHub Actions the per-file table also goes to the job summary.

set -u

WP_PATH="${1:-}"
WP_CLI="${WP_CLI:-wp}"
# Physical path (-P): PHP reports files by their real path, also when the
# plugin folder is a symlink.
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
SKIP=" integration-bulk-rest.php integration-stale-status.php "

# Never empty: bash 3.2 (the macOS default) treats "${empty[@]}" as an
# unbound variable under `set -u`.
path_arg=(--no-color)
if [ -n "$WP_PATH" ]; then
	path_arg+=(--path="$WP_PATH")
fi
if [ -n "${WP_URL:-}" ]; then
	path_arg+=(--url="$WP_URL")
fi

if ! $WP_CLI "${path_arg[@]}" plugin is-active omnioffloader >/dev/null 2>&1; then
	echo "OmniOffloader is not active in this WordPress install. Activate it first:"
	echo "  $WP_CLI ${path_arg[*]} --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" plugin activate omnioffloader"
	exit 2
fi

site="single site"
if $WP_CLI "${path_arg[@]}" eval 'exit( is_multisite() ? 0 : 1 );' >/dev/null 2>&1; then
	site="multisite (blog $($WP_CLI "${path_arg[@]}" eval 'echo get_current_blog_id();' 2>/dev/null | tail -1))"
fi
cache="no persistent object cache"
if $WP_CLI "${path_arg[@]}" eval 'exit( wp_using_ext_object_cache() ? 0 : 1 );' >/dev/null 2>&1; then
	cache="persistent object cache"
fi
heading="WordPress $($WP_CLI "${path_arg[@]}" core version 2>/dev/null | tail -1) · PHP $(php -r 'echo PHP_VERSION;') · $site · $cache"
echo "$heading"
echo

failed=0
ran=0
checks=0
rows=""

for test in "$PLUGIN_DIR"/tests/integration-*.php; do
	name="$(basename "$test")"

	if [[ "$SKIP" == *" $name "* ]]; then
		continue
	fi
	if [ -n "${ONLY:-}" ] && [[ " $ONLY " != *" $name "* ]]; then
		continue
	fi

	ran=$((ran + 1))
	output="$($WP_CLI "${path_arg[@]}" \
		--exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true ); define( 'OMNIOFFLOADER_SAFE_MODE', false );" \
		eval-file "$test" 2>&1)"

	result="$(printf '%s\n' "$output" | grep -E '^RESULT:' | tail -1)"
	# PHP notices raised inside the plugin's own code (not in tests or WP-CLI).
	notices="$(printf '%s\n' "$output" | grep -E '(Warning|Notice|Deprecated|Fatal error)' | grep -v 'phar://' | grep -F -e "$PLUGIN_DIR/includes/" -e "$PLUGIN_DIR/omnioffloader.php" || true)"

	if [ -z "$result" ]; then
		status="FAIL (no RESULT line: fatal error?)"
	elif ! printf '%s' "$result" | grep -qE ' 0 failed$'; then
		status="FAIL ($result)"
	elif [ -n "$notices" ]; then
		status="FAIL (PHP notices from plugin code)"
	else
		status="ok   (${result#RESULT: })"
	fi

	printf '%-40s %s\n' "$name" "$status"
	rows+="| \`$name\` | ${status} |"$'\n'

	passed_here="$(printf '%s' "$result" | sed -nE 's/^RESULT: ([0-9]+) passed.*/\1/p')"
	checks=$((checks + ${passed_here:-0}))

	if [[ "$status" == FAIL* ]]; then
		failed=$((failed + 1))
		echo "----- output of $name -----"
		printf '%s\n' "$output" | grep -v 'phar://' | tail -80
		echo "---------------------------"
	fi
done

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### Integration tests"
		echo
		echo "$heading"
		echo
		echo "| Test file | Result |"
		echo "|---|---|"
		printf '%s' "$rows"
		echo
		echo "**$checks checks passed** in $ran test files, $failed failed."
	} >> "$GITHUB_STEP_SUMMARY"
fi

echo
if [ "$ran" -eq 0 ] || [ "$checks" -eq 0 ]; then
	echo "No tests ran (or no check passed)."
	exit 2
fi
if [ "$failed" -gt 0 ]; then
	echo "$failed of $ran test files failed ($checks checks passed)."
	exit 1
fi
echo "All $ran test files passed: $checks checks."
