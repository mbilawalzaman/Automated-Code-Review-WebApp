# Production Deployment & Installation Runbook

## Overview
This runbook documents the deployment of the Code Review Automation Platform Control Plane (VPS / Web Application) and Review Worker Engine (Mac Mini).

---

## 1. Prerequisites
- **Control Plane Server:** Linux VPS / Server with PHP 8.2+, MySQL/MariaDB (or SQLite), Composer, Nginx/Apache.
- **Review Worker:** macOS Machine (Mac Mini) with Python 3.10+, Git, and local open-source AI runtime (Ollama / Llama.cpp).
- **GitHub App:** Registered GitHub App with permissions for Checks (R/W), Contents (R), Pull Requests (R/W), and Administration (R/W).

---

## 2. Control Plane Deployment (VPS / Web Application)

### Step 2.1: Clone and Configure Environment
```bash
cd /var/www/
git clone https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp.git code-review-webapp
cd code-review-webapp
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### Step 2.2: Database Migration & Seeding
```bash
# Configure DB credentials in .env
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
```

### Step 2.3: Storage Permissions & Link
```bash
chmod -R 775 storage bootstrap/cache
php artisan storage:link
```

### Step 2.4: Crontab Configuration (MANDATORY)
> [!IMPORTANT]
> The Laravel Scheduler **MUST** be added to the server's crontab. Scheduled backups (`backup:create`), stuck-job sweeping (`jobs:sweep-stuck`), worker outage health checking (`health:check-workers`), and scheduler freshness signals depend on this line:

Edit crontab (`crontab -e` as web server user `www-data` or app user):
```cron
* * * * * php /var/www/code-review-webapp/artisan schedule:run >> /dev/null 2>&1
```

---

## 3. Mac Mini Review Worker Deployment

### Step 3.1: Python Worker Setup
```bash
cd ~
git clone https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp.git worker
cd worker/worker-engine
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

### Step 3.2: Configure Worker Environment
In `worker-engine/.env`:
```env
WORKER_API_URL=https://your-domain.com/api/v1/worker
WORKER_API_TOKEN=your_secure_worker_bearer_token
WORKER_ID=mac-mini-primary
POLL_INTERVAL_SECONDS=5
WORK_DIR=./workspace
OLLAMA_HOST=http://localhost:11434
```

> **Worker Authentication (`WORKER_API_TOKEN`):** Worker requests require Bearer token authentication. Configure a strong random `WORKER_API_TOKEN` in both the control plane and worker `.env` files (values must match).

### Step 3.2b: Enroll the Worker on the Dashboard
The dashboard only alerts on workers it expects to exist. In the **dashboard** `.env`,
list every `WORKER_ID` deployed on a Mac mini, then rebuild the config cache:
```env
EXPECTED_WORKER_IDS=mac-mini-primary
# Optional tuning (defaults shown)
WORKER_OFFLINE_AFTER_MINUTES=15
REVIEW_JOB_STUCK_AFTER_MINUTES=30
```
```bash
php artisan config:cache
```
Workers listed here appear as **Expected (Never Connected)** on `/admin/health` until
their first heartbeat arrives, and trigger offline alerts to `CRITICAL_ALERT_EMAILS`
if heartbeats stop. Leaving the list empty monitors only workers that have connected
at least once and disables never-connected alerting.

### Step 3.3: Launch Daemon
```bash
python main.py
```
*(Optionally configure macOS launchd agent for 24/7 background execution).*

---

## 4. Environment Verification
1. Log in to Web Application as Super Admin (`admin@example.com` / `password`).
2. Navigate to **Audit & Operations > System Health** (`/admin/health`).
3. Verify that:
   - Worker Heartbeat status displays **Online & Polling**
   - Scheduler status displays **Healthy & Running**
   - Database and Storage status display **Healthy / Writable**
