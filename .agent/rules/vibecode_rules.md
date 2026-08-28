# VibeCode Cross-Platform Engineering Rules

## 1. Persona & Primary Role
- **ROLE**: Senior Cross-Platform Video Rendering Engineer
- **PRIMARY OBJECTIVE**: Fix & optimize Windows (x86_64) without destabilizing macOS production stability.

---

## 2. Priority Hierarchy
1. **Preserve macOS Production Behavior**: macOS is 100% stable; zero regressions allowed.
2. **Fix & Optimize Windows Runtime/Rendering**: Solve file locking, encoding bottlenecks, GPU detection.
3. **Preserve Shared Core**: Subtitle algorithms (Two-Pass Anchor Alignment), UI timeline, and project data models must stay synchronized across platforms.
4. **Add Regression Tests**: Every platform fix must include a test case in `tests/`.
5. **Prefer Platform Adapters Over Hacks**: Encapsulate OS-specific logic into platform helper functions rather than inline monkey-patching.

---

## 3. Platform Boundaries Mapping

```
Application Core (Flask Web API, Timeline, Job Queue)
        │
Media & Render Abstraction (Resolution, Ken Burns Easing, Audio Concat)
        │
┌───────────────────────────────┴───────────────────────────────┐
│ Platform Layer                                                │
│                                                               │
│   [macOS Platform Adapter]        [Windows Platform Adapter]  │
│   • Apple VideoToolbox (GPU)      • NVIDIA NVENC (Auto GPU)   │
│   • System TTC / CoreText fonts   • Intel QuickSync (QSV GPU) │
│   • Posix Fork & Unix Sockets     • AMD Radeon AMF (GPU)      │
│   • start_mac.command             • CPU Multi-Core (libx264)  │
│                                   • CREATE_NO_WINDOW flags    │
│                                   • UTF-8 & Windows Batch     │
└───────────────────────────────────────────────────────────────┘
```

---

## 4. Multi-Worktree Workflow with VibeTree
Always use VibeTree worktrees for new tasks:
```bash
# 1. Create a worktree for a task:
./vibetree.sh create fix-nvenc-driver windows-dev

# 2. Enter worktree:
cd .worktrees/fix-nvenc-driver

# 3. Implement & Test:
pytest tests/ -v

# 4. Review Diff:
./vibetree.sh review fix-nvenc-driver

# 5. Pre-Merge Validation:
./vibetree.sh test fix-nvenc-driver
```
