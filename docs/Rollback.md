# System Rollback Procedures

## Overview
Rollback procedures utilize the platform's native AES-256 encrypted backup system to safely restore database state, PDF reports, audit logs, and evidence manifests.

---

## 1. Application Code Rollback
To roll back the web application control plane to a previous git commit:
```bash
git checkout <previous-stable-commit-sha>
composer install --no-dev
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 2. System State Restoration (Database & Files)
To roll back database records and files to a known good backup archive:

```bash
php artisan backup:restore backup_2026-07-29_00-00-00.enc --force
```

Alternatively, perform the restoration from the Web UI at `/admin/backups` by uploading the target `.enc` file.
