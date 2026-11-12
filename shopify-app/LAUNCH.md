# Launching the SharkAds Shopify app

This guide covers local startup, Shopify CLI, client installation and production URLs. Commands use Shopify CLI 5. The Shopify frontend lives in `shopify-app/`; Laravel handles authentication, credentials and imports.

## 1. Know which app you are working on

Run Shopify CLI commands from `D:\code\bs-ads\shopify-app`.

| App | Local configuration file | CLI `--config` value | Backend registration slug |
| --- | --- | --- | --- |
| SharkAds – Development | `shopify.app.development-local.toml` | `development-local` | `development` |
| SharkAds – Skin Mumma | `shopify.app.skin-mummalocal.toml` | `skin-mummalocal` | `skin-mumma` |

These machine-specific files are ignored by Git. Keep the development registration pointed at the local tunnel and use the live registration for production.

The existing test store is `sharkads-testing.myshopify.com`. The live Skin Mumma store is `skinmumma.myshopify.com`.

The scope is **`read_orders`**, covering orders created within the last **60 days**. No older-order permission is part of this setup.

## 2. Start locally

Start MySQL in XAMPP. Use the existing `backend/.env` with your local database and Google-login settings. Shopify is enabled by default.

After pulling code or installing dependencies, run:

```powershell
cd D:\code\bs-ads\backend
composer install
php artisan config:clear
php artisan migrate

cd ..\frontend
npm ci

cd ..\shopify-app
npm ci
npm run build
```

Then run each command below in a **separate PowerShell terminal**, keeping all five open:

| Terminal | Folder | Command |
| --- | --- | --- |
| Laravel | `D:\code\bs-ads\backend` | `php artisan serve --host=127.0.0.1 --port=8000` |
| BS Ads frontend | `D:\code\bs-ads\frontend` | `npm run dev` |
| Shopify worker | `D:\code\bs-ads\backend` | `php artisan queue:work --queue=shopify-sync --timeout=90 --tries=5` |
| Scheduler | `D:\code\bs-ads\backend` | `php artisan schedule:work` |
| HTTPS tunnel | Any folder | `ngrok http 8000` |

Open BS Ads at **http://localhost:5173**. Shopify uses the **HTTPS forwarding URL** printed by ngrok, which forwards to Laravel on port 8000.

When editing the merchant dashboard, rebuild with `npm run build` in `shopify-app/`, or keep `npm run build -- --watch` running in another terminal. There is no separate production Node server for this frontend.

## 3. Use Shopify CLI

Check the CLI, or install it if missing:

```powershell
shopify version
# Only if not installed:
npm install -g @shopify/cli@latest
```

To link an existing registration on a new machine, or create a new client registration:

```powershell
cd D:\code\bs-ads\shopify-app
shopify app config link --file-name client-local
```

Complete the Shopify login prompt, select the correct organization, then choose **connect to an existing app** or **create a new app** as appropriate. This creates `shopify.app.client-local.toml`. Use a new filename instead of overwriting an existing configuration.

For a new registration, copy the scopes, managed-install settings and webhook subscriptions from `shopify.app.custom.toml` into the generated file. Keep the generated Client ID and set the registration slug in its URLs.

Common commands, using the existing development app:

```powershell
shopify app info --config development-local
shopify app config validate --config development-local
shopify app deploy --config development-local --allow-updates
```

Always pass `--config` so the command targets the intended app. **`shopify app deploy` publishes Shopify settings and a new app version. It does not deploy Laravel, the BS Ads web app or the database.**

For a new test store, these commands list existing dev stores and create one in Brandshark. Use a new name; the current SharkAds Testing store already exists.

```powershell
shopify store list --organization-id 129002739 --type dev
shopify store create dev --organization-id 129002739 --name "SharkAds Testing 2" --plan basic --country IN --demo-data
```

## 4. Set the local URLs and install

For the development registration, edit `shopify.app.development-local.toml`. Replace the example hostname below with the current ngrok hostname.

| Setting in the TOML file | Development value |
| --- | --- |
| `application_url` | `https://YOUR-TUNNEL.ngrok-free.dev/shopify/app/development` |
| `[auth] redirect_urls` | `["https://YOUR-TUNNEL.ngrok-free.dev/shopify/app/development"]` |
| **Every** webhook subscription `uri` | `https://YOUR-TUNNEL.ngrok-free.dev/api/shopify/webhooks/development` |

Keep `embedded = true`, `use_legacy_install_flow = false`, `scopes = "read_orders"` and webhook `api_version = "2026-10"`. Set `[build] automatically_update_urls_on_dev = false` to keep these explicit paths intact.

If ngrok changes its hostname, update all three settings above and run the validate/deploy commands again.

For a new Partner-owned custom registration:

