import json
import logging
import os
import subprocess
from typing import List

from fingerprint import sanitize_relative_path
from models.finding import NormalizedFinding
from scanners.base_scanner import BaseScanner

logger = logging.getLogger("PlaywrightRunner")


class PlaywrightRunner(BaseScanner):
    """
    Executes Playwright only when the trust gate authorizes execution.
    Never runs npm install; relies on an already-present project toolchain.
    """

    @property
    def tool_name(self) -> str:
        return "playwright"

    def scan(self, workspace_dir: str, paths=None) -> List[NormalizedFinding]:
        # Compatibility with BaseScanner; prefer run_authorized().
        return self.run_authorized(workspace_dir, authorized=False)

    def run_authorized(self, workspace_dir: str, authorized: bool) -> List[NormalizedFinding]:
        if not authorized:
            return []
        if not os.path.isdir(workspace_dir):
            return []

        cmd = ["npx", "--yes", "playwright", "test", "--reporter=json"]
        try:
            res = subprocess.run(
                cmd,
                cwd=workspace_dir,
                capture_output=True,
                text=True,
                timeout=600,
                env=self._safe_env(),
            )
            payload = res.stdout.strip()
            if not payload:
                if res.returncode != 0:
                    return [
                        NormalizedFinding(
                            title="Playwright suite failed",
                            severity="high",
                            file_path="e2e",
                            description=(res.stderr or "Playwright exited non-zero without JSON output.")[:500],
                            tool_name=self.tool_name,
                            tool_version=self.tool_version,
                            rule_id="playwright-failed",
                            confidence=0.9,
                            is_blocking_override=True,
                            remediation="Fix failing Playwright E2E tests.",
                        )
                    ]
                return []

            data = json.loads(payload)
            findings: List[NormalizedFinding] = []
            suites = data.get("suites") or []
            self._collect_failures(suites, findings, workspace_dir)
            if res.returncode != 0 and not findings:
                findings.append(
                    NormalizedFinding(
                        title="Playwright suite failed",
                        severity="high",
                        file_path="e2e",
                        description="One or more Playwright tests failed.",
                        tool_name=self.tool_name,
                        tool_version=self.tool_version,
                        rule_id="playwright-failed",
                        confidence=0.9,
                        is_blocking_override=True,
                        remediation="Fix failing Playwright E2E tests.",
                    )
                )
            return findings
        except FileNotFoundError:
            logger.info("Playwright/npx unavailable; skipping.")
            return []
        except Exception as exc:
            logger.warning(f"Playwright run failed gracefully: {exc}")
            return []

    def _collect_failures(
        self,
        suites: list,
        findings: List[NormalizedFinding],
        workspace_dir: str,
    ) -> None:
        for suite in suites:
            for spec in suite.get("specs", []):
                title = spec.get("title") or "Playwright test"
                file_path = sanitize_relative_path(suite.get("file") or "e2e", workspace_dir)
                for test in spec.get("tests", []):
                    for result in test.get("results", []):
                        if result.get("status") in {"failed", "timedOut"}:
                            findings.append(
                                NormalizedFinding(
                                    title=f"Playwright failed: {title}",
                                    severity="high",
                                    file_path=file_path,
                                    description=str(result.get("error", {}).get("message") or result.get("status")),
                                    tool_name=self.tool_name,
                                    tool_version=self.tool_version,
                                    rule_id="playwright-test-failed",
                                    confidence=0.9,
                                    is_blocking_override=True,
                                    remediation="Investigate the failing browser E2E scenario.",
                                )
                            )
            self._collect_failures(suite.get("suites") or [], findings, workspace_dir)

    def _safe_env(self) -> dict:
        env = {
            "PATH": os.environ.get("PATH", ""),
            "HOME": os.environ.get("HOME", "/tmp"),
            "CI": "1",
            "npm_config_ignore_scripts": "true",
        }
        for key, value in os.environ.items():
            upper = key.upper()
            if any(token in upper for token in ("TOKEN", "SECRET", "PASSWORD", "PRIVATE_KEY", "API_KEY")):
                continue
            if upper.startswith(("NODE", "NPM", "PLAYWRIGHT", "LC_", "LANG")):
                env[key] = value
        return env
