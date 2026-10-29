#!/bin/bash
set -e

ROOT="$(cd "$(dirname "$0")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}=== AdAudit Dev Server ===${NC}"

# Run migrations
echo -e "${YELLOW}Running migrations...${NC}"
cd "$BACKEND" && php artisan migrate --force
echo -e "${GREEN}Migrations done.${NC}"

# Start background processes
echo -e "${YELLOW}Starting services...${NC}"

php artisan serve --host=127.0.0.1 --port=8000 &
PID_API=$!
echo -e "${GREEN}[API]     Laravel → http://localhost:8000  (pid $PID_API)${NC}"

QUEUES="notifications,collection,audits,reports,ad-library,ad-library-analysis,default"
run_queue_worker() {
    while true; do
        if DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-1200}" php artisan queue:work --queue="$QUEUES" --timeout=1000 --sleep=3 --tries=3; then
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
echo -e "${GREEN}[QUEUE]   Supervised worker for all queues started (pid $PID_QUEUE)${NC}"

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
echo -e "${GREEN}[SCHED]   Supervised Laravel scheduler started (pid $PID_SCHEDULER)${NC}"

cd "$FRONTEND" && npm run dev &
PID_VITE=$!
echo -e "${GREEN}[VITE]    React   → http://localhost:5173  (pid $PID_VITE)${NC}"

echo ""
echo -e "${BLUE}All services running. Press Ctrl+C to stop all.${NC}"
echo ""

cleanup() {
    trap - INT TERM EXIT
    echo ""
    echo "Stopping..."
    kill "$PID_API" "$PID_QUEUE" "$PID_SCHEDULER" "$PID_VITE" 2>/dev/null || true
    wait "$PID_API" "$PID_QUEUE" "$PID_SCHEDULER" "$PID_VITE" 2>/dev/null || true
}

trap cleanup INT TERM EXIT

# Wait for any process to exit
wait -n
