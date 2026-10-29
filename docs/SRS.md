Code Review Automation
Software Requirements Specification (SRS)
A production-ready, open-source, objective-aware code review system for GitHub repositories, operated through a secure central dashboard and a locally hosted AI review worker.

Document field
Value
Version
1.0
Status
Approved requirements baseline
Date
15 July 2026
Primary owner
Project Manager
Primary scope
Automated code review and security assurance
Future compatibility
Mission Control module integration without redesign

1. Purpose and Objective
   The system shall replace the current manual repository review process with a meticulous, evidence-based automated workflow. It shall review every commit and pull request for alignment with approved project objectives, security risks, functional defects, maintainability, testing gaps, dependency risks, and wider change impact.
   The platform must work whether a repository has no deployment, has no live website, is being reviewed before any server push, or already uses CI/CD and live environments.
2. Scope
   2.1 In Scope
   Manual connection and activation of GitHub repositories through a VPS-hosted dashboard.
   Full baseline review when a repository is first connected.
   Automated review of every commit and pull request on every branch.
   Objective/SRS-aware review when an approved requirements document exists.
   Technical and security-only review when no objective/SRS exists.
   Open-source deterministic scanners plus a locally hosted open-source AI reviewer.
   GitHub checks and consolidated reviews, dashboard reporting, Trello summaries, and critical email alerts.
   Merge protection, human approvals, audited overrides, evidence retention, and encrypted backups.
   Compatibility with repositories that have CI/CD and repositories that do not.
   2.2 Out of Scope
   Automatic merging or deployment.
   Automatic modification or committing of repository code.
   Automatic execution of untrusted contributor code before human approval.
   Sending source code to external AI providers.
   Building unrelated agents or the full Mission Control platform in this phase.
   2.3 Mission Control Provision
   Code Review Automation is the complete implementation priority. Its architecture shall expose stable module interfaces so it can later join one Mission Control platform with shared projects, identities, permissions, documents, alerts, and audit logs. No wider multi-agent orchestration is required in this phase.
3. Stakeholders and Roles
   Role
   Responsibilities
   Access
   Project Manager
   Connect repositories; approve SRS checklists; review findings; approve pull requests; manage overrides and integrations.
   Full dashboard control
   Developer
   Push code; view GitHub and Trello results; address findings.
   No dashboard access by default
   GitHub App
   Receive events; read code; publish checks and consolidated reviews; enforce approved settings.
   Least privilege
   Mac Review Worker
   Clone/review code; run local models and tools; return redacted results.
   Outbound connection only
   Trello Automation Account
   Find project cards and post one consolidated review message.
   Connected boards only

4. System Architecture
   The system shall use a split architecture. The control plane and dashboard run on the VPS. Source-code analysis runs on the always-on Mac mini. Source code remains on the Mac mini, while only redacted findings and review metadata are sent to the VPS.
   Component
   Location
   Purpose
   Web dashboard and API
   VPS
   Repository management, job control, findings, approvals, reporting, audit logs.
   Database and queue
   VPS
   Persist metadata, configuration, jobs, findings, integrations, and audit history.
   Review worker
   Mac mini
   Repository checkout, deterministic scans, test execution, impact analysis, local AI review.
   Local AI runtime
   Mac mini
   Objective-aware and contextual review using open-source models.
   GitHub integration
   GitHub App
   Webhooks, checks, branch protection, reviews, repository discovery.
   Trello integration
   Dedicated account
   Card matching and one bulk message per completed review.
   Document integration
   Restricted Drive folder/GitHub
   Objective and SRS ingestion and versioning.

