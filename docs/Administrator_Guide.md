# Administrator & Operations Guide

Instructions for Project Managers, Security Auditors, and Super Admins operating the Code Review Automation Platform.

---

## 1. Projects

Projects group repositories, SRS documents, and review objectives.

1. Navigate to **Projects** (`/admin/projects`).
2. Users with **`projects.manage`** see **Create Project** and can edit or delete projects from the row actions menu.
3. Users with **`projects.view`** only see the project list (no create or row actions).

Project data is **organization-wide**: all users with view access see the same projects. The `created_by` field is for audit only, not row-level isolation.

---

## 2. Onboarding & activating repositories (ONB-001 / ONB-003)

### Connecting a repository

1. Navigate to **Repositories** (`/admin/repositories`).
2. Click **Add Repository** (requires **`repositories.manage`**).
3. Select **Discover from GitHub App** to populate repositories installed under your GitHub App.
4. Associate a **project** and **default branch**; configure Trello board/list and notification options as needed. Click **Save**.

### Activating & branch protection (WF-004)

1. Click **Activate** on the repository row.
2. The activation modal shows proposed changes:
   - Automated Check Run name (`Automated Code Review`)
   - Branch protection for configured branches (`main` / `staging` by default): PR requirements, status check enforcement, blocking direct pushes and force pushes.
3. Click **Approve & Activate** (password confirmation).
4. A **baseline review** is queued and GitHub branch protection is applied via API.

### Pause & resume (ONB-006)

- **Pause** stops new webhook reviews without disconnecting the repository.
- **Resume** re-enables reviews on the next push or PR.
- **Review Now** (manual trigger) remains available when the repository is active.

### Trusted authors (WF-007)

On repository create/edit, set **Trusted authors** (comma-separated GitHub usernames). Those authors may run repository tests on webhook jobs. Others receive static-first review only unless a PM triggers **Review Now** or baseline activation (authorized by default).

---

## 3. Objectives & SRS (SRS-001 – SRS-006)

Objective-aware review is driven by an approved requirement checklist. Until a revision is approved, reviews run technical and security analysis only (SRS-004).

### Adding a revision (SRS-001)

On **Objectives & SRS** (`/admin/srs`), choose **Add SRS Revision** (requires **`srs.manage`**):

| Source | What to provide | Notes |
| --- | --- | --- |
| Upload | Markdown, TXT, PDF, or DOCX up to 10 MB | Text is extracted server-side |
| GitHub | Repository path (`docs/SRS.md`) or a blob URL | Read with the GitHub App installation token |
| Google Drive | Sharing link or file ID | Only when Drive is configured |

Scope the revision to a single repository, or leave the repository blank for a project-wide document. A repository-specific revision takes precedence over the project-wide one. Each intake is versioned automatically (v1, v2, …) within its scope (SRS-002).

### Curating the checklist (SRS-003)

Requirements are extracted into a checklist. Normative statements ("shall", "must", "required") are marked mandatory; advisory ones ("should", "may") are not. While the revision is a **draft** you can:

- Edit codes and wording, toggle mandatory, add or remove rows
- **Re-extract from document** to start over
- **Replace** the source file (draft/rejected/pending only) — checklist is re-extracted
- **Delete** a deletable draft/rejected/pending revision

### Approval (SRS-002 / AC-006)

**Submit for approval** moves the revision to `Pending Approval`. A user with **`srs.approve`** (Project Manager or Super Admin) confirms their password and clicks **Approve & activate**. On approval:

- The checklist becomes active and is sent to the worker with every subsequent review job.
- The previously approved revision is marked `Superseded`.
- An audit entry is written for the approval and superseded revisions.

Rejection requires a reason. Approved and superseded revisions are immutable; corrections require a new revision.

### How objective findings behave

A confirmed breach of a **mandatory** requirement blocks merging regardless of severity (SRS-005). Advisory breaches are warnings. Objective findings appear in the **Objective** column on **Findings** and in findings exports.

### Google Drive

Prefer **Admin → Integrations → Google Drive**: paste service-account JSON + folder ID (encrypted in DB). Share the folder with the service-account email (Viewer). Env vars remain a fallback. Files outside the configured folder are refused.

---

## 4. Reviews, findings & overrides

### Findings (FND-004)

On **Findings** (`/admin/findings`): filter by repository, severity, status, and date. Open a row for detail. Users with **`findings.manage`** can **Mark as False Positive** or **Accept** with a mandatory reason (password confirmation). Each action writes to the audit ledger.

### Reviews (FND-006)

On **Reviews** (`/admin/reviews`): view summary, change classes, impact analysis, and tool provenance. Users with **`reviews.override`** can **Override** a failed review (password confirmation):

- Reason is required.
- API-contract changes need documentation URL/notes.
- Destructive migrations need a recent **verified** backup (CHG-002).
- Override can finalize the GitHub Check Run as success when a check run exists.

