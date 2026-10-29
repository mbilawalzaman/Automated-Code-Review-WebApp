import os
import json
import subprocess
import logging
from typing import List
from scanners.base_scanner import BaseScanner
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper

logger = logging.getLogger("SemgrepRunner")

class SemgrepRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "semgrep"

    def scan(self, workspace_dir: str, paths=None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []

        if not os.path.exists(workspace_dir):
            return findings

        try:
            cmd = ["semgrep", "scan", "--config=auto", "--json", "--quiet", workspace_dir]
            res = subprocess.run(cmd, capture_output=True, text=True, timeout=120)

            if res.stdout.strip():
                data = json.loads(res.stdout)
                results = data.get("results", [])

                for item in results:
                    extra = item.get("extra", {})
                    rel_path = sanitize_relative_path(item.get("path", "Unknown"), workspace_dir)

                    raw_sev = extra.get("severity", "WARNING")
                    norm_sev = SeverityMapper.normalize(raw_sev, default="medium")

                    rule_id = item.get("check_id", "semgrep-finding")
                    short_title = rule_id.split(".")[-1].replace("-", " ").title()

                    finding = NormalizedFinding(
                        title=f"SAST Finding: {short_title}",
                        severity=norm_sev,
                        file_path=rel_path,
                        line_start=item.get("start", {}).get("line", 1),
                        line_end=item.get("end", {}).get("line", 1),
                        description=extra.get("message", "Semgrep security or quality rule triggered."),
                        tool_name=self.tool_name,
                        tool_version=self.tool_version,
                        rule_id=rule_id,
                        confidence=0.90,
                        raw_output=item
                    )
                    findings.append(finding)
        except FileNotFoundError:
            from scanners.mandatory_scanner_error import MandatoryScannerMissingError
            raise MandatoryScannerMissingError(self.tool_name)
        except Exception as e:
            logger.warning(f"Semgrep scan failed gracefully: {e}")

        return findings
