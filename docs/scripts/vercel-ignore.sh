#!/bin/sh
# Vercel "Ignored Build Step" (docs/vercel.json).
# Exit 0 skips / cancels the build. Exit 1 proceeds with the build.
#
# Production:
# Vercel's production branch is `docs-live`: the docs of the released plugin.
# It only moves at a release (release-please.yml) or with a docs fix merged into it,
# so every production deployment builds.
if [ "$VERCEL_ENV" = "production" ]; then
	echo "Production deployment on docs-live: proceeding with build."
	exit 1
fi

# Previews / Pull Requests:
# Only build when documentation files (docs/) or user-facing changelog (readme.txt) changed.
BASE="origin/main"
if ! git rev-parse --verify "$BASE" >/dev/null 2>&1; then
	git fetch origin main --depth=1 >/dev/null 2>&1 || true
fi

DIFF_TARGET=""
if git rev-parse --verify "$BASE" >/dev/null 2>&1; then
	DIFF_TARGET="$BASE...HEAD"
elif git rev-parse --verify HEAD^ >/dev/null 2>&1; then
	DIFF_TARGET="HEAD^ HEAD"
elif [ -n "$CACHED_COMMIT_REF" ] && git rev-parse --verify "$CACHED_COMMIT_REF" >/dev/null 2>&1; then
	DIFF_TARGET="$CACHED_COMMIT_REF HEAD"
fi

if [ -n "$DIFF_TARGET" ]; then
	if git diff --quiet $DIFF_TARGET -- . ../readme.txt; then
		echo "No changes detected in docs/ or readme.txt: skipping Vercel build."
		exit 0
	else
		echo "Changes detected in docs/ or readme.txt: proceeding with Vercel build."
		exit 1
	fi
fi

# Fallback: if git history cannot be resolved, skip preview build by default.
echo "Could not resolve diff target: skipping Vercel preview build by default."
exit 0
