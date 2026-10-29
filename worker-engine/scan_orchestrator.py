import logging
import concurrent.futures
import os
import time
from typing import List, Dict, Any
from git_service import GitService, CloneFailureError
from worker_info import WorkerInfo
from scanners.gitleaks_runner import GitleaksRunner
from scanners.semgrep_runner import SemgrepRunner
from scanners.osv_runner import OsvRunner
from scanners.phpstan_runner import PHPStanRunner
from scanners.ruff_runner import RuffRunner
from scanners.bandit_runner import BanditRunner
from scanners.eslint_runner import ESLintRunner
from scanners.playwright_runner import PlaywrightRunner
from scanners.mandatory_scanner_error import MandatoryScannerMissingError
from models.finding import NormalizedFinding
from deduplicator import FindingDeduplicator
from ollama_client import OllamaClient
from analyzers.objective_compliance import ObjectiveComplianceAnalyzer
from analyzers.language_detector import LanguageDetector
from analyzers.path_exclusions import filter_findings
from analyzers.finding_prioritizer import prioritize_findings
from analyzers.scan_path_scope import LANGUAGE_SCOPED_TOOLS, paths_for_tool
from analyzers.trust_gate import execution_authorized, authorization_reason
from analyzers.test_runner import TestRunner
from analyzers.migration_analyzer import analyze_migrations
from analyzers.api_contract_analyzer import analyze_api_contract
from analyzers.impact_analyzer import build_impact_analysis, analyze_sensitive_changes
from analyzers.commit_verification_analyzer import analyze_commit_signature
from payload_builder import PayloadBuilder

logger = logging.getLogger("ScanOrchestrator")


