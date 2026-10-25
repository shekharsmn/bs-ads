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
DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-1200}" php artisan queue:work --queue="$QUEUES" --timeout=1000 --sleep=3 --tries=3 &
PID_QUEUE=$!
echo -e "${GREEN}[QUEUE]   Worker for all queues started      (pid $PID_QUEUE)${NC}"

cd "$FRONTEND" && npm run dev &
PID_VITE=$!
echo -e "${GREEN}[VITE]    React   → http://localhost:5173  (pid $PID_VITE)${NC}"

echo ""
echo -e "${BLUE}All services running. Press Ctrl+C to stop all.${NC}"
echo ""

# Trap Ctrl+C and kill all children
trap "echo ''; echo 'Stopping...'; kill $PID_API $PID_QUEUE $PID_VITE 2>/dev/null; exit 0" INT TERM

# Wait for any process to exit
wait