ARCH-001 The Mac worker shall initiate encrypted outbound connections to the VPS; no public inbound port shall expose the Mac mini.
ARCH-002 Review jobs shall queue securely while the Mac worker is unavailable and resume automatically after reconnection.
ARCH-003 The Mac worker shall process repositories sequentially to prevent resource exhaustion.
ARCH-004 The worker runs directly on macOS under the main user account. This accepted risk must be documented and mitigated through execution controls, restrictive credentials, isolated workspaces, timeouts, and static-first review. 5. Repository Onboarding and Configuration
ONB-001 The dashboard shall discover available repositories but shall not review them until manually connected and activated by the Project Manager.
ONB-002 Disconnected repositories shall remain completely unreviewed.
ONB-003 Before activation, the dashboard shall show proposed webhooks, checks, permissions, and branch-protection changes for approval.
ONB-004 The system shall integrate with existing CI/CD without replacing or disrupting existing pipelines.
ONB-005 Each repository shall support configuration of its objective/SRS, Trello board and list, notification settings, review rules, and relevant project metadata.
ONB-006 The Project Manager shall be able to pause reviews without disconnecting the repository.
ONB-007 The dashboard shall provide Review Now for any connected repository and branch.
ONB-008 Initial activation shall trigger a complete baseline review of the repository. 6. Objective and SRS Management
SRS-001 Objectives and SRS documents may be provided by dashboard upload, GitHub link, Google Drive link, or a combination.
SRS-002 Each document revision shall be versioned and require Project Manager approval before affecting reviews.
SRS-003 The system shall extract reviewable requirements into a checklist and require approval of that checklist before use.
SRS-004 When no approved objective or SRS is available, the system shall continue with technical, quality, testing, and security review only.
SRS-005 Confirmed non-compliance with an approved mandatory requirement shall block merging.
SRS-006 Every requirement-related finding shall identify the relevant requirement and explain the evidence of non-compliance. 7. Review Triggers and Workflow
WF-001 Reviews shall run on every commit and pull request on every branch.
WF-002 A successful commit without a pull request shall receive a pass status only; the system shall not create, approve, or merge a pull request.
WF-003 Successful automated review shall not replace mandatory human pull-request approval.
WF-004 Direct pushes to main and staging shall be blocked through approved branch-protection rules.
WF-005 When a fix is pushed, the system shall re-review affected code and verify previous findings automatically.
WF-006 Large, high-risk, authentication, authorisation, CI/CD, secrets, deployment, database, and security-control changes shall receive enhanced review.
WF-007 Unknown or untrusted contributor code shall receive static review first and shall not execute until human approval.
WF-008 Failed jobs shall retry safely without duplicating GitHub, Trello, email, or dashboard results. 8. Review Coverage
Each applicable review shall cover the following areas:
Area
Required analysis
Correctness
Logic defects, edge cases, error handling, regressions, incomplete implementation.
Objective alignment
Approved mandatory SRS requirements and accepted project checklist.
Security
SAST, secrets, injection, authentication, authorisation, cryptography, data exposure, malicious behaviour.
Dependencies
Known vulnerabilities, malicious packages, dependency confusion, typosquatting, unsafe install scripts.
Change impact
Affected files, modules, APIs, databases, integrations, workflows, and security controls.
Quality
Architecture, maintainability, complexity, duplication, standards, documentation.
Testing
Available tests, changed-code coverage, missing tests, flaky tests, regression risk.
Operations
GitHub Actions, Docker, deployment scripts, server configuration, observability, rollback impact.
Compliance
Privacy, personal data, licences, accessibility where applicable.
Performance
Repository-specific baselines and material regression risks.

REV-001 Reviews shall focus on changed code and perform repository-wide impact analysis.
REV-002 Generated files, vendor code, and dependency sources shall be excluded from normal style review unless directly relevant.
REV-003 The system shall detect backdoors, obfuscation, unauthorised network calls, suspicious persistence, and data-exfiltration risks.
REV-004 Unsigned or unverified commits shall be warnings and shall not automatically block merging.
REV-005 Repository-specific coding standards shall take precedence; recognised language standards shall be used as fallback rules. 9. Deterministic Tooling and Local AI
AI-001 The system shall automatically detect languages and frameworks and select compatible open-source scanners and review tools.
AI-002 Tool versions shall be pinned and updated only after compatibility and security verification.
AI-003 Local open-source AI models shall run on the Mac mini; repository code shall not be transmitted to external AI providers.
AI-004 AI findings shall not independently block merging unless supported by deterministic evidence or human confirmation.
AI-005 Low-confidence AI findings shall remain visible warnings unless verified.
AI-006 Each review shall record the exact tools, versions, configurations, and rules used.
AI-007 The system shall deduplicate overlapping findings from multiple scanners and AI analysis. 10. Testing and Coverage
TST-001 The system shall run all relevant tests already supplied by the repository when execution is authorised and dependencies are available.
TST-002 Applicable test categories include unit, integration, API, database, migration, authentication, authorisation, security, regression, build, and syntax tests.
TST-003 Coverage shall be measured primarily against changed code, while overall coverage remains visible.
TST-004 A material coverage reduction affecting Critical or High-risk logic shall block merging.
TST-005 Missing tests and coverage gaps shall be reported with suggested tests for human approval.
TST-006 The system shall not automatically commit generated tests.
TST-007 Flaky tests shall be detected, tracked, and reported separately. 11. Security Controls
SEC-001 Confirmed secrets, API keys, passwords, tokens, private keys, and credentials shall block approval.
SEC-002 Confirmed Critical and High dependency vulnerabilities shall block approval.
SEC-003 Sensitive values shall be redacted from GitHub, Trello, email, logs, exports, and dashboard displays.
SEC-004 GitHub shall use a dedicated GitHub App with least-privilege permissions.
SEC-005 Google Drive access shall be restricted to one dedicated project-documents folder.
SEC-006 Trello shall use a dedicated automation account with access only to connected project boards.
SEC-007 All credentials shall be encrypted, least-privileged, and rotatable without rebuilding the platform.
SEC-008 Remote dashboard access shall require a private VPN and multi-factor authentication.
SEC-009 Security-sensitive dashboard actions shall require reauthentication.
SEC-010 Changes to CI/CD permissions, secrets handling, or deployment logic shall require explicit Project Manager approval. 12. Database, API, and Irreversible Changes
CHG-001 Destructive migrations and irreversible data changes shall block merging until explicitly approved.
CHG-002 Relevant backups shall be verified before an approved destructive database change proceeds.
CHG-003 Confirmed breaking API contract changes shall block merging unless explicitly approved and documented.
CHG-004 Authentication and authorisation changes shall undergo enhanced security and regression review. 13. Findings, Severity, and Status
Status
Meaning
Merge effect
Passed
All mandatory checks completed with no blocking finding.
Eligible for human approval
Passed with Warnings
No blocking finding; unresolved Medium, Low, or accepted warning exists.
Eligible for human approval
Failed
At least one verified blocking finding exists.
Blocked
Review Error
A mandatory scanner, test, or review stage did not complete.
Blocked

