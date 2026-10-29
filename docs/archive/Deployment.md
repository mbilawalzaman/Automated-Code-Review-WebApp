# Deployment Guide

# Overview

This document describes the deployment workflow implemented in this Laravel CI/CD template.

The template automates application deployment using GitHub Actions while following a Git-based branching strategy.

The deployment implementation is designed to work with **Hostinger Shared Hosting (SSH recommended)** and can be adapted to other SSH-accessible Linux hosting providers.

---

# Deployment Workflow

The deployment process follows the workflow below.

```text
Feature Branch
        │
        ▼
GitHub Actions CI
        │
Run Tests
        │
        ▼
Merge into staging
        │
        ▼
Automatic Staging Deployment
        │
Health Check
        │
        ▼
Merge into main
        │
(Optional Manual Approval)
        ▼
Production Deployment
```

---

# Continuous Integration (CI)

The CI workflow executes automatically when code is pushed to:

* `feature/*`
* `staging`
* `main`

CI Pipeline Steps:

1. Checkout Repository
2. Setup PHP
3. Install Composer Dependencies
4. Generate Application Key
5. Configure SQLite Database
6. Run Database Migrations
7. Execute Laravel Tests

Only successfully validated code should be merged into higher environments.

---

# Staging Deployment

The staging deployment is triggered automatically after code is pushed to the `staging` branch.

Deployment Steps:

1. Connect to the deployment server using SSH.
2. Navigate to the Laravel project directory.
3. Fetch the latest source code.
4. Reset the working copy to the latest `staging` branch.
5. Install Composer dependencies.
6. Execute database migrations.
7. Optimize the Laravel application.
8. Create the storage symlink if required.
9. Validate the application using the health endpoint.

---

# Production Deployment

Production deployment starts when changes are merged into the `main` branch.

The workflow uses the GitHub **production environment**.

If required, manual approval can be enabled by configuring **Required Reviewers** in the GitHub Production Environment.

Deployment Steps:

1. Connect to the deployment server.
2. Fetch the latest production code.
3. Install Composer dependencies.
4. Execute database migrations.
5. Optimize Laravel.
6. Validate deployment using the health endpoint.

---

# Health Validation

After deployment, the workflow validates the application by requesting the health endpoint.

Example:

```text
GET /health
```

Expected response:

```json
{
    "status": "ok"
}
```

A successful response indicates that the deployment completed successfully.

---

# Deployment Secrets

The deployment workflow requires the following GitHub Secrets.

| Secret           | Description                                       |
| ---------------- | ------------------------------------------------- |
| `DEPLOY_HOST`    | Deployment server hostname or IP                  |
| `DEPLOY_USER`    | SSH username                                      |
| `DEPLOY_SSH_KEY` | Private SSH key                                   |
| `DEPLOY_PATH`    | Laravel application directory                     |
| `APP_URL`        | Public application URL used for health validation |

---

# Deployment Target

The template is designed for **Hostinger Shared Hosting** using SSH access.

The deployment configuration is reusable and can be adapted to other SSH-accessible Linux hosting providers by updating the deployment secrets.

---

# Rollback

Rollback procedures are documented separately in:

```text
docs/Rollback.md
```

---

# Notes

* No server credentials are stored in the repository.
* All sensitive information is managed using GitHub Secrets.
* Manual production approval is optional and configured through GitHub Environments.
* The deployment workflow is intentionally generic to simplify reuse across multiple Laravel projects.

