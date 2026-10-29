import json
import logging
import os
import subprocess
import tempfile
from typing import List, Optional

from analyzers.path_exclusions import scanner_exclude_args
from analyzers.scan_path_scope import existing_workspace_paths
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper
from scanners.base_scanner import BaseScanner

logger = logging.getLogger("BanditRunner")


class BanditRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "bandit"

    def scan(self, workspace_dir: str, paths: Optional[List[str]] = None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []
        if not os.path.isdir(workspace_dir):
            return findings

        targets: List[str]
        if paths is not None:
            targets = existing_workspace_paths(workspace_dir, paths)
            if not targets:
                logger.info("Bandit skipped: no changed Python files in scope.")
                return findings
            # Bandit -r on files still works; prefer explicit file list without recursive root.
            scan_roots = [os.path.join(workspace_dir, rel) for rel in targets]
            recursive = False
        else:
            scan_roots = [workspace_dir]
            recursive = True

        report_file = os.path.join(tempfile.gettempdir(), f"bandit_{os.path.basename(workspace_dir)}.json")
        cmd = ["bandit"]
        if recursive:
            cmd.append("-r")
        cmd.extend(scan_roots)
        cmd.extend(["-f", "json", "-o", report_file, *scanner_exclude_args("bandit"), "-q"])
        try:
            subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            if not os.path.isfile(report_file):
                return findings
            with open(report_file, "r", encoding="utf-8") as handle:
                data = json.load(handle)
            for item in data.get("results", []):
                filename = sanitize_relative_path(item.get("filename") or "Unknown", workspace_dir)
                severity = SeverityMapper.normalize(str(item.get("issue_severity") or "MEDIUM"), default="medium")
                findings.append(
                    NormalizedFinding(
                        title=f"Bandit: {item.get('test_id', 'security')}",
                        severity=severity,
                        file_path=filename,
                        line_start=int(item.get("line_number") or 1),
                        line_end=int(item.get("line_number") or 1),
                        description=str(item.get("issue_text") or "Bandit security finding"),
                        tool_name=self.tool_name,
                        tool_version=self.tool_version,
                        rule_id=str(item.get("test_id") or "bandit"),
                        confidence=0.85,
                        remediation="Review and remediate the flagged insecure pattern.",
                        raw_output={
                            "test_id": item.get("test_id"),
                            "issue_text": item.get("issue_text"),
                            "line_number": item.get("line_number"),
                        },
                    )
                )
        except FileNotFoundError:
            logger.info("Bandit not installed; skipping Python security adapter.")
        except Exception as exc:
            logger.warning(f"Bandit scan failed gracefully: {exc}")
        finally:
            if os.path.isfile(report_file):
                try:
                    os.remove(report_file)
                except OSError:
                    pass

        return findings
