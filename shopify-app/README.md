# SharkAds Shopify application

For local startup, Shopify CLI commands and production URLs, use the [launch guide](LAUNCH.md).

The embedded merchant UI and Shopify registration templates live here. All API calls, credentials, synchronization and reporting run in the existing Laravel backend. This directory does not require a production Node server.

## Local development

1. Install backend dependencies with Composer and migrate a disposable local database using `php artisan migrate`. Do not use `migrate:fresh` on real data.
2. In this directory run `npm ci`, then `npm run build`. Assets and the Vite manifest are generated into `backend/public/shopify/`, which is ignored by Git.
3. For iterative work use `npm run build -- --watch` alongside the existing Laravel/Vite development servers. The agency Vite proxy forwards `/shopify` to Laravel. `npm run dev` is available for asset tooling; the authenticated application shell is always Laravel's `/shopify/app/{registration}`.
4. Shopify is enabled by default; no extra environment setting is required. A store starts importing only after its registration, permissions and installation are configured. No credentials are committed, and this implementation does not edit an existing `.env`.
5. Run `php artisan queue:work --queue=shopify-sync --timeout=90 --tries=5` and `php artisan schedule:work`. The existing local `deploy.sh` also consumes this queue.
6. Real embedded installation needs a development Shopify registration, an authorized development store, and an HTTPS tunnel forwarding to Laravel. Never expose a development database or use production secrets in fixtures.

## Registration and installation

Create each registration through Shopify CLI or Shopify's Dev Dashboard. Copy the appropriate TOML template to an ignored `shopify.app.NAME-local.toml`, fill its public client ID, URLs and registration slug, then release it using Shopify CLI (`shopify app deploy --config NAME-local --allow-updates`). Shopify CLI deployment releases app configuration; it does not deploy Laravel or build the production Docker images.

Configure the existing custom registration privately in the Laravel backend, outside the agency interface:

```bash
cd backend
php artisan shopify:configure skin-mumma --client=1 --name="SharkAds - Skin Mumma" --shopify-client-id=SHOPIFY_CLIENT_ID --install-url="SHOPIFY_GENERATED_INSTALL_URL" --data-access-confirmed
```

The command prompts for the client secret with hidden input. Do not put secrets in command arguments, chat, screenshots or source control. Confirm the local client ID and actual Shopify data access before running it. This configures BS Ads to use the existing Shopify app; it does not create another Shopify registration. The optional admin registration API remains available for operator automation.

`data_approved` is an operator attestation after checking applicable protected order-data access, not a mechanism to obtain approval. The integration requests only `read_orders` and imports orders created within the last 60 days. Older-order access and its separate permission request are outside the product scope. No write scope or customer contact fields are requested.

App Home: `https://sharkads.brandshark.in/shopify/app/{slug}`. Webhooks: `https://sharkads.brandshark.in/api/shopify/webhooks/{slug}`. Both URLs share the existing domain for every registration.

Follow the Shopify-hosted install link. After permission approval the merchant dashboard authenticates with App Bridge and an expiring offline token. No agency Google account is needed. The client's Connections tab contains a compact Shopify card and a single Connect Shopify button. It opens the configured installation link in a new tab, keeping BS Ads open. After verified installation, a custom registration automatically associates the store with its preassigned client; there are no credentials or manual linking codes in the client interface. Public registrations retain explicit two-party linking and never infer an agency association from merchant identity. One store can belong to only one client; custom installation refuses to replace another store's existing association.

## Data and operations

- `shopify:maintain` queues incremental work; `--kind=recent` and `--kind=reconcile` perform bounded rescans. Scheduling is registered in Laravel.
- History exports persist operation IDs and checkpoints. API pages and sales snapshots are resumable; the old snapshot stays visible until the replacement completes. Nested agreement/sale pagination is not truncated at the first page.
- Net merchandise revenue uses product sales activity after discounts and excluding tax/shipping. Reversals use their activity date. Payment receipts and platform-attributed purchases are different metrics.
- Real-store reports exclude test, deleted and cancelled orders, but retain unpaid/COD and refunded orders. Stores verified by Shopify as development stores include test orders and are labelled Development store. Order dates use store-local processed time, falling back to creation time. Last 7 days and Last 30 days include today; Yesterday remains a closed day.
- `shop_hooks` is a durable, signature-verified inbox. Stored payloads contain only operational IDs, are encrypted, and processed/non-privacy payloads are purged after seven days. Failed privacy payloads may be retained up to 30 days; normalized requests and redaction tombstones remain available for recovery. Pending expired records become visible failures rather than silently retrying without payloads.
- `shop_privacy` records export/redaction requests. Merchants can retrieve prepared exports through their authenticated privacy endpoints. Exports expire after 24 hours. Customer redactions leave order-ID tombstones preventing replay from recreating records.
- Monitor failed runs/hooks, `shopify` worker heartbeats, incomplete history, stale `last_synced_at` and registration errors. A quiet store alone is not proof of disconnection.
- `POST /api/clients/{client}/shopify/refresh` reuses active work. After a failed run the next request starts a replacement import; checkpoints of the failed run remain available for diagnosis.
- `PATCH /api/shopify/registrations/{id}` updates approval/enabled state. Coordinate credential rotation in Shopify and reconnect installations. A secret update retains the preceding signing secret during the transition; after revoking it in Shopify, PATCH `retire_previous_secret=true` to remove that verification key. Do not leave retired secrets trusted indefinitely; do not log or send tokens to support.

## Public release and migration

Use the public template for a separate public registration pointing to the same code. The public merchant UI is a usable free dashboard, not an agency-login redirect. Listing visibility may be limited; review is still required.

Before submission obtain applicable order/data approvals, provide accurate listing screenshots and installation instructions, reviewer access without SSO obstacles, support contact `design@brandshark.com`, emergency contact and truthful privacy/retention disclosures. Recheck current Shopify requirements at submission. No claim of Shopify approval is made by passing automated tests.

To migrate a pilot, install the public registration and import its available 60-day history under the new authorization. Only after successful completion does BS Ads activate it and supersede the old credentials. The replacement reacquires the supported 60-day window. Verify totals before uninstalling the old app; old-app privacy requests are honored by acquisition lineage. Do not automatically transfer old tokens or assume approval transfers across registrations.

## Verification

```bash
# Existing backend, synthetic/in-memory tests
cd backend
php artisan test
# Dedicated MySQL container/database only; see tests/README.md
php vendor/bin/phpunit -c phpunit.mysql.xml

cd ../shopify-app
npm test
npm run lint
npm run build

cd ../frontend
npm test
npm run lint
npm run build
```

The CI workflow runs the SQLite regression suite, real MySQL migration suite, both frontend suites/builds and isolated deployment tests. Real Shopify managed installation, approval state, versioned query execution, browser framing/App Bridge, and uninstall must additionally be checked on an authorized development store before enabling live clients.

## Backup restoration

Keep BS Ads in maintenance mode during SQL restoration. Preserve its private storage volume and APP_KEY. Run `php artisan shopify:replay-privacy` before returning traffic; this reapplies the encrypted redaction ledger retained outside SQL backups. Production deployment also runs this command before leaving maintenance. The dedicated `bs-ads-backup-retention` cron entry expires only release `database.sql` files under `/opt/bs-ads/.deployment/releases`; audit any older/manual backups separately before public launch. Do not restore a SQL backup over the independent redaction ledger.

To replay a retained failed Shopify event, run `php artisan shopify:retry-event EVENT_ID`. This command targets only that inbox event; it never runs queue-wide retry commands.
