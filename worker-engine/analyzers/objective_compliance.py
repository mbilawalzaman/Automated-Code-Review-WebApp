import json
import logging
import os
import time
from typing import Any, Dict, List, Optional

from models.finding import NormalizedFinding
from ollama_client import OllamaClient

try:
    from config import (
        OBJECTIVE_BATCH_SIZE,
        OBJECTIVE_CALL_TIMEOUT_SECONDS,
        OBJECTIVE_MAX_CALLS,
        OBJECTIVE_WALL_CLOCK_SECONDS,
    )
except ImportError:  # pragma: no cover - tests may import without full config
    OBJECTIVE_BATCH_SIZE = max(1, int(os.getenv("OBJECTIVE_BATCH_SIZE", "20")))
    OBJECTIVE_CALL_TIMEOUT_SECONDS = max(5, int(os.getenv("OBJECTIVE_CALL_TIMEOUT_SECONDS", "30")))
    OBJECTIVE_MAX_CALLS = max(1, int(os.getenv("OBJECTIVE_MAX_CALLS", "3")))
    OBJECTIVE_WALL_CLOCK_SECONDS = max(15, int(os.getenv("OBJECTIVE_WALL_CLOCK_SECONDS", "120")))

logger = logging.getLogger("ObjectiveCompliance")

# Below this confidence a non-compliance verdict is discarded rather than reported,
# keeping false positives out of merge-blocking findings.
MIN_CONFIDENCE = 0.6

MAX_DIFF_CHARS = 6000


