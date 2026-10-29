import logging
from typing import List
from models.finding import NormalizedFinding

logger = logging.getLogger("FindingDeduplicator")


class FindingDeduplicator:
    @classmethod
    def deduplicate(cls, findings: List[NormalizedFinding]) -> List[NormalizedFinding]:
        if not findings:
            return []

        unique_findings: List[NormalizedFinding] = []
        seen_fingerprints = set()
        seen_fallback_keys = set()

        for item in findings:
            fp = item.fingerprint()
            if fp and fp in seen_fingerprints:
                logger.debug(
                    "Skipping duplicate finding by fingerprint: %s on %s:%s",
                    item.title,
                    item.file_path,
                    item.line_start,
                )
                continue

            # Secondary key for findings missing a stable fingerprint.
            norm_path = (item.file_path or "Unknown").strip().lower()
            norm_title = (item.title or "").strip().lower()[:40]
            line_bucket = item.line_start // 3
            fallback = (norm_path, line_bucket, norm_title, (item.rule_id or "").lower())

            if not fp and fallback in seen_fallback_keys:
                logger.debug(
                    "Skipping duplicate finding by fallback key: %s on %s:%s",
                    item.title,
                    item.file_path,
                    item.line_start,
                )
                continue

            if fp:
                seen_fingerprints.add(fp)
            else:
                seen_fallback_keys.add(fallback)

            # Merge tool provenance when a fingerprint collides we already skipped;
            # for kept items, annotate contributing tool.
            unique_findings.append(item)

        logger.info(
            "Deduplication complete: reduced %s raw findings down to %s unique items.",
            len(findings),
            len(unique_findings),
        )
        return unique_findings
