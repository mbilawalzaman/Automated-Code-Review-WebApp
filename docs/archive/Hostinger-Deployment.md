# Hostinger Shared Hosting Deployment Guide

# Overview

This document describes the deployment strategy used by this Laravel CI/CD template when deploying to Hostinger Shared Hosting.

The deployment workflow is designed to be reusable while following Laravel deployment best practices.

The recommended deployment method is **SSH**, as it provides secure remote access and supports automated deployment tasks required by Laravel applications.

---

# Recommended Deployment Method

The template uses **SSH-based deployment**.

SSH was selected because it allows GitHub Actions to:

* Connect securely to the hosting server.
* Pull the latest application code.
* Install Composer dependencies.
* Execute Laravel Artisan commands.
* Run database migrations.
* Optimize the application.
* Perform post-deployment health validation.

This approach minimizes manual intervention while keeping the deployment process repeatable and maintainable.

---

# Deployment Workflow

```text
Developer
      │
      ▼
Git Push
      │
      ▼
GitHub Actions
      │
      ▼
SSH Connection
      │
      ▼
Hostinger Server
      │
      ▼
Update Application
      │
      ▼
Composer Install
      │
      ▼
Laravel Migrations
      │
      ▼
Laravel Optimization
      │
      ▼
Health Check
```

---

# Required Hosting Configuration

Before using this template, ensure the hosting environment provides:

* SSH access
* PHP 8.3 or later
* Composer
* Git
* Laravel application directory
* Internet access for Composer packages

---

# Required GitHub Secrets

Configure the following Repository Secrets.

| Secret           | Description                   |
| ---------------- | ----------------------------- |
| `DEPLOY_HOST`    | SSH host or IP address        |
| `DEPLOY_USER`    | SSH username                  |
| `DEPLOY_SSH_KEY` | Private SSH key               |
| `DEPLOY_PATH`    | Absolute deployment directory |
| `APP_URL`        | Public application URL        |

---

# Deployment Steps

The deployment workflow performs the following actions:

1. Connect to the server using SSH.
2. Navigate to the Laravel application directory.
3. Fetch the latest source code.
4. Reset to the latest branch revision.
5. Install Composer dependencies.
6. Execute database migrations.
7. Optimize Laravel.
8. Create the storage symlink if required.
9. Validate deployment using the health endpoint.

---

# Laravel Commands Executed

During deployment, the workflow executes:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader

php artisan migrate --force

php artisan optimize

php artisan storage:link || true
```

---

# Health Validation

Deployment success is verified by calling:

```text
GET /health
```

The application should respond with HTTP 200.

---

# Shared Hosting Considerations

Unlike a dedicated VPS, Hostinger Shared Hosting has certain limitations.

Examples include:

* No access to system services such as Nginx or PHP-FPM.
* No `systemctl` access.
* Server configuration is managed by the hosting provider.
* Some hosting plans may have limited shell access.

For these reasons, the deployment workflow intentionally avoids server administration commands.

---

# Rollback Strategy

Rollback is performed using Git.

Recommended process:

1. Connect to the server.
2. Navigate to the application directory.
3. Reset the repository to a previous stable commit.
4. Reinstall Composer dependencies if required.
5. Re-run Laravel optimization.

Detailed rollback instructions are available in:

```text
docs/Rollback.md
```

---

# Security Recommendations

* Store all deployment credentials in GitHub Secrets.
* Never commit private SSH keys.
* Never commit the `.env` file.
* Restrict SSH access to authorized users.
* Rotate deployment keys periodically.

---

# Future Improvements

This deployment template can be extended to support:

* Additional hosting providers.
* Multiple deployment environments.
* Zero-downtime deployment strategies.
* Deployment notifications.
* Automated rollback.
* Slack or Microsoft Teams notifications.

---

# Summary

This deployment template provides a reusable, secure, and automated deployment workflow for Laravel applications hosted on Hostinger Shared Hosting while remaining adaptable to other SSH-accessible Linux hosting providers.

