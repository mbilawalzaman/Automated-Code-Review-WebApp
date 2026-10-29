import time
import socket
import platform
import os
import requests
import logging
from typing import Dict, Any, Optional
from config import WORKER_API_URL, WORKER_API_TOKEN, WORKER_ID

logger = logging.getLogger("ApiClient")

RESULT_SINGLE_SHOT_MAX = int(os.getenv("RESULT_SINGLE_SHOT_MAX", "75"))
RESULT_CHUNK_SIZE = int(os.getenv("RESULT_CHUNK_SIZE", "100"))
RESULT_SUBMIT_TIMEOUT = int(os.getenv("RESULT_SUBMIT_TIMEOUT", "90"))


class ApiClient:
    def __init__(self, api_url: str = WORKER_API_URL, token: str = WORKER_API_TOKEN):
        self.api_url = api_url.rstrip("/")
        self.headers = {
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json"
        }

    def send_heartbeat(self, worker_id: str = WORKER_ID, payload: Optional[Dict[str, Any]] = None) -> bool:
        url = f"{self.api_url}/heartbeat"
        data = {
            "worker_id": worker_id,
            "hostname": socket.gethostname(),
            "os_info": f"{platform.system()} {platform.release()}",
            "python_version": platform.python_version(),
            "payload": payload or {}
        }
        try:
            res = requests.post(url, headers=self.headers, json=data, timeout=10)
            if res.status_code == 200:
                logger.debug(f"Heartbeat ping sent for worker '{worker_id}'.")
                return True
            else:
                logger.warning(f"Heartbeat ping returned HTTP {res.status_code}: {res.text}")
        except Exception as e:
            logger.warning(f"Failed to send heartbeat to VPS API: {e}")

        return False

    def fetch_next_job(self) -> Optional[Dict[str, Any]]:
        url = f"{self.api_url}/jobs/next"
        try:
            res = requests.post(url, headers=self.headers, timeout=15)
            if res.status_code == 200:
                data = res.json()
                if data.get("has_job") and data.get("job"):
                    return data["job"]
            elif res.status_code == 401:
                logger.error("API Authentication failed. Verify WORKER_API_TOKEN.")
            else:
                logger.warning(f"Worker API returned HTTP {res.status_code}: {res.text}")
        except Exception as e:
            logger.error(f"Failed to fetch next job from WebApp API: {e}")

        return None

    def submit_job_result(self, job_id: int, payload: Dict[str, Any], max_retries: int = 3) -> bool:
        findings = payload.get("findings") or []
        if not isinstance(findings, list):
            findings = []

        single_shot_max = int(
            payload.get("result_single_shot_max")
            or os.getenv("RESULT_SINGLE_SHOT_MAX", str(RESULT_SINGLE_SHOT_MAX))
        )
        chunk_size = int(
            payload.get("result_chunk_size")
            or os.getenv("RESULT_CHUNK_SIZE", str(RESULT_CHUNK_SIZE))
        )
        chunk_size = max(1, min(500, chunk_size))

        if len(findings) <= single_shot_max:
            return self._post_with_retries(
                f"{self.api_url}/jobs/{job_id}/result",
                payload,
                job_id,
                max_retries,
                label="single-shot result",
            )

        logger.info(
            f"Job #{job_id}: chunked result submit "
            f"({len(findings)} findings, chunk_size={chunk_size})."
        )

        meta = {k: v for k, v in payload.items() if k != "findings"}
        meta["findings_total"] = len(findings)
        meta["chunk_size"] = chunk_size

        if not self._post_with_retries(
            f"{self.api_url}/jobs/{job_id}/result/begin",
            meta,
            job_id,
            max_retries,
            label="result begin",
        ):
            return False

        for offset in range(0, len(findings), chunk_size):
            chunk_index = offset // chunk_size
            chunk_payload = {
                "chunk_index": chunk_index,
                "findings": findings[offset : offset + chunk_size],
            }
            if not self._post_with_retries(
                f"{self.api_url}/jobs/{job_id}/result/chunks",
                chunk_payload,
                job_id,
                max_retries,
                label=f"result chunk {chunk_index}",
            ):
                return False

        return self._post_with_retries(
            f"{self.api_url}/jobs/{job_id}/result/complete",
            {},
            job_id,
            max_retries,
            label="result complete",
        )

    def _post_with_retries(
        self,
        url: str,
        payload: Dict[str, Any],
        job_id: int,
        max_retries: int,
        label: str,
    ) -> bool:
        for attempt in range(1, max_retries + 1):
            try:
                res = requests.post(
                    url,
                    headers=self.headers,
                    json=payload,
                    timeout=RESULT_SUBMIT_TIMEOUT,
                )
                if res.status_code == 200:
                    logger.info(f"Successfully submitted {label} for Job #{job_id}.")
                    return True
                elif res.status_code == 409:
                    # complete may race with a prior success if Stage-2 (PDF/GitHub) timed out
                    # after the job was already marked terminal.
                    if "complete" in label:
                        logger.warning(
                            f"Job #{job_id} already terminal on {label} (HTTP 409). "
                            "Treating as successful finalize."
                        )
                        return True
                    logger.warning(
                        f"Job #{job_id} was timed out and finalized as terminal by VPS control plane. "
                        f"Aborting {label}."
                    )
                    return False
                else:
                    logger.warning(
                        f"Submit {label} for Job #{job_id} attempt {attempt}/{max_retries} failed. "
                        f"HTTP {res.status_code}: {res.text}"
                    )
            except Exception as e:
                logger.error(
                    f"Network error submitting {label} for Job #{job_id} "
                    f"on attempt {attempt}/{max_retries}: {e}"
                )

            if attempt < max_retries:
                backoff = attempt * 5
                logger.info(f"Retrying {label} for Job #{job_id} in {backoff} seconds...")
                time.sleep(backoff)

        logger.error(f"Permanently failed to submit {label} for Job #{job_id} after {max_retries} attempts.")
        return False
