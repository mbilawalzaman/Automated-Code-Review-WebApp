# API Specifications & Complete Endpoint Reference

## Base URLs
- **Admin Control Plane:** `https://codereviewapp.online/admin` (Staging: `https://staging.codereviewapp.online/admin`)
- **Worker REST API (v1):** `https://codereviewapp.online/api/v1`

---

## 1. Worker API Endpoints (`/api/v1/worker`)

All worker endpoints require a Bearer Token in the HTTP Authorization header:
```http
Authorization: Bearer <WORKER_API_TOKEN>
```

### `POST /api/v1/worker/heartbeat`
- **Purpose:** Outbound liveness heartbeat and tool version registration from worker nodes.
- **Request Body:**
```json
{
  "worker_id": "mac-mini-primary",
  "hostname": "macbook-pro.local",
  "os_info": "Darwin 23.4.0",
  "python_version": "3.11.5",
  "payload": {
    "tools": {
      "gitleaks": "8.18.0",
      "semgrep": "1.45.0",
      "osv-scanner": "1.6.0",
      "phpstan": "1.10.50",
      "ruff": "0.1.9",
      "eslint": "8.56.0",
      "playwright": "1.41.0"
    }
  }
}
```
- **Response (`200 OK`):**
```json
{
  "success": true,
  "worker_id": "mac-mini-primary",
  "last_ping_at": "2026-08-01T16:00:00Z"
}
```

---

### `POST /api/v1/worker/jobs/next`
- **Purpose:** Atomically claims the oldest pending review job for execution.
- **Response (`200 OK` with job payload):**
```json
{
  "has_job": true,
  "job": {
    "id": 102,
    "repository_id": 5,
    "execution_authorized": true,
    "repository": {
      "name": "payments-api",
      "full_name": "acme/payments-api",
      "default_branch": "main",
      "clone_url": "https://github.com/acme/payments-api.git",
      "review_rules": {
        "trusted_authors": ["AlwaysWinningForever", "mbilawalzaman"]
      }
    },
    "commit_sha": "a1b2c3d4e5f67890",
    "pull_request_number": 12,
    "github_check_run_id": "789101112",
    "objectives": [
      {
        "id": 41,
        "code": "SEC-001",
        "title": "Secrets must never be committed",
        "is_mandatory": true
      }
    ]
  }
}
```
- **`execution_authorized` Field (`WF-007`):** Indicates whether test execution and Playwright browser tests are allowed. `true` for manual triggers and trusted authors; `false` for untrusted contributor webhooks (static analysis only).

---

### `POST /api/v1/worker/jobs/{id}/result`
- **Purpose:** Ingests complete review results, security findings, changed-code test coverage, change impact analysis, and tool provenance from the worker engine.
- **Request Body Payload:**
```json
{
  "status": "completed",
  "summary": "Completed review for PR #12 with 0 blocking security defects.",
  "coverage_percentage": 84.5,
  "changed_code_coverage": 78.2,
  "test_summary": {
    "framework": "phpunit + playwright",
    "total_tests": 42,
    "passed": 42,
    "failed": 0,
    "playwright_browser_tests": { "total": 6, "passed": 6 }
  },
  "change_classes": ["api_contract", "destructive_migration"],
  "impact_analysis": {
    "affected_modules": ["PaymentGateway", "InvoiceService"],
    "database_tables": ["orders", "transactions"],
    "breaking_api_changes": false
  },
  "tool_executions": [
    {
      "tool": "gitleaks",
      "version": "8.18.0",
      "duration_ms": 1240,
      "ruleset": "default"
    },
    {
      "tool": "phpstan",
      "version": "1.10.50",
      "duration_ms": 3450,
      "ruleset": "level 5"
    }
  ],
  "findings": [
    {
      "title": "Hardcoded Secret Leaked",
      "file_path": "config/services.php",
      "line_number": 42,
      "severity": "high",
      "status": "open",
      "fingerprint": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "evidence": "Potential credential detected (generic-api-key). [REDACTED]",
      "srs_objective_id": 41,
      "objective_impact": "Violates SEC-001 credential security",
      "is_blocking": true
    }
  ]
}
```
- **Finding Fields:**
  - `fingerprint` (`FND-002`): Stable `sha256(rule_id + file_path + code_snippet)` hash for finding continuity across commits.
  - `parent_finding_id` (`FND-003`): ID of previously open finding linked to this item.
  - `resolved_in_review_id` & `resolved_at` (`GIT-003`): Automatically set when a prior finding is no longer detected.
