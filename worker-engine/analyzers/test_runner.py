"""Sandboxed repository test execution and changed-line coverage (TST-001..005)."""

from __future__ import annotations

import logging
import os
import re
import subprocess
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Set, Tuple

from analyzers.language_detector import StackDetection
from models.finding import NormalizedFinding

logger = logging.getLogger("TestRunner")

SENSITIVE_ENV_TOKENS = ("TOKEN", "SECRET", "PASSWORD", "PRIVATE_KEY", "API_KEY", "CREDENTIAL")


@dataclass
class TestRunResult:
    executed: bool = False
    skipped_reason: Optional[str] = None
    framework: Optional[str] = None
    passed: Optional[bool] = None
    tests: int = 0
    failures: int = 0
    errors: int = 0
    overall_coverage: Optional[float] = None
    changed_code_coverage: Optional[float] = None
    findings: List[NormalizedFinding] = field(default_factory=list)

    def to_dict(self) -> Dict[str, object]:
        return {
            "executed": self.executed,
            "skipped_reason": self.skipped_reason,
            "framework": self.framework,
            "passed": self.passed,
            "tests": self.tests,
            "failures": self.failures,
            "errors": self.errors,
            "overall_coverage": self.overall_coverage,
            "changed_code_coverage": self.changed_code_coverage,
        }


