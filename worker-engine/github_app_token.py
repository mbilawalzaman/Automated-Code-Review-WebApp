"""Mint and cache GitHub App installation tokens for git clone auth.

Installation tokens expire after ~1 hour. The worker must refresh them before
clone/fetch without requiring a process restart (SEC-004 / ops reliability).
"""

from __future__ import annotations

import logging
import os
import time
from datetime import datetime, timezone
from typing import Optional

import jwt
import requests

from config import (
    GITHUB_API_BASE,
    GITHUB_APP_ID,
    GITHUB_APP_INSTALLATION_ID,
    GITHUB_APP_PRIVATE_KEY,
    GITHUB_TOKEN,
)

logger = logging.getLogger("GitHubAppToken")

# Refresh a few minutes before GitHub's expiry to avoid mid-clone failures.
_REFRESH_SKEW_SECONDS = 300


class GitHubAppTokenProvider:
    def __init__(self) -> None:
        self._token: Optional[str] = None
        self._expires_at: float = 0.0

    def clear(self) -> None:
        self._token = None
        self._expires_at = 0.0

    def get_token(self, force_refresh: bool = False) -> str:
        """
        Return a usable clone token.

        Prefer a GitHub App installation token (auto-refreshed). Fall back to a
        static GITHUB_TOKEN (PAT) when App credentials are not configured.
        """
        if self._app_configured():
            if force_refresh or self._is_expired():
                self._refresh_installation_token()
            if self._token:
                return self._token
            raise RuntimeError(
                "GitHub App installation token could not be obtained. "
                "Check GITHUB_APP_ID / GITHUB_APP_PRIVATE_KEY / GITHUB_APP_INSTALLATION_ID."
            )

        if GITHUB_TOKEN:
            return GITHUB_TOKEN

        raise RuntimeError(
            "No GitHub credentials configured. Set GitHub App env vars "
            "(preferred) or GITHUB_TOKEN as a PAT fallback."
        )

    def _app_configured(self) -> bool:
        return bool(
            GITHUB_APP_ID
            and GITHUB_APP_INSTALLATION_ID
            and self._resolve_private_key()
        )

    def _is_expired(self) -> bool:
        if not self._token:
            return True
        return time.time() >= (self._expires_at - _REFRESH_SKEW_SECONDS)

    def _resolve_private_key(self) -> Optional[str]:
        raw = (GITHUB_APP_PRIVATE_KEY or "").strip()
        if not raw:
            return None

        # Support PEM inline (with escaped newlines) or a path to a .pem file.
        if "BEGIN" in raw:
            return raw.replace("\\n", "\n")

        if os.path.isfile(raw) and os.access(raw, os.R_OK):
            with open(raw, "r", encoding="utf-8") as fh:
                return fh.read()

        return None

    def _refresh_installation_token(self) -> None:
        private_key = self._resolve_private_key()
        if not private_key:
            raise RuntimeError("GitHub App private key is missing or unreadable.")

        app_jwt = self._mint_app_jwt(str(GITHUB_APP_ID), private_key)
        url = (
            f"{GITHUB_API_BASE.rstrip('/')}/app/installations/"
            f"{GITHUB_APP_INSTALLATION_ID}/access_tokens"
        )

        logger.info(
            "Requesting GitHub App installation token for installation %s",
            GITHUB_APP_INSTALLATION_ID,
        )

        response = requests.post(
            url,
            headers={
                "Authorization": f"Bearer {app_jwt}",
                "Accept": "application/vnd.github+json",
                "X-GitHub-Api-Version": "2022-11-28",
            },
            timeout=20,
        )

        if response.status_code >= 400:
            detail = (response.text or "")[:400]
            raise RuntimeError(
                f"GitHub installation token request failed "
                f"(HTTP {response.status_code}): {detail}"
            )

        payload = response.json() if response.content else {}
        token = payload.get("token")
        if not token:
            raise RuntimeError("GitHub installation token response missing 'token'.")

        expires_at = self._parse_expires_at(payload.get("expires_at"))
        self._token = str(token)
        self._expires_at = expires_at
        logger.info(
            "GitHub App installation token refreshed (expires_at=%s)",
            datetime.fromtimestamp(expires_at, tz=timezone.utc).isoformat(),
        )

    def _mint_app_jwt(self, app_id: str, private_key: str) -> str:
        now = int(time.time())
        payload = {
            "iat": now - 60,
            "exp": now + (10 * 60),
            "iss": app_id,
        }
        return jwt.encode(payload, private_key, algorithm="RS256")

    def _parse_expires_at(self, raw) -> float:
        if isinstance(raw, str) and raw.strip():
            try:
                # GitHub returns e.g. 2026-08-01T01:00:00Z
                normalized = raw.replace("Z", "+00:00")
                return datetime.fromisoformat(normalized).timestamp()
            except ValueError:
                logger.warning("Unable to parse expires_at=%r; defaulting to 50 minutes", raw)
        # Installation tokens last ~60 minutes; stay under that.
        return time.time() + (50 * 60)


_provider = GitHubAppTokenProvider()


def get_github_token(force_refresh: bool = False) -> str:
    return _provider.get_token(force_refresh=force_refresh)


def clear_cached_github_token() -> None:
    _provider.clear()