class ObjectiveComplianceAnalyzer:
    """
    Evaluates a change against the approved SRS requirement checklist (SRS-004).

    Only requirements the platform has already approved are received here, and the
    analyzer is fault-tolerant: if the model is unavailable or the per-job budget is
    exhausted it returns whatever findings it has so the technical and security
    review still completes.
    """

    def __init__(
        self,
        ollama: Optional[OllamaClient] = None,
        *,
        batch_size: Optional[int] = None,
        call_timeout_seconds: Optional[int] = None,
        max_calls: Optional[int] = None,
        wall_clock_seconds: Optional[int] = None,
    ):
        self.ollama = ollama or OllamaClient()
        self.batch_size = max(1, int(batch_size if batch_size is not None else OBJECTIVE_BATCH_SIZE))
        self.call_timeout_seconds = max(
            5,
            int(call_timeout_seconds if call_timeout_seconds is not None else OBJECTIVE_CALL_TIMEOUT_SECONDS),
        )
        self.max_calls = max(1, int(max_calls if max_calls is not None else OBJECTIVE_MAX_CALLS))
        self.wall_clock_seconds = max(
            15,
            int(wall_clock_seconds if wall_clock_seconds is not None else OBJECTIVE_WALL_CLOCK_SECONDS),
        )

    def analyze(
        self,
        objectives: List[Dict[str, Any]],
        diff_text: str,
        repo_name: str,
    ) -> List[NormalizedFinding]:
        if not objectives:
            logger.info("No approved SRS objectives supplied; skipping objective compliance analysis.")
            return []

        if not diff_text or not diff_text.strip():
            logger.info("No diff content available; objective compliance analysis skipped.")
            return []

        findings: List[NormalizedFinding] = []
        started = time.monotonic()
        calls = 0
        evaluated = 0
        stopped_reason: Optional[str] = None

        for start in range(0, len(objectives), self.batch_size):
            elapsed = time.monotonic() - started
            if calls >= self.max_calls:
                stopped_reason = f"max_calls={self.max_calls}"
                break
            if elapsed >= self.wall_clock_seconds:
                stopped_reason = f"wall_clock={self.wall_clock_seconds}s"
                break

            batch = objectives[start:start + self.batch_size]
            calls += 1
            verdicts = self._evaluate_batch(batch, diff_text, repo_name)
            evaluated += len(batch)

            for verdict in verdicts:
                finding = self._to_finding(verdict, batch)
                if finding:
                    findings.append(finding)

        if stopped_reason:
            remaining = max(0, len(objectives) - evaluated)
            logger.warning(
                "Objective compliance stopped early (%s) after %d Ollama call(s), "
                "%d/%d requirement(s) evaluated, %d remaining skipped. "
                "SAST findings will still be submitted.",
                stopped_reason,
                calls,
                evaluated,
                len(objectives),
                remaining,
            )
        else:
            logger.info(
                "Objective compliance analysis produced %d finding(s) "
                "across %d approved requirement(s) in %d Ollama call(s).",
                len(findings),
                len(objectives),
                calls,
            )
        return findings

    def _evaluate_batch(
        self,
        batch: List[Dict[str, Any]],
        diff_text: str,
        repo_name: str,
    ) -> List[Dict[str, Any]]:
        requirement_lines = []
        for objective in batch:
            requirement_lines.append(
                f"- id={objective.get('id')} | code={objective.get('code')} | "
                f"mandatory={'yes' if objective.get('is_mandatory') else 'no'} | "
                f"requirement: {objective.get('title')}"
                + (f" | detail: {objective.get('description')}" if objective.get('description') else "")
            )

        prompt = f"""You are auditing a code change in repository '{repo_name}' against approved project requirements.

Requirements:
{chr(10).join(requirement_lines)}

Code diff under review:
{diff_text[:MAX_DIFF_CHARS]}

For each requirement, decide whether this diff violates it. Report a violation only when the
diff itself contains clear evidence; if the requirement is unrelated to the diff, treat it as
not violated. Never guess.

Respond with JSON only:
{{
  "results": [
    {{
      "id": <requirement id>,
      "violated": true,
      "confidence": 0.0,
      "evidence": "What in the diff conflicts with the requirement",
      "file_path": "path/to/file",
      "line_start": 1,
      "recommendation": "How to comply"
    }}
  ]
}}
"""

        response = self.ollama.generate_json(prompt, timeout=self.call_timeout_seconds)

        if not response:
            logger.warning(
                "Objective compliance batch skipped after Ollama failure/timeout "
                "(%ss) covering %d requirement(s).",
                self.call_timeout_seconds,
                len(batch),
            )
            return []

        results = response.get("results", [])

        if isinstance(results, dict):
            results = [results]

        return [item for item in results if isinstance(item, dict)]

    def _to_finding(
        self,
        verdict: Dict[str, Any],
        batch: List[Dict[str, Any]],
    ) -> Optional[NormalizedFinding]:
        if not self._is_true(verdict.get("violated")):
            return None

        objective = self._match_objective(verdict, batch)
        if not objective:
            return None

        try:
            confidence = float(verdict.get("confidence", 0))
        except (TypeError, ValueError):
            confidence = 0.0

        if confidence < MIN_CONFIDENCE:
            logger.info(
                f"Discarded low-confidence ({confidence}) objective verdict for "
                f"{objective.get('code')}."
            )
            return None

        is_mandatory = bool(objective.get("is_mandatory"))
        evidence = str(verdict.get("evidence") or "").strip()
        code = objective.get("code") or f"OBJ-{objective.get('id')}"

        try:
            line_start = max(1, int(verdict.get("line_start", 1)))
        except (TypeError, ValueError):
            line_start = 1

        return NormalizedFinding(
            title=f"Objective {code} not met: {objective.get('title')}",
            severity="high" if is_mandatory else "medium",
            file_path=str(verdict.get("file_path") or "Unknown"),
            line_start=line_start,
            line_end=line_start,
            description=evidence or f"The change does not satisfy requirement {code}.",
            tool_name="objective-compliance",
            tool_version="1.0.0",
            rule_id=f"SRS-{code}",
            confidence=min(max(confidence, 0.0), 1.0),
            remediation=str(verdict.get("recommendation") or "").strip() or None,
            srs_objective_id=objective.get("id"),
            objective_impact=evidence or None,
            # Mandatory requirements block the merge irrespective of severity (SRS-005).
            is_blocking_override=True if is_mandatory else None,
            raw_output={"objective": objective, "verdict": verdict},
        )

    def _match_objective(
        self,
        verdict: Dict[str, Any],
        batch: List[Dict[str, Any]],
    ) -> Optional[Dict[str, Any]]:
        # Prefer the id the platform issued; fall back to the requirement code.
        raw_id = verdict.get("id")

        if raw_id is not None:
            try:
                objective_id = int(raw_id)
                for objective in batch:
                    if int(objective.get("id", 0)) == objective_id:
                        return objective
            except (TypeError, ValueError):
                pass

        code = str(verdict.get("code") or "").strip().upper()
        if code:
            for objective in batch:
                if str(objective.get("code") or "").strip().upper() == code:
                    return objective

        logger.warning(f"Objective verdict did not match any supplied requirement: {json.dumps(verdict)[:200]}")
        return None

    @staticmethod
    def _is_true(value: Any) -> bool:
        if isinstance(value, bool):
            return value
        return str(value).strip().lower() in ("true", "yes", "1")
