# Integration verification

Backend feature tests use the existing SQLite in-memory configuration and fake external HTTP. Production migrations are additionally checked on real MySQL 8, using only the deliberately fixed loopback test database:

```bash
docker run -d --name bs-ads-shopify-test-mysql --memory=512m --cpus=1 \
  -p 127.0.0.1:43316:3306 -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
  -e MYSQL_DATABASE=bs_ads_shopify_test -e MYSQL_USER=bs_ads_test \
  -e MYSQL_PASSWORD=local-disposable-only mysql:8.0.46

# Wait for mysqladmin ping, then run these sequentially, never concurrently:
cd backend
php vendor/bin/phpunit -c phpunit.mysql.xml
```

The MySQL suite guards environment, host, port, database and restricted username before it uses `migrate:fresh`. It does not use production `.env` database settings. Credentials above are public, disposable test values, not application secrets. Remove only this named test container after verification.

`test_deploy_prod.py` must run on Linux as root. It supplies fake Docker/Git/MySQL/network executables and confines filesystem mutations to a temporary directory. It never connects to the real Docker daemon or deploys an application. Run via `sudo python3 tests/test_deploy_prod.py`, or in an isolated Linux container with a read-only checkout mount.

Browser fixtures test client navigation and UI isolation, not real Shopify installation. Run the agency Playwright tests after building the frontend. Real App Bridge/permission/installation checks require the developer's Shopify development store and remain a separate release gate.
