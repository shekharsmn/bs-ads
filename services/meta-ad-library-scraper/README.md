# Meta Ad Library scraper service

Internal, single-concurrency HTTP wrapper around the pinned Playwright scraper.

```bash
docker compose -f compose.ad-library.yml up --build -d
curl http://127.0.0.1:8090/health/ready
```

Set the same non-empty secret in `SCRAPER_SERVICE_TOKEN` and Laravel's
`AD_LIBRARY_SCRAPER_TOKEN`. The service must remain on a private network.

Live canary requests are intentionally not part of CI. Review Meta's current
terms and obtain the required compliance approval before enabling production use.
