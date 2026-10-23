import os
import unittest
from pathlib import Path

os.environ.setdefault("SCRAPER_SERVICE_TOKEN", "test-token")

from fastapi import HTTPException
from pydantic import ValidationError
import app as scraper_app


class ScraperServiceTest(unittest.TestCase):
    def test_health_routes_are_registered(self):
        paths = {route.path for route in scraper_app.app.routes}
        self.assertIn("/health/live", paths)
        self.assertIn("/health/ready", paths)

    def test_scrape_requires_authentication(self):
        with self.assertRaises(HTTPException) as context:
            scraper_app.authenticate(None)
        self.assertEqual(context.exception.status_code, 401)

    def test_rejects_arbitrary_or_incomplete_input(self):
        with self.assertRaises(ValidationError):
            scraper_app.ScrapeRequest(request_id="1", search_mode="advertiser", query="https://example.com")

    def test_details_limit_is_enforced(self):
        with self.assertRaises(ValidationError):
            scraper_app.ScrapeRequest(request_id="1", search_mode="keyword", query="shoes", details=True, max_results=51)

    def test_sanitizes_credentials(self):
        value = scraper_app.sanitize_log("proxy http://user:secret@example.com token=abc")
        self.assertNotIn("secret", value)
        self.assertNotIn("abc", value)

    def test_command_exposes_only_supported_search_arguments(self):
        request = scraper_app.ScrapeRequest(request_id="1", search_mode="keyword", query="running shoes", region="US", exact_phrase=True)
        command = scraper_app.build_command(request, Path("result.json"))
        self.assertIn("--query", command)
        self.assertIn("--exact-phrase", command)
        self.assertNotIn("--url", command)
        self.assertNotIn("--proxy", command)


if __name__ == "__main__":
    unittest.main()
