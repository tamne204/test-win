# MILESTONE A1-M3 REPORT: IMAGE ALLOCATION & SILENT TAIL

- **Milestone:** A1-M3
- **Subsystems:** Image Allocation Policy & Silent Tail Allocator
- **Status:** COMPLETED
- **Branch:** `feat/a1-m3-image-and-tail`
- **Date:** September 2026

---

### 1. Implemented Components

1. **Image Allocation Policy (`apps/capcut-v2/core/visual/image_allocator.py`):**
   - `ImageAllocationPolicy`: Assigns physical image files to planned speech shots in strictly monotonic, chronological narrative order.
   - Zero-Image Validation: Immediately raises `ProjectValidationError("No physical visual assets supplied")` if zero visual assets are provided.
   - Deterministic Shortage Management: When shots exceed images, selects the oldest used image satisfying `MIN_REUSE_DISTANCE_SECONDS >= 60.0s`, strictly prohibiting adjacent duplicates and rapid A-B-A loops. Sets `alternate_motion = True` for reused shots.
   - Surplus Management: Unconsumed physical images are preserved and cleanly routed to the silent tail allocator.

2. **Silent Tail Allocator (`apps/capcut-v2/core/visual/tail_allocator.py`):**
   - `SilentTailAllocator`: Eliminates the 2.5-minute black screen dropout across the outro audio ($1640.86\text{s} \to 1787.23\text{s}$).
   - Case A (Sufficient Images): Paces remaining unconsumed images across the tail with natural duration ($146.37\text{s} / 6 \approx 24.4\text{s/shot}$ on `LONG_01`).
   - Case B (Severe Shortage): Allocates remaining assets up to soft_max ($25\text{s}$), then holds the final image or reuses with alternate motion.
   - Case C (Zero Remaining Images): Holds the final speech image across the entire tail with `ULTRA_SLOW` motion ($0.2\%/\text{s}$).
   - Invariant Guarantee: Guaranteed contiguous visual coverage with zero gaps, zero overlaps, and zero black screen.

---

### 2. Verification
- Fast test profile: 79 passed in 0.41s (`tests/test_visual_image_and_tail.py` 5/5 passed).
- Zero-image validation tested and verified.
- Shortage reuse distance $\ge 60\text{s}$ and no adjacent duplicates verified.
- Tail Cases A, B, and C verified.
