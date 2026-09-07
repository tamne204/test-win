#!/usr/bin/env python3
"""
scripts/run_tests.py
Standard developer test profile runner for 2TOOLNE AutoEdit V2.
Supported profiles:
  fast        - Pure unit, component, and snapshot tests (< 2s)
  integration - Offline pipeline integration and draft normalization
  accuracy    - Accuracy benchmarks and adversarial tests
  full        - Complete automated test suite
  release     - Full gate + packaging + version integrity
"""
from __future__ import annotations

import argparse
import os
import subprocess
import sys
import time
from pathlib import Path

# Suite file groups
FAST_SUITES = [
    "tests/test_script_normalizer.py",
    "tests/test_subtitle_segmenter.py",
    "tests/test_script_aligner.py",
    "tests/test_srt_generator.py",
    "tests/test_a0_data_models.py",
    "tests/test_a0_anchor_finder.py",
    "tests/test_a0_collapse_detector.py",
    "tests/test_a0_hierarchical_aligner.py",
    "tests/test_a0_paragraph_mapping.py",
    "tests/test_dev_artifact_cache.py",
    "tests/test_golden_corpus.py",
    "tests/test_draft_normalizer.py",
    "tests/test_capcut_v2_core.py",
    "tests/test_visual_boundary_builder.py",
    "tests/test_visual_dp_planner.py",
    "tests/test_visual_image_and_tail.py",
    "tests/test_visual_motion_policy.py",
]

INTEGRATION_SUITES = [
    "tests/test_a0_pipeline_shadow.py",
    "tests/test_pipeline_replay.py",
    "tests/test_capcut_v2_beta.py",
    "tests/test_script_to_srt_pipeline.py",
    "tests/test_capcut_v2_desktop.py",
    "tests/test_client_diagnostics.py",
]

ACCURACY_SUITES = [
    "tests/test_a0_benchmark.py",
    "tests/test_a0_adversarial.py",
    "tests/test_forced_alignment.py",
]

RELEASE_ADDITIONAL_SUITES = [
    "tests/test_security_hardening.py",
    "tests/test_mandatory_license_gate.py",
    "tests/test_packaging_validation.py",
]


def run_pytest(files: list[str], extra_args: list[str] = None) -> int:
    repo_root = Path(__file__).resolve().parents[1]
    env = os.environ.copy()
    existing_pythonpath = env.get("PYTHONPATH", "")
    v2_path = str(repo_root / "apps" / "capcut-v2")
    root_path = str(repo_root)
    env["PYTHONPATH"] = f"{root_path}:{v2_path}:{existing_pythonpath}".strip(":")

    cmd = [sys.executable, "-m", "pytest"] + files + (extra_args or [])
    res = subprocess.run(cmd, cwd=str(repo_root), env=env)
    return res.returncode


def main() -> int:
    parser = argparse.ArgumentParser(description="2TOOLNE AutoEdit Test Profile Runner")
    parser.add_argument(
        "profile",
        choices=["fast", "integration", "accuracy", "full", "release"],
        default="fast",
        nargs="?",
        help="Test profile to execute (default: fast)",
    )
    parser.add_argument(
        "-v", "--verbose", action="store_true", help="Verbose pytest output"
    )
    parser.add_argument(
        "-k", "--keyword", type=str, default=None, help="Pytest -k filter"
    )

    args = parser.parse_args()

    extra_args = []
    if args.verbose:
        extra_args.append("-v")
    if args.keyword:
        extra_args.extend(["-k", args.keyword])

    print("=" * 70)
    print(f"2TOOLNE AUTOEDIT TEST RUNNER — Profile: [{args.profile.upper()}]")
    print("=" * 70)

    t0 = time.perf_counter()

    if args.profile == "fast":
        target_files = FAST_SUITES
    elif args.profile == "integration":
        target_files = INTEGRATION_SUITES
    elif args.profile == "accuracy":
        target_files = ACCURACY_SUITES
    elif args.profile == "full":
        target_files = FAST_SUITES + INTEGRATION_SUITES + ACCURACY_SUITES
    elif args.profile == "release":
        target_files = FAST_SUITES + INTEGRATION_SUITES + ACCURACY_SUITES + RELEASE_ADDITIONAL_SUITES

    rc = run_pytest(target_files, extra_args)

    duration = time.perf_counter() - t0
    print("=" * 70)
    status_str = "SUCCESS" if rc == 0 else "FAILED"
    print(f"PROFILE [{args.profile.upper()}] COMPLETED IN {duration:.2f}s — {status_str}")
    print("=" * 70)

    return rc


if __name__ == "__main__":
    sys.exit(main())
