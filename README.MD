# Brandshark Pulse

Brandshark Pulse is an internal multi-client reporting and alert layer for Google Ads and Meta Ads. It collects lightweight daily account/campaign metrics every hour, evaluates inherited alert policies, sends Google Chat incident/recovery messages, and produces immutable weekly/monthly client-safe report links. The original deep audit and Meta creative-analysis workflows remain available as separate diagnostics.

## Architecture

- `backend/`: Laravel 12 API, queue jobs, scheduler, normalized metrics, alert engine, Google Chat delivery and report snapshots.
- `frontend/`: React/Vite operator console for clients, connections, performance, alert handling, rules and reports.
- `.graphify/`: local generated code graph; intentionally ignored by Git.

Credentials and Google Chat webhook URLs are encrypted at rest and omitted from API responses. Metric APIs return separate currency blocks and never sum unlike currencies.

## Local setup

1. Configure `backend/.env` from `backend/.env.example`.
2. Set Google Workspace SSO values:
   - `GOOGLE_WORKSPACE_CLIENT_ID`
   - `GOOGLE_WORKSPACE_CLIENT_SECRET`
   - `GOOGLE_WORKSPACE_REDIRECT_URI`
   - `GOOGLE_WORKSPACE_DOMAIN`
   - `FRONTEND_URL`
3. Configure Google Ads developer credentials in the existing Google Ads environment variables.
4. Reset and seed the disposable development database:

   ```bash
   cd backend
   php artisan migrate:fresh --seed
   ```

5. Run the API, worker and scheduler in separate terminals:

   ```bash
   php artisan serve
   php artisan queue:work --timeout=600 --tries=1
   php artisan schedule:work
   ```

6. Run the frontend:

   ```bash
   cd frontend
   npm install
   npm run dev
   ```

The first verified user from the configured Workspace domain becomes Admin. Later users default to Viewer until an Admin changes their role and client assignments.

## Operations

### Production deployments (existing BS Ads server)

Run as root inside `/opt/bs-ads`. The production script uses the existing
`.deployment/compose.yaml` and Dockerfiles; it does not provision a new server.

```bash
git pull --ff-only
bash deploy-prod.sh
```

This builds the checked-out backend and frontend before pausing BS Ads, backs up
the `bs_ads` database, applies pending migrations, refreshes alert templates and
caches, and starts the updated services. Builds use a dedicated cached builder
limited to one CPU and 768 MiB. The scraper is rebuilt only when its source changes
(or when there is no previous deployment record).

To apply `.env` changes or restart the currently deployed images without building
or running migrations:

```bash
bash deploy-prod.sh restart
```

Both commands synchronize `backend/.env` into the private runtime copy, preserve
persistent storage, and check web health and fresh worker/scheduler heartbeats.
Only the named services in the `bs-ads` Compose project are managed. Caddy,
MySQL, Docker itself, PM2, and other applications are not restarted or configured.

The script blocks overlapping runs and refuses to build tracked or untracked
source changes outside the server-owned `.deployment/` directory. Logs, database
backups, and the previous Compose/environment configuration are kept in
`.deployment/releases/`. On cutover failure it attempts
to restart the previous images and cached configuration. **Database migrations
are not automatically reversed**; a failed/incompatible migration may require
manual recovery using the saved database backup. It never uses `migrate:fresh`,
Compose `down`, volume deletion, or global cleanup commands.

- Hourly heartbeat: `php artisan performance:heartbeat`
- Force synchronous collection for diagnostics: `php artisan performance:heartbeat --sync`
- Scheduled report dispatcher: `php artisan reports:dispatch-due`
- Inspect registered schedules: `php artisan schedule:list`

## Meta Ad Library research

The optional Ad Library feature keeps authorization, saved searches, run history,
and results in Laravel while an isolated Python/Playwright container performs the
public Ad Library read. It is disabled by default.

1. Set a strong shared `AD_LIBRARY_SCRAPER_TOKEN` in the shell used by Docker and
   in `backend/.env`.
2. Start the private scraper service:

   ```bash
   docker compose -f compose.ad-library.yml up --build -d
   curl http://127.0.0.1:8090/health/ready
   ```

3. Run migrations, set `AD_LIBRARY_ENABLED=true`, and run a dedicated worker:

   ```bash
   cd backend
   php artisan migrate
   php artisan queue:work --queue=ad-library --timeout=900 --tries=2
   ```

The service permits one scrape at a time. Live scraping is not exercised in CI;
complete a reviewed canary and compliance review before enabling production use.
Upstream provenance and the update procedure are documented in
`services/meta-ad-library-scraper/UPSTREAM.md`.

### Stepwise content audits

The optional content-audit workflow snapshots up to 50 grouped creatives from a
completed Ad Library run. Enable it only after the base Ad Library feature is
configured:

Ad Library research and content audits are company-scoped, not client-scoped.
Each saved search identifies the company or brand and its relationship to the
business (client, competitor, lead, partner, or other), so research does not
require a client record or client assignment.

```dotenv
AD_LIBRARY_AUDITS_ENABLED=true
GEMINI_API_KEY=...
# OPENAI_API_KEY=...
# ANTHROPIC_API_KEY=...
```

Admins manage enabled models, capabilities, pricing, and the default selection in
**AI Model Settings**. Provider API keys remain in the backend environment. Models
are filtered by media capability; full-media audits require audio support.
Run the analysis queue independently so long AI work cannot block searches:

```bash
php artisan queue:work --queue=ad-library-analysis --timeout=900 --tries=3
```

Install `ffmpeg` on the analysis-worker host so visual audits can extract bounded
video-frame samples. Missing or unavailable media falls back to text and metadata
analysis and does not alter the stored source URLs.

The workflow crawls the homepage plus at most five relevant public, same-origin
HTML pages without running JavaScript, requires review of context and personas,
preserves individual row failures, and generates insights only after the creative grid is confirmed.
Treat its output as content analysis rather than evidence of ad performance.

Collection refreshes today plus the preceding 14 local account days. Deployments must keep both the queue worker and scheduler running. Google Chat webhooks are configured per client; client-visible spaces receive sanitized wording without internal notes.

## Verification

```bash
cd backend && php artisan test
cd frontend && npm run lint
cd frontend && npm run build
```

Backend tests cover Workspace-domain access, RBAC and client isolation, encryption/redaction, currency separation, rule operators, two-strike incident/recovery behavior, and audited alert actions.
