"""Static-first trust gate (WF-007 / AC-009 / ARCH-004)."""

from __future__ import annotations

from typing import Any, Dict


def execution_authorized(job: Dict[str, Any]) -> bool:
    """
    Only execute repository tests / Playwright when the control plane explicitly
    authorizes execution. Static scanners always run.
    """
    if bool(job.get("execution_authorized")):
        return True

    payload = job.get("payload") if isinstance(job.get("payload"), dict) else {}
    if bool(payload.get("execution_authorized")):
        return True

    return False


def authorization_reason(job: Dict[str, Any]) -> str:
    if execution_authorized(job):
        return str(job.get("execution_authorization_reason") or "authorized")
    return str(job.get("execution_authorization_reason") or "static_first_untrusted")
