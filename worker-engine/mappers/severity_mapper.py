class SeverityMapper:
    VALID_SEVERITIES = {"critical", "high", "medium", "low"}

    @classmethod
    def normalize(cls, raw_severity: str, default: str = "medium") -> str:
        if not raw_severity:
            return default

        val = str(raw_severity).strip().lower()
        if val in cls.VALID_SEVERITIES:
            return val

        mapping = {
            "error": "high",
            "warning": "medium",
            "info": "low",
            "note": "low",
            "fatal": "critical",
            "critical": "critical",
            "high": "high",
            "medium": "medium",
            "low": "low",
            "passthrough": default
        }

        return mapping.get(val, default)

    @classmethod
    def from_cvss_score(cls, score: float) -> str:
        try:
            val = float(score)
            if val >= 9.0:
                return "critical"
            elif val >= 7.0:
                return "high"
            elif val >= 4.0:
                return "medium"
            else:
                return "low"
        except (ValueError, TypeError):
            return "medium"
