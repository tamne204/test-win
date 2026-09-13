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
    FrameQuantizationPolicy,
    rational_fps_from_float,
)
from .boundary_builder import VisualBoundaryCandidateBuilder
from .dp_planner import VisualShotPlanner, PlannedShotInterval, VisualShotBoundaryPath
from .image_allocator import ImageAllocationPolicy, AllocationResult, ProjectValidationError
from .tail_allocator import SilentTailAllocator
from .motion_policy import DurationAwareMotionPolicy
from .quantization import (
    FrameTimebase,
    FrameQuantizer,
    FrameAccuracyValidator,
    FrameValidationReport,
    FrameValidationIssue,
    BoundaryQuantizationRecord,
    QuantizationDiagnostics,
)
from .validator import (
    VisualAccuracyValidator,
    ValidationSeverity,
    ValidationIssue,
    ValidationReport,
)
from .pipeline_adapter import VisualPipelineAdapter

__all__ = [
    "VisualBoundaryType",
    "VisualBoundaryCandidate",
    "VisualShot",
    "ShotDurationPolicy",
    "TailDurationPolicy",
    "ImageSupplyState",
    "VisualPlannerEngine",
    "VisualPlannerOptions",
    "FrameQuantizationPolicy",
    "rational_fps_from_float",
    "VisualBoundaryCandidateBuilder",
    "VisualShotPlanner",
    "PlannedShotInterval",
    "VisualShotBoundaryPath",
    "ImageAllocationPolicy",
    "AllocationResult",
    "ProjectValidationError",
    "SilentTailAllocator",
    "DurationAwareMotionPolicy",
    "FrameTimebase",
    "FrameQuantizer",
    "FrameAccuracyValidator",
    "FrameValidationReport",
    "FrameValidationIssue",
    "BoundaryQuantizationRecord",
    "QuantizationDiagnostics",
    "VisualAccuracyValidator",
    "ValidationSeverity",
    "ValidationIssue",
    "ValidationReport",
    "VisualPipelineAdapter",
]