1. Open the existing app in [Shopify Dev Dashboard](https://dev.shopify.com/).
2. Select **Distribution → Custom distribution** and enter the target store's `myshopify.com` domain.
3. Generate its installation link. Confirm applicable protected order-data access; customer contact fields are not needed.
4. Publish the app configuration successfully **before** opening the installation link.

If Distribution is absent because the app belongs to the merchant's organization, use its **Installs → Install app** flow instead. Distribution is selected once per registration.

Configure that existing registration in the **local Laravel backend**. For example, the current testing client has ID `2`:

```powershell
cd D:\code\bs-ads\backend
php artisan shopify:configure development --client=2 --name="SharkAds - Development" --shopify-client-id=YOUR_SHOPIFY_CLIENT_ID --install-url="YOUR_SHOPIFY_INSTALL_URL" --data-access-confirmed
```

Replace the placeholder values. The command securely prompts for the **Shopify client secret**. The final flag confirms you have checked the registration's data access; it does not grant Shopify permission. Credentials are encrypted in the backend database, not entered in the client-facing screen.

Open the installation link and approve access. Then launch the app **inside Shopify admin** to establish the connection and start importing. An already-installed app usually needs reopening and, if requested, approval of updated permissions rather than reinstallation.

In BS Ads, open **Clients → the ecommerce client → Connections → Shopify**. Connect Shopify opens a new tab so the original BS Ads page stays open. A verified custom installation connects to its preassigned client automatically. One client has one store; replacing it requires disconnecting the existing store first.

## 5. Switch a live client to production

### Deploy BS Ads first

Once the code is committed and pushed, run on the production server:

```bash
cd /opt/bs-ads
git pull --ff-only
bash deploy-prod.sh
```

The script builds both frontends, runs migrations and starts the BS Ads services, including its Shopify worker and scheduler. Use the project's existing Compose configuration. Do not restart shared Caddy, MySQL, Docker or another application's process manager.

For new code, use the full command above. `bash deploy-prod.sh restart` only reloads configuration and existing images; it skips builds and migrations.

Confirm the production `backend/.env` uses:

```dotenv
APP_URL=https://sharkads.brandshark.in
FRONTEND_URL=https://sharkads.brandshark.in
```

### Point the live Shopify registration at production

On your computer, prepare an ignored production configuration from the custom template:

```powershell
cd D:\code\bs-ads\shopify-app
Copy-Item shopify.app.custom.toml shopify.app.skin-mumma-prod-local.toml
```

In that file, enter the **existing live app's Client ID and name**, and replace every `REPLACE_WITH_REGISTRATION_SLUG` with `skin-mumma`.

The resulting production URLs must be:

| Purpose | URL |
| --- | --- |
| App URL / `application_url` | `https://sharkads.brandshark.in/shopify/app/skin-mumma` |
| Allowed redirection URL / `[auth] redirect_urls` | `https://sharkads.brandshark.in/shopify/app/skin-mumma` |
| All lifecycle, order and privacy webhook `uri` values | `https://sharkads.brandshark.in/api/shopify/webhooks/skin-mumma` |
| Privacy policy, where requested by Shopify | `https://sharkads.brandshark.in/privacy-policy` |

For another client, replace `skin-mumma` with that registration's slug everywhere. The slug must match the backend configuration command. Keep the webhook topics from the template; both webhook subscription groups need the production URI.

Validate and check the app identity now; publish after configuring the production backend in the next step:

```powershell
shopify app info --config skin-mumma-prod-local
shopify app config validate --config skin-mumma-prod-local
```

### Configure the production backend, then publish

Local registration records are not automatically copied to production. Create/select the ecommerce client in production BS Ads and get its ID from its client URL. **Production client IDs can differ from local IDs.**

On the server, run this command, replacing `42` and the Shopify placeholders with the actual production values:

```bash
cd /opt/bs-ads
docker compose -p bs-ads -f .deployment/compose.yaml exec --user 33:33 php \
  php artisan shopify:configure skin-mumma --client=42 \
  --name="SharkAds - Skin Mumma" --shopify-client-id=YOUR_SHOPIFY_CLIENT_ID \
  --install-url="YOUR_SHOPIFY_INSTALL_URL" --data-access-confirmed
```

Enter the existing live app's secret at the hidden prompt. Keep the production `APP_KEY` stable because it encrypts credentials.

Now publish the production URLs from your computer:

```powershell
cd D:\code\bs-ads\shopify-app
shopify app deploy --config skin-mumma-prod-local --allow-updates
```

Open/install the app through Shopify, then check the production client's Connections card for the linked store, successful synchronization and expected totals. Keep the development registration on ngrok for future testing.

## 6. Quick checks and common issues

| What you see | What to check |
| --- | --- |
| Shopify's default placeholder page | The correct app configuration must be successfully published with the real App URL. |
| App works only while your computer is running | It still points to ngrok. Publish the production URLs for the live registration. |
| Store connected but new order is missing | Check the selected dates, worker/scheduler and last sync. A draft order must be completed before import. |
| Dev-store order marked as test | Test orders count only for stores Shopify verifies as development stores. Real stores exclude them. |
| Configuration rejected for protected data | Check the registration's distribution and applicable protected-data access in Shopify; retry the release afterward. |

For ordinary code changes, rebuild/deploy BS Ads. Publish Shopify configuration again only when its URLs, scopes, webhook subscriptions or other Shopify settings change.

## References

- [Shopify CLI: link or create an app registration](https://shopify.dev/docs/api/shopify-cli/app/app-config-link)
- [Shopify CLI: publish app configuration](https://shopify.dev/docs/api/shopify-cli/app/app-deploy)
- [Shopify: select a distribution method](https://shopify.dev/docs/apps/launch/distribution/select-distribution-method)
