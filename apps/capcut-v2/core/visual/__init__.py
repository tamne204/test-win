"""
apps/capcut-v2/core/visual/__init__.py
Phase A1 Visual Shot Planning & Duration-Aware Motion subsystem.
"""
from .models import (
    VisualBoundaryType,
    VisualBoundaryCandidate,
    VisualShot,
    ShotDurationPolicy,
    TailDurationPolicy,
    ImageSupplyState,
    VisualPlannerEngine,
    VisualPlannerOptions,
)
from .boundary_builder import VisualBoundaryCandidateBuilder
from .dp_planner import VisualShotPlanner, PlannedShotInterval, VisualShotBoundaryPath
from .image_allocator import ImageAllocationPolicy, AllocationResult, ProjectValidationError
from .tail_allocator import SilentTailAllocator
from .motion_policy import DurationAwareMotionPolicy

__all__ = [
    "VisualBoundaryType",
    "VisualBoundaryCandidate",
    "VisualShot",
    "ShotDurationPolicy",
    "TailDurationPolicy",
    "ImageSupplyState",
    "VisualPlannerEngine",
    "VisualPlannerOptions",
    "VisualBoundaryCandidateBuilder",
    "VisualShotPlanner",
    "PlannedShotInterval",
    "VisualShotBoundaryPath",
    "ImageAllocationPolicy",
    "AllocationResult",
    "ProjectValidationError",
    "SilentTailAllocator",
    "DurationAwareMotionPolicy",
]
