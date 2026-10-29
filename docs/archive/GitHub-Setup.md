# GitHub Repository Setup Guide

# Overview

This document explains how to configure a GitHub repository to use this Laravel CI/CD template.

The template is designed to be reusable across multiple Laravel projects. Before using the workflows, the repository must be configured with GitHub Environments, Secrets, Branch Protection Rules, and optional Production Approval.

---

# Repository Secrets

Create the following Repository Secrets:

| Secret           | Description                                       |
| ---------------- | ------------------------------------------------- |
| `DEPLOY_HOST`    | Deployment server hostname or IP address          |
| `DEPLOY_USER`    | SSH username                                      |
| `DEPLOY_SSH_KEY` | Private SSH key used for deployment               |
| `DEPLOY_PATH`    | Absolute path to the Laravel application          |
| `APP_URL`        | Public application URL used for health validation |

Navigate to:

```text
Repository
→ Settings
→ Secrets and variables
→ Actions
→ New repository secret
```

---

# GitHub Environments

Create the following environments:

```text
staging
production
```

Navigate to:

```text
Repository
→ Settings
→ Environments
```

These environments allow environment-specific secrets, deployment protection rules, and optional manual approvals.

---

# Branch Protection

Configure branch protection for the `main` branch.

Recommended settings:

* Require a pull request before merging
* Require status checks to pass before merging
* Require branches to be up to date before merging
* Restrict direct pushes to the protected branch

This ensures that only validated code is deployed.

---

# Required Status Checks

Select the GitHub Actions CI workflow as a required status check.

Example:

```text
Laravel CI
```

Only successful CI runs should allow merges into protected branches.

---

# Manual Production Approval (Optional)

To require approval before production deployments:

Navigate to:

```text
Repository
→ Settings
→ Environments
→ production
```

Configure:

* Required Reviewers

This causes GitHub to pause the deployment workflow until an authorized reviewer approves the deployment.

This setting is optional and depends on the organization's deployment policy.

---

# Prevent Self Review (Optional)

GitHub provides an option to prevent users from approving deployments they initiated.

If enabled:

* The user who triggered the deployment cannot approve it.
* A different reviewer must approve the deployment.

This feature is recommended for teams requiring separation of duties.

Solo developers may choose to leave this option disabled.

---

# Branch Workflow

Recommended Git workflow:

```text
feature/*
        │
        ▼
GitHub Actions CI
        │
        ▼
staging
        │
Automatic Deployment
        │
        ▼
main
        │
(Optional Manual Approval)
        ▼
Production Deployment
```

---

# Deployment Workflow

The deployment workflows use the configured GitHub Secrets to:

* Connect to the deployment server
* Update the Laravel application
* Execute database migrations
* Optimize the application
* Validate deployment using the health endpoint

No credentials are stored inside the repository.

---

# Security Best Practices

* Never commit SSH keys.
* Never commit `.env`.
* Store all credentials in GitHub Secrets.
* Use separate secrets for staging and production if required.
* Rotate deployment keys periodically.

---

# Repository Checklist

Before using this template, verify:

* Repository Secrets configured
* GitHub Environments created
* Branch Protection enabled
* CI workflow passing
* Deployment workflow enabled
* Health endpoint available

Once these steps are complete, the repository is ready for automated deployments.

