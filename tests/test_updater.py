"""
test_updater.py
Unit tests using standard Python unittest framework:
SemVer comparisons, platform detection, checksum calculation,
backup/rollback, and network failure resilience.
"""

import os
import sys
import unittest
import tempfile

# Add parent directory to sys.path
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from version import __version__
from updater.version_manager import (
    parse_version,
    compare_versions,
    is_newer_version,
    get_current_version,
    get_app_version
)
from updater.platform_detector import detect_os, detect_arch, get_platform_tag
from updater.checksum import compute_sha256, verify_sha256
from updater.rollback_manager import create_backup, restore_backup
from updater.github_release_client import fetch_latest_release
from updater.update_manager import UpdateManager, check_for_updates


class TestSemanticVersioning(unittest.TestCase):
    def test_version_parsing(self):
        self.assertEqual(parse_version("1.0.0"), (1, 0, 0))
        self.assertEqual(parse_version("v1.2.3"), (1, 2, 3))
        self.assertEqual(parse_version("V2.10.5"), (2, 10, 5))
        self.assertEqual(parse_version("2.0.0-beta.1"), (2, 0, 0, 1))
        self.assertEqual(parse_version("1"), (1, 0, 0))
        self.assertEqual(parse_version(""), (0, 0, 0))

    def test_version_comparison(self):
        # 1.0.0 < 1.1.0
        self.assertEqual(compare_versions("1.0.0", "1.1.0"), -1)
        # 1.1.0 > 1.0.9
        self.assertEqual(compare_versions("1.1.0", "1.0.9"), 1)
        # 1.2.0 == 1.2.0
        self.assertEqual(compare_versions("1.2.0", "1.2.0"), 0)
        # v-prefix equality
        self.assertEqual(compare_versions("v2.0.0", "2.0.0"), 0)
        self.assertEqual(compare_versions("2.1.0", "v2.0.9"), 1)
        self.assertEqual(compare_versions("1.0.0", "1.0.0.1"), -1)

    def test_is_newer_version(self):
        self.assertTrue(is_newer_version("2.1.0", "2.0.0"))
        self.assertFalse(is_newer_version("2.0.0", "2.0.0"))
        self.assertFalse(is_newer_version("1.9.9", "2.0.0"))
        self.assertTrue(is_newer_version("v3.0.0", "2.0.0"))

    def test_current_version_consistency(self):
        self.assertEqual(get_current_version(), __version__)
        self.assertEqual(get_app_version(), __version__)


class TestPlatformDetector(unittest.TestCase):
    def test_platform_detector(self):
        os_name = detect_os()
        self.assertIn(os_name, ("windows", "darwin", "linux"))

        arch = detect_arch()
        self.assertIn(arch, ("arm64", "x64"))

        tag = get_platform_tag()
        self.assertIn("-", tag)
        self.assertIn(tag, ("win-x64", "mac-arm64", "mac-x64", "linux-x64"))


class TestChecksum(unittest.TestCase):
    def test_sha256_computation_and_verification(self):
        with tempfile.NamedTemporaryFile("w", delete=False) as f:
            f.write("test content for sha256 checksum verification")
            fpath = f.name

        try:
            h = compute_sha256(fpath)
            self.assertEqual(len(h), 64)
            self.assertTrue(verify_sha256(fpath, h))
            self.assertFalse(verify_sha256(fpath, "invalid_hash_value"))
            self.assertTrue(verify_sha256(fpath, h.upper()))
        finally:
            if os.path.exists(fpath):
                os.remove(fpath)


class TestBackupAndRollback(unittest.TestCase):
    def test_backup_and_restore_rollback(self):
        with tempfile.TemporaryDirectory() as src_dir, tempfile.TemporaryDirectory() as bkp_dir:
            f1 = os.path.join(src_dir, "app.py")
            f2 = os.path.join(src_dir, "version.py")
            with open(f1, "w") as f:
                f.write("# original app.py v1.0")
            with open(f2, "w") as f:
                f.write('__version__ = "1.0.0"')

            zip_path = create_backup(src_dir, "1.0.0", bkp_dir)
            self.assertTrue(os.path.isfile(zip_path))

            # Simulate corrupted file update
            with open(f1, "w") as f:
                f.write("# CORRUPTED APP.PY")

            # Restore rollback
            ok = restore_backup(zip_path, src_dir)
            self.assertTrue(ok)

            with open(f1, "r") as f:
                self.assertEqual(f.read(), "# original app.py v1.0")


class TestNetworkResilience(unittest.TestCase):
    def test_github_client_unreachable_host(self):
        res = fetch_latest_release(repo="tamne204/ffmpeg-tool", proxy_url="http://192.0.2.1:9999/dummy", timeout=1)
        self.assertFalse(res["ok"])
        self.assertIn("error", res)

    def test_update_manager_offline_check(self):
        mgr = UpdateManager()
        res = mgr.check_for_updates()
        self.assertIn("update_available", res)
        self.assertIn("current_version", res)
        self.assertEqual(res["current_version"], __version__)


if __name__ == "__main__":
    unittest.main()
