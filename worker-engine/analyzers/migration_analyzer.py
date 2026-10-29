"""Detect destructive Laravel/SQL migration patterns (CHG-001)."""

from __future__ import annotations

import os
import re
from typing import List, Tuple

from models.finding import NormalizedFinding

DESTRUCTIVE_PATTERNS: List[Tuple[str, re.Pattern[str]]] = [
    ("DROP TABLE", re.compile(r"\bdrop\s+table\b|\bdropIfExists\s*\(", re.I)),
    ("DROP COLUMN", re.compile(r"\bdrop\s+column\b|->dropColumn\s*\(|->dropConstrainedForeignId\s*\(", re.I)),
    ("TRUNCATE", re.compile(r"\btruncate\b", re.I)),
    ("RENAME COLUMN", re.compile(r"\brename\s+column\b|->renameColumn\s*\(", re.I)),
    ("DROP INDEX", re.compile(r"\bdrop\s+index\b|->dropIndex\s*\(|->dropUnique\s*\(|->dropForeign\s*\(", re.I)),
]


def _changed_migration_files(diff_text: str) -> List[str]:
    files: List[str] = []
    for line in diff_text.splitlines():
        if line.startswith("+++ b/") or line.startswith("+++ b\\"):
            path = line[6:].strip()
            if "database/migrations/" in path.replace("\\", "/") and path.endswith(".php"):
                files.append(path.replace("\\", "/"))
    return sorted(set(files))


def _added_lines_for_file(diff_text: str, file_path: str) -> str:
    """Collect added (+) lines for a specific file from a unified diff."""
    lines: List[str] = []
    current = None
    needle = file_path.replace("\\", "/")
    for line in diff_text.splitlines():
        if line.startswith("+++ b/") or line.startswith("+++ b\\"):
            current = line[6:].strip().replace("\\", "/")
            continue
        if current == needle and line.startswith("+") and not line.startswith("+++"):
            lines.append(line[1:])
    return "\n".join(lines)


def analyze_migrations(diff_text: str, workspace_dir: str | None = None) -> List[NormalizedFinding]:
    findings: List[NormalizedFinding] = []
    files = _changed_migration_files(diff_text)

    for path in files:
        added = _added_lines_for_file(diff_text, path)
        content = added
        if workspace_dir:
            abs_path = os.path.join(workspace_dir, path)
            if os.path.isfile(abs_path):
                try:
                    with open(abs_path, "r", encoding="utf-8", errors="ignore") as fh:
                        content = fh.read()
                except OSError:
                    pass

        matched: List[str] = []
        for label, pattern in DESTRUCTIVE_PATTERNS:
            if pattern.search(content):
                matched.append(label)

        if not matched:
            continue

        findings.append(
            NormalizedFinding(
                title=f"Destructive migration detected ({', '.join(matched)})",
                severity="critical",
                file_path=path,
                line_start=1,
                description=(
                    f"CHG-001: Migration introduces irreversible schema changes: {', '.join(matched)}. "
                    "Merging is blocked until a Project Manager approves and a recent backup is verified (CHG-002)."
                ),
                tool_name="migration-analyzer",
                tool_version="1.0.0",
                rule_id="CHG-001",
                remediation=(
                    "Obtain PM override with a mandatory reason, ensure BackupService::verifyBackup passes "
                    "on a recent encrypted backup, then re-check merge eligibility."
                ),
                is_blocking_override=True,
                raw_output={"patterns": matched, "change_class": "destructive_migration"},
            )
        )

    return findings
