# Upstream source

The files under `upstream/` are selectively vendored from
[`2scraper/facebook-ads-scraper`](https://github.com/2scraper/facebook-ads-scraper)
at commit `0f3c0df0bb5c282b0827430886a7e36bda9a8374`.

Only the Playwright execution path and modules imported by it are included.
The upstream MIT license is preserved at `upstream/LICENSE`.

To update, review the upstream changelog and tests, replace the selected files,
record the new full commit SHA here, rebuild the image, run the service tests,
and complete an opt-in live canary before enabling it.
