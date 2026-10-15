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

- Hourly heartbeat: `php artisan performance:heartbeat`
- Force synchronous collection for diagnostics: `php artisan performance:heartbeat --sync`
- Scheduled report dispatcher: `php artisan reports:dispatch-due`
- Inspect registered schedules: `php artisan schedule:list`

Collection refreshes today plus the preceding 14 local account days. Deployments must keep both the queue worker and scheduler running. Google Chat webhooks are configured per client; client-visible spaces receive sanitized wording without internal notes.

## Verification

```bash
cd backend && php artisan test
cd frontend && npm run lint
cd frontend && npm run build
```

Backend tests cover Workspace-domain access, RBAC and client isolation, encryption/redaction, currency separation, rule operators, two-strike incident/recovery behavior, and audited alert actions.
