"""
apps/capcut-v2/adapters/capcut/ownership_manager.py
CapCut Control Ownership Manager per Section 32 of CEO Master Directive.
Maintains mutual exclusion between human editing and automated rendering.
"""
from __future__ import annotations

import threading
from typing import Optional, Callable, List

OWNER_NONE = "NONE"
OWNER_MANUAL = "MANUAL"
OWNER_RENDER_QUEUE = "RENDER_QUEUE"


class CapCutOwnershipManager:
    """
    Manages exclusive control ownership of the CapCut Desktop instance.
    """

    _lock = threading.Lock()
    _current_owner: str = OWNER_NONE
    _active_job_id: Optional[str] = None
    _listeners: List[Callable[[str, str], None]] = []

    @classmethod
    def get_owner(cls) -> str:
        with cls._lock:
            return cls._current_owner

    @classmethod
    def get_active_job_id(cls) -> Optional[str]:
        with cls._lock:
            return cls._active_job_id

    @classmethod
    def acquire_for_queue(cls, job_id: str) -> bool:
        """
        Attempt to acquire ownership for an automated render queue job.
        Fails if user currently has manual ownership.
        """
        with cls._lock:
            if cls._current_owner == OWNER_MANUAL:
                return False
            old_owner = cls._current_owner
            cls._current_owner = OWNER_RENDER_QUEUE
            cls._active_job_id = job_id

        cls._notify(old_owner, OWNER_RENDER_QUEUE)
        return True

    @classmethod
    def set_manual_owner(cls) -> None:
        """
        Mark that human user has taken focus or opened CapCut directly.
        """
        with cls._lock:
            old_owner = cls._current_owner
            cls._current_owner = OWNER_MANUAL
            cls._active_job_id = None

        cls._notify(old_owner, OWNER_MANUAL)

    @classmethod
    def release(cls, job_id: Optional[str] = None) -> None:
        """
        Release ownership back to NONE if matching active job or force release.
        """
        with cls._lock:
            if job_id and cls._active_job_id != job_id:
                return
            old_owner = cls._current_owner
            cls._current_owner = OWNER_NONE
            cls._active_job_id = None

        cls._notify(old_owner, OWNER_NONE)

    @classmethod
    def add_listener(cls, listener: Callable[[str, str], None]) -> None:
        with cls._lock:
            cls._listeners.append(listener)

    @classmethod
    def _notify(cls, old_owner: str, new_owner: str) -> None:
        if old_owner == new_owner:
            return
        for l in list(cls._listeners):
            try:
                l(old_owner, new_owner)
            except Exception:
                pass
