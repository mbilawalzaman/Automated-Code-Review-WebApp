"""Chunked result submission chooses begin/chunks/complete for large payloads."""

from __future__ import annotations

from unittest.mock import MagicMock, patch

from api_client import ApiClient


def test_submit_job_result_uses_single_shot_for_small_payload():
    client = ApiClient(api_url="https://example.test/api/v1/worker", token="t")
    payload = {
        "status": "passed",
        "summary": "ok",
        "findings": [{"title": "one"}],
    }
    with patch.object(client, "_post_with_retries", return_value=True) as mock_post:
        assert client.submit_job_result(7, payload) is True
        assert mock_post.call_count == 1
        assert mock_post.call_args.args[0].endswith("/jobs/7/result")


def test_submit_job_result_chunks_large_payload():
    client = ApiClient(api_url="https://example.test/api/v1/worker", token="t")
    findings = [{"title": f"f{i}", "severity": "low", "file_path": "a.py", "line_number": 1} for i in range(5)]
    payload = {
        "status": "passed_with_warnings",
        "summary": "many",
        "findings": findings,
        "result_single_shot_max": 2,
        "result_chunk_size": 2,
    }

    with patch.object(client, "_post_with_retries", return_value=True) as mock_post:
        assert client.submit_job_result(9, payload) is True
        urls = [call.args[0] for call in mock_post.call_args_list]
        assert urls[0].endswith("/jobs/9/result/begin")
        assert urls[1].endswith("/jobs/9/result/chunks")
        assert urls[2].endswith("/jobs/9/result/chunks")
        assert urls[3].endswith("/jobs/9/result/chunks")
        assert urls[4].endswith("/jobs/9/result/complete")
        # 5 findings / chunk_size 2 => 3 chunks + begin + complete
        assert mock_post.call_count == 5
