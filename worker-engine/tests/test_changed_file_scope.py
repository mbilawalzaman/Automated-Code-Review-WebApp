"""Changed-file scope must use commit paths, not empty merge-base → full-repo."""

from __future__ import annotations

from unittest.mock import MagicMock, patch

from git_service import GitService


def test_list_changed_files_uses_commit_paths_when_merge_base_empty():
    svc = GitService(base_workspace_dir="/tmp/scanner-test-scope")

    with (
        patch("git_service.os.path.exists", return_value=True),
        patch.object(svc, "_name_only_against_base", return_value=[]),
        patch.object(svc, "_name_only_commit_paths", return_value=["app/Models/User.php", "tests/UserTest.php"]) as commit_paths,
    ):
        files = svc.list_changed_files("/tmp/ws", base_branch="main", for_pull_request=False)

    assert files == ["app/Models/User.php", "tests/UserTest.php"]
    commit_paths.assert_called_once()


def test_list_changed_files_empty_list_is_resolved_not_none():
    svc = GitService(base_workspace_dir="/tmp/scanner-test-scope")

    with (
        patch("git_service.os.path.exists", return_value=True),
        patch.object(svc, "_name_only_against_base", return_value=[]),
        patch.object(svc, "_name_only_commit_paths", return_value=[]),
    ):
        files = svc.list_changed_files("/tmp/ws", base_branch="main", for_pull_request=False)

    assert files == []


def test_list_changed_files_returns_none_when_not_a_git_repo():
    svc = GitService(base_workspace_dir="/tmp/scanner-test-scope")
    with patch("git_service.os.path.exists", return_value=False):
        assert svc.list_changed_files("/tmp/ws") is None


def test_parse_name_only_filters_noise():
    paths = GitService._parse_name_only("app/a.php\n\ncommit abc\nREADME.md\n")
    assert paths == ["app/a.php", "README.md"]


def test_api_client_treats_complete_409_as_success():
    from api_client import ApiClient

    client = ApiClient(api_url="https://example.test/api/v1/worker", token="t")
    response = MagicMock(status_code=409, text="already finalized")
    with patch("api_client.requests.post", return_value=response):
        assert client._post_with_retries(
            "https://example.test/api/v1/worker/jobs/1/result/complete",
            {},
            1,
            max_retries=1,
            label="result complete",
        ) is True
