#!/usr/bin/env bash
# Dedicated BS Ads backups only. Never traverse symlinks or prune Docker resources.
set -Eeuo pipefail
ROOT=/opt/bs-ads/.deployment/releases
[[ -d "$ROOT" && ! -L "$ROOT" ]] || exit 0
[[ "$(realpath -- "$ROOT")" == /opt/bs-ads/.deployment/releases ]] || exit 1
find -P "$ROOT" -mindepth 2 -maxdepth 2 -type f -name database.sql -mtime +29 -delete
