import os
import json
import subprocess
import logging
import tempfile
from typing import List
from scanners.base_scanner import BaseScanner
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper

logger = logging.getLogger("GitleaksRunner")

class GitleaksRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "gitleaks"

    def scan(self, workspace_dir: str, paths=None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []

        if not os.path.exists(workspace_dir):
            return findings

        report_file = os.path.join(tempfile.gettempdir(), f"gitleaks_report_{os.path.basename(workspace_dir)}.json")

        try:
            cmd = [
                "gitleaks", "detect",
                "--source", workspace_dir,
                "--no-git",
                "--report-format", "json",
                "--report-path", report_file
            ]

            res = subprocess.run(cmd, capture_output=True, text=True, timeout=60)

            if os.path.exists(report_file):
                with open(report_file, "r") as f:
                    data = json.load(f)
                    if isinstance(data, list):
                        for leak in data:
                            rel_path = sanitize_relative_path(leak.get("File", "Unknown"), workspace_dir)

                            # Never include Match / secret material in description or raw_output (SEC-003).
                            safe_raw = {
                                "RuleID": leak.get("RuleID"),
                                "Description": leak.get("Description"),
                                "File": rel_path,
                                "StartLine": leak.get("StartLine"),
                                "EndLine": leak.get("EndLine"),
                                "Match": "[REDACTED]",
                            }
                            finding = NormalizedFinding(
                                title=f"Hardcoded Secret Leaked: {leak.get('Description', leak.get('RuleID', 'Secret Leaked'))}",
                                severity="high",
                                file_path=rel_path,
                                line_start=leak.get("StartLine", 1),
                                line_end=leak.get("EndLine", 1),
                                description=(
                                    f"Potential hardcoded secret or API credential detected "
                                    f"({leak.get('RuleID', 'generic-secret')}). Match: [REDACTED]"
                                ),
                                tool_name=self.tool_name,
                                tool_version=self.tool_version,
                                rule_id=leak.get("RuleID", "gitleaks-secret"),
                                confidence=0.95,
                                raw_output=safe_raw,
                            )
                            findings.append(finding)
        except FileNotFoundError:
            from scanners.mandatory_scanner_error import MandatoryScannerMissingError
            raise MandatoryScannerMissingError(self.tool_name)
        except Exception as e:
            logger.warning(f"Gitleaks scan failed gracefully: {e}")
        finally:
            if os.path.exists(report_file):
                try:
                    os.remove(report_file)
                except OSError:
                    pass

        return findings
