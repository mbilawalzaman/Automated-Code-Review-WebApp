# System Architecture & Topology

## Overview
The Code Review Automation Platform utilizes a **split control-plane / worker-plane topology** designed for data privacy, source-code isolation, and scalable objective-aware code reviews.

```
┌─────────────────────────────────────────────────────────────────┐
│                     VPS CONTROL PLANE (Web App)                  │
│                                                                 │
│  ┌──────────────┐   ┌──────────────┐   ┌─────────────────────┐  │
│  │ Admin UI /   │   │  Worker API  │   │ Audit Ledger &      │  │
│  │ Dashboard    │   │  (/api/v1)   │   │ Encrypted Backups   │  │
│  └──────────────┘   └──────────────┘   └─────────────────────┘  │
│         │                  ▲                      ▲             │
└─────────┼──────────────────┼──────────────────────┼─────────────┘
          │                  │                      │
          │ GitHub Webhooks  │ Outbound HTTPS Pings │ Encrypted AES-256
          ▼                  │ & Result Submissions │ Backups
┌───────────────────┐        │                      │
│   GitHub App &    │        │                      │
│ Check Runs API    │        │                      │
└───────────────────┘        │                      │
                             │                      │
┌────────────────────────────┴──────────────────────┴─────────────┐
│                    MAC MINI REVIEW WORKER                       │
│                                                                 │
│  ┌──────────────────────┐    ┌───────────────────────────────┐  │
│  │ Git Workspace        │    │ Local Scanners & AI Runtime   │  │
│  │ (/tmp/scanner-...)   │    │ (Gitleaks, Semgrep, Ollama)   │  │
│  └──────────────────────┘    └───────────────────────────────┘  │
│                                                                 │
│  * Source code cloned temporarily on Mac Mini ONLY.             │
│  * Source code is NEVER transmitted to VPS or external AI APIs. │
└─────────────────────────────────────────────────────────────────┘
```

---

## Key Components

### 1. VPS Control Plane (Laravel WebApp)
- **Role:** Management dashboard, project onboarding, GitHub webhook receiver, job queue manager, findings registry, PDF report generation, audit ledger, and encrypted backup vault.
- **Security Boundary:** Stores redacted findings, audit logs, and metadata. Source code is **never** stored on or transmitted to the VPS.

### 2. Mac Mini Review Worker (Python Engine)
- **Role:** Sequential review job execution, shallow git clones, multi-scanner static analysis (Gitleaks, Semgrep, OSV-Scanner), test execution, and local LLM review via Ollama.
- **Connection Model:** Initiates outbound-only HTTPS requests (`POST /api/v1/worker/jobs/next`, `POST /api/v1/worker/heartbeat`, `POST /api/v1/worker/jobs/{id}/result`). No public inbound ports are exposed on the Mac Mini.

---

## Security & Data Isolation
- **Source Code Protection (AUD-003 / SEC-003):** Repositories are cloned into ephemeral `/tmp/scanner-workspaces/job_<uuid>` directories on the Mac Mini and completely purged via `shutil.rmtree()` immediately upon scan completion.
- **Redaction Pipeline (SEC-003):** Secrets, credentials, API keys, and sensitive tokens are automatically redacted prior to database storage or notification dispatch.
- **Zero External AI Exposure (AI-003 / NFR-242):** All AI evaluations run on local open-source models (CodeLlama / Llama-3) hosted via Ollama directly on the Mac Mini.