Severity
Default treatment
Critical
Blocks merging; immediate email alert.
High
Blocks merging.
Medium
Persistent visible warning.
Low
Persistent visible warning.

FND-001 Every finding shall include severity, affected file and line, evidence, objective impact where applicable, and recommended remediation.
FND-002 Recurring unresolved findings shall remain linked across commits.
FND-003 Verified fixes shall close findings automatically while preserving history.
FND-004 False positives require a reason and permanent audit record.
FND-005 Accepted or ignored issues shall remain visible as warnings until fixed.
FND-006 The Project Manager may override a failed review only with a mandatory reason and permanent audit record. 14. GitHub Integration
GIT-001 The GitHub App shall receive commit and pull-request events and create check results for every review.
GIT-002 Review findings shall be posted as one consolidated GitHub review rather than numerous individual comments.
GIT-003 Outdated findings shall be updated or resolved automatically after verification.
GIT-004 The system may generate suggested patches but shall never commit, push, approve, merge, or deploy changes automatically.
GIT-005 Existing pipelines shall remain authoritative and operational unless separately changed through an approved project. 15. Trello Integration
TRL-001 Each repository shall have a configured Trello board and default list where applicable.
TRL-002 Card matching shall use branch names, commit messages, pull-request data, and linked card references.
TRL-003 If a relevant card exists, the result shall be posted there; otherwise, the system shall create one new card.
TRL-004 Each completed review shall produce exactly one consolidated Trello message containing the entire bulk result.
TRL-005 The integration shall prevent duplicate cards and comment flooding during retries or multi-scanner processing.
TRL-006 All result types—Pass, Passed with Warnings, Failed, and Review Error—shall be reported. 16. Dashboard Requirements
UI-001 The VPS-hosted dashboard shall be accessible only to the Project Manager.
UI-002 The dashboard shall display project, repository, branch, commit, pull request, review status, findings, evidence, tool execution, and audit history.
UI-003 Filters shall include project, repository, branch, developer, severity, status, and date.
UI-004 The dashboard shall show security, quality, coverage, failure, and resolution trends over time.
UI-005 Reports shall be exportable as PDF, CSV, and JSON.
UI-006 The dashboard shall expose repository connection, pause, Review Now, SRS approval, Trello mapping, override, and integration-health controls.
UI-007 Warnings shall remain visible until the underlying issue is verified as fixed. 17. Notifications and Reporting
NOT-001 Critical failures shall trigger immediate email alerts.
NOT-002 Email alerts shall contain redacted summaries and shall not expose sensitive code or credentials.
NOT-003 Each completed review shall publish one coherent result to each configured destination.
NOT-004 Reports, evidence, and audit logs shall be retained until manually deleted. 18. Auditability and Data Management
AUD-001 Every connection, configuration change, document approval, finding state change, override, and security-sensitive action shall be auditable.
AUD-002 Audit records shall contain actor, timestamp, action, target, reason where required, and before/after state where applicable.
AUD-003 Source code shall remain on the Mac mini; the VPS shall store redacted findings, review metadata, configuration, and audit data only.
AUD-004 Dashboard data and audit logs shall receive encrypted daily backups.
AUD-005 Backup restoration shall be tested before final acceptance. 19. Reliability and Operations
OPS-001 The platform shall monitor the Mac worker, VPS dashboard, API, database, queue, backups, and external integrations.
OPS-002 Job execution shall be idempotent so retries cannot create duplicate reports, cards, or messages.
OPS-003 Jobs shall use defined timeouts, cancellation handling, and resource limits.
OPS-004 Failure of a mandatory tool shall produce Review Error rather than a false pass.
OPS-005 The system shall retain sufficient logs to diagnose failures without exposing secrets. 20. Non-Functional Requirements
Category
Requirement
Security
Least privilege, encryption in transit and at rest, MFA, VPN, redaction, credential rotation, auditable approvals.
Privacy
No source-code transmission to external AI providers; minimal metadata on VPS; restricted document access.
Extensibility
Modular interfaces compatible with future Mission Control integration.
Maintainability
Documented architecture, configuration, tool adapters, test suite, runbooks, and upgrade process.
Scalability
Sequential Mac execution with durable queueing; additional workers may be introduced later without redesign.
Usability
Clear statuses, evidence, remediation guidance, filters, exports, and minimal notification noise.
Portability
Open-source components and documented installation without dependence on a proprietary AI service.

