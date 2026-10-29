# Incident Response Runbook

## Security & Operational Incident Handlers

### 1. Secret Exposure / Credential Compromise
1. Rotate affected credential immediately (`WORKER_API_TOKEN`, `GITHUB_TOKEN`, `TRELLO_TOKEN`).
2. Update `.env` on VPS control plane and Mac Mini worker.
3. Restart worker engine daemon.

### 2. Worker Machine Compromise / Isolation
1. Revoke `WORKER_API_TOKEN` on VPS control plane.
2. The VPS control plane will reject all incoming heartbeat/claim/submission calls with `401 Unauthorized`.
3. Re-image Mac Mini worker and issue new credentials.
