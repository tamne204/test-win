# 2TOOLNE Agent Guidelines & Workspace Discipline

All AI coding agents operating in this workspace must follow these mandatory rules and workflows.

## Active Rules
- [CONTEXT_FIRST_RULE](.agents/rules/context_first_rule.md): Always query memory and read GEMINI.md before planning.
- [NO_DUPLICATE_ARCHITECTURE_RULE](.agents/rules/no_duplicate_architecture_rule.md): Respect 1 Bundle = 1 Video = 1 PipelineJob = 1 CapCut Project. No duplicate queues.
- [MEMORY_NOT_TRUTH_RULE](.agents/rules/memory_not_truth_rule.md): Live code is the source of runtime truth; reconcile memory with code when discrepancies arise.
- [SCOPE_CONTROL_RULE](.agents/rules/scope_control_rule.md): Strictly adhere to task boundaries. Zero unauthorized refactoring.
- [RECORD_DURABLE_DECISIONS_RULE](.agents/rules/record_durable_decisions_rule.md): Record durable decisions to persistent memory upon task completion.
- [RELEASE_STANDARD_RULE](.agents/rules/release_standard_rule.md): Mandatory 46-section release policy & hard gates for every version bump and deployment.

## Core Memory CLI Commands
- Query: `uv run .agent/skills/memory-manager/bridge.py query --query "<keywords>" --scope all`
- Save: `uv run .agent/skills/memory-manager/bridge.py save --text "<content>" --type <decision|learning|convention|warning> --tags "<tag1,tag2>"`
- Status: `uv run .agent/skills/memory-manager/bridge.py status`
