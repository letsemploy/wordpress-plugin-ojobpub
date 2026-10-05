#!/usr/bin/env bash
# Deploys a built plugin directory to the wordpress.org SVN repository.
#
#   bin/svn-deploy.sh <build-dir>            sync trunk/ (and assets/ from .wordpress-org/)
#   bin/svn-deploy.sh <build-dir> <version>  same, plus copy trunk/ to tags/<version>/
#
# Environment: SVN_PASSWORD (required), SVN_USERNAME (default letsemploy),
# SVN_URL (default https://plugins.svn.wordpress.org/ojobpub), SVN_DIR (checkout dir).

set -euo pipefail

build_dir=${1:?usage: $0 <build-dir> [version]}
version=${2:-}
build_dir=$(cd "$build_dir" && pwd)
repo_dir=$(cd "$(dirname "$0")/.." && pwd)

: "${SVN_PASSWORD:?SVN_PASSWORD is not set}"
SVN_USERNAME=${SVN_USERNAME:-letsemploy}
SVN_URL=${SVN_URL:-https://plugins.svn.wordpress.org/ojobpub}
SVN_DIR=${SVN_DIR:-${RUNNER_TEMP:-/tmp}/ojobpub-svn}

if [ -n "$version" ] && svn ls --non-interactive "$SVN_URL/tags/$version" > /dev/null 2>&1; then
	echo "::error::$SVN_URL/tags/$version already exists"
	exit 1
fi

# Only trunk and assets are needed in full; never check out all tags.
rm -rf "$SVN_DIR"
svn checkout --non-interactive --depth immediates "$SVN_URL" "$SVN_DIR"
cd "$SVN_DIR"
for dir in trunk assets; do
	if [ -d "$dir" ]; then
		svn update --non-interactive --set-depth infinity "$dir"
	else
		mkdir "$dir"
	fi
done
mkdir -p tags

rsync -a --delete --exclude=.svn "$build_dir/" trunk/

if [ -d "$repo_dir/.wordpress-org" ]; then
	rsync -a --delete --exclude=.svn "$repo_dir/.wordpress-org/" assets/
fi

svn add --force --quiet trunk assets tags
svn status | awk '/^!/ { print substr($0, 9) }' | while IFS= read -r path; do
	svn rm --quiet "$path@"
done

# Without a mime type, browsers download the assets instead of displaying them.
find assets -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' -o -name '*.gif' -o -name '*.svg' \) -print0 |
	while IFS= read -r -d '' file; do
		case "$file" in
			*.png) type=image/png ;;
			*.jpg | *.jpeg) type=image/jpeg ;;
			*.gif) type=image/gif ;;
			*.svg) type=image/svg+xml ;;
		esac
		svn propset --quiet svn:mime-type "$type" "$file"
	done

if [ -n "$version" ]; then
	svn cp --quiet trunk "tags/$version"
	message="Release $version"
else
	message="Update trunk from ${GITHUB_SHA:-$(git -C "$repo_dir" rev-parse HEAD)}"
fi

svn status
if [ -z "$(svn status)" ]; then
	echo "Nothing to commit."
	exit 0
fi

svn commit --non-interactive --no-auth-cache \
	--username "$SVN_USERNAME" --password "$SVN_PASSWORD" \
	-m "$message"
