# VibeTree Multi-Agent Task Template

## Task Overview
- **TASK NAME**: `windows-fix-<issue-name>`
- **TASK TYPE**: Windows Bug Fix / Optimization
- **TARGET**: Windows only (x86_64)
- **RISK LEVEL**: ZERO TOLERANCE FOR MACOS REGRESSION
- **BASE BRANCH**: `windows-dev`
- **TARGET BRANCH**: `windows-fix-<issue-name>`
- **WORKTREE PATH**: `.worktrees/<issue-name>`

---

## Agent Operating Rules
```
MACOS = PROTECTED (Do not change behavior or VideoToolbox pipeline)
WINDOWS = ACTIVE DEVELOPMENT (Optimize, fix platform-specific quirks)
SHARED CORE = RESTRICTED (Requires justification & regression tests)
```

---

## Standard 10-Step Execution Protocol

1. **Reproduce Issue**: Run reproduction script or test case in the worktree.
2. **Identify Root Cause**: Determine if bug is in Platform Layer (Windows-specific) or Shared Core.
3. **Determine Layer**:
   - *Windows Layer*: Modify `start_windows.bat`, `start_windows.ps1`, `SlideshowStudio.vbs`, Windows GPU/encoding flags in `ffmpeg_utils.py`.
   - *Shared Core*: If modifying shared core, provide proof that macOS is unaffected.
4. **Implement Smallest Safe Fix**: Write clean, targeted, minimal code changes.
5. **Add Regression Test**: Add a test in `tests/` covering the bug scenario.
6. **Run Worktree Tests**: Run `pytest tests/ -v`.
7. **Run Shared Tests**: Verify shared subtitle alignment and FFmpeg command generation.
8. **Check macOS Protection**: Ensure `start_mac.command` and `platform/macos/` are untouched.
9. **Commit Changes**: Use semantic commit message (e.g. `fix(windows): optimize NVENC encoder detection`).
10. **Create Pull Request**: Submit PR from `windows-fix-<issue-name>` into `windows-dev`.

---

## Strict Prohibitions
- ❌ NO direct commits to `main` or `macos-stable`.
- ❌ NO `git push --force`.
- ❌ NO `git reset --hard` or destructive commands.
- ❌ NO refactoring of working macOS VideoToolbox code.
- ❌ NO changing Two-Pass Anchor Forced Alignment core algorithms.
