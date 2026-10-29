import requests
import json
import logging
from typing import List, Dict, Any, Optional
from config import OLLAMA_HOST, OLLAMA_MODEL
from models.finding import NormalizedFinding

logger = logging.getLogger("OllamaClient")

class OllamaClient:
    def __init__(self, host=OLLAMA_HOST, model=OLLAMA_MODEL):
        self.host = host
        self.model = model

    def generate_json(self, prompt: str, timeout: int = 60) -> Optional[Dict[str, Any]]:
        """
        Ask the local model for a single JSON object. Returns None on any failure so
        callers can degrade gracefully instead of failing the review.
        """
        try:
            res = requests.post(
                f"{self.host}/api/generate",
                json={"model": self.model, "prompt": prompt, "stream": False, "format": "json"},
                timeout=timeout,
            )

            if res.status_code != 200:
                logger.warning(f"Ollama returned HTTP {res.status_code} for JSON generation.")
                return None

            parsed = json.loads(res.json().get("response", "{}"))
            return parsed if isinstance(parsed, dict) else None
        except Exception as e:
            logger.warning(f"Ollama JSON generation failed gracefully ({e}).")
            return None

    def enrich_findings(self, findings: List[NormalizedFinding], diff_text: str, repo_name: str) -> Dict[str, Any]:
        """
        Uses local Ollama LLM to enrich empirical SAST findings with AI root cause explanations,
        remediation code snippets, and an executive review summary.
        Fault-tolerant: If Ollama times out or fails, returns SAST findings un-enriched.
        """
        if not findings and not diff_text:
            return {
                "summary": f"Automated code review completed for {repo_name}. No findings detected.",
                "enriched_findings": []
            }

        finding_summaries = []
        for idx, f in enumerate(findings[:10]):  # Cap at top 10 for LLM prompt context size
            finding_summaries.append(f"[{idx+1}] Tool: {f.tool_name} | Rule: {f.rule_id} | File: {f.file_path}:{f.line_start} | Title: {f.title}")

        findings_text = "\n".join(finding_summaries) if finding_summaries else "No automated SAST tool findings."
        diff_snippet = diff_text[:3000] if diff_text else "No diff content provided."

        prompt = f"""You are a Lead AI Security Auditor reviewing code for repository '{repo_name}'.
Below are the deterministic SAST findings identified by automated static analysis tools (Semgrep, Gitleaks, OSV):

SAST Findings Detected:
{findings_text}

Code Diff Snippet:
{diff_snippet}

Tasks:
1. Write a concise 2-sentence executive summary of the repository's security posture and code quality.
2. For each finding listed above, provide a 1-sentence remediation recommendation.

Respond with a JSON object:
{{
  "summary": "Executive summary text here...",
  "remediations": [
    {{"index": 1, "remediation": "Remediation fix code or advice..."}}
  ]
}}
"""

        try:
            url = f"{self.host}/api/generate"
            payload = {
                "model": self.model,
                "prompt": prompt,
                "stream": False,
                "format": "json"
            }
            res = requests.post(url, json=payload, timeout=45)

            if res.status_code == 200:
                resp_json = res.json().get("response", "{}")
                parsed = json.loads(resp_json)

                summary = parsed.get("summary", f"Automated code review completed for {repo_name} with {len(findings)} findings.")
                remediations = parsed.get("remediations", [])

                # Apply remediations back to findings
                if isinstance(remediations, list):
                    for rem_item in remediations:
                        try:
                            idx = int(rem_item.get("index", 0)) - 1
                            if 0 <= idx < len(findings):
                                findings[idx].remediation = rem_item.get("remediation")
                        except (ValueError, TypeError):
                            pass

                return {
                    "summary": summary,
                    "enriched_findings": findings
                }
        except Exception as e:
            logger.warning(f"Ollama AI enrichment skipped gracefully ({e}). Returning empirical SAST findings.")

        # Fallback if Ollama query fails/times out
        default_summary = f"Automated code review completed for {repo_name}. Identified {len(findings)} findings across static analysis tools."
        return {
            "summary": default_summary,
            "enriched_findings": findings
        }
