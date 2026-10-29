#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-bs-ads}"
COMPOSE_FILE="${COMPOSE_FILE:-/opt/bs-ads/.deployment/compose.yaml}"

trap 'status=$?; printf "\nDeployment failed at line %s (exit %s).\n" "$LINENO" "$status" >&2; exit "$status"' ERR

for command in git php docker; do
    if ! command -v "$command" >/dev/null 2>&1; then
        printf 'Required command not found: %s\n' "$command" >&2
        exit 1
    fi
done

if [ ! -d "$ROOT/.git" ]; then
    printf 'Git repository not found at %s\n' "$ROOT" >&2
    exit 1
fi

if [ ! -f "$BACKEND/artisan" ]; then
    printf 'Laravel artisan file not found at %s\n' "$BACKEND/artisan" >&2
    exit 1
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    printf 'Docker Compose file not found at %s\n' "$COMPOSE_FILE" >&2
    exit 1
fi

printf '=== SharkAds production deployment ===\n'

printf '\n[1/3] Pulling the latest code...\n'
git -C "$ROOT" pull --ff-only

printf '\n[2/3] Running database migrations...\n'
(
    cd "$BACKEND"
    php artisan migrate --force
)

printf '\n[3/3] Restarting Docker Compose services...\n'
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" restart

printf '\nDeployment completed successfully.\n'
