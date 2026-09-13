/**
 * apps/capcut-v2/desktop/src/main/workspace_manager.js
 * Production-ready Workspace Manager for 2TOOLNE AutoEdit Desktop Main Process.
 * Manages active Personal and Team Workspaces, RBAC permissions, and Token Wallet routing.
 */

'use strict';

class WorkspaceManager {
  constructor(options = {}) {
    this.cloudClient = options.cloudClient;
    this.secureStorage = options.secureStorage;
    this.activeWorkspace = null;
    this.spaces = [];
  }

  /**
   * Set or update the cloud client instance
   */
  setCloudClient(client) {
    this.cloudClient = client;
  }

  /**
   * Sync available workspaces for current authenticated user
   */
  async syncWorkspaces() {
    if (!this.cloudClient) {
      return { ok: false, error: 'Cloud client not configured' };
    }

    const res = await this.cloudClient.listSpaces();
    if (!res.ok) {
      return res;
    }

    this.spaces = res.spaces || [];

    // Attempt to restore previously selected workspace from secure storage
    const savedId = this.secureStorage ? this.secureStorage.getItem('active_workspace_id') : null;
    let target = null;

    if (savedId) {
      target = this.spaces.find((s) => s.id === savedId);
    }

    // Default fallback to first space (usually Personal Space)
    if (!target && this.spaces.length > 0) {
      target = this.spaces[0];
    }

    if (target) {
      this.setActiveWorkspace(target);
    } else {
      this.activeWorkspace = null;
    }

    return {
      ok: true,
      spaces: this.spaces,
      activeWorkspace: this.activeWorkspace,
    };
  }

  /**
   * Switch active workspace
   */
  switchWorkspace(workspaceId) {
    const space = this.spaces.find((s) => s.id === workspaceId);
    if (!space) {
      return { ok: false, error: `Workspace not found: ${workspaceId}` };
    }

    this.setActiveWorkspace(space);

    if (this.secureStorage) {
      try {
        this.secureStorage.setItem('active_workspace_id', space.id);
      } catch (e) {}
    }

    return {
      ok: true,
      activeWorkspace: this.activeWorkspace,
    };
  }

  /**
   * Set active workspace and compute permissions
   */
  setActiveWorkspace(space) {
    const isTeam = space.space_type === 'TEAM';
    const role = (space.user_role || (isTeam ? 'MEMBER' : 'OWNER')).toUpperCase();

    // Standard authoritative permissions
    const permissions = space.permissions || {
      can_view: ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'].includes(role),
      can_download: ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'].includes(role),
      can_upload: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_create_folder: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_rename: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_move: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_trash: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_restore: ['OWNER', 'ADMIN'].includes(role),
      can_permanent_delete: ['OWNER', 'ADMIN'].includes(role),
      can_create_share: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_revoke_share: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_use_tokens: ['OWNER', 'ADMIN', 'EDITOR'].includes(role),
      can_invite_member: ['OWNER', 'ADMIN'].includes(role),
      can_remove_member: ['OWNER', 'ADMIN'].includes(role),
      can_change_role: ['OWNER', 'ADMIN'].includes(role),
      can_manage_billing: role === 'OWNER',
      can_manage_seats: ['OWNER', 'ADMIN'].includes(role),
    };

    this.activeWorkspace = {
      id: space.id,
      name: space.name,
      space_type: space.space_type,
      owner_id: space.owner_id,
      team_id: isTeam ? (space.team_id || space.owner_id) : null,
      user_role: role,
      permissions,
      effective_quota_bytes: space.effective_quota_bytes,
      used_bytes: space.used_bytes,
      free_bytes: space.free_bytes,
    };

    return this.activeWorkspace;
  }

  /**
   * Get active workspace info
   */
  getActiveWorkspace() {
    return this.activeWorkspace;
  }

  /**
   * Get target token wallet routing parameters for Upscale jobs
   */
  getTokenRoutingParams() {
    if (!this.activeWorkspace) {
      return {};
    }

    if (this.activeWorkspace.space_type === 'TEAM') {
      if (!this.activeWorkspace.permissions.can_use_tokens) {
        const err = new Error('Bạn không có quyền sử dụng token của Team (Role VIEWER)');
        err.code = 'WORKSPACE_TOKEN_PERMISSION_DENIED';
        throw err;
      }
      return {
        team_id: this.activeWorkspace.team_id,
        workspace_id: this.activeWorkspace.id,
      };
    }

    return {
      workspace_id: this.activeWorkspace.id,
    };
  }
}

module.exports = { WorkspaceManager };
