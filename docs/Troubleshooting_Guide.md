# Troubleshooting & Operations Guide

## Common Issues & Diagnostics

### 1. Worker Heartbeat Status Displays "Offline"
- **Cause:** Mac Mini worker daemon is stopped, disconnected, or custom `WORKER_API_TOKEN` is mismatched.
- **Diagnosis:**
  1. Check System Health page (`/admin/health`) for last ping timestamp.
  2. Inspect worker output on Mac Mini: active foreground console/terminal output (or process output if using `launchd` / background logging).
  3. Verify `WORKER_API_TOKEN` in `worker-engine/.env` matches the control plane `.env`.
- **Remediation:** Restart daemon on Mac Mini (`python main.py`).

### 2. High Pending Queue Depth (Jobs Not Claimed)
- **Cause:** Worker offline or failing to poll API.
- **Remediation:** Check worker liveness on `/admin/health`. Ensure network connection from Mac Mini to VPS is active.

### 3. Stuck Jobs (>30 Minutes in Processing State)
- **Automated Recovery:** The scheduled sweeper (`php artisan jobs:sweep-stuck`) automatically sweeps stuck jobs every 10 minutes, finalizes the job as `ReviewError`, and marks the GitHub Check Run as `failure`.
- **Manual Rerun:** Click **Review Now** on the repository page (`/admin/repositories`).

### 4. Scheduler Signal "Stale / Crontab Missing"
- **Cause:** Server crontab is missing `artisan schedule:run`.
- **Remediation:** Add `* * * * * php /var/www/code-review-webapp/artisan schedule:run >> /dev/null 2>&1` to server crontab.
