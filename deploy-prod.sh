#!/usr/bin/env bash
# Existing BS Ads Docker installation only. Pull code separately before deploying.
set -Eeuo pipefail
umask 077

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
STATE="$ROOT/.deployment"
COMPOSE="$STATE/compose.yaml"
PROJECT=bs-ads
BUILDER=bs-ads-prod-builder
MODE="${1:-deploy}"
APP=(php worker-core worker-audits worker-library worker-analysis scheduler web scraper)
WORKERS=(worker-core worker-audits worker-library worker-analysis scheduler)
MAINTENANCE=0
RUNTIME_CHANGED=0
BUILDER_USED=0
SUCCESS=0

usage() {
    printf 'Usage: bash deploy-prod.sh [deploy|restart]\n\n'
    printf '  deploy   Build checked-out code, back up DB, migrate, refresh caches, start BS Ads.\n'
    printf '  restart  Reload .env/caches and restart existing images (no builds/migrations).\n\n'
    printf 'Run git pull --ff-only first for new commits. No host PHP is needed.\n'
}
die() { printf 'Error: %s\n' "$*" >&2; exit 1; }
step() { printf '\n=== %s ===\n' "$*"; }
dc() { docker compose -p "$PROJECT" -f "$COMPOSE" "$@"; }
next() { docker compose -p "$PROJECT" -f "$RELEASE/compose.yaml" "$@"; }
artisan() { next run --rm --no-deps -T --user 33:33 php php artisan "$@"; }

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    set +e
    if (( ! SUCCESS && RUNTIME_CHANGED )); then
        printf '\nDeployment failed. Restoring the previous BS Ads configuration.\n' >&2
        install -o 33 -g 33 -m 400 "$RELEASE/runtime.before.env" "$STATE/runtime.env"
        cp "$RELEASE/scraper.before.env" "$STATE/scraper.env"
        cp "$RELEASE/compose.before.yaml" "$COMPOSE"
        if (( MAINTENANCE )); then
            dc up -d --no-deps --force-recreate "${APP[@]}"
            dc exec -T --user 33:33 php php artisan up
            printf 'Migrations were NOT reversed. Database backup: %s/database.sql\n' "$RELEASE" >&2
        fi
    fi
    if (( BUILDER_USED )); then
        # Stop only our builder, never Docker, PM2, Caddy, or another project.
        docker buildx stop "$BUILDER" >/dev/null 2>&1
    fi
    if (( status != 0 )); then
        printf 'Deployment failed; logs: %s\n' "${RELEASE:-$STATE}" >&2
    fi
    exit "$status"
}

build_image() {
    local name=$1 dockerfile=$2 context=$3
    step "Building $name (one CPU, 768 MiB; cached layers retained)"
    if docker buildx build --builder "$BUILDER" --load --file "$dockerfile" \
        --tag "bs-ads-$name:$RUN_ID" "$context" >"$RELEASE/build-$name.log" 2>&1; then
        return 0
    fi
    tail -n 35 "$RELEASE/build-$name.log" >&2
    return 1
}

