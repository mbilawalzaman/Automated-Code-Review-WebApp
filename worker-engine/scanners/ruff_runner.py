import json
import logging
import os
import subprocess
from typing import Any, List, Optional

from analyzers.path_exclusions import scanner_exclude_args
from analyzers.scan_path_scope import existing_workspace_paths
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper
from scanners.base_scanner import BaseScanner

logger = logging.getLogger("RuffRunner")

_DEFAULT_REMEDIATION = "Apply the Ruff-recommended fix."


def remediation_from_ruff_fix(fix: Any) -> str:
    """Ruff JSON `fix` is often an object; NormalizedFinding.remediation must be a string."""
    if fix is None:
        return _DEFAULT_REMEDIATION
    if isinstance(fix, str):
        text = fix.strip()
        return text or _DEFAULT_REMEDIATION
    if isinstance(fix, dict):
        message = fix.get("message")
        if isinstance(message, str) and message.strip():
            return message.strip()
        return _DEFAULT_REMEDIATION
    text = str(fix).strip()
    return text or _DEFAULT_REMEDIATION


class RuffRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "ruff"

    def scan(self, workspace_dir: str, paths: Optional[List[str]] = None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []
        if not os.path.isdir(workspace_dir):
            return findings

        targets: List[str]
        if paths is not None:
            targets = existing_workspace_paths(workspace_dir, paths)
            if not targets:
                logger.info("Ruff skipped: no changed Python files in scope.")
                return findings
        else:
            targets = [workspace_dir]

        cmd = ["ruff", "check", "--output-format", "json", *scanner_exclude_args("ruff"), *targets]
        try:
            res = subprocess.run(cmd, capture_output=True, text=True, timeout=120, cwd=workspace_dir if paths is not None else None)
            if not res.stdout.strip():
                return findings
            data = json.loads(res.stdout)
            if not isinstance(data, list):
                return findings
            for item in data:
                filename = sanitize_relative_path(item.get("filename") or "Unknown", workspace_dir)
                code = item.get("code") or "ruff"
                message = item.get("message") or "Ruff lint finding"
                loc = item.get("location") or {}
                findings.append(
                    NormalizedFinding(
                        title=f"Ruff: {code}",
                        severity=SeverityMapper.normalize(str(item.get("severity") or "WARNING"), default="medium"),
                        file_path=filename,
                        line_start=int(loc.get("row") or 1),
                        line_end=int((item.get("end_location") or {}).get("row") or loc.get("row") or 1),
                        description=str(message),
                        tool_name=self.tool_name,
                        tool_version=self.tool_version,
                        rule_id=str(code),
                        confidence=0.8,
                        remediation=remediation_from_ruff_fix(item.get("fix")),
                        raw_output={"code": code, "message": message},
                    )
                )
        except FileNotFoundError:
            logger.info("Ruff not installed; skipping Python adapter.")
        except Exception as exc:
            logger.warning(f"Ruff scan failed gracefully: {exc}")

        return findings
