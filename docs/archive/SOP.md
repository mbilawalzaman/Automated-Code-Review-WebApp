# Standard Operating Procedure (SOP)

# Purpose

This document defines the recommended workflow for developing, testing, reviewing, and deploying Laravel applications using this CI/CD template.

---

# Development Workflow

1. Create a new feature branch.

```text
feature/<feature-name>
```

2. Develop the required changes.

3. Commit changes with meaningful commit messages.

4. Push the feature branch.

5. Verify that the CI workflow completes successfully.

---

# Code Review

1. Create a Pull Request.
2. Review code changes.
3. Ensure all required status checks pass.
4. Merge into the staging branch.

---

# Staging Deployment

After merging into `staging`:

* Deployment starts automatically.
* Application is updated.
* Database migrations execute.
* Laravel is optimized.
* Health checks are performed.

Verify that staging behaves as expected before promoting changes.

---

# Production Deployment

After successful staging validation:

1. Merge `staging` into `main`.
2. (Optional) Approve production deployment if GitHub Environment approval is enabled.
3. Production deployment starts automatically.
4. Verify the application health.

---

# Monitoring

After each deployment verify:

* GitHub Actions completed successfully.
* Application is accessible.
* `/health` returns HTTP 200.
* No unexpected application errors are present.

---

# Security Guidelines

* Never commit `.env` files.
* Never commit SSH keys.
* Store sensitive information only in GitHub Secrets.
* Use protected branches for production.
* Enable deployment approval where required by organizational policy.

---

# Best Practices

* Use feature branches for all development.
* Keep Pull Requests focused and small.
* Test before merging.
* Review deployment logs after each release.
* Update documentation whenever deployment logic changes.

---

# Summary

Following this SOP ensures a consistent, secure, and repeatable deployment process while maintaining a reusable Laravel CI/CD template suitable for Hostinger Shared Hosting and other SSH-accessible Linux hosting providers.

