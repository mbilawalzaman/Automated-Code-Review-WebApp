import unittest
import os
import shutil
import subprocess
import tempfile
from models.finding import NormalizedFinding
from mappers.severity_mapper import SeverityMapper
from deduplicator import FindingDeduplicator
from payload_builder import PayloadBuilder
from git_service import GitService, CloneFailureError
from scanners.mandatory_scanner_error import MandatoryScannerMissingError
from analyzers.objective_compliance import ObjectiveComplianceAnalyzer


class TestWorkerEngineComponents(unittest.TestCase):

    def test_normalized_finding_model(self):
        f = NormalizedFinding(
            title="SQL Injection Vulnerability",
            severity="high",
            file_path="app/Services/UserService.php",
            line_start=42,
            line_end=45,
            description="Unsanitized query input.",
            tool_name="semgrep",
            tool_version="1.132.0",
            rule_id="php.lang.security.sql-injection"
        )

        d = f.to_dict()
        self.assertEqual(d["title"], "SQL Injection Vulnerability")
        self.assertEqual(d["severity"], "high")
        self.assertEqual(d["file_path"], "app/Services/UserService.php")
        self.assertEqual(d["line_start"], 42)
        self.assertEqual(d["tool_name"], "semgrep")
        self.assertTrue(d["is_blocking"])

    def test_severity_mapper(self):
        self.assertEqual(SeverityMapper.normalize("ERROR"), "high")
        self.assertEqual(SeverityMapper.normalize("WARNING"), "medium")
        self.assertEqual(SeverityMapper.normalize("INFO"), "low")
        self.assertEqual(SeverityMapper.from_cvss_score(9.8), "critical")
        self.assertEqual(SeverityMapper.from_cvss_score(7.2), "high")
        self.assertEqual(SeverityMapper.from_cvss_score(5.0), "medium")

    def test_finding_deduplicator(self):
        f1 = NormalizedFinding(title="Hardcoded API Secret", severity="high", file_path="config.py", line_start=10)
        f2 = NormalizedFinding(title="Hardcoded API Secret", severity="high", file_path="config.py", line_start=11)
        f3 = NormalizedFinding(title="Different Finding", severity="medium", file_path="app.py", line_start=50)

        results = FindingDeduplicator.deduplicate([f1, f2, f3])
        self.assertEqual(len(results), 2)

    def test_payload_builder(self):
        f = NormalizedFinding(title="Leak", severity="critical", file_path="key.txt", line_start=1)
        payload = PayloadBuilder.build(
            findings=[f],
            summary="Review summary text",
            pull_request_title="Fix Security",
            author="Dev",
            tools_used=["gitleaks"]
        )

        self.assertEqual(payload["status"], "failed")
        self.assertEqual(payload["summary"], "Review summary text")
        self.assertEqual(len(payload["findings"]), 1)
        self.assertTrue(payload["findings"][0]["is_blocking"])

    def test_git_service_requires_clone_url(self):
        git_svc = GitService()
        with self.assertRaises(CloneFailureError):
            git_svc.prepare_workspace(clone_url=None)

    def test_mandatory_scanner_missing_error(self):
        err = MandatoryScannerMissingError("semgrep")
        self.assertIn("semgrep", str(err))
        self.assertEqual(err.tool_name, "semgrep")


