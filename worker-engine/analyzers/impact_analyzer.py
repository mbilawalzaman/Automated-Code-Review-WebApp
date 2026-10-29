"""Sensitive change + repository impact analysis (REV-001, WF-006, SEC-010, CHG-004)."""

from __future__ import annotations

import re
from typing import Any, Dict, List, Set

from models.finding import NormalizedFinding

AUTH_PATHS = re.compile(
    r"(Http/Middleware/Auth|Http/Controllers/Auth|Policies/|Gates?\.php|config/auth\.php|TwoFactor|EnsurePermission|EnsureUserRole)",
    re.I,
)
CICD_PATHS = re.compile(
    r"(\.github/workflows/|deploy-|\.yml$|\.yaml$|Dockerfile|docker-compose|scripts/deploy)",
    re.I,
)
SECRETS_PATHS = re.compile(
    r"(\.env\.example|config/services\.php|config/github\.php|credentials|secrets)",
    re.I,
)
SECURITY_PATHS = re.compile(
    r"(Redaction|Unauthorized|Security|Encrypt|Hash::|password\.confirm)",
    re.I,
)
MIGRATION_PATHS = re.compile(r"database/migrations/", re.I)
API_PATHS = re.compile(r"(routes/|Http/Controllers/|Http/Requests/)", re.I)


def _changed_files(diff_text: str) -> List[str]:
    files: Set[str] = set()
    for line in diff_text.splitlines():
        if line.startswith("+++ b/") or line.startswith("--- a/"):
            path = line[6:].strip().replace("\\", "/")
            if path and path != "/dev/null":
                files.add(path)
    return sorted(files)


def build_impact_analysis(diff_text: str) -> Dict[str, Any]:
    files = _changed_files(diff_text)
    modules: Set[str] = set()
    tables: Set[str] = set()
    apis: Set[str] = set()
    security: Set[str] = set()
    cicd: Set[str] = set()
    change_classes: Set[str] = set()

    for path in files:
        if MIGRATION_PATHS.search(path):
            modules.add("database")
            # Heuristic table name from migration filename.
            base = path.split("/")[-1]
            m = re.search(r"_(create|add|drop|update|alter)_(.+?)_table", base, re.I)
            if m:
                tables.add(m.group(2))
        if API_PATHS.search(path):
            modules.add("api")
            apis.add(path)
        if AUTH_PATHS.search(path) or SECURITY_PATHS.search(path):
            modules.add("security")
            security.add(path)
            if AUTH_PATHS.search(path):
                change_classes.add("auth")
        if CICD_PATHS.search(path):
            modules.add("ci_cd")
            cicd.add(path)
            change_classes.add("ci_cd")
        if SECRETS_PATHS.search(path):
            modules.add("secrets")
            change_classes.add("secrets")

    return {
        "changed_files": files,
        "affected_modules": sorted(modules),
        "database_tables": sorted(tables),
        "downstream_apis": sorted(apis)[:50],
        "security_controls": sorted(security)[:50],
        "ci_cd_pipelines": sorted(cicd)[:50],
        "change_classes": sorted(change_classes),
    }


def analyze_sensitive_changes(diff_text: str, impact: Dict[str, Any] | None = None) -> List[NormalizedFinding]:
    impact = impact or build_impact_analysis(diff_text)
    findings: List[NormalizedFinding] = []
    classes = set(impact.get("change_classes") or [])

    if "auth" in classes:
        primary = (impact.get("security_controls") or ["app/Http/Controllers/Auth"])[0]
        findings.append(
            NormalizedFinding(
                title="Authentication / authorisation change requires enhanced review",
                severity="high",
                file_path=primary,
                line_start=1,
                description=(
                    "CHG-004 / WF-006: Changes touch authentication or authorisation controls. "
                    "Enhanced security and regression review is required; PM approval is needed to merge."
                ),
                tool_name="impact-analyzer",
                tool_version="1.0.0",
                rule_id="CHG-004",
                remediation="Complete enhanced security review, then apply a PM override with rationale.",
                is_blocking_override=True,
                raw_output={"change_class": "auth"},
            )
        )

    if "ci_cd" in classes or "secrets" in classes:
        files = (impact.get("ci_cd_pipelines") or []) + [
            f for f in (impact.get("changed_files") or []) if SECRETS_PATHS.search(f)
        ]
        primary = files[0] if files else ".github/workflows"
        cls = "ci_cd" if "ci_cd" in classes else "secrets"
        findings.append(
            NormalizedFinding(
                title="CI/CD or secrets configuration change requires PM approval",
                severity="high",
                file_path=primary,
                line_start=1,
                description=(
                    "SEC-010 / WF-006: Changes modify CI/CD workflows, deployment scripts, or secrets configuration. "
                    "Explicit Project Manager approval is required before merge."
                ),
                tool_name="impact-analyzer",
                tool_version="1.0.0",
                rule_id="SEC-010",
                remediation="Obtain PM override after reviewing deployment and secret-handling impact.",
                is_blocking_override=True,
                raw_output={"change_class": cls},
            )
        )

    return findings
