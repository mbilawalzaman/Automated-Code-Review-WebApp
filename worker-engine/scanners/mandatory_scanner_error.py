class MandatoryScannerMissingError(Exception):
    """Raised when a mandatory scanner binary is not available on PATH."""

    def __init__(self, tool_name: str):
        self.tool_name = tool_name
        super().__init__(
            f"Mandatory scanner '{tool_name}' is not installed or not available on PATH."
        )
