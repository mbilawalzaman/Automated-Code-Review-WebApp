from abc import ABC, abstractmethod
from typing import List, Optional
from models.finding import NormalizedFinding

class BaseScanner(ABC):
    def __init__(self, tool_version: str = "1.0.0"):
        self.tool_version = tool_version

    @property
    @abstractmethod
    def tool_name(self) -> str:
        pass

    @abstractmethod
    def scan(self, workspace_dir: str, paths: Optional[List[str]] = None) -> List[NormalizedFinding]:
        """
        Run the scanner.

        paths: optional allow-list of repo-relative paths (changed files). When
        provided for language/style scanners, only those paths are analyzed.
        """
        pass
