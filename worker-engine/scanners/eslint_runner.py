import json
import logging
import os
import subprocess
from typing import List, Optional

from analyzers.path_exclusions import scanner_exclude_args
from analyzers.scan_path_scope import existing_workspace_paths
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper
from scanners.base_scanner import BaseScanner

logger = logging.getLogger("ESLintRunner")


class ESLintRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "eslint"

    def scan(self, workspace_dir: str, paths: Optional[List[str]] = None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []
        if not os.path.isdir(workspace_dir):
            return findings

        if paths is not None:
            targets = existing_workspace_paths(workspace_dir, paths)
            if not targets:
                logger.info("ESLint skipped: no changed JS/TS files in scope.")
                return findings
        else:
            targets = ["."]

        cmd = [
            "npx",
            "--yes",
            "eslint",
            *targets,
            "--format",
            "json",
            *scanner_exclude_args("eslint"),
        ]
        try:
            res = subprocess.run(
                cmd,
                cwd=workspace_dir,
                capture_output=True,
                text=True,
                timeout=180,
                env=self._safe_env(),
            )
            payload = res.stdout.strip()
            if not payload:
                return findings
            data = json.loads(payload)
            if not isinstance(data, list):
                return findings
            for file_result in data:
                file_path = sanitize_relative_path(file_result.get("filePath") or "Unknown", workspace_dir)
                for message in file_result.get("messages", []):
                    severity = "high" if int(message.get("severity") or 1) >= 2 else "medium"
                    rule = message.get("ruleId") or "eslint"
                    findings.append(
                        NormalizedFinding(
                            title=f"ESLint: {rule}",
                            severity=SeverityMapper.normalize(severity, default="medium"),
                            file_path=file_path,
                            line_start=int(message.get("line") or 1),
                            line_end=int(message.get("endLine") or message.get("line") or 1),
                            description=str(message.get("message") or "ESLint finding"),
                            tool_name=self.tool_name,
                            tool_version=self.tool_version,
                            rule_id=str(rule),
                            confidence=0.75,
                            remediation="Apply the ESLint rule guidance or adjust project config.",
                            raw_output={"ruleId": rule, "message": message.get("message")},
                        )
                    )
        except FileNotFoundError:
            logger.info("npx/eslint not available; skipping JS adapter.")
        except Exception as exc:
            logger.warning(f"ESLint scan failed gracefully: {exc}")

        return findings

    def _safe_env(self) -> dict:
        env = os.environ.copy()
        for key in list(env.keys()):
            upper = key.upper()
            if any(token in upper for token in ("TOKEN", "SECRET", "PASSWORD", "PRIVATE_KEY", "API_KEY")):
                env.pop(key, None)
        return env
