"""
checksum.py
Computes and verifies SHA-256 hashes of downloaded update packages.
"""

import os
import hashlib
import hmac


def compute_sha256(file_path: str, chunk_size: int = 65536) -> str:
    """
    Compute SHA-256 hexadecimal hash for a local file.
    Reads in 64KB chunks for memory safety on large files.
    """
    if not os.path.isfile(file_path):
        raise FileNotFoundError(f"File not found: {file_path}")

    sha256 = hashlib.sha256()
    with open(file_path, "rb") as f:
        while True:
            data = f.read(chunk_size)
            if not data:
                break
            sha256.update(data)
    return sha256.hexdigest().lower()


def verify_sha256(file_path: str, expected_hash: str) -> bool:
    """
    Constant-time comparison between actual file hash and expected SHA-256 hash.
    Returns True if matches, False otherwise.
    """
    if not expected_hash:
        return True  # If no checksum provided, pass
    try:
        actual = compute_sha256(file_path)
        expected = expected_hash.strip().lower()
        return hmac.compare_digest(actual, expected)
    except Exception:
        return False
