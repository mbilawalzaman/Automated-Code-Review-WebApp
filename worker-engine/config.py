import os
import socket
from dotenv import load_dotenv

load_dotenv()

WORKER_API_URL = os.getenv("WORKER_API_URL", "https://staging.codereviewapp.online/api/v1/worker").rstrip('/')
WORKER_API_TOKEN = os.getenv("WORKER_API_TOKEN", "")
WORKER_ID = os.getenv("WORKER_ID", socket.gethostname() or "mac-mini-primary")

# Static PAT fallback (optional). Prefer GitHub App credentials below so the
# worker can auto-refresh installation tokens (~1h expiry) without a restart.
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN", "")
GITHUB_APP_ID = os.getenv("GITHUB_APP_ID", "")
GITHUB_APP_INSTALLATION_ID = os.getenv("GITHUB_APP_INSTALLATION_ID", "")
# Inline PEM (use \n for newlines) or absolute path to a .pem file.
GITHUB_APP_PRIVATE_KEY = os.getenv("GITHUB_APP_PRIVATE_KEY", "")
GITHUB_API_BASE = os.getenv("GITHUB_API_BASE", "https://api.github.com").rstrip("/")
GITHUB_WEB_BASE = os.getenv("GITHUB_WEB_BASE", "https://github.com").rstrip("/")

OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://localhost:11434").rstrip('/')
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "codellama:7b")

# Objective compliance (SRS checklist via Ollama). Keep budgets tight so a large
# approved checklist cannot exceed the VPS ~30m stuck-job sweeper.
OBJECTIVE_BATCH_SIZE = max(1, int(os.getenv("OBJECTIVE_BATCH_SIZE", "20")))
OBJECTIVE_CALL_TIMEOUT_SECONDS = max(5, int(os.getenv("OBJECTIVE_CALL_TIMEOUT_SECONDS", "30")))
OBJECTIVE_MAX_CALLS = max(1, int(os.getenv("OBJECTIVE_MAX_CALLS", "3")))
OBJECTIVE_WALL_CLOCK_SECONDS = max(15, int(os.getenv("OBJECTIVE_WALL_CLOCK_SECONDS", "120")))

POLL_INTERVAL_SECONDS = int(os.getenv("POLL_INTERVAL_SECONDS", "5"))
WORK_DIR = os.getenv("WORK_DIR", "./workspace")
