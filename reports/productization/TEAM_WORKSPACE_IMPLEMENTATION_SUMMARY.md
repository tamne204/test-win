# 2TOOLNE AUTOEDIT V2 — PRIORITY 6: TEAM / WORKSPACE
## Fast-Track Production Architecture & Verification Summary

**Document ID:** `REP-P6-TEAM-WORKSPACE-V2`  
**Status:** `PASS / FREEZE` — Ready for Production Deployment  
**Author:** Antigravity (Google Deepmind)  
**Date:** September 8, 2026  
**Authoritative Plan Reference:** `reports/productization/AUTH_LICENSE_CLOUD_TEAM_INTEGRATION_PLAN.md`  
**Prerequisite Baselines:** Priority 1–5 (`PASS / FREEZE`), Core Subtitle Engines (`A0`, `A1`, `A2`, `SubtitleLayoutEngine` = `FROZEN_PRODUCTION`)

---

### Executive Summary

Priority 6 (Team / Workspace) has been designed, implemented, hardened, and verified under strict production specifications for 2TOOLNE AutoEdit V2. Shared workspaces, authoritative server-side RBAC across 4 roles (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`), isolated Team Cloud storage, cryptographic single-use expiring invitations, isolated Team Token Wallets with **zero silent fallback**, and desktop license seat management have been fully realized across both the backend REST services and the Desktop application.

All **40 automated tests (`TEAM-T01` through `TEAM-T40`)** pass with a 100% success rate. In addition, zero regressions were introduced into Priority 1 (License + Account), Priority 2 (Browser Quick Login), Priority 3 (Token & Upscale Gating), Priority 4 (Cloud Explorer), Priority 5 (Cloud Share Link), or the frozen subtitle engines (`A0`, `A1`, `A2`, `SubtitleLayoutEngine` — 57/57 unit tests passed).

---

### Section 0: Production Database Safety Check & Confirmation

Before any database schema or code deployment, an audit was conducted across backend configuration, hosting environment, and active PDO connections:

- **Authoritative Source of Truth:** `website/api/v1/config.php`
  - `DB_HOST`: `localhost`
  - `DB_NAME`: `ecxaebka_bot`
  - `DB_USER`: `ecxaebka_bot`
  - `DB_CHARSET`: `utf8mb4`
- **Resolution of Discrepancy:** Previous audit notes confirmed `ecxaebka_bot` as the live DirectAdmin MySQL production database. The mention of `tamne_cloud` in the Priority 5 report was an illustrative staging/local documentation placeholder. The live target database is confirmed as `ecxaebka_bot`.
- **Status:**
  - `PRODUCTION_DB_NAME` = `ecxaebka_bot`
  - `SOURCE_OF_TRUTH_CONFIG` = `website/api/v1/config.php`
  - `MIGRATION_TARGET_CONFIRMED` = `YES`

---

### Key Architectural Implementations

#### 1. Schema Migration (`database/migrations/v4_teams_and_workspace_schema.sql`)
- **Strict Non-Breaking Schema:** MySQL 5.7+ compatible, zero request-time DDL (`CREATE TABLE` or `ALTER TABLE` inside API endpoints is strictly prohibited).
- **Core Entities:**
  - `teams`: Team profile, `owner_user_id`, plan tiers, and `app_key_count` (desktop license seat capacity cap).
  - `team_members`: Membership binding user to team with authoritative roles (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`), status, and audit timestamps.
  - `team_invitations`: Opaque 256-bit cryptographic single-use invitation tokens (`token_hash` index, `expires_at`, `used_at`, `offered_role`).
  - `team_seats`: Device-level license seat assignments consumed against `teams.app_key_count`, storing `device_id`, platform, status (`ACTIVE` / `REVOKED`), and assigned timestamp.
  - Alterations to `credit_wallets`, `credit_reservations`, `credit_transactions`: Added indexed `team_id` and `workspace_id` to allow strict wallet isolation between personal accounts and team workspaces.

