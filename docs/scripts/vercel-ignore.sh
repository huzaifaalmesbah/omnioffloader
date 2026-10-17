#!/bin/sh
# Vercel "Ignored Build Step" (docs/vercel.json). Exit 0 skips the build, 1 runs it.
#
# Vercel's production branch is `docs-live`: the docs of the released plugin.
# It only moves at a release (release-please.yml) or with a docs fix merged
# into it, so every production push builds. Everything else — main, pull
# requests, branches — is a preview, built when docs/ or readme.txt changed.

if [ "$VERCEL_ENV" = "production" ]; then
	echo "docs-live changed: building the production docs."
	exit 1
fi

git diff --quiet HEAD^ HEAD -- . ../readme.txt
