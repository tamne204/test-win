"""
apps/capcut-v2/core/dev_cache/artifact_cache.py
Robust, content-addressed, disposable artifact cache for AutoEdit V2.
Stores intermediate artifacts (ASR, Alignment, Subtitle, VisualShot, EditPlan, Draft).
Corrupt entries produce an immediate MISS and safe self-eviction.
"""
from __future__ import annotations

import json
import logging
import os
import shutil
import tempfile
import time
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Union

from .cache_keys import CacheStage, compute_content_hash

logger = logging.getLogger("autoedit.dev_cache")

FORBIDDEN_CACHE_PATHS = [
    "projects_capcut",
    "projects",
    "uploads",
    "license.json",
    "dist",
]


class ArtifactCache:
    """
    Content-addressed developer cache manager.
    """

    def __init__(self, cache_dir: Optional[Union[str, Path]] = None):
        if cache_dir is None:
            cache_dir = os.environ.get("AUTOEDIT_DEV_CACHE_DIR")
        if cache_dir is None:
            # Default to repo_root/.dev_cache
            repo_root = Path(__file__).resolve().parents[4]
            cache_dir = repo_root / ".dev_cache"

        self.root_dir = Path(cache_dir).resolve()
        self._validate_cache_root()
        self.root_dir.mkdir(parents=True, exist_ok=True)

        # In-memory telemetry counters
        self.stats = {
            "hits": 0,
            "misses": 0,
            "writes": 0,
            "corruptions_evicted": 0,
        }

    def _validate_cache_root(self) -> None:
        """Ensure dev cache is NEVER located in sensitive production dirs."""
        root_str = str(self.root_dir)
        for forbidden in FORBIDDEN_CACHE_PATHS:
            if forbidden in root_str and not root_str.endswith(".dev_cache"):
                raise ValueError(f"Safety Violation: Dev cache cannot be in production directory: {root_str}")

    def _get_entry_dir(self, stage: Union[CacheStage, str], key: str) -> Path:
        stage_name = stage.value if isinstance(stage, CacheStage) else str(stage)
        prefix = key[:2] if len(key) >= 2 else "00"
        return self.root_dir / stage_name / prefix

    def _get_entry_paths(self, stage: Union[CacheStage, str], key: str) -> tuple[Path, Path]:
        entry_dir = self._get_entry_dir(stage, key)
        data_path = entry_dir / f"{key}.json"
        meta_path = entry_dir / f"{key}.meta.json"
        return data_path, meta_path

    def has(self, stage: Union[CacheStage, str], key: str) -> bool:
        data_path, meta_path = self._get_entry_paths(stage, key)
        return data_path.is_file() and meta_path.is_file()

    def get(
        self,
        stage: Union[CacheStage, str],
        key: str,
        validator: Optional[Callable[[Any], bool]] = None
    ) -> Optional[Any]:
        """
        Retrieve a cached artifact by key.
        Returns:
            The parsed JSON data if valid and intact.
            None on cache miss OR corrupted cache (corrupt entry is deleted).
        """
        data_path, meta_path = self._get_entry_paths(stage, key)
        if not data_path.is_file() or not meta_path.is_file():
            self.stats["misses"] += 1
            return None

        try:
            with open(meta_path, "r", encoding="utf-8") as f:
                meta = json.load(f)

            with open(data_path, "r", encoding="utf-8") as f:
                data = json.load(f)

            # Check checksum
            expected_hash = meta.get("payload_sha256")
            actual_hash = compute_content_hash(data)
            if expected_hash and actual_hash != expected_hash:
                logger.warning(f"Cache corruption detected for key {key}. Evicting.")
                self._evict_files(data_path, meta_path)
                self.stats["corruptions_evicted"] += 1
                self.stats["misses"] += 1
                return None

            # Optional custom validator
            if validator and not validator(data):
                logger.warning(f"Cache validator rejected entry for key {key}. Evicting.")
                self._evict_files(data_path, meta_path)
                self.stats["misses"] += 1
                return None

            self.stats["hits"] += 1
            return data

        except Exception as err:
            logger.warning(f"Error reading cache for key {key}: {err}. Evicting corrupted files.")
            self._evict_files(data_path, meta_path)
            self.stats["corruptions_evicted"] += 1
            self.stats["misses"] += 1
            return None

    def put(
        self,
        stage: Union[CacheStage, str],
        key: str,
        data: Any,
        metadata: Optional[Dict[str, Any]] = None
    ) -> str:
        """
        Store an artifact atomically.
        Returns the SHA-256 hash of the stored data payload.
        """
        stage_name = stage.value if isinstance(stage, CacheStage) else str(stage)
        data_path, meta_path = self._get_entry_paths(stage, key)
        entry_dir = data_path.parent
        entry_dir.mkdir(parents=True, exist_ok=True)

        payload_hash = compute_content_hash(data)

        meta_dict = {
            "key": key,
            "stage": stage_name,
            "payload_sha256": payload_hash,
            "created_at": time.time(),
            "created_iso": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "custom": metadata or {}
        }

        # Write atomically via temporary files
        tmp_data = tempfile.NamedTemporaryFile(mode="w", dir=str(entry_dir), delete=False, encoding="utf-8")
        tmp_meta = tempfile.NamedTemporaryFile(mode="w", dir=str(entry_dir), delete=False, encoding="utf-8")
        try:
            json.dump(data, tmp_data, ensure_ascii=False, indent=2)
            tmp_data.flush()
            tmp_data.close()

            json.dump(meta_dict, tmp_meta, ensure_ascii=False, indent=2)
            tmp_meta.flush()
            tmp_meta.close()

            os.replace(tmp_data.name, str(data_path))
            os.replace(tmp_meta.name, str(meta_path))
        except Exception:
            for p in (tmp_data.name, tmp_meta.name):
                if os.path.exists(p):
                    try:
                        os.remove(p)
                    except OSError:
                        pass
            raise

        self.stats["writes"] += 1
        return payload_hash

    def invalidate(self, stage: Union[CacheStage, str], key: str) -> bool:
        """Remove a specific cache entry."""
        data_path, meta_path = self._get_entry_paths(stage, key)
        return self._evict_files(data_path, meta_path)

    def _evict_files(self, data_path: Path, meta_path: Path) -> bool:
        evicted = False
        for p in (data_path, meta_path):
            if p.is_file():
                try:
                    p.unlink()
                    evicted = True
                except OSError:
                    pass
        return evicted

    def clear_stage(self, stage: Union[CacheStage, str]) -> int:
        """Delete all cache entries for a given stage."""
        stage_name = stage.value if isinstance(stage, CacheStage) else str(stage)
        stage_dir = self.root_dir / stage_name
        if not stage_dir.is_dir():
            return 0
        count = sum(1 for p in stage_dir.glob("**/*.json") if not p.name.endswith(".meta.json"))
        shutil.rmtree(stage_dir, ignore_errors=True)
        return count

    def clear_project(self, project_id: str) -> int:
        """Delete all cache entries tagged with the given project_id."""
        cleared = 0
        for meta_file in self.root_dir.glob("**/*.meta.json"):
            try:
                with open(meta_file, "r", encoding="utf-8") as f:
                    meta = json.load(f)
                custom = meta.get("custom", {})
                if custom.get("project_id") == project_id or custom.get("project_name") == project_id:
                    key = meta.get("key")
                    stage = meta.get("stage")
                    if key and stage:
                        self.invalidate(stage, key)
                        cleared += 1
            except Exception:
                continue
        return cleared

    def clear_all(self) -> int:
        """Safely wipe all cached entries in this dev cache."""
        if not self.root_dir.is_dir():
            return 0
        count = sum(1 for p in self.root_dir.glob("**/*.json") if not p.name.endswith(".meta.json"))
        for item in self.root_dir.iterdir():
            if item.is_dir():
                shutil.rmtree(item, ignore_errors=True)
            elif item.is_file():
                try:
                    item.unlink()
                except OSError:
                    pass
        return count

    def get_stats(self) -> Dict[str, Any]:
        """Return detailed cache storage and hit/miss statistics."""
        stage_stats = {}
        total_bytes = 0
        total_items = 0

        if self.root_dir.is_dir():
            for stage in CacheStage:
                s_dir = self.root_dir / stage.value
                if s_dir.is_dir():
                    items = list(s_dir.glob("**/*.json"))
                    data_items = [p for p in items if not p.name.endswith(".meta.json")]
                    size = sum(p.stat().st_size for p in items if p.is_file())
                    stage_stats[stage.value] = {
                        "count": len(data_items),
                        "bytes": size
                    }
                    total_bytes += size
                    total_items += len(data_items)
                else:
                    stage_stats[stage.value] = {"count": 0, "bytes": 0}

        return {
            "root_dir": str(self.root_dir),
            "total_items": total_items,
            "total_bytes": total_bytes,
            "stages": stage_stats,
            "session": self.stats
        }
