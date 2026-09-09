/**
 * apps/capcut-v2/desktop/src/preload/preload.js
 * Preload security bridge.
 * Exposes strictly typed APIs on window.autoedit via contextBridge.
 * Completely shields Renderer from raw Electron, Node.js, and filesystem access.
 */
const { contextBridge, ipcRenderer } = require('electron');

let _lastPreloadAiKeyCall = null;

contextBridge.exposeInMainWorld('autoedit', {
  // Native File Dialogs & Archive Importer
  selectImages: () => ipcRenderer.invoke('dialog:select-images'),
  selectAudio: () => ipcRenderer.invoke('dialog:select-audio'),
  selectSrt: () => ipcRenderer.invoke('dialog:select-srt'),
  selectScript: () => ipcRenderer.invoke('dialog:select-script'),
  saveSrtDialog: (defaultName) => ipcRenderer.invoke('dialog:save-srt', defaultName),
  openFolder: (folderPath) => ipcRenderer.invoke('shell:open-folder', folderPath),
  processImportPaths: (paths) => ipcRenderer.invoke('importer:process-paths', { paths }),
  openFilesDialog: (options) => ipcRenderer.invoke('dialog:open-files', options),
  openAudioDialog: () => ipcRenderer.invoke('dialog:open-audio'),
  openDirectoryDialog: () => ipcRenderer.invoke('dialog:open-directory'),
  openDirectoriesDialog: () => ipcRenderer.invoke('dialog:open-directories'),
  openPath: (targetPath) => ipcRenderer.invoke('shell:open-path', targetPath),
  showItemInFolder: (targetPath) => ipcRenderer.invoke('shell:show-item-in-folder', targetPath),
  deleteDraftFolder: (targetPath) => ipcRenderer.invoke('fs:delete-draft', targetPath),
  checkForUpdates: (opts) => ipcRenderer.invoke('updater:check-update', opts),
  updater: {
    check: (opts) => ipcRenderer.invoke('updater:check-update', opts),
    download: () => ipcRenderer.invoke('updater:download-update'),
    install: () => ipcRenderer.invoke('updater:install-update'),
    getState: () => ipcRenderer.invoke('updater:get-state'),
    onStatusChanged: (callback) => {
      const listener = (_, data) => callback(data);
      ipcRenderer.on('updater:status-changed', listener);
      return () => ipcRenderer.removeListener('updater:status-changed', listener);
    },
    onProgress: (callback) => {
      const listener = (_, data) => callback(data);
      ipcRenderer.on('updater:progress', listener);
      return () => ipcRenderer.removeListener('updater:progress', listener);
    },
  },

  // AI Upscale Operations (GAP-12)
  runUpscale: (params) => ipcRenderer.invoke('upscale:process-images', params),
  onUpscaleProgress: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('upscale:progress', listener);
    return () => ipcRenderer.removeListener('upscale:progress', listener);
  },

  // Auth & Account Operations (GAP-09 & GAP-10)
  login: (credentials) => ipcRenderer.invoke('auth:login', credentials),
  logout: () => ipcRenderer.invoke('auth:logout'),
  getUser: () => ipcRenderer.invoke('auth:get-user'),
  getAccountState: () => ipcRenderer.invoke('auth:get-state'),
  startQuickLogin: () => ipcRenderer.invoke('auth:start-quick-login'),
  cancelQuickLogin: () => ipcRenderer.invoke('auth:cancel-quick-login'),
  getWalletBalance: () => ipcRenderer.invoke('wallet:get-balance'),
  getWalletState: () => ipcRenderer.invoke('wallet:get-state'),
  refreshWallet: () => ipcRenderer.invoke('wallet:refresh'),
  onAuthChanged: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('auth:changed', listener);
    return () => ipcRenderer.removeListener('auth:changed', listener);
  },
  onSessionExpired: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('auth:session-expired', listener);
    return () => ipcRenderer.removeListener('auth:session-expired', listener);
  },
  onWalletBalanceUpdated: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('wallet:balance-updated', listener);
    return () => ipcRenderer.removeListener('wallet:balance-updated', listener);
  },
  onWalletStateUpdated: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('wallet:state-updated', listener);
    return () => ipcRenderer.removeListener('wallet:state-updated', listener);
  },

  // Cloud Explorer Operations (Priority 4)
  cloud: {
    getSpaces: () => ipcRenderer.invoke('cloud:get-spaces'),
    getQuota: (spaceId) => ipcRenderer.invoke('cloud:get-quota', { spaceId }),
    listFiles: (params) => ipcRenderer.invoke('cloud:list-files', params),
    createFolder: (params) => ipcRenderer.invoke('cloud:create-folder', params),
    renameItem: (params) => ipcRenderer.invoke('cloud:rename-item', params),
    moveItem: (params) => ipcRenderer.invoke('cloud:move-item', params),
    trashItem: (params) => ipcRenderer.invoke('cloud:trash-item', params),
    listTrash: (params) => ipcRenderer.invoke('cloud:list-trash', params),
    restoreItem: (params) => ipcRenderer.invoke('cloud:restore-item', params),
    permanentDelete: (params) => ipcRenderer.invoke('cloud:permanent-delete', params),
    uploadFile: (params) => ipcRenderer.invoke('cloud:upload-file', params),
    cancelUpload: (params) => ipcRenderer.invoke('cloud:cancel-upload', params),
    downloadFile: (params) => ipcRenderer.invoke('cloud:download-file', params),
    cacheAndGetPath: (file) => ipcRenderer.invoke('cloud:cache-and-get-path', file),
    openItem: (file) => ipcRenderer.invoke('cloud:open-item', file),
    selectLocalUploadFiles: () => ipcRenderer.invoke('cloud:select-local-upload-files'),
    createShare: (params) => ipcRenderer.invoke('cloud:create-share', params),
    getItemShares: (params) => ipcRenderer.invoke('cloud:get-item-shares', params),
    revokeShare: (params) => ipcRenderer.invoke('cloud:revoke-share', params),
    onUploadProgress: (callback) => {
      const listener = (_, data) => callback(data);
      ipcRenderer.on('cloud:upload-progress', listener);
      return () => ipcRenderer.removeListener('cloud:upload-progress', listener);
    },
    onDownloadProgress: (callback) => {
      const listener = (_, data) => callback(data);
      ipcRenderer.on('cloud:download-progress', listener);
      return () => ipcRenderer.removeListener('cloud:download-progress', listener);
    },
    materializeBundle: (spaceId, folderId, folderName) =>
      ipcRenderer.invoke('cloud:materialize-bundle', { spaceId, folderId, folderName }),
  },

  // Team & Workspace Operations (Priority 6)
  workspace: {
    sync: () => ipcRenderer.invoke('workspace:sync'),
    getActive: () => ipcRenderer.invoke('workspace:get-active'),
    switch: (workspaceId) => ipcRenderer.invoke('workspace:switch', { workspaceId }),
  },
  team: {
    create: (name) => ipcRenderer.invoke('team:create', { name }),
    get: (teamId) => ipcRenderer.invoke('team:get', { teamId }),
    listMembers: (teamId) => ipcRenderer.invoke('team:list-members', { teamId }),
    createInvitation: (teamId, offeredRole, recipientEmail) =>
      ipcRenderer.invoke('team:create-invitation', { teamId, offeredRole, recipientEmail }),
    acceptInvitation: (inviteToken) => ipcRenderer.invoke('team:accept-invitation', { inviteToken }),
    changeRole: (teamId, userId, role) => ipcRenderer.invoke('team:change-role', { teamId, userId, role }),
    removeMember: (teamId, userId) => ipcRenderer.invoke('team:remove-member', { teamId, userId }),
    listSeats: (teamId) => ipcRenderer.invoke('team:list-seats', { teamId }),
    activateSeat: (teamId, deviceAlias, platform) =>
      ipcRenderer.invoke('team:activate-seat', { teamId, deviceAlias, platform }),
    revokeSeat: (teamId, seatId) => ipcRenderer.invoke('team:revoke-seat', { teamId, seatId }),
    delete: (teamId) => ipcRenderer.invoke('team:delete', { teamId }),
  },

  // Scoped AI Access Keys (Phase 1)
  aiKeys: {
    list: () => ipcRenderer.invoke('ai:list-keys'),
    create: (params) => {
      _lastPreloadAiKeyCall = { timestamp: Date.now(), params };
      return ipcRenderer.invoke('ai:create-key', params);
    },
    revoke: (keyId) => ipcRenderer.invoke('ai:revoke-key', { keyId }),
    getLastCreateCall: () => _lastPreloadAiKeyCall,
  },

  // Input Bundle Engine (Phase 2)
  bundle: {
    validateLocal: (bundleDir) => ipcRenderer.invoke('bundle:validate-local', { bundleDir }),
    createTemplate: (targetDir, config) => ipcRenderer.invoke('bundle:create-template', { targetDir, config }),
    parseFilename: (filename) => ipcRenderer.invoke('bundle:parse-filename', { filename }),
  },

  // Pipeline Queue V2 (Phase 3)
  pipeline: {
    enqueue: (bundleDir, options) => ipcRenderer.invoke('pipeline:enqueue', { bundleDir, options }),
    listJobs: () => ipcRenderer.invoke('pipeline:list-jobs'),
    getJob: (jobId) => ipcRenderer.invoke('pipeline:get-job', { jobId }),
    pause: (jobId) => ipcRenderer.invoke('pipeline:pause', { jobId }),
    resume: (jobId) => ipcRenderer.invoke('pipeline:resume', { jobId }),
    cancel: (jobId) => ipcRenderer.invoke('pipeline:cancel', { jobId }),
    retryScene: (jobId, sceneId) => ipcRenderer.invoke('pipeline:retry-scene', { jobId, sceneId }),
    approveCharacter: (jobId, characterId) => ipcRenderer.invoke('pipeline:approve-character', { jobId, characterId }),
    approveAllCharacters: (jobId) => ipcRenderer.invoke('pipeline:approve-all-characters', { jobId }),
    regenerateCharacter: (jobId, characterId) => ipcRenderer.invoke('pipeline:regenerate-character', { jobId, characterId }),
    getActiveSummary: () => ipcRenderer.invoke('pipeline:get-active-summary'),
    clearCompleted: () => ipcRenderer.invoke('pipeline:clear-completed'),
    runAll: () => ipcRenderer.invoke('pipeline:run-all'),
    updateBundleDir: (jobId, newBundleDir) => ipcRenderer.invoke('pipeline:update-bundle-dir', { jobId, newBundleDir }),
    deleteJob: (jobId) => ipcRenderer.invoke('pipeline:delete-job', { jobId }),
    onProgress: (callback) => ipcRenderer.on('pipeline:job-progress', (_, data) => callback(data)),
    onStateChanged: (callback) => ipcRenderer.on('pipeline:state-changed', (_, data) => callback(data)),
    onCharacterApprovalRequired: (callback) => ipcRenderer.on('pipeline:character-approval-required', (_, data) => callback(data)),
    onCompleted: (callback) => ipcRenderer.on('pipeline:completed', (_, data) => callback(data)),
    onFailed: (callback) => ipcRenderer.on('pipeline:failed', (_, data) => callback(data)),
  },

  // Google Flow Embedded Browser (Phase 4)
  flow: {
    getProfiles: () => ipcRenderer.invoke('flow:get-profiles'),
    createProfile: (name) => ipcRenderer.invoke('flow:create-profile', { name }),
    switchProfile: (profileId) => ipcRenderer.invoke('flow:switch-profile', { profileId }),
    deleteProfile: (profileId) => ipcRenderer.invoke('flow:delete-profile', { profileId }),
    getStatus: () => ipcRenderer.invoke('flow:get-status'),
    setMode: (mode) => ipcRenderer.invoke('flow:set-mode', { mode }),
    takeover: () => ipcRenderer.invoke('flow:takeover'),
    resumeAuto: () => ipcRenderer.invoke('flow:resume-auto'),
    reload: () => ipcRenderer.invoke('flow:reload'),
    navigateFlow: () => ipcRenderer.invoke('flow:navigate-flow'),
    updateViewBounds: (bounds) => ipcRenderer.invoke('flow:view-bounds', { bounds }),
    hideView: () => ipcRenderer.invoke('flow:view-hide'),
    onStatusUpdated: (callback) => ipcRenderer.on('flow:status-updated', (_, data) => callback(data)),
    onModeChanged: (callback) => ipcRenderer.on('flow:mode-changed', (_, data) => callback(data)),
  },

  // Python Sidecar Operations
  detectCapCut: () => ipcRenderer.invoke('sidecar:detect-capcut'),
  getPresets: () => ipcRenderer.invoke('sidecar:get-presets'),
  saveCustomPreset: (presetData) => ipcRenderer.invoke('sidecar:save-custom-preset', presetData),
  deleteCustomPreset: (presetId) => ipcRenderer.invoke('sidecar:delete-custom-preset', { presetId }),
  generateProject: (projectConfig) => ipcRenderer.invoke('sidecar:generate-project', projectConfig),
  openCapCut: (draftPath) => ipcRenderer.invoke('sidecar:open-capcut', { draftPath }),
  getAppInfo: () => ipcRenderer.invoke('sidecar:get-app-info'),
  getDiagnostics: () => ipcRenderer.invoke('sidecar:get-diagnostics'),

  // Script-to-SRT Subtitle Alignment (Phase 5C)
  generateSrtFromScript: (params) => ipcRenderer.invoke('sidecar:generate-srt-from-script', params),
  getSubtitleAlignmentStatus: () => ipcRenderer.invoke('sidecar:get-subtitle-alignment-status'),
  cancelSubtitleAlignment: () => ipcRenderer.invoke('sidecar:cancel-subtitle-alignment'),
  exportSrt: (params) => ipcRenderer.invoke('sidecar:export-srt', params),

  // Project Build Queue (Queue A - Wave 1)
  enqueueBuildJob: (params) => ipcRenderer.invoke('sidecar:enqueue-build-job', params),
  getBuildQueueState: () => ipcRenderer.invoke('sidecar:get-build-queue-state'),
  buildProjectJob: (params) => ipcRenderer.invoke('sidecar:build-project-job', params),
  buildAllProjects: () => ipcRenderer.invoke('sidecar:build-all-projects'),
  cancelBuildJob: (params) => ipcRenderer.invoke('sidecar:cancel-build-job', params),
  retryBuildJob: (params) => ipcRenderer.invoke('sidecar:retry-build-job', params),
  removeBuildJob: (params) => ipcRenderer.invoke('sidecar:remove-build-job', params),
  clearCompletedBuildJobs: () => ipcRenderer.invoke('sidecar:clear-completed-build-jobs'),

  // Render Automation & Queue (Phase 5E)
  callSidecar: (method, params) => ipcRenderer.invoke('sidecar:call', { method, params }),
  renderNow: (params) => ipcRenderer.invoke('sidecar:render-now', params),
  enqueueRender: (params) => ipcRenderer.invoke('sidecar:enqueue-render', params),
  getRenderQueueState: () => ipcRenderer.invoke('sidecar:get-render-queue-state'),
  controlRenderQueue: (params) => ipcRenderer.invoke('sidecar:control-render-queue', params),
  getRenderProfile: (params) => ipcRenderer.invoke('sidecar:get-render-profile', params),

  // Local Store Persistence
  getStoredData: (key) => ipcRenderer.invoke('store:get-data', key),
  setStoredData: (key, data) => ipcRenderer.invoke('store:set-data', { key, data }),

  // Commercial License & Security APIs (Phase 4)
  getLicenseStatus: () => ipcRenderer.invoke('license:get-status'),
  activateLicense: (licenseKey) => ipcRenderer.invoke('license:activate', { licenseKey }),
  deactivateDevice: () => ipcRenderer.invoke('license:deactivate'),
  onLicenseChanged: (callback) => {
    const listener = (_, data) => callback(data);
    ipcRenderer.on('license:status-changed', listener);
    return () => ipcRenderer.removeListener('license:status-changed', listener);
  },

  // Event Listeners
  onProgress: (callback) => {
    const handler = (_, payload) => {
      if (payload && payload.event === 'progress') {
        callback(payload.data);
      }
    };
    ipcRenderer.on('autoedit:event', handler);
    return () => {
      ipcRenderer.removeListener('autoedit:event', handler);
    };
  },
  onRenderQueueUpdate: (callback) => {
    const handler = (_, payload) => {
      if (payload && payload.event === 'render_queue_update') {
        callback(payload.data);
      }
    };
    ipcRenderer.on('autoedit:event', handler);
    return () => {
      ipcRenderer.removeListener('autoedit:event', handler);
    };
  },
  onBuildQueueUpdate: (callback) => {
    const handler = (_, payload) => {
      if (payload && payload.event === 'build_queue_update') {
        callback(payload.data);
      }
    };
    ipcRenderer.on('autoedit:event', handler);
    return () => {
      ipcRenderer.removeListener('autoedit:event', handler);
    };
  },
  onEvent: (callback) => {
    const handler = (_, payload) => {
      if (payload) {
        callback(payload.event, payload.data);
      }
    };
    ipcRenderer.on('autoedit:event', handler);
    return () => {
      ipcRenderer.removeListener('autoedit:event', handler);
    };
  },
});
