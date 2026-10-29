"""Stable finding fingerprints for cross-commit reconciliation (FND-002)."""

from __future__ import annotations

import hashlib
import re
from pathlib import Path
from typing import Optional

# Drop a leading temp workspace root: .../scanner-workspaces/<job_dir>/<repo-relative>
_SCANNER_WORKSPACE_RE = re.compile(
    r"(?:^|[/\\])scanner-workspaces[/\\]+[^/\\]+[/\\]+(.+)$",
    re.IGNORECASE,
)
# Fallback when only the job_* segment is present in the path.
_JOB_DIR_RE = re.compile(
    r"(?:^|[/\\])job_[a-f0-9]+[/\\]+(.+)$",
    re.IGNORECASE,
)
_LEADING_DOTDOT_RE = re.compile(r"^(\.\./)+")


def sanitize_relative_path(file_path: Optional[str], workspace_dir: Optional[str] = None) -> str:
    """
    Resolve absolute / temp-workspace paths to a repo-relative POSIX path.

    Prefer Path.resolve() + relative_to(workspace) when workspace_dir is known.
    Fall back to stripping known scanner workspace prefixes. Never leave a
    bare job_<uuid>/ segment on the returned path.
    """
    if not file_path:
        return "Unknown"

    clean_p = str(file_path).replace("\\", "/").strip()
    if not clean_p:
        return "Unknown"

    if workspace_dir:
        try:
            base = Path(workspace_dir).resolve()
            target_path = Path(clean_p)
            if not target_path.is_absolute():
                target_path = base / target_path
            rel = target_path.resolve().relative_to(base)
            cleaned = str(rel).replace("\\", "/")
            if cleaned and cleaned != ".":
                return cleaned
        except (ValueError, RuntimeError, OSError):
            pass

    match = _SCANNER_WORKSPACE_RE.search(clean_p)
    if match:
        clean_p = match.group(1)
    else:
        match = _JOB_DIR_RE.search(clean_p)
        if match:
            clean_p = match.group(1)

    clean_p = _LEADING_DOTDOT_RE.sub("", clean_p)
    clean_p = clean_p.lstrip("/")
    # Match historical fingerprint normalize: strip leading ./ segments only.
    while clean_p.startswith("./"):
        clean_p = clean_p[2:]

    return clean_p or "Unknown"


def normalize_path(path: Optional[str]) -> str:
    """Lowercased repo-relative path for fingerprint material (FND-002)."""
    if not path:
        return "unknown"
    # Keep fingerprinting aligned with already-sanitized relative paths.
    # Do not invent a workspace here — callers should sanitize before fingerprinting
    # when they have absolute scanner paths.
    return sanitize_relative_path(path).lower()


def fingerprint(
    rule_id: Optional[str],
    file_path: Optional[str],
    line_start: Optional[int] = None,
    title: Optional[str] = None,
) -> str:
    """
    sha256(rule_id + normalized_path + line_bucket + title_token).

    Line numbers drift slightly across edits, so we bucket by 5-line windows.
    """
    rule = (rule_id or "general").strip().lower()
    path = normalize_path(file_path)
    line = int(line_start or 1)
    bucket = max(1, ((line - 1) // 5) * 5 + 1)
    title_token = re.sub(r"\s+", " ", (title or "").strip().lower())[:40]
    material = f"{rule}|{path}|{bucket}|{title_token}"
    return hashlib.sha256(material.encode("utf-8")).hexdigest()