### Reports

**Reports** (`/admin/reports`) lists generated PDF review reports. Download or stream when **`reports.view`** is granted.

---

## 5. Audit logs

Under the **Audit & Health** sidebar section (requires **`audit.view`**):

- **Audit Logs** — immutable event ledger with category, severity, actor, and target filters. Export CSV/JSON from the page header (requires **`audit.view`** on the export route).
- **Audit Analytics** — KPI cards and trend charts for security and operational events.

**System Health** (`/admin/health`, requires **`system.health.view`**) shows database, storage, scheduler heartbeat, worker nodes, review queue, stuck-job sweep status, and live integration probes. Cancel stuck jobs from health when **`jobs.cancel`** is granted.

Backup **create / verify / restore** actions are on **`/admin/backups`** (requires **`settings.manage`**, typically Super Admin). Daily automated backups still run via the scheduler when VPS cron is configured.

---

## 6. Notifications

**Notifications** (`/admin/notifications`) is a per-user inbox for system alerts (e.g. critical review emails mirrored in-app). Users with **`notifications.view`** see their notifications. **`notifications.manage`** allows mark-all-read, mark read, and delete.

---

## 7. Users & roles (Super Admin)

**Users** (`/admin/users`, **`users.manage`**) — create, edit, and delete dashboard users; assign one or more RBAC roles.

**Roles** (`/admin/roles`, **`roles.manage`**) — create custom roles and assign granular permissions. System roles (`super_admin`, `project_manager`, `security_auditor`, `developer`) are protected.

### View vs manage permissions

RBAC uses **`*.view`** (read module/list) and **`*.manage`** (create, edit, delete, or mutate). Example: **`projects.view`** without **`projects.manage`** shows the project list but hides Create and row Edit/Delete actions. Missing a view permission hides the sidebar link and returns 403 on direct URL access.

### Data visibility model

The platform is a **single-organization** control plane. Projects, repositories, reviews, and findings are **shared system data** visible to any role with the corresponding view permission. RBAC controls **which modules and actions** a role may use, not isolation by creator or role.

---

## 8. MFA (SEC-008)

**Two-Factor Auth** (`/two-factor` in the sidebar):

1. Enable MFA → scan QR / enter secret in an authenticator app.
2. Confirm with a TOTP code; store recovery codes securely.
3. Login requires password + `/two-factor-challenge` when MFA is enabled on the account.
4. Disable requires a valid TOTP or recovery code.

MFA is **opt-in per user** (not forced globally). VPN for remote access is an **infrastructure** control outside the application.

---

## 9. Integrations

**Integrations** (`/admin/integrations`, **`integrations.manage`**) — rotate encrypted GitHub App, Trello, and Google Drive credentials (password confirmation). Live connectivity probes appear on **System Health**.

---

## 10. Backups

**Backups** (`/admin/backups`, **`settings.manage`**) — create, download, verify integrity (SHA-256), upload-restore, and restore existing archives (restore requires password confirmation).

> **Note:** The default **Project Manager** role does **not** include **`settings.manage`**. Backup UI is typically used by **Super Admin**. See [Backup_Restore_Runbook.md](./Backup_Restore_Runbook.md) for CLI alternatives (`backup:create`, `backup:verify`).

Daily automated backups run via the Laravel scheduler when VPS cron is configured.

---

## 11. RBAC — default system roles

| Role | Typical access |
|---|---|
| Super Admin | All modules, users, roles, backups, settings |
| Project Manager | Projects, repos, SRS manage/approve, reviews, findings, reports, integrations, notifications, health, job cancel |
| Security Auditor | Reviews, findings, reports, audit logs, health, read-only SRS, notifications (view) |
| Developer | **No admin dashboard** by default (UI-001). Views GitHub Check Runs, PR comments, and Trello results outside the panel |

SRS permissions: **`srs.view`**, **`srs.manage`**, **`srs.approve`**.

---

## 12. Sensitive action reauthentication (SEC-009)

Password confirmation (default 15-minute window in `config/auth.php`) for:

- Approving or rejecting an SRS revision
- Overriding a failed review
- Marking a finding false positive or accepted
- Activating a repository
- Restoring a system backup
- Rotating integration credentials

Affected screens show a **Confirm password** banner when the window has lapsed.

---

## 13. Exports (UI-005)

| Export | Permission | Notes |
|---|---|---|
| Reviews CSV/JSON | **`reports.view`** | From Reviews page header |
| Findings CSV/JSON | **`findings.view`** | From Findings page header |
| Audit CSV/JSON | **`audit.view`** | From Audit Logs / Analytics headers |

Up to **5,000 rows** per export. Exports honour `repository_id`, `status`, `severity`, `from`, and `to` query parameters where applicable. Findings exports include objective code, title, and impact when present.
