from dataclasses import dataclass, field
from typing import Optional, Dict, Any

from fingerprint import fingerprint as build_fingerprint
from redaction import redact_text, sanitize_raw_output


@dataclass
class NormalizedFinding:
    title: str
    severity: str  # 'critical', 'high', 'medium', 'low'
    file_path: str
    line_start: int = 1
    line_end: int = 1
    description: str = ""
    tool_name: str = "unknown"
    tool_version: str = "1.0.0"
    rule_id: str = "general"
    confidence: float = 1.0  # 0.0 to 1.0
    remediation: Optional[str] = None
    srs_objective_id: Optional[int] = None
    objective_impact: Optional[str] = None
    # Set explicitly when a mandatory SRS objective is violated; otherwise severity decides.
    is_blocking_override: Optional[bool] = None
    raw_output: Optional[Dict[str, Any]] = field(default_factory=dict)

    def is_blocking(self) -> bool:
        if self.is_blocking_override is not None:
            return self.is_blocking_override
        return self.severity.lower() in ("critical", "high")

    def fingerprint(self) -> str:
        return build_fingerprint(
            rule_id=self.rule_id,
            file_path=self.file_path,
            line_start=self.line_start,
            title=self.title,
        )

    def to_dict(self) -> Dict[str, Any]:
        severity = self.severity.lower()
        is_blocking = self.is_blocking()
        return {
            "title": self.title,
            "severity": severity,
            "file_path": self.file_path if self.file_path else "Unknown",
            "line_start": self.line_start,
            "line_end": self.line_end,
            "description": redact_text(self.description),
            "tool_name": self.tool_name,
            "tool_version": self.tool_version,
            "rule_id": self.rule_id,
            "confidence": self.confidence,
            "remediation": redact_text(self.remediation or ""),
            "srs_objective_id": self.srs_objective_id,
            "objective_impact": redact_text(self.objective_impact) if self.objective_impact else None,
            "is_blocking": is_blocking,
            "fingerprint": self.fingerprint(),
            # Never send raw secret match material to the VPS (AUD-003 / SEC-003).
            "raw_output": sanitize_raw_output(self.raw_output),
        }