- **`change_classes` values:** `destructive_migration`, `api_contract`, `auth`, `ci_cd`, `secrets`, `unsigned_commit`.

---

## 2. GitHub Webhook

### `POST /api/v1/github/webhook`
- **Purpose:** Receives GitHub App webhook events (push, pull request, check suite as configured).
- **Auth:** GitHub webhook signature verification (`X-Hub-Signature-256` / App webhook secret).
- **Effect:** Creates or updates review jobs; does not accept source code payloads for analysis on the VPS.

---

## 3. Authentication & 2FA Endpoints

### `GET /two-factor` / `POST /two-factor` / `DELETE /two-factor`
- **Purpose:** Authenticated MFA setup UI and actions.
  - `GET` — setup page (QR / secret / recovery codes after enable).
  - `POST` — enable TOTP (confirm with authenticator code).
  - `DELETE` — disable MFA (requires valid TOTP or recovery code).

### `GET /two-factor-challenge` / `POST /two-factor-challenge`
- **Purpose:** Login challenge after password when MFA is enabled.
- **Request Body (POST):**
```json
{
  "code": "123456"
}
```
*(Or `"recovery_code": "abcd-efgh"`).*

---

## 4. Admin Management Endpoints (`/admin`)

Requires authenticated session with admin-panel access (Super Admin, Project Manager, or Security Auditor) and the listed permission. Super Admin bypasses permission checks.

### Dashboard & projects

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/dashboard` | admin panel | Executive dashboard & trend charts (`UI-004`) |
| `GET /admin/projects` | `projects.view` | Project list |
| `GET /admin/projects/data` | `projects.view` | DataTables JSON for projects |
| `POST /admin/projects` | `projects.manage` | Create project |
| `PUT /admin/projects/{project}` | `projects.manage` | Update project |
| `DELETE /admin/projects/{project}` | `projects.manage` | Delete project |

### Repositories

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/repositories` | `repositories.view` | Repository list |
| `GET /admin/repositories/data` | `repositories.view` | DataTables JSON for repositories |
| `GET /admin/repositories/discover` | `repositories.manage` | Discover repos from GitHub App |
| `POST /admin/repositories` | `repositories.manage` | Connect / add repository |
| `PUT /admin/repositories/{repository}` | `repositories.manage` | Update repository settings |
| `DELETE /admin/repositories/{repository}` | `repositories.manage` | Disconnect repository |
| `PATCH /admin/repositories/{repository}/connect` | `repositories.manage` | Re-connect repository |
| `PATCH /admin/repositories/{repository}/activate` | `repositories.manage` + password confirm | Activate + baseline review |
| `PATCH /admin/repositories/{repository}/pause` | `repositories.manage` | Pause reviews (ONB-006) |
| `PATCH /admin/repositories/{repository}/resume` | `repositories.manage` | Resume reviews |
| `POST /admin/repositories/{repository}/review-now` | `repositories.manage` | Manual review trigger (`ONB-007`) |
| `POST /admin/repositories/{repository}/protect-branches` | `repositories.manage` | Re-apply branch protection |

