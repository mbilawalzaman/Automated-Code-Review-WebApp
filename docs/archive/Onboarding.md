# Onboarding Guide

# Purpose

This document describes the onboarding process for a new Laravel project using the CI/CD deployment framework.

---

# Prerequisites

Before onboarding, ensure the following are available:

- GitHub repository
- Hostinger Shared Hosting account
- Laravel application
- SSH access (if supported)
- GitHub repository administrator access

---

# Repository Setup

1. Create a new repository.
2. Clone the Laravel CI/CD template.
3. Push the initial project structure.
4. Configure the default branch.

---

# Branch Configuration

Create the following branches:

- main
- staging
- feature/*

Enable branch protection for:

- main
- staging

---

# GitHub Environments

Create:

- staging
- production

Configure deployment protection rules if required.

---

# Repository Secrets

Configure the following GitHub Secrets:

- DEPLOY_HOST
- DEPLOY_USER
- DEPLOY_SSH_KEY
- DEPLOY_PATH
- APP_URL

---

# Hosting Configuration

Configure:

- Laravel deployment directory
- SSH access (if available)
- PHP version
- Composer
- Environment variables

---

# Validation

Verify:

- CI workflow passes
- Staging deployment succeeds
- Production deployment succeeds
- Health endpoint responds successfully

---

# Completion

Once the above steps are complete, the project is ready for development and deployment using the CI/CD framework.
