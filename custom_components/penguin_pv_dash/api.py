"""Connection helpers for PenguinPVDash."""

from __future__ import annotations

import asyncio
import base64
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import hmac
import json
from typing import Any
from urllib.parse import urlsplit, urlunsplit

from aiohttp import ClientError

from homeassistant.core import HomeAssistant
from homeassistant.helpers.aiohttp_client import async_get_clientsession

from .const import HEADER_DEV, HEADER_SIG, HEADER_TS


class PenguinPVDashConnectionError(Exception):
    """Base class for connection validation errors."""


class InvalidServerUrl(PenguinPVDashConnectionError):
    """Raised when the configured server URL is invalid."""


class CannotConnect(PenguinPVDashConnectionError):
    """Raised when the server cannot be reached."""


class InvalidAuth(PenguinPVDashConnectionError):
    """Raised when the API key or device ID is rejected."""


class InvalidServer(PenguinPVDashConnectionError):
    """Raised when the endpoint is not a PenguinPVDash server."""


@dataclass(frozen=True, slots=True)
class ServerTestResult:
    """Result returned by a successful connection test."""

    ingest_url: str
    version: str
    authentication_verified: bool


def normalize_ingest_url(value: Any) -> str:
    """Turn a server address or ingest URL into the canonical ingest URL.

    Accepted examples:
    - 10.10.4.122:8092
    - http://10.10.4.122:8092
    - https://example.org/pvdash
    - https://example.org/pvdash/api/ingest.php
    """
    raw = str(value or "").strip()
    if not raw:
        raise InvalidServerUrl

    if raw.startswith("//"):
        raw = "http:" + raw
    elif "://" not in raw:
        raw = "http://" + raw

    try:
        parsed = urlsplit(raw)
    except ValueError as err:
        raise InvalidServerUrl from err

    scheme = parsed.scheme.lower()
    if scheme not in {"http", "https"} or not parsed.netloc:
        raise InvalidServerUrl
    if parsed.username is not None or parsed.password is not None:
        raise InvalidServerUrl

    path = parsed.path or ""
    path = path.rstrip("/")
    lower_path = path.lower()

    if lower_path.endswith("/ingest.php"):
        ingest_path = path
    elif lower_path.endswith("/api"):
        ingest_path = path + "/ingest.php"
    elif path:
        ingest_path = path + "/api/ingest.php"
    else:
        ingest_path = "/api/ingest.php"

    return urlunsplit((scheme, parsed.netloc, ingest_path, "", ""))


def display_server_url(value: Any) -> str:
    """Return a user-friendly server address without the standard ingest suffix."""
    raw = str(value or "").strip()
    if not raw:
        return ""
    try:
        parsed = urlsplit(normalize_ingest_url(raw))
    except InvalidServerUrl:
        return raw

    suffix = "/api/ingest.php"
    path = parsed.path
    if path.lower().endswith(suffix):
        path = path[: -len(suffix)]
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def verification_url(ingest_url: str) -> str:
    """Return the non-writing verification endpoint for an ingest URL."""
    parsed = urlsplit(normalize_ingest_url(ingest_url))
    path = parsed.path
    if path.lower().endswith("/ingest.php"):
        path = path[: -len("ingest.php")] + "verify.php"
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def health_url(ingest_url: str) -> str:
    """Return the server health URL for an ingest URL."""
    parsed = urlsplit(normalize_ingest_url(ingest_url))
    path = parsed.path
    suffix = "/api/ingest.php"
    if path.lower().endswith(suffix):
        path = path[: -len(suffix)] + "/health.php"
    elif path.lower().endswith("/ingest.php"):
        api_dir = path[: -len("/ingest.php")]
        path = api_dir.rsplit("/api", 1)[0] + "/health.php"
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def signed_headers(raw_body: str, api_key: str, device_id: str, timestamp: int) -> dict[str, str]:
    """Build PenguinPVDash HMAC headers."""
    headers = {
        "Content-Type": "application/json",
        HEADER_TS: str(timestamp),
        HEADER_DEV: device_id,
    }
    if api_key:
        signature = hmac.new(
            api_key.encode("utf-8"),
            f"{timestamp}.{raw_body}".encode("utf-8"),
            hashlib.sha256,
        ).digest()
        headers[HEADER_SIG] = base64.b64encode(signature).decode("ascii")
    return headers


async def async_test_server(
    hass: HomeAssistant,
    server_url: str,
    api_key: str,
    device_id: str,
) -> ServerTestResult:
    """Validate the URL, PenguinPVDash endpoint, device ID and API key."""
    ingest_url = normalize_ingest_url(server_url)
    verify_url = verification_url(ingest_url)
    timestamp = int(datetime.now(timezone.utc).timestamp())
    payload = {
        "ts": timestamp,
        "device": device_id,
        "test": True,
    }
    raw_body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    headers = signed_headers(raw_body, api_key, device_id, timestamp)
    session = async_get_clientsession(hass)

    try:
        async with asyncio.timeout(10):
            async with session.post(verify_url, data=raw_body, headers=headers) as response:
                status = response.status
                text = await response.text()
    except (TimeoutError, ClientError, OSError) as err:
        raise CannotConnect from err

    if status in {401, 403}:
        raise InvalidAuth

    # Servers before 1.7.2 do not have /api/verify.php. In that case, still
    # accept a healthy PenguinPVDash server but mark authentication as unverified.
    if status == 404:
        try:
            async with asyncio.timeout(8):
                async with session.get(health_url(ingest_url)) as response:
                    health_status = response.status
                    health_text = await response.text()
        except (TimeoutError, ClientError, OSError) as err:
            raise CannotConnect from err

        if health_status >= 300:
            raise InvalidServer
        try:
            health_payload = json.loads(health_text)
        except (TypeError, ValueError) as err:
            raise InvalidServer from err
        if not isinstance(health_payload, dict) or health_payload.get("ok") is not True:
            raise InvalidServer
        return ServerTestResult(ingest_url, "< 1.7.2", False)

    if status >= 500:
        raise CannotConnect
    if status >= 300:
        raise InvalidServer

    try:
        result = json.loads(text)
    except (TypeError, ValueError) as err:
        raise InvalidServer from err

    if not isinstance(result, dict) or result.get("ok") is not True:
        raise InvalidServer
    if result.get("service") not in {None, "PenguinPVDash"}:
        raise InvalidServer

    return ServerTestResult(
        ingest_url=ingest_url,
        version=str(result.get("version") or "unknown"),
        authentication_verified=bool(result.get("authentication_verified", True)),
    )
