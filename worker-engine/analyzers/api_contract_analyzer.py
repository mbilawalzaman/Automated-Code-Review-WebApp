"""Heuristic breaking API contract detection (CHG-003)."""

from __future__ import annotations

import re
from typing import List, Set

from models.finding import NormalizedFinding

ROUTE_REMOVED = re.compile(
    r"^-\s*Route::(get|post|put|patch|delete|any|match|resource|apiResource)\s*\(",
    re.I | re.M,
)
CONTROLLER_METHOD_REMOVED = re.compile(
    r"^-\s*public\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(",
    re.M,
)
REQUEST_RULE_REMOVED = re.compile(
    r"^-\s*['\"]([a-zA-Z0-9_.]+)['\"]\s*=>\s*\[",
    re.M,
)


def _is_api_path(path: str) -> bool:
    norm = path.replace("\\", "/")
    return (
        norm.startswith("routes/")
        or "/Http/Controllers/" in f"/{norm}"
        or "/Http/Requests/" in f"/{norm}"
        or norm.endswith("Controller.php")
    )


def _files_in_diff(diff_text: str) -> Set[str]:
    files: Set[str] = set()
    for line in diff_text.splitlines():
        if line.startswith("+++ b/") or line.startswith("--- a/"):
            path = line[6:].strip().replace("\\", "/")
            if path != "/dev/null" and _is_api_path(path):
                files.add(path)
    return files


def analyze_api_contract(diff_text: str) -> List[NormalizedFinding]:
    findings: List[NormalizedFinding] = []
    if not diff_text.strip():
        return findings

    route_removals = ROUTE_REMOVED.findall(diff_text)
    method_removals = CONTROLLER_METHOD_REMOVED.findall(diff_text)
    rule_removals = REQUEST_RULE_REMOVED.findall(diff_text)

    # Restrict method/rule hits to API-related files only by checking hunk headers nearby is hard;
    # require at least one API-related path in the diff.
    api_files = _files_in_diff(diff_text)
    if not api_files:
        return findings

    signals: List[str] = []
    if route_removals:
        signals.append(f"removed Route:: declarations ({len(route_removals)})")
    if method_removals:
        signals.append(f"removed controller methods ({len(set(method_removals))})")
    if rule_removals:
        signals.append(f"removed FormRequest rules ({len(set(rule_removals))})")

    # High confidence when routes or public controller methods are removed.
    high_confidence = bool(route_removals) or bool(method_removals)
    if not signals:
        return findings

    primary = sorted(api_files)[0]
    findings.append(
        NormalizedFinding(
            title="Potential breaking API contract change",
            severity="high" if high_confidence else "medium",
            file_path=primary,
            line_start=1,
            description=(
                "CHG-003: Diff suggests a breaking API contract change: "
                + "; ".join(signals)
                + ". Confirm documentation and obtain Project Manager approval before merge."
            ),
            tool_name="api-contract-analyzer",
            tool_version="1.0.0",
            rule_id="CHG-003",
            remediation=(
                "Document the breaking change for consumers, then apply a PM override with a "
                "documentation URL or notes before merging."
            ),
            is_blocking_override=high_confidence,
            raw_output={
                "change_class": "api_contract",
                "signals": signals,
                "files": sorted(api_files),
                "removed_methods": sorted(set(method_removals)),
            },
        )
    )

    return findings
