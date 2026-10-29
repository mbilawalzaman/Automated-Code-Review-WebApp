"""Detect repository language/framework stacks from manifests (AI-001)."""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from typing import Dict, List, Set


@dataclass
class StackDetection:
    stacks: Set[str] = field(default_factory=set)
    tools: Set[str] = field(default_factory=set)
    manifests: Dict[str, bool] = field(default_factory=dict)

    def to_dict(self) -> Dict[str, object]:
        return {
            "stacks": sorted(self.stacks),
            "tools": sorted(self.tools),
            "manifests": dict(self.manifests),
        }


class LanguageDetector:
    """
    Detect PHP/Laravel, Python, JavaScript/TypeScript, and Playwright from
    common project configuration files and lightweight extension sampling.
    """

    def detect(self, workspace_dir: str) -> StackDetection:
        result = StackDetection()
        if not workspace_dir or not os.path.isdir(workspace_dir):
            return result

        composer = os.path.join(workspace_dir, "composer.json")
        package = os.path.join(workspace_dir, "package.json")
        pyproject = os.path.join(workspace_dir, "pyproject.toml")
        requirements = os.path.join(workspace_dir, "requirements.txt")
        setup_py = os.path.join(workspace_dir, "setup.py")
        phpunit = os.path.join(workspace_dir, "phpunit.xml")
        phpunit_dist = os.path.join(workspace_dir, "phpunit.xml.dist")
        playwright_js = os.path.join(workspace_dir, "playwright.config.js")
        playwright_ts = os.path.join(workspace_dir, "playwright.config.ts")
        playwright_mjs = os.path.join(workspace_dir, "playwright.config.mjs")

        result.manifests = {
            "composer.json": os.path.isfile(composer),
            "package.json": os.path.isfile(package),
            "pyproject.toml": os.path.isfile(pyproject),
            "requirements.txt": os.path.isfile(requirements),
            "phpunit.xml": os.path.isfile(phpunit) or os.path.isfile(phpunit_dist),
            "playwright.config": (
                os.path.isfile(playwright_js)
                or os.path.isfile(playwright_ts)
                or os.path.isfile(playwright_mjs)
            ),
        }

        if result.manifests["composer.json"] or self._has_extension(workspace_dir, {".php"}):
            result.stacks.add("php")
            result.tools.update({"phpstan", "phpunit"})

        if (
            result.manifests["pyproject.toml"]
            or result.manifests["requirements.txt"]
            or os.path.isfile(setup_py)
            or self._has_extension(workspace_dir, {".py"})
        ):
            result.stacks.add("python")
            result.tools.update({"ruff", "bandit", "pytest"})

        package_data: Dict[str, object] = {}
        if result.manifests["package.json"]:
            package_data = self._read_json(package)
            result.stacks.add("javascript")
            result.tools.add("eslint")
            deps = {
                **(package_data.get("dependencies") or {}),
                **(package_data.get("devDependencies") or {}),
            }
            if any(name.startswith("@playwright/") or name == "playwright" for name in deps):
                result.stacks.add("playwright")
                result.tools.add("playwright")
            scripts = package_data.get("scripts") or {}
            if isinstance(scripts, dict) and "test" in scripts:
                result.tools.add("npm-test")

        if result.manifests["playwright.config"]:
            result.stacks.add("playwright")
            result.tools.add("playwright")

        if not result.stacks and self._has_extension(workspace_dir, {".js", ".ts", ".tsx", ".jsx"}):
            result.stacks.add("javascript")
            result.tools.add("eslint")

        return result

    def _has_extension(self, workspace_dir: str, extensions: Set[str], limit: int = 400) -> bool:
        seen = 0
        for root, dirs, files in os.walk(workspace_dir):
            dirs[:] = [
                d for d in dirs
                if d not in {"vendor", "node_modules", ".git", "dist", "build", "coverage", ".venv", "vendor-bin"}
            ]
            for name in files:
                seen += 1
                if seen > limit:
                    return False
                _, ext = os.path.splitext(name)
                if ext.lower() in extensions:
                    return True
        return False

    def _read_json(self, path: str) -> Dict[str, object]:
        try:
            with open(path, "r", encoding="utf-8") as handle:
                data = json.load(handle)
                return data if isinstance(data, dict) else {}
        except Exception:
            return {}
