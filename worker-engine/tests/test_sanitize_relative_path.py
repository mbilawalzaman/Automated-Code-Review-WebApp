"""Tests for path sanitization and fingerprint stability."""

from __future__ import annotations

import hashlib
from pathlib import Path

from fingerprint import fingerprint, normalize_path, sanitize_relative_path


def _legacy_normalize(path: str | None) -> str:
    """Pre-sanitize fingerprint normalize (must stay compatible for relative paths)."""
    if not path:
        return "unknown"
    normalized = path.replace("\\", "/").strip().lstrip("./")
    return normalized.lower()


def test_sanitize_empty_returns_unknown():
    assert sanitize_relative_path(None) == "Unknown"
    assert sanitize_relative_path("") == "Unknown"
    assert sanitize_relative_path("   ") == "Unknown"


def test_sanitize_already_relative():
    assert sanitize_relative_path("app/Models/User.php") == "app/Models/User.php"
    assert sanitize_relative_path("./app/Http/Kernel.php") == "app/Http/Kernel.php"


def test_sanitize_absolute_under_workspace(tmp_path: Path):
    workspace = tmp_path / "job_abc"
    target = workspace / "app" / "Services" / "Foo.php"
    target.parent.mkdir(parents=True)
    target.write_text("<?php\n")

    abs_path = str(target)
    assert sanitize_relative_path(abs_path, str(workspace)) == "app/Services/Foo.php"


def test_sanitize_relative_with_workspace(tmp_path: Path):
    workspace = tmp_path / "ws"
    (workspace / "src").mkdir(parents=True)
    (workspace / "src" / "main.py").write_text("x=1\n")

    assert sanitize_relative_path("src/main.py", str(workspace)) == "src/main.py"


def test_sanitize_strips_scanner_workspaces_prefix_without_leaving_job_dir():
    raw = "/var/folders/xx/T/scanner-workspaces/job_a1b2c3d4/app/Http/Controllers/HomeController.php"
    assert (
        sanitize_relative_path(raw)
        == "app/Http/Controllers/HomeController.php"
    )


def test_sanitize_strips_job_dir_only_prefix():
    raw = "/tmp/job_deadbeef/resources/js/app.js"
    assert sanitize_relative_path(raw) == "resources/js/app.js"


def test_sanitize_windows_separators():
    raw = r"C:\Temp\scanner-workspaces\job_abcd1234\app\Models\User.php"
    assert sanitize_relative_path(raw) == "app/Models/User.php"


def test_sanitize_does_not_keep_job_uuid_segment():
    raw = "/tmp/scanner-workspaces/job_ff00aa11/vendor/autoload.php"
    result = sanitize_relative_path(raw)
    assert not result.startswith("job_")
    assert result == "vendor/autoload.php"


def test_normalize_path_matches_legacy_for_relative_paths():
    samples = [
        "app/Models/User.php",
        "./resources/views/welcome.blade.php",
        "src\\main.py",
        None,
        "",
    ]
    for sample in samples:
        assert normalize_path(sample) == _legacy_normalize(sample)


def test_fingerprint_stable_for_relative_repo_paths():
    """FND-002: relative paths must hash the same as the prior normalize implementation."""
    rule = "phpstan"
    path = "app/Services/WorkerService.php"
    line = 12
    title = "PHPStan: Undefined variable"

    bucket = max(1, ((line - 1) // 5) * 5 + 1)
    title_token = "phpstan: undefined variable"[:40]
    material = f"{rule}|{_legacy_normalize(path)}|{bucket}|{title_token}"
    expected = hashlib.sha256(material.encode("utf-8")).hexdigest()

    assert fingerprint(rule, path, line, title) == expected


def test_fingerprint_uses_sanitized_absolute_path(tmp_path: Path):
    workspace = tmp_path / "job_cafe"
    target = workspace / "app" / "A.php"
    target.parent.mkdir(parents=True)
    target.write_text("<?php\n")

    rel = sanitize_relative_path(str(target), str(workspace))
    assert fingerprint("ruff", rel, 1, "x") == fingerprint("ruff", "app/A.php", 1, "x")
