"""Ruff JSON fix objects must not crash finding serialization / redaction."""

from __future__ import annotations

import json
from unittest.mock import MagicMock, patch

from models.finding import NormalizedFinding
from redaction import redact_text
from scanners.ruff_runner import RuffRunner, remediation_from_ruff_fix


def test_remediation_from_ruff_fix_dict_uses_message():
    assert (
        remediation_from_ruff_fix({"applicability": "safe", "message": "Remove unused import"})
        == "Remove unused import"
    )


def test_remediation_from_ruff_fix_dict_without_message_uses_default():
    assert remediation_from_ruff_fix({"edits": []}) == "Apply the Ruff-recommended fix."


def test_remediation_from_ruff_fix_string():
    assert remediation_from_ruff_fix("  Use f-string  ") == "Use f-string"


def test_redact_text_tolerates_dict():
    assert redact_text({"message": "secret"}) == ""
    assert "REDACTED" in redact_text("api_key=supersecret")


def test_finding_to_dict_with_ruff_style_fix_object_via_runner():
    payload = [
        {
            "filename": "/tmp/ws/app.py",
            "code": "F401",
            "message": "Unused import",
            "severity": "WARNING",
            "location": {"row": 2},
            "end_location": {"row": 2},
            "fix": {"applicability": "safe", "message": "Remove unused import: os"},
        }
    ]
    completed = MagicMock(stdout=json.dumps(payload), returncode=1)
    with (
        patch("scanners.ruff_runner.os.path.isdir", return_value=True),
        patch("scanners.ruff_runner.subprocess.run", return_value=completed),
    ):
        findings = RuffRunner(tool_version="0.1.0").scan("/tmp/ws")

    assert len(findings) == 1
    assert findings[0].remediation == "Remove unused import: os"
    serialized = findings[0].to_dict()
    assert serialized["remediation"] == "Remove unused import: os"
    assert serialized["description"] == "Unused import"


def test_normalized_finding_to_dict_survives_dict_remediation():
    finding = NormalizedFinding(
        title="Ruff: F401",
        severity="medium",
        file_path="app.py",
        remediation={"message": "Remove unused import"},  # type: ignore[arg-type]
    )
    # Hardening: redact_text must not raise TypeError on dict remediation.
    payload = finding.to_dict()
    assert payload["remediation"] == ""
