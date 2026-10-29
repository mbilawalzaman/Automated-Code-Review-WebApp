import json
import logging
import os
import subprocess
from typing import List, Optional

from analyzers.scan_path_scope import existing_workspace_paths
from fingerprint import sanitize_relative_path
from mappers.severity_mapper import SeverityMapper
from models.finding import NormalizedFinding
from scanners.base_scanner import BaseScanner

logger = logging.getLogger("PHPStanRunner")


class PHPStanRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "phpstan"

    def scan(self, workspace_dir: str, paths: Optional[List[str]] = None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []
        if not os.path.isdir(workspace_dir):
            return findings

        binary = self._resolve_binary(workspace_dir)
        if not binary:
            logger.info("PHPStan not available; skipping language adapter.")
            return findings

        cmd = binary + ["analyse", "--error-format=json", "--no-progress", "--memory-limit=512M"]
        if paths is not None:
            targets = existing_workspace_paths(workspace_dir, paths)
            if not targets:
                logger.info("PHPStan skipped: no changed PHP files in scope.")
                return findings
            cmd.extend(targets)
        else:
            for candidate in ("app", "src", "lib"):
                if os.path.isdir(os.path.join(workspace_dir, candidate)):
                    cmd.append(candidate)

        try:
            res = subprocess.run(
                cmd,
                cwd=workspace_dir,
                capture_output=True,
                text=True,
                timeout=180,
            )
            payload = res.stdout.strip() or res.stderr.strip()
            if not payload:
                return findings

            data = json.loads(payload)
            files = data.get("files") or {}
            for file_path, file_data in files.items():
                rel = sanitize_relative_path(file_path, workspace_dir)
                for message in file_data.get("messages", []):
                    severity = SeverityMapper.normalize(str(message.get("tip") or "error"), default="medium")
                    if message.get("ignorable") is False and severity == "low":
                        severity = "medium"
                    findings.append(
                        NormalizedFinding(
                            title=f"PHPStan: {str(message.get('message', 'Issue'))[:80]}",
                            severity="medium" if severity == "low" else severity,
                            file_path=rel,
                            line_start=int(message.get("line") or 1),
                            line_end=int(message.get("line") or 1),
                            description=str(message.get("message") or "PHPStan reported an issue."),
                            tool_name=self.tool_name,
                            tool_version=self.tool_version,
                            rule_id=str(message.get("identifier") or "phpstan"),
                            confidence=0.85,
                            remediation="Address the PHPStan finding or document an intentional ignore.",
                            raw_output=message if isinstance(message, dict) else {},
                        )
                    )
        except FileNotFoundError:
            logger.info("PHPStan binary missing; skipping.")
        except Exception as exc:
            logger.warning(f"PHPStan scan failed gracefully: {exc}")

        return findings

    def _resolve_binary(self, workspace_dir: str) -> List[str] | None:
        local = os.path.join(workspace_dir, "vendor", "bin", "phpstan")
        if os.path.isfile(local) and os.access(local, os.X_OK):
            return [local]
        try:
            subprocess.run(["phpstan", "--version"], capture_output=True, text=True, timeout=5)
            return ["phpstan"]
        except Exception:
            return None
