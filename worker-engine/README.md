# Mac Mini Worker Engine

This directory contains the Python-based Worker Engine daemon for performing automated code reviews, local AI analysis via Ollama, and communicating with the Control Plane API.

---

## 🚀 Quickstart Setup Instructions (Mac mini)

### 1. Clone Repository & Navigate to Worker Engine
```bash
git clone https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp.git
cd Automated-Code-Review-WebApp/worker-engine
```

### 2. Install Dependencies
```bash
pip3 install -r requirements.txt
```

### 3. Configure Environment File (`.env`)
```bash
cp .env.example .env
```
Edit `.env` to set your Worker API token:
```env
WORKER_API_URL=https://staging.codereviewapp.online/api/v1/worker
WORKER_API_TOKEN=<YOUR_WORKER_API_TOKEN>
# Stable identity for this machine, reported on every heartbeat and shown on
# /admin/health. Defaults to the machine hostname if omitted. Must match one of
# the dashboard's EXPECTED_WORKER_IDS entries for offline alerting to apply.
WORKER_ID=mac-mini-primary
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=codellama:7b
POLL_INTERVAL_SECONDS=5
```

The daemon posts `POST /api/v1/worker/heartbeat` roughly every 30 seconds, including
while idle, so the dashboard can distinguish "no jobs queued" from "worker down".

### Git checkout behaviour

Jobs are checked out at the **exact** `commit_sha` from the job payload:

- Pull requests fetch `refs/pull/<N>/head` (plus a SHA fetch fallback)
- Push/branch jobs fetch `refs/heads/<branch_name>`
- If checkout cannot land on the requested SHA, the job fails as `review_error`
  instead of silently scanning the default-branch `HEAD`

Diffs are computed against the repository default branch via `merge-base` so
objective/AI analysis sees the PR change set.

### Objective Compliance Analysis

Claimed jobs may include an `objectives` array — the requirement checklist from the currently
approved SRS revision for that repository. `analyzers/objective_compliance.py` evaluates the diff
against each requirement through the local Ollama model in batches of five, and reports a finding
only when the verdict is a violation with confidence of at least 0.6.

- A breach of a **mandatory** requirement is marked blocking, so the GitHub Check Run fails.
- Advisory breaches are reported as warnings.
- If the model is unavailable or returns unusable output, no objective findings are produced and
  the SAST review still completes; jobs with an empty `objectives` array skip this stage entirely.

### Language detection, adapters, and trust gate (Phase 21)

- `analyzers/language_detector.py` detects PHP, Python, JavaScript/TypeScript, and Playwright from
  project manifests.
- Language adapters (PHPStan, Ruff, Bandit, ESLint) run in addition to baseline Gitleaks/Semgrep/OSV.
- Vendor/generated paths (`vendor/`, `node_modules/`, `dist/`, `*.min.js`) are excluded from style
  findings (REV-002). Secrets scanners still report vendor leaks.
- Test execution (PHPUnit/pytest/npm test) and Playwright run **only** when the job payload includes
  `execution_authorized=true` (trusted author, Review Now/baseline, or explicit policy). Otherwise
  the worker stays static-first (WF-007).
- Child processes use scrubbed environments (no worker tokens/secrets) and timeouts. Prefer running
  the worker under a restricted macOS user/container for stronger isolation.
- Changed-line coverage below `CHANGED_COVERAGE_MIN_PERCENT` (default 70) produces a blocking
  `TST-004` finding.

### 4. Ensure Ollama Service is Running
```bash
ollama serve
```

### 5. Start Worker Engine Daemon
```bash
python3 main.py
```
