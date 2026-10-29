"""Unsigned / unverified commit warnings (REV-004)."""

from __future__ import annotations

import logging
import subprocess
from typing import List

from models.finding import NormalizedFinding

logger = logging.getLogger("CommitVerificationAnalyzer")


def analyze_commit_signature(workspace_dir: str, commit_sha: str) -> List[NormalizedFinding]:
    """Emit a non-blocking warning when the commit is unsigned or unverified."""
    if not workspace_dir or not commit_sha or commit_sha == "HEAD":
        return []

    try:
        result = subprocess.run(
            ["git", "verify-commit", commit_sha],
            cwd=workspace_dir,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        logger.warning("git verify-commit failed: %s", exc)
        return []

    # Exit 0 => good signature. Non-zero => unsigned / unverified / bad.
    if result.returncode == 0:
        return []

    detail = (result.stderr or result.stdout or "Commit signature could not be verified.").strip()
    return [
        NormalizedFinding(
            title="Unsigned or unverified commit",
            severity="medium",
            file_path=".",
            line_start=1,
            description=(
                f"REV-004: Commit {commit_sha[:12]} is unsigned or could not be verified. "
                f"Detail: {detail[:500]}"
            ),
            tool_name="commit-verification",
            tool_version="1.0.0",
            rule_id="REV-004",
            remediation="Sign commits with GPG/SSH. This warning does not by itself block merging.",
            is_blocking_override=False,
            raw_output={"change_class": "unsigned_commit", "verify_exit_code": result.returncode},
        )
    ]