class ScanOrchestrator:
    def __init__(self, worker_info: WorkerInfo):
        self.worker_info = worker_info
        self.git_service = GitService()
        self.ollama = OllamaClient()
        self.objective_analyzer = ObjectiveComplianceAnalyzer(self.ollama)
        self.language_detector = LanguageDetector()
        self.test_runner = TestRunner(
            timeout_seconds=int(os.getenv("TEST_RUN_TIMEOUT_SECONDS", "300")),
            coverage_threshold=float(os.getenv("CHANGED_COVERAGE_MIN_PERCENT", "70")),
        )
        self.playwright = PlaywrightRunner(tool_version=worker_info.get_version("playwright"))

        # Baseline mandatory scanners always run.
        self.baseline_scanners = [
            GitleaksRunner(tool_version=worker_info.get_version("gitleaks")),
            SemgrepRunner(tool_version=worker_info.get_version("semgrep")),
            OsvRunner(tool_version=worker_info.get_version("osv-scanner")),
        ]

    def execute_scan_job(self, job: Dict[str, Any]) -> Dict[str, Any]:
        job_id = job.get("id")
        repo_data = job.get("repository", {})
        repo_name = repo_data.get("full_name", "unknown/repository")
        clone_url = repo_data.get("clone_url") or repo_data.get("url")
        commit_sha = job.get("commit_sha", "HEAD")
        pull_request_number = job.get("pull_request_number")
        payload = job.get("payload") if isinstance(job.get("payload"), dict) else {}
        branch_name = payload.get("branch_name") or job.get("branch_name")
        base_branch = repo_data.get("default_branch") or "main"

        logger.info(f"Orchestrating scan job #{job_id} for {repo_name} (Commit: {str(commit_sha)[:7]})")

        workspace_dir = None
        raw_findings: List[NormalizedFinding] = []
        tool_executions: List[Dict[str, Any]] = []
        diff_text = ""
        pr_title = payload.get("title", f"Commit Review {str(commit_sha)[:7]}")
        author = payload.get("author", "Developer")
        objectives = job.get("objectives") or []
        authorized = execution_authorized(job)
        auth_reason = authorization_reason(job)

        try:
            workspace_dir = self.git_service.prepare_workspace(
                clone_url=clone_url,
                commit_sha=commit_sha,
                pull_request_number=pull_request_number,
                branch_name=branch_name,
                base_branch=base_branch,
            )
            diff_text = self.git_service.get_commit_diff(
                workspace_dir,
                base_branch=base_branch,
            )
            # None = git could not resolve scope → full-repo for language tools.
            # [] = resolved but no files in scope → skip language tools (do NOT full-repo).
            scoped_changed_files = self.git_service.list_changed_files(
                workspace_dir,
                base_branch=base_branch,
                for_pull_request=bool(pull_request_number),
            )
            if scoped_changed_files is None:
                logger.info("Changed-file scope unavailable; language scanners will run full-repo.")
            else:
                logger.info(
                    f"Changed-file scope: {len(scoped_changed_files)} path(s) for language scanners "
                    f"(pr={bool(pull_request_number)})."
                )

            stack = self.language_detector.detect(workspace_dir)
            logger.info(f"Detected stacks={sorted(stack.stacks)} tools={sorted(stack.tools)}")

            scanners = list(self.baseline_scanners)
            if "php" in stack.stacks:
                scanners.append(PHPStanRunner(tool_version=self.worker_info.get_version("phpstan")))
            if "python" in stack.stacks:
                scanners.append(RuffRunner(tool_version=self.worker_info.get_version("ruff")))
                scanners.append(BanditRunner(tool_version=self.worker_info.get_version("bandit")))
            if "javascript" in stack.stacks:
                scanners.append(ESLintRunner(tool_version=self.worker_info.get_version("eslint")))

            logger.info(f"Executing {len(scanners)} scanners in parallel (static-first)...")
            with concurrent.futures.ThreadPoolExecutor(max_workers=min(6, len(scanners))) as executor:
                future_to_scanner = {
                    executor.submit(
                        self._run_scanner_timed,
                        scanner,
                        workspace_dir,
                        paths_for_tool(scanner.tool_name, scoped_changed_files)
                        if scanner.tool_name in LANGUAGE_SCOPED_TOOLS
                        else None,
                    ): scanner
                    for scanner in scanners
                }

                for future in concurrent.futures.as_completed(future_to_scanner):
                    scanner = future_to_scanner[future]
                    try:
                        results, execution = future.result()
                        tool_executions.append(execution)
                        logger.info(f"Scanner '{scanner.tool_name}' completed with {len(results)} findings.")
                        raw_findings.extend(results)
                    except MandatoryScannerMissingError:
                        for pending in future_to_scanner:
                            pending.cancel()
                        raise
                    except Exception as exc:
                        logger.error(f"Scanner '{scanner.tool_name}' generated an exception: {exc}")
                        tool_executions.append({
                            "tool": getattr(scanner, "tool_name", "unknown"),
                            "version": getattr(scanner, "tool_version", None),
                            "duration_ms": None,
                            "exit_code": 1,
                            "error": str(exc),
                        })

            # REV-002: drop vendor/generated style findings from language adapters / semgrep noise.
            raw_findings = filter_findings(raw_findings)

            # Phase 23 policy analyzers (diff-based).
            impact = build_impact_analysis(diff_text)
            policy_findings: List[NormalizedFinding] = []
            policy_findings.extend(analyze_migrations(diff_text, workspace_dir))
            policy_findings.extend(analyze_api_contract(diff_text))
            policy_findings.extend(analyze_sensitive_changes(diff_text, impact))
            policy_findings.extend(analyze_commit_signature(workspace_dir, str(commit_sha)))
            raw_findings.extend(policy_findings)

            # Merge policy-derived change classes into impact for VPS persistence.
            merged_classes = set(impact.get("change_classes") or [])
            for finding in policy_findings:
                raw = finding.raw_output or {}
                if isinstance(raw, dict) and raw.get("change_class"):
                    merged_classes.add(str(raw["change_class"]))
            impact["change_classes"] = sorted(merged_classes)

            tool_executions.append({
                "tool": "policy-analyzers",
                "version": "1.0.0",
                "ruleset": "CHG-001,CHG-003,CHG-004,SEC-010,REV-001,REV-004",
                "duration_ms": None,
                "exit_code": 0,
                "findings": len(policy_findings),
            })

            deduped_findings = FindingDeduplicator.deduplicate(raw_findings)

            logger.info("Enriching SAST findings with Ollama AI model...")
            t0 = time.monotonic()
            enrichment_result = self.ollama.enrich_findings(deduped_findings, diff_text, repo_name)
            tool_executions.append({
                "tool": f"ollama/{self.ollama.model}",
                "version": self.worker_info.get_version("ollama"),
                "duration_ms": int((time.monotonic() - t0) * 1000),
                "exit_code": 0,
            })
            final_summary = enrichment_result.get("summary", f"Review completed for {repo_name}.")
            final_findings = enrichment_result.get("enriched_findings", deduped_findings)

            # Bounded objective pass: never block SAST result submission on Ollama.
            objective_findings: List[NormalizedFinding] = []
            if objectives:
                t1 = time.monotonic()
                try:
                    objective_findings = self.objective_analyzer.analyze(objectives, diff_text, repo_name)
                except Exception as exc:
                    logger.warning(f"Objective compliance skipped after unexpected error ({exc}).")
                    objective_findings = []
                tool_executions.append({
                    "tool": "objective-compliance",
                    "version": "1.0.0",
                    "duration_ms": int((time.monotonic() - t1) * 1000),
                    "exit_code": 0,
                    "objectives_supplied": len(objectives),
                    "objective_findings": len(objective_findings),
                })
            final_findings = list(final_findings) + objective_findings

            test_result = self.test_runner.run(
                workspace_dir=workspace_dir,
                stack=stack,
                diff_text=diff_text,
                authorized=authorized,
            )
            final_findings = list(final_findings) + list(test_result.findings)
            if test_result.executed:
                tool_executions.append({
                    "tool": test_result.framework or "tests",
                    "version": None,
                    "duration_ms": None,
                    "exit_code": 0 if test_result.passed else 1,
                    "ruleset": "TST-001/TST-004",
                })

            if "playwright" in stack.stacks and authorized:
                playwright_findings = self.playwright.run_authorized(workspace_dir, authorized=True)
                final_findings = list(final_findings) + playwright_findings
                tool_executions.append({
                    "tool": "playwright",
                    "version": self.worker_info.get_version("playwright"),
                    "duration_ms": None,
                    "exit_code": 0,
                })
            elif "playwright" in stack.stacks and not authorized:
                logger.info("Playwright detected but skipped by static-first trust gate.")

            tools_used = [s.tool_name for s in scanners] + [f"ollama/{self.ollama.model}"]
            if objectives:
                tools_used.append("objective-compliance")
            if test_result.executed and test_result.framework:
                tools_used.append(test_result.framework)
            if "playwright" in stack.stacks and authorized:
                tools_used.append("playwright")
            tools_used.extend(["migration-analyzer", "api-contract-analyzer", "impact-analyzer", "commit-verification"])

            if objective_findings:
                mandatory_breaches = sum(1 for f in objective_findings if f.is_blocking())
                final_summary = (
                    f"{final_summary} Objective review flagged {len(objective_findings)} requirement "
                    f"issue(s), {mandatory_breaches} of them merge-blocking."
                )
            elif objectives:
                final_summary = f"{final_summary} All {len(objectives)} approved requirements appear satisfied."

            if not authorized:
                final_summary = (
                    f"{final_summary} Test execution skipped ({auth_reason}); static analysis only (WF-007)."
                )
            elif test_result.skipped_reason:
                final_summary = f"{final_summary} Tests not run: {test_result.skipped_reason}."
            elif test_result.executed:
                coverage_note = ""
                if test_result.changed_code_coverage is not None:
                    coverage_note = f" Changed-line coverage: {test_result.changed_code_coverage:.1f}%."
                final_summary = (
                    f"{final_summary} Repository tests via {test_result.framework} "
                    f"{'passed' if test_result.passed else 'failed'}.{coverage_note}"
                )

            if policy_findings:
                blocking_policy = sum(1 for f in policy_findings if f.is_blocking())
                final_summary = (
                    f"{final_summary} Policy analyzers raised {len(policy_findings)} change-class "
                    f"finding(s) ({blocking_policy} blocking)."
                )

            prioritized, findings_metrics = prioritize_findings(list(final_findings))
            if findings_metrics.get("truncated"):
                final_summary = (
                    f"{final_summary} Prioritized submission: "
                    f"{findings_metrics['findings_submitted']} of "
                    f"{findings_metrics['findings_total']} findings "
                    f"({findings_metrics['findings_truncated']} truncated by soft cap)."
                )

            return PayloadBuilder.build(
                findings=prioritized,
                summary=final_summary,
                pull_request_title=pr_title,
                author=author,
                tools_used=tools_used,
                objectives_evaluated=len(objectives),
                stacks=sorted(stack.stacks),
                test_summary=test_result.to_dict(),
                coverage_percentage=test_result.overall_coverage,
                changed_code_coverage=test_result.changed_code_coverage,
                execution_authorized=authorized,
                change_classes=impact.get("change_classes") or [],
                impact_analysis=impact,
                tool_executions=tool_executions,
                findings_metrics=findings_metrics,
                changed_files_scoped=len(scoped_changed_files) if scoped_changed_files is not None else None,
            )

        except (CloneFailureError, MandatoryScannerMissingError) as e:
            logger.error(f"Review error for scan job #{job_id}: {e}")
            return PayloadBuilder.build(
                findings=[],
                summary=f"Automated review could not complete: {e}",
                pull_request_title=pr_title,
                author=author,
                tools_used=["scan_orchestrator"],
                status="review_error",
                error_message=str(e),
                tool_executions=tool_executions,
            )
        except Exception as e:
            logger.error(f"Fatal error during scan job orchestration #{job_id}: {e}")
            return PayloadBuilder.build(
                findings=[],
                summary=f"Automated review job failed due to execution error: {e}",
                pull_request_title=pr_title,
                author=author,
                tools_used=["scan_orchestrator"],
                status="review_error",
                error_message=str(e),
                tool_executions=tool_executions,
            )
        finally:
            if workspace_dir:
                self.git_service.cleanup(workspace_dir)

    def _run_scanner_timed(self, scanner, workspace_dir: str, paths=None):
        started = time.monotonic()
        results = scanner.scan(workspace_dir, paths=paths)
        duration_ms = int((time.monotonic() - started) * 1000)
        execution = {
            "tool": getattr(scanner, "tool_name", "unknown"),
            "binary": getattr(scanner, "binary_path", None) or getattr(scanner, "tool_name", None),
            "version": getattr(scanner, "tool_version", None),
            "ruleset": getattr(scanner, "ruleset", None),
            "duration_ms": duration_ms,
            "exit_code": 0,
            "findings": len(results),
            "scoped_paths": len(paths) if paths is not None else None,
        }
        return results, execution
