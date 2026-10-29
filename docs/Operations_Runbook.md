# Operations Runbook

## Day-to-Day Platform Maintenance

### 1. Daily System Health Checks
- Access `/admin/health` daily to monitor:
  - Worker node liveness & heartbeat freshness
  - Queue depth & stuck jobs
  - Scheduler heartbeat (`scheduler:last_seen_at`)
  - Backup completion & retention status

### 2. Log Inspections
- **Laravel Logs:** `storage/logs/laravel.log`
- **Worker Daemon Logs:** Foreground terminal / process stdout (or custom `worker.log` if output redirected)

### 3. Service Management
- **VPS Web Application:** Nginx (`systemctl status nginx`) & PHP-FPM (`systemctl status php8.2-fpm`).
- **Scheduler:** Verified via crontab (`crontab -l`).