class TestRunner:
    """
    Execute native test suites without installing dependencies or leaking worker
    credentials into the child process. Full OS sandboxing should be layered by
    the host (container/restricted user); this enforces process-level controls.
    """

    def __init__(self, timeout_seconds: int = 300, coverage_threshold: float = 70.0):
        self.timeout_seconds = timeout_seconds
        self.coverage_threshold = coverage_threshold

    def run(
        self,
        workspace_dir: str,
        stack: StackDetection,
        diff_text: str,
        authorized: bool,
    ) -> TestRunResult:
        result = TestRunResult()
        if not authorized:
            result.skipped_reason = "static_first_untrusted"
            return result

        command, framework = self._select_command(workspace_dir, stack)
        if not command:
            result.skipped_reason = "no_supported_test_runner"
            return result

        result.framework = framework
        result.executed = True

        try:
            proc = subprocess.run(
                command,
                cwd=workspace_dir,
                capture_output=True,
                text=True,
                timeout=self.timeout_seconds,
                env=self._scrubbed_env(),
            )
            result.passed = proc.returncode == 0
            if proc.returncode != 0:
                result.findings.append(
                    NormalizedFinding(
                        title=f"Test suite failed ({framework})",
                        severity="high",
                        file_path="tests",
                        line_start=1,
                        description=(
                            f"{framework} exited with code {proc.returncode}. "
                            f"{(proc.stderr or proc.stdout or '')[:500]}"
                        ),
                        tool_name="test-runner",
                        tool_version="1.0.0",
                        rule_id="TST-001",
                        confidence=0.95,
                        is_blocking_override=True,
                        remediation="Fix failing repository tests before merging.",
                    )
                )
        except subprocess.TimeoutExpired:
            result.passed = False
            result.findings.append(
                NormalizedFinding(
                    title=f"Test suite timed out ({framework})",
                    severity="high",
                    file_path="tests",
                    description=f"{framework} exceeded {self.timeout_seconds}s timeout.",
                    tool_name="test-runner",
                    rule_id="TST-001-timeout",
                    confidence=1.0,
                    is_blocking_override=True,
                    remediation="Reduce test runtime or split slow suites.",
                )
            )
        except FileNotFoundError:
            result.executed = False
            result.skipped_reason = f"{framework}_not_installed"
            return result
        except Exception as exc:
            logger.warning(f"Test execution failed gracefully: {exc}")
            result.executed = False
            result.skipped_reason = "execution_error"
            return result

        coverage_file = self._find_coverage_report(workspace_dir)
        if coverage_file:
            result.overall_coverage = self._parse_overall_coverage(coverage_file)
            changed_lines = self._changed_lines_from_diff(diff_text)
            result.changed_code_coverage = self._changed_line_coverage(coverage_file, changed_lines)

            if (
                result.changed_code_coverage is not None
                and result.changed_code_coverage < self.coverage_threshold
                and changed_lines
            ):
                result.findings.append(
                    NormalizedFinding(
                        title="Changed-code coverage below threshold",
                        severity="high",
                        file_path="coverage",
                        description=(
                            f"Changed-line coverage is {result.changed_code_coverage:.1f}% "
                            f"(threshold {self.coverage_threshold:.0f}%)."
                        ),
                        tool_name="coverage",
                        rule_id="TST-004",
                        confidence=0.9,
                        is_blocking_override=True,
                        remediation="Add tests covering the changed lines before merging.",
                    )
                )
            elif not changed_lines and result.overall_coverage is not None:
                # Still surface overall coverage for visibility (TST-003).
                pass
        else:
            result.findings.append(
                NormalizedFinding(
                    title="Coverage report missing",
                    severity="medium",
                    file_path="coverage",
                    description="Tests ran but no clover.xml/coverage.xml report was found.",
                    tool_name="coverage",
                    rule_id="TST-003",
                    confidence=0.7,
                    is_blocking_override=False,
                    remediation="Enable coverage output for the repository test suite.",
                )
            )

        return result

    def _select_command(self, workspace_dir: str, stack: StackDetection) -> Tuple[Optional[List[str]], Optional[str]]:
        phpunit = os.path.join(workspace_dir, "vendor", "bin", "phpunit")
        if "php" in stack.stacks and os.path.isfile(phpunit):
            return (
                [phpunit, "--coverage-clover", "coverage/clover.xml"],
                "phpunit",
            )

        if "python" in stack.stacks:
            if os.path.isfile(os.path.join(workspace_dir, "pytest.ini")) or os.path.isdir(
                os.path.join(workspace_dir, "tests")
            ):
                return (
                    ["pytest", "--maxfail=5", "--disable-warnings", "--cov=.", "--cov-report=xml:coverage.xml"],
                    "pytest",
                )

        package_json = os.path.join(workspace_dir, "package.json")
        if "javascript" in stack.stacks and os.path.isfile(package_json):
            # Use existing scripts only; never npm install in this phase.
            return (["npm", "test", "--", "--coverage", "--watchAll=false"], "npm-test")

        return None, None

    def _scrubbed_env(self) -> Dict[str, str]:
        env = {
            "PATH": os.environ.get("PATH", ""),
            "HOME": os.environ.get("HOME", "/tmp"),
            "LANG": os.environ.get("LANG", "en_US.UTF-8"),
            "TERM": "dumb",
            "CI": "1",
            "npm_config_ignore_scripts": "true",
            "COMPOSER_DISABLE_XDEBUG_WARN": "1",
        }
        # Preserve non-secret locale/runtime helpers only.
        for key, value in os.environ.items():
            upper = key.upper()
            if any(token in upper for token in SENSITIVE_ENV_TOKENS):
                continue
            if upper.startswith(("PYTHON", "NODE", "NPM", "PHP", "LC_")):
                env[key] = value
        return env

    def _find_coverage_report(self, workspace_dir: str) -> Optional[str]:
        candidates = [
            os.path.join(workspace_dir, "coverage", "clover.xml"),
            os.path.join(workspace_dir, "clover.xml"),
            os.path.join(workspace_dir, "coverage.xml"),
            os.path.join(workspace_dir, "coverage", "coverage.xml"),
        ]
        for path in candidates:
            if os.path.isfile(path):
                return path
        return None

    def _parse_overall_coverage(self, coverage_file: str) -> Optional[float]:
        try:
            root = ET.parse(coverage_file).getroot()
            # clover
            metrics = root.find(".//metrics")
            if metrics is not None:
                statements = float(metrics.attrib.get("statements") or 0)
                covered = float(metrics.attrib.get("coveredstatements") or 0)
                if statements > 0:
                    return round((covered / statements) * 100.0, 2)
            # cobertura
            line_rate = root.attrib.get("line-rate")
            if line_rate is not None:
                return round(float(line_rate) * 100.0, 2)
        except Exception as exc:
            logger.warning(f"Failed to parse coverage report: {exc}")
        return None

    def _changed_lines_from_diff(self, diff_text: str) -> Dict[str, Set[int]]:
        changed: Dict[str, Set[int]] = {}
        current_file = None
        new_line = 0
        for line in (diff_text or "").splitlines():
            if line.startswith("+++ b/"):
                current_file = line[6:].strip()
                changed.setdefault(current_file, set())
                continue
            if line.startswith("@@"):
                match = re.search(r"\+(\d+)", line)
                new_line = int(match.group(1)) if match else 0
                continue
            if current_file is None:
                continue
            if line.startswith("+") and not line.startswith("+++"):
                changed[current_file].add(new_line)
                new_line += 1
            elif line.startswith("-") and not line.startswith("---"):
                continue
            else:
                new_line += 1
        return {path: lines for path, lines in changed.items() if lines}

    def _changed_line_coverage(self, coverage_file: str, changed: Dict[str, Set[int]]) -> Optional[float]:
        if not changed:
            return None
        try:
            root = ET.parse(coverage_file).getroot()
            covered = 0
            total = 0

            # clover files
            for file_node in root.findall(".//file"):
                name = file_node.attrib.get("name") or file_node.attrib.get("path") or ""
                rel = self._match_changed_file(name, changed)
                if not rel:
                    continue
                lines = changed[rel]
                for line_node in file_node.findall(".//line"):
                    num = int(line_node.attrib.get("num") or line_node.attrib.get("number") or 0)
                    if num not in lines:
                        continue
                    total += 1
                    count = int(line_node.attrib.get("count") or 0)
                    if count > 0:
                        covered += 1

            # cobertura fallback
            if total == 0:
                for class_node in root.findall(".//class"):
                    filename = class_node.attrib.get("filename") or ""
                    rel = self._match_changed_file(filename, changed)
                    if not rel:
                        continue
                    lines = changed[rel]
                    for line_node in class_node.findall("./lines/line"):
                        num = int(line_node.attrib.get("number") or 0)
                        if num not in lines:
                            continue
                        total += 1
                        if int(line_node.attrib.get("hits") or 0) > 0:
                            covered += 1

            if total == 0:
                return 0.0
            return round((covered / total) * 100.0, 2)
        except Exception as exc:
            logger.warning(f"Changed-line coverage calculation failed: {exc}")
            return None

    def _match_changed_file(self, coverage_path: str, changed: Dict[str, Set[int]]) -> Optional[str]:
        normalized = coverage_path.replace("\\", "/")
        for path in changed:
            if normalized.endswith(path) or path.endswith(normalized) or normalized.endswith("/" + path):
                return path
        return None
