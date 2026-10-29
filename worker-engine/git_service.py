import os
import shutil
import tempfile
import uuid
import subprocess
import logging
from typing import Optional, List
from urllib.parse import urlparse

from config import GITHUB_WEB_BASE
from github_app_token import get_github_token, clear_cached_github_token

logger = logging.getLogger("GitService")


class CloneFailureError(Exception):
    """Raised when the worker cannot clone or check out the target revision."""

    pass


class GitService:
    def __init__(self, base_workspace_dir: Optional[str] = None):
        self.base_dir = base_workspace_dir or os.path.join(
            tempfile.gettempdir(), "scanner-workspaces"
        )
        os.makedirs(self.base_dir, exist_ok=True)

    def prepare_workspace(
        self,
        clone_url: Optional[str] = None,
        commit_sha: Optional[str] = None,
        pull_request_number: Optional[int] = None,
        branch_name: Optional[str] = None,
        base_branch: Optional[str] = None,
    ) -> str:
        """
        Build a workspace checked out at the exact job commit.

        Shallow `git clone --depth N` implies `--single-branch`, so PR head
        commits on unmerged branches are missing and checkout fails with
        "reference is not a tree". We instead init + fetch the specific ref
        (PR head, branch tip, or SHA) and refuse to scan default HEAD when the
        requested commit cannot be checked out.
        """
        if not clone_url:
            raise CloneFailureError(
                "Repository clone_url is missing from the job payload. "
                "Cannot scan without cloning the repository."
            )

        requested_sha = (commit_sha or "").strip()
        if requested_sha in ("", "HEAD"):
            requested_sha = None

        job_uuid = str(uuid.uuid4())[:8]
        workspace_dir = os.path.join(self.base_dir, f"job_{job_uuid}")
        os.makedirs(workspace_dir, exist_ok=True)

        logger.info(f"Created scanner workspace directory: {workspace_dir}")

        authenticated_url = self._authenticate_url(clone_url)
        log_url = clone_url

        try:
            logger.info(f"Fetching repository {log_url} into workspace...")
            self._run(
                ["git", "init"],
                cwd=workspace_dir,
                error_prefix=f"git init failed for {log_url}",
            )
            self._run(
                ["git", "remote", "add", "origin", authenticated_url],
                cwd=workspace_dir,
                error_prefix=f"git remote add failed for {log_url}",
            )

            fetched = self._fetch_target_refs(
                workspace_dir=workspace_dir,
                pull_request_number=pull_request_number,
                branch_name=branch_name,
                commit_sha=requested_sha,
                base_branch=base_branch,
            )

            if not fetched:
                # Installation tokens expire ~1h; refresh once and retry fetches.
                logger.warning(
                    "Git fetch produced no refs; refreshing GitHub token and retrying once."
                )
                clear_cached_github_token()
                authenticated_url = self._authenticate_url(clone_url, force_refresh=True)
                self._run(
                    ["git", "remote", "set-url", "origin", authenticated_url],
                    cwd=workspace_dir,
                    error_prefix=f"git remote set-url failed for {log_url}",
                )
                fetched = self._fetch_target_refs(
                    workspace_dir=workspace_dir,
                    pull_request_number=pull_request_number,
                    branch_name=branch_name,
                    commit_sha=requested_sha,
                    base_branch=base_branch,
                )

            if not fetched:
                raise CloneFailureError(
                    f"Unable to fetch any refs for {log_url} "
                    f"(pr={pull_request_number}, branch={branch_name}, sha={requested_sha})."
                )

            checkout_target = requested_sha or "FETCH_HEAD"
            checkout = self._run(
                ["git", "checkout", "--force", checkout_target],
                cwd=workspace_dir,
                check=False,
            )
            if checkout.returncode != 0:
                raise CloneFailureError(
                    f"Checkout of {checkout_target} failed: "
                    f"{checkout.stderr.strip() or checkout.stdout.strip() or 'unknown error'}"
                )

            if requested_sha:
                self._assert_checked_out_sha(workspace_dir, requested_sha)

        except CloneFailureError:
            self.cleanup(workspace_dir)
            raise
        except Exception as e:
            self.cleanup(workspace_dir)
            raise CloneFailureError(f"Error during git fetch/checkout: {e}") from e

        if not os.path.exists(os.path.join(workspace_dir, ".git")):
            self.cleanup(workspace_dir)
            raise CloneFailureError(
                f"Clone completed but workspace is not a git repository: {workspace_dir}"
            )

        return workspace_dir

    def get_commit_diff(
        self,
        workspace_dir: str,
        base_sha: Optional[str] = None,
        head_sha: str = "HEAD",
        base_branch: Optional[str] = None,
    ) -> str:
        """
        Prefer the merge-base diff against the repository default/base branch
        so PR reviews describe the actual change set, not HEAD~1 on whatever
        tip happened to be checked out.
        """
        if not os.path.exists(os.path.join(workspace_dir, ".git")):
            return "Diff unavailable: workspace does not contain a git repository."

        try:
            left = base_sha
            if not left and base_branch:
                left = self._resolve_base_ref(workspace_dir, base_branch)

            if left:
                merge_base = self._run(
                    ["git", "merge-base", left, head_sha],
                    cwd=workspace_dir,
                    check=False,
                )
                if merge_base.returncode == 0 and merge_base.stdout.strip():
                    left = merge_base.stdout.strip()

                diff = self._run(
                    ["git", "diff", f"{left}...{head_sha}"],
                    cwd=workspace_dir,
                    check=False,
                )
                if diff.returncode == 0:
                    return diff.stdout or ""

            # Fallback for push of a single commit / root commit
            cmd = ["git", "diff", f"{base_sha or 'HEAD~1'}..{head_sha}"]
            res = subprocess.run(
                cmd, cwd=workspace_dir, capture_output=True, text=True, timeout=30
            )
            if res.returncode == 0:
                return res.stdout or ""

            cmd_root = ["git", "show", "--stat", head_sha]
            res_root = subprocess.run(
                cmd_root, cwd=workspace_dir, capture_output=True, text=True, timeout=30
            )
            return res_root.stdout or ""
        except Exception as e:
            logger.warning(f"Failed to collect commit diff: {e}")
            return f"Diff unavailable: {e}"

    def list_changed_files(
        self,
        workspace_dir: str,
        base_branch: Optional[str] = None,
        head_sha: str = "HEAD",
        for_pull_request: bool = False,
    ) -> Optional[List[str]]:
        """
        Return repo-relative changed paths for language-scanner scoping.

        Returns:
          - list[str] (possibly empty) when scope was determined successfully
          - None only when git could not determine scope (caller may full-repo fall back)

        Empty list must NOT be treated as "unavailable": tip-of-branch Review Now
        often has an empty merge-base diff vs default branch; we then use the
        commit's own changed files instead of scanning the entire repository.
        """
        if not os.path.exists(os.path.join(workspace_dir, ".git")):
            return None

        files: List[str] = []
        try:
            if for_pull_request and base_branch:
                files = self._name_only_against_base(workspace_dir, base_branch, head_sha)

            # Push / Review Now / empty PR merge-base → files touched by HEAD commit.
            if not files:
                files = self._name_only_commit_paths(workspace_dir, head_sha)

            return sorted(set(files))
        except Exception as exc:
            logger.warning(f"Failed to resolve changed-file scope: {exc}")
            return None

    def _name_only_against_base(
        self,
        workspace_dir: str,
        base_branch: str,
        head_sha: str,
    ) -> List[str]:
        left = self._resolve_base_ref(workspace_dir, base_branch)
        if not left:
            return []

        merge_base = self._run(
            ["git", "merge-base", left, head_sha],
            cwd=workspace_dir,
            check=False,
        )
        if merge_base.returncode == 0 and merge_base.stdout.strip():
            left = merge_base.stdout.strip()

        # Tip-of-base reviews produce an empty range; caller falls back to commit paths.
        if left == self._rev_parse(workspace_dir, head_sha):
            return []

        diff = self._run(
            ["git", "diff", "--name-only", f"{left}...{head_sha}"],
            cwd=workspace_dir,
            check=False,
        )
        if diff.returncode != 0:
            return []
        return self._parse_name_only(diff.stdout)

    def _name_only_commit_paths(self, workspace_dir: str, head_sha: str) -> List[str]:
        commands = [
            ["git", "diff-tree", "--no-commit-id", "--name-only", "-r", head_sha],
            ["git", "diff", "--name-only", f"{head_sha}~1", head_sha],
            ["git", "show", "--name-only", "--pretty=format:", head_sha],
        ]
        for cmd in commands:
            res = self._run(cmd, cwd=workspace_dir, check=False)
            if res.returncode != 0:
                continue
            paths = self._parse_name_only(res.stdout)
            if paths:
                return paths
        # Commands succeeded but commit has no file changes (empty commit).
        return []

    def _rev_parse(self, workspace_dir: str, ref: str) -> Optional[str]:
        res = self._run(
            ["git", "rev-parse", "--verify", ref],
            cwd=workspace_dir,
            check=False,
        )
        if res.returncode != 0:
            return None
        return (res.stdout or "").strip() or None

    @staticmethod
    def _parse_name_only(output: Optional[str]) -> List[str]:
        paths: List[str] = []
        for line in (output or "").splitlines():
            path = line.strip().replace("\\", "/")
            if path and path != "/dev/null" and not path.startswith("commit "):
                paths.append(path)
        return paths

    def cleanup(self, workspace_dir: Optional[str]) -> None:
        if workspace_dir and os.path.exists(workspace_dir):
            try:
                shutil.rmtree(workspace_dir, ignore_errors=True)
                logger.info(f"Cleaned up scanner workspace: {workspace_dir}")
            except OSError as e:
                logger.warning(f"Failed to cleanup workspace {workspace_dir}: {e}")

        clone_sibling = f"{workspace_dir}_clone" if workspace_dir else None
        if clone_sibling and os.path.exists(clone_sibling):
            shutil.rmtree(clone_sibling, ignore_errors=True)

    def _authenticate_url(self, clone_url: str, force_refresh: bool = False) -> str:
        if "x-access-token" in clone_url or not clone_url.startswith("https://"):
            return clone_url

        host = urlparse(GITHUB_WEB_BASE if "://" in GITHUB_WEB_BASE else f"https://{GITHUB_WEB_BASE}").netloc
        host = host or "github.com"
        if host not in clone_url and "github.com" not in clone_url:
            return clone_url

        try:
            token = get_github_token(force_refresh=force_refresh)
        except Exception as exc:
            logger.warning("Unable to resolve GitHub clone token: %s", exc)
            return clone_url

        if not token:
            return clone_url

        # Match either github.com or GHE host from GITHUB_WEB_BASE.
        for needle in {f"https://{host}", "https://github.com"}:
            if clone_url.startswith(needle):
                return clone_url.replace(
                    needle,
                    f"https://x-access-token:{token}@{urlparse(needle).netloc}",
                    1,
                )
        return clone_url

    def _fetch_target_refs(
        self,
        workspace_dir: str,
        pull_request_number: Optional[int],
        branch_name: Optional[str],
        commit_sha: Optional[str],
        base_branch: Optional[str],
    ) -> bool:
        """Attempt the most specific fetches first. Returns True if any succeeded."""
        fetch_specs: List[List[str]] = []

        if pull_request_number:
            pr = int(pull_request_number)
            fetch_specs.append(
                [
                    "git",
                    "fetch",
                    "--depth",
                    "50",
                    "origin",
                    f"+refs/pull/{pr}/head:refs/remotes/origin/pr/{pr}",
                ]
            )

        if branch_name:
            safe_branch = branch_name.strip()
            if safe_branch and safe_branch not in (".", "..") and "/" != safe_branch[0]:
                fetch_specs.append(
                    [
                        "git",
                        "fetch",
                        "--depth",
                        "50",
                        "origin",
                        f"+refs/heads/{safe_branch}:refs/remotes/origin/{safe_branch}",
                    ]
                )

        if commit_sha:
            # GitHub (and most hosts) allow fetching a commit SHA directly.
            fetch_specs.append(
                ["git", "fetch", "--depth", "50", "origin", commit_sha]
            )

        # Always try the repo default branch so merge-base diffs work.
        default_branch = (base_branch or "main").strip() or "main"
        fetch_specs.append(
            [
                "git",
                "fetch",
                "--depth",
                "50",
                "origin",
                f"+refs/heads/{default_branch}:refs/remotes/origin/{default_branch}",
            ]
        )

        any_ok = False
        for cmd in fetch_specs:
            result = self._run(cmd, cwd=workspace_dir, check=False, timeout=120)
            if result.returncode == 0:
                any_ok = True
            else:
                logger.info(
                    "Fetch attempt failed (%s): %s",
                    " ".join(cmd[4:]),
                    (result.stderr or result.stdout or "").strip()[:300],
                )

        return any_ok

    def _assert_checked_out_sha(self, workspace_dir: str, requested_sha: str) -> None:
        head = self._run(
            ["git", "rev-parse", "HEAD"],
            cwd=workspace_dir,
            error_prefix="Unable to resolve checked-out HEAD",
        )
        actual = head.stdout.strip()
        requested = requested_sha.strip().lower()
        actual_l = actual.lower()

        if not (
            actual_l == requested
            or actual_l.startswith(requested)
            or requested.startswith(actual_l)
        ):
            raise CloneFailureError(
                f"Checked out {actual} but job requested {requested_sha}. "
                "Refusing to scan the wrong revision."
            )

        logger.info(f"Verified checkout at {actual[:12]} matches requested commit.")

    def _resolve_base_ref(self, workspace_dir: str, base_branch: str) -> Optional[str]:
        candidates = [
            f"refs/remotes/origin/{base_branch}",
            f"origin/{base_branch}",
            base_branch,
        ]
        for ref in candidates:
            probe = self._run(
                ["git", "rev-parse", "--verify", ref],
                cwd=workspace_dir,
                check=False,
            )
            if probe.returncode == 0 and probe.stdout.strip():
                return probe.stdout.strip()
        return None

    def _run(
        self,
        cmd: List[str],
        cwd: str,
        check: bool = True,
        timeout: int = 60,
        error_prefix: Optional[str] = None,
    ) -> subprocess.CompletedProcess:
        result = subprocess.run(
            cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout
        )
        if check and result.returncode != 0:
            detail = result.stderr.strip() or result.stdout.strip() or "unknown error"
            prefix = error_prefix or f"Command failed: {' '.join(cmd)}"
            raise CloneFailureError(f"{prefix}: {detail}")
        return result
