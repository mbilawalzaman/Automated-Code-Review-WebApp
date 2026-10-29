"""Prioritize and optionally cap findings before VPS submission."""

from __future__ import annotations

import os
from typing import Any, Dict, List, Tuple

from models.finding import NormalizedFinding

_SEVERITY_RANK = {
    "critical": 0,
    "high": 1,
    "medium": 2,
    "low": 3,
    "info": 4,
}

# Soft safety cap after scoping; chunked upload handles large sets safely.
DEFAULT_SUBMIT_MAX = int(os.getenv("FINDINGS_SUBMIT_MAX", "500"))


def prioritize_findings(
    findings: List[NormalizedFinding],
    submit_max: int | None = None,
) -> Tuple[List[NormalizedFinding], Dict[str, Any]]:
    """
    Order by blocking → severity → tool, then optionally truncate.

    Returns (selected_findings, metrics) where metrics includes totals/truncated counts.
    """
    limit = DEFAULT_SUBMIT_MAX if submit_max is None else max(0, int(submit_max))
    total = len(findings)

    ordered = sorted(
        findings,
        key=lambda f: (
            0 if f.is_blocking() else 1,
            _SEVERITY_RANK.get((f.severity or "medium").lower(), 9),
            (f.tool_name or ""),
            (f.file_path or ""),
            int(f.line_start or 1),
        ),
    )

    if limit <= 0 or total <= limit:
        return ordered, {
            "findings_total": total,
            "findings_submitted": total,
            "findings_truncated": 0,
            "truncated": False,
        }

    selected = ordered[:limit]
    return selected, {
        "findings_total": total,
        "findings_submitted": len(selected),
        "findings_truncated": total - len(selected),
        "truncated": True,
    }


def slim_finding_dict(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Drop bulky raw_output from wire payload to shrink POST size."""
    slim = dict(payload)
    slim.pop("raw_output", None)
    return slim