21. Suggested Open-Source Tool Categories
    The implementation team shall select and justify maintained open-source tools based on detected languages and frameworks. The architecture must support adapters rather than hard-coded dependence on one scanner.
    Capability
    Example open-source options
    Multi-language SAST
    Semgrep Community rules, CodeQL CLI where licensing and use permit
    Secrets detection
    Gitleaks, TruffleHog
    Dependencies and SBOM
    OSV-Scanner, Syft, Grype, Trivy
    Infrastructure as code
    Checkov, Trivy configuration scanning
    Python
    Ruff, Bandit, mypy, pytest, coverage.py
    PHP/Laravel
    PHPStan, Psalm, PHP-CS-Fixer, PHPUnit, Composer audit
    JavaScript/TypeScript
    ESLint, TypeScript compiler, framework-native test tools
    Local AI runtime
    Ollama or llama.cpp-compatible runtime with approved open-source model

Final tools and versions must be documented, pinned, tested, and approved during implementation. Examples above are not permission to introduce proprietary source-code processing. 22. Acceptance Criteria
AC-001 A repository without CI/CD can be connected manually, baseline-reviewed, and reviewed on subsequent commits.
AC-002 A repository with existing CI/CD integrates without pipeline disruption.
AC-003 Every commit and pull request on every branch produces exactly one final review status.
AC-004 Verified Critical or High findings block merging; Medium and Low findings remain warnings.
AC-005 A missing mandatory scanner or test produces Review Error and cannot pass.
AC-006 An approved SRS checklist is used in objective-aware review; absent SRS falls back to technical and security review.
AC-007 Source code remains on the Mac mini and is not sent to the VPS or an external AI provider.
AC-008 Trello receives no more than one consolidated message for a completed review.
AC-009 Untrusted code is not executed before static review and human approval.
AC-010 Failed job retries do not duplicate GitHub reviews, Trello cards, messages, emails, or findings.
AC-011 Daily encrypted backups complete successfully and a restoration test succeeds.
AC-012 The complete platform passes security verification and testing on real repositories.
AC-013 Documentation covers deployment, configuration, integrations, operations, incident response, backup, restore, updates, and troubleshooting. 23. Required Deliverables
Complete source code in a private GitHub repository.
VPS dashboard and API deployment.
Mac mini review worker and local AI runtime deployment.
GitHub App and branch-protection integration.
Restricted Google Drive and Trello automation integrations.
Database schema, migrations, queue, and encrypted backup system.
Automated test suite and security test evidence.
Architecture documentation, API documentation, administrator guide, deployment runbook, backup/restore runbook, upgrade guide, and troubleshooting guide.
Final demonstration using real connected repositories with and without existing CI/CD.
Full handover with no undocumented dependency on the developer. 24. Constraints and Assumptions
The solution shall use open-source review tools and locally hosted open-source AI models.
The Mac mini remains powered on and connected 24/7.
The Mac worker runs directly under the main macOS account; this is an explicitly accepted risk, not a recommended security posture.
Repositories are activated manually through the dashboard.
Human approval remains mandatory for pull requests and consequential actions.
The dashboard runs on the VPS; the Mac mini is not publicly exposed.
Mission Control compatibility is required architecturally, but wider agent implementation is deferred. 25. Definition of Done
The project is complete only when every mandatory requirement is implemented, deployed, tested against real repositories, security-verified, documented, demonstrated, and formally accepted by the Project Manager. A prototype, partial workflow, scanner-only implementation, dashboard mock-up, or unverified local build does not satisfy this SRS.
