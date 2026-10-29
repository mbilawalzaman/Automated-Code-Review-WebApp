import subprocess
import logging
from typing import Dict

logger = logging.getLogger("WorkerInfo")

class WorkerInfo:
    def __init__(self):
        self._versions: Dict[str, str] = {}
        self.refresh_versions()

    def refresh_versions(self):
        self._versions["gitleaks"] = self._get_command_version(["gitleaks", "version"])
        self._versions["semgrep"] = self._get_command_version(["semgrep", "--version"])
        self._versions["osv-scanner"] = self._get_command_version(["osv-scanner", "--version"])
        self._versions["phpstan"] = self._get_command_version(["phpstan", "--version"])
        self._versions["ruff"] = self._get_command_version(["ruff", "--version"])
        self._versions["bandit"] = self._get_command_version(["bandit", "--version"])
        self._versions["eslint"] = self._get_command_version(["npx", "eslint", "--version"])
        self._versions["playwright"] = self._get_command_version(["npx", "playwright", "--version"])
        self._versions["pytest"] = self._get_command_version(["pytest", "--version"])
        self._versions["phpunit"] = self._get_command_version(["phpunit", "--version"])

    def _get_command_version(self, cmd_list: list) -> str:
        try:
            res = subprocess.run(cmd_list, capture_output=True, text=True, timeout=5)
            if res.returncode == 0:
                output = (res.stdout or res.stderr).strip().split("\n")[0]
                return output.replace("v", "").strip() or "1.0.0"
            return "1.0.0"
        except FileNotFoundError:
            logger.debug(f"Command {' '.join(cmd_list)} not installed on PATH.")
            return "not_installed"
        except Exception as e:
            logger.warning(f"Error fetching version for {' '.join(cmd_list)}: {e}")
            return "1.0.0"

    def get_version(self, tool_name: str) -> str:
        return self._versions.get(tool_name.lower(), "1.0.0")

    def to_dict(self) -> Dict[str, str]:
        return self._versions.copy()