healthy() {
    local ids=() states
    mapfile -t ids < <(dc ps -aq "${APP[@]}")
    (( ${#ids[@]} == ${#APP[@]} )) || return 1
    states=$(docker inspect --format '{{.State.Running}} {{.State.OOMKilled}}{{if .State.Health}} {{.State.Health.Status}}{{end}}' "${ids[@]}") || return 1
    if grep -Ev '^true false( healthy)?$' <<<"$states" >/dev/null; then return 1; fi
    curl -fsS --max-time 5 http://127.0.0.1:4280/up >/dev/null 2>&1 || return 1
    timeout 15 docker compose -p "$PROJECT" -f "$COMPOSE" exec -T --user 33:33 -e "DEPLOY_SINCE=$STARTED" php php <<'PHP' >/dev/null 2>&1
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = app(App\Services\OperationsReadinessService::class)->inspect();
if ($result['status'] !== 'ready') exit(1);
foreach ($result['checks']['heartbeats'] as $heartbeat) {
    if (strtotime($heartbeat['last_seen_at'] ?? '') < (int) getenv('DEPLOY_SINCE')) exit(1);
}
PHP
}

main() {
    case "$MODE" in
        -h|--help) usage; return ;;
        deploy|restart) ;;
        *) usage >&2; die "Unknown mode: $MODE" ;;
    esac
    (( $# <= 1 )) || die 'Use only deploy or restart.'
    (( EUID == 0 )) || die 'Run as root on the BS Ads server.'
    for command in docker git python3 curl flock install timeout; do
        command -v "$command" >/dev/null || die "Missing command: $command"
    done
    for file in "$COMPOSE" "$ROOT/backend/.env" "$STATE/runtime.env" "$STATE/scraper.env"; do
        [[ -f "$file" ]] || die "Missing existing deployment file: $file"
    done
    docker compose version >/dev/null
    mkdir -p "$STATE/releases"
    # Prevent overlapping builds, migrations, and restarts for this app.
    exec 9>"$STATE/deploy.lock"
    flock -n 9 || die 'Another BS Ads deployment is running.'
    RUN_ID="$(git -C "$ROOT" rev-parse --short=12 HEAD)-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    RELEASE="$STATE/releases/$RUN_ID"
    mkdir -m 700 "$RELEASE"
    cp "$COMPOSE" "$RELEASE/compose.before.yaml"
    cp "$STATE/runtime.env" "$RELEASE/runtime.before.env"
    cp "$STATE/scraper.env" "$RELEASE/scraper.before.env"
    trap cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM

    local update_scraper=0 previous=''
    if [[ "$MODE" == deploy ]]; then
        command -v mysqldump >/dev/null || die 'Missing command: mysqldump'
        git -C "$ROOT" -c core.fileMode=false diff --quiet
        git -C "$ROOT" -c core.fileMode=false diff --cached --quiet
        for file in .dockerignore .deployment/Dockerfile.php .deployment/Dockerfile.web; do
            [[ -f "$ROOT/$file" ]] || die "Missing build file: $file"
        done
        if [[ -f "$STATE/deployed-revision" ]]; then previous="$(cat "$STATE/deployed-revision")"; fi
        if [[ ! "$previous" =~ ^[0-9a-f]{7,40}$ ]] || \
            ! git -C "$ROOT" diff --quiet "$previous" HEAD -- services/meta-ad-library-scraper; then
            update_scraper=1
        fi
        docker buildx version >/dev/null
        if ! docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
            docker buildx create --name "$BUILDER" --driver docker-container \
                --driver-opt memory=768m --driver-opt cpu-quota=100000 --driver-opt cpu-period=100000
        fi
        BUILDER_USED=1
        build_image php "$STATE/Dockerfile.php" "$ROOT"
        build_image web "$STATE/Dockerfile.web" "$ROOT"
        if (( update_scraper )); then
            build_image scraper "$ROOT/services/meta-ad-library-scraper/Dockerfile" "$ROOT/services/meta-ad-library-scraper"
        fi
        docker buildx stop "$BUILDER" >/dev/null
        BUILDER_USED=0
    fi

    step 'Preparing isolated BS Ads configuration'
    # This override retains the existing ports, limits, networks, and storage.
    python3 - "$RELEASE/override.json" "$RUN_ID" "$MODE" "$update_scraper" <<'PY'
import json, sys
path, tag, mode, scraper = sys.argv[1:]
services = {}
if mode == 'deploy':
    for service in ['php', 'worker-core', 'worker-audits', 'worker-library', 'worker-analysis', 'scheduler']:
        services[service] = {'image': 'bs-ads-php:' + tag}
    services['web'] = {'image': 'bs-ads-web:' + tag}
    if scraper == '1': services['scraper'] = {'image': 'bs-ads-scraper:' + tag}
with open(path, 'w') as f:
    json.dump({'services': services, 'volumes': {'bootstrap-cache': {'name': 'bs-ads_bootstrap-cache-' + tag}}}, f)
PY
    docker compose -p "$PROJECT" -f "$COMPOSE" -f "$RELEASE/override.json" \
        config --no-env-resolution >"$RELEASE/compose.yaml"
    next config --quiet
    # Never source .env in a shell or print credentials.
    RUNTIME_CHANGED=1
    install -o 33 -g 33 -m 400 "$ROOT/backend/.env" "$STATE/runtime.env"
    next run --rm --no-deps -T --user 33:33 php php <<'PHP' >"$RELEASE/scraper.next.env"
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('production') || config('app.debug') ||
    config('database.default') !== 'mysql' ||
    config('database.connections.mysql.database') !== 'bs_ads' ||
    config('database.connections.mysql.username') !== 'bs_ads' ||
    config('database.connections.mysql.unix_socket') !== '/var/run/mysqld/mysqld.sock' ||
    rtrim(config('app.url'), '/') !== 'https://sharkads.brandshark.in') {
    fwrite(STDERR, "Refusing to deploy: expected production settings and dedicated bs_ads database/user.\n");
    exit(1);
}
$app->make('encrypter'); // Reject missing or invalid keys before maintenance mode.
$token = config('services.ad_library.token');
if ($token) echo 'SCRAPER_SERVICE_TOKEN='.$token.PHP_EOL;
PHP
    if [[ -s "$RELEASE/scraper.next.env" ]]; then cp "$RELEASE/scraper.next.env" "$STATE/scraper.env"; fi

    step 'Pausing only BS Ads for cutover'
    MAINTENANCE=1
    dc exec -T --user 33:33 php php artisan down --retry=60
    dc stop "${WORKERS[@]}"
    if [[ "$MODE" == deploy ]]; then
        step 'Backing up bs_ads and running pending migrations'
        mysqldump --protocol=SOCKET --socket=/var/run/mysqld/mysqld.sock \
            --single-transaction --no-tablespaces --routines --triggers bs_ads >"$RELEASE/database.sql"
        # Support the legacy MySQL FK while its unique index is replaced.
        next run --rm --no-deps -T --user 33:33 php php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (Schema::hasTable('report_schedules') && Schema::hasTable('migrations') &&
    !DB::table('migrations')->where('migration', '2026_10_29_000000_add_account_scope_to_performance_reports')->exists() &&
    !Schema::hasIndex('report_schedules', 'report_schedules_client_id_support_index')) {
    Schema::table('report_schedules', fn ($table) => $table->index('client_id', 'report_schedules_client_id_support_index'));
}
PHP
        artisan migrate --force --no-interaction
        artisan db:seed --class=AlertTemplateSeeder --force --no-interaction
    fi
    step 'Refreshing caches and starting BS Ads'
    artisan config:cache
    artisan route:cache
    artisan view:cache
    cp "$RELEASE/compose.yaml" "$COMPOSE"
    STARTED="$(date +%s)"
    if [[ "$MODE" == restart ]]; then dc up -d --no-deps --force-recreate "${APP[@]}"
    else dc up -d --no-deps "${APP[@]}"; fi
    dc exec -T --user 33:33 php php artisan up
    step 'Checking web, database, cache, scheduler and queues'
    local deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do
        if healthy; then
            dc ps
            if [[ "$MODE" == deploy ]]; then
                git -C "$ROOT" rev-parse HEAD >"$RELEASE/revision"
                cp "$RELEASE/revision" "$STATE/deployed-revision.next"
                mv "$STATE/deployed-revision.next" "$STATE/deployed-revision"
            fi
            SUCCESS=1
            printf '\nBS Ads is ready. Release files: %s\n' "$RELEASE"
            return
        fi
        sleep 3
    done
    die 'BS Ads did not become ready; restoring the previous services.'
}

main "$@"
