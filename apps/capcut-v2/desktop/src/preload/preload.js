/**
 * apps/capcut-v2/desktop/src/preload/preload.js
 * Preload security bridge.
 * Exposes strictly typed APIs on window.autoedit via contextBridge.
 * Completely shields Renderer from raw Electron, Node.js, and filesystem access.
 */
const { contextBridge, ipcRenderer } = require('electron');

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
  openPath: (targetPath) => ipcRenderer.invoke('shell:open-path', targetPath),
  showItemInFolder: (targetPath) => ipcRenderer.invoke('shell:show-item-in-folder', targetPath),
  deleteDraftFolder: (targetPath) => ipcRenderer.invoke('fs:delete-draft', targetPath),
  exportDiagnosticBundle: () => ipcRenderer.invoke('diagnostics:export-bundle'),
  checkForUpdates: () => ipcRenderer.invoke('updater:check-update'),

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
  getWalletBalance: () => ipcRenderer.invoke('wallet:get-balance'),

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
