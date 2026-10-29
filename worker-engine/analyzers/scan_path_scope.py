"""Helpers for scoping language scanners to changed files."""

from __future__ import annotations

from typing import Iterable, List, Optional, Sequence

LANGUAGE_SCOPED_TOOLS = frozenset({"ruff", "bandit", "eslint", "phpstan"})

_EXTENSIONS = {
    "ruff": (".py",),
    "bandit": (".py",),
    "eslint": (".js", ".jsx", ".ts", ".tsx", ".mjs", ".cjs"),
    "phpstan": (".php",),
}


def paths_for_tool(tool_name: str, changed_files: Optional[Sequence[str]]) -> Optional[List[str]]:
    """
    Return path allow-list for a language scanner, or None for a full-repo scan.

    None changed_files → full scan (caller should pass None when diff is unavailable).
    Empty after extension filter → empty list (caller should skip / return no findings).
    """
    if changed_files is None:
        return None
    if tool_name not in LANGUAGE_SCOPED_TOOLS:
        return None

    exts = _EXTENSIONS.get(tool_name, ())
    scoped = [
        path.replace("\\", "/")
        for path in changed_files
        if path and any(path.lower().endswith(ext) for ext in exts)
    ]
    return scoped


def existing_workspace_paths(workspace_dir: str, paths: Iterable[str]) -> List[str]:
    """Keep only paths that exist under the workspace (as files)."""
    import os

    kept: List[str] = []
    for rel in paths:
        full = os.path.join(workspace_dir, rel)
        if os.path.isfile(full):
            kept.append(rel)
    return kept
