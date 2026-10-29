import time
import logging
from config import POLL_INTERVAL_SECONDS
from worker_info import WorkerInfo
from api_client import ApiClient
from scan_orchestrator import ScanOrchestrator

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s'
)
logger = logging.getLogger("WorkerEngineDaemon")

HEARTBEAT_INTERVAL_SECONDS = 30

class WorkerEngineDaemon:
    def __init__(self):
        logger.info("Initializing Worker Engine Daemon...")
        self.worker_info = WorkerInfo()
        logger.info(f"Cached CLI Tool Versions: {self.worker_info.to_dict()}")

        self.api_client = ApiClient()
        self.orchestrator = ScanOrchestrator(self.worker_info)
        self.last_heartbeat_time = 0

    def send_heartbeat_if_due(self):
        now = time.time()
        if now - self.last_heartbeat_time >= HEARTBEAT_INTERVAL_SECONDS:
            try:
                payload = {"tools": self.worker_info.to_dict()}
                self.api_client.send_heartbeat(payload=payload)
                self.last_heartbeat_time = now
            except Exception as e:
                logger.warning(f"Heartbeat dispatch failed silently: {e}")

    def run(self):
        logger.info("Worker Engine Daemon active and polling for review jobs...")
        logger.info(f"Target API Base URL: {self.api_client.api_url}")
        logger.info(f"Polling Interval: {POLL_INTERVAL_SECONDS} seconds")

        while True:
            try:
                self.send_heartbeat_if_due()
                self.poll_and_process()
            except KeyboardInterrupt:
                logger.info("Worker Engine Daemon shutting down gracefully.")
                break
            except Exception as e:
                logger.error(f"Unexpected error in worker daemon loop: {e}")

            time.sleep(POLL_INTERVAL_SECONDS)

    def poll_and_process(self):
        job = self.api_client.fetch_next_job()
        if not job:
            logger.debug("No pending review jobs available.")
            return

        job_id = job["id"]
        repo_name = job.get("repository", {}).get("full_name", "unknown/repo")
        commit_sha = job.get("commit_sha", "HEAD")
        logger.info(f"Claimed Review Job #{job_id} for repository '{repo_name}' (Commit: {commit_sha[:7]})")

        payload = self.orchestrator.execute_scan_job(job)
        if job.get("result_single_shot_max") is not None:
            payload["result_single_shot_max"] = job.get("result_single_shot_max")
        if job.get("result_chunk_size") is not None:
            payload["result_chunk_size"] = job.get("result_chunk_size")
        self.api_client.submit_job_result(job_id, payload)

if __name__ == "__main__":
    daemon = WorkerEngineDaemon()
    daemon.run()
