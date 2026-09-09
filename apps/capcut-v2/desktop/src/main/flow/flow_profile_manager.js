/**
 * apps/capcut-v2/desktop/src/main/flow/flow_profile_manager.js
 * Multi-Account Google Flow Profile Manager.
 *
 * Enforces:
 * - Stable account/profile IDs: flowacc_<uuid>
 * - Electron session partition isolation: persist:2toolne-flow-<uuid>
 * - Zero password extraction or storage.
 * - Profile metadata persistence in ~/.2toolne/flow_profiles/profiles.json
 * - Forward-compatible with Phase 5 multi-account rotation.
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');

class FlowProfileManager {
  /**
   * @param {Object} [options]
   * @param {string} [options.storageDir] Custom profiles storage directory
   */
  constructor(options = {}) {
    this.storageDir = options.storageDir || path.join(os.homedir(), '.2toolne', 'flow_profiles');
    this.profilesFile = path.join(this.storageDir, 'profiles.json');
    this.profiles = [];
    this.activeProfileId = null;

    this.ensureStorage();
    this.loadProfiles();
  }

  ensureStorage() {
    if (!fs.existsSync(this.storageDir)) {
      fs.mkdirSync(this.storageDir, { recursive: true });
    }
  }

  loadProfiles() {
    try {
      if (fs.existsSync(this.profilesFile)) {
        const raw = fs.readFileSync(this.profilesFile, 'utf8');
        const data = JSON.parse(raw);
        this.profiles = Array.isArray(data.profiles) ? data.profiles : [];
        this.activeProfileId = data.active_profile_id || null;
      }
    } catch (err) {
      console.warn('[FlowProfileManager] Failed to load profiles.json, resetting:', err.message);
      this.profiles = [];
      this.activeProfileId = null;
    }

    // Auto-create initial default profile if empty
    if (this.profiles.length === 0) {
      const defaultId = `flowacc_${crypto.randomUUID()}`;
      const defaultProfile = {
        id: defaultId,
        name: 'Tài khoản 1 (Mặc định)',
        partition: `persist:2toolne-flow-${defaultId.replace('flowacc_', '')}`,
        email: '',
        tier: 'UNKNOWN',
        credits: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        is_default: true,
      };
      this.profiles.push(defaultProfile);
      this.activeProfileId = defaultId;
      this.saveProfiles();
    } else if (!this.activeProfileId && this.profiles.length > 0) {
      this.activeProfileId = this.profiles[0].id;
      this.saveProfiles();
    }
  }

  saveProfiles() {
    this.ensureStorage();
    const data = {
      active_profile_id: this.activeProfileId,
      updated_at: new Date().toISOString(),
      profiles: this.profiles,
    };
    const tmpFile = `${this.profilesFile}.tmp.${Date.now()}`;
    fs.writeFileSync(tmpFile, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmpFile, this.profilesFile);
  }

  getProfiles() {
    return this.profiles.map(p => ({
      ...p,
      is_active: p.id === this.activeProfileId,
    }));
  }

  getProfile(profileId) {
    return this.profiles.find(p => p.id === profileId) || null;
  }

  getActiveProfile() {
    return this.getProfile(this.activeProfileId) || this.profiles[0] || null;
  }

  setActiveProfile(profileId) {
    const profile = this.getProfile(profileId);
    if (!profile) {
      throw new Error(`Profile not found: ${profileId}`);
    }
    this.activeProfileId = profileId;
    this.saveProfiles();
    return profile;
  }

  createProfile({ name }) {
    const uuid = crypto.randomUUID();
    const profileId = `flowacc_${uuid}`;
    const newProfile = {
      id: profileId,
      name: name || `Tài khoản ${this.profiles.length + 1}`,
      partition: `persist:2toolne-flow-${uuid}`,
      email: '',
      tier: 'UNKNOWN',
      credits: null,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      is_default: this.profiles.length === 0,
    };
    this.profiles.push(newProfile);
    if (!this.activeProfileId) {
      this.activeProfileId = profileId;
    }
    this.saveProfiles();
    return newProfile;
  }

  updateProfile(profileId, updates = {}) {
    const idx = this.profiles.findIndex(p => p.id === profileId);
    if (idx === -1) {
      throw new Error(`Profile not found: ${profileId}`);
    }

    // Disallow overwriting critical partition or ID
    delete updates.id;
    delete updates.partition;

    this.profiles[idx] = {
      ...this.profiles[idx],
      ...updates,
      updated_at: new Date().toISOString(),
    };
    this.saveProfiles();
    return this.profiles[idx];
  }

  deleteProfile(profileId) {
    if (this.profiles.length <= 1) {
      throw new Error('Cannot delete the only remaining Flow profile');
    }
    const idx = this.profiles.findIndex(p => p.id === profileId);
    if (idx === -1) {
      throw new Error(`Profile not found: ${profileId}`);
    }
    this.profiles.splice(idx, 1);
    if (this.activeProfileId === profileId) {
      this.activeProfileId = this.profiles[0].id;
    }
    this.saveProfiles();
    return true;
  }

  getPartition(profileId = null) {
    const target = profileId ? this.getProfile(profileId) : this.getActiveProfile();
    if (!target) {
      throw new Error(`Target profile unresolved for partition`);
    }
    return target.partition;
  }
}

module.exports = { FlowProfileManager };
