#!/bin/sh
# Vercel "Ignored Build Step" (docs/vercel.json). Exit 0 skips the build, 1 runs it.
#
# Production docs describe the released plugin, so they deploy only with a
# release: the merged Release Please PR ("chore: release x.y.z"), whose commit
# carries every docs change up to that release. A docs fix needed sooner can
# say "[deploy docs]" in its commit message, or be redeployed from Vercel.
# Previews (pull requests, branches) build whenever docs/ or readme.txt changed.

if [ "$VERCEL_ENV" = "production" ]; then
	case "$VERCEL_GIT_COMMIT_MESSAGE" in
		"chore: release "* | *"[deploy docs]"*)
			echo "Release (or [deploy docs]): building the production docs."
			exit 1
			;;
		*)
			echo "Not a release: production docs wait for the next release."
			exit 0
			;;
	esac
fi

git diff --quiet HEAD^ HEAD -- . ../readme.txt
