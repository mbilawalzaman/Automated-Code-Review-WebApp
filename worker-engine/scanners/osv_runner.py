import os
import json
import subprocess
import logging
from typing import List
from scanners.base_scanner import BaseScanner
from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper

logger = logging.getLogger("OsvRunner")

class OsvRunner(BaseScanner):
    @property
    def tool_name(self) -> str:
        return "osv-scanner"

    def scan(self, workspace_dir: str, paths=None) -> List[NormalizedFinding]:
        findings: List[NormalizedFinding] = []

        if not os.path.exists(workspace_dir):
            return findings

        try:
            cmd = ["osv-scanner", "--json", workspace_dir]
            res = subprocess.run(cmd, capture_output=True, text=True, timeout=60)

            if res.stdout.strip():
                data = json.loads(res.stdout)
                results = data.get("results", [])

                for res_item in results:
                    source_path = sanitize_relative_path(
                        res_item.get("source", {}).get("path", "manifest"),
                        workspace_dir,
                    )

                    packages = res_item.get("packages", [])
                    for pkg_item in packages:
                        pkg_info = pkg_item.get("package", {})
                        pkg_name = pkg_info.get("name", "dependency")
                        pkg_ver = pkg_info.get("version", "unknown")

                        vulnerabilities = pkg_item.get("vulnerabilities", [])
                        for vuln in vulnerabilities:
                            cve_id = vuln.get("id", "CVE-UNKNOWN")
                            summary = vuln.get("summary") or vuln.get("details") or f"Vulnerable dependency {pkg_name}"

                            # Determine severity from CVSS if available
                            cvss_score = 7.5
                            database_specific = vuln.get("database_specific", {})
                            if "severity" in database_specific:
                                raw_sev = str(database_specific["severity"])
                                norm_sev = SeverityMapper.normalize(raw_sev, default="high")
                            else:
                                norm_sev = "high"

                            finding = NormalizedFinding(
                                title=f"Vulnerable Dependency: {pkg_name} ({cve_id})",
                                severity=norm_sev,
                                file_path=source_path,
                                line_start=1,
                                line_end=1,
                                description=f"Dependency {pkg_name} v{pkg_ver} has a known vulnerability: {summary[:200]}",
                                tool_name=self.tool_name,
                                tool_version=self.tool_version,
                                rule_id=cve_id,
                                confidence=0.95,
                                raw_output=vuln
                            )
                            findings.append(finding)
        except FileNotFoundError:
            from scanners.mandatory_scanner_error import MandatoryScannerMissingError
            raise MandatoryScannerMissingError(self.tool_name)
        except Exception as e:
            logger.warning(f"OSV scanner failed gracefully: {e}")

        return findings