```sql
CREATE TABLE IF NOT EXISTS `teams` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `name` VARCHAR(128) NOT NULL,
  `owner_user_id` VARCHAR(64) NOT NULL,
  `plan_id` VARCHAR(64) NOT NULL DEFAULT 'plan_team_standard',
  `app_key_count` INT UNSIGNED NOT NULL DEFAULT 2,
  `cloud_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 53687091200,
  `status` ENUM('ACTIVE', 'SUSPENDED', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_teams_owner` (`owner_user_id`),
  INDEX `idx_teams_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2. Server-Side RBAC Permission Service (`WorkspacePermissionService.php`)
- Centralized server-side authority implementing 16 fine-grained capability checks:
  - `canView`: All active members (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`).
  - `canDownload`: All active members (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`).
  - `canUpload`: `OWNER`, `ADMIN`, `EDITOR`. Denied to `VIEWER` (HTTP 403 `WORKSPACE_PERMISSION_DENIED`).
  - `canCreateFolder`: `OWNER`, `ADMIN`, `EDITOR`. Denied to `VIEWER`.
  - `canRename`, `canMove`, `canTrash`: `OWNER`, `ADMIN`, `EDITOR`.
  - `canRestore`, `canPermanentDelete`: `OWNER`, `ADMIN`. Denied to `EDITOR` and `VIEWER`.
  - `canCreateShare`: `OWNER`, `ADMIN`, `EDITOR`. Denied to `VIEWER`.
  - `canRevokeShare`: `OWNER` and `ADMIN` can revoke any share; `EDITOR` can only revoke shares they created.
  - `canUseWorkspaceTokens`: `OWNER`, `ADMIN`, `EDITOR`. Denied to `VIEWER` (HTTP 403 `WORKSPACE_TOKEN_PERMISSION_DENIED`).
  - `canInviteMember`, `canRemoveMember`, `canChangeRole`, `canManageSeats`: `OWNER`, `ADMIN`.
  - `canManageBilling`, `deleteTeam`: `OWNER` only.
- Protects against privilege escalation: `ADMIN` cannot remove `OWNER`, `ADMIN` cannot promote anyone to `OWNER`, and the last `OWNER` cannot be deleted or demoted (`OWNER_IMMUTABLE`).

#### 3. Zero Silent Fallback Token Billing (`CreditsController.php`)
- Strict separation of token balances:
  - When active workspace is `PERSONAL`, reservations query and deduct from the user's `credit_wallets` (`user_id = ?`).
  - When active workspace is `TEAM`, reservations query and deduct from the team's dedicated `credit_wallets` (`team_id = ?`).
- **Zero Fallback Guarantee:** If team tokens are insufficient, the transaction immediately fails with HTTP 402 `INSUFFICIENT_TOKENS` and friendly Vietnamese messaging:
  > *"Team không đủ token để thực hiện thao tác này. (Hiện có: X, Cần: Y). Vui lòng liên hệ Chủ nhóm để nạp thêm."*
- Personal wallet balances are **never silently touched or decremented** when operating in team context.

#### 4. Team Cloud Storage & Share Link Ownership Persistence
- Reuses Priority 4 Cloud Explorer UI and VFS hierarchy. The explorer simply requests files for the active workspace space (`sp_team_*`).
- Files uploaded by team members are owned by the Team Space. When an employee/member is removed from the team, their uploaded files remain intact and accessible to remaining team members.
- Public share links created for team items remain team property. `OWNER` and `ADMIN` can view, copy, or revoke shares created by former team members, while the departed member loses all access immediately.

#### 5. Team License Seats (`CapCutLicenseController.php`)
- Entitlement is bounded by `teams.app_key_count`.
- When a team member activates the desktop app under a team seat, the server checks `active_seats < app_key_count`.
- Issues standard cryptographically signed Ed25519/HMAC license envelopes bound to `device_id` (HWID).
- **Dual Entitlement Priority:** If a user possesses a valid individual license, it is preserved in local storage and not destroyed.
- **Revocation Semantics:**
  - Online: Member removal immediately flips `team_seats.status = 'REVOKED'`. Subsequent API requests fail immediately.
  - Offline: Documented 72h offline grace is cryptographically preserved in existing signed envelopes until normal grace expiration.

#### 6. Desktop UI & Main Process Workspace Manager
- **Authoritative Main-Process State (`WorkspaceManager`):** Holds `active_workspace_id`, space metadata, user role, resolved permissions, and provides `getTokenRoutingParams()`.
- **Header Workspace Switcher:** Located in the desktop header (`[ Cá nhân ▼ ]` / `[ 2TOOLNE Studio ▼ ]`). Displays active workspace name, role badge, and allows switching with instant refresh of Cloud Explorer, quota bar, token display, and upload permissions.
- **Modals:**
  - **Modal 14 (`#modalTeamMembers`):** Displays team roster, roles, member status, seat activation counts, invitation creation (with instant URL copy), role promotion/demotion dropdowns, and member removal.
  - **Modal 15 (`#modalCreateTeam`):** Self-service team creation modal automatically setting up team record, team space, and team wallet.

---

### Verification Matrix (40/40 Tests Passed)

| Test ID | Scenario / Verification Scope | Result |
| :--- | :--- | :---: |
| **TEAM-T01** | Personal workspace still works (default space, personal quota, personal wallet) | **PASS** |
| **TEAM-T02** | Create Team (auto-creates team record, team cloud space, team wallet) | **PASS** |
| **TEAM-T03** | Creator becomes OWNER with full administrative & billing permissions | **PASS** |
| **TEAM-T04** | Invite EDITOR with single-use opaque cryptographic URL token | **PASS** |
| **TEAM-T05** | Accept invitation adds member with offered role to team roster | **PASS** |
| **TEAM-T06** | Invite token single-use (re-use fails with HTTP 410 `INVITATION_ALREADY_USED`) | **PASS** |
| **TEAM-T07** | Expired invite denied (past `expires_at` fails with HTTP 410 `INVITATION_EXPIRED`) | **PASS** |
| **TEAM-T08** | VIEWER cannot upload (returns HTTP 403 `WORKSPACE_PERMISSION_DENIED`) | **PASS** |
| **TEAM-T09** | EDITOR can upload and create folders in team space | **PASS** |
| **TEAM-T10** | EDITOR cannot manage billing (`can_manage_billing = false`) | **PASS** |
| **TEAM-T11** | ADMIN manages Viewer/Editor (role promotion and demotion) | **PASS** |
| **TEAM-T12** | ADMIN cannot remove OWNER (HTTP 403 `OWNER_IMMUTABLE`) | **PASS** |
| **TEAM-T13** | Last OWNER protected (cannot demote or remove last owner) | **PASS** |
| **TEAM-T14** | Workspace switch refreshes Cloud space and breadcrumb context | **PASS** |
| **TEAM-T15** | Team quota shown correctly (50GB quota, independent used bytes) | **PASS** |
| **TEAM-T16** | Personal quota restored on switch back to Personal workspace | **PASS** |
| **TEAM-T17** | Team file remains accessible in team space after uploader is removed | **PASS** |
| **TEAM-T18** | Removed member access denied immediately online (HTTP 403) | **PASS** |
| **TEAM-T19** | Cross-team file IDOR denied (cannot mutate files in alien team space) | **PASS** |
| **TEAM-T20** | Team share create by EDITOR works with URL fragment pattern | **PASS** |
| **TEAM-T21** | Viewer share creation denied (HTTP 403 `FORBIDDEN`) | **PASS** |
| **TEAM-T22** | Former member cannot revoke Team share after departure | **PASS** |
| **TEAM-T23** | Owner/Admin can revoke former member's share in team space | **PASS** |
| **TEAM-T24** | Personal Upscale charges Personal Wallet (Team wallet untouched) | **PASS** |
| **TEAM-T25** | Team Upscale charges Team Wallet (Personal wallet untouched) | **PASS** |
| **TEAM-T26** | Viewer Team Upscale denied (HTTP 403 `WORKSPACE_TOKEN_PERMISSION_DENIED`) | **PASS** |
| **TEAM-T27** | Empty Team Wallet does not charge Personal Wallet (HTTP 402, 0 silent fallback) | **PASS** |
| **TEAM-T28** | Upscale Team commit remains idempotent (no double billing on replay) | **PASS** |
| **TEAM-T29** | Team seat assigned when available (consumes 1 seat from `app_key_count`) | **PASS** |
| **TEAM-T30** | No seat available handled cleanly (HTTP 403 `TEAM_SEATS_EXHAUSTED`) | **PASS** |
| **TEAM-T31** | Personal license remains untouched in secure storage when using team seat | **PASS** |
| **TEAM-T32** | Team seat revokes online after member removal (status flipped to `REVOKED`) | **PASS** |
| **TEAM-T33** | Offline Team entitlement behavior matches documented 72h grace window | **PASS** |
| **TEAM-T34** | App restart restores active workspace from persistent storage if authorized | **PASS** |
| **TEAM-T35** | Revoked workspace safely falls back to Personal workspace on restart | **PASS** |
| **TEAM-T36** | Team deletion/archiving owner-only (ADMIN/EDITOR denied with 403) | **PASS** |
| **TEAM-T37** | Invite IDOR denied (cannot create invite for alien team) | **PASS** |
| **TEAM-T38** | Wallet cross-team IDOR denied (cannot inspect or reserve from alien team wallet) | **PASS** |
| **TEAM-T39** | Membership API cross-team IDOR denied (cannot inspect alien team members) | **PASS** |
| **TEAM-T40** | Priority 1–5 regression suite passes cleanly | **PASS** |

---

### Regression Testing Summary

| Test Suite | Scope | Result | Details |
| :--- | :--- | :---: | :--- |
| `test_quick_login_and_auth.js` | Priorities 1, 2, 3 | **PASS** | 5/5 passed (Loopback PKCE, CSRF state, cancellation, state separation, upscale lifecycle) |
| `test_cloud_explorer.js` | Priority 4 | **PASS** | 20/20 passed (Auth gate, VFS navigation, mutations, streaming upload, cache manager) |
| `test_cloud_share.js` | Priority 5 | **PASS** | 32/32 passed (Fragment URLs, AES-GCM recovery, capability sessions, subtree isolation) |
| `pytest` Subtitle Engines | Core Algorithms | **PASS** | 57/57 passed (`test_a0_collapse_healing`, `test_a1_acceptance_matrix`, `test_a2_acceptance_matrix`, `test_subtitle_layout_engine`) |
| `test_team_workspace.js` | Priority 6 | **PASS** | 40/40 passed (All RBAC, wallet isolation, seat caps, invitations, IDOR boundary checks) |

---

### Physical End-to-End Simulation Results

1. **Owner (`user_owner`):** Created team `Beta Creative` with initial wallet of 50 tokens.
2. **Invitations:** Sent cryptographic invite token to `user_editor` and `user_viewer`.
3. **Acceptance:** Both members joined successfully; roles mapped to `EDITOR` and `VIEWER`.
4. **Cloud Mutations:** `EDITOR` successfully created folder `EditorProjectDir` and file share; `VIEWER` was blocked with HTTP 403 on folder creation and share creation.
5. **Upscale Billing:**
   - Personal Upscale reserved and committed 2 tokens from Owner Personal Wallet ($100 \to 98$, Team wallet untouched at 50).
   - Team Upscale reserved and committed 3 tokens from Team Wallet ($50 \to 47$, Personal wallet untouched at 98).
   - Viewer Team Upscale was blocked with HTTP 403 `WORKSPACE_TOKEN_PERMISSION_DENIED`.
   - Exhausted team balance returned HTTP 402 without touching personal tokens.
6. **Desktop Seats:** Assigned 2 seats successfully; 3rd seat request correctly rejected with `TEAM_SEATS_EXHAUSTED`.
7. **Member Removal:** `user_editor` removed from team. Online access was immediately severed (HTTP 403), seat status flipped to `REVOKED`, yet uploaded team files and share links remained intact and manageable by Owner/Admin.

---

### Production Deployment Order & Status

1. `database/migrations/v4_teams_and_workspace_schema.sql` ready for deployment on `ecxaebka_bot`.
2. `WorkspacePermissionService.php` centralized RBAC active.
3. `TeamController.php` endpoints registered in `website/api/v1/index.php`.
4. `CreditsController.php` and `WalletController.php` team wallet support active.
5. Desktop UI: Header Workspace Switcher and Modals 14 & 15 integrated.
6. Feature flag `TEAM_PLANS_ENABLED` set to `true` in `website/api/v1/config.php`.

**FINAL STATUS:** `TEAM_WORKSPACE_PRODUCTION_READY`
