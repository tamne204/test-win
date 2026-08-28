#!/usr/bin/env bash
# ==============================================================================
# VibeTree CLI Manager for VibeCode
# Multi-worktree isolated agentic development workflow
# ==============================================================================
set -e

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
WORKTREE_BASE="$REPO_ROOT/.worktrees"
DEFAULT_BASE="windows-dev"

usage() {
    echo "🌲 VibeTree CLI — Multi-Worktree Agent Workflow for VibeCode"
    echo ""
    echo "Usage: ./vibetree.sh <command> [options]"
    echo ""
    echo "Commands:"
    echo "  create <task-name> [base-branch]  Spawn an isolated worktree for an AI task"
    echo "  list                              List all active worktrees and branches"
    echo "  review <task-name>                Review git diff against base branch"
    echo "  test <task-name>                  Run pre-merge test suite on worktree"
    echo "  remove <task-name>                Safely remove a finished worktree"
    echo "  rollback-mac                      Verify or checkout the macos-stable snapshot"
    echo ""
    echo "Examples:"
    echo "  ./vibetree.sh create fix-gpu-nvenc windows-dev"
    echo "  ./vibetree.sh review fix-gpu-nvenc"
    echo "  ./vibetree.sh test fix-gpu-nvenc"
    echo "  ./vibetree.sh list"
    echo ""
    exit 1
}

CMD="${1:-}"
TASK_NAME="${2:-}"
BASE_BRANCH="${3:-$DEFAULT_BASE}"

case "$CMD" in
    create)
        if [ -z "$TASK_NAME" ]; then
            echo "❌ Error: Task name is required (e.g., ./vibetree.sh create fix-render)"
            exit 1
        fi
        BRANCH_NAME="windows-fix-${TASK_NAME#windows-fix-}"
        TARGET_DIR="$WORKTREE_BASE/$TASK_NAME"

        if [ -d "$TARGET_DIR" ]; then
            echo "⚠️ Worktree already exists at: $TARGET_DIR"
            exit 0
        fi

        echo "🌲 [VibeTree] Creating worktree for branch '$BRANCH_NAME' based on '$BASE_BRANCH'..."
        mkdir -p "$WORKTREE_BASE"
        git worktree add -b "$BRANCH_NAME" "$TARGET_DIR" "$BASE_BRANCH"
        
        echo "🌲 [VibeTree] Executing post-create hook..."
        "$REPO_ROOT/.vibetree/hooks/post-create" "$TARGET_DIR" "$REPO_ROOT"
        
        echo ""
        echo "✅ Worktree created successfully at: $TARGET_DIR"
        echo "👉 To start working with Antigravity / Gemini in this worktree, navigate to:"
        echo "   cd \"$TARGET_DIR\""
        ;;

    list)
        echo "🌲 [VibeTree Active Worktrees]"
        git worktree list
        ;;

    review)
        if [ -z "$TASK_NAME" ]; then
            echo "❌ Error: Task name is required."
            exit 1
        fi
        TARGET_DIR="$WORKTREE_BASE/$TASK_NAME"
        if [ ! -d "$TARGET_DIR" ]; then
            echo "❌ Worktree not found at: $TARGET_DIR"
            exit 1
        fi
        echo "🌲 [VibeTree Diff Review] Showing changes in $TASK_NAME against $DEFAULT_BASE:"
        git -C "$TARGET_DIR" diff "$DEFAULT_BASE"...HEAD
        ;;

    test)
        if [ -z "$TASK_NAME" ]; then
            echo "❌ Error: Task name is required."
            exit 1
        fi
        TARGET_DIR="$WORKTREE_BASE/$TASK_NAME"
        if [ ! -d "$TARGET_DIR" ]; then
            echo "❌ Worktree not found at: $TARGET_DIR"
            exit 1
        fi
        "$REPO_ROOT/.vibetree/hooks/pre-merge" "$TARGET_DIR" "$DEFAULT_BASE"
        ;;

    remove)
        if [ -z "$TASK_NAME" ]; then
            echo "❌ Error: Task name is required."
            exit 1
        fi
        TARGET_DIR="$WORKTREE_BASE/$TASK_NAME"
        echo "🌲 [VibeTree] Removing worktree: $TARGET_DIR..."
        git worktree remove --force "$TARGET_DIR" 2>/dev/null || rm -rf "$TARGET_DIR"
        git worktree prune
        echo "✅ Worktree removed."
        ;;

    rollback-mac)
        echo "🍏 [VibeTree macOS Safe Rollback]"
        echo "Checking tag 'macos-stable'..."
        git tag -v macos-stable 2>/dev/null || git show macos-stable --oneline -s
        echo "To checkout the macOS stable code in a dedicated worktree, run:"
        echo "  git worktree add ../vibecode-macos-stable macos-stable"
        ;;

    *)
        usage
        ;;
esac
