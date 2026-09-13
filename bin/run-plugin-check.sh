#!/usr/bin/env bash
set -euo pipefail

# Runs official WordPress Plugin Check against this tree.
# Requires the local WP install at ~/Projects/clockwork-plugin-check-wp
# (wp-cli phar + Plugin Check plugin). Does not need Docker.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ROOT="${CLOCKWORK_PLUGIN_CHECK_WP:-$HOME/Projects/clockwork-plugin-check-wp}"
WP_PATH="${WP_ROOT}/wordpress"
WP_CLI="${WP_ROOT}/wp"
DEST="${WP_PATH}/wp-content/plugins/clockwork-renegade"
CLI="${WP_PATH}/wp-content/plugins/plugin-check/cli.php"

if [[ ! -x "$WP_CLI" && ! -f "$WP_CLI" ]]; then
    echo "Missing WP-CLI phar at $WP_CLI" >&2
    exit 1
fi
if [[ ! -f "$CLI" ]]; then
    echo "Plugin Check is not installed at $CLI" >&2
    exit 1
fi

mkdir -p "$DEST"
rsync -a --delete "${REPO_ROOT}/src/" "${DEST}/src/"
rsync -a --delete "${REPO_ROOT}/assets/" "${DEST}/assets/"
if [[ -d "${REPO_ROOT}/languages" ]]; then
    rsync -a --delete "${REPO_ROOT}/languages/" "${DEST}/languages/"
fi
cp "${REPO_ROOT}/clockwork-renegade.php" "${DEST}/"
cp "${REPO_ROOT}/readme.txt" "${DEST}/"
cp "${REPO_ROOT}/uninstall.php" "${DEST}/"

php "$WP_CLI" --path="$WP_PATH" --require="$CLI" plugin check clockwork-renegade --format=table --mode=new
