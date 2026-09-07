"""
apps/capcut-v2/adapters/capcut/registry.py
CapCut Adapter Registry and Version Router.
Ensures that version adapters are strictly mapped to verified CapCut versions
and prevents silent fallback to unverified schemas.
"""
from __future__ import annotations

import re
from typing import Dict, Type, Optional, Tuple, Any

from .version_9_3 import CapCutVersionAdapter_9_3

# Status Constants
STATUS_VERIFIED = "CAPCUT_VERSION_SUPPORTED"
STATUS_UNTESTED = "CAPCUT_VERSION_UNTESTED"
STATUS_UNSUPPORTED = "CAPCUT_VERSION_UNSUPPORTED"


class CapCutAdapterRegistry:
    """
    Registry for resolving detected CapCut versions to compatible adapter classes.
    """

    # Explicit map of verified version regex patterns to adapter classes
    _VERIFIED_REGISTRY: Dict[str, Type] = {
        r"^9\.[3-4](\.\d+)?$": CapCutVersionAdapter_9_3,
    }

    # Explicit map of known broken / unsupported version patterns
    _UNSUPPORTED_PATTERNS = [
        r"^[1-7]\.",  # Versions prior to 8.x
    ]

    @classmethod
    def resolve_adapter(
        cls,
        version_str: Optional[str],
        allow_untested: bool = False,
    ) -> Tuple[Optional[Type], str, str]:
        """
        Resolve a version string to (AdapterClass, status_code, diagnostic_message).

        Args:
            version_str: Detected CapCut version (e.g. "9.3.0").
            allow_untested: If True, allows candidate adapter for untested versions in developer mode.

        Returns:
            Tuple of (AdapterClass or None, status_code, diagnostic_message)
        """
        if not version_str or version_str == "Unknown":
            if allow_untested:
                return (
                    CapCutVersionAdapter_9_3,
                    STATUS_UNTESTED,
                    "Unknown CapCut version; fallback candidate permitted in developer mode.",
                )
            return (
                None,
                STATUS_UNTESTED,
                "CapCut version is unknown. Automatic project registration blocked for safety.",
            )

        # 1. Check verified matches
        for pattern, adapter_cls in cls._VERIFIED_REGISTRY.items():
            if re.match(pattern, version_str):
                return (
                    adapter_cls,
                    STATUS_VERIFIED,
                    f"CapCut {version_str} is VERIFIED with {adapter_cls.__name__}.",
                )

        # 2. Check explicitly unsupported matches
        for pattern in cls._UNSUPPORTED_PATTERNS:
            if re.match(pattern, version_str):
                return (
                    None,
                    STATUS_UNSUPPORTED,
                    f"CapCut {version_str} is known to be UNSUPPORTED.",
                )

        # 3. Handle untested version (e.g. 9.4, 8.7, 10.x)
        if allow_untested:
            # Candidate fallback in dev override
            return (
                CapCutVersionAdapter_9_3,
                STATUS_UNTESTED,
                f"CapCut {version_str} is UNTESTED. Using candidate adapter {CapCutVersionAdapter_9_3.__name__} (Override mode).",
            )
        else:
            return (
                None,
                STATUS_UNTESTED,
                f"CapCut {version_str} has not been verified yet. Automatic draft write blocked to prevent corruption.",
            )
