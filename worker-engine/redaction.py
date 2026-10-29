"""Redact sensitive values before findings leave the Mac worker (SEC-003 / AUD-003)."""

from __future__ import annotations

import re
from typing import Any, Dict, Optional


_PATTERNS = [
    re.compile(r"(?i)(api[_-]?key|secret|password|token|private[_-]?key)\s*[:=]\s*\S+"),
    re.compile(r"(?i)Bearer\s+[A-Za-z0-9\-._~+/=]+"),
    re.compile(r"(?i)(AKIA|ASIA)[0-9A-Z]{16}"),
    re.compile(r"-----BEGIN (?:RSA )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA )?PRIVATE KEY-----"),
    re.compile(r"(?i)Match:\s*\S+"),
    re.compile(r"(?i)ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"(?i)gho_[A-Za-z0-9]{20,}"),
    re.compile(r"(?i)sk-[A-Za-z0-9]{20,}"),
]


def redact_text(text: Optional[Any]) -> str:
    """Redact secrets from text. Coerce non-strings so scanner dict fields cannot crash re.sub."""
    if text is None:
        return ""
    if not isinstance(text, str):
        if isinstance(text, (dict, list)):
            return ""
        text = str(text)
    if not text:
        return ""

    redacted = text
    for pattern in _PATTERNS:
        redacted = pattern.sub("[REDACTED]", redacted)
    return redacted


def sanitize_raw_output(raw: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    """Strip secret match material from scanner raw payloads before VPS upload."""
    if not isinstance(raw, dict):
        return {}

    sanitized: Dict[str, Any] = {}
    for key, value in raw.items():
        lower = str(key).lower()
        if lower in {"match", "secret", "password", "token", "private_key", "snippet", "line"}:
            sanitized[key] = "[REDACTED]"
        elif isinstance(value, str):
            sanitized[key] = redact_text(value)
        elif isinstance(value, dict):
            sanitized[key] = sanitize_raw_output(value)
        else:
            sanitized[key] = value
    return sanitized
