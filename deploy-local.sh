#!/bin/bash
set -e

ROOT="$(cd "$(dirname "$0")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

VITE_HOST="${VITE_HOST:-0.0.0.0}"
VITE_PORT="${VITE_PORT:-5173}"
API_HOST="${API_HOST:-127.0.0.1}"
API_PORT="${API_PORT:-8000}"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

detect_lan_ip() {
    local detected=""

    # Prefer the active Windows adapter used by the default route.
    if command -v powershell.exe >/dev/null 2>&1; then
        detected="$(powershell.exe -NoProfile -Command \
            '$config = Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq "Up" } | Select-Object -First 1; if ($config) { $config.IPv4Address.IPAddress }' \
            2>/dev/null | tr -d '\r' | head -n 1)"
    fi

    # Fallback for Linux/macOS environments.
    if [ -z "$detected" ] && command -v hostname >/dev/null 2>&1; then
        detected="$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi

    printf '%s' "$detected"
}

LAN_IP="${LAN_IP:-$(detect_lan_ip)}"

if [ -z "$LAN_IP" ] || [ "$LAN_IP" = "127.0.0.1" ]; then
    echo -e "${RED}Unable to detect a LAN IPv4 address.${NC}"
    echo "Run again with an explicit address, for example:"
    echo "  LAN_IP=192.168.0.125 bash deploy-local.sh"
    exit 1
fi

LAN_ORIGIN="http://${LAN_IP}:${VITE_PORT}"

# These process-level values let Sanctum accept the Vite origin without
# rewriting backend/.env. Explicit shell overrides remain supported.
export FRONTEND_URL="${FRONTEND_URL:-$LAN_ORIGIN}"
export CORS_ALLOWED_ORIGINS="${CORS_ALLOWED_ORIGINS:-$LAN_ORIGIN,http://localhost:${VITE_PORT},http://127.0.0.1:${VITE_PORT}}"
export SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-localhost,localhost:3000,localhost:${VITE_PORT},127.0.0.1,127.0.0.1:${VITE_PORT},127.0.0.1:${API_PORT},::1,${LAN_IP}:${VITE_PORT}}"

echo -e "${BLUE}=== Brandshark Pulse Local Wi-Fi Server ===${NC}"
echo -e "${GREEN}LAN URL: ${LAN_ORIGIN}${NC}"

echo -e "${YELLOW}Refreshing Laravel configuration and running migrations...${NC}"
cd "$BACKEND"
php artisan config:clear
php artisan migrate --force
echo -e "${GREEN}Laravel is ready.${NC}"

echo -e "${YELLOW}Starting services...${NC}"

php artisan serve --host="$API_HOST" --port="$API_PORT" &
PID_API=$!
echo -e "${GREEN}[API]     Laravel (local proxy target) -> http://${API_HOST}:${API_PORT}  (pid $PID_API)${NC}"

QUEUES="notifications,collection,audits,reports,ad-library,ad-library-analysis,default"
run_queue_worker() {
    while true; do
        if DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-1200}" php artisan queue:work \
            --queue="$QUEUES" \
            --timeout=1000 \
            --sleep=3 \
            --tries=3; then
            EXIT_CODE=0
        else
            EXIT_CODE=$?
        fi
        echo -e "${YELLOW}[QUEUE] Worker exited with code ${EXIT_CODE}; restarting in 3 seconds...${NC}"
        sleep 3
    done
}

run_queue_worker &
PID_QUEUE=$!
echo -e "${GREEN}[QUEUE]   Supervised worker for all queues started              (pid $PID_QUEUE)${NC}"

run_scheduler() {
    while true; do
        if php artisan schedule:work; then
            EXIT_CODE=0
        else
            EXIT_CODE=$?
        fi
        echo -e "${YELLOW}[SCHED] Scheduler exited with code ${EXIT_CODE}; restarting in 3 seconds...${NC}"
        sleep 3
    done
}

run_scheduler &
PID_SCHEDULER=$!
echo -e "${GREEN}[SCHED]   Supervised Laravel scheduler started                 (pid $PID_SCHEDULER)${NC}"

(
    cd "$FRONTEND"
    exec npm run dev -- --host "$VITE_HOST" --port "$VITE_PORT"
) &
PID_VITE=$!
echo -e "${GREEN}[VITE]    React available on your Wi-Fi -> ${LAN_ORIGIN}  (pid $PID_VITE)${NC}"

cleanup() {
    trap - INT TERM EXIT
    echo ""
    echo "Stopping local services..."
    kill "$PID_API" "$PID_QUEUE" "$PID_SCHEDULER" "$PID_VITE" 2>/dev/null || true
    wait "$PID_API" "$PID_QUEUE" "$PID_SCHEDULER" "$PID_VITE" 2>/dev/null || true
}

trap cleanup INT TERM EXIT

echo ""
echo -e "${YELLOW}If Windows Firewall blocks access, run this once in Administrator PowerShell:${NC}"
echo "  New-NetFirewallRule -DisplayName \"Brandshark Pulse Vite LAN\" -Direction Inbound -Action Allow -Protocol TCP -LocalPort ${VITE_PORT} -Profile Private -RemoteAddress LocalSubnet"
echo ""
echo -e "${YELLOW}Google Workspace sign-in will not work through a raw LAN IP callback.${NC}"
echo "Use a registered HTTPS hostname when authenticated Wi-Fi access is required."
echo ""
echo -e "${BLUE}All services are running. Press Ctrl+C to stop them.${NC}"

wait -n
