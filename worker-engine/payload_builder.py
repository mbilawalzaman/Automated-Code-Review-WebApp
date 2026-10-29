import logging
from typing import List, Dict, Any, Optional
from analyzers.finding_prioritizer import slim_finding_dict
from models.finding import NormalizedFinding

logger = logging.getLogger("PayloadBuilder")


class PayloadBuilder:
    @classmethod
    def build(
        cls,
        findings: List[NormalizedFinding],
        summary: str,
        pull_request_title: str,
        author: str,
        tools_used: List[str],
        status: Optional[str] = None,
        error_message: Optional[str] = None,
        objectives_evaluated: int = 0,
        stacks: Optional[List[str]] = None,
        test_summary: Optional[Dict[str, Any]] = None,
        coverage_percentage: Optional[float] = None,
        changed_code_coverage: Optional[float] = None,
        execution_authorized: Optional[bool] = None,
        change_classes: Optional[List[str]] = None,
        impact_analysis: Optional[Dict[str, Any]] = None,
        tool_executions: Optional[List[Dict[str, Any]]] = None,
        findings_metrics: Optional[Dict[str, Any]] = None,
        changed_files_scoped: Optional[int] = None,
    ) -> Dict[str, Any]:

        finding_dicts = [slim_finding_dict(f.to_dict()) for f in findings]

        if not status:
            if error_message:
                status = "review_error"
            else:
                if any(f.is_blocking() for f in findings):
                    status = "failed"
                elif len(findings) > 0:
                    status = "passed_with_warnings"
                else:
                    status = "passed"

        derived_classes = set(change_classes or [])
        for finding in findings:
            raw = finding.raw_output or {}
            if isinstance(raw, dict) and raw.get("change_class"):
                derived_classes.add(str(raw["change_class"]))

        return {
            "status": status,
            "summary": summary,
            "pull_request_title": pull_request_title,
            "author": author,
            "tools_used": tools_used,
            "error_message": error_message,
            "objectives_evaluated": objectives_evaluated,
            "stacks": stacks or [],
            "test_summary": test_summary or {},
            "coverage_percentage": coverage_percentage,
            "changed_code_coverage": changed_code_coverage,
            "execution_authorized": execution_authorized,
            "change_classes": sorted(derived_classes),
            "impact_analysis": impact_analysis or {},
            "tool_executions": tool_executions or [],
            "findings": finding_dicts,
            "findings_metrics": findings_metrics or {
                "findings_total": len(finding_dicts),
                "findings_submitted": len(finding_dicts),
                "findings_truncated": 0,
                "truncated": False,
            },
            "changed_files_scoped": changed_files_scoped,
        }
