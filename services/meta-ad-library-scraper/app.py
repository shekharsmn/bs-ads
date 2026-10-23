from __future__ import annotations

import asyncio
import hmac
import json
import logging
import os
import re
import signal
import sys
import tempfile
from pathlib import Path
from typing import Literal

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field, model_validator

SCHEMA_VERSION = 1
MAX_RESPONSE_BYTES = 25 * 1024 * 1024
SCRAPE_TIMEOUT_SECONDS = 720
UPSTREAM = Path(__file__).parent / "upstream"
SCRAPER = UPSTREAM / "playwright_scraper.py"
TOKEN = os.environ.get("SCRAPER_SERVICE_TOKEN", "")
_lock = asyncio.Lock()
_log = logging.getLogger("meta-ad-library-scraper")


class ScrapeRequest(BaseModel):
    request_id: str = Field(min_length=1, max_length=100, pattern=r"^[A-Za-z0-9._:-]+$")
    search_mode: Literal["keyword", "advertiser"]
    query: str | None = Field(default=None, min_length=1, max_length=255)
    advertiser_page_id: str | None = Field(default=None, pattern=r"^\d+$", max_length=64)
    region: str = Field(default="ALL", pattern=r"^(ALL|[A-Z]{2})$")
    active_status: Literal["active", "inactive", "all"] = "active"
    ad_type: Literal["all", "political_and_issue_ads"] = "all"
    exact_phrase: bool = False
    details: bool = False
    max_results: int = Field(default=100, ge=1, le=500)

    @model_validator(mode="after")
    def validate_search(self):
        if self.search_mode == "keyword" and not self.query:
            raise ValueError("query is required for keyword searches")
        if self.search_mode == "advertiser" and not self.advertiser_page_id:
            raise ValueError("advertiser_page_id is required for advertiser searches")
        if self.search_mode == "advertiser" and self.exact_phrase:
            raise ValueError("exact_phrase applies only to keyword searches")
        if self.details and self.max_results > 50:
            raise ValueError("detailed searches are limited to 50 ads")
        return self


def authenticate(authorization: str | None = Header(default=None)) -> None:
    if not TOKEN:
        raise HTTPException(status_code=503, detail="Service token is not configured")
    if not authorization or not hmac.compare_digest(authorization, f"Bearer {TOKEN}"):
        raise HTTPException(status_code=401, detail="Unauthorized")


app = FastAPI(title="Meta Ad Library Scraper", version="1.0.0", docs_url=None, redoc_url=None)


@app.get("/health/live")
async def live():
    return {"status": "ok"}


@app.get("/health/ready")
async def ready():
    if not TOKEN or not SCRAPER.is_file():
        raise HTTPException(status_code=503, detail="Service is not configured")
    return {"status": "ready", "upstream_commit": "0f3c0df0bb5c282b0827430886a7e36bda9a8374"}


@app.post("/v1/scrapes", dependencies=[Depends(authenticate)])
async def scrape(request: ScrapeRequest):
    if _lock.locked():
        raise HTTPException(status_code=429, detail="A scrape is already running", headers={"Retry-After": "60"})

    async with _lock:
        result = await run_scraper(request)
        encoded = json.dumps(result, separators=(",", ":")).encode("utf-8")
        if len(encoded) > MAX_RESPONSE_BYTES:
            raise HTTPException(status_code=502, detail="Scraper response exceeded 25 MB")
        return result


async def run_scraper(request: ScrapeRequest) -> dict:
    with tempfile.TemporaryDirectory(prefix="ad-library-") as temp_dir:
        output = Path(temp_dir) / "ads.json"
        command = build_command(request, output)

        process = await asyncio.create_subprocess_exec(
            *command,
            cwd=temp_dir,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            start_new_session=True,
        )
        try:
            _stdout, stderr = await asyncio.wait_for(process.communicate(), timeout=SCRAPE_TIMEOUT_SECONDS)
        except asyncio.TimeoutError:
            terminate_process_group(process)
            await process.communicate()
            return response(request.request_id, "failed", "timeout", [], error="The scrape exceeded 12 minutes.")

        ads = read_json(output, [])
        metadata = read_json(Path(f"{output}.meta.json"), {})
        if not metadata:
            metadata = read_json(Path(f"{output}.latest_attempt.meta.json"), {})

        upstream_status = metadata.get("status")
        status = {
            "complete": "complete",
            "partial": "partial",
            "empty": "empty",
            "blocked": "blocked",
        }.get(upstream_status, "failed")
        if process.returncode == 0 and ads and status == "failed":
            status = "complete"

        error = None
        if status in {"failed", "blocked"}:
            error = sanitize_log(stderr.decode("utf-8", errors="replace")[-4000:]) or "The scraper did not complete."

        safe_metadata = {key: value for key, value in metadata.items() if key not in {"url", "urls", "searches", "output_sha256"}}
        return response(
            request.request_id,
            status,
            metadata.get("stop_reason") or f"exit_{process.returncode}",
            ads if isinstance(ads, list) else [],
            reported_total=metadata.get("total_results"),
            capped=bool(metadata.get("capped", False)),
            metadata=safe_metadata,
            error=error,
        )


def build_command(request: ScrapeRequest, output: Path) -> list[str]:
    command = [
        sys.executable, str(SCRAPER),
        "--region", request.region,
        "--active-status", request.active_status,
        "--ad-type", request.ad_type,
        "--max-results", str(request.max_results),
        "--format", "json",
        "--out", str(output),
        "--allow-empty",
    ]
    if request.search_mode == "keyword":
        command.extend(["--query", request.query or ""])
        if request.exact_phrase:
            command.append("--exact-phrase")
    else:
        command.extend(["--page-id", request.advertiser_page_id or ""])
    if request.details:
        command.append("--details")
    return command


def terminate_process_group(process) -> None:
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except (AttributeError, ProcessLookupError, PermissionError):
        process.kill()


def response(request_id: str, status: str, stop_reason: str, ads: list, *, reported_total=None, capped=False, metadata=None, error=None) -> dict:
    return {
        "schema_version": SCHEMA_VERSION,
        "request_id": request_id,
        "status": status,
        "stop_reason": stop_reason,
        "reported_total": reported_total,
        "capped": capped,
        "ads": ads,
        "metadata": metadata or {},
        "error": error,
    }


def read_json(path: Path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default


def sanitize_log(value: str) -> str:
    value = re.sub(r"(?i)(https?://)([^\s/@:]+):([^\s/@]+)@", r"\1[redacted]@", value)
    value = re.sub(r"(?i)(bearer|token|key|password)[=: ]+\S+", r"\1=[redacted]", value)
    return value.strip()[:1000]
