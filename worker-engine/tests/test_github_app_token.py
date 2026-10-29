"""Tests for GitHub App installation token refresh."""

from __future__ import annotations

import sys
import time
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import github_app_token as token_mod
from github_app_token import GitHubAppTokenProvider, clear_cached_github_token
from git_service import GitService


def test_get_token_uses_static_pat_when_app_not_configured():
    clear_cached_github_token()
    with patch.object(token_mod, "GITHUB_APP_ID", ""), patch.object(
        token_mod, "GITHUB_APP_INSTALLATION_ID", ""
    ), patch.object(token_mod, "GITHUB_APP_PRIVATE_KEY", ""), patch.object(
        token_mod, "GITHUB_TOKEN", "ghp_static_pat"
    ):
        provider = GitHubAppTokenProvider()
        assert provider.get_token() == "ghp_static_pat"


def test_installation_token_is_cached_until_near_expiry():
    provider = GitHubAppTokenProvider()
    provider.clear()

    fake_response = MagicMock()
    fake_response.status_code = 201
    fake_response.content = b'{"token":"ghs_one"}'
    fake_response.json.return_value = {
        "token": "ghs_one",
        "expires_at": "2099-01-01T00:00:00Z",
    }

    with patch.object(token_mod, "GITHUB_APP_ID", "123"), patch.object(
        token_mod, "GITHUB_APP_INSTALLATION_ID", "456"
    ), patch.object(
        token_mod, "GITHUB_APP_PRIVATE_KEY", "-----BEGIN PRIVATE KEY-----\\nABC\\n-----END PRIVATE KEY-----"
    ), patch.object(token_mod, "GITHUB_TOKEN", ""), patch.object(
        provider, "_mint_app_jwt", return_value="jwt.token.here"
    ), patch("github_app_token.requests.post", return_value=fake_response) as post:
        first = provider.get_token()
        second = provider.get_token()
        assert first == "ghs_one"
        assert second == "ghs_one"
        assert post.call_count == 1


def test_force_refresh_requests_new_token():
    provider = GitHubAppTokenProvider()
    provider.clear()
    provider._token = "ghs_old"
    provider._expires_at = time.time() + 3600

    fake_response = MagicMock()
    fake_response.status_code = 201
    fake_response.content = b'{"token":"ghs_new"}'
    fake_response.json.return_value = {
        "token": "ghs_new",
        "expires_at": "2099-01-01T00:00:00Z",
    }

    with patch.object(token_mod, "GITHUB_APP_ID", "123"), patch.object(
        token_mod, "GITHUB_APP_INSTALLATION_ID", "456"
    ), patch.object(
        token_mod, "GITHUB_APP_PRIVATE_KEY", "-----BEGIN PRIVATE KEY-----\\nABC\\n-----END PRIVATE KEY-----"
    ), patch.object(provider, "_mint_app_jwt", return_value="jwt"), patch(
        "github_app_token.requests.post", return_value=fake_response
    ) as post:
        token = provider.get_token(force_refresh=True)
        assert token == "ghs_new"
        assert post.call_count == 1


def test_expired_token_triggers_refresh():
    provider = GitHubAppTokenProvider()
    provider.clear()
    provider._token = "ghs_stale"
    provider._expires_at = time.time() - 10  # already expired

    fake_response = MagicMock()
    fake_response.status_code = 201
    fake_response.content = b'{"token":"ghs_fresh"}'
    fake_response.json.return_value = {
        "token": "ghs_fresh",
        "expires_at": "2099-01-01T00:00:00Z",
    }

    with patch.object(token_mod, "GITHUB_APP_ID", "123"), patch.object(
        token_mod, "GITHUB_APP_INSTALLATION_ID", "456"
    ), patch.object(
        token_mod, "GITHUB_APP_PRIVATE_KEY", "-----BEGIN PRIVATE KEY-----\\nABC\\n-----END PRIVATE KEY-----"
    ), patch.object(provider, "_mint_app_jwt", return_value="jwt"), patch(
        "github_app_token.requests.post", return_value=fake_response
    ) as post:
        assert provider.get_token() == "ghs_fresh"
        assert post.call_count == 1


def test_authenticate_url_injects_token():
    svc = GitService()
    with patch("git_service.get_github_token", return_value="ghs_abc"):
        url = svc._authenticate_url("https://github.com/acme/repo.git")
    assert url == "https://x-access-token:ghs_abc@github.com/acme/repo.git"
