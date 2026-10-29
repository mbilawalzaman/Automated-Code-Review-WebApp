# Code Review Automation Platform

A production-ready, open-source, objective-aware automated code review platform for GitHub repositories. Operating through a secure VPS-hosted central dashboard and an always-on Mac Mini AI review worker.

---

## Key Features

- **Split Control/Worker Topology:** Source code stays isolated on the Mac Mini; only redacted findings and review metadata are stored on the VPS.
- **Multi-Scanner Review Pipeline:** Gitleaks (secrets), Semgrep (SAST), OSV-Scanner (dependencies), and local LLM evaluation via Ollama.
- **GitHub & Trello Integrations:** Automatic Check Runs, consolidated PR review summaries, branch protection enforcement (`main`/`staging`), and Trello board card updates.
- **Enterprise Security & Compliance:** Granular RBAC, immutable audit ledger, manual overrides with audit trail, and AES-256 encrypted daily system backups.
- **Real-Time Operational Health (OPS-001):** Heartbeat tracking, 30-minute stuck-job sweeper, outage alerting, and system health status.
- **Objective-Aware Review (Section 6):** SRS documents from upload, GitHub, or Google Drive; automatic requirement extraction into a checklist; versioned revisions that only affect reviews after Project Manager approval.

---

## Documentation

Full operational documentation is located in the [`docs/`](docs/) directory:

- [System Architecture](docs/Architecture.md)
- [API Documentation](docs/API_Documentation.md)
- [Administrator Guide](docs/Administrator_Guide.md)
- [Deployment Runbook](docs/Deployment_Runbook.md)
- [Operations Runbook](docs/Operations_Runbook.md)
- [Backup & Restoration Runbook](docs/Backup_Restore_Runbook.md)
- [Troubleshooting Guide](docs/Troubleshooting_Guide.md)
- [Incident Response Runbook](docs/Incident_Response_Runbook.md)
- [Upgrade Guide](docs/Upgrade_Guide.md)
- [Rollback Guide](docs/Rollback.md)

---

## Quick Start (VPS Web App)

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

Ensure the Laravel scheduler is added to crontab:
```cron
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

---

## License
Open-source software under the [MIT License](LICENSE).