class TestGitServiceCheckout(unittest.TestCase):
    """Regression: PR/unmerged-branch commits must be fetched and verified."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="git-svc-test-")
        self.origin = os.path.join(self.tmp, "origin.git")
        self.workspaces = os.path.join(self.tmp, "workspaces")
        os.makedirs(self.workspaces, exist_ok=True)
        self.main_sha, self.feature_sha = self._build_origin_with_feature_branch()
        self.git_svc = GitService(base_workspace_dir=self.workspaces)

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def _run(self, cmd, cwd):
        result = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, check=True)
        return result.stdout.strip()

    def _build_origin_with_feature_branch(self):
        bare = self.origin
        work = os.path.join(self.tmp, "seed")
        os.makedirs(work)

        subprocess.run(["git", "init", "--bare", bare], check=True, capture_output=True)
        self._run(["git", "clone", bare, work], cwd=self.tmp)
        self._run(["git", "config", "user.email", "test@example.com"], cwd=work)
        self._run(["git", "config", "user.name", "Tester"], cwd=work)

        with open(os.path.join(work, "README.md"), "w", encoding="utf-8") as handle:
            handle.write("main\n")
        self._run(["git", "add", "README.md"], cwd=work)
        self._run(["git", "commit", "-m", "main commit"], cwd=work)
        self._run(["git", "branch", "-M", "main"], cwd=work)
        main_sha = self._run(["git", "rev-parse", "HEAD"], cwd=work)
        self._run(["git", "push", "-u", "origin", "main"], cwd=work)

        # Unmerged feature branch commit (missing from shallow single-branch clone of main)
        self._run(["git", "checkout", "-b", "feature/pr-head"], cwd=work)
        with open(os.path.join(work, "feature.txt"), "w", encoding="utf-8") as handle:
            handle.write("only-on-feature\n")
        self._run(["git", "add", "feature.txt"], cwd=work)
        self._run(["git", "commit", "-m", "feature commit"], cwd=work)
        feature_sha = self._run(["git", "rev-parse", "HEAD"], cwd=work)
        self._run(["git", "push", "-u", "origin", "feature/pr-head"], cwd=work)

        return main_sha, feature_sha

    def test_checks_out_unmerged_branch_commit_by_branch_name(self):
        workspace = self.git_svc.prepare_workspace(
            clone_url=self.origin,
            commit_sha=self.feature_sha,
            branch_name="feature/pr-head",
            base_branch="main",
        )
        try:
            head = self._run(["git", "rev-parse", "HEAD"], cwd=workspace)
            self.assertEqual(head, self.feature_sha)
            self.assertTrue(os.path.exists(os.path.join(workspace, "feature.txt")))
        finally:
            self.git_svc.cleanup(workspace)

    def test_checks_out_commit_via_direct_sha_fetch(self):
        workspace = self.git_svc.prepare_workspace(
            clone_url=self.origin,
            commit_sha=self.feature_sha,
            base_branch="main",
        )
        try:
            head = self._run(["git", "rev-parse", "HEAD"], cwd=workspace)
            self.assertEqual(head, self.feature_sha)
        finally:
            self.git_svc.cleanup(workspace)

    def test_missing_commit_raises_instead_of_scanning_default_head(self):
        fake_sha = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
        with self.assertRaises(CloneFailureError):
            self.git_svc.prepare_workspace(
                clone_url=self.origin,
                commit_sha=fake_sha,
                branch_name="main",
                base_branch="main",
            )
        self.assertEqual(os.listdir(self.workspaces), [])

    def test_diff_uses_merge_base_against_default_branch(self):
        workspace = self.git_svc.prepare_workspace(
            clone_url=self.origin,
            commit_sha=self.feature_sha,
            branch_name="feature/pr-head",
            base_branch="main",
        )
        try:
            diff = self.git_svc.get_commit_diff(workspace, base_branch="main")
            self.assertIn("feature.txt", diff)
            self.assertNotIn("Diff unavailable", diff)
        finally:
            self.git_svc.cleanup(workspace)


class StubOllama:
    """Returns a canned verdict payload instead of calling a local model."""

    model = "stub"

    def __init__(self, response):
        self.response = response
        self.prompts = []

    def generate_json(self, prompt, timeout=60):
        self.prompts.append(prompt)
        return self.response


class TestObjectiveComplianceAnalyzer(unittest.TestCase):

    OBJECTIVES = [
        {"id": 7, "code": "SEC-001", "title": "Secrets must never be committed", "is_mandatory": True},
        {"id": 8, "code": "PERF-002", "title": "Endpoints should respond within 300ms", "is_mandatory": False},
    ]

    DIFF = "+++ b/config.js\n+const apiKey = 'AKIAIOSFODNN7EXAMPLE';\n"

    def test_mandatory_violation_becomes_blocking_finding(self):
        ollama = StubOllama({"results": [{
            "id": 7,
            "violated": True,
            "confidence": 0.92,
            "evidence": "An API key is added directly to config.js.",
            "file_path": "config.js",
            "line_start": 2,
            "recommendation": "Load the key from the environment.",
        }]})

        findings = ObjectiveComplianceAnalyzer(ollama).analyze(self.OBJECTIVES, self.DIFF, "acme/repo")

        self.assertEqual(len(findings), 1)
        self.assertEqual(findings[0].srs_objective_id, 7)
        self.assertEqual(findings[0].severity, "high")
        self.assertTrue(findings[0].is_blocking())
        self.assertEqual(findings[0].to_dict()["objective_impact"], "An API key is added directly to config.js.")

    def test_advisory_violation_is_not_blocking(self):
        ollama = StubOllama({"results": [{"id": 8, "violated": True, "confidence": 0.8, "evidence": "Adds a slow query."}]})

        findings = ObjectiveComplianceAnalyzer(ollama).analyze(self.OBJECTIVES, self.DIFF, "acme/repo")

        self.assertEqual(len(findings), 1)
        self.assertEqual(findings[0].severity, "medium")
        self.assertFalse(findings[0].is_blocking())

    def test_low_confidence_verdicts_are_discarded(self):
        ollama = StubOllama({"results": [{"id": 7, "violated": True, "confidence": 0.2, "evidence": "Maybe?"}]})

        self.assertEqual(ObjectiveComplianceAnalyzer(ollama).analyze(self.OBJECTIVES, self.DIFF, "acme/repo"), [])

    def test_unknown_objective_ids_are_discarded(self):
        ollama = StubOllama({"results": [{"id": 999, "violated": True, "confidence": 0.99, "evidence": "Unrelated."}]})

        self.assertEqual(ObjectiveComplianceAnalyzer(ollama).analyze(self.OBJECTIVES, self.DIFF, "acme/repo"), [])

    def test_model_failure_degrades_gracefully(self):
        findings = ObjectiveComplianceAnalyzer(StubOllama(None)).analyze(self.OBJECTIVES, self.DIFF, "acme/repo")

        self.assertEqual(findings, [])

    def test_no_objectives_skips_model_entirely(self):
        ollama = StubOllama({"results": []})

        self.assertEqual(ObjectiveComplianceAnalyzer(ollama).analyze([], self.DIFF, "acme/repo"), [])
        self.assertEqual(ollama.prompts, [])

    def test_large_checklist_stops_after_max_calls(self):
        objectives = [
            {"id": i, "code": f"REQ-{i}", "title": f"Requirement {i}", "is_mandatory": False}
            for i in range(1, 129)
        ]
        ollama = StubOllama({"results": []})

        analyzer = ObjectiveComplianceAnalyzer(
            ollama,
            batch_size=20,
            max_calls=3,
            call_timeout_seconds=30,
            wall_clock_seconds=600,
        )
        findings = analyzer.analyze(objectives, self.DIFF, "acme/repo")

        self.assertEqual(findings, [])
        self.assertEqual(len(ollama.prompts), 3)

    def test_passes_short_timeout_to_ollama(self):
        class TimeoutProbe(StubOllama):
            def __init__(self):
                super().__init__({"results": []})
                self.timeouts = []

            def generate_json(self, prompt, timeout=60):
                self.timeouts.append(timeout)
                return super().generate_json(prompt, timeout=timeout)

        ollama = TimeoutProbe()
        ObjectiveComplianceAnalyzer(
            ollama,
            batch_size=5,
            max_calls=1,
            call_timeout_seconds=30,
        ).analyze(self.OBJECTIVES, self.DIFF, "acme/repo")

        self.assertEqual(ollama.timeouts, [30])

    def test_payload_fails_review_for_blocking_objective_breach(self):
        ollama = StubOllama({"results": [{"id": 7, "violated": True, "confidence": 0.9, "evidence": "Key committed."}]})
        findings = ObjectiveComplianceAnalyzer(ollama).analyze(self.OBJECTIVES, self.DIFF, "acme/repo")

        payload = PayloadBuilder.build(
            findings=findings,
            summary="Objective review",
            pull_request_title="Add config",
            author="Dev",
            tools_used=["objective-compliance"],
            objectives_evaluated=len(self.OBJECTIVES),
        )

        self.assertEqual(payload["status"], "failed")
        self.assertTrue(payload["findings"][0]["is_blocking"])
        self.assertEqual(payload["objectives_evaluated"], 2)
        self.assertEqual(payload["findings"][0]["srs_objective_id"], 7)


class TestRedactionAndFingerprint(unittest.TestCase):
    def test_gitleaks_style_match_is_redacted_in_payload(self):
        finding = NormalizedFinding(
            title="Hardcoded Secret Leaked",
            severity="high",
            file_path="config/.env",
            line_start=12,
            description="Potential secret. Match: sk-live-ABCDEFG1234567890",
            tool_name="gitleaks",
            rule_id="generic-api-key",
            raw_output={"Match": "sk-live-ABCDEFG1234567890", "RuleID": "generic-api-key"},
        )

        payload = finding.to_dict()
        self.assertNotIn("sk-live", payload["description"])
        self.assertIn("[REDACTED]", payload["description"])
        self.assertEqual(payload["raw_output"]["Match"], "[REDACTED]")
        self.assertIn("fingerprint", payload)
        self.assertEqual(len(payload["fingerprint"]), 64)

    def test_fingerprint_is_stable_within_line_bucket(self):
        from fingerprint import fingerprint

        a = fingerprint("rule-a", "app/User.php", 8, "SQL Injection")
        b = fingerprint("rule-a", "app/User.php", 10, "SQL Injection")
        c = fingerprint("rule-a", "app/User.php", 16, "SQL Injection")

        self.assertEqual(a, b)
        self.assertNotEqual(a, c)


if __name__ == "__main__":
    unittest.main()
