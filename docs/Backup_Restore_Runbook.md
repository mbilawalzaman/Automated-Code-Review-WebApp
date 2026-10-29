# Encrypted Backup & Restoration Runbook (AUD-004 / AUD-005)

## Overview
The system provides daily AES-256-CBC encrypted backups covering database schema/records, PDF reports, audit logs, and evidence manifests.

---

## 1. Automated Schedule & Retention
- **Schedule:** Runs daily at midnight (`00:00`) via `php artisan backup:create` scheduled in `routes/console.php`.
- **Retention:** Archives older than 30 days are automatically pruned during backup execution.
- **Storage Location:** `/mnt/sda3/QA-Automation/Automated-Code-Review-WebApp/storage/app/backups/`

---

## 2. Generating Backups

### Via Web UI
1. Navigate to **Backups** (`/admin/backups`).
2. Click **Create Backup Now**.
3. Download the encrypted `.enc` file using the **Download** action.

### Via CLI
```bash
php artisan backup:create
```

---

## 3. Restoring Backups (AUD-005)

> [!WARNING]
> Restoration completely overwrites the existing database tables, PDF reports, and evidence files.

### Option A: Via Web UI
1. Navigate to `/admin/backups`.
2. Click **Upload & Restore** to upload a local `.enc` file, OR click **Restore** next to an existing server backup archive.
3. Confirm the browser warning modal.

### Option B: Via CLI
```bash
php artisan backup:restore backup_2026-07-29_00-00-00.enc --force
```
*(Or pass the full path to a downloaded `.enc` file).*
