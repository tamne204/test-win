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
    this.settings = {
      default_aspect: '9:16',
      mode: 'AUTO',
      char_approval_mode: 'MANUAL',
      auto_approve_valid: true,
      image_model: 'AUTO',
      image_prompt_batch_size: 1,
    };

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
        if (data.settings && typeof data.settings === 'object') {
          this.settings = {
            ...this.settings,
            ...data.settings,
          };
        }
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
      settings: this.settings,
    };
    const tmpFile = `${this.profilesFile}.tmp.${Date.now()}`;
    fs.writeFileSync(tmpFile, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmpFile, this.profilesFile);
  }

  getSettings() {
    return {
      active_profile_id: this.activeProfileId,
      default_aspect: this.settings?.default_aspect || '9:16',
      mode: this.settings?.mode || 'AUTO',
      char_approval_mode: this.settings?.char_approval_mode || 'MANUAL',
      auto_approve_valid: this.settings?.auto_approve_valid !== false,
      image_model: this.settings?.image_model || 'AUTO',
      image_prompt_batch_size: Math.max(1, Math.min(4, parseInt(this.settings?.image_prompt_batch_size || 1, 10))),
    };
  }

  updateSettings(newSettings = {}) {
    this.settings = {
      ...this.settings,
      ...newSettings,
    };
    if (newSettings.image_prompt_batch_size !== undefined) {
      this.settings.image_prompt_batch_size = Math.max(1, Math.min(4, parseInt(newSettings.image_prompt_batch_size || 1, 10)));
    }
    if (newSettings.image_model !== undefined) {
      this.settings.image_model = newSettings.image_model || 'AUTO';
    }
    if (newSettings.active_profile_id && this.getProfile(newSettings.active_profile_id)) {
      this.activeProfileId = newSettings.active_profile_id;
    }
    this.saveProfiles();
    return this.getSettings();
  }

  static normalizeTier(rawTier) {
    if (!rawTier || typeof rawTier !== 'string') return 'UNKNOWN';
    const upper = rawTier.trim().toUpperCase();
    if (upper.includes('ULTRA')) return 'ULTRA';
    if (upper.includes('PRO')) return 'PRO';
    if (upper.includes('FREE')) return 'FREE';
    return 'UNKNOWN';
  }

  static getMaxResolutionForTier(tier) {
    const normalized = FlowProfileManager.normalizeTier(tier);
    if (normalized === 'ULTRA') return '4K';
    if (normalized === 'PRO') return '2K';
    return '1080p';
  }

  static getAllowedResolutionsForTier(tier) {
    const normalized = FlowProfileManager.normalizeTier(tier);
    if (normalized === 'ULTRA') return ['1080p', '2K', '4K'];
    if (normalized === 'PRO') return ['1080p', '2K'];
    return ['1080p'];
  }

  getProfileCapability(profileId) {
    const p = this.resolveProfile(profileId);
    const tier = FlowProfileManager.normalizeTier(p?.tier);
    return {
      profile_id: p?.id || null,
      tier,
      max_download_resolution: FlowProfileManager.getMaxResolutionForTier(tier),
      allowed_resolutions: FlowProfileManager.getAllowedResolutionsForTier(tier),
    };
  }

  getProfiles() {
    return this.profiles.map(p => {
      const tier = FlowProfileManager.normalizeTier(p.tier);
      return {
        ...p,
        tier,
        max_download_resolution: FlowProfileManager.getMaxResolutionForTier(tier),
        allowed_resolutions: FlowProfileManager.getAllowedResolutionsForTier(tier),
        is_active: p.id === this.activeProfileId,
      };
    });
  }

  getProfile(profileId) {
    return this.profiles.find(p => p.id === profileId) || null;
  }

  getDefaultProfile() {
    return this.profiles.find(p => p.is_default) || this.profiles[0] || null;
  }

  getActiveProfile() {
    return this.getProfile(this.activeProfileId) || this.getDefaultProfile();
  }

  /**
   * Resolve a profile reference (stable ID, exact display name, or legacy alias).
   * @param {string} profileRef
   * @returns {Object|null} Resolved profile or null
   */
  resolveProfile(profileRef) {
    if (!profileRef || typeof profileRef !== 'string') {
      return this.getActiveProfile() || this.getDefaultProfile();
    }
    const cleanRef = profileRef.trim();
    if (!cleanRef) {
      return this.getActiveProfile() || this.getDefaultProfile();
    }

    // 1. Exact ID match (Stable flowacc_<uuid>)
    const byId = this.profiles.find(p => p.id === cleanRef);
    if (byId) return byId;

    // 2. Legacy alias check: "Flow #1", "flow_#1", "flow#1", "flowacc_default"
    if (/^flow\s*#?\s*1$/i.test(cleanRef) || cleanRef === 'flowacc_default') {
      const def = this.getDefaultProfile() || this.getActiveProfile();
      if (def) {
        return { ...def, _legacy_migrated: true };
      }
    }

    // 3. Exact Display Name match (case-insensitive)
    const matchingNames = this.profiles.filter(p => p.name && p.name.trim().toLowerCase() === cleanRef.toLowerCase());
    if (matchingNames.length === 1) {
      return matchingNames[0];
    }

    // 4. Exact Email match (if profile has email)
    const matchingEmails = this.profiles.filter(p => p.email && p.email.trim().toLowerCase() === cleanRef.toLowerCase());
    if (matchingEmails.length === 1) {
      return matchingEmails[0];
    }

    return null;
  }

  setActiveProfile(profileId) {
    const profile = this.resolveProfile(profileId);
    if (!profile) {
      throw new Error(`Profile not found: ${profileId}`);
    }
    this.activeProfileId = profile.id;
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

    if (updates.tier) {
      updates.tier = FlowProfileManager.normalizeTier(updates.tier);
      updates.max_download_resolution = FlowProfileManager.getMaxResolutionForTier(updates.tier);
    }

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