### Objectives & SRS

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/srs` | `srs.view` | SRS revision list |
| `GET /admin/srs/{srsDocument}` | `srs.view` | Revision detail & checklist |
| `POST /admin/srs` | `srs.manage` | Add SRS revision |
| `POST /admin/srs/{srsDocument}/extract` | `srs.manage` | Re-extract checklist from document |
| `POST /admin/srs/{srsDocument}/replace` | `srs.manage` | Replace source & re-extract |
| `PUT /admin/srs/{srsDocument}/checklist` | `srs.manage` | Save checklist edits |
| `POST /admin/srs/{srsDocument}/submit` | `srs.manage` | Submit for approval |
| `POST /admin/srs/{srsDocument}/approve` | `srs.approve` + password confirm | Approve & activate |
| `POST /admin/srs/{srsDocument}/reject` | `srs.approve` + password confirm | Reject with reason |
| `DELETE /admin/srs/{srsDocument}` | `srs.manage` | Delete deletable revision |

### Reviews & findings

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/reviews` | `reviews.view` | Review list (`UI-002`) |
| `GET /admin/reviews/data` | `reviews.view` | DataTables JSON for reviews |
| `GET /admin/reviews/{review}` | `reviews.view` | Review detail |
| `POST /admin/reviews/{review}/override` | `reviews.override` + password confirm | Override failed review |
| `GET /admin/findings` | `findings.view` | Findings list with filters (`UI-003`) |
| `GET /admin/findings/data` | `findings.view` | DataTables JSON for findings |
| `GET /admin/findings/{finding}` | `findings.view` | Finding detail |
| `POST /admin/findings/{finding}/false-positive` | `findings.manage` + password confirm | Mark false positive |
| `POST /admin/findings/{finding}/accept` | `findings.manage` + password confirm | Accept / acknowledge warning |
| `POST /admin/review-jobs/{reviewJob}/cancel` | `jobs.cancel` | Cancel pending/processing job (`OPS-003`) |

### Reports & exports

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/reports` | `reports.view` | PDF report list |
| `GET /admin/reports/data` | `reports.view` | DataTables JSON for reports |
| `GET /admin/reports/{report}/download` | `reports.view` | Download PDF report |
| `GET /admin/reports/{report}/stream` | `reports.view` | Stream PDF inline |
| `GET /admin/exports/reviews` | `reports.view` | Export reviews CSV/JSON (max 5,000 rows) |
| `GET /admin/exports/findings` | `findings.view` | Export findings CSV/JSON (max 5,000 rows) |

### Audit & health

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/audit-logs` | `audit.view` | Audit ledger |
| `GET /admin/audit-logs/data` | `audit.view` | DataTables JSON for audit logs |
| `GET /admin/audit-logs/analytics` | `audit.view` | Audit analytics dashboard |
| `GET /admin/audit-logs/analytics/data` | `audit.view` | Analytics chart data |
| `GET /admin/audit-logs/export` | `audit.view` | Export audit logs CSV/JSON |
| `GET /admin/audit-logs/{auditLog}` | `audit.view` | Single audit entry detail |
| `GET /admin/health` | `system.health.view` | System health dashboard |
| `GET /admin/health/data` | `system.health.view` | Health metrics JSON |

### Integrations, notifications, backups, users & roles

| Method & Route | Permission | Purpose |
| :--- | :--- | :--- |
| `GET /admin/integrations` | `integrations.manage` | Integration credentials UI |
| `POST /admin/integrations` | `integrations.manage` + password confirm | Update encrypted credentials |
| `GET /admin/notifications` | `notifications.view` | Notifications inbox |
| `POST /admin/notifications/mark-all-read` | `notifications.manage` | Mark all notifications read |
| `POST /admin/notifications/{notification}/read` | `notifications.manage` | Mark one notification read |
| `DELETE /admin/notifications/{notification}` | `notifications.manage` | Delete notification |
| `GET /admin/backups` | `settings.manage` | Backup list & actions |
| `POST /admin/backups/generate` | `settings.manage` | Create backup now |
| `POST /admin/backups/restore` | `settings.manage` + password confirm | Restore from archive or upload |
| `POST /admin/backups/{filename}/verify` | `settings.manage` | Verify archive integrity |
| `GET /admin/backups/{filename}/download` | `settings.manage` | Download `.enc` archive |
| `GET /admin/settings` | `settings.manage` | System settings (if exposed) |
| `GET /admin/users` | `users.manage` | User management |
| `GET /admin/users/data` | `users.manage` | DataTables JSON for users |
| `POST /admin/users` | `users.manage` | Create user |
| `PUT /admin/users/{user}` | `users.manage` | Update user |
| `DELETE /admin/users/{user}` | `users.manage` | Delete user |
| `GET /admin/roles` | `roles.manage` | Role management |
| `GET /admin/roles/data` | `roles.manage` | DataTables JSON for roles |
| `POST /admin/roles` | `roles.manage` | Create custom role |
| `PUT /admin/roles/{role}` | `roles.manage` | Update role permissions |
| `DELETE /admin/roles/{role}` | `roles.manage` | Delete custom role |
