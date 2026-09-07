"""
apps/capcut-v2/core/dev_cache/cli.py
Command-line interface for inspecting and managing the AutoEdit V2 dev cache.
"""
from __future__ import annotations

import argparse
import json
import sys
from .artifact_cache import ArtifactCache
from .cache_keys import CacheStage


def main() -> int:
    parser = argparse.ArgumentParser(description="AutoEdit V2 Developer Cache Tool")
    parser.add_argument("--stats", action="store_true", help="Print cache statistics")
    parser.add_argument("--clear-all", action="store_true", help="Wipe entire developer cache")
    parser.add_argument("--clear-stage", type=str, choices=[s.value for s in CacheStage], help="Wipe a single cache stage")
    parser.add_argument("--clear-project", type=str, help="Wipe cache entries tagged with project ID")
    parser.add_argument("--cache-dir", type=str, default=None, help="Explicit cache directory")

    args = parser.parse_args()
    cache = ArtifactCache(cache_dir=args.cache_dir)

    if args.clear_all:
        count = cache.clear_all()
        print(f"Cleared {count} cache items from dev cache.")
        return 0

    if args.clear_stage:
        count = cache.clear_stage(args.clear_stage)
        print(f"Cleared {count} cache items from stage "{args.clear_stage}".")
        return 0

    if args.clear_project:
        count = cache.clear_project(args.clear_project)
        print(f"Cleared {count} cache items for project "{args.clear_project}".")
        return 0

    # Default to printing stats
    stats = cache.get_stats()
    print(json.dumps(stats, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
