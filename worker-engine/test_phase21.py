import os
import tempfile
import unittest
from analyzers.language_detector import LanguageDetector
from analyzers.path_exclusions import filter_findings, is_excluded_path
from analyzers.trust_gate import execution_authorized
from analyzers.test_runner import TestRunner
from models.finding import NormalizedFinding


class TestLanguageDetector(unittest.TestCase):
    def test_detects_php_laravel_stack(self):
        with tempfile.TemporaryDirectory() as tmp:
            with open(os.path.join(tmp, "composer.json"), "w", encoding="utf-8") as handle:
                handle.write('{"name":"acme/app"}')
            os.makedirs(os.path.join(tmp, "app"))
            with open(os.path.join(tmp, "app", "User.php"), "w", encoding="utf-8") as handle:
                handle.write("<?php\n")

            detection = LanguageDetector().detect(tmp)
            self.assertIn("php", detection.stacks)
            self.assertIn("phpstan", detection.tools)

    def test_detects_playwright_from_package_json(self):
        with tempfile.TemporaryDirectory() as tmp:
            with open(os.path.join(tmp, "package.json"), "w", encoding="utf-8") as handle:
                handle.write('{"devDependencies":{"@playwright/test":"1.40.0"},"scripts":{"test":"jest"}}')

            detection = LanguageDetector().detect(tmp)
            self.assertIn("javascript", detection.stacks)
            self.assertIn("playwright", detection.stacks)
            self.assertIn("eslint", detection.tools)


class TestPathExclusions(unittest.TestCase):
    def test_excludes_vendor_and_minified(self):
        self.assertTrue(is_excluded_path("vendor/laravel/framework/src/Foo.php"))
        self.assertTrue(is_excluded_path("public/app.min.js"))
        self.assertFalse(is_excluded_path("app/Services/UserService.php"))

    def test_filter_keeps_gitleaks_in_vendor(self):
        findings = [
            NormalizedFinding(title="secret", severity="high", file_path="vendor/pkg/x.php", tool_name="gitleaks"),
            NormalizedFinding(title="style", severity="medium", file_path="vendor/pkg/x.php", tool_name="phpstan"),
        ]
        filtered = filter_findings(findings)
        self.assertEqual(len(filtered), 1)
        self.assertEqual(filtered[0].tool_name, "gitleaks")


class TestTrustGate(unittest.TestCase):
    def test_defaults_to_static_only(self):
        self.assertFalse(execution_authorized({"payload": {"author": "outsider"}}))

    def test_authorized_flag_allows_execution(self):
        self.assertTrue(execution_authorized({"execution_authorized": True}))


class TestCoverageParsing(unittest.TestCase):
    def test_changed_line_coverage_from_clover(self):
        with tempfile.TemporaryDirectory() as tmp:
            clover = os.path.join(tmp, "clover.xml")
            with open(clover, "w", encoding="utf-8") as handle:
                handle.write(
                    """<?xml version="1.0"?>
                    <coverage>
                      <project>
                        <file name="app/User.php">
                          <line num="10" type="stmt" count="1"/>
                          <line num="11" type="stmt" count="0"/>
                          <metrics statements="2" coveredstatements="1"/>
                        </file>
                        <metrics statements="2" coveredstatements="1"/>
                      </project>
                    </coverage>
                    """
                )

            runner = TestRunner()
            changed = {"app/User.php": {10, 11}}
            coverage = runner._changed_line_coverage(clover, changed)
            self.assertEqual(coverage, 50.0)


if __name__ == "__main__":
    unittest.main()
