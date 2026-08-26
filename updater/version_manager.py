"""
version_manager.py
Handles Semantic Versioning (SemVer) parsing and comparisons.
Supports formats: "1.0.0", "v1.2.3", "2.0.0-beta.1", "1.1.0.post1"
"""

import re
from typing import Tuple
from version import __version__, get_version as _get_v, get_app_name


def parse_version(v_str: str) -> Tuple[int, ...]:
    """
    Parse a version string into a comparable integer tuple.
    Strips leading 'v' / 'V' and non-numeric suffixes safely.
    Example: 'v1.2.3' -> (1, 2, 3), '2.0.0-rc1' -> (2, 0, 0)
    """
    if not v_str or not isinstance(v_str, str):
        return (0, 0, 0)
    
    clean_str = v_str.strip().lstrip("vV")
    num_parts = [int(x) for x in re.findall(r"\d+", clean_str)]

    while len(num_parts) < 3:
        num_parts.append(0)

    return tuple(num_parts)


def compare_versions(v1: str, v2: str) -> int:
    """
    Compare two semantic version strings.
    Returns:
       -1 if v1 < v2
        0 if v1 == v2
        1 if v1 > v2
    """
    t1 = parse_version(v1)
    t2 = parse_version(v2)

    # Pad with zeros to matching length
    max_len = max(len(t1), len(t2))
    t1_padded = t1 + (0,) * (max_len - len(t1))
    t2_padded = t2 + (0,) * (max_len - len(t2))

    if t1_padded < t2_padded:
        return -1
    elif t1_padded > t2_padded:
        return 1
    return 0


def is_newer_version(candidate_version: str, current_version: str = None) -> bool:
    """
    Return True if candidate_version is strictly newer than current_version.
    If current_version is None, uses app's current version from version.py.
    """
    if current_version is None:
        current_version = __version__
    return compare_versions(candidate_version, current_version) > 0


def get_current_version() -> str:
    """Return the active version string from version.py."""
    return _get_v()


def get_app_version() -> str:
    """Alias for get_current_version()."""
    return get_current_version()
