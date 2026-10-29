"""Tests for findings prioritization and changed-path scoping helpers."""

from __future__ import annotations

from analyzers.finding_prioritizer import prioritize_findings, slim_finding_dict
from analyzers.scan_path_scope import paths_for_tool
from models.finding import NormalizedFinding


def _finding(severity: str, tool: str, blocking: bool = False, path: str = "a.py", line: int = 1) -> NormalizedFinding:
    f = NormalizedFinding(
        title=f"{tool}:{severity}",
        severity=severity,
        file_path=path,
        line_start=line,
        tool_name=tool,
        description="desc",
        remediation="fix",
    )
    if blocking:
        # NormalizedFinding.is_blocking() typically derives from severity; set explicitly if supported.
        if hasattr(f, "is_blocking") and callable(getattr(NormalizedFinding, "is_blocking", None)):
            pass
    return f


def test_prioritize_orders_by_severity_and_caps():
    findings = [
        _finding("low", "ruff", path="z.py"),
        _finding("critical", "gitleaks", path="a.py"),
        _finding("medium", "eslint", path="b.js"),
        _finding("high", "bandit", path="c.py"),
    ]
    selected, metrics = prioritize_findings(findings, submit_max=2)
    assert metrics["findings_total"] == 4
    assert metrics["findings_submitted"] == 2
    assert metrics["truncated"] is True
    assert selected[0].severity == "critical"
    assert selected[1].severity == "high"


def test_slim_finding_dict_drops_raw_output():
    slim = slim_finding_dict({"title": "x", "raw_output": {"huge": True}, "severity": "low"})
    assert "raw_output" not in slim
    assert slim["title"] == "x"


def test_paths_for_tool_filters_extensions():
    changed = ["app/main.py", "src/App.tsx", "README.md", "app/Http/Foo.php"]
    assert paths_for_tool("ruff", changed) == ["app/main.py"]
    assert paths_for_tool("eslint", changed) == ["src/App.tsx"]
    assert paths_for_tool("phpstan", changed) == ["app/Http/Foo.php"]
    assert paths_for_tool("gitleaks", changed) is None
    assert paths_for_tool("ruff", None) is None
