/**
 * apps/capcut-v2/desktop/src/renderer/app.js
 * 2TOOLNE AutoEdit for CapCut - Desktop Renderer Controller
 * Modern Dashboard + FFmpeg Subtitle Studio + Queue Manager + AI Upscale
 */

// -----------------------------------------------------------------------------
// Application State
// -----------------------------------------------------------------------------
const state = {
  currentTab: 'studio',
  projectName: '',
  aspectRatio: '9:16',
  fps: 60,
  mediaList: [], // Array of absolute image file paths
  audioPath: null,
  scriptText: '',
  srtContent: '',
  subtitleWorkflowMode: 'fa', // 'fa' (Forced Alignment) or 'stt' (AutoSub)
  motionWeights: {
    zoom_in: 25,
    zoom_out: 25,
    pan: 25,
    tilt: 25,
  },
  autoUpscale: false,

  upscaleFiles: [],
  lastUpscaleOutputDir: null,
  queue: [], // Array of job objects { id, name, ratio, images, audio, script, srt, status, progress, error, draftDir }
  isQueueRunning: false,
  projects: [], // Installed drafts history
  tokenBalance: 0,
  currentUser: null,
  licenseInfo: null,
  capcutInfo: null,
  buildQueue: {
    status: 'IDLE',
    jobs: [],
    totalJobs: 0,
    activeJob: null,
  },
  renderQueue: {
    status: 'IDLE',
    jobs: [],
    totalJobs: 0,
    activeJob: null,
  },
  lastJobStatuses: {},
  settings: {
    renderOutputDir: '',
  },
  cloud: {
    spaces: [],
    currentSpaceId: null,
    currentFolderId: null,
    breadcrumbs: [{ id: null, name: 'Cloud Cá Nhân' }],
    files: [],
    folders: [],
    trash: [],
    isTrashOpen: false,
    quota: null,
    searchQuery: '',
    sortBy: 'date_desc',
    activeUploads: new Map(),
    picker: {
      active: false,
      mode: 'SELECT_IMAGES',
      selectedFiles: [],
      currentFolderId: null,
      breadcrumbs: [{ id: null, name: 'Cloud Cá Nhân' }],
      resolve: null,
      reject: null,
    },
  },
};

// -----------------------------------------------------------------------------
// Utility Helpers
// -----------------------------------------------------------------------------
function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapePath(str) {
  if (!str) return '';
  return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ═══════════════════════════════════════════════════════════════════════════
// 2TOOLNE GLOBAL NOTIFICATION SEMANTIC HIERARCHY
// Canonical Semantic Levels: SUCCESS | INFO | WARNING | ERROR | PROGRESS
// ═══════════════════════════════════════════════════════════════════════════

const TOAST_SEMANTICS = {
  success: {
    level: 'success',
    priority: 2,
    icon: 'circle-check',
    defaultDuration: 3000,
    className: 'toast-success',
    spin: false,
  },
  info: {
    level: 'info',
    priority: 1,
    icon: 'info',
    defaultDuration: 3500,
    className: 'toast-info',
    spin: false,
  },
  warning: {
    level: 'warning',
    priority: 4,
    icon: 'triangle-alert',
    defaultDuration: 4500,
    className: 'toast-warning',
    spin: false,
  },
  error: {
    level: 'error',
    priority: 5,
    icon: 'circle-x',
    defaultDuration: 6500,
    className: 'toast-error',
    spin: false,
  },
  progress: {
    level: 'progress',
    priority: 3,
    icon: 'loader-circle',
    defaultDuration: 0,
    className: 'toast-progress',
    spin: true,
  },
};

function sanitizeToastMessage(msg) {
  if (msg == null) return '';
  let str = typeof msg === 'object' ? (msg.message || JSON.stringify(msg)) : String(msg);
  
  // Guardrail: Never expose raw SQLSTATE, database column names, internal stack traces
  if (/SQLSTATE\[\w+\]|Integrity constraint violation|Duplicate entry/i.test(str)) {
    return 'Dữ liệu đã tồn tại hoặc có xung đột trên hệ thống. Vui lòng kiểm tra lại.';
  }
  if (/ECONNREFUSED|ENOTFOUND/i.test(str)) {
    return 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra kết nối mạng.';
  }
  return str;
}

const _recentToasts = new Map();

function dismissToastElement(el, immediate = false) {
  if (!el || el._isDismissing) return;
  el._isDismissing = true;
  if (el._dismissTimer) {
    clearTimeout(el._dismissTimer);
    el._dismissTimer = null;
  }
  if (immediate) {
    el.remove();
  } else {
    el.style.opacity = '0';
    el.style.transform = 'translateY(8px) scale(0.96)';
    setTimeout(() => {
      if (el.parentNode) el.remove();
    }, 280);
  }
}

function refreshIcons(container) {
  if (window.lucide && typeof window.lucide.createIcons === 'function') {
    try {
      window.lucide.createIcons(container ? { root: container } : undefined);
    } catch (e) {
      console.warn('refreshIcons error:', e);
    }
  }
}

function showToast(message, type = 'info', options = null) {
  const cleanMessage = sanitizeToastMessage(message);
  if (!cleanMessage) return null;

  const normalizedType = String(type || 'info').toLowerCase().trim();
  const config = TOAST_SEMANTICS[normalizedType] || TOAST_SEMANTICS.info;

  let toastId = null;
  let detail = '';
  let duration = null;

  if (typeof options === 'number') {
    duration = options;
  } else if (options && typeof options === 'object') {
    if (options.id) toastId = String(options.id);
    if (options.detail) detail = String(options.detail);
    if (typeof options.duration === 'number') duration = options.duration;
  }

  if (duration === null || duration === undefined) {
    duration = config.defaultDuration;
  }

  // Deduplication guard: Suppress identical toasts within 1.8s (unless it is a progress or targeted id)
  const now = Date.now();
  if (config.level !== 'progress' && !toastId) {
    const dedupeKey = `${config.level}:${cleanMessage}`;
    const lastShown = _recentToasts.get(dedupeKey);
    if (lastShown && (now - lastShown) < 1800) {
      return null;
    }
    _recentToasts.set(dedupeKey, now);
    if (_recentToasts.size > 60) {
      for (const [k, v] of _recentToasts.entries()) {
        if (now - v > 6000) _recentToasts.delete(k);
      }
    }
  }

  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);
  }

  // In-place morphing support (e.g. progress -> success transition with matching id)
  let existingToast = toastId ? container.querySelector(`[data-toast-id="${toastId}"]`) : null;
  if (existingToast) {
    if (existingToast._dismissTimer) {
      clearTimeout(existingToast._dismissTimer);
      existingToast._dismissTimer = null;
    }
    existingToast._isDismissing = false;
    existingToast.style.opacity = '1';
    existingToast.style.transform = 'none';

    // Remove previous level classes
    existingToast.className = `toolne-toast ${config.className}`;
    existingToast.dataset.priority = String(config.priority);

    existingToast.innerHTML = `
      <div class="toolne-toast-icon">
        <i data-lucide="${config.icon}" class="${config.spin ? 'toolne-toast-spin' : ''}"></i>
      </div>
      <div class="toolne-toast-body">
        <div class="toolne-toast-msg">${escapeHtml(cleanMessage)}</div>
        ${detail ? `<div class="toolne-toast-detail">${escapeHtml(detail)}</div>` : ''}
      </div>
    `;
    refreshIcons(existingToast);

    if (duration > 0) {
      existingToast._dismissTimer = setTimeout(() => {
        dismissToastElement(existingToast);
      }, duration);
    }
    return existingToast;
  }

  // Enforce visible toast capacity (max 4). Do NOT dismiss higher priority toasts (like ERROR)
  const MAX_VISIBLE = 4;
  const currentToasts = Array.from(container.querySelectorAll('.toolne-toast'));
  if (currentToasts.length >= MAX_VISIBLE) {
    let victim = null;
    let lowestPrio = 999;
    for (const t of currentToasts) {
      if (t._isDismissing) continue;
      const p = parseInt(t.dataset.priority || '1', 10);
      if (p < lowestPrio) {
        lowestPrio = p;
        victim = t;
      }
    }
    if (victim) {
      dismissToastElement(victim, true);
    }
  }

  const toast = document.createElement('div');
  toast.className = `toolne-toast ${config.className}`;
  if (toastId) toast.dataset.toastId = toastId;
  toast.dataset.priority = String(config.priority);

  toast.innerHTML = `
    <div class="toolne-toast-icon">
      <i data-lucide="${config.icon}" class="${config.spin ? 'toolne-toast-spin' : ''}"></i>
    </div>
    <div class="toolne-toast-body">
      <div class="toolne-toast-msg">${escapeHtml(cleanMessage)}</div>
      ${detail ? `<div class="toolne-toast-detail">${escapeHtml(detail)}</div>` : ''}
    </div>
  `;
  refreshIcons(toast);

  // Click-to-dismiss
  toast.addEventListener('click', () => {
    dismissToastElement(toast);
  });

  container.appendChild(toast);

  if (duration > 0) {
    toast._dismissTimer = setTimeout(() => {
      dismissToastElement(toast);
    }, duration);
  }

  return toast;
}


function generateDefaultProjectName() {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const HH = pad(now.getHours());
  const dd = pad(now.getDate());
  const MM = pad(now.getMonth() + 1);
  const yy = String(now.getFullYear()).slice(-2);
  return `2toolne_${HH}${dd}${MM}${yy}`;
}

function countSrtCues(srt) {
  if (!srt || !srt.trim()) return 0;
  const blocks = srt.trim().split(/\n\s*\n/);
  return blocks.filter((b) => b.includes('-->')).length;
}

// -----------------------------------------------------------------------------
// DOM Elements
// -----------------------------------------------------------------------------
const DOM = {
  // Navigation & Header
  navBtns: document.querySelectorAll('.nav-btn'),
  viewPanes: document.querySelectorAll('.view-pane'),
  viewTitle: document.getElementById('currentViewTitle'),
  viewSub: document.getElementById('currentViewSub'),
  tokenBalance: document.getElementById('tokenBalance'),
  lblWalletContext: document.getElementById('lblWalletContext'),
  accWalletLabel: document.getElementById('accWalletLabel'),
  licenseDot: document.getElementById('licenseDot'),
  licenseText: document.getElementById('licenseText'),
  capcutDot: document.getElementById('capcutDot'),
  capcutText: document.getElementById('capcutText'),
  badgeLicense: document.getElementById('badgeLicenseStatus'),
  badgeCapcut: document.getElementById('badgeCapcutStatus'),
  btnQuickNewProject: document.getElementById('btnQuickNewProject'),
  queueBadge: document.getElementById('queueBadge'),

  // Column 1: Config & Media
  inpProjectName: document.getElementById('inpProjectName'),
  selAspectRatio: document.getElementById('selAspectRatio'),
  selFps: document.getElementById('selFps'),
  mediaDropZone: document.getElementById('mediaDropZone'),
  btnBrowseFiles: document.getElementById('btnBrowseFiles'),
  btnBrowseFolder: document.getElementById('btnBrowseFolder'),
  chkAutoUpscale: document.getElementById('chkAutoUpscale'),
  mediaCount: document.getElementById('mediaCount'),
  btnClearMedia: document.getElementById('btnClearMedia'),
  mediaGrid: document.getElementById('mediaGrid'),

  // Column 2: Subtitles & Script
  tabModeFA: document.getElementById('tabModeFA'),
  tabModeSTT: document.getElementById('tabModeSTT'),
  inpScriptText: document.getElementById('inpScriptText'),
  btnLoadScriptFile: document.getElementById('btnLoadScriptFile'),
  btnSelectAudioFile: document.getElementById('btnSelectAudioFile'),
  btnRemoveAudio: document.getElementById('btnRemoveAudio'),
  audioFileName: document.getElementById('audioFileName'),
  btnStartAlign: document.getElementById('btnStartAlign'),
  alignProgressWrap: document.getElementById('alignProgressWrap'),
  alignProgressMsg: document.getElementById('alignProgressMsg'),
  alignProgressPct: document.getElementById('alignProgressPct'),
  alignProgressFill: document.getElementById('alignProgressFill'),
  outSrtContent: document.getElementById('outSrtContent'),
  srtStatusLine: document.getElementById('srtStatusLine'),
  srtLineCount: document.getElementById('srtLineCount'),
  btnDownloadSrt: document.getElementById('btnDownloadSrt'),
  btnClearSrt: document.getElementById('btnClearSrt'),

  // Column 3: Motion & Export
  selPresetStyle: document.getElementById('selPresetStyle'),
  slZoomIn: document.getElementById('slZoomIn'),
  slZoomOut: document.getElementById('slZoomOut'),
  slPan: document.getElementById('slPan'),
  slTilt: document.getElementById('slTilt'),
  valZoomIn: document.getElementById('valZoomIn'),
  valZoomOut: document.getElementById('valZoomOut'),
  valPan: document.getElementById('valPan'),
  valTilt: document.getElementById('valTilt'),
  inpSubFontSize: document.getElementById('inpSubFontSize'),
  inpSubPosY: document.getElementById('inpSubPosY'),
  inpDefaultDuration: document.getElementById('inpDefaultDuration'),
  btnGenerateProject: document.getElementById('btnGenerateProject'),
  btnAddToQueue: document.getElementById('btnAddToQueue'),

  // Queue View (Sub-tabs, Build Queue, Render Queue)
  tabSubQueueBuild: document.getElementById('tabSubQueueBuild'),
  tabSubQueueRender: document.getElementById('tabSubQueueRender'),
  subpaneBuildQueue: document.getElementById('subpane-build-queue'),
  subpaneRenderQueue: document.getElementById('subpane-render-queue'),
  buildQueueStatusBadge: document.getElementById('buildQueueStatusBadge'),
  btnBuildAllProjects: document.getElementById('btnBuildAllProjects'),
  btnStopBuildQueue: document.getElementById('btnStopBuildQueue'),
  btnClearBuildQueue: document.getElementById('btnClearBuildQueue'),
  buildQueueTableBody: document.getElementById('buildQueueTableBody'),

  queueTableBody: document.getElementById('queueTableBody'),
  queueStatusBadge: document.getElementById('queueStatusBadge'),
  btnStartQueue: document.getElementById('btnStartQueue'),
  btnPauseQueue: document.getElementById('btnPauseQueue'),
  btnStopAfterCurrent: document.getElementById('btnStopAfterCurrent'),
  btnClearQueue: document.getElementById('btnClearQueue'),

  // Projects View
  projectsGrid: document.getElementById('projectsGrid'),
  btnRefreshProjects: document.getElementById('btnRefreshProjects'),
  inpProjectSearch: document.getElementById('inpProjectSearch'),
  selProjectSort: document.getElementById('selProjectSort'),

  // Upscale View
  upscaleDropZone: document.getElementById('upscaleDropZone'),
  btnBrowseUpscale: document.getElementById('btnBrowseUpscale'),
  selUpscaleModel: document.getElementById('selUpscaleModel'),
  btnRunUpscale: document.getElementById('btnRunUpscale'),
  upscaleFilesContainer: document.getElementById('upscaleFilesContainer'),
  upscaleCount: document.getElementById('upscaleCount'),
  upscaleFileList: document.getElementById('upscaleFileList'),
  btnClearUpscale: document.getElementById('btnClearUpscale'),
  upscaleProgressWrap: document.getElementById('upscaleProgressWrap'),
  upscaleProgressPct: document.getElementById('upscaleProgressPct'),
  upscaleProgressFill: document.getElementById('upscaleProgressFill'),
  upscaleProgressMsg: document.getElementById('upscaleProgressMsg'),
  upscaleResultBox: document.getElementById('upscaleResultBox'),
  upscaleResultPath: document.getElementById('upscaleResultPath'),
  btnOpenUpscaleDir: document.getElementById('btnOpenUpscaleDir'),
  lblUpscaleTokenHint: document.getElementById('lblUpscaleTokenHint'),

  // Account View (Separated Account & Machine License)
  accUserEmail: document.getElementById('accUserEmail'),
  accLoginStatus: document.getElementById('accLoginStatus'),
  accMaskedKey: document.getElementById('accMaskedKey'),
  accStatus: document.getElementById('accLicenseStatus'),
  accLicenseStatus: document.getElementById('accLicenseStatus'),
  accLicenseTier: document.getElementById('accLicenseTier'),
  accDeviceId: document.getElementById('accDeviceId'),
  accLicenseExpiry: document.getElementById('accLicenseExpiry'),
  accTokenCount: document.getElementById('accTokenCount'),
  btnRefreshWallet: document.getElementById('btnRefreshWallet'),
  btnTopupToken: document.getElementById('btnTopupToken'),
  btnOpenLoginModal: document.getElementById('btnOpenLoginModal'),
  btnLogoutAccount: document.getElementById('btnLogoutAccount'),
  btnOpenActivateModal: document.getElementById('btnOpenActivateModal'),
  btnDeactivateLicense: document.getElementById('btnDeactivateLicense'),

  // Settings View
  cfgCapcutVer: document.getElementById('cfgCapcutVer'),
  cfgCapcutPath: document.getElementById('cfgCapcutPath'),
  chkAutoOpenCapcut: document.getElementById('chkAutoOpenCapcut'),
  inpRenderOutputDir: document.getElementById('inpRenderOutputDir'),
  btnBrowseRenderOutputDir: document.getElementById('btnBrowseRenderOutputDir'),
  btnOpenRenderOutputDir: document.getElementById('btnOpenRenderOutputDir'),
  btnResetRenderOutputDir: document.getElementById('btnResetRenderOutputDir'),
  btnExportDiagnostics: document.getElementById('btnExportDiagnostics'),
  txtAppCurrentVersion: document.getElementById('txtAppCurrentVersion'),
  txtUpdateStatus: document.getElementById('txtUpdateStatus'),
  btnCheckUpdate: document.getElementById('btnCheckUpdate'),
  boxUpdateProgress: document.getElementById('boxUpdateProgress'),
  barUpdateProgress: document.getElementById('barUpdateProgress'),
  txtUpdateProgressState: document.getElementById('txtUpdateProgressState'),
  txtUpdateSpeed: document.getElementById('txtUpdateSpeed'),
  boxUpdateActions: document.getElementById('boxUpdateActions'),
  btnDownloadUpdate: document.getElementById('btnDownloadUpdate'),
  btnInstallUpdate: document.getElementById('btnInstallUpdate'),
  btnDismissUpdate: document.getElementById('btnDismissUpdate'),

  // Modals
  modalLicense: document.getElementById('modalLicense'),
  btnCloseModalLicense: document.getElementById('btnCloseModalLicense'),
  btnCancelModalLicense: document.getElementById('btnCancelModalLicense'),
  inpLicenseKeyModal: document.getElementById('inpLicenseKeyModal'),
  btnSubmitModalLicense: document.getElementById('btnSubmitModalLicense'),
  licenseModalError: document.getElementById('licenseModalError'),

  modalProgress: document.getElementById('modalProgress'),
  btnCloseModalProgress: document.getElementById('btnCloseModalProgress'),
  btnCancelProgressModal: document.getElementById('btnCancelProgressModal'),
  progressModalTitle: document.getElementById('progressModalTitle'),
  progressModalStage: document.getElementById('progressModalStage'),
  progressModalPct: document.getElementById('progressModalPct'),
  progressModalFill: document.getElementById('progressModalFill'),
  progressModalDetail: document.getElementById('progressModalDetail'),

  modalAlert: document.getElementById('modalAlert'),
  btnCloseModalAlert: document.getElementById('btnCloseModalAlert'),
  btnOkModalAlert: document.getElementById('btnOkModalAlert'),
  alertModalTitle: document.getElementById('alertModalTitle'),
  alertModalMessage: document.getElementById('alertModalMessage'),

  modalMediaPreview: document.getElementById('modalMediaPreview'),
  mediaPreviewTitle: document.getElementById('mediaPreviewTitle'),
  previewSingleImage: document.getElementById('previewSingleImage'),
  mediaPreviewDimensions: document.getElementById('mediaPreviewDimensions'),
  btnCloseModalMediaPreview: document.getElementById('btnCloseModalMediaPreview'),
  btnOkModalMediaPreview: document.getElementById('btnOkModalMediaPreview'),

  // Modal 5: Render Result Modal
  modalRenderResult: document.getElementById('modalRenderResult'),
  renderResultTitle: document.getElementById('renderResultTitle'),
  btnCloseModalRenderResult: document.getElementById('btnCloseModalRenderResult'),
  btnCloseRenderResultModal: document.getElementById('btnCloseRenderResultModal'),
  renderResultSuccessContent: document.getElementById('renderResultSuccessContent'),
  renderResultProjectName: document.getElementById('renderResultProjectName'),
  renderResultOutputPath: document.getElementById('renderResultOutputPath'),
  wrapRenderDuration: document.getElementById('wrapRenderDuration'),
  renderResultDuration: document.getElementById('renderResultDuration'),
  renderResultErrorContent: document.getElementById('renderResultErrorContent'),
  renderResultErrorProject: document.getElementById('renderResultErrorProject'),
  renderResultErrorMessage: document.getElementById('renderResultErrorMessage'),
  renderResultErrorDetails: document.getElementById('renderResultErrorDetails'),
  renderResultSuccessActions: document.getElementById('renderResultSuccessActions'),
  btnOpenRenderedFile: document.getElementById('btnOpenRenderedFile'),
  btnOpenRenderedFolder: document.getElementById('btnOpenRenderedFolder'),
  renderResultErrorActions: document.getElementById('renderResultErrorActions'),
  btnRetryRenderJobModal: document.getElementById('btnRetryRenderJobModal'),

  // Modal 6: Delete Project Modal
  modalDeleteProject: document.getElementById('modalDeleteProject'),
  btnCloseModalDeleteProject: document.getElementById('btnCloseModalDeleteProject'),
  btnCancelDeleteProject: document.getElementById('btnCancelDeleteProject'),
  btnConfirmDeleteProject: document.getElementById('btnConfirmDeleteProject'),
  deleteProjectTargetName: document.getElementById('deleteProjectTargetName'),
  chkDeleteDraftFolder: document.getElementById('chkDeleteDraftFolder'),

  // Modal 7: Login Modal (Browser Quick Login & Direct Form)
  modalLogin: document.getElementById('modalLogin'),
  btnCloseModalLogin: document.getElementById('btnCloseModalLogin'),
  btnCancelModalLogin: document.getElementById('btnCancelModalLogin'),
  quickLoginPrompt: document.getElementById('quickLoginPrompt'),
  quickLoginWaiting: document.getElementById('quickLoginWaiting'),
  btnStartQuickLogin: document.getElementById('btnStartQuickLogin'),
  btnCancelQuickLoginAction: document.getElementById('btnCancelQuickLoginAction'),
  toggleManualLogin: document.getElementById('toggleManualLogin'),
  manualLoginForm: document.getElementById('manualLoginForm'),
  inpLoginEmail: document.getElementById('inpLoginEmail'),
  inpLoginPassword: document.getElementById('inpLoginPassword'),
  btnSubmitModalLogin: document.getElementById('btnSubmitModalLogin'),
  loginModalError: document.getElementById('loginModalError'),

  // Missing Images Sequence (001-xxx)
  missingImagesBanner: document.getElementById('missingImagesBanner'),
  missingImagesText: document.getElementById('missingImagesText'),
  btnOpenMissingModal: document.getElementById('btnOpenMissingModal'),
  modalMissingImages: document.getElementById('modalMissingImages'),
  btnCloseModalMissingImages: document.getElementById('btnCloseModalMissingImages'),
  btnCancelModalMissing: document.getElementById('btnCancelModalMissing'),
  btnApplyMissingOption: document.getElementById('btnApplyMissingOption'),
  missingIndicesDetails: document.getElementById('missingIndicesDetails'),
  selAsrEngine: document.getElementById('selAsrEngine'),
  selAppLanguage: document.getElementById('selAppLanguage'),

  // Cloud Explorer & Studio Integration (Priority 4)
  btnBrowseCloudImages: document.getElementById('btnBrowseCloudImages'),
  btnBrowseCloudAudio: document.getElementById('btnBrowseCloudAudio'),
  btnUploadRenderToCloud: document.getElementById('btnUploadRenderToCloud'),

  cloudLockedState: document.getElementById('cloudLockedState'),
  btnCloudLoginPrompt: document.getElementById('btnCloudLoginPrompt'),
  cloudMainState: document.getElementById('cloudMainState'),
  selCloudSpace: document.getElementById('selCloudSpace'),
  btnRefreshCloud: document.getElementById('btnRefreshCloud'),
  cloudQuotaBox: document.getElementById('cloudQuotaBox'),
  cloudQuotaUsed: document.getElementById('cloudQuotaUsed'),
  cloudQuotaTotal: document.getElementById('cloudQuotaTotal'),
  cloudQuotaPct: document.getElementById('cloudQuotaPct'),
  cloudQuotaFill: document.getElementById('cloudQuotaFill'),
  btnCloudToggleTrash: document.getElementById('btnCloudToggleTrash'),
  cloudTrashCount: document.getElementById('cloudTrashCount'),
  cloudNormalView: document.getElementById('cloudNormalView'),
  cloudBreadcrumbs: document.getElementById('cloudBreadcrumbs'),
  inpCloudSearch: document.getElementById('inpCloudSearch'),
  btnClearCloudSearch: document.getElementById('btnClearCloudSearch'),
  selCloudSort: document.getElementById('selCloudSort'),
  btnCloudNewFolder: document.getElementById('btnCloudNewFolder'),
  btnCloudUploadAction: document.getElementById('btnCloudUploadAction'),
  cloudDropZone: document.getElementById('cloudDropZone'),
  cloudTableBody: document.getElementById('cloudTableBody'),
  cloudEmptyFolderHint: document.getElementById('cloudEmptyFolderHint'),
  cloudLoadingSpinner: document.getElementById('cloudLoadingSpinner'),

  // Cloud Trash
  cloudTrashView: document.getElementById('cloudTrashView'),
  btnCloudBackFromTrash: document.getElementById('btnCloudBackFromTrash'),
  cloudTrashTableBody: document.getElementById('cloudTrashTableBody'),
  cloudTrashEmptyHint: document.getElementById('cloudTrashEmptyHint'),

  // Cloud Upload Drawer
  cloudUploadDrawer: document.getElementById('cloudUploadDrawer'),
  cloudUploadDrawerCount: document.getElementById('cloudUploadDrawerCount'),
  btnToggleUploadDrawer: document.getElementById('btnToggleUploadDrawer'),
  btnCloseUploadDrawer: document.getElementById('btnCloseUploadDrawer'),
  cloudUploadDrawerBody: document.getElementById('cloudUploadDrawerBody'),

  // Cloud Modals
  modalCloudNewFolder: document.getElementById('modalCloudNewFolder'),
  inpCloudNewFolderName: document.getElementById('inpCloudNewFolderName'),
  cloudNewFolderError: document.getElementById('cloudNewFolderError'),
  btnCloseModalCloudNewFolder: document.getElementById('btnCloseModalCloudNewFolder'),
  btnCancelModalCloudNewFolder: document.getElementById('btnCancelModalCloudNewFolder'),
  btnSubmitModalCloudNewFolder: document.getElementById('btnSubmitModalCloudNewFolder'),

  modalCloudRename: document.getElementById('modalCloudRename'),
  inpCloudRenameName: document.getElementById('inpCloudRenameName'),
  cloudRenameError: document.getElementById('cloudRenameError'),
  btnCloseModalCloudRename: document.getElementById('btnCloseModalCloudRename'),
  btnCancelModalCloudRename: document.getElementById('btnCancelModalCloudRename'),
  btnSubmitModalCloudRename: document.getElementById('btnSubmitModalCloudRename'),

  modalCloudMove: document.getElementById('modalCloudMove'),
  cloudMoveItemTitle: document.getElementById('cloudMoveItemTitle'),
  selCloudMoveDestination: document.getElementById('selCloudMoveDestination'),
  cloudMoveError: document.getElementById('cloudMoveError'),
  btnCloseModalCloudMove: document.getElementById('btnCloseModalCloudMove'),
  btnCancelModalCloudMove: document.getElementById('btnCancelModalCloudMove'),
  btnSubmitModalCloudMove: document.getElementById('btnSubmitModalCloudMove'),

  modalCloudConfirmDelete: document.getElementById('modalCloudConfirmDelete'),
  cloudDeleteModalTitle: document.getElementById('cloudDeleteModalTitle'),
  cloudDeleteModalMessage: document.getElementById('cloudDeleteModalMessage'),
  cloudDeleteError: document.getElementById('cloudDeleteError'),
  btnCloseModalCloudConfirmDelete: document.getElementById('btnCloseModalCloudConfirmDelete'),
  btnCancelModalCloudConfirmDelete: document.getElementById('btnCancelModalCloudConfirmDelete'),
  btnSubmitModalCloudConfirmDelete: document.getElementById('btnSubmitModalCloudConfirmDelete'),

  modalCloudPicker: document.getElementById('modalCloudPicker'),
  cloudPickerTitle: document.getElementById('cloudPickerTitle'),
  btnCloseModalCloudPicker: document.getElementById('btnCloseModalCloudPicker'),
  btnCancelModalCloudPicker: document.getElementById('btnCancelModalCloudPicker'),
  btnSubmitModalCloudPicker: document.getElementById('btnSubmitModalCloudPicker'),
  cloudPickerBreadcrumbs: document.getElementById('cloudPickerBreadcrumbs'),
  inpCloudPickerSearch: document.getElementById('inpCloudPickerSearch'),
  cloudPickerTypeHint: document.getElementById('cloudPickerTypeHint'),
  cloudPickerSelectedCount: document.getElementById('cloudPickerSelectedCount'),
  cloudPickerConfirmCount: document.getElementById('cloudPickerConfirmCount'),
  chkCloudPickerSelectAll: document.getElementById('chkCloudPickerSelectAll'),
  cloudPickerTableBody: document.getElementById('cloudPickerTableBody'),
  cloudPickerEmptyHint: document.getElementById('cloudPickerEmptyHint'),
  cloudPickerLoading: document.getElementById('cloudPickerLoading'),
  cloudPickerStatusText: document.getElementById('cloudPickerStatusText'),

  modalCloudShare: document.getElementById('modalCloudShare'),
  cloudShareModalTitle: document.getElementById('cloudShareModalTitle'),
  btnCloseModalCloudShare: document.getElementById('btnCloseModalCloudShare'),
  btnCancelModalCloudShare: document.getElementById('btnCancelModalCloudShare'),
  btnSubmitModalCloudShare: document.getElementById('btnSubmitModalCloudShare'),
  cloudShareItemIcon: document.getElementById('cloudShareItemIcon'),
  cloudShareItemName: document.getElementById('cloudShareItemName'),
  cloudShareItemMeta: document.getElementById('cloudShareItemMeta'),
  cloudShareActiveWrap: document.getElementById('cloudShareActiveWrap'),
  cloudShareActiveExpires: document.getElementById('cloudShareActiveExpires'),
  cloudShareActiveAccess: document.getElementById('cloudShareActiveAccess'),
  inpCloudActiveShareUrl: document.getElementById('inpCloudActiveShareUrl'),
  btnCopyActiveShareLink: document.getElementById('btnCopyActiveShareLink'),
  btnRevokeActiveShareLink: document.getElementById('btnRevokeActiveShareLink'),
  cloudShareCreateWrap: document.getElementById('cloudShareCreateWrap'),
  selCloudShareExpires: document.getElementById('selCloudShareExpires'),
  cloudShareError: document.getElementById('cloudShareError'),

  // Priority 6: Team & Workspace DOM
  workspaceSwitcherWrapper: document.getElementById('workspaceSwitcherWrapper'),
  btnWorkspaceDropdown: document.getElementById('btnWorkspaceDropdown'),
  workspaceDropdownMenu: document.getElementById('workspaceDropdownMenu'),
  wsIcon: document.getElementById('wsIcon'),
  wsActiveName: document.getElementById('wsActiveName'),
  wsRoleBadge: document.getElementById('wsRoleBadge'),
  workspaceList: document.getElementById('workspaceList'),
  btnOpenCreateTeamModal: document.getElementById('btnOpenCreateTeamModal'),
  btnOpenManageTeamModal: document.getElementById('btnOpenManageTeamModal'),

  modalTeamMembers: document.getElementById('modalTeamMembers'),
  teamModalTitle: document.getElementById('teamModalTitle'),
  btnCloseModalTeamMembers: document.getElementById('btnCloseModalTeamMembers'),
  btnCloseModalTeam: document.getElementById('btnCloseModalTeam'),
  teamMemberCount: document.getElementById('teamMemberCount'),
  teamMemberSlots: document.getElementById('teamMemberSlots'),
  teamSeatCount: document.getElementById('teamSeatCount'),
  teamAppKeyCount: document.getElementById('teamAppKeyCount'),
  teamUserRoleBadge: document.getElementById('teamUserRoleBadge'),
  tabBtnTeamMembers: document.getElementById('tabBtnTeamMembers'),
  tabBtnTeamSeats: document.getElementById('tabBtnTeamSeats'),
  tabBtnTeamInvite: document.getElementById('tabBtnTeamInvite'),
  teamPaneMembers: document.getElementById('teamPaneMembers'),
  teamPaneSeats: document.getElementById('teamPaneSeats'),
  teamPaneInvite: document.getElementById('teamPaneInvite'),
  teamMembersTableBody: document.getElementById('teamMembersTableBody'),
  teamSeatsTableBody: document.getElementById('teamSeatsTableBody'),
  btnActivateCurrentDeviceSeat: document.getElementById('btnActivateCurrentDeviceSeat'),
  selTeamInviteRole: document.getElementById('selTeamInviteRole'),
  inpTeamInviteEmail: document.getElementById('inpTeamInviteEmail'),
  btnGenerateTeamInvite: document.getElementById('btnGenerateTeamInvite'),
  teamInviteResultWrap: document.getElementById('teamInviteResultWrap'),
  inpTeamInviteUrl: document.getElementById('inpTeamInviteUrl'),
  btnCopyTeamInviteUrl: document.getElementById('btnCopyTeamInviteUrl'),
  btnDangerDeleteTeam: document.getElementById('btnDangerDeleteTeam'),
  teamModalError: document.getElementById('teamModalError'),

  modalCreateTeam: document.getElementById('modalCreateTeam'),
  btnCloseModalCreateTeam: document.getElementById('btnCloseModalCreateTeam'),
  btnCancelModalCreateTeam: document.getElementById('btnCancelModalCreateTeam'),
  btnNextToStep2: document.getElementById('btnNextToStep2'),
  btnBackToStep1: document.getElementById('btnBackToStep1'),
  inpCreateTeamName: document.getElementById('inpCreateTeamName'),
  createTeamError: document.getElementById('createTeamError'),
  teamPlansGrid: document.getElementById('teamPlansGrid'),
  btnOpenHostedCheckout: document.getElementById('btnOpenHostedCheckout'),
  btnSwitchToCreatedTeam: document.getElementById('btnSwitchToCreatedTeam'),
  teamInvitesTableBody: document.getElementById('teamInvitesTableBody'),
  btnRefreshTeamInvites: document.getElementById('btnRefreshTeamInvites'),

  // Modal Token Topup DOM
  modalTokenTopup: document.getElementById('modalTokenTopup'),
  btnCloseModalTokenTopup: document.getElementById('btnCloseModalTokenTopup'),
  radioTopupPersonal: document.getElementById('radioTopupPersonal'),
  radioTopupTeam: document.getElementById('radioTopupTeam'),
  lblTopupTeamOption: document.getElementById('lblTopupTeamOption'),
  lblTopupTeamName: document.getElementById('lblTopupTeamName'),
  lblTopupPersonalBal: document.getElementById('lblTopupPersonalBal'),
  lblTopupTeamBal: document.getElementById('lblTopupTeamBal'),
  tokenPackagesSection: document.getElementById('tokenPackagesSection'),
  tokenPackagesGrid: document.getElementById('tokenPackagesGrid'),
  tokenCheckoutSection: document.getElementById('tokenCheckoutSection'),
  tokenTopupSuccessSection: document.getElementById('tokenTopupSuccessSection'),
  tokenCheckoutPkgTitle: document.getElementById('tokenCheckoutPkgTitle'),
  tokenCheckoutAmount: document.getElementById('tokenCheckoutAmount'),
  btnOpenTokenCheckoutUrl: document.getElementById('btnOpenTokenCheckoutUrl'),
  btnCancelTokenCheckout: document.getElementById('btnCancelTokenCheckout'),
  btnCloseTokenTopupSuccess: document.getElementById('btnCloseTokenTopupSuccess'),
  tokenTopupError: document.getElementById('tokenTopupError'),

  // AI Keys DOM (Phase 1)
  btnRefreshAiKeys: document.getElementById('btnRefreshAiKeys'),
  aiKeysListContainer: document.getElementById('aiKeysListContainer'),
  btnOpenCreateAiKeyModal: document.getElementById('btnOpenCreateAiKeyModal'),
  modalCreateAiKey: document.getElementById('modalCreateAiKey'),
  btnCloseModalCreateAiKey: document.getElementById('btnCloseModalCreateAiKey'),
  btnCancelModalCreateAiKey: document.getElementById('btnCancelModalCreateAiKey'),
  btnSubmitModalCreateAiKey: document.getElementById('btnSubmitModalCreateAiKey'),
  inpAiKeyName: document.getElementById('inpAiKeyName'),
  selAiKeyWorkspace: document.getElementById('selAiKeyWorkspace'),
  inpAiKeyExpiry: document.getElementById('inpAiKeyExpiry'),
  aiKeyModalError: document.getElementById('aiKeyModalError'),
  aiKeyFormSection: document.getElementById('aiKeyFormSection'),
  aiKeyResultSection: document.getElementById('aiKeyResultSection'),
  inpAiKeySecretResult: document.getElementById('inpAiKeySecretResult'),
  btnToggleAiKeyVisibility: document.getElementById('btnToggleAiKeyVisibility'),
  btnCopyAiKeySecret: document.getElementById('btnCopyAiKeySecret'),

  // Input Bundle DOM (Phase 2)
  btnBrowseBundle: document.getElementById('btnBrowseBundle'),
  bundleStatusBanner: document.getElementById('bundleStatusBanner'),
  bundleProjectTitle: document.getElementById('bundleProjectTitle'),
  bundleSceneStats: document.getElementById('bundleSceneStats'),
  btnInspectBundlePlan: document.getElementById('btnInspectBundlePlan'),
  btnClearBundle: document.getElementById('btnClearBundle'),
  bundleProgressFill: document.getElementById('bundleProgressFill'),
  modalBundlePlan: document.getElementById('modalBundlePlan'),
  modalBundlePlanTitle: document.getElementById('modalBundlePlanTitle'),
  bundlePlanScenesList: document.getElementById('bundlePlanScenesList'),
  modalBundleSummaryBadge: document.getElementById('modalBundleSummaryBadge'),
  btnCloseModalBundlePlan: document.getElementById('btnCloseModalBundlePlan'),
  btnDismissModalBundlePlan: document.getElementById('btnDismissModalBundlePlan'),

  // Pipeline Queue V2 DOM (Phase 3)
  btnRunPipelineAll: document.getElementById('btnRunPipelineAll'),
  btnModalRunPipeline: document.getElementById('btnModalRunPipeline'),
  floatingPipelineActivity: document.getElementById('floatingPipelineActivity'),
  floatingWidgetHeader: document.getElementById('floatingWidgetHeader'),
  floatingWidgetBody: document.getElementById('floatingWidgetBody'),
  floatingProjectTitle: document.getElementById('floatingProjectTitle'),
  floatingSceneActivity: document.getElementById('floatingSceneActivity'),
  floatingProgressBar: document.getElementById('floatingProgressBar'),
  floatingProgressPercent: document.getElementById('floatingProgressPercent'),
  floatingFlowAccountBadge: document.getElementById('floatingFlowAccountBadge'),
  floatingMiniProgressBadge: document.getElementById('floatingMiniProgressBadge'),
  btnFloatingMinimize: document.getElementById('btnFloatingMinimize'),
  btnFloatingDismiss: document.getElementById('btnFloatingDismiss'),
  iconFloatingMinimize: document.getElementById('iconFloatingMinimize'),
  badgePipelineQuickToggle: document.getElementById('badgePipelineQuickToggle'),
  badgePipelineQuickText: document.getElementById('badgePipelineQuickText'),
  btnFloatingPauseResume: document.getElementById('btnFloatingPauseResume'),
  btnFloatingViewQueue: document.getElementById('btnFloatingViewQueue'),
  pipelineJobsList: document.getElementById('pipelineJobsList'),
  btnClearCompletedPipelineJobs: document.getElementById('btnClearCompletedPipelineJobs'),

  // Flow Refinement DOM
  flowRealtimeStatusLine: document.getElementById('flowRealtimeStatusLine'),
  flowRealtimeStatusBox: document.getElementById('flowRealtimeStatusBox'),
  flowCharApprovalShortcut: document.getElementById('flowCharApprovalShortcut'),
  flowCharApprovalPendingCount: document.getElementById('flowCharApprovalPendingCount'),
  btnFlowOpenApprovalShortcut: document.getElementById('btnFlowOpenApprovalShortcut'),
  btnOpenFlowDiagnostics: document.getElementById('btnOpenFlowDiagnostics'),
  btnFlowGoToQueue: document.getElementById('btnFlowGoToQueue'),
  modalFlowDiagnostics: document.getElementById('modalFlowDiagnostics'),
  btnCloseModalFlowDiagnostics: document.getElementById('btnCloseModalFlowDiagnostics'),
  btnDismissModalFlowDiagnostics: document.getElementById('btnDismissModalFlowDiagnostics'),

  // Project Build Queue Toolbar (Bundle Import)
  btnAddBuildJob: document.getElementById('btnAddBuildJob'),
  btnImportBundleQueue: document.getElementById('btnImportBundleQueue'),
  btnImportCloudBundleQueue: document.getElementById('btnImportCloudBundleQueue'),
  btnRunAllPipelineJobs: document.getElementById('btnRunAllPipelineJobs'),
  btnStopBuildQueue: document.getElementById('btnStopBuildQueue'),
  btnClearBuildQueue: document.getElementById('btnClearBuildQueue'),

  // Modal: Bundle Import Preview
  modalBundleImportPreview: document.getElementById('modalBundleImportPreview'),
  btnCloseModalBundleImport: document.getElementById('btnCloseModalBundleImport'),
  btnCancelBundleImport: document.getElementById('btnCancelBundleImport'),
  btnConfirmEnqueueBundles: document.getElementById('btnConfirmEnqueueBundles'),
  bundleImportPreviewList: document.getElementById('bundleImportPreviewList'),
  bundleImportSummaryText: document.getElementById('bundleImportSummaryText'),

  // Modal: Cloud Bundle Picker
  modalCloudBundlePicker: document.getElementById('modalCloudBundlePicker'),
  btnCloseModalCloudBundlePicker: document.getElementById('btnCloseModalCloudBundlePicker'),
  btnCancelCloudBundlePicker: document.getElementById('btnCancelCloudBundlePicker'),
  btnConfirmSelectCloudBundles: document.getElementById('btnConfirmSelectCloudBundles'),
  btnRefreshCloudBundlePicker: document.getElementById('btnRefreshCloudBundlePicker'),
  cloudBundleFoldersList: document.getElementById('cloudBundleFoldersList'),
  txtCloudPickerCurrentFolder: document.getElementById('txtCloudPickerCurrentFolder'),
  txtCloudSelectedCount: document.getElementById('txtCloudSelectedCount'),

  // Modal: Queue Job Details
  modalQueueJobDetails: document.getElementById('modalQueueJobDetails'),
  queueJobDetailsTitle: document.getElementById('queueJobDetailsTitle'),
  queueJobDetailsContent: document.getElementById('queueJobDetailsContent'),
  btnCloseModalQueueJobDetails: document.getElementById('btnCloseModalQueueJobDetails'),
  btnDismissQueueJobDetails: document.getElementById('btnDismissQueueJobDetails'),
  btnOpenJobBundleFolder: document.getElementById('btnOpenJobBundleFolder'),

  // Flow Browser DOM (In-Page Settings UX & Modern Overlay)
  btnFlowReload: document.getElementById('btnFlowReload'),
  btnFlowNavigate: document.getElementById('btnFlowNavigate'),
  btnFlowTakeover: document.getElementById('btnFlowTakeover'),
  btnFlowResumeAuto: document.getElementById('btnFlowResumeAuto'),
  flowModeBadge: document.getElementById('flowModeBadge'),
  flowAuthBadge: document.getElementById('flowAuthBadge'),
  flowCreditBadge: document.getElementById('flowCreditBadge'),
  flowBrowserContainer: document.getElementById('flowBrowserContainer'),
};

// -----------------------------------------------------------------------------
// UI Navigation & Views (Multilingual GAP-13)
// -----------------------------------------------------------------------------
const VIEW_METADATA = {
  studio: {
    vi: { title: 'Studio Dựng Phim', sub: 'Tự động dựng timeline, căn chỉnh kịch bản & chuyển động CapCut' },
    en: { title: 'CapCut Project Studio', sub: 'Automated keyframe timing, script alignment, and cinematic motion' },
  },
  queue: {
    vi: { title: 'Hàng Đợi Xử Lý (Queue)', sub: 'Tự động xử lý hàng loạt dự án tuần tự trong nền' },
    en: { title: 'Job Queue & Automation', sub: 'Automated sequential background video rendering' },
  },
  projects: {
    vi: { title: 'Dự Án CapCut Đã Cài Đặt', sub: 'Xem và mở trực tiếp các dự án đã đăng ký vào CapCut Desktop' },
    en: { title: 'Installed CapCut Projects', sub: 'Browse and open projects directly in CapCut Desktop' },
  },
  upscale: {
    vi: { title: 'Phân Hệ AI Upscale 4K', sub: 'Tăng độ phân giải ảnh 2K/4K bằng GPU Vulkan NCNN' },
    en: { title: 'AI Upscale 4K Engine', sub: '2K/4K image super-resolution accelerated by Vulkan NCNN' },
  },
  account: {
    vi: { title: 'Tài Khoản & Ví Bản Quyền', sub: 'Thông tin bản quyền thiết bị, số dư token và gói sử dụng' },
    en: { title: 'Account & License Wallet', sub: 'Hardware license entitlements, token wallet, and plan details' },
  },
  cloud: {
    vi: { title: '2TOOLNE Cloud Lưu Trữ', sub: 'Quản lý tệp tin đám mây, đồng bộ tải lên / tải xuống và nạp trực tiếp vào Studio' },
    en: { title: '2TOOLNE Cloud Storage', sub: 'Cloud file management, direct upload / download, and seamless import into Studio' },
  },
  settings: {
    vi: { title: 'Cài Đặt Ứng Dụng', sub: 'Cấu hình liên kết CapCut và tùy chọn xuất bản' },
    en: { title: 'Application Settings', sub: 'Configure CapCut paths, language, and render preferences' },
  },
  flow: {
    vi: { title: 'Google Flow Trực Tiếp', sub: 'Trình duyệt nhúng an toàn, cách ly đa tài khoản & tự động hóa tạo cảnh' },
    en: { title: 'Google Flow Browser', sub: 'Isolated multi-account embedded browser & automated scene generation' },
  },
  tts: {
    vi: { title: 'Text-to-Speech & Voice Cloning Studio', sub: 'Tổng hợp giọng đọc AI đa ngôn ngữ (EN, JA, KO, VI) và nạp trực tiếp vào Timeline CapCut' },
    en: { title: 'TTS & Voice Cloning Studio', sub: 'Multilingual AI speech synthesis and zero-byte cloud voice cloning' },
  },
};

function applyCurrentLanguage(lang) {
  if (window.i18n) {
    window.i18n.setLanguage(lang);
    const meta = VIEW_METADATA[state.currentTab]?.[lang] || VIEW_METADATA[state.currentTab]?.vi;
    if (meta && DOM.viewTitle && DOM.viewSub) {
      DOM.viewTitle.textContent = meta.title;
      DOM.viewSub.textContent = meta.sub;
    }
  }
}

function switchTab(tabId) {
  closeWorkspaceDropdown();
  state.currentTab = tabId;

  // Update Nav Buttons
  DOM.navBtns.forEach((btn) => {
    if (btn.dataset.tab === tabId) {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });

  // Update View Panes
  DOM.viewPanes.forEach((pane) => {
    if (pane.id === `view-${tabId}`) {
      pane.classList.add('active');
      pane.style.display = 'flex';
      pane.style.flexDirection = 'column';
      pane.style.width = '100%';
    } else {
      pane.classList.remove('active');
      pane.style.display = 'none';
    }
  });

  // Update Header Title with localized string
  const lang = window.i18n?.currentLang || 'vi';
  const meta = VIEW_METADATA[tabId]?.[lang] || VIEW_METADATA[tabId]?.vi || { title: '2TOOLNE AutoEdit', sub: '' };
  DOM.viewTitle.textContent = meta.title;
  DOM.viewSub.textContent = meta.sub;

  if (tabId === 'projects') renderProjectsGrid();
  if (tabId === 'queue') {
    refreshPipelineQueueUI();
    refreshBuildQueueUI();
    refreshRenderQueueUI();
  }
  if (tabId === 'cloud') {
    onOpenCloudTab();
  }
  if (tabId === 'tts') {
    onOpenTtsTab();
  }
  if (tabId === 'account') {
    loadAiKeys();
  }
  const mainScroll = document.querySelector('.main-content-scroll');
  if (tabId === 'flow') {
    if (mainScroll) {
      mainScroll.scrollTop = 0;
      mainScroll.scrollLeft = 0;
      mainScroll.classList.add('flow-active');
    }
    onOpenFlowTab();
  } else {
    if (mainScroll) mainScroll.classList.remove('flow-active');
    onLeaveFlowTab();
  }
}

// -----------------------------------------------------------------------------
// Modal Dialog Controller (Guaranteed Dismissibility with Close and ESC)
// -----------------------------------------------------------------------------
function showModal(modalEl) {
  if (modalEl) modalEl.style.display = 'flex';
  if (state.currentTab === 'flow' && window.autoedit?.flow?.hideView) {
    window.autoedit.flow.hideView();
  }
}

function hideModal(modalEl) {
  if (modalEl) modalEl.style.display = 'none';
  if (state.currentTab === 'flow') {
    const anyModalVisible = Array.from(document.querySelectorAll('.modal, .modal-backdrop')).some(
      (m) => m.style.display === 'flex' || m.style.display === 'block'
    );
    if (!anyModalVisible && window.autoedit?.flow?.showView) {
      const container = DOM.flowBrowserContainer || document.getElementById('flowBrowserContainer');
      if (container) {
        const rect = container.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
          window.autoedit.flow.showView({
            x: Math.round(rect.left),
            y: Math.round(rect.top),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
          });
        }
      }
    }
  }
}

function hideAllModals() {
  hideModal(DOM.modalLicense);
  hideModal(DOM.modalProgress);
  hideModal(DOM.modalAlert);
  hideModal(DOM.modalMissingImages);
  hideModal(DOM.modalCloudNewFolder);
  hideModal(DOM.modalCloudRename);
  hideModal(DOM.modalCloudMove);
  hideModal(DOM.modalCloudConfirmDelete);
  hideModal(DOM.modalCloudPicker);
  hideModal(DOM.modalCloudShare);
  hideModal(DOM.modalTeamMembers);
  hideModal(DOM.modalCreateTeam);
  if (DOM.modalCreateAiKey && DOM.modalCreateAiKey.style.display !== 'none') {
    if (DOM.inpAiKeySecretResult) DOM.inpAiKeySecretResult.value = '';
    hideModal(DOM.modalCreateAiKey);
    loadAiKeys();
  } else {
    hideModal(DOM.modalCreateAiKey);
  }
  hideModal(DOM.modalBundlePlan);
  hideModal(DOM.modalBundleImportPreview);
  hideModal(DOM.modalCloudBundlePicker);
  hideModal(DOM.modalQueueJobDetails);
}

function showAlert(message, title = 'Thông Báo') {
  DOM.alertModalTitle.textContent = title;
  DOM.alertModalMessage.textContent = message;
  showModal(DOM.modalAlert);
}

// Attach Close Handlers to All Modals
[DOM.btnCloseModalLicense, DOM.btnCancelModalLicense].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalLicense));
});
[DOM.btnCloseModalProgress, DOM.btnCancelProgressModal].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalProgress));
});
[DOM.btnCloseModalAlert, DOM.btnOkModalAlert].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalAlert));
});
[DOM.btnCloseModalMissingImages, DOM.btnCancelModalMissing].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalMissingImages));
});
[
  DOM.btnCloseModalBundleImport,
  DOM.btnCancelBundleImport,
  DOM.btnCloseModalCloudBundlePicker,
  DOM.btnCancelCloudBundlePicker,
  DOM.btnCloseModalQueueJobDetails,
  DOM.btnDismissQueueJobDetails,
].forEach((btn) => {
  btn?.addEventListener('click', () => {
    hideModal(DOM.modalBundleImportPreview);
    hideModal(DOM.modalCloudBundlePicker);
    hideModal(DOM.modalQueueJobDetails);
  });
});

// Click Backdrop to Dismiss
[
  DOM.modalLicense,
  DOM.modalProgress,
  DOM.modalAlert,
  DOM.modalMissingImages,
  DOM.modalBundleImportPreview,
  DOM.modalCloudBundlePicker,
  DOM.modalQueueJobDetails,
].forEach((modal) => {
  modal?.addEventListener('click', (e) => {
    if (e.target === modal) hideModal(modal);
  });
});

// ESC Key Closes Any Active Modal or Dropdown
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeWorkspaceDropdown();
    hideAllModals();
  }
});

// -----------------------------------------------------------------------------
// Media Files Importer (Drag, Drop, Archive, Folder)
// -----------------------------------------------------------------------------
async function handleImportPaths(paths) {
  if (!paths || !paths.length) return;

  // Auto-detect if user dropped/selected an Input Bundle directory
  if (paths.length === 1 && typeof paths[0] === 'string' && window.autoedit?.bundle?.validateLocal) {
    try {
      const checkBundle = await window.autoedit.bundle.validateLocal(paths[0]);
      if (checkBundle && checkBundle.ok && (checkBundle.has_manifest || checkBundle.has_prompts)) {
        await loadBundleFromDirectory(paths[0]);
        return;
      }
    } catch (bundleCheckErr) {
      // Continue normal image processing
    }
  }

  try {
    const res = await window.autoedit.processImportPaths(paths);
    if (res && res.images && res.images.length) {
      for (const img of res.images) {
        if (!state.mediaList.includes(img)) {
          state.mediaList.push(img);
        }
      }
      renderMediaGrid();
      if (res.errors && res.errors.length) {
        showAlert(`Đã thêm ${res.images.length} ảnh.\nMột số cảnh báo:\n${res.errors.join('\n')}`, 'Lưu ý Import');
      }
    } else if (res.errors && res.errors.length) {
      showAlert(`Không tìm thấy hình ảnh hợp lệ:\n${res.errors.join('\n')}`, 'Lỗi Import');
    }
  } catch (err) {
    showAlert(`Lỗi xử lý file: ${err.message}`, 'Lỗi');
  }
}

// -----------------------------------------------------------------------------
// Sequential Image (001-xxx) Gap Detection & Stretch Resolution
// -----------------------------------------------------------------------------

// Maximum plausible span for a sequential image index series (max - min + 1).
//
// extractFileIndex() matches ANY trailing digit run, so real-world filenames such as
// "mmexport1699999999999.jpg" (messenger epoch-ms) or "IMG_20240115_143022.jpg"
// (camera timestamp) yield astronomically large indices that are NOT part of a
// sequential 001..N series. Left unvalidated, they drive an effectively unbounded
// synchronous loop on the renderer main thread, which starves the event loop before
// renderMediaGrid() can paint its first slice -- the user sees a completely black
// window. Any index span beyond this cap is treated as "not a sequential series".
const MEDIA_INDEX_MAX_SPAN = 5000;

function extractFileIndex(filePath) {
  if (!filePath) return null;
  const name = filePath.split(/[/\\]/).pop();
  // Extract trailing digits before extension: e.g. "image_001.png" -> 1, "003.jpg" -> 3
  const m = name.match(/(\d+)(?:\.[^.]+)?$/);
  return m ? { num: parseInt(m[1], 10), raw: m[1], name, path: filePath } : null;
}

function detectMissingIndices(mediaList) {
  if (!mediaList || mediaList.length < 2) return null;

  const mapped = [];
  for (const p of mediaList) {
    const info = extractFileIndex(p);
    if (info) mapped.push(info);
  }

  // Require that at least half the files have numeric endings
  if (mapped.length < Math.min(2, mediaList.length)) return null;

  mapped.sort((a, b) => a.num - b.num);
  const min = mapped[0].num;
  const max = mapped[mapped.length - 1].num;

  // Reject implausible index spans BEFORE any loop runs. Filenames commonly carry
  // large trailing digit runs (epoch-ms, camera timestamps, download counters) that
  // are not sequential indices; treating them as such previously drove an unbounded
  // synchronous loop on the main thread and hung the renderer.
  if (!Number.isFinite(min) || !Number.isFinite(max) || max < min) return null;
  if (max - min + 1 > MEDIA_INDEX_MAX_SPAN) return null;

  const present = new Set(mapped.map((m) => m.num));
  const missing = [];

  for (let i = min; i <= max; i++) {
    if (!present.has(i)) {
      missing.push(i);
    }
  }

  return { min, max, missing, mapped };
}

function updateMissingBanner() {
  const analysis = detectMissingIndices(state.mediaList);
  if (analysis && analysis.missing.length > 0) {
    DOM.missingImagesBanner.style.display = 'flex';
    const missingStr = analysis.missing.map((n) => String(n).padStart(3, '0'));
    const preview = missingStr.slice(0, 5).join(', ') + (missingStr.length > 5 ? ` (+${missingStr.length - 5} nữa)` : '');
    DOM.missingImagesText.textContent = `Thiếu ${analysis.missing.length} ảnh trong chuỗi: ${preview}`;
    state.lastMissingAnalysis = analysis;
  } else {
    DOM.missingImagesBanner.style.display = 'none';
    state.lastMissingAnalysis = null;
  }
}

function applyMissingResolution(strategy) {
  const analysis = state.lastMissingAnalysis || detectMissingIndices(state.mediaList);
  if (!analysis || analysis.missing.length === 0) return;

  const { min, max, mapped } = analysis;
  // Defence in depth: state.lastMissingAnalysis may arrive from a stale or restored
  // snapshot that never passed through detectMissingIndices(). Never materialise a
  // path array sized by an implausible span.
  if (!Number.isFinite(min) || !Number.isFinite(max) || max < min) return;
  if (max - min + 1 > MEDIA_INDEX_MAX_SPAN) return;
  const lookup = new Map();
  // Store unique by number
  mapped.forEach((m) => {
    if (!lookup.has(m.num)) lookup.set(m.num, m.path);
  });

  const resolvedPaths = [];
  for (let i = min; i <= max; i++) {
    if (lookup.has(i)) {
      resolvedPaths.push(lookup.get(i));
    } else {
      if (strategy === 'stretch_next') {
        // Find closest succeeding image
        let nextPath = null;
        for (let j = i + 1; j <= max; j++) {
          if (lookup.has(j)) {
            nextPath = lookup.get(j);
            break;
          }
        }
        resolvedPaths.push(nextPath || mapped[0].path);
      } else {
        // Default: stretch_prev (closest preceding image)
        let prevPath = null;
        for (let j = i - 1; j >= min; j--) {
          if (lookup.has(j)) {
            prevPath = lookup.get(j);
            break;
          }
        }
        resolvedPaths.push(prevPath || mapped[mapped.length - 1].path);
      }
    }
  }

  state.mediaList = resolvedPaths;
  renderMediaGrid();
  showAlert(
    `Đã áp dụng phương án kéo giãn "${strategy === 'stretch_next' ? 'ảnh liền sau' : 'ảnh liền trước'}"!\n` +
    `Đã bù đủ ${max - min + 1} ảnh cho dòng thời gian.`,
    'Bù Thời Lượng Thành Công'
  );
}

// Missing Images Modal Open & Action
DOM.btnOpenMissingModal?.addEventListener('click', () => {
  const analysis = state.lastMissingAnalysis || detectMissingIndices(state.mediaList);
  if (!analysis || analysis.missing.length === 0) {
    showAlert('Không tìm thấy số thứ tự ảnh nào bị thiếu.', 'Thông Báo');
    return;
  }

  const missingFormatted = analysis.missing.map((n) => `#${String(n).padStart(3, '0')}`).join(', ');
  DOM.missingIndicesDetails.textContent = `Thiếu ${analysis.missing.length} số thứ tự: [ ${missingFormatted} ] (từ #${String(analysis.min).padStart(3, '0')} đến #${String(analysis.max).padStart(3, '0')})`;
  showModal(DOM.modalMissingImages);
});

DOM.btnCancelModalMissing?.addEventListener('click', () => {
  hideModal(DOM.modalMissingImages);
});

DOM.btnApplyMissingOption?.addEventListener('click', async () => {
  const selected = document.querySelector('input[name="radMissingOption"]:checked')?.value || 'stretch_prev';
  hideModal(DOM.modalMissingImages);

  if (selected === 'manual') {
    const files = await window.autoedit.openFilesDialog();
    if (files && files.length) {
      await handleImportPaths(files);
    }
  } else {
    applyMissingResolution(selected);
  }
});

// -----------------------------------------------------------------------------
// Virtualized Filename-Only Media Grid Engine (Zero Image Decode in Grid)
// -----------------------------------------------------------------------------
let mediaGridVirtualizer = null;

function toFileUrl(filePath) {
  if (!filePath) return '';
  if (filePath.startsWith('file://')) return filePath;
  let normalized = filePath.replace(/\\/g, '/');
  if (!normalized.startsWith('/')) {
    normalized = '/' + normalized;
  }
  return `file://${normalized}`;
}

function openSingleMediaPreview(originalPath, index) {
  if (!DOM.modalMediaPreview || !DOM.previewSingleImage) return;

  const fileName = originalPath.split(/[/\\]/).pop();
  if (DOM.mediaPreviewTitle) {
    DOM.mediaPreviewTitle.textContent = `#${String(index + 1).padStart(3, '0')}: ${fileName}`;
  }
  if (DOM.mediaPreviewDimensions) {
    DOM.mediaPreviewDimensions.textContent = 'Đang nạp ảnh gốc...';
  }

  // Load ONLY this single original image on demand
  DOM.previewSingleImage.onload = () => {
    if (DOM.mediaPreviewDimensions) {
      DOM.mediaPreviewDimensions.textContent = `${DOM.previewSingleImage.naturalWidth} × ${DOM.previewSingleImage.naturalHeight} px (${fileName})`;
    }
  };
  DOM.previewSingleImage.onerror = () => {
    if (DOM.mediaPreviewDimensions) {
      DOM.mediaPreviewDimensions.textContent = 'Lỗi nạp ảnh gốc';
    }
  };
  DOM.previewSingleImage.src = toFileUrl(originalPath);

  showModal(DOM.modalMediaPreview);
}

function closeSingleMediaPreview() {
  if (!DOM.modalMediaPreview) return;
  hideModal(DOM.modalMediaPreview);
  if (DOM.previewSingleImage) {
    // Release memory reference immediately
    DOM.previewSingleImage.src = '';
    DOM.previewSingleImage.onload = null;
    DOM.previewSingleImage.onerror = null;
  }
}

DOM.btnCloseModalMediaPreview?.addEventListener('click', closeSingleMediaPreview);
DOM.btnOkModalMediaPreview?.addEventListener('click', closeSingleMediaPreview);

class MediaGridVirtualizer {
  constructor(container) {
    this.container = container;
    this.spacer = null;
    this.content = null;
    this.emptyHint = null;

    this.rowPitch = 36; // 32px height + 4px gap
    this.overscanRows = 3;

    this.lastRenderedStart = -1;
    this.lastRenderedEnd = -1;

    this.initDOM();
    this.bindEvents();
  }

  initDOM() {
    this.container.innerHTML = '';

    this.spacer = document.createElement('div');
    this.spacer.className = 'media-virtual-spacer';

    this.content = document.createElement('div');
    this.content.className = 'media-virtual-content';

    this.emptyHint = document.createElement('div');
    this.emptyHint.className = 'empty-media-hint';
    this.emptyHint.textContent = 'Chưa có ảnh nào được chọn.';

    this.container.appendChild(this.spacer);
    this.container.appendChild(this.content);
    this.container.appendChild(this.emptyHint);
  }

  bindEvents() {
    let scrollRaf = null;
    this.container.addEventListener('scroll', () => {
      if (scrollRaf) cancelAnimationFrame(scrollRaf);
      scrollRaf = requestAnimationFrame(() => {
        this.updateVisibleSlice();
      });
    }, { passive: true });

    // Delegated click handler on content container
    this.content.addEventListener('click', (e) => {
      const delBtn = e.target.closest('.btn-file-remove');
      if (delBtn) {
        e.stopPropagation();
        const idx = parseInt(delBtn.dataset.index, 10);
        if (!isNaN(idx) && idx >= 0 && idx < state.mediaList.length) {
          state.mediaList.splice(idx, 1);
          renderMediaGrid();
        }
        return;
      }

      const prevBtn = e.target.closest('.btn-file-preview');
      if (prevBtn) {
        e.stopPropagation();
        const idx = parseInt(prevBtn.dataset.index, 10);
        if (!isNaN(idx) && idx >= 0 && idx < state.mediaList.length) {
          openSingleMediaPreview(state.mediaList[idx], idx);
        }
        return;
      }
    });

    // Double-click row for single preview
    this.content.addEventListener('dblclick', (e) => {
      const row = e.target.closest('.media-file-row');
      if (row) {
        const idx = parseInt(row.dataset.index, 10);
        if (!isNaN(idx) && idx >= 0 && idx < state.mediaList.length) {
          openSingleMediaPreview(state.mediaList[idx], idx);
        }
      }
    });
  }

  recalculateDimensions() {
    // Single-column vertical list layout
    this.rowPitch = 36;
  }

  updateVisibleSlice(force = false) {
    const totalItems = state.mediaList.length;

    if (totalItems === 0) {
      this.spacer.style.height = '0px';
      this.content.innerHTML = '';
      this.emptyHint.style.display = 'flex';
      this.container.classList.remove('has-media');
      this.lastRenderedStart = -1;
      this.lastRenderedEnd = -1;
      return;
    }

    this.emptyHint.style.display = 'none';
    this.container.classList.add('has-media');

    const totalHeight = totalItems * this.rowPitch;
    this.spacer.style.height = `${totalHeight}px`;

    const scrollTop = this.container.scrollTop;
    const clientHeight = this.container.clientHeight || 220;

    const visibleStartRow = Math.max(0, Math.floor(scrollTop / this.rowPitch) - this.overscanRows);
    const visibleEndRow = Math.min(totalItems - 1, Math.ceil((scrollTop + clientHeight) / this.rowPitch) + this.overscanRows);

    const startIndex = visibleStartRow;
    const endIndex = visibleEndRow;

    if (!force && startIndex === this.lastRenderedStart && endIndex === this.lastRenderedEnd) {
      return;
    }

    this.lastRenderedStart = startIndex;
    this.lastRenderedEnd = endIndex;

    const offsetY = visibleStartRow * this.rowPitch;
    this.content.style.transform = `translateY(${offsetY}px)`;

    const fragment = document.createDocumentFragment();

    for (let i = startIndex; i <= endIndex; i++) {
      const originalPath = state.mediaList[i];
      const fileName = originalPath.split(/[/\\]/).pop() || originalPath;
      const extMatch = fileName.match(/\.([a-zA-Z0-9]+)$/);
      const ext = extMatch ? extMatch[1].toUpperCase() : 'IMG';

      const row = document.createElement('div');
      row.className = 'media-file-row';
      row.dataset.index = i;
      row.dataset.originalPath = originalPath;
      row.title = `#${String(i + 1).padStart(3, '0')}: ${originalPath} (Click nút mắt hoặc nhấp đúp để xem ảnh gốc)`;

      // Sequence #001
      const seq = document.createElement('span');
      seq.className = 'media-file-seq';
      seq.textContent = `#${String(i + 1).padStart(3, '0')}`;

      // File icon
      const iconWrap = document.createElement('span');
      iconWrap.className = 'media-file-icon';
      iconWrap.innerHTML = '<i data-lucide="image" class="icon-xs"></i>';

      // Filename
      const name = document.createElement('span');
      name.className = 'media-file-name';
      name.textContent = fileName;

      // Extension badge
      const extBadge = document.createElement('span');
      extBadge.className = 'media-file-ext';
      extBadge.textContent = ext;

      // Actions
      const actions = document.createElement('div');
      actions.className = 'media-file-actions';

      const prevBtn = document.createElement('button');
      prevBtn.type = 'button';
      prevBtn.className = 'btn-file-preview';
      prevBtn.dataset.index = i;
      prevBtn.title = 'Xem ảnh gốc';
      prevBtn.innerHTML = '<i data-lucide="eye" class="icon-xs"></i>';

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn-file-remove';
      delBtn.dataset.index = i;
      delBtn.title = 'Xóa ảnh này';
      delBtn.innerHTML = '<i data-lucide="x" class="icon-xs"></i>';

      actions.appendChild(prevBtn);
      actions.appendChild(delBtn);

      row.appendChild(seq);
      row.appendChild(iconWrap);
      row.appendChild(name);
      row.appendChild(extBadge);
      row.appendChild(actions);

      fragment.appendChild(row);
    }

    this.content.innerHTML = '';
    this.content.appendChild(fragment);

    // Refresh icons inside rendered rows
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons({ root: this.content });
    }
  }
}

function renderMediaGrid() {
  // Sort naturally by filename / numeric index
  state.mediaList.sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

  DOM.mediaCount.textContent = state.mediaList.length;
  DOM.btnClearMedia.style.display = state.mediaList.length > 0 ? 'inline-block' : 'none';

  updateMissingBanner();

  if (!mediaGridVirtualizer && DOM.mediaGrid) {
    mediaGridVirtualizer = new MediaGridVirtualizer(DOM.mediaGrid);
  }

  if (mediaGridVirtualizer) {
    mediaGridVirtualizer.recalculateDimensions();
    mediaGridVirtualizer.updateVisibleSlice(true);
  }
}

// Dropzone Events
DOM.mediaDropZone.addEventListener('dragover', (e) => {
  e.preventDefault();
  DOM.mediaDropZone.classList.add('dragover');
});
DOM.mediaDropZone.addEventListener('dragleave', () => {
  DOM.mediaDropZone.classList.remove('dragover');
});
DOM.mediaDropZone.addEventListener('drop', async (e) => {
  e.preventDefault();
  DOM.mediaDropZone.classList.remove('dragover');
  const rawFiles = Array.from(e.dataTransfer?.files || []);
  const files = rawFiles
    .map((f) => {
      if (window.autoedit?.getPathForFile) {
        try {
          const p = window.autoedit.getPathForFile(f);
          if (p) return p;
        } catch (err) {}
      }
      return f.path || '';
    })
    .filter(Boolean);

  if (files.length > 0) {
    await handleImportPaths(files);
  }
});

DOM.btnBrowseFiles.addEventListener('click', async () => {
  const files = await window.autoedit.openFilesDialog();
  await handleImportPaths(files);
});

DOM.btnBrowseFolder.addEventListener('click', async () => {
  const folder = await window.autoedit.openDirectoryDialog();
  if (folder) await handleImportPaths([folder]);
});

DOM.btnClearMedia.addEventListener('click', () => {
  state.mediaList = [];
  renderMediaGrid();
});

// -----------------------------------------------------------------------------
// Audio & Subtitle Studio (Forced Alignment Prototype)
// -----------------------------------------------------------------------------
function setAudioPathUI(audioPath) {
  state.audioPath = audioPath || null;
  if (state.audioPath) {
    DOM.audioFileName.textContent = state.audioPath.split(/[\/\\]/).pop();
    DOM.audioFileName.title = state.audioPath;
    DOM.btnRemoveAudio.style.display = 'inline-block';
  } else {
    DOM.audioFileName.textContent = 'Chưa chọn âm thanh';
    DOM.btnRemoveAudio.style.display = 'none';
  }
}

DOM.btnSelectAudioFile.addEventListener('click', async () => {
  const audio = await window.autoedit.openAudioDialog();
  if (audio) {
    setAudioPathUI(audio);
  }
});

DOM.btnRemoveAudio.addEventListener('click', () => {
  setAudioPathUI(null);
});

DOM.btnLoadScriptFile.addEventListener('click', async () => {
  const path = await window.autoedit.selectScript();
  if (path) {
    // Read text file via sidecar or store
    try {
      const res = await window.autoedit.openPath(path);
      // Fallback: prompt user or set path
    } catch (e) {}
  }
});

DOM.tabModeFA?.addEventListener('click', () => {
  state.subtitleWorkflowMode = 'fa';
  DOM.tabModeFA.classList.add('active');
  DOM.tabModeSTT.classList.remove('active');
  DOM.inpScriptText.disabled = false;
  DOM.inpScriptText.placeholder = `Nhập kịch bản tại đây...
Quy tắc:
• Xuống dòng 1 lần (\\n) = Câu phụ đề mới
• Cách 1 dòng trống (\\n\\n) = Chuyển sang ảnh/cảnh mới`;
  DOM.btnStartAlign.innerHTML = '<i data-lucide="sparkles" class="icon-sm"></i> BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)'; refreshIcons(DOM.btnStartAlign);
});

DOM.tabModeSTT?.addEventListener('click', () => {
  state.subtitleWorkflowMode = 'stt';
  DOM.tabModeSTT.classList.add('active');
  DOM.tabModeFA.classList.remove('active');
  DOM.inpScriptText.disabled = true;
  DOM.inpScriptText.placeholder = 'Chế độ AutoSub: Whisper sẽ tự động nhận diện tiếng nói và tạo phụ đề từ tệp âm thanh (không cần kịch bản văn bản).';
  DOM.btnStartAlign.innerHTML = '<i data-lucide="mic" class="icon-sm"></i> BẮT ĐẦU TỰ ĐỘNG TẠO PHỤ ĐỀ (AutoSub)'; refreshIcons(DOM.btnStartAlign);
});

// Start Alignment / AutoSub Action
DOM.btnStartAlign.addEventListener('click', async () => {
  const isAutoSub = state.subtitleWorkflowMode === 'stt';
  const script = isAutoSub ? '' : DOM.inpScriptText.value.trim();

  if (!isAutoSub && !script) {
    showAlert('Vui lòng dán hoặc nhập kịch bản lời thoại vào ô kịch bản, hoặc chuyển sang tab "2. Tự Động Tạo Sub (AutoSub)" để nhận diện không cần kịch bản.', 'Thiếu Kịch Bản');
    return;
  }
  if (!state.audioPath) {
    showAlert('Vui lòng chọn tệp âm thanh (giọng đọc) để tiến hành xử lý phụ đề.', 'Thiếu Âm Thanh');
    return;
  }

  DOM.alignProgressWrap.style.display = 'block';
  DOM.alignProgressPct.textContent = '10%';
  DOM.alignProgressFill.style.width = '10%';
  DOM.alignProgressMsg.textContent = isAutoSub ? 'Đang nhận diện giọng nói tự động qua Whisper...' : 'Khởi động động cơ âm học Faster-Whisper...';
  DOM.btnStartAlign.disabled = true;

  try {
    const modelSize = DOM.selAsrEngine?.value === 'base' ? 'base' : 'tiny';
    const res = await window.autoedit.generateSrtFromScript({
      script_text: script,
      audio_path: state.audioPath,
      mode: isAutoSub ? 'autosub' : 'fa',
      allow_autosub: isAutoSub,
      options: {
        model_size: modelSize,
        allow_degraded: true,
      },
    });

    if (res && res.srt_content) {
      DOM.outSrtContent.value = res.srt_content;
      state.srtContent = res.srt_content;
      const count = countSrtCues(res.srt_content);
      DOM.srtLineCount.textContent = count;
      DOM.srtStatusLine.style.display = 'block';

      DOM.alignProgressPct.textContent = '100%';
      DOM.alignProgressFill.style.width = '100%';
      let successMsg = isAutoSub ? 'Tự động tạo phụ đề AutoSub thành công!' : 'So khớp kịch bản thành công!';
      if (res.warnings && res.warnings.length > 0) {
        successMsg += ` (Hệ thống đã tự động tối ưu hóa ${res.warnings.length} đoạn)`;
      }
      DOM.alignProgressMsg.textContent = successMsg;
    } else {
      throw new Error(res?.error || 'Không sinh được nội dung SRT');
    }
  } catch (err) {
    showAlert(`Lỗi so khớp kịch bản: ${err.message}`, 'So Khớp Thất Bại');
    DOM.alignProgressMsg.textContent = 'Lỗi so khớp kịch bản';
  } finally {
    DOM.btnStartAlign.disabled = false;
  }
});

DOM.outSrtContent.addEventListener('input', () => {
  state.srtContent = DOM.outSrtContent.value;
  const count = countSrtCues(state.srtContent);
  DOM.srtLineCount.textContent = count;
  DOM.srtStatusLine.style.display = count > 0 ? 'block' : 'none';
});

DOM.btnClearSrt.addEventListener('click', () => {
  DOM.outSrtContent.value = '';
  state.srtContent = '';
  DOM.srtStatusLine.style.display = 'none';
});

DOM.btnDownloadSrt.addEventListener('click', async () => {
  if (!DOM.outSrtContent.value.trim()) {
    showAlert('Chưa có nội dung phụ đề để tải.', 'Thông báo');
    return;
  }
  await window.autoedit.saveSrtDialog(DOM.outSrtContent.value);
});

// -----------------------------------------------------------------------------
// Ken Burns Motion Sliders
// -----------------------------------------------------------------------------
function syncWeightSlider(slider, labelEl, key) {
  slider.addEventListener('input', () => {
    labelEl.textContent = `${slider.value}%`;
    state.motionWeights[key] = parseFloat(slider.value) || 0;
    if (DOM.selPresetStyle) DOM.selPresetStyle.value = 'custom';
  });
}
syncWeightSlider(DOM.slZoomIn, DOM.valZoomIn, 'zoom_in');
syncWeightSlider(DOM.slZoomOut, DOM.valZoomOut, 'zoom_out');
syncWeightSlider(DOM.slPan, DOM.valPan, 'pan');
syncWeightSlider(DOM.slTilt, DOM.valTilt, 'tilt');

if (DOM.selPresetStyle) {
  DOM.selPresetStyle.addEventListener('change', () => {
    const val = DOM.selPresetStyle.value;
    state.presetId = val;
    if (val === 'normal') {
      state.motionWeights = { zoom_in: 25, zoom_out: 25, pan: 25, tilt: 25 };
      if (DOM.slZoomIn) DOM.slZoomIn.value = 25;
      if (DOM.slZoomOut) DOM.slZoomOut.value = 25;
      if (DOM.slPan) DOM.slPan.value = 25;
      if (DOM.slTilt) DOM.slTilt.value = 25;
      if (DOM.valZoomIn) DOM.valZoomIn.textContent = '25%';
      if (DOM.valZoomOut) DOM.valZoomOut.textContent = '25%';
      if (DOM.valPan) DOM.valPan.textContent = '25%';
      if (DOM.valTilt) DOM.valTilt.textContent = '25%';
      if (DOM.inpDefaultDuration) DOM.inpDefaultDuration.value = 5.0;
      showToast('Đã áp dụng phong cách Tiêu Chuẩn.', 'success');
    } else if (val === 'calm') {
      state.motionWeights = { zoom_in: 40, zoom_out: 40, pan: 10, tilt: 10 };
      if (DOM.slZoomIn) DOM.slZoomIn.value = 40;
      if (DOM.slZoomOut) DOM.slZoomOut.value = 40;
      if (DOM.slPan) DOM.slPan.value = 10;
      if (DOM.slTilt) DOM.slTilt.value = 10;
      if (DOM.valZoomIn) DOM.valZoomIn.textContent = '40%';
      if (DOM.valZoomOut) DOM.valZoomOut.textContent = '40%';
      if (DOM.valPan) DOM.valPan.textContent = '10%';
      if (DOM.valTilt) DOM.valTilt.textContent = '10%';
      if (DOM.inpDefaultDuration) DOM.inpDefaultDuration.value = 6.5;
      showToast('Đã áp dụng phong cách Êm Đềm / Trầm Lặng.', 'success');
    } else if (val === 'fast') {
      state.motionWeights = { zoom_in: 15, zoom_out: 15, pan: 35, tilt: 35 };
      if (DOM.slZoomIn) DOM.slZoomIn.value = 15;
      if (DOM.slZoomOut) DOM.slZoomOut.value = 15;
      if (DOM.slPan) DOM.slPan.value = 35;
      if (DOM.slTilt) DOM.slTilt.value = 35;
      if (DOM.valZoomIn) DOM.valZoomIn.textContent = '15%';
      if (DOM.valZoomOut) DOM.valZoomOut.textContent = '15%';
      if (DOM.valPan) DOM.valPan.textContent = '35%';
      if (DOM.valTilt) DOM.valTilt.textContent = '35%';
      if (DOM.inpDefaultDuration) DOM.inpDefaultDuration.value = 3.2;
      showToast('Đã áp dụng phong cách Nhanh / Sôi Động.', 'success');
    }
  });
}

// -----------------------------------------------------------------------------
// Project Generator & Progress Tracking
// -----------------------------------------------------------------------------
function assembleCurrentProjectPayload() {
  let pName = DOM.inpProjectName.value.trim();
  if (!pName) {
    pName = generateDefaultProjectName();
    DOM.inpProjectName.value = pName;
  }

  const script = DOM.inpScriptText.value.trim();
  const srt = DOM.outSrtContent.value.trim();
  const hasSub = bool(srt || (script && state.audioPath));

  // If there are unresolved missing image numbers (001-xxx), auto-stretch previous to ensure gap-free timeline
  let imagesToUse = [...state.mediaList];
  const analysis = detectMissingIndices(imagesToUse);
  const spanWithinCap =
    !!analysis &&
    Number.isFinite(analysis.min) &&
    Number.isFinite(analysis.max) &&
    analysis.max >= analysis.min &&
    analysis.max - analysis.min + 1 <= MEDIA_INDEX_MAX_SPAN;
  if (analysis && analysis.missing.length > 0 && spanWithinCap) {
    const { min, max, mapped } = analysis;
    const lookup = new Map();
    mapped.forEach((m) => {
      if (!lookup.has(m.num)) lookup.set(m.num, m.path);
    });
    const filled = [];
    for (let i = min; i <= max; i++) {
      if (lookup.has(i)) {
        filled.push(lookup.get(i));
      } else {
        let prev = null;
        for (let j = i - 1; j >= min; j--) {
          if (lookup.has(j)) {
            prev = lookup.get(j);
            break;
          }
        }
        filled.push(prev || mapped[0].path);
      }
    }
    imagesToUse = filled;
  }

  return {
    project_name: pName,
    aspect_ratio: DOM.selAspectRatio.value,
    fps: parseFloat(DOM.selFps.value) || 60.0,
    images: imagesToUse,
    audio_path: state.audioPath,
    script_text: script || null,
    srt_source: srt || null,
    timing_mode: srt ? 'SRT_DRIVEN' : (script && state.audioPath ? 'SRT_DRIVEN' : 'FIXED'),
    custom_clip_duration_s: parseFloat(DOM.inpDefaultDuration.value) || 5.0,
    motion_weights: { ...state.motionWeights },
    preset_id: DOM.selPresetStyle ? DOM.selPresetStyle.value : (state.presetId || 'normal'),
    auto_install: true,
    auto_upscale: DOM.chkAutoUpscale.checked,
    caption_font_size: parseFloat(DOM.inpSubFontSize.value) || 8.0,
    caption_position_y: parseFloat(DOM.inpSubPosY.value) || -0.6,
  };
}

function bool(val) {
  return !!val;
}

// Sidecar Event Listener
window.autoedit.onProgress((data) => {
  if (data) {
    const pct = data.percent || 0;
    const msg = data.message || 'Đang xử lý...';
    DOM.progressModalPct.textContent = `${pct}%`;
    DOM.progressModalFill.style.width = `${pct}%`;
    DOM.progressModalStage.textContent = msg;
    DOM.progressModalDetail.textContent = `Giai đoạn: ${data.stage || 'PROCESSING'}`;
  }
});

// Single Direct Project Creation
DOM.btnGenerateProject.addEventListener('click', async () => {
  if (DOM.btnGenerateProject.disabled) return;

  if (state.mediaList.length === 0) {
    showAlert('Vui lòng chọn hoặc kéo thả ít nhất 1 hình ảnh để tạo dự án.', 'Thiếu Ảnh');
    return;
  }

  const origHtml = DOM.btnGenerateProject.innerHTML;
  DOM.btnGenerateProject.disabled = true;
  DOM.btnGenerateProject.innerHTML = '<i data-lucide="loader-2" class="icon-sm animate-spin"></i> Đang tạo dự án...'; refreshIcons(DOM.btnGenerateProject);

  const payload = assembleCurrentProjectPayload();

  showModal(DOM.modalProgress);
  DOM.progressModalTitle.textContent = `Đang Tạo "${payload.project_name}"...`;
  DOM.progressModalPct.textContent = '10%';
  DOM.progressModalFill.style.width = '10%';
  DOM.progressModalStage.textContent = 'Chuẩn bị dữ liệu và thư viện...';

  try {
    const res = await window.autoedit.generateProject(payload);

    const isSuccess = res && (
      res.status === 'READY' ||
      res.ok === true ||
      res.outcome === 'SUCCESS' ||
      res.outcome === 'SUCCESS_WITH_WARNING' ||
      !!res.final_draft_dir
    );

    if (isSuccess) {
      DOM.progressModalPct.textContent = '100%';
      DOM.progressModalFill.style.width = '100%';
      DOM.progressModalStage.textContent = 'Hoàn thành!';

      // Record in Projects Store (decoupled, failures do NOT invalidate project creation)
      try {
        const projectRecord = {
          id: res.project_id || `proj_${Date.now()}`,
          name: payload.project_name,
          aspectRatio: payload.aspect_ratio,
          imageCount: payload.images.length,
          durationS: res.duration_s || res.total_duration_s || 0,
          hasAudio: !!payload.audio_path,
          draftDir: res.final_draft_dir,
          createdAt: Date.now(),
          studioData: {
            projectName: payload.project_name,
            aspectRatio: payload.aspect_ratio,
            mediaList: [...state.mediaList],
            audioPath: state.audioPath,
            scriptText: DOM.inpScriptText ? DOM.inpScriptText.value : '',
            srtContent: DOM.inpSrtText ? DOM.inpSrtText.value : '',
            presetId: payload.preset_id || 'normal',
          },
        };
        state.projects.unshift(projectRecord);
        await saveProjects();
      } catch (saveErr) {
        console.warn('Could not save project to local store:', saveErr);
        showToast('Dự án đã được tạo. Không thể cập nhật danh sách dự án.', 'warning');
      }

      // Wallet balance refresh (decoupled)
      try {
        if (typeof refreshWalletBalance === 'function') {
          await refreshWalletBalance();
        }
      } catch (wErr) {
        console.warn('Wallet refresh failed:', wErr);
      }

      setTimeout(() => {
        hideModal(DOM.modalProgress);
        const isWarning = res.outcome === 'SUCCESS_WITH_WARNING' || !!res.warning || !!res.secondary_message;
        if (isWarning) {
          const warnMsg = res.secondary_message || res.warning || 'Không thể mở CapCut tự động.';
          showAlert(`Dự án "${payload.project_name}" đã được tạo thành công.\n${warnMsg}`, 'Thành Công (Có Lưu Ý)');
          showToast(`Dự án "${payload.project_name}" đã được tạo thành công. ${warnMsg}`, 'warning', 4500);
        } else {
          showToast(`Dự án "${payload.project_name}" đã được tạo thành công!`, 'success', 3500);
          showAlert(`Dự án "${payload.project_name}" đã được tạo thành công.\nBạn có thể mở ngay trong CapCut hoặc xem trong mục Dự Án.`, 'Thành Công');
        }

        // Auto open if checked (isolated try/catch)
        if (DOM.chkAutoOpenCapcut && DOM.chkAutoOpenCapcut.checked && res.final_draft_dir) {
          try {
            window.autoedit.openCapCut(res.final_draft_dir).catch((err) => {
              console.warn('Auto-open CapCut failed:', err);
              showToast('Không thể mở CapCut tự động.', 'warning');
            });
          } catch (openErr) {
            console.warn('Auto-open CapCut call failed:', openErr);
            showToast('Không thể mở CapCut tự động.', 'warning');
          }
        }
      }, 500);
    } else {
      const errDetail = res?.primary_message
        ? (res.secondary_message ? `${res.primary_message}\n${res.secondary_message}` : res.primary_message)
        : (res?.error || 'Không thể tạo dự án CapCut lúc này.');
      throw new Error(errDetail);
    }
  } catch (err) {
    hideModal(DOM.modalProgress);
    let friendlyMsg = err.message || 'Không thể tạo dự án CapCut lúc này.';
    if (friendlyMsg.includes('Traceback') || friendlyMsg.includes('[object Object]')) {
      friendlyMsg = 'Không thể tạo dự án CapCut lúc này. Vui lòng kiểm tra lại cấu hình thư mục CapCut hoặc thử lại.';
    }
    showAlert(`Lỗi tạo dự án:\n${friendlyMsg}`, 'Thất Bại');
    showToast(friendlyMsg, 'error', 5000);
  } finally {
    DOM.btnGenerateProject.disabled = false;
    DOM.btnGenerateProject.innerHTML = origHtml;
  }
});

// -----------------------------------------------------------------------------
// Sub-Tab Switcher for Queue View (Queue A: Build vs Queue B: Render)
// -----------------------------------------------------------------------------
if (DOM.tabSubQueueBuild && DOM.tabSubQueueRender) {
  DOM.tabSubQueueBuild.addEventListener('click', () => {
    DOM.tabSubQueueBuild.classList.add('active');
    DOM.tabSubQueueRender.classList.remove('active');
    if (DOM.subpaneBuildQueue) DOM.subpaneBuildQueue.style.display = 'block';
    if (DOM.subpaneRenderQueue) DOM.subpaneRenderQueue.style.display = 'none';
  });

  DOM.tabSubQueueRender.addEventListener('click', () => {
    DOM.tabSubQueueRender.classList.add('active');
    DOM.tabSubQueueBuild.classList.remove('active');
    if (DOM.subpaneBuildQueue) DOM.subpaneBuildQueue.style.display = 'none';
    if (DOM.subpaneRenderQueue) DOM.subpaneRenderQueue.style.display = 'block';
  });
}

// -----------------------------------------------------------------------------
// Project Build Queue Manager (Queue A - Wave 1)
// Connects 1:1 to Python ProjectBuildQueueManager FSM
// -----------------------------------------------------------------------------

DOM.btnAddToQueue.addEventListener('click', async () => {
  if (DOM.btnAddToQueue.disabled) return;

  if (state.mediaList.length === 0) {
    showAlert('Vui lòng chọn ít nhất 1 hình ảnh trước khi thêm vào hàng đợi.', 'Thiếu Ảnh');
    return;
  }

  DOM.btnAddToQueue.disabled = true;
  const payload = assembleCurrentProjectPayload();
  try {
    const res = await window.autoedit.enqueueBuildJob({
      payload,
      project_name: payload.project_name,
    });
    if (res && res.ok) {
      showToast(`Đã thêm dự án "${payload.project_name}" vào Hàng Đợi Tạo Dự Án!`, 'success', 3000);
      DOM.inpProjectName.value = generateDefaultProjectName();
      await refreshBuildQueueUI();
    } else {
      showAlert('Không thể thêm vào hàng đợi: ' + (res?.error || 'Lỗi không xác định'), 'Lỗi');
    }
  } catch (err) {
    showAlert('Lỗi thêm vào hàng đợi: ' + err.message, 'Lỗi');
  } finally {
    DOM.btnAddToQueue.disabled = false;
  }
});

function updateQueueBadge() {
  let pendingBuild = 0;
  if (state.buildQueue && state.buildQueue.jobs) {
    pendingBuild = state.buildQueue.jobs.filter((j) =>
      ['QUEUED', 'VALIDATING', 'PINNING_INPUTS', 'UPSCALING', 'SUBTITLE', 'WAITING_SRT_REVIEW', 'TIMELINE', 'BUILDING_DRAFT', 'VERIFYING'].includes(j.state)
    ).length;
  }
  let pendingRender = 0;
  if (state.renderQueue && state.renderQueue.jobs) {
    pendingRender = state.renderQueue.jobs.filter((j) =>
      ['QUEUED', 'PRECHECK', 'STARTING_CAPCUT', 'OPENING_PROJECT', 'TRIGGERING_EXPORT', 'CONFIRMING_EXPORT', 'RENDERING', 'VERIFYING_OUTPUT'].includes(j.status)
    ).length;
  }
  const totalPending = pendingBuild + pendingRender;
  if (DOM.queueBadge) {
    DOM.queueBadge.textContent = String(totalPending);
    DOM.queueBadge.style.display = totalPending > 0 ? 'inline-block' : 'none';
  }
}

function renderBuildQueueTableFromState(queueData) {
  if (!queueData) return;

  state.buildQueue.status = queueData.queue_status || 'IDLE';
  state.buildQueue.jobs = queueData.jobs || [];
  state.buildQueue.totalJobs = queueData.total_jobs || state.buildQueue.jobs.length;
  state.buildQueue.activeJob = queueData.active_job || null;

  // Update Status Badge in Header
  if (DOM.buildQueueStatusBadge) {
    const bStatus = state.buildQueue.status;
    if (bStatus === 'RUNNING') {
      DOM.buildQueueStatusBadge.innerHTML = '<i data-lucide="play" class="icon-xs" style="margin-right:4px;"></i>Đang Xử Lý'; refreshIcons(DOM.buildQueueStatusBadge);
      DOM.buildQueueStatusBadge.style.color = '#34d399';
      DOM.buildQueueStatusBadge.style.borderColor = 'rgba(52,211,153,0.3)';
    } else if (bStatus === 'STOPPING') {
      DOM.buildQueueStatusBadge.innerHTML = '<i data-lucide="square" class="icon-xs" style="margin-right:4px;"></i>Đang Dừng Dần...'; refreshIcons(DOM.buildQueueStatusBadge);
      DOM.buildQueueStatusBadge.style.color = '#facc15';
      DOM.buildQueueStatusBadge.style.borderColor = 'rgba(250,204,21,0.3)';
    } else {
      DOM.buildQueueStatusBadge.innerHTML = '<i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>Đang Chờ'; refreshIcons(DOM.buildQueueStatusBadge);
      DOM.buildQueueStatusBadge.style.color = '#94a3b8';
      DOM.buildQueueStatusBadge.style.borderColor = 'rgba(148,163,184,0.3)';
    }
  }

  updateQueueBadge();

  // Auto-sync completed build jobs into state.projects
  let newlyAdded = false;
  state.buildQueue.jobs.forEach((job) => {
    if (job.state === 'PROJECT_READY' && job.result?.final_draft_dir) {
      const existing = state.projects.find((p) => p.draftDir === job.result.final_draft_dir || p.id === job.job_id);
      if (!existing) {
        state.projects.unshift({
          id: job.job_id,
          name: job.project_name,
          aspectRatio: job.payload?.aspect_ratio || '9:16',
          imageCount: (job.payload?.images || []).length,
          durationS: job.result?.duration_s || 0,
          hasAudio: !!job.payload?.audio_path,
          draftDir: job.result.final_draft_dir,
          createdAt: job.completed_at ? Math.round(job.completed_at * 1000) : Date.now(),
          studioData: {
            projectName: job.project_name,
            aspectRatio: job.payload?.aspect_ratio,
            mediaList: job.payload?.images || [],
            audioPath: job.payload?.audio_path,
            scriptText: job.payload?.script_text || '',
            srtContent: job.payload?.srt_source || '',
            presetId: job.payload?.preset_id || 'normal',
          },
        });
        newlyAdded = true;
      }
    }
  });
  if (newlyAdded) {
    saveProjects();
    if (typeof refreshWalletBalance === 'function') {
      refreshWalletBalance();
    }
  }

  if (!DOM.buildQueueTableBody) return;
  DOM.buildQueueTableBody.innerHTML = '';

  if (state.buildQueue.jobs.length === 0) {
    DOM.buildQueueTableBody.innerHTML = `
      <tr class="empty-row">
        <td colspan="5">Hàng đợi tạo dự án đang trống. Hãy bấm "Thêm vào hàng đợi" từ Studio!</td>
      </tr>
    `;
    return;
  }

  state.buildQueue.jobs.forEach((job) => {
    const tr = document.createElement('tr');
    const st = job.state;

    let statusBadge = '';
    if (st === 'QUEUED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)"><i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>Đang Chờ</span>';
    } else if (st === 'PROJECT_READY') {
      statusBadge = '<span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3)"><i data-lucide="check" class="icon-xs" style="margin-right:4px;"></i>Dự Án Sẵn Sàng</span>';
    } else if (st === 'FAILED') {
      statusBadge = '<span class="status-badge" style="color:#f87171;border-color:rgba(248,113,113,0.3)"><i data-lucide="alert-circle" class="icon-xs" style="margin-right:4px;"></i>Lỗi</span>';
    } else if (st === 'CANCELLED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)"><i data-lucide="square" class="icon-xs" style="margin-right:4px;"></i>Đã Hủy</span>';
    } else if (st === 'WAITING_SRT_REVIEW') {
      statusBadge = '<span class="status-badge" style="color:#fb923c;border-color:rgba(251,146,60,0.3)"><i data-lucide="file-text" class="icon-xs" style="margin-right:4px;"></i>Chờ Duyệt SRT</span>';
    } else {
      statusBadge = `<span class="status-badge" style="color:#facc15;border-color:rgba(250,204,21,0.3)"><i data-lucide="zap" class="icon-xs" style="margin-right:4px;"></i>${escapeHtml(job.current_step || 'Đang xử lý...')}</span>`;
    }

    // Step / Progress Display
    let stepDisplay = `
      <div style="font-size:12px; font-weight:500;">${escapeHtml(job.current_step || '')}</div>
      <div class="progress-bar-wrap" style="height:4px; background:rgba(255,255,255,0.1); border-radius:2px; margin-top:4px; overflow:hidden;">
        <div style="height:100%; width:${Math.min(100, Math.max(0, job.progress || 0))}%; background:var(--color-primary); transition:width 0.3s;"></div>
      </div>
    `;
    if (st === 'FAILED' && job.error) {
      stepDisplay += `<div style="font-size:11px; color:#f87171; margin-top:3px;">${escapeHtml(job.error)}</div>`;
    }

    // Time display
    let timeStr = '--';
    if (job.started_at && job.completed_at) {
      timeStr = `${Math.round(job.completed_at - job.started_at)}s`;
    } else if (job.created_at) {
      timeStr = new Date(job.created_at * 1000).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
    }

    // Actions
    let actionsHtml = '';
    const safeJobId = escapeHtml(job.job_id);
    if (st === 'QUEUED') {
      actionsHtml = `
        <button class="btn-action-primary" style="padding:2px 8px; font-size:11px;" onclick="buildSingleProject('${safeJobId}')"><i data-lucide="zap" class="icon-xs"></i> Tạo Dự Án</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="cancelBuildJob('${safeJobId}')"><i data-lucide="x" class="icon-xs"></i> Hủy</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')"><i data-lucide="trash-2" class="icon-xs"></i> Xóa</button>
      `;
    } else if (st === 'PROJECT_READY') {
      const draftDir = job.result?.final_draft_dir || '';
      const safeDraftDir = draftDir.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      const safeProjName = (job.project_name || 'project').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      actionsHtml = `
        <button class="btn-action-primary" style="padding:2px 8px; font-size:11px;" onclick="openDraftInCapCut('${safeDraftDir}')"><i data-lucide="clapperboard" class="icon-xs"></i> Mở CapCut</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="renderDraftNow('${safeDraftDir}', '${safeProjName}')"><i data-lucide="zap" class="icon-xs"></i> Render Ngay</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="addDraftToRenderQueue('${safeDraftDir}', '${safeProjName}')"><i data-lucide="plus" class="icon-xs"></i> Render Queue</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')"><i data-lucide="trash-2" class="icon-xs"></i></button>
      `;
    } else if (st === 'FAILED' || st === 'CANCELLED') {
      actionsHtml = `
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="retryBuildJob('${safeJobId}')"><i data-lucide="rotate-cw" class="icon-xs"></i> Thử Lại</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')"><i data-lucide="trash-2" class="icon-xs"></i> Xóa</button>
      `;
    } else {
      actionsHtml = `
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="cancelBuildJob('${safeJobId}')"><i data-lucide="x" class="icon-xs"></i> Hủy</button>
      `;
    }

    tr.innerHTML = `
      <td>
        <div style="font-weight:600; color:var(--color-text-main);">${escapeHtml(job.project_name)}</div>
        <div style="font-size:10.5px; color:var(--color-text-muted); margin-top:2px;">
          Tỷ lệ: ${escapeHtml(job.payload?.aspect_ratio || '9:16')} • ${job.payload?.images?.length || 0} ảnh
        </div>
      </td>
      <td>${statusBadge}</td>
      <td>${stepDisplay}</td>
      <td style="font-size:11.5px; color:var(--color-text-muted);">${timeStr}</td>
      <td><div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">${actionsHtml}</div></td>
    `;
    DOM.buildQueueTableBody.appendChild(tr);
  });
}

window.buildSingleProject = async (jobId) => {
  try {
    await window.autoedit.buildProjectJob({ job_id: jobId });
    await refreshBuildQueueUI();
  } catch (err) {
    showAlert('Lỗi tạo dự án: ' + err.message, 'Lỗi');
  }
};

window.cancelBuildJob = async (jobId) => {
  try {
    await window.autoedit.cancelBuildJob({ job_id: jobId });
    await refreshBuildQueueUI();
  } catch (err) {
    showAlert('Lỗi hủy dự án: ' + err.message, 'Lỗi');
  }
};

window.retryBuildJob = async (jobId) => {
  try {
    await window.autoedit.retryBuildJob({ job_id: jobId });
    await refreshBuildQueueUI();
  } catch (err) {
    showAlert('Lỗi thử lại: ' + err.message, 'Lỗi');
  }
};

window.removeBuildJob = async (jobId) => {
  try {
    await window.autoedit.removeBuildJob({ job_id: jobId });
    await refreshBuildQueueUI();
  } catch (err) {
    showAlert('Lỗi xóa mục hàng đợi: ' + err.message, 'Lỗi');
  }
};

async function refreshBuildQueueUI() {
  if (!window.autoedit?.getBuildQueueState) return;
  try {
    const res = await window.autoedit.getBuildQueueState();
    if (res) renderBuildQueueTableFromState(res);
  } catch (err) {
    console.warn('Failed refreshing build queue:', err);
  }
}

if (DOM.btnBuildAllProjects) {
  DOM.btnBuildAllProjects.addEventListener('click', async () => {
    try {
      const res = await window.autoedit.buildAllProjects();
      if (!res?.ok) {
        showToast('Không có dự án nào đang chờ trong hàng đợi.', 'info', 2500);
      } else {
        showToast('Đã bắt đầu tạo tất cả dự án tuần tự!', 'success', 2500);
      }
      await refreshBuildQueueUI();
    } catch (err) {
      showAlert('Lỗi chạy hàng đợi tạo dự án: ' + err.message, 'Lỗi');
    }
  });
}

if (DOM.btnClearBuildQueue) {
  DOM.btnClearBuildQueue.addEventListener('click', async () => {
    try {
      const res = await window.autoedit.clearCompletedBuildJobs();
      const count = res?.cleared_count || 0;
      showToast(`Đã xóa ${count} dự án đã xong/hủy!`, 'success', 2500);
      await refreshBuildQueueUI();
    } catch (err) {
      showAlert('Lỗi dọn dẹp: ' + err.message, 'Lỗi');
    }
  });
}

// -----------------------------------------------------------------------------
// Authoritative Render Queue Controller (GAP-02 & GAP-03)
// Connected 1:1 to Python RenderQueueManager FSM
// -----------------------------------------------------------------------------

function renderQueueTableFromState(queueData) {
  if (!queueData) return;

  state.renderQueue.status = queueData.queue_status || 'IDLE';
  state.renderQueue.jobs = queueData.jobs || [];
  state.renderQueue.totalJobs = queueData.total_jobs || state.renderQueue.jobs.length;
  state.renderQueue.activeJob = queueData.active_job || null;

  // Update Status Badge in Header
  if (DOM.queueStatusBadge) {
    const qStatus = state.renderQueue.status;
    if (qStatus === 'RUNNING') {
      DOM.queueStatusBadge.innerHTML = '<i data-lucide="play" class="icon-xs" style="margin-right:4px;"></i>Đang Xử Lý'; refreshIcons(DOM.queueStatusBadge);
      DOM.queueStatusBadge.style.color = '#34d399';
      DOM.queueStatusBadge.style.borderColor = 'rgba(52,211,153,0.3)';
    } else if (qStatus === 'PAUSED') {
      DOM.queueStatusBadge.innerHTML = '<i data-lucide="pause" class="icon-xs" style="margin-right:4px;"></i>Đang Tạm Dừng'; refreshIcons(DOM.queueStatusBadge);
      DOM.queueStatusBadge.style.color = '#fb923c';
      DOM.queueStatusBadge.style.borderColor = 'rgba(251,146,60,0.3)';
    } else if (qStatus === 'STOPPING') {
      DOM.queueStatusBadge.innerHTML = '<i data-lucide="square" class="icon-xs" style="margin-right:4px;"></i>Đang Dừng Dần...'; refreshIcons(DOM.queueStatusBadge);
      DOM.queueStatusBadge.style.color = '#facc15';
      DOM.queueStatusBadge.style.borderColor = 'rgba(250,204,21,0.3)';
    } else {
      DOM.queueStatusBadge.innerHTML = '<i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>Đang Chờ'; refreshIcons(DOM.queueStatusBadge);
      DOM.queueStatusBadge.style.color = '#94a3b8';
      DOM.queueStatusBadge.style.borderColor = 'rgba(148,163,184,0.3)';
    }
  }

  updateQueueBadge();

  if (!DOM.queueTableBody) return;
  DOM.queueTableBody.innerHTML = '';

  if (state.renderQueue.jobs.length === 0) {
    DOM.queueTableBody.innerHTML = `
      <tr class="empty-row">
        <td colspan="6">Hàng đợi xuất video đang trống. Hãy bấm "Render Ngay" hoặc "Thêm Hàng Đợi" từ tab Dự Án!</td>
      </tr>
    `;
    return;
  }

  state.renderQueue.jobs.forEach((job) => {
    const tr = document.createElement('tr');
    const st = job.status;

    let statusBadge = '';
    if (st === 'QUEUED') {
      statusBadge = '<span class="status-badge" style="color:var(--text-muted);border-color:rgba(148,163,184,0.3)"><i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>Đang Chờ</span>';
    } else if (st === 'DONE') {
      statusBadge = '<span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3)"><i data-lucide="check" class="icon-xs" style="margin-right:4px;"></i>Hoàn Thành</span>';
    } else if (st === 'FAILED') {
      statusBadge = '<span class="status-badge" style="color:var(--danger);border-color:rgba(248,113,113,0.3)"><i data-lucide="alert-circle" class="icon-xs" style="margin-right:4px;"></i>Lỗi</span>';
    } else if (st === 'CANCELLED') {
      statusBadge = '<span class="status-badge" style="color:var(--text-muted);border-color:rgba(148,163,184,0.3)"><i data-lucide="square" class="icon-xs" style="margin-right:4px;"></i>Đã Hủy</span>';
    } else if (st === 'SKIPPED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)"><i data-lucide="skip-forward" class="icon-xs" style="margin-right:4px;"></i>Bỏ Qua</span>';
    } else if (st.includes('PAUSED')) {
      statusBadge = '<span class="status-badge" style="color:#fb923c;border-color:rgba(251,146,60,0.3)"><i data-lucide="pause" class="icon-xs" style="margin-right:4px;"></i>Tạm Dừng</span>';
    } else if (st === 'VERIFYING_OUTPUT') {
      statusBadge = '<span class="status-badge" style="color:#a78bfa;border-color:rgba(167,139,250,0.3)"><i data-lucide="search" class="icon-xs" style="margin-right:4px;"></i>Xác Thực MP4</span>';
    } else {
      statusBadge = '<span class="status-badge" style="color:#facc15;border-color:rgba(250,204,21,0.3)"><i data-lucide="zap" class="icon-xs" style="margin-right:4px;"></i>Đang Xuất...</span>';
    }

    // Stage text (No fake percentages, honest FSM description)
    let stageDisplay = '';
    if (st === 'DONE') stageDisplay = 'Video MP4 toàn vẹn';
    else if (st === 'FAILED') stageDisplay = 'Dừng lại do lỗi';
    else if (st === 'QUEUED') stageDisplay = 'Chờ đến lượt';
    else if (st === 'PRECHECK') stageDisplay = 'Kiểm tra thư mục draft';
    else if (st === 'STARTING_CAPCUT') stageDisplay = 'Kích hoạt CapCut Desktop';
    else if (st === 'OPENING_PROJECT') stageDisplay = 'Mở dự án trong CapCut';
    else if (st === 'TRIGGERING_EXPORT') stageDisplay = 'Gửi phím xuất (Ctrl+E)';
    else if (st === 'CONFIRMING_EXPORT') stageDisplay = 'Xác nhận xuất video (Enter)';
    else if (st === 'RENDERING') stageDisplay = 'CapCut đang render ghi file...';
    else if (st === 'VERIFYING_OUTPUT') stageDisplay = 'Kiểm tra ffprobe container';
    else if (st === 'CANCELLED') stageDisplay = 'Người dùng đã hủy';
    else if (st === 'SKIPPED') stageDisplay = 'Đã bỏ qua';
    else stageDisplay = st;

    // Time display
    let timeStr = '--';
    if (job.started_at && job.finished_at) {
      const dur = Math.round(job.finished_at - job.started_at);
      timeStr = dur < 60 ? `${dur}s` : `${Math.floor(dur / 60)}m ${dur % 60}s`;
    } else if (job.started_at) {
      const el = Math.round(Date.now() / 1000 - job.started_at);
      timeStr = el < 60 ? `${el}s` : `${Math.floor(el / 60)}m ${el % 60}s`;
    } else if (job.created_at) {
      const d = new Date(job.created_at * 1000);
      timeStr = d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    const outFilename = job.output_filename || (job.output_path ? job.output_path.split(/[\\/]/).pop() : 'output.mp4');

    let actionBtns = '';
    if (st === 'DONE') {
      actionBtns = `
        <button class="btn-subtle" onclick="window.openOutputFile('${escapePath(job.output_path)}')"><i data-lucide="play" class="icon-xs"></i> Mở File</button>
        <button class="btn-subtle" onclick="window.openOutputFolder('${escapePath(job.output_path)}')"><i data-lucide="folder" class="icon-xs"></i> Thư Mục</button>
      `;
    } else if (st === 'FAILED') {
      actionBtns = `
        <button class="btn-subtle" onclick="window.retryRenderJob('${job.job_id}')"><i data-lucide="rotate-cw" class="icon-xs"></i> Thử Lại</button>
        <button class="btn-subtle btn-danger" onclick="window.showJobError('${job.job_id}')"><i data-lucide="info" class="icon-xs"></i> Lỗi</button>
      `;
    } else if (st === 'QUEUED' || st.includes('PAUSED')) {
      actionBtns = `
        <button class="btn-subtle" onclick="window.skipRenderJob('${job.job_id}')"><i data-lucide="skip-forward" class="icon-xs"></i> Bỏ Qua</button>
        <button class="btn-subtle btn-danger" onclick="window.cancelRenderJob('${job.job_id}')"><i data-lucide="x" class="icon-xs"></i> Hủy</button>
      `;
    } else {
      actionBtns = `
        <button class="btn-subtle btn-danger" onclick="window.cancelRenderJob('${job.job_id}')"><i data-lucide="square" class="icon-xs"></i> Dừng</button>
      `;
    }

    tr.innerHTML = `
      <td><strong>${escapeHtml(job.project_id || job.job_id)}</strong></td>
      <td>${statusBadge}</td>
      <td><span style="font-size:12px;color:#cbd5e1;">${escapeHtml(stageDisplay)}</span></td>
      <td><span class="font-mono" title="${escapeHtml(job.output_path || '')}" style="font-size:11px;color:#94a3b8;cursor:help;">${escapeHtml(outFilename)}</span></td>
      <td><span style="font-size:12px;color:#94a3b8;">${escapeHtml(timeStr)}</span></td>
      <td><div style="display:flex;gap:4px;flex-wrap:wrap;">${actionBtns}</div></td>
    `;

    DOM.queueTableBody.appendChild(tr);
  });
}

function checkRenderJobStatusChanges(queueData) {
  if (!queueData || !queueData.jobs) return;
  const currentJobs = queueData.jobs;

  for (const job of currentJobs) {
    const prevStatus = state.lastJobStatuses[job.job_id];
    const newStatus = job.status;

    if (prevStatus && prevStatus !== newStatus) {
      if (newStatus === 'DONE') {
        showRenderCompletionModal(job);
      } else if (newStatus === 'FAILED') {
        showRenderFailureModal(job);
      }
    }
    state.lastJobStatuses[job.job_id] = newStatus;
  }
}

function showRenderCompletionModal(job) {
  if (!DOM.modalRenderResult) return;
  DOM.renderResultTitle.innerHTML = '<i data-lucide="clapperboard" class="icon-sm" style="color:var(--brand);margin-right:6px;"></i>Xuất Video Thành Công'; refreshIcons(DOM.renderResultTitle);
  DOM.renderResultProjectName.textContent = job.project_id || 'Dự Án CapCut';
  DOM.renderResultOutputPath.textContent = job.output_path || '--';

  if (job.started_at && job.finished_at) {
    const durSec = Math.round(job.finished_at - job.started_at);
    DOM.wrapRenderDuration.style.display = 'flex';
    DOM.renderResultDuration.textContent = `${durSec} giây`;
  } else {
    DOM.wrapRenderDuration.style.display = 'none';
  }

  DOM.renderResultSuccessContent.style.display = 'block';
  DOM.renderResultErrorContent.style.display = 'none';
  DOM.renderResultSuccessActions.style.display = 'flex';
  DOM.renderResultErrorActions.style.display = 'none';

  DOM.btnOpenRenderedFile.onclick = () => {
    if (job.output_path && window.autoedit?.openPath) {
      window.autoedit.openPath(job.output_path);
    }
  };

  DOM.btnOpenRenderedFolder.onclick = () => {
    if (job.output_path && window.autoedit?.showItemInFolder) {
      window.autoedit.showItemInFolder(job.output_path);
    }
  };

  if (DOM.btnUploadRenderToCloud) {
    DOM.btnUploadRenderToCloud.onclick = async () => {
      if (!state.currentUser) {
        showAlert('Vui lòng đăng nhập tài khoản 2TOOLNE để tải video lên Cloud.', 'Cần Đăng Nhập');
        return;
      }
      if (!job.output_path) {
        showAlert('Không tìm thấy đường dẫn tệp video.', 'Lỗi');
        return;
      }
      startCloudUpload(job.output_path);
    };
  }

  showModal(DOM.modalRenderResult);
}

function showRenderFailureModal(job) {
  if (!DOM.modalRenderResult) return;
  DOM.renderResultTitle.innerHTML = '<i data-lucide="triangle-alert" class="icon-sm" style="color:var(--warning);margin-right:6px;"></i>Lỗi Xuất Video CapCut'; refreshIcons(DOM.renderResultTitle);
  DOM.renderResultErrorProject.textContent = `Dự Án: ${job.project_id || 'Không xác định'}`;

  const errObj = job.last_error || {};
  const reason = (typeof errObj === 'object' ? errObj.message : String(errObj)) || 'Render video thất bại trong quá trình tự động hóa.';
  const details = (typeof errObj === 'object' && errObj.details) ? JSON.stringify(errObj.details, null, 2) : (typeof errObj === 'object' ? JSON.stringify(errObj, null, 2) : '');

  DOM.renderResultErrorMessage.textContent = reason;
  DOM.renderResultErrorDetails.textContent = details || reason;

  DOM.renderResultSuccessContent.style.display = 'none';
  DOM.renderResultErrorContent.style.display = 'block';
  DOM.renderResultSuccessActions.style.display = 'none';
  DOM.renderResultErrorActions.style.display = 'flex';

  DOM.btnRetryRenderJobModal.onclick = async () => {
    hideModal(DOM.modalRenderResult);
    await window.retryRenderJob(job.job_id);
  };

  showModal(DOM.modalRenderResult);
}

async function refreshRenderQueueUI() {
  if (!window.autoedit || !window.autoedit.getRenderQueueState) return;
  try {
    const res = await window.autoedit.getRenderQueueState();
    if (res) {
      renderQueueTableFromState(res);
      checkRenderJobStatusChanges(res);
    }
  } catch (err) {
    console.error('Failed to query render queue state:', err);
  }
}

// Global window actions for queue rows
window.openOutputFile = (targetPath) => {
  if (targetPath && window.autoedit?.openPath) {
    window.autoedit.openPath(targetPath);
  }
};

window.openOutputFolder = (targetPath) => {
  if (targetPath && window.autoedit?.showItemInFolder) {
    window.autoedit.showItemInFolder(targetPath);
  }
};

window.retryRenderJob = async (jobId) => {
  if (!jobId || !window.autoedit?.controlRenderQueue) return;
  try {
    const res = await window.autoedit.controlRenderQueue({ action: 'retry', job_id: jobId });
    if (res && res.ok) {
      showToast('Đã xếp hàng thử lại tiến trình render!', 'success', 2500);
      await refreshRenderQueueUI();
    } else {
      showAlert('Không thể thử lại job này.', 'Lỗi');
    }
  } catch (e) {
    showAlert('Lỗi: ' + e.message, 'Lỗi', true);
  }
};

window.cancelRenderJob = async (jobId) => {
  if (!jobId || !window.autoedit?.controlRenderQueue) return;
  try {
    const res = await window.autoedit.controlRenderQueue({ action: 'cancel', job_id: jobId });
    if (res && res.ok) {
      showToast('Đã hủy tác vụ render!', 'info', 2500);
      await refreshRenderQueueUI();
    }
  } catch (e) {
    showAlert('Lỗi hủy: ' + e.message, 'Lỗi', true);
  }
};

window.skipRenderJob = async (jobId) => {
  if (!jobId || !window.autoedit?.controlRenderQueue) return;
  try {
    const res = await window.autoedit.controlRenderQueue({ action: 'skip', job_id: jobId });
    if (res && res.ok) {
      showToast('Đã bỏ qua tác vụ này!', 'info', 2500);
      await refreshRenderQueueUI();
    }
  } catch (e) {
    showAlert('Lỗi: ' + e.message, 'Lỗi', true);
  }
};

window.showJobError = (jobId) => {
  const job = state.renderQueue.jobs.find((j) => j.job_id === jobId);
  if (job) {
    showRenderFailureModal(job);
  }
};

window.openDraftInCapCut = (draftDir) => {
  window.autoedit.openCapCut(draftDir);
};

// Queue Control Buttons (Legacy RenderQueue - Internal Only)
DOM.btnStartQueue?.addEventListener('click', async () => {
  if (!window.autoedit?.controlRenderQueue) return;
  try {
    await window.autoedit.controlRenderQueue({ action: 'resume' });
    showToast('Đã kích hoạt chạy hàng đợi render!', 'success', 2500);
    await refreshRenderQueueUI();
  } catch (err) {
    showAlert('Lỗi khởi động hàng đợi: ' + err.message, 'Lỗi', true);
  }
});

DOM.btnPauseQueue?.addEventListener('click', async () => {
  if (!window.autoedit?.controlRenderQueue) return;
  try {
    await window.autoedit.controlRenderQueue({ action: 'pause' });
    showToast('Đã tạm dừng hàng đợi render!', 'info', 2500);
    await refreshRenderQueueUI();
  } catch (err) {
    showAlert('Lỗi tạm dừng: ' + err.message, 'Lỗi', true);
  }
});

DOM.btnStopAfterCurrent?.addEventListener('click', async () => {
  if (!window.autoedit?.controlRenderQueue) return;
  try {
    await window.autoedit.controlRenderQueue({ action: 'stop_after_current' });
    showToast('Sẽ dừng hàng đợi sau khi render xong tác vụ hiện tại!', 'info', 3000);
    await refreshRenderQueueUI();
  } catch (err) {
    showAlert('Lỗi: ' + err.message, 'Lỗi', true);
  }
});

DOM.btnClearQueue?.addEventListener('click', async () => {
  if (!window.autoedit?.controlRenderQueue) return;
  try {
    const res = await window.autoedit.controlRenderQueue({ action: 'clear_completed' });
    const count = res?.cleared_count || 0;
    showToast(`Đã dọn dẹp ${count} tác vụ đã xong/hủy!`, 'success', 2500);
    await refreshRenderQueueUI();
  } catch (err) {
    showAlert('Lỗi dọn dẹp: ' + err.message, 'Lỗi', true);
  }
});

[DOM.btnCloseModalRenderResult, DOM.btnCloseRenderResultModal].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalRenderResult));
});

// -----------------------------------------------------------------------------
// Projects Grid View & Delegated Action Routing
// -----------------------------------------------------------------------------
let targetProjectToDelete = null;

function initProjectsEventDelegation() {
  const container = DOM.projectsGrid || document.getElementById('projectsGrid');
  if (!container || container.__projectsEventsDelegated) return;
  container.__projectsEventsDelegated = true;

  container.addEventListener('click', async (event) => {
    const btn = event.target.closest('[data-project-action]');
    if (!btn) return;
    const action = btn.dataset.projectAction;
    const projectId = btn.dataset.projectId;
    console.log(`[PROJECT_ACTION_CLICK] action=${action} projectId=${projectId || 'N/A'}`);
    await handleProjectAction(action, projectId, btn);
  });

  // Global dismiss listener: close open project context menus when clicking outside
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.project-edit-wrapper')) {
      document.querySelectorAll('.project-context-menu').forEach((menu) => {
        menu.style.display = 'none';
      });
    }
  });
}

async function handleProjectAction(action, projectId, btn) {
  if (!action) return;
  if (btn && btn.disabled) return;

  if (action === 'create-first') {
    switchTab('studio');
    return;
  }

  const proj = state.projects.find((p) => p.id === projectId);
  if (!proj) {
    console.warn('[PROJECT_ACTION] Project not found in state:', projectId);
    showAlert('Không tìm thấy dự án trong danh sách.', 'Lỗi Dự Án', true);
    return;
  }

  console.log(`[PROJECT_ACTION_DISPATCH] action=${action} projectId=${projectId} name=${proj.name}`);

  const withButtonLoading = async (taskFn) => {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    const icon = btn.querySelector('svg, i');
    if (icon) {
      icon.outerHTML = '<i data-lucide="loader-circle" class="icon-xs spin"></i>';
      refreshIcons(btn);
    }
    try {
      return await taskFn();
    } finally {
      btn.disabled = false;
      btn.innerHTML = origHtml;
      refreshIcons(btn);
    }
  };

  try {
    switch (action) {
      case 'edit-menu': {
        const menu = document.getElementById(`projectContextMenu_${projectId}`);
        if (!menu) return;
        const isShown = menu.style.display !== 'none';
        document.querySelectorAll('.project-context-menu').forEach((m) => {
          m.style.display = 'none';
        });
        if (!isShown) {
          menu.style.display = 'flex';
          refreshIcons(menu);
        }
        break;
      }

      case 'rename-project': {
        document.querySelectorAll('.project-context-menu').forEach((m) => {
          m.style.display = 'none';
        });
        const currentName = proj.name || '';
        const newName = window.prompt('Nhập tên mới cho dự án:', currentName);
        if (newName && newName.trim() && newName.trim() !== currentName) {
          proj.name = newName.trim();
          if (proj.studioData) {
            proj.studioData.projectName = proj.name;
          }
          await saveProjects();
          renderProjectsGrid();
          showToast(`Đã đổi tên dự án thành "${proj.name}".`, 'success', 3000);
        }
        break;
      }

      case 'regen-images': {
        document.querySelectorAll('.project-context-menu').forEach((m) => {
          m.style.display = 'none';
        });
        loadProjectToStudio(proj.id);
        switchTab('studio');
        showToast(`Đã nạp dự án "${proj.name}" vào Studio để tạo lại ảnh.`, 'info', 3000);
        break;
      }

      case 'regen-voice': {
        document.querySelectorAll('.project-context-menu').forEach((m) => {
          m.style.display = 'none';
        });
        loadProjectToStudio(proj.id);
        switchTab('studio');
        showToast(`Đã nạp dự án "${proj.name}" vào Studio để tạo lại Voice.`, 'info', 3000);
        break;
      }

      case 'upload-cloud': {
        await withButtonLoading(async () => {
          if (!state.currentUser) {
            showAlert('Vui lòng đăng nhập tài khoản 2TOOLNE để tải lên Cloud.', 'Cần Đăng Nhập');
            return;
          }
          if (!state.cloud.spaces || state.cloud.spaces.length === 0) {
            await loadCloudSpaces();
          }
          if (state.cloud.spaces && state.cloud.spaces.length > 1) {
            const spaceChoices = state.cloud.spaces.map((s, idx) => `${idx + 1}. ${s.name} (${s.type === 'PERSONAL' ? 'Cá nhân' : 'Nhóm'})`).join('\n');
            const choice = window.prompt(`Chọn không gian lưu trữ để tải lên (1-${state.cloud.spaces.length}):\n\n${spaceChoices}`, '1');
            if (!choice) return;
            const chosenIdx = parseInt(choice, 10) - 1;
            if (chosenIdx >= 0 && chosenIdx < state.cloud.spaces.length) {
              state.cloud.currentSpaceId = state.cloud.spaces[chosenIdx].id;
              if (DOM.selCloudSpace) DOM.selCloudSpace.value = state.cloud.currentSpaceId;
            }
          }
          let fileToUpload = proj.outputVideoPath;
          if (!fileToUpload && proj.draftDir) {
            if (proj.studioData?.audioPath) fileToUpload = proj.studioData.audioPath;
            else if (proj.studioData?.mediaList?.[0]?.path) fileToUpload = proj.studioData.mediaList[0].path;
          }
          if (fileToUpload) {
            proj.cloudStatus = 'UPLOADING';
            renderProjectsGrid();
            try {
              await startCloudUpload(fileToUpload);
              proj.cloudStatus = 'SYNCED';
              proj.cloudSynced = true;
              await saveProjects();
            } catch (upErr) {
              proj.cloudStatus = 'FAILED';
              await saveProjects();
              throw upErr;
            } finally {
              renderProjectsGrid();
            }
          } else {
            showToast(`Đang chuyển sang Cloud để tải lên dự án "${proj.name}"...`, 'progress', 2000);
            switchTab('cloud');
          }
        });
        break;
      }

      case 'open-capcut': {
        await withButtonLoading(async () => {
          console.log(`[PROJECT_ACTION_IPC] channel=sidecar:open-capcut draftPath=${proj.draftDir}`);
          const res = await window.autoedit.openCapCut(proj.draftDir);
          console.log('[PROJECT_ACTION_RESULT]', res);
          if (res && res.ok) {
            showToast(`Đã mở dự án "${proj.name}" trong CapCut!`, 'success', 3000);
          } else if (res && res.error === 'DRAFT_NOT_FOUND') {
            showAlert(`Thư mục dự án không còn tồn tại trên ổ đĩa:\n${proj.draftDir}`, 'Dự Án Không Tồn Tại', true);
          } else if (res && res.error === 'CAPCUT_NOT_FOUND') {
            showAlert('Không tìm thấy ứng dụng CapCut Desktop trên máy tính.', 'CapCut Chưa Cài Đặt', true);
          } else {
            showAlert(`Không thể mở CapCut: ${res?.message || res?.error || 'Lỗi không xác định'}`, 'Lỗi Mở CapCut', true);
          }
        });
        break;
      }

      case 'open-folder': {
        await withButtonLoading(async () => {
          console.log(`[PROJECT_ACTION_IPC] channel=shell:open-project-folder projectId=${proj.id} name=${proj.name}`);
          let res = null;
          if (window.autoedit?.openProjectFolder) {
            res = await window.autoedit.openProjectFolder({
              projectId: proj.id,
              projectData: proj,
              draftDir: proj.draftDir,
            });
          } else if (window.autoedit?.openFolder) {
            const canonicalDir = proj.projectDir || proj.bundleDir || proj.workspaceDir || proj.draftDir;
            const ok = await window.autoedit.openFolder(canonicalDir);
            res = { ok, path: canonicalDir };
          }
          console.log('[PROJECT_ACTION_RESULT] openProjectFolder=', res);
          if (res && res.ok) {
            showToast(`Đã mở thư mục dự án "${proj.name}".`, 'success', 2500);
          } else {
            showAlert(`Không thể mở thư mục dự án 2TOOLNE: ${res?.error || 'Lỗi không xác định'}`, 'Lỗi Thư Mục', true);
          }
        });
        break;
      }

      case 'load-studio': {
        await withButtonLoading(async () => {
          console.log(`[PROJECT_ACTION_DISPATCH] loadProjectToStudio projectId=${projectId}`);
          loadProjectToStudio(proj.id);
          console.log('[PROJECT_ACTION_RESULT] Loaded into Studio successfully');
        });
        break;
      }

      case 'queue-pipeline':
      case 'queue-export': {
        document.querySelectorAll('.project-context-menu').forEach((m) => {
          m.style.display = 'none';
        });
        await withButtonLoading(async () => {
          const bundlePath = proj.bundleDir || proj.projectDir || proj.workspaceDir || proj.draftDir;
          if (bundlePath && window.autoedit?.pipeline?.enqueue) {
            const res = await window.autoedit.pipeline.enqueue(bundlePath, { require_character_approval: false });
            if (res && res.ok) {
              showToast(`Đã thêm "${proj.name}" vào Hàng Đợi Xử Lý!`, 'success', 2500);
              switchTab('queue');
            } else if (res && res.duplicate) {
              showToast('Dự án này đã có trong hàng đợi xử lý.', 'warning', 2500);
            } else {
              showToast(`Không thể thêm vào hàng đợi: ${res?.error || 'Lỗi không xác định'}`, 'error', 3000);
            }
          } else {
            showToast('Không tìm thấy thư mục bundle của dự án để đưa vào hàng đợi.', 'warning', 3000);
          }
        });
        break;
      }

      case 'export-video': {
        await withButtonLoading(async () => {
          console.log(`[PROJECT_ACTION_IPC] channel=sidecar:render-now draftPath=${proj.draftDir}`);
          await renderDraftNow(proj.draftDir, proj.name);
          console.log('[PROJECT_ACTION_RESULT] Triggered direct render');
        });
        break;
      }

      case 'delete': {
        console.log(`[PROJECT_ACTION_DISPATCH] promptDeleteProject projectId=${projectId}`);
        promptDeleteProject(proj.id);
        console.log('[PROJECT_ACTION_RESULT] Confirmation dialog displayed');
        break;
      }

      default:
        console.warn('Unknown project action:', action);
    }
  } catch (err) {
    console.error(`[PROJECT_ACTION_ERROR] action=${action} error:`, err);
    showAlert(`Lỗi thực hiện tác vụ: ${err.message}`, 'Lỗi', true);
  }
}

function renderProjectsGrid() {
  initProjectsEventDelegation();

  const searchTerm = (DOM.inpProjectSearch?.value || '').trim().toLowerCase();
  const sortOption = DOM.selProjectSort?.value || 'newest';

  let filteredProjects = state.projects.filter((p) => {
    if (!searchTerm) return true;
    return (p.name || '').toLowerCase().includes(searchTerm) || (p.draftDir || '').toLowerCase().includes(searchTerm);
  });

  filteredProjects.sort((a, b) => {
    if (sortOption === 'oldest') {
      return (a.createdAt || 0) - (b.createdAt || 0);
    } else if (sortOption === 'name') {
      return (a.name || '').localeCompare(b.name || '');
    } else if (sortOption === 'duration') {
      return (b.durationS || 0) - (a.durationS || 0);
    } else {
      return (b.createdAt || 0) - (a.createdAt || 0);
    }
  });

  if (filteredProjects.length === 0) {
    if (state.projects.length === 0) {
      DOM.projectsGrid.innerHTML = `
        <div class="empty-projects-hint" style="text-align:center;padding:48px 16px;">
          <div style="margin-bottom:12px;"><i data-lucide="folder" class="icon-lg" style="width:36px;height:36px;color:var(--text-muted);"></i></div>
          <div style="font-size:15px;color:#cbd5e1;font-weight:500;margin-bottom:14px;">Bạn chưa tạo dự án nào.</div>
          <button type="button" class="btn-action-primary" data-project-action="create-first" style="margin:0 auto;display:inline-flex;"><i data-lucide="clapperboard" class="icon-xs"></i> Tạo dự án đầu tiên</button>
        </div>
      `;
    } else {
      DOM.projectsGrid.innerHTML = `<div class="empty-projects-hint">Không tìm thấy dự án nào khớp với từ khóa "${escapeHtml(searchTerm)}".</div>`;
    }
    refreshIcons(DOM.projectsGrid);
    return;
  }

  DOM.projectsGrid.innerHTML = '';
  filteredProjects.forEach((proj) => {
    const card = document.createElement('div');
    card.className = 'project-card';

    const dateStr = new Date(proj.createdAt || Date.now()).toLocaleDateString('vi-VN');
    const safeProjId = escapeHtml(proj.id || '');
    const durationLabel = proj.durationS ? `${Math.round(proj.durationS)}s` : '--';

    const cloudStatus = proj.cloudStatus || (proj.cloudSynced ? 'SYNCED' : 'LOCAL');
    let cloudBtnHtml = '';
    if (cloudStatus === 'SYNCED') {
      cloudBtnHtml = `
        <button type="button" class="btn-subtle project-btn-cloud is-synced" data-project-action="upload-cloud" data-project-id="${safeProjId}" title="Đã lên Cloud">
          <i data-lucide="cloud-check" class="icon-xs" style="color:var(--success,#35C889);"></i> <span>Đã lên Cloud</span>
        </button>
      `;
    } else if (cloudStatus === 'UPLOADING') {
      const pctStr = proj.uploadPercent ? `${proj.uploadPercent}%` : '';
      cloudBtnHtml = `
        <button type="button" class="btn-subtle project-btn-cloud is-uploading" data-project-action="upload-cloud" data-project-id="${safeProjId}" disabled title="Đang tải lên Cloud">
          <i data-lucide="loader-circle" class="icon-xs spin"></i> <span>Đang tải lên... ${pctStr}</span>
        </button>
      `;
    } else if (cloudStatus === 'FAILED') {
      cloudBtnHtml = `
        <button type="button" class="btn-subtle project-btn-cloud is-failed" data-project-action="upload-cloud" data-project-id="${safeProjId}" title="Thử lại tải lên Cloud">
          <i data-lucide="triangle-alert" class="icon-xs" style="color:var(--warning,#F5A623);"></i> <span>Thử lại Upload</span>
        </button>
      `;
    } else {
      cloudBtnHtml = `
        <button type="button" class="btn-subtle project-btn-cloud" data-project-action="upload-cloud" data-project-id="${safeProjId}" title="Tải lên Cloud">
          <i data-lucide="cloud-upload" class="icon-xs"></i> <span>Upload Cloud</span>
        </button>
      `;
    }

    card.innerHTML = `
      <div class="project-card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
          <h4 class="project-card-title">${escapeHtml(proj.name)}</h4>
          <span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3);font-size:11px;"><i data-lucide="check" class="icon-xs" style="margin-right:4px;"></i>Đã Tạo</span>
        </div>
        <div class="project-card-meta">
          <span>Tỷ lệ: ${escapeHtml(proj.aspectRatio || '9:16')}</span> • 
          <span>${proj.imageCount || 0} ảnh</span> • 
          <span><i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>${durationLabel}</span> • 
          <span>${dateStr}</span>
        </div>
        <div class="project-card-actions">
          <!-- 1. Full-Width Primary Action: Chỉnh sửa dự án & Contextual Dropdown -->
          <div class="project-edit-wrapper">
            <button type="button" class="btn-action-primary action-primary project-btn-edit" data-project-action="edit-menu" data-project-id="${safeProjId}">
              <i data-lucide="sliders-horizontal" class="icon-xs"></i> <span>Chỉnh sửa dự án</span>
            </button>
            <div class="project-context-menu" id="projectContextMenu_${safeProjId}" style="display:none;">
              <button type="button" class="project-menu-item" data-project-action="rename-project" data-project-id="${safeProjId}">
                <i data-lucide="pencil" class="icon-xs"></i> <span>Đổi tên dự án</span>
              </button>
              <button type="button" class="project-menu-item" data-project-action="regen-images" data-project-id="${safeProjId}">
                <i data-lucide="image" class="icon-xs"></i> <span>Tạo lại ảnh</span>
              </button>
              <button type="button" class="project-menu-item" data-project-action="regen-voice" data-project-id="${safeProjId}">
                <i data-lucide="audio-lines" class="icon-xs"></i> <span>Tạo lại Voice</span>
              </button>
              <button type="button" class="project-menu-item" data-project-action="queue-pipeline" data-project-id="${safeProjId}">
                <i data-lucide="list-plus" class="icon-xs"></i> <span>Thêm vào Hàng Đợi Xử Lý</span>
              </button>
              <div class="project-menu-divider"></div>
              <button type="button" class="project-menu-item" data-project-action="open-capcut" data-project-id="${safeProjId}">
                <i data-lucide="clapperboard" class="icon-xs"></i> <span>Mở Project CapCut</span>
              </button>
            </div>
          </div>

          <!-- 2. Secondary Utilities & Destructive: [ Upload Cloud ] [ Mở thư mục ] [Trash2] -->
          <div class="action-utilities">
            ${cloudBtnHtml}
            <button type="button" class="btn-subtle project-btn-folder" data-project-action="open-folder" data-project-id="${safeProjId}" title="Mở thư mục dự án 2TOOLNE">
              <i data-lucide="folder-open" class="icon-xs"></i> <span>Mở thư mục</span>
            </button>
            <button type="button" class="btn-subtle project-btn-delete-icon" data-project-action="delete" data-project-id="${safeProjId}" title="Xóa dự án">
              <i data-lucide="trash-2" class="icon-xs"></i>
            </button>
          </div>
        </div>
      </div>
    `;
    DOM.projectsGrid.appendChild(card);
  });
  refreshIcons(DOM.projectsGrid);
}

window.promptDeleteProject = (projId) => {
  const proj = state.projects.find((p) => p.id === projId);
  if (!proj) return;
  targetProjectToDelete = proj;
  if (DOM.deleteProjectTargetName) {
    DOM.deleteProjectTargetName.textContent = proj.name || 'Dự án';
  }
  if (DOM.chkDeleteDraftFolder) {
    DOM.chkDeleteDraftFolder.checked = true;
  }
  showModal(DOM.modalDeleteProject);
};

[DOM.btnCloseModalDeleteProject, DOM.btnCancelDeleteProject].forEach((btn) => {
  btn?.addEventListener('click', () => {
    hideModal(DOM.modalDeleteProject);
    targetProjectToDelete = null;
  });
});

if (DOM.btnConfirmDeleteProject) {
  DOM.btnConfirmDeleteProject.addEventListener('click', async () => {
    if (!targetProjectToDelete) return;
    const proj = targetProjectToDelete;
    const deleteFolder = DOM.chkDeleteDraftFolder ? DOM.chkDeleteDraftFolder.checked : false;

    hideModal(DOM.modalDeleteProject);

    if (deleteFolder) {
      if (window.autoedit?.deleteProjectFolder) {
        try {
          const delRes = await window.autoedit.deleteProjectFolder({
            projectId: proj.id,
            projectData: proj,
            draftDir: proj.draftDir,
          });
          if (!delRes || !delRes.ok) {
            console.warn('Project folder deletion failed:', delRes?.error);
          }
        } catch (err) {
          console.warn('Error deleting 2TOOLNE project directory:', err);
        }
      } else if (proj.draftDir && window.autoedit?.deleteDraftFolder) {
        try {
          const delRes = await window.autoedit.deleteDraftFolder(proj.draftDir);
          if (!delRes || !delRes.ok) {
            console.warn('Draft deletion failed or protected:', delRes?.error);
          }
        } catch (err) {
          console.warn('Error deleting draft directory:', err);
        }
      }
    }

    state.projects = state.projects.filter((p) => p.id !== proj.id);
    await saveProjects();
    renderProjectsGrid();
    showToast(`Đã xóa dự án "${proj.name}".`, 'success', 3000);
    targetProjectToDelete = null;
  });
}

window.loadProjectToStudio = (projId) => {
  const proj = state.projects.find((p) => p.id === projId);
  if (!proj) return;

  if (DOM.inpProjectName) {
    DOM.inpProjectName.value = proj.name || '';
  }
  state.projectName = proj.name || '';

  if (proj.aspectRatio && DOM.selAspectRatio) {
    DOM.selAspectRatio.value = proj.aspectRatio;
    state.aspectRatio = proj.aspectRatio;
  }

  if (proj.studioData) {
    const sData = proj.studioData;
    if (Array.isArray(sData.mediaList)) {
      state.mediaList = [...sData.mediaList];
      renderMediaGrid();
    }
    if (sData.audioPath) {
      setAudioPathUI(sData.audioPath);
    } else {
      setAudioPathUI(null);
    }
    if (DOM.inpScriptText) {
      DOM.inpScriptText.value = sData.scriptText || '';
      state.scriptText = sData.scriptText || '';
    }
    if (DOM.inpSrtText) {
      DOM.inpSrtText.value = sData.srtContent || '';
      state.srtContent = sData.srtContent || '';
    }
    if (sData.presetId && DOM.selPresetStyle) {
      DOM.selPresetStyle.value = sData.presetId;
      DOM.selPresetStyle.dispatchEvent(new Event('change'));
    }
  }

  switchTab('studio');
  showToast(`Đã tải dự án "${proj.name}" vào Studio!`, 'success', 3000);
};

window.renderDraftNow = async (draftDir, projName) => {
  try {
    const outDir = (state.settings && state.settings.renderOutputDir) ? state.settings.renderOutputDir : draftDir;
    const sep = (outDir.includes('\\') && !outDir.includes('/')) ? '\\' : '/';
    const safeOutDir = outDir.endsWith('/') || outDir.endsWith('\\') ? outDir.slice(0, -1) : outDir;
    const outPath = safeOutDir + sep + (projName || 'output') + '.mp4';
    showToast(`Đang khởi động xuất video cho "${projName}"...`, 'progress', 3000);

    const res = await window.autoedit.renderNow({
      draft_path: draftDir,
      output_path: outPath,
      project_id: projName,
    });

    if (res && res.ok) {
      showToast(`Đã đưa "${projName}" vào tiến trình xuất video!`, 'success', 3500);
      switchTab('queue');
      DOM.tabSubQueueRender?.click();
      await refreshRenderQueueUI();
    } else {
      showAlert(`Lỗi xuất video: ${res?.error?.message || res?.error || 'Không xác định'}`, 'Lỗi Render', true);
    }
  } catch (err) {
    showAlert(`Lỗi kích hoạt Render: ${err.message}`, 'Lỗi', true);
  }
};

window.addDraftToRenderQueue = async (draftDir, projName) => {
  try {
    const outDir = (state.settings && state.settings.renderOutputDir) ? state.settings.renderOutputDir : draftDir;
    const sep = (outDir.includes('\\') && !outDir.includes('/')) ? '\\' : '/';
    const safeOutDir = outDir.endsWith('/') || outDir.endsWith('\\') ? outDir.slice(0, -1) : outDir;
    const outPath = safeOutDir + sep + (projName || 'output') + '.mp4';

    const res = await window.autoedit.enqueueRender({
      draft_path: draftDir,
      output_path: outPath,
      project_id: projName,
    });

    if (res && res.ok) {
      showToast(`Đã thêm dự án "${projName}" vào hàng đợi xuất video!`, 'success', 3000);
      switchTab('queue');
      DOM.tabSubQueueRender?.click();
      await refreshRenderQueueUI();
    } else {
      showAlert(`Không thể thêm vào hàng đợi: ${res?.error?.message || res?.error || 'Không xác định'}`, 'Lỗi Hàng Đợi', true);
    }
  } catch (err) {
    showAlert(`Lỗi thêm hàng đợi: ${err.message}`, 'Lỗi', true);
  }
};

window.openDraftFolder = (draftDir) => {
  window.autoedit.openFolder(draftDir);
};

DOM.btnRefreshProjects?.addEventListener('click', async () => {
  showToast('Đang làm mới danh sách dự án...', 'progress', { id: 'refresh-projects' });
  await loadStoredState();
  renderProjectsGrid();
  showToast('Đã làm mới danh sách dự án.', 'success', { id: 'refresh-projects' });
});

DOM.inpProjectSearch?.addEventListener('input', () => {
  renderProjectsGrid();
});

DOM.selProjectSort?.addEventListener('change', () => {
  renderProjectsGrid();
});

// Settings Handlers: Output Directory & Diagnostic Bundle
DOM.btnBrowseRenderOutputDir?.addEventListener('click', async () => {
  if (!window.autoedit?.openDirectoryDialog) return;
  const chosen = await window.autoedit.openDirectoryDialog();
  if (chosen) {
    if (!state.settings) state.settings = {};
    state.settings.renderOutputDir = chosen;
    if (DOM.inpRenderOutputDir) DOM.inpRenderOutputDir.value = chosen;
    await saveSettings();
    showToast('Đã lưu thư mục xuất mặc định!', 'success', 2500);
  }
});

DOM.btnOpenRenderOutputDir?.addEventListener('click', () => {
  const dir = (state.settings && state.settings.renderOutputDir) || (DOM.inpRenderOutputDir && DOM.inpRenderOutputDir.value);
  if (dir) {
    window.autoedit.openFolder(dir);
  } else {
    showToast('Chưa cấu hình thư mục xuất tùy chỉnh (đang dùng thư mục dự án).', 'info', 3000);
  }
});

DOM.btnResetRenderOutputDir?.addEventListener('click', async () => {
  if (!state.settings) state.settings = {};
  state.settings.renderOutputDir = '';
  if (DOM.inpRenderOutputDir) DOM.inpRenderOutputDir.value = '';
  await saveSettings();
  showToast('Đã đặt lại thư mục xuất về mặc định.', 'success', 2500);
});

DOM.btnExportDiagnostics?.addEventListener('click', async () => {
  if (!window.autoedit?.exportDiagnosticBundle) return;
  try {
    showToast('Đang tạo gói chẩn đoán hệ thống...', 'progress', 3000);
    const res = await window.autoedit.exportDiagnosticBundle();
    if (res && res.ok) {
      showToast(`Đã xuất gói chẩn đoán ra Desktop: ${res.path}`, 'success', 5000);
    } else {
      showAlert(`Không thể xuất gói chẩn đoán: ${res?.error || 'Lỗi không xác định'}`, 'Lỗi', true);
    }
  } catch (err) {
    showAlert(`Lỗi xuất gói chẩn đoán: ${err.message}`, 'Lỗi', true);
  }
});

function initAppUpdater() {
  const updater = window.autoedit?.updater;
  if (!updater) return;

  // Initialize initial state from main process
  updater.getState?.().then((st) => {
    if (st && st.currentVersion && DOM.txtAppCurrentVersion) {
      DOM.txtAppCurrentVersion.textContent = `v${st.currentVersion}`;
    }
  }).catch(() => {});

  // Handle status transitions
  updater.onStatusChanged?.((payload) => {
    const { state: st, currentVersion, availableUpdate, lastError, manual } = payload;
    if (currentVersion && DOM.txtAppCurrentVersion) {
      DOM.txtAppCurrentVersion.textContent = `v${currentVersion}`;
    }

    switch (st) {
      case 'CHECKING':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = true;
          DOM.btnCheckUpdate.innerHTML = '<i data-lucide="loader-2" class="icon-xs animate-spin"></i> Đang kiểm tra...'; refreshIcons(DOM.btnCheckUpdate);
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = 'Đang kiểm tra bản cập nhật...';
          DOM.txtUpdateStatus.style.color = '#94a3b8';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
        break;

      case 'UP_TO_DATE':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.innerHTML = '<i data-lucide="rotate-cw" class="icon-xs"></i> Kiểm Tra Cập Nhật'; refreshIcons(DOM.btnCheckUpdate);
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `Bạn đang dùng phiên bản mới nhất (v${currentVersion}).`;
          DOM.txtUpdateStatus.style.color = 'var(--success)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
        if (manual) {
          showToast(`Bạn đang dùng phiên bản mới nhất (v${currentVersion}).`, 'success', 3000);
        }
        break;

      case 'UPDATE_AVAILABLE':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.innerHTML = '<i data-lucide="rotate-cw" class="icon-xs"></i> Kiểm Tra Lại'; refreshIcons(DOM.btnCheckUpdate);
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `Có phiên bản mới: v${availableUpdate?.version}!`;
          DOM.txtUpdateStatus.style.color = 'var(--brand-primary, #FF7A00)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) {
          DOM.boxUpdateActions.style.display = 'flex';
          if (DOM.btnDownloadUpdate) {
            DOM.btnDownloadUpdate.style.display = 'inline-flex';
            DOM.btnDownloadUpdate.disabled = false;
            DOM.btnDownloadUpdate.innerHTML = `<i data-lucide="download" class="icon-xs"></i> Tải Bản Cập Nhật (v${availableUpdate?.version})`; refreshIcons(DOM.btnDownloadUpdate);
          }
          if (DOM.btnInstallUpdate) DOM.btnInstallUpdate.style.display = 'none';
          if (DOM.btnDismissUpdate) DOM.btnDismissUpdate.style.display = 'inline-flex';
        }
        if (manual) {
          showToast(`Phát hiện bản cập nhật mới v${availableUpdate?.version}!`, 'info', 4000);
        }
        break;

      case 'DOWNLOADING':
        if (DOM.btnCheckUpdate) DOM.btnCheckUpdate.disabled = true;
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `Đang tải bản cập nhật v${availableUpdate?.version || ''}...`;
          DOM.txtUpdateStatus.style.color = 'var(--brand-primary, #FF7A00)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'block';
        if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
        break;

      case 'DOWNLOADED':
      case 'INSTALL_READY':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.innerHTML = '<i data-lucide="rotate-cw" class="icon-xs"></i> Kiểm Tra Cập Nhật'; refreshIcons(DOM.btnCheckUpdate);
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = 'Đã tải xong bản cập nhật. Sẵn sàng cài đặt!';
          DOM.txtUpdateStatus.style.color = 'var(--success)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) {
          DOM.boxUpdateActions.style.display = 'flex';
          if (DOM.btnDownloadUpdate) DOM.btnDownloadUpdate.style.display = 'none';
          if (DOM.btnInstallUpdate) {
            DOM.btnInstallUpdate.style.display = 'inline-flex';
            DOM.btnInstallUpdate.disabled = false;
            DOM.btnInstallUpdate.innerHTML = '<i data-lucide="refresh-cw" class="icon-xs"></i> Khởi Động Lại & Cập Nhật'; refreshIcons(DOM.btnInstallUpdate);
          }
          if (DOM.btnDismissUpdate) DOM.btnDismissUpdate.style.display = 'inline-flex';
        }
        showToast('Bản cập nhật đã tải xong và sẵn sàng cài đặt.', 'success', 5000);
        break;

      case 'INSTALLING':
        if (DOM.btnCheckUpdate) DOM.btnCheckUpdate.disabled = true;
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = 'Đang cài đặt bản cập nhật và khởi động lại...';
          DOM.txtUpdateStatus.style.color = '#f59e0b';
        }
        if (DOM.btnInstallUpdate) {
          DOM.btnInstallUpdate.disabled = true;
          DOM.btnInstallUpdate.innerHTML = '<i data-lucide="loader-2" class="icon-xs animate-spin"></i> Đang cài đặt...'; refreshIcons(DOM.btnInstallUpdate);
        }
        break;

      case 'ERROR':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.innerHTML = '<i data-lucide="rotate-ccw" class="icon-xs"></i> Thử Lại'; refreshIcons(DOM.btnCheckUpdate);
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `Lỗi cập nhật: ${lastError || 'Không xác định'}`;
          DOM.txtUpdateStatus.style.color = 'var(--danger)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
        if (manual) {
          showToast(`Lỗi kiểm tra cập nhật: ${lastError || 'Không thể kết nối máy chủ'}`, 'error', 4000);
        }
        break;
    }
  });

  // Handle download progress
  updater.onProgress?.((prog) => {
    if (!prog) return;
    const percent = Math.min(100, Math.max(0, prog.percent || 0));
    if (DOM.barUpdateProgress) {
      DOM.barUpdateProgress.style.width = `${percent}%`;
    }
    if (DOM.txtUpdateSpeed) {
      const mbTrans = ((prog.transferred || 0) / 1048576).toFixed(1);
      const mbTotal = ((prog.total || 0) / 1048576).toFixed(1);
      const mbSpeed = ((prog.bytesPerSecond || 0) / 1048576).toFixed(1);
      DOM.txtUpdateSpeed.textContent = `${percent}% — ${mbTrans} MB / ${mbTotal} MB (${mbSpeed} MB/s)`;
    }
  });

  // Wire buttons
  DOM.btnCheckUpdate?.addEventListener('click', async () => {
    try {
      await updater.check({ manual: true });
    } catch (e) {
      showToast(`Lỗi kiểm tra cập nhật: ${e.message}`, 'error', 3000);
    }
  });

  DOM.btnDownloadUpdate?.addEventListener('click', async () => {
    try {
      if (DOM.btnDownloadUpdate) DOM.btnDownloadUpdate.disabled = true;
      await updater.download();
    } catch (e) {
      showToast(`Lỗi tải bản cập nhật: ${e.message}`, 'error', 4000);
    }
  });

  DOM.btnInstallUpdate?.addEventListener('click', async () => {
    try {
      if (DOM.btnInstallUpdate) DOM.btnInstallUpdate.disabled = true;
      const res = await updater.install();
      if (res && res.busy) {
        if (DOM.btnInstallUpdate) DOM.btnInstallUpdate.disabled = false;
        showAlert(res.message || 'Đang có tác vụ đang xử lý. Vui lòng thử lại sau.', 'Thông Báo');
      }
    } catch (e) {
      if (DOM.btnInstallUpdate) DOM.btnInstallUpdate.disabled = false;
      showToast(`Lỗi cài đặt: ${e.message}`, 'error', 4000);
    }
  });

  DOM.btnDismissUpdate?.addEventListener('click', () => {
    if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
    if (DOM.txtUpdateStatus) {
      DOM.txtUpdateStatus.textContent = 'Đã hoãn cập nhật. Bạn có thể cập nhật lại bất cứ lúc nào.';
      DOM.txtUpdateStatus.style.color = '#94a3b8';
    }
  });
}

// -----------------------------------------------------------------------------
// AI Upscale Tab Actions (GAP-12)
// -----------------------------------------------------------------------------
function renderUpscaleList() {
  if (!state.upscaleFiles.length) {
    if (DOM.upscaleFilesContainer) DOM.upscaleFilesContainer.style.display = 'none';
    if (DOM.upscaleCount) DOM.upscaleCount.textContent = '0';
    if (DOM.upscaleFileList) DOM.upscaleFileList.innerHTML = '';
    return;
  }
  if (DOM.upscaleFilesContainer) DOM.upscaleFilesContainer.style.display = 'block';
  if (DOM.upscaleCount) DOM.upscaleCount.textContent = state.upscaleFiles.length;
  if (DOM.upscaleFileList) {
    DOM.upscaleFileList.innerHTML = state.upscaleFiles.map((p, idx) => {
      const name = p.split(/[\\/]/).pop();
      return `<div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.06);">
        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;">${idx + 1}. ${name}</span>
        <button type="button" class="btn-link-danger" data-idx="${idx}" style="font-size: 11px; cursor: pointer; padding: 0 4px;"><i data-lucide="x" class="icon-xs"></i></button>
      </div>`;
    }).join('');

    DOM.upscaleFileList.querySelectorAll('button[data-idx]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const i = parseInt(e.currentTarget.getAttribute('data-idx'), 10);
        state.upscaleFiles.splice(i, 1);
        renderUpscaleList();
      });
    });
  }
}

DOM.btnBrowseUpscale?.addEventListener('click', async () => {
  const files = await window.autoedit.openFilesDialog({
    title: 'Chọn ảnh cần phóng to (2K / 4K)',
    properties: ['openFile', 'multiSelections'],
  });
  if (files && files.length) {
    state.upscaleFiles = [...new Set([...state.upscaleFiles, ...files])];
    renderUpscaleList();
  }
});

DOM.btnClearUpscale?.addEventListener('click', () => {
  state.upscaleFiles = [];
  renderUpscaleList();
});

DOM.upscaleDropZone?.addEventListener('dragover', (e) => {
  e.preventDefault();
  DOM.upscaleDropZone.classList.add('active');
});

DOM.upscaleDropZone?.addEventListener('dragleave', () => {
  DOM.upscaleDropZone.classList.remove('active');
});

DOM.upscaleDropZone?.addEventListener('drop', (e) => {
  e.preventDefault();
  DOM.upscaleDropZone.classList.remove('active');
  const dropped = Array.from(e.dataTransfer.files || [])
    .map((f) => (window.autoedit?.getPathForFile ? window.autoedit.getPathForFile(f) : f.path))
    .filter(Boolean);
  if (dropped.length) {
    state.upscaleFiles = [...new Set([...state.upscaleFiles, ...dropped])];
    renderUpscaleList();
  }
});

DOM.btnRunUpscale?.addEventListener('click', async () => {
  if (!state.upscaleFiles.length) {
    showAlert('Vui lòng chọn hoặc kéo thả ít nhất một hình ảnh để tiến hành phóng to (Upscale).', 'Thiếu Ảnh');
    return;
  }

  const model = DOM.selUpscaleModel?.value || '2x_2k';
  const is4K = model === '4x_4k';
  const costPerImg = is4K ? 2 : 1;
  const totalCost = state.upscaleFiles.length * costPerImg;

  // Pre-flight check: Account login & Token gating
  if (!state.currentUser) {
    showAlert('Vui lòng đăng nhập tài khoản Web để sử dụng tính năng AI Upscale.', 'Yêu Cầu Đăng Nhập');
    showModal(DOM.modalLogin);
    return;
  }

  if (state.tokenBalance !== null && state.tokenBalance < totalCost) {
    showAlert(`Số dư token trong ví không đủ (Cần: ${totalCost} token cho ${state.upscaleFiles.length} ảnh ${is4K ? '4K' : '2K'}, hiện có: ${state.tokenBalance} token). Vui lòng nạp thêm token trên portal.`, 'Thiếu Token');
    return;
  }

  DOM.btnRunUpscale.disabled = true;
  if (DOM.upscaleProgressWrap) {
    DOM.upscaleProgressWrap.style.display = 'block';
    DOM.upscaleProgressFill.style.width = '0%';
    DOM.upscaleProgressPct.textContent = '0%';
    DOM.upscaleProgressMsg.textContent = 'Khởi động động cơ AI Upscale...';
  }
  if (DOM.upscaleResultBox) DOM.upscaleResultBox.style.display = 'none';

  try {
    const res = await window.autoedit.runUpscale({
      filePaths: state.upscaleFiles,
      resolution: model,
    });

    if (res && res.ok) {
      state.lastUpscaleOutputDir = res.outputDir;
      if (DOM.upscaleProgressWrap) {
        DOM.upscaleProgressFill.style.width = '100%';
        DOM.upscaleProgressPct.textContent = '100%';
        DOM.upscaleProgressMsg.textContent = `Hoàn tất phóng to ${res.completedCount}/${res.totalCount} ảnh!`;
      }
      if (DOM.upscaleResultBox) {
        DOM.upscaleResultBox.style.display = 'flex';
        if (DOM.upscaleResultPath) DOM.upscaleResultPath.textContent = `Thư mục lưu: ${res.outputDir}`;
      }
      if (typeof refreshWalletBalance === 'function') {
        await refreshWalletBalance();
      }
    } else {
      if (res?.code === 'AUTH_REQUIRED') {
        showAlert(res.error || 'Vui lòng đăng nhập tài khoản Web để sử dụng tính năng AI Upscale.', 'Yêu Cầu Đăng Nhập');
        showModal(DOM.modalLogin);
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      if (res?.code === 'INSUFFICIENT_TOKENS' || res?.code === 'INSUFFICIENT_CREDITS') {
        showAlert(res.error || 'Số dư token không đủ để thực hiện upscale.', 'Thiếu Token');
        if (typeof refreshWalletBalance === 'function') await refreshWalletBalance();
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      if (res?.code === 'INVALID_DEVICE_ID') {
        showAlert('Định danh thiết bị không hợp lệ hoặc vượt quá giới hạn cho phép. Vui lòng khởi động lại ứng dụng hoặc kích hoạt lại bản quyền.', 'Lỗi Thiết Bị');
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      if (res?.code === 'DEVICE_LIMIT_REACHED') {
        showAlert(res.error || 'Thiết bị đã vượt quá giới hạn liên kết cho phép.', 'Giới Hạn Thiết Bị');
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      if (res?.code === 'NETWORK_ERROR') {
        showAlert('Không thể kết nối đến máy chủ xác thực. Vui lòng kiểm tra kết nối mạng.', 'Lỗi Kết Nối');
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      if (res?.code === 'RESERVATION_FAILED') {
        let cleanErr = res?.error || 'Không thể khóa giữ token cho phiên xử lý. Vui lòng thử lại.';
        if (cleanErr.includes('SQLSTATE') || cleanErr.includes('Data too long')) {
          cleanErr = 'Không thể khóa giữ token cho phiên xử lý. Vui lòng thử lại.';
        }
        showAlert(cleanErr, 'Lỗi Khóa Token');
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      let errText = res?.error || 'Lỗi không xác định khi xử lý upscale.';
      if (errText.includes('SQLSTATE') || errText.includes('Data too long')) {
        errText = 'Không thể khóa giữ token cho phiên xử lý. Vui lòng thử lại.';
      }
      throw new Error(errText);
    }
  } catch (err) {
    let msg = err.message || '';
    if (msg.includes('SQLSTATE') || msg.includes('Data too long')) {
      msg = 'Không thể hoàn tất tác vụ do lỗi phiên xử lý token. Vui lòng thử lại.';
    }
    showAlert(`Lỗi trong quá trình phóng to ảnh: ${msg}`, 'Lỗi Upscale');
    if (DOM.upscaleProgressMsg) DOM.upscaleProgressMsg.textContent = 'Quá trình bị gián đoạn do lỗi.';
  } finally {
    DOM.btnRunUpscale.disabled = false;
  }
});

DOM.btnOpenUpscaleDir?.addEventListener('click', async () => {
  if (state.lastUpscaleOutputDir) {
    await window.autoedit.openPath(state.lastUpscaleOutputDir);
  }
});

if (window.autoedit?.onUpscaleProgress) {
  window.autoedit.onUpscaleProgress((data) => {
    if (DOM.upscaleProgressFill) DOM.upscaleProgressFill.style.width = `${data.percent}%`;
    if (DOM.upscaleProgressPct) DOM.upscaleProgressPct.textContent = `${data.percent}%`;
    if (DOM.upscaleProgressMsg) DOM.upscaleProgressMsg.textContent = data.message || `Đang xử lý ${data.currentFile}...`;
  });
}

DOM.chkAutoUpscale?.addEventListener('change', () => {
  if (DOM.lblUpscaleTokenHint) {
    DOM.lblUpscaleTokenHint.style.display = DOM.chkAutoUpscale.checked ? 'inline' : 'none';
  }
});

// -----------------------------------------------------------------------------
// Account & License
// -----------------------------------------------------------------------------
DOM.badgeLicense?.addEventListener('click', () => {
  showModal(DOM.modalLicense);
});

DOM.btnOpenActivateModal.addEventListener('click', () => {
  showModal(DOM.modalLicense);
});

DOM.btnSubmitModalLicense.addEventListener('click', async () => {
  const key = DOM.inpLicenseKeyModal.value.trim();
  if (!key) {
    DOM.licenseModalError.style.display = 'block';
    DOM.licenseModalError.textContent = 'Vui lòng nhập License Key.';
    return;
  }

  DOM.licenseModalError.style.display = 'none';
  try {
    const res = await window.autoedit.activateLicense(key);
    if (res && res.ok) {
      hideModal(DOM.modalLicense);
      if (res.license) {
        updateLicenseUI(res.license);
      }
      showAlert(res.message || 'Kích hoạt bản quyền thành công!', 'Thành Công');
      await checkLicenseStatus();
    } else {
      DOM.licenseModalError.style.display = 'block';
      DOM.licenseModalError.textContent = res.error || 'Kích hoạt không thành công.';
    }
  } catch (err) {
    DOM.licenseModalError.style.display = 'block';
    DOM.licenseModalError.textContent = err.message;
  }
});

DOM.btnDeactivateLicense.addEventListener('click', async () => {
  if (confirm('Bạn có chắc chắn muốn hủy kích hoạt thiết bị này?')) {
    try {
      const res = await window.autoedit.deactivateDevice();
      if (res && res.ok) {
        showAlert('Đã hủy kích hoạt thiết bị thành công.', 'Thông Báo');
        await checkLicenseStatus();
      }
    } catch (e) {
      showAlert(`Lỗi hủy kích hoạt: ${e.message}`, 'Lỗi');
    }
  }
});

// -----------------------------------------------------------------------------
// User Account, Machine License & Live Wallet Helpers (PRIORITY 1 & 2)
// -----------------------------------------------------------------------------
let quickLoginPending = false;

function updateAccountUI(user) {
  state.currentUser = user || null;
  if (user && (user.email || user.username || user.id)) {
    if (DOM.accUserEmail) {
      DOM.accUserEmail.textContent = user.email || user.username || `User #${user.id}`;
    }
    if (DOM.accLoginStatus) {
      DOM.accLoginStatus.textContent = 'ĐÃ ĐĂNG NHẬP';
      DOM.accLoginStatus.className = 'status-badge status-active';
      DOM.accLoginStatus.style.color = 'var(--success, #22c55e)';
    }
    if (DOM.btnOpenLoginModal) DOM.btnOpenLoginModal.style.display = 'none';
    if (DOM.btnLogoutAccount) DOM.btnLogoutAccount.style.display = 'inline-block';
  } else {
    if (DOM.accUserEmail) {
      DOM.accUserEmail.textContent = 'Chưa đăng nhập';
    }
    if (DOM.accLoginStatus) {
      DOM.accLoginStatus.textContent = 'CHƯA ĐĂNG NHẬP';
      DOM.accLoginStatus.className = 'status-badge status-idle';
      DOM.accLoginStatus.style.color = 'var(--text-muted, #94a3b8)';
    }
    if (DOM.btnOpenLoginModal) DOM.btnOpenLoginModal.style.display = 'inline-block';
    if (DOM.btnLogoutAccount) DOM.btnLogoutAccount.style.display = 'none';
  }
  syncCloudAuthStatus();
}

function updateLicenseUI(license) {
  state.licenseInfo = license || null;
  const isValid = !!(license && (license.valid || license.active || license.authorized));
  if (isValid) {
    if (DOM.licenseDot) DOM.licenseDot.className = 'status-dot dot-active';
    if (DOM.licenseText) DOM.licenseText.textContent = license.maskedKey || 'Bản quyền hợp lệ';
    if (DOM.accLicenseStatus) {
      DOM.accLicenseStatus.textContent = 'ĐÃ KÍCH HOẠT';
      DOM.accLicenseStatus.className = 'status-badge status-active';
      DOM.accLicenseStatus.style.color = 'var(--success, #22c55e)';
    }
    if (DOM.accMaskedKey) DOM.accMaskedKey.textContent = license.maskedKey || 'Đã kích hoạt';
    if (DOM.accLicenseTier) DOM.accLicenseTier.textContent = license.tier || 'PRO';
    if (DOM.accDeviceId) DOM.accDeviceId.textContent = license.deviceId || '--';
    if (DOM.accLicenseExpiry) {
      const tier = (license.tier || '').toUpperCase();
      const rawExp = Number(license.expiresAt);
      const isLifetime = tier === 'LIFETIME' || !license.expiresAt || rawExp >= 4000000000;
      if (isLifetime) {
        DOM.accLicenseExpiry.textContent = 'Thời hạn: Trọn đời | Grace: Không áp dụng';
      } else {
        const expMs = rawExp < 1e11 ? rawExp * 1000 : rawExp;
        const expDate = new Date(expMs);
        const expStr = isNaN(expDate.getTime()) ? 'Vĩnh viễn' : expDate.toLocaleDateString();
        const graceStr = license.offlineGraceRemaining ? ` | Grace: ${license.offlineGraceRemaining}` : '';
        DOM.accLicenseExpiry.textContent = `Thời hạn: ${expStr}${graceStr}`;
      }
    }
    if (DOM.btnDeactivateLicense) DOM.btnDeactivateLicense.style.display = 'inline-block';
  } else {
    if (DOM.licenseDot) DOM.licenseDot.className = 'status-dot dot-error';
    if (DOM.licenseText) DOM.licenseText.textContent = 'Chưa kích hoạt';
    if (DOM.accLicenseStatus) {
      DOM.accLicenseStatus.textContent = 'CHƯA KÍCH HOẠT';
      DOM.accLicenseStatus.className = 'status-badge status-idle';
      DOM.accLicenseStatus.style.color = 'var(--warning, #f59e0b)';
    }
    if (DOM.accMaskedKey) DOM.accMaskedKey.textContent = 'Chưa có bản quyền';
    if (DOM.accLicenseTier) DOM.accLicenseTier.textContent = 'CHƯA ĐĂNG KÝ';
    if (DOM.accDeviceId) DOM.accDeviceId.textContent = license?.deviceId || '--';
    if (DOM.accLicenseExpiry) DOM.accLicenseExpiry.textContent = '--';
    if (DOM.btnDeactivateLicense) DOM.btnDeactivateLicense.style.display = 'none';
  }
}

function applyWalletStateToUI(walletState) {
  if (!walletState) return;

  const isTeam = walletState.wallet_type === 'TEAM';
  const contextLabel = isTeam ? 'Số dư Team' : 'Số dư cá nhân';

  if (DOM.lblWalletContext) {
    DOM.lblWalletContext.textContent = contextLabel;
  }
  if (DOM.accWalletLabel) {
    DOM.accWalletLabel.textContent = `${contextLabel}:`;
  }

  if (walletState.loading) {
    if (DOM.tokenBalance) DOM.tokenBalance.textContent = 'Đang tải...';
    if (DOM.accTokenCount) DOM.accTokenCount.textContent = 'Đang tải...';
    return;
  }

  if (walletState.error && (walletState.balance === null || walletState.balance === undefined)) {
    if (DOM.tokenBalance) DOM.tokenBalance.textContent = 'Không thể tải số dư';
    if (DOM.accTokenCount) DOM.accTokenCount.textContent = 'Không thể tải số dư';
    return;
  }

  if (walletState.balance !== null && walletState.balance !== undefined) {
    state.tokenBalance = Number(walletState.balance);
    const formatted = Number(walletState.balance).toLocaleString();
    if (DOM.tokenBalance) DOM.tokenBalance.textContent = formatted;
    if (DOM.accTokenCount) DOM.accTokenCount.textContent = formatted;
  } else {
    if (DOM.tokenBalance) DOM.tokenBalance.textContent = '--';
    if (DOM.accTokenCount) DOM.accTokenCount.textContent = '--';
  }
}

async function refreshWalletBalance() {
  try {
    if (window.autoedit?.getWalletState) {
      applyWalletStateToUI({ loading: true });
      const stateRes = await window.autoedit.getWalletState();
      applyWalletStateToUI(stateRes);
    } else if (window.autoedit?.getWalletBalance) {
      const res = await window.autoedit.getWalletBalance();
      if (res && res.walletState) {
        applyWalletStateToUI(res.walletState);
      } else if (res && res.ok && res.balance !== undefined) {
        applyWalletStateToUI({
          wallet_type: res.team_id ? 'TEAM' : 'PERSONAL',
          balance: res.balance,
          loading: false,
          error: null,
        });
      } else {
        applyWalletStateToUI({
          balance: null,
          loading: false,
          error: res?.error || 'Không thể tải số dư',
        });
      }
    }
  } catch (err) {
    console.warn('Error refreshing wallet balance:', err);
    applyWalletStateToUI({
      balance: null,
      loading: false,
      error: err.message || 'Không thể tải số dư',
    });
  }
}

async function refreshAccountState() {
  try {
    if (window.autoedit?.getAccountState) {
      const res = await window.autoedit.getAccountState();
      if (res && res.ok) {
        updateAccountUI(res.account?.authenticated ? res.account.user : null);
        updateLicenseUI(res.license);
      }
    } else {
      if (window.autoedit?.getUser) {
        const userRes = await window.autoedit.getUser();
        updateAccountUI(userRes && userRes.ok ? userRes.user : null);
      }
      if (window.autoedit?.getLicenseStatus) {
        const licRes = await window.autoedit.getLicenseStatus();
        if (licRes) {
          updateLicenseUI({
            valid: !!(licRes.authorized || licRes.state === 'ACTIVE' || licRes.active),
            maskedKey: licRes.masked_key,
            deviceId: licRes.device_id,
            tier: licRes.plan || 'PRO',
            expiresAt: licRes.expires_at,
          });
        }
      }
    }
    await refreshWalletBalance();
  } catch (err) {
    console.warn('Error checking account state:', err);
  }
}

// Reset Login Modal UI
function resetLoginModal() {
  quickLoginPending = false;
  if (DOM.quickLoginPrompt) DOM.quickLoginPrompt.style.display = 'block';
  if (DOM.quickLoginWaiting) DOM.quickLoginWaiting.style.display = 'none';
  if (DOM.manualLoginForm) DOM.manualLoginForm.style.display = 'none';
  if (DOM.loginModalError) {
    DOM.loginModalError.style.display = 'none';
    DOM.loginModalError.textContent = '';
  }
  if (DOM.inpLoginEmail) DOM.inpLoginEmail.value = '';
  if (DOM.inpLoginPassword) DOM.inpLoginPassword.value = '';
}

// User Login Modal Navigation
DOM.btnOpenLoginModal?.addEventListener('click', () => {
  resetLoginModal();
  showModal(DOM.modalLogin);
});

[DOM.btnCloseModalLogin, DOM.btnCancelModalLogin].forEach((btn) => {
  btn?.addEventListener('click', () => {
    if (quickLoginPending) {
      window.autoedit?.cancelQuickLogin?.();
      quickLoginPending = false;
    }
    hideModal(DOM.modalLogin);
  });
});

// Toggle Manual Form
DOM.toggleManualLogin?.addEventListener('click', (e) => {
  e.preventDefault();
  if (!DOM.manualLoginForm) return;
  const isHidden = DOM.manualLoginForm.style.display === 'none';
  DOM.manualLoginForm.style.display = isHidden ? 'block' : 'none';
  DOM.toggleManualLogin.textContent = isHidden
    ? '‹ Ẩn đăng nhập mật khẩu'
    : 'Hoặc đăng nhập trực tiếp bằng mật khẩu ›';
});

// Browser Quick Login (PKCE)
DOM.btnStartQuickLogin?.addEventListener('click', async () => {
  if (DOM.loginModalError) DOM.loginModalError.style.display = 'none';
  if (DOM.quickLoginPrompt) DOM.quickLoginPrompt.style.display = 'none';
  if (DOM.quickLoginWaiting) DOM.quickLoginWaiting.style.display = 'block';
  quickLoginPending = true;

  try {
    const res = await window.autoedit.startQuickLogin();
    if (res && res.ok) {
      hideModal(DOM.modalLogin);
      showToast(`Đăng nhập thành công với tài khoản ${res.user?.email || ''}!`, 'success', 3500);
      updateAccountUI(res.user);
      await refreshWalletBalance();
    } else {
      if (DOM.quickLoginPrompt) DOM.quickLoginPrompt.style.display = 'block';
      if (DOM.quickLoginWaiting) DOM.quickLoginWaiting.style.display = 'none';
      if (DOM.loginModalError) {
        DOM.loginModalError.style.display = 'block';
        DOM.loginModalError.textContent = res?.error || 'Đăng nhập không thành công.';
      }
    }
  } catch (err) {
    if (DOM.quickLoginPrompt) DOM.quickLoginPrompt.style.display = 'block';
    if (DOM.quickLoginWaiting) DOM.quickLoginWaiting.style.display = 'none';
    if (DOM.loginModalError) {
      DOM.loginModalError.style.display = 'block';
      DOM.loginModalError.textContent = err.message;
    }
  } finally {
    quickLoginPending = false;
  }
});

// Cancel Quick Login
DOM.btnCancelQuickLoginAction?.addEventListener('click', async () => {
  try {
    await window.autoedit?.cancelQuickLogin?.();
  } catch (e) {}
  resetLoginModal();
});

// Fallback Direct Manual Login
DOM.btnSubmitModalLogin?.addEventListener('click', async () => {
  const email = DOM.inpLoginEmail?.value.trim();
  const password = DOM.inpLoginPassword?.value;

  if (!email || !password) {
    if (DOM.loginModalError) {
      DOM.loginModalError.style.display = 'block';
      DOM.loginModalError.textContent = 'Vui lòng nhập đầy đủ Email và Mật khẩu.';
    }
    return;
  }

  if (DOM.loginModalError) DOM.loginModalError.style.display = 'none';
  DOM.btnSubmitModalLogin.disabled = true;
  DOM.btnSubmitModalLogin.innerHTML = '<i data-lucide="loader-2" class="icon-xs animate-spin"></i> Đang đăng nhập...'; refreshIcons(DOM.btnSubmitModalLogin);

  try {
    const res = await window.autoedit.login({ email, password });
    if (res && res.ok) {
      hideModal(DOM.modalLogin);
      showToast(`Đăng nhập thành công với tài khoản ${email}!`, 'success', 3500);
      updateAccountUI(res.user);
      await refreshWalletBalance();
    } else {
      if (DOM.loginModalError) {
        DOM.loginModalError.style.display = 'block';
        DOM.loginModalError.textContent = res?.error || 'Đăng nhập không thành công. Vui lòng kiểm tra lại tài khoản.';
      }
    }
  } catch (err) {
    if (DOM.loginModalError) {
      DOM.loginModalError.style.display = 'block';
      DOM.loginModalError.textContent = err.message;
    }
  } finally {
    DOM.btnSubmitModalLogin.disabled = false;
    DOM.btnSubmitModalLogin.textContent = 'Đăng Nhập Trực Tiếp';
  }
});

// Account Logout (Keeps Desktop Machine License Untouched)
DOM.btnLogoutAccount?.addEventListener('click', async () => {
  if (confirm('Bạn có chắc chắn muốn đăng xuất tài khoản web? (Bản quyền desktop của máy vẫn được giữ nguyên)')) {
    try {
      await window.autoedit.logout();
      showToast('Đã đăng xuất tài khoản web.', 'info', 2500);
      updateAccountUI(null);
      await refreshWalletBalance();
    } catch (err) {
      showAlert(`Lỗi đăng xuất: ${err.message}`, 'Lỗi');
    }
  }
});

// Topup Tokens Modal Trigger
DOM.btnTopupToken?.addEventListener('click', () => {
  openTokenTopupModal();
});


// Live Event Listeners from Main Process
if (window.autoedit?.onAuthChanged) {
  window.autoedit.onAuthChanged((data) => {
    if (data?.authenticated) {
      updateAccountUI(data.user);
    } else {
      updateAccountUI(null);
    }
    refreshWalletBalance();
  });
}

if (window.autoedit?.onSessionExpired) {
  window.autoedit.onSessionExpired((data) => {
    updateAccountUI(null);
    refreshWalletBalance();
    showToast('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 'warning');
  });
}

if (window.autoedit?.onWalletStateUpdated) {
  window.autoedit.onWalletStateUpdated((walletState) => {
    applyWalletStateToUI(walletState);
  });
}

if (window.autoedit?.onWalletBalanceUpdated) {
  window.autoedit.onWalletBalanceUpdated((balance) => {
    if (balance !== undefined && balance !== null) {
      state.tokenBalance = Number(balance);
      const formatted = Number(balance).toLocaleString();
      if (DOM.tokenBalance) DOM.tokenBalance.textContent = formatted;
      if (DOM.accTokenCount) DOM.accTokenCount.textContent = formatted;
    }
  });
}

DOM.btnRefreshWallet?.addEventListener('click', async () => {
  if (window.autoedit?.refreshWallet) {
    applyWalletStateToUI({ loading: true });
    const wState = await window.autoedit.refreshWallet();
    applyWalletStateToUI(wState);
  } else {
    await refreshWalletBalance();
  }
  showToast('Đã cập nhật số dư ví token!', 'success', 2000);
});

// -----------------------------------------------------------------------------
// State Persistence Helpers
// -----------------------------------------------------------------------------
async function loadStoredState() {
  try {
    const q = await window.autoedit.getStoredData('autoedit_queue');
    if (Array.isArray(q)) state.queue = q;

    const p = await window.autoedit.getStoredData('autoedit_projects');
    if (Array.isArray(p)) state.projects = p;

    const s = await window.autoedit.getStoredData('autoedit_settings');
    if (s && typeof s === 'object') {
      state.settings = { ...state.settings, ...s };
      if (DOM.inpRenderOutputDir && state.settings.renderOutputDir) {
        DOM.inpRenderOutputDir.value = state.settings.renderOutputDir;
      }
    }
  } catch (e) {
    console.warn('Failed loading store data:', e);
  }
}

async function saveQueue() {
  try {
    await window.autoedit.setStoredData('autoedit_queue', state.queue);
  } catch (e) {}
}

async function saveProjects() {
  try {
    await window.autoedit.setStoredData('autoedit_projects', state.projects);
  } catch (e) {}
}

async function saveSettings() {
  try {
    await window.autoedit.setStoredData('autoedit_settings', state.settings);
  } catch (e) {}
}

// -----------------------------------------------------------------------------
// Cloud Explorer & Studio Integration Controller (Priority 4)
// -----------------------------------------------------------------------------

function formatBytes(bytes) {
  if (bytes === undefined || bytes === null || isNaN(bytes) || bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function formatCloudDate(dateStr) {
  if (!dateStr) return '--';
  try {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return String(dateStr);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  } catch (e) {
    return String(dateStr);
  }
}

function getFileIcon(item, isFolder) {
  if (isFolder) return '<i data-lucide="folder" class="icon-sm"></i>';
  const name = (item.name || '').toLowerCase();
  if (/\.(png|jpe?g|webp|bmp|tiff|gif|svg)$/i.test(name)) return '<i data-lucide="image" class="icon-sm"></i>';
  if (/\.(mp3|wav|m4a|aac|flac|ogg|wma)$/i.test(name)) return '<i data-lucide="audio-lines" class="icon-sm"></i>';
  if (/\.(mp4|mov|mkv|avi|webm|flv)$/i.test(name)) return '<i data-lucide="video" class="icon-sm"></i>';
  if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return '<i data-lucide="archive" class="icon-sm"></i>';
  if (/\.(txt|srt|ass|vtt|json|csv|pdf|docx?)$/i.test(name)) return '<i data-lucide="file-text" class="icon-sm"></i>';
  return '<i data-lucide="file" class="icon-sm"></i>';
}

function syncCloudAuthStatus() {
  const isLoggedIn = !!(state.currentUser && (state.currentUser.id || state.currentUser.email));
  if (!isLoggedIn) {
    if (DOM.cloudLockedState) DOM.cloudLockedState.style.display = 'flex';
    if (DOM.cloudMainState) DOM.cloudMainState.style.display = 'none';
  } else {
    if (DOM.cloudLockedState) DOM.cloudLockedState.style.display = 'none';
    if (DOM.cloudMainState) DOM.cloudMainState.style.display = 'flex';
    if (state.currentTab === 'cloud') {
      onOpenCloudTab();
    }
  }
}

async function onOpenCloudTab() {
  if (!state.currentUser) {
    syncCloudAuthStatus();
    return;
  }
  if (!state.cloud.spaces || state.cloud.spaces.length === 0) {
    await loadCloudSpaces();
  } else {
    await Promise.all([loadCloudQuota(), loadCloudFiles()]);
  }
}

async function loadCloudSpaces() {
  if (!window.autoedit?.cloud?.getSpaces) return;
  try {
    const res = await window.autoedit.cloud.getSpaces();
    if (res && res.ok && Array.isArray(res.spaces) && res.spaces.length > 0) {
      state.cloud.spaces = res.spaces;
      if (!state.cloud.currentSpaceId || !state.cloud.spaces.find((s) => s.id == state.cloud.currentSpaceId)) {
        state.cloud.currentSpaceId = state.cloud.spaces[0].id;
      }
    } else {
      state.cloud.spaces = [{ id: 1, name: 'Cloud Cá Nhân', type: 'PERSONAL' }];
      state.cloud.currentSpaceId = 1;
    }
    renderCloudSpacesSelect();
    await Promise.all([loadCloudQuota(), loadCloudFiles()]);
  } catch (err) {
    console.error('Error loading cloud spaces:', err);
    showToast('Lỗi khi tải không gian lưu trữ: ' + (err.message || err), 'error');
  }
}

function renderCloudSpacesSelect() {
  if (!DOM.selCloudSpace) return;
  DOM.selCloudSpace.innerHTML = '';
  state.cloud.spaces.forEach((sp) => {
    const opt = document.createElement('option');
    opt.value = sp.id;
    opt.textContent = `${sp.name} (${sp.type === 'PERSONAL' ? 'Cá nhân' : 'Nhóm'})`;
    if (sp.id == state.cloud.currentSpaceId) opt.selected = true;
    DOM.selCloudSpace.appendChild(opt);
  });
}

async function loadCloudQuota() {
  if (!window.autoedit?.cloud?.getQuota || !state.cloud.currentSpaceId) return;
  try {
    const res = await window.autoedit.cloud.getQuota(state.cloud.currentSpaceId);
    if (res && res.ok && res.quota) {
      state.cloud.quota = res.quota;
      const used = Number(res.quota.used_bytes || 0);
      const total = Number(res.quota.quota_bytes || (15 * 1024 * 1024 * 1024));
      const pct = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
      if (DOM.cloudQuotaUsed) DOM.cloudQuotaUsed.textContent = formatBytes(used);
      if (DOM.cloudQuotaTotal) DOM.cloudQuotaTotal.textContent = formatBytes(total);
      if (DOM.cloudQuotaPct) DOM.cloudQuotaPct.textContent = `${pct}%`;
      if (DOM.cloudQuotaFill) {
        DOM.cloudQuotaFill.style.width = `${pct}%`;
        DOM.cloudQuotaFill.className = pct > 90 ? 'cloud-quota-bar danger' : (pct > 75 ? 'cloud-quota-bar warning' : 'cloud-quota-bar');
      }
    }
  } catch (err) {
    console.warn('Error loading cloud quota:', err);
  }
}

async function loadCloudFiles() {
  if (!window.autoedit?.cloud?.listFiles || !state.cloud.currentSpaceId) return;
  if (DOM.cloudLoadingSpinner) DOM.cloudLoadingSpinner.style.display = 'flex';
  try {
    const res = await window.autoedit.cloud.listFiles({
      spaceId: state.cloud.currentSpaceId,
      parentId: state.cloud.currentFolderId,
      search: state.cloud.searchQuery,
      sort: state.cloud.sortBy,
    });
    if (res && res.ok) {
      state.cloud.folders = res.folders || [];
      state.cloud.files = res.files || [];
      if (res.breadcrumbs && Array.isArray(res.breadcrumbs)) {
        state.cloud.breadcrumbs = res.breadcrumbs;
      }
      renderCloudBreadcrumbs();
      renderCloudTable();
    } else {
      showToast('Lỗi khi nạp danh sách tệp: ' + (res?.error || 'Không xác định'), 'error');
    }
  } catch (err) {
    console.error('Error listing cloud files:', err);
    showToast('Lỗi kết nối máy chủ Cloud: ' + (err.message || err), 'error');
  } finally {
    if (DOM.cloudLoadingSpinner) DOM.cloudLoadingSpinner.style.display = 'none';
  }
}

function renderCloudBreadcrumbs() {
  if (!DOM.cloudBreadcrumbs) return;
  DOM.cloudBreadcrumbs.innerHTML = '';
  const crumbs = state.cloud.breadcrumbs || [{ id: null, name: 'Cloud Cá Nhân' }];
  crumbs.forEach((crumb, idx) => {
    const isLast = idx === crumbs.length - 1;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `crumb-btn ${isLast ? 'active' : ''}`;
    btn.dataset.folderId = crumb.id || '';
    btn.innerHTML = `<i data-lucide="${idx === 0 ? 'cloud' : 'folder'}" class="icon-xs" style="margin-right:4px;"></i>${escapeHtml(crumb.name)}`; refreshIcons(btn);
    if (!isLast) {
      btn.onclick = () => {
        state.cloud.currentFolderId = crumb.id || null;
        loadCloudFiles();
      };
    }
    DOM.cloudBreadcrumbs.appendChild(btn);

    if (!isLast) {
      const sep = document.createElement('span');
      sep.className = 'crumb-sep';
      sep.textContent = '›';
      DOM.cloudBreadcrumbs.appendChild(sep);
    }
  });
}

function renderCloudTable() {
  if (!DOM.cloudTableBody) return;
  DOM.cloudTableBody.innerHTML = '';

  const hasItems = (state.cloud.folders.length > 0 || state.cloud.files.length > 0);
  if (DOM.cloudEmptyFolderHint) {
    DOM.cloudEmptyFolderHint.style.display = hasItems ? 'none' : 'flex';
  }

  // Render Folders
  state.cloud.folders.forEach((folder) => {
    const tr = document.createElement('tr');
    tr.className = 'cloud-folder-row';
    tr.ondblclick = () => {
      state.cloud.currentFolderId = folder.id;
      loadCloudFiles();
    };

    tr.innerHTML = `
      <td>
        <div class="cloud-item-name-cell">
          <span class="cloud-item-icon"><i data-lucide="folder" class="icon-sm"></i></span>
          <span class="cloud-item-title" title="${escapeHtml(folder.name)}">${escapeHtml(folder.name)}</span>
          ${folder.has_share ? '<span class="cloud-share-badge" title="Đang có liên kết chia sẻ"><i data-lucide="share-2" class="icon-xs"></i></span>' : ''}
        </div>
      </td>
      <td><span style="color:var(--text-dim);">--</span></td>
      <td><span style="color:var(--text-dim);">Thư mục</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(folder.updated_at || folder.created_at)}</span></td>
      <td>
        <div class="cloud-table-actions">
          <button type="button" class="btn-cloud-mini btn-open-folder" title="Mở thư mục">Mở</button>
          <button type="button" class="btn-cloud-mini btn-share-item" title="Chia sẻ liên kết"><i data-lucide="share-2" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-rename-item" title="Đổi tên"><i data-lucide="edit-3" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-move-item" title="Di chuyển"><i data-lucide="folder-input" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-trash-item" title="Xóa vào thùng rác"><i data-lucide="trash-2" class="icon-xs"></i></button>
        </div>
      </td>
    `;

    tr.querySelector('.btn-open-folder').onclick = (e) => {
      e.stopPropagation();
      state.cloud.currentFolderId = folder.id;
      loadCloudFiles();
    };
    tr.querySelector('.btn-share-item').onclick = (e) => {
      e.stopPropagation();
      openShareModal(folder, true);
    };
    tr.querySelector('.btn-rename-item').onclick = (e) => {
      e.stopPropagation();
      openRenameModal(folder, true);
    };
    tr.querySelector('.btn-move-item').onclick = (e) => {
      e.stopPropagation();
      openMoveModal(folder, true);
    };
    tr.querySelector('.btn-trash-item').onclick = (e) => {
      e.stopPropagation();
      openDeleteModal(folder, true, false);
    };

    DOM.cloudTableBody.appendChild(tr);
  });

  // Render Files
  state.cloud.files.forEach((file) => {
    const tr = document.createElement('tr');
    tr.className = 'cloud-file-row';
    const icon = getFileIcon(file, false);
    const ext = (file.name || '').split('.').pop()?.toUpperCase() || 'FILE';

    tr.innerHTML = `
      <td>
        <div class="cloud-item-name-cell">
          <span class="cloud-item-icon">${icon}</span>
          <span class="cloud-item-title" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
          ${file.has_share ? '<span class="cloud-share-badge" title="Đang có liên kết chia sẻ"><i data-lucide="share-2" class="icon-xs"></i></span>' : ''}
        </div>
      </td>
      <td><span style="font-family:monospace;font-size:11.5px;">${formatBytes(file.size_bytes)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${escapeHtml(ext)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(file.updated_at || file.created_at)}</span></td>
      <td>
        <div class="cloud-table-actions">
          <button type="button" class="btn-cloud-mini btn-open-file" title="Mở trực tiếp"><i data-lucide="external-link" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-download-file" title="Tải về máy"><i data-lucide="download" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-share-item" title="Chia sẻ liên kết"><i data-lucide="share-2" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-rename-item" title="Đổi tên"><i data-lucide="edit-3" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-move-item" title="Di chuyển"><i data-lucide="folder-input" class="icon-xs"></i></button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-trash-item" title="Xóa vào thùng rác"><i data-lucide="trash-2" class="icon-xs"></i></button>
        </div>
      </td>
    `;

    tr.querySelector('.btn-open-file').onclick = async (e) => {
      e.stopPropagation();
      showToast(`Đang mở ${file.name}...`, 'progress', 2000);
      try {
        const res = await window.autoedit.cloud.openItem(file);
        if (!res?.ok) showToast('Không thể mở tệp: ' + (res?.error || ''), 'error');
      } catch (err) {
        showToast('Lỗi khi mở tệp: ' + err.message, 'error');
      }
    };
    tr.querySelector('.btn-download-file').onclick = async (e) => {
      e.stopPropagation();
      try {
        showToast(`Bắt đầu tải về: ${file.name}`, 'progress', 2500);
        const res = await window.autoedit.cloud.downloadFile({
          spaceId: state.cloud.currentSpaceId,
          fileId: file.id,
          fileName: file.name,
        });
        if (res?.ok) {
          showToast(`Đã tải về thành công: ${file.name}`, 'success', 3000);
        } else if (!res?.canceled) {
          showToast('Tải về thất bại: ' + (res?.error || ''), 'error');
        }
      } catch (err) {
        showToast('Lỗi tải về: ' + err.message, 'error');
      }
    };
    tr.querySelector('.btn-share-item').onclick = (e) => {
      e.stopPropagation();
      openShareModal(file, false);
    };
    tr.querySelector('.btn-rename-item').onclick = (e) => {
      e.stopPropagation();
      openRenameModal(file, false);
    };
    tr.querySelector('.btn-move-item').onclick = (e) => {
      e.stopPropagation();
      openMoveModal(file, false);
    };
    tr.querySelector('.btn-trash-item').onclick = (e) => {
      e.stopPropagation();
      openDeleteModal(file, false, false);
    };

    DOM.cloudTableBody.appendChild(tr);
  });
  refreshIcons(DOM.cloudTableBody);
}

// -----------------------------------------------------------------------------
// Cloud Modals & Actions
// -----------------------------------------------------------------------------

function openNewFolderModal() {
  if (DOM.inpCloudNewFolderName) DOM.inpCloudNewFolderName.value = '';
  if (DOM.cloudNewFolderError) {
    DOM.cloudNewFolderError.style.display = 'none';
    DOM.cloudNewFolderError.textContent = '';
  }
  showModal(DOM.modalCloudNewFolder);
  setTimeout(() => DOM.inpCloudNewFolderName?.focus(), 50);
}

let currentRenameTarget = null;
function openRenameModal(item, isFolder) {
  currentRenameTarget = { item, isFolder };
  if (DOM.inpCloudRenameName) DOM.inpCloudRenameName.value = item.name || '';
  if (DOM.cloudRenameError) {
    DOM.cloudRenameError.style.display = 'none';
    DOM.cloudRenameError.textContent = '';
  }
  showModal(DOM.modalCloudRename);
  setTimeout(() => {
    DOM.inpCloudRenameName?.focus();
    DOM.inpCloudRenameName?.select();
  }, 50);
}

let currentMoveTarget = null;
function openMoveModal(item, isFolder) {
  currentMoveTarget = { item, isFolder };
  if (DOM.cloudMoveItemTitle) DOM.cloudMoveItemTitle.textContent = item.name || '';
  if (DOM.cloudMoveError) {
    DOM.cloudMoveError.style.display = 'none';
    DOM.cloudMoveError.textContent = '';
  }

  if (DOM.selCloudMoveDestination) {
    DOM.selCloudMoveDestination.innerHTML = '<option value="">Thư mục gốc (Gốc không gian)</option>';
    // Populate folders except current folder itself (if target is folder)
    state.cloud.folders.forEach((f) => {
      if (isFolder && f.id == item.id) return;
      const opt = document.createElement('option');
      opt.value = f.id;
      opt.textContent = f.name;
      DOM.selCloudMoveDestination.appendChild(opt);
    });
  }

  showModal(DOM.modalCloudMove);
}

let currentDeleteTarget = null;
function openDeleteModal(item, isFolder, isPermanent) {
  currentDeleteTarget = { item, isFolder, isPermanent };
  if (DOM.cloudDeleteModalTitle) {
    DOM.cloudDeleteModalTitle.innerHTML = `<i data-lucide="trash-2" class="icon-sm" style="color:var(--danger);margin-right:6px;"></i>${isPermanent ? 'Xác Nhận Xóa Vĩnh Viễn' : 'Chuyển Vào Thùng Rác'}`; refreshIcons(DOM.cloudDeleteModalTitle);
  }
  if (DOM.cloudDeleteModalMessage) {
    if (isPermanent) {
      DOM.cloudDeleteModalMessage.innerHTML = `Bạn có chắc chắn muốn <strong style="color:#f87171;">xóa vĩnh viễn</strong> ${isFolder ? 'thư mục' : 'tệp'} <strong>${escapeHtml(item.name)}</strong>?<br/><span style="color:#f87171;font-size:11.5px;">Hành động này không thể khôi phục.</span>`;
    } else {
      DOM.cloudDeleteModalMessage.innerHTML = `Bạn có muốn chuyển ${isFolder ? 'thư mục' : 'tệp'} <strong>${escapeHtml(item.name)}</strong> vào thùng rác?`;
    }
  }
  if (DOM.cloudDeleteError) {
    DOM.cloudDeleteError.style.display = 'none';
    DOM.cloudDeleteError.textContent = '';
  }
  showModal(DOM.modalCloudConfirmDelete);
}

let currentShareTarget = null;
let activeShareRecord = null;

async function openShareModal(item, isFolder) {
  currentShareTarget = { item, isFolder };
  activeShareRecord = null;

  if (DOM.cloudShareItemIcon) {
    DOM.cloudShareItemIcon.innerHTML = isFolder ? '<i data-lucide="folder" class="icon-md"></i>' : getFileIcon(item, false); refreshIcons(DOM.cloudShareItemIcon);
  }
  if (DOM.cloudShareItemName) {
    DOM.cloudShareItemName.textContent = item.name || '';
  }
  if (DOM.cloudShareItemMeta) {
    DOM.cloudShareItemMeta.textContent = isFolder ? 'Thư mục' : `Kích thước: ${formatBytes(item.size_bytes)}`;
  }

  if (DOM.cloudShareError) {
    DOM.cloudShareError.style.display = 'none';
    DOM.cloudShareError.textContent = '';
  }

  if (DOM.cloudShareActiveWrap) DOM.cloudShareActiveWrap.style.display = 'none';
  if (DOM.cloudShareCreateWrap) DOM.cloudShareCreateWrap.style.display = 'flex';
  if (DOM.btnSubmitModalCloudShare) DOM.btnSubmitModalCloudShare.style.display = 'inline-block';

  showModal(DOM.modalCloudShare);

  // Check if active share already exists
  try {
    const res = await window.autoedit.cloud.getItemShares({
      spaceId: state.cloud.currentSpaceId,
      itemType: isFolder ? 'FOLDER' : 'FILE',
      itemId: item.id,
    });

    if (res && res.ok && res.shares && res.shares.length > 0) {
      activeShareRecord = res.shares[0];
      if (DOM.cloudShareActiveWrap) {
        DOM.cloudShareActiveWrap.style.display = 'flex';
        const expStr = activeShareRecord.expires_at ? `Hết hạn: ${formatCloudDate(activeShareRecord.expires_at)}` : 'Không hết hạn';
        if (DOM.cloudShareActiveExpires) DOM.cloudShareActiveExpires.textContent = expStr;
        if (DOM.cloudShareActiveAccess) {
          DOM.cloudShareActiveAccess.textContent = activeShareRecord.access_level === 'ALLOW_DOWNLOAD' ? 'Cho phép tải xuống' : 'Chỉ xem';
        }
        if (DOM.inpCloudActiveShareUrl) {
          DOM.inpCloudActiveShareUrl.value = activeShareRecord.share_url || `https://2tamne.site/share/${activeShareRecord.id}`;
        }
      }
      if (DOM.cloudShareCreateWrap) DOM.cloudShareCreateWrap.style.display = 'none';
      if (DOM.btnSubmitModalCloudShare) DOM.btnSubmitModalCloudShare.style.display = 'none';
    }
  } catch (e) {
    // Keep create form active
  }
}

// -----------------------------------------------------------------------------
// Cloud Trash Controller
// -----------------------------------------------------------------------------

async function toggleTrashView(show) {
  state.cloud.isTrashOpen = show !== undefined ? show : !state.cloud.isTrashOpen;
  if (DOM.cloudNormalView) DOM.cloudNormalView.style.display = state.cloud.isTrashOpen ? 'none' : 'flex';
  if (DOM.cloudTrashView) DOM.cloudTrashView.style.display = state.cloud.isTrashOpen ? 'flex' : 'none';

  if (state.cloud.isTrashOpen) {
    await loadCloudTrash();
  } else {
    await loadCloudFiles();
  }
}

async function loadCloudTrash() {
  if (!window.autoedit?.cloud?.listTrash || !state.cloud.currentSpaceId) return;
  try {
    const res = await window.autoedit.cloud.listTrash(state.cloud.currentSpaceId);
    if (res && res.ok) {
      state.cloud.trash = res.items || [];
      if (DOM.cloudTrashCount) DOM.cloudTrashCount.textContent = state.cloud.trash.length;
      renderCloudTrashTable();
    }
  } catch (err) {
    console.error('Error listing cloud trash:', err);
    showToast('Lỗi khi tải thùng rác: ' + (err.message || err), 'error');
  }
}

function renderCloudTrashTable() {
  if (!DOM.cloudTrashTableBody) return;
  DOM.cloudTrashTableBody.innerHTML = '';

  const hasTrash = state.cloud.trash && state.cloud.trash.length > 0;
  if (DOM.cloudTrashEmptyHint) DOM.cloudTrashEmptyHint.style.display = hasTrash ? 'none' : 'flex';

  if (!hasTrash) return;

  state.cloud.trash.forEach((item) => {
    const tr = document.createElement('tr');
    const isFolder = item.type === 'FOLDER';
    const icon = isFolder ? '<i data-lucide="folder" class="icon-sm"></i>' : getFileIcon(item, false);

    tr.innerHTML = `
      <td>
        <div class="cloud-item-name-cell">
          <span class="cloud-item-icon">${icon}</span>
          <span class="cloud-item-title" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</span>
        </div>
      </td>
      <td><span style="font-family:monospace;font-size:11.5px;">${isFolder ? '--' : formatBytes(item.size_bytes)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(item.deleted_at || item.updated_at)}</span></td>
      <td>
        <div class="cloud-table-actions">
          <button type="button" class="btn-cloud-mini btn-restore-item" title="Khôi phục"><i data-lucide="rotate-ccw" class="icon-xs"></i> Khôi phục</button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-delete-permanent" title="Xóa vĩnh viễn"><i data-lucide="trash-2" class="icon-xs"></i> Xóa hẳn</button>
        </div>
      </td>
    `;

    tr.querySelector('.btn-restore-item').onclick = async (e) => {
      e.stopPropagation();
      try {
        const res = await window.autoedit.cloud.restoreItem({
          spaceId: state.cloud.currentSpaceId,
          type: item.type,
          itemId: item.id,
        });
        if (res?.ok) {
          showToast(`Đã khôi phục: ${item.name}`, 'success', 2500);
          await loadCloudTrash();
          await loadCloudQuota();
        } else {
          showToast('Lỗi khôi phục: ' + (res?.error || ''), 'error');
        }
      } catch (err) {
        showToast('Lỗi: ' + err.message, 'error');
      }
    };

    tr.querySelector('.btn-delete-permanent').onclick = (e) => {
      e.stopPropagation();
      openDeleteModal(item, isFolder, true);
    };

    DOM.cloudTrashTableBody.appendChild(tr);
  });
  refreshIcons(DOM.cloudTrashTableBody);
}

// -----------------------------------------------------------------------------
// Cloud Upload Controller & Floating Drawer
// -----------------------------------------------------------------------------

async function startCloudUpload(filePath) {
  if (!filePath) return;
  if (!state.currentUser) {
    showAlert('Vui lòng đăng nhập tài khoản 2TOOLNE để tải lên Cloud.', 'Cần Đăng Nhập');
    return;
  }
  if (!state.cloud.currentSpaceId) {
    await loadCloudSpaces();
  }

  const fileName = filePath.split(/[/\\]/).pop();
  showToast(`Bắt đầu tải lên: ${fileName}`, 'progress', 2500);

  if (DOM.cloudUploadDrawer) DOM.cloudUploadDrawer.style.display = 'block';

  try {
    const res = await window.autoedit.cloud.uploadFile({
      spaceId: state.cloud.currentSpaceId,
      folderId: state.cloud.currentFolderId,
      localFilePath: filePath,
    });

    if (res && res.ok) {
      showToast(`Đã tải lên thành công: ${fileName}!`, 'success', 3000);
      await Promise.all([loadCloudFiles(), loadCloudQuota()]);
    } else if (!res?.canceled) {
      showToast(`Tải lên thất bại (${fileName}): ${res?.error || 'Lỗi không xác định'}`, 'error', 4000);
    }
  } catch (err) {
    showToast(`Lỗi tải lên: ${err.message}`, 'error', 4000);
  }
}

function updateUploadDrawerItem(data) {
  if (!DOM.cloudUploadDrawerBody) return;
  if (DOM.cloudUploadDrawer) DOM.cloudUploadDrawer.style.display = 'block';

  let itemEl = document.getElementById(`uploadItem_${data.uploadId}`);
  if (!itemEl) {
    itemEl = document.createElement('div');
    itemEl.id = `uploadItem_${data.uploadId}`;
    itemEl.className = 'cloud-upload-item';
    itemEl.innerHTML = `
      <div class="upload-item-header">
        <span class="upload-item-name" title="${escapeHtml(data.fileName)}">${escapeHtml(data.fileName)}</span>
        <button type="button" class="upload-item-cancel" title="Hủy tải lên"><i data-lucide="x" class="icon-xs"></i></button>
      </div>
      <div class="upload-item-track">
        <div class="upload-item-fill" style="width: 0%;"></div>
      </div>
      <div class="upload-item-meta">
        <span class="upload-item-status">Chuẩn bị...</span>
        <span class="upload-item-speed">--</span>
      </div>
    `;

    itemEl.querySelector('.upload-item-cancel').onclick = () => {
      window.autoedit?.cloud?.cancelUpload?.(data.uploadId);
      itemEl.querySelector('.upload-item-status').textContent = 'Đã hủy';
      setTimeout(() => itemEl.remove(), 2500);
    };

    DOM.cloudUploadDrawerBody.prepend(itemEl);
  }

  const fillEl = itemEl.querySelector('.upload-item-fill');
  const statusEl = itemEl.querySelector('.upload-item-status');
  const speedEl = itemEl.querySelector('.upload-item-speed');

  if (data.status === 'uploading') {
    if (fillEl) fillEl.style.width = `${data.percent || 0}%`;
    if (statusEl) statusEl.textContent = `${data.percent || 0}% (${formatBytes(data.loaded)} / ${formatBytes(data.total)})`;
    if (speedEl && data.speed) speedEl.textContent = `${formatBytes(data.speed)}/s`;
  } else if (data.status === 'completed') {
    if (fillEl) {
      fillEl.style.width = '100%';
      fillEl.style.background = '#10b981';
    }
    if (statusEl) {
      statusEl.innerHTML = '<i data-lucide="check" class="icon-xs" style="margin-right:4px;"></i>Hoàn tất'; refreshIcons(statusEl);
      statusEl.style.color = '#10b981';
    }
    setTimeout(() => {
      itemEl.remove();
      if (DOM.cloudUploadDrawerBody && DOM.cloudUploadDrawerBody.children.length === 0) {
        if (DOM.cloudUploadDrawer) DOM.cloudUploadDrawer.style.display = 'none';
      }
    }, 3500);
  } else if (data.status === 'error') {
    if (statusEl) {
      statusEl.innerHTML = '<i data-lucide="alert-circle" class="icon-xs" style="margin-right:4px;"></i>' + escapeHtml(data.error || 'Lỗi'); refreshIcons(statusEl);
      statusEl.style.color = '#f87171';
    }
  }

  if (DOM.cloudUploadDrawerCount) {
    const activeCount = DOM.cloudUploadDrawerBody.children.length;
    DOM.cloudUploadDrawerCount.textContent = `(${activeCount})`;
  }
}

// -----------------------------------------------------------------------------
// Studio Cloud Asset Picker (Decoupled Cached Local Paths)
// -----------------------------------------------------------------------------

function openCloudPicker(options = {}) {
  return new Promise(async (resolve, reject) => {
    if (!state.currentUser) {
      showAlert('Vui lòng đăng nhập tài khoản 2TOOLNE để chọn tài nguyên từ Cloud.', 'Cần Đăng Nhập');
      resolve([]);
      return;
    }

    state.cloud.picker.active = true;
    state.cloud.picker.mode = options.mode || 'SELECT_IMAGES';
    state.cloud.picker.selectedFiles = [];
    state.cloud.picker.currentFolderId = null;
    state.cloud.picker.breadcrumbs = [{ id: null, name: 'Cloud Cá Nhân' }];
    state.cloud.picker.resolve = resolve;
    state.cloud.picker.reject = reject;

    if (DOM.cloudPickerTitle) {
      DOM.cloudPickerTitle.innerHTML = `<i data-lucide="cloud" class="icon-sm" style="color:var(--brand);margin-right:6px;"></i>${escapeHtml(options.title || (state.cloud.picker.mode === 'SELECT_IMAGES' ? 'Chọn Ảnh Từ Cloud' : 'Chọn Âm Thanh Từ Cloud'))}`; refreshIcons(DOM.cloudPickerTitle);
    }
    if (DOM.cloudPickerTypeHint) {
      DOM.cloudPickerTypeHint.textContent = (state.cloud.picker.mode === 'SELECT_IMAGES')
        ? 'Hỗ trợ: PNG, JPG, JPEG, WEBP, BMP, TIFF'
        : 'Hỗ trợ: MP3, WAV, M4A, AAC, FLAC';
    }
    if (DOM.cloudPickerSelectedCount) DOM.cloudPickerSelectedCount.textContent = '0';
    if (DOM.cloudPickerConfirmCount) DOM.cloudPickerConfirmCount.textContent = '0';
    if (DOM.btnSubmitModalCloudPicker) DOM.btnSubmitModalCloudPicker.disabled = true;
    if (DOM.chkCloudPickerSelectAll) {
      DOM.chkCloudPickerSelectAll.checked = false;
      DOM.chkCloudPickerSelectAll.style.display = (state.cloud.picker.mode === 'SELECT_IMAGES') ? 'inline-block' : 'none';
    }
    if (DOM.cloudPickerStatusText) {
      DOM.cloudPickerStatusText.textContent = 'Tệp chọn sẽ được tải và lưu tạm an toàn để nạp vào Studio.';
    }

    showModal(DOM.modalCloudPicker);

    if (!state.cloud.spaces || state.cloud.spaces.length === 0) {
      await loadCloudSpaces();
    }
    await loadPickerFiles();
  });
}

async function loadPickerFiles() {
  if (!window.autoedit?.cloud?.listFiles || !state.cloud.currentSpaceId) return;
  if (DOM.cloudPickerLoading) DOM.cloudPickerLoading.style.display = 'flex';

  try {
    const res = await window.autoedit.cloud.listFiles({
      spaceId: state.cloud.currentSpaceId,
      parentId: state.cloud.picker.currentFolderId,
      search: DOM.inpCloudPickerSearch?.value?.trim() || '',
      sort: 'date_desc',
    });

    renderPickerBreadcrumbs(res?.breadcrumbs);

    const folders = res?.folders || [];
    let files = res?.files || [];

    // Filter files based on picker mode
    if (state.cloud.picker.mode === 'SELECT_IMAGES') {
      files = files.filter((f) => /\.(png|jpe?g|webp|bmp|tiff)$/i.test(f.name || ''));
    } else if (state.cloud.picker.mode === 'SELECT_AUDIO') {
      files = files.filter((f) => /\.(mp3|wav|m4a|aac|flac)$/i.test(f.name || ''));
    }

    renderPickerTable(folders, files);
  } catch (err) {
    console.error('Error loading picker files:', err);
  } finally {
    if (DOM.cloudPickerLoading) DOM.cloudPickerLoading.style.display = 'none';
  }
}

function renderPickerBreadcrumbs(breadcrumbs) {
  if (!DOM.cloudPickerBreadcrumbs) return;
  DOM.cloudPickerBreadcrumbs.innerHTML = '';
  const crumbs = breadcrumbs || [{ id: null, name: 'Cloud Cá Nhân' }];
  crumbs.forEach((crumb, idx) => {
    const isLast = idx === crumbs.length - 1;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `crumb-btn ${isLast ? 'active' : ''}`;
    btn.innerHTML = `<i data-lucide="${idx === 0 ? 'cloud' : 'folder'}" class="icon-xs" style="margin-right:4px;"></i>${escapeHtml(crumb.name)}`; refreshIcons(btn);
    if (!isLast) {
      btn.onclick = () => {
        state.cloud.picker.currentFolderId = crumb.id || null;
        loadPickerFiles();
      };
    }
    DOM.cloudPickerBreadcrumbs.appendChild(btn);

    if (!isLast) {
      const sep = document.createElement('span');
      sep.className = 'crumb-sep';
      sep.textContent = '›';
      DOM.cloudPickerBreadcrumbs.appendChild(sep);
    }
  });
}

function renderPickerTable(folders, files) {
  if (!DOM.cloudPickerTableBody) return;
  DOM.cloudPickerTableBody.innerHTML = '';

  const hasItems = folders.length > 0 || files.length > 0;
  if (DOM.cloudPickerEmptyHint) DOM.cloudPickerEmptyHint.style.display = hasItems ? 'none' : 'flex';

  // Folders in Picker
  folders.forEach((folder) => {
    const tr = document.createElement('tr');
    tr.style.cursor = 'pointer';
    tr.innerHTML = `
      <td></td>
      <td>
        <div class="cloud-item-name-cell">
          <span class="cloud-item-icon"><i data-lucide="folder" class="icon-sm"></i></span>
          <span class="cloud-item-title" style="font-weight:600;">${escapeHtml(folder.name)}</span>
        </div>
      </td>
      <td><span style="color:var(--text-dim);">--</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(folder.updated_at || folder.created_at)}</span></td>
    `;
    tr.onclick = () => {
      state.cloud.picker.currentFolderId = folder.id;
      loadPickerFiles();
    };
    DOM.cloudPickerTableBody.appendChild(tr);
  });

  // Files in Picker
  files.forEach((file) => {
    const tr = document.createElement('tr');
    tr.style.cursor = 'pointer';
    const isSelected = state.cloud.picker.selectedFiles.some((f) => f.id == file.id);
    const icon = getFileIcon(file, false);
    const inputType = state.cloud.picker.mode === 'SELECT_IMAGES' ? 'checkbox' : 'radio';

    tr.innerHTML = `
      <td>
        <input type="${inputType}" name="cloudPickerItemChoice" class="chk-picker-item" ${isSelected ? 'checked' : ''} />
      </td>
      <td>
        <div class="cloud-item-name-cell">
          <span class="cloud-item-icon">${icon}</span>
          <span class="cloud-item-title">${escapeHtml(file.name)}</span>
        </div>
      </td>
      <td><span style="font-family:monospace;font-size:11.5px;">${formatBytes(file.size_bytes)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(file.updated_at || file.created_at)}</span></td>
    `;

    const chk = tr.querySelector('.chk-picker-item');

    const toggleSelection = () => {
      if (state.cloud.picker.mode === 'SELECT_AUDIO') {
        state.cloud.picker.selectedFiles = [file];
        DOM.cloudPickerTableBody.querySelectorAll('.chk-picker-item').forEach((c) => (c.checked = false));
        chk.checked = true;
      } else {
        const existIdx = state.cloud.picker.selectedFiles.findIndex((f) => f.id == file.id);
        if (existIdx >= 0) {
          state.cloud.picker.selectedFiles.splice(existIdx, 1);
          chk.checked = false;
        } else {
          state.cloud.picker.selectedFiles.push(file);
          chk.checked = true;
        }
      }
      updatePickerSelectionUI();
    };

    tr.onclick = (e) => {
      if (e.target.tagName !== 'INPUT') toggleSelection();
    };
    chk.onchange = (e) => {
      e.stopPropagation();
      toggleSelection();
    };

    DOM.cloudPickerTableBody.appendChild(tr);
  });
}

function updatePickerSelectionUI() {
  const count = state.cloud.picker.selectedFiles.length;
  if (DOM.cloudPickerSelectedCount) DOM.cloudPickerSelectedCount.textContent = count;
  if (DOM.cloudPickerConfirmCount) DOM.cloudPickerConfirmCount.textContent = count;
  if (DOM.btnSubmitModalCloudPicker) DOM.btnSubmitModalCloudPicker.disabled = count === 0;
}

// -----------------------------------------------------------------------------
// Initialize Cloud Explorer Event Handlers
// -----------------------------------------------------------------------------
function initCloudExplorer() {
  // Login prompt CTA
  DOM.btnCloudLoginPrompt?.addEventListener('click', () => {
    resetLoginModal();
    showModal(DOM.modalLogin);
  });

  // Topbar space change
  DOM.selCloudSpace?.addEventListener('change', async (e) => {
    state.cloud.currentSpaceId = e.target.value;
    state.cloud.currentFolderId = null;
    await Promise.all([loadCloudQuota(), loadCloudFiles()]);
  });

  // Refresh button
  DOM.btnRefreshCloud?.addEventListener('click', async () => {
    showToast('Đang làm mới Cloud...', 'progress', { id: 'refresh-cloud' });
    await Promise.all([loadCloudQuota(), state.cloud.isTrashOpen ? loadCloudTrash() : loadCloudFiles()]);
    showToast('Đã làm mới Cloud.', 'success', { id: 'refresh-cloud' });
  });

  // Toggle trash
  DOM.btnCloudToggleTrash?.addEventListener('click', () => {
    toggleTrashView(true);
  });
  DOM.btnCloudBackFromTrash?.addEventListener('click', () => {
    toggleTrashView(false);
  });

  // Search input with debounce
  let searchDebounceTimer = null;
  DOM.inpCloudSearch?.addEventListener('input', (e) => {
    clearTimeout(searchDebounceTimer);
    const val = e.target.value;
    if (DOM.btnClearCloudSearch) DOM.btnClearCloudSearch.style.display = val ? 'inline-block' : 'none';
    searchDebounceTimer = setTimeout(() => {
      state.cloud.searchQuery = val;
      loadCloudFiles();
    }, 300);
  });

  DOM.btnClearCloudSearch?.addEventListener('click', () => {
    if (DOM.inpCloudSearch) DOM.inpCloudSearch.value = '';
    if (DOM.btnClearCloudSearch) DOM.btnClearCloudSearch.style.display = 'none';
    state.cloud.searchQuery = '';
    loadCloudFiles();
  });

  // Sort dropdown
  DOM.selCloudSort?.addEventListener('change', (e) => {
    state.cloud.sortBy = e.target.value;
    loadCloudFiles();
  });

  // New folder button
  DOM.btnCloudNewFolder?.addEventListener('click', () => {
    openNewFolderModal();
  });

  // Upload button
  DOM.btnCloudUploadAction?.addEventListener('click', async () => {
    if (!window.autoedit?.cloud?.selectLocalUploadFiles) return;
    try {
      const files = await window.autoedit.cloud.selectLocalUploadFiles();
      if (files && files.length > 0) {
        for (const f of files) {
          await startCloudUpload(f);
        }
      }
    } catch (err) {
      console.error('Error selecting upload files:', err);
    }
  });

  // Drag & drop on cloudDropZone
  const dropZone = DOM.cloudDropZone;
  if (dropZone) {
    ['dragenter', 'dragover'].forEach((evtName) => {
      dropZone.addEventListener(evtName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.add('drag-over');
      });
    });
    ['dragleave', 'drop'].forEach((evtName) => {
      dropZone.addEventListener(evtName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.remove('drag-over');
      });
    });
    dropZone.addEventListener('drop', (e) => {
      const files = Array.from(e.dataTransfer?.files || []);
      if (files.length > 0) {
        files.forEach((f) => {
          const filePath = (window.autoedit?.getPathForFile ? window.autoedit.getPathForFile(f) : f.path) || '';
          if (filePath) startCloudUpload(filePath);
        });
      }
    });
  }

  // Upload drawer toggle & close
  DOM.btnToggleUploadDrawer?.addEventListener('click', () => {
    if (DOM.cloudUploadDrawerBody) {
      const isCollapsed = DOM.cloudUploadDrawerBody.style.display === 'none';
      DOM.cloudUploadDrawerBody.style.display = isCollapsed ? 'flex' : 'none';
    }
  });
  DOM.btnCloseUploadDrawer?.addEventListener('click', () => {
    if (DOM.cloudUploadDrawer) DOM.cloudUploadDrawer.style.display = 'none';
  });

  // Modal: New Folder Submit
  DOM.btnSubmitModalCloudNewFolder?.addEventListener('click', async () => {
    const name = DOM.inpCloudNewFolderName?.value?.trim();
    if (!name) {
      if (DOM.cloudNewFolderError) {
        DOM.cloudNewFolderError.style.display = 'block';
        DOM.cloudNewFolderError.textContent = 'Vui lòng nhập tên thư mục.';
      }
      return;
    }

    try {
      const res = await window.autoedit.cloud.createFolder({
        spaceId: state.cloud.currentSpaceId,
        parentId: state.cloud.currentFolderId,
        name,
      });
      if (res && res.ok) {
        hideModal(DOM.modalCloudNewFolder);
        showToast(`Đã tạo thư mục: ${name}`, 'success', 2500);
        await loadCloudFiles();
      } else {
        if (DOM.cloudNewFolderError) {
          DOM.cloudNewFolderError.style.display = 'block';
          DOM.cloudNewFolderError.textContent = res?.error || 'Lỗi khi tạo thư mục.';
        }
      }
    } catch (err) {
      if (DOM.cloudNewFolderError) {
        DOM.cloudNewFolderError.style.display = 'block';
        DOM.cloudNewFolderError.textContent = err.message;
      }
    }
  });

  // Modal: Rename Submit
  DOM.btnSubmitModalCloudRename?.addEventListener('click', async () => {
    if (!currentRenameTarget) return;
    const newName = DOM.inpCloudRenameName?.value?.trim();
    if (!newName) {
      if (DOM.cloudRenameError) {
        DOM.cloudRenameError.style.display = 'block';
        DOM.cloudRenameError.textContent = 'Vui lòng nhập tên mới.';
      }
      return;
    }

    try {
      const res = await window.autoedit.cloud.renameItem({
        spaceId: state.cloud.currentSpaceId,
        type: currentRenameTarget.isFolder ? 'FOLDER' : 'FILE',
        itemId: currentRenameTarget.item.id,
        newName,
      });
      if (res && res.ok) {
        hideModal(DOM.modalCloudRename);
        showToast(`Đã đổi tên thành công: ${newName}`, 'success', 2500);
        await loadCloudFiles();
      } else {
        if (DOM.cloudRenameError) {
          DOM.cloudRenameError.style.display = 'block';
          DOM.cloudRenameError.textContent = res?.error || 'Không thể đổi tên.';
        }
      }
    } catch (err) {
      if (DOM.cloudRenameError) {
        DOM.cloudRenameError.style.display = 'block';
        DOM.cloudRenameError.textContent = err.message;
      }
    }
  });

  // Modal: Move Submit
  DOM.btnSubmitModalCloudMove?.addEventListener('click', async () => {
    if (!currentMoveTarget) return;
    const targetFolderId = DOM.selCloudMoveDestination?.value ? Number(DOM.selCloudMoveDestination.value) : null;

    try {
      const res = await window.autoedit.cloud.moveItem({
        spaceId: state.cloud.currentSpaceId,
        type: currentMoveTarget.isFolder ? 'FOLDER' : 'FILE',
        itemId: currentMoveTarget.item.id,
        targetFolderId,
      });
      if (res && res.ok) {
        hideModal(DOM.modalCloudMove);
        showToast(`Đã di chuyển thành công: ${currentMoveTarget.item.name}`, 'success', 2500);
        await loadCloudFiles();
      } else {
        if (DOM.cloudMoveError) {
          DOM.cloudMoveError.style.display = 'block';
          DOM.cloudMoveError.textContent = res?.error || 'Không thể di chuyển.';
        }
      }
    } catch (err) {
      if (DOM.cloudMoveError) {
        DOM.cloudMoveError.style.display = 'block';
        DOM.cloudMoveError.textContent = err.message;
      }
    }
  });

  // Modal: Confirm Delete Submit
  DOM.btnSubmitModalCloudConfirmDelete?.addEventListener('click', async () => {
    if (!currentDeleteTarget) return;
    const { item, isFolder, isPermanent } = currentDeleteTarget;

    try {
      let res;
      if (isPermanent) {
        res = await window.autoedit.cloud.permanentDelete({
          spaceId: state.cloud.currentSpaceId,
          type: isFolder ? 'FOLDER' : 'FILE',
          itemId: item.id,
        });
      } else {
        res = await window.autoedit.cloud.trashItem({
          spaceId: state.cloud.currentSpaceId,
          type: isFolder ? 'FOLDER' : 'FILE',
          itemId: item.id,
        });
      }

      if (res && res.ok) {
        hideModal(DOM.modalCloudConfirmDelete);
        showToast(isPermanent ? `Đã xóa vĩnh viễn: ${item.name}` : `Đã chuyển vào thùng rác: ${item.name}`, 'success', 2500);
        await Promise.all([
          state.cloud.isTrashOpen ? loadCloudTrash() : loadCloudFiles(),
          loadCloudQuota(),
        ]);
      } else {
        if (DOM.cloudDeleteError) {
          DOM.cloudDeleteError.style.display = 'block';
          DOM.cloudDeleteError.textContent = res?.error || 'Không thể xóa mục này.';
        }
      }
    } catch (err) {
      if (DOM.cloudDeleteError) {
        DOM.cloudDeleteError.style.display = 'block';
        DOM.cloudDeleteError.textContent = err.message;
      }
    }
  });

  // Modal Close & Cancel Listeners
  [DOM.btnCloseModalCloudNewFolder, DOM.btnCancelModalCloudNewFolder].forEach((b) => b?.addEventListener('click', () => hideModal(DOM.modalCloudNewFolder)));
  [DOM.btnCloseModalCloudRename, DOM.btnCancelModalCloudRename].forEach((b) => b?.addEventListener('click', () => hideModal(DOM.modalCloudRename)));
  [DOM.btnCloseModalCloudMove, DOM.btnCancelModalCloudMove].forEach((b) => b?.addEventListener('click', () => hideModal(DOM.modalCloudMove)));
  [DOM.btnCloseModalCloudConfirmDelete, DOM.btnCancelModalCloudConfirmDelete].forEach((b) => b?.addEventListener('click', () => hideModal(DOM.modalCloudConfirmDelete)));
  [DOM.btnCloseModalCloudShare, DOM.btnCancelModalCloudShare].forEach((b) => b?.addEventListener('click', () => hideModal(DOM.modalCloudShare)));
  [DOM.btnCloseModalCloudPicker, DOM.btnCancelModalCloudPicker].forEach((b) => b?.addEventListener('click', () => {
    if (state.cloud.picker.resolve) state.cloud.picker.resolve([]);
    state.cloud.picker.active = false;
    hideModal(DOM.modalCloudPicker);
  }));

  // Modal 13: Cloud Share Event Listeners
  DOM.btnSubmitModalCloudShare?.addEventListener('click', async () => {
    if (!currentShareTarget) return;
    const { item, isFolder } = currentShareTarget;

    const accessLevel = document.querySelector('input[name="cloudShareAccessLevel"]:checked')?.value || 'ALLOW_DOWNLOAD';
    const expiresIn = DOM.selCloudShareExpires?.value || '7d';

    if (DOM.btnSubmitModalCloudShare) {
      DOM.btnSubmitModalCloudShare.disabled = true;
      DOM.btnSubmitModalCloudShare.textContent = 'Đang tạo...';
    }

    try {
      const res = await window.autoedit.cloud.createShare({
        spaceId: state.cloud.currentSpaceId,
        itemType: isFolder ? 'FOLDER' : 'FILE',
        itemId: item.id,
        accessLevel,
        expiresIn,
      });

      if (res && res.ok && res.share) {
        activeShareRecord = res.share;
        item.has_share = true;
        renderCloudTable();

        if (DOM.cloudShareActiveWrap) {
          DOM.cloudShareActiveWrap.style.display = 'flex';
          const expStr = res.share.expires_at ? `Hết hạn: ${formatCloudDate(res.share.expires_at)}` : 'Không hết hạn';
          if (DOM.cloudShareActiveExpires) DOM.cloudShareActiveExpires.textContent = expStr;
          if (DOM.cloudShareActiveAccess) {
            DOM.cloudShareActiveAccess.textContent = res.share.access_level === 'ALLOW_DOWNLOAD' ? 'Cho phép tải xuống' : 'Chỉ xem';
          }
          if (DOM.inpCloudActiveShareUrl) {
            DOM.inpCloudActiveShareUrl.value = res.share.share_url || `https://2tamne.site/share/${res.share.raw_token}`;
          }
        }
        if (DOM.cloudShareCreateWrap) DOM.cloudShareCreateWrap.style.display = 'none';
        if (DOM.btnSubmitModalCloudShare) DOM.btnSubmitModalCloudShare.style.display = 'none';

        // Auto copy to clipboard
        if (res.share.share_url && navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(res.share.share_url);
        }
        showToast('Đã tạo liên kết và sao chép vào bộ nhớ tạm!', 'success', 3000);
      } else {
        if (DOM.cloudShareError) {
          DOM.cloudShareError.style.display = 'block';
          DOM.cloudShareError.textContent = res?.error || 'Không thể tạo liên kết chia sẻ.';
        }
      }
    } catch (err) {
      if (DOM.cloudShareError) {
        DOM.cloudShareError.style.display = 'block';
        DOM.cloudShareError.textContent = err.message;
      }
    } finally {
      if (DOM.btnSubmitModalCloudShare) {
        DOM.btnSubmitModalCloudShare.disabled = false;
        DOM.btnSubmitModalCloudShare.textContent = 'Tạo Liên Kết';
      }
    }
  });

  DOM.btnCopyActiveShareLink?.addEventListener('click', async () => {
    const url = DOM.inpCloudActiveShareUrl?.value;
    if (url) {
      try {
        await navigator.clipboard.writeText(url);
        showToast('Đã sao chép liên kết vào bộ nhớ tạm.', 'success', 2500);
      } catch (e) {
        showToast('Không thể sao chép: ' + e.message, 'error');
      }
    }
  });

  DOM.btnRevokeActiveShareLink?.addEventListener('click', async () => {
    if (!activeShareRecord) return;
    const confirmed = confirm('Bạn có chắc chắn muốn thu hồi liên kết chia sẻ này ngay lập tức?');
    if (!confirmed) return;

    try {
      const res = await window.autoedit.cloud.revokeShare({ shareId: activeShareRecord.id });
      if (res && res.ok) {
        showToast('Đã thu hồi liên kết chia sẻ thành công.', 'success', 2500);
        if (currentShareTarget?.item) {
          currentShareTarget.item.has_share = false;
          renderCloudTable();
        }
        activeShareRecord = null;
        if (DOM.cloudShareActiveWrap) DOM.cloudShareActiveWrap.style.display = 'none';
        if (DOM.cloudShareCreateWrap) DOM.cloudShareCreateWrap.style.display = 'flex';
        if (DOM.btnSubmitModalCloudShare) DOM.btnSubmitModalCloudShare.style.display = 'inline-block';
      } else {
        showToast('Thu hồi thất bại: ' + (res?.error || ''), 'error');
      }
    } catch (err) {
      showToast('Lỗi khi thu hồi: ' + err.message, 'error');
    }
  });

  // Picker search input
  DOM.inpCloudPickerSearch?.addEventListener('input', () => {
    loadPickerFiles();
  });

  // Picker Select All checkbox
  DOM.chkCloudPickerSelectAll?.addEventListener('change', (e) => {
    const isChecked = e.target.checked;
    DOM.cloudPickerTableBody?.querySelectorAll('.chk-picker-item').forEach((chk) => {
      chk.checked = isChecked;
    });
    if (isChecked) {
      state.cloud.picker.selectedFiles = [...state.cloud.files];
    } else {
      state.cloud.picker.selectedFiles = [];
    }
    updatePickerSelectionUI();
  });

  // Picker Submit Action (Download/Cache and return local paths)
  DOM.btnSubmitModalCloudPicker?.addEventListener('click', async () => {
    if (!state.cloud.picker.selectedFiles || state.cloud.picker.selectedFiles.length === 0) return;

    const selected = [...state.cloud.picker.selectedFiles];
    DOM.btnSubmitModalCloudPicker.disabled = true;
    if (DOM.cloudPickerStatusText) {
      DOM.cloudPickerStatusText.textContent = `Đang tải ${selected.length} tệp về bộ nhớ tạm máy tính...`;
    }

    const localPaths = [];
    try {
      for (let i = 0; i < selected.length; i++) {
        const file = selected[i];
        if (DOM.cloudPickerStatusText) {
          DOM.cloudPickerStatusText.textContent = `Đang tải (${i + 1}/${selected.length}): ${file.name}...`;
        }
        const res = await window.autoedit.cloud.cacheAndGetPath(file);
        if (res && res.ok && res.localPath) {
          localPaths.push(res.localPath);
        } else {
          console.warn('Failed to cache cloud file:', file.name, res?.error);
        }
      }

      hideModal(DOM.modalCloudPicker);
      if (state.cloud.picker.resolve) {
        state.cloud.picker.resolve(localPaths);
      }
    } catch (err) {
      showAlert('Lỗi khi nạp tệp từ Cloud: ' + err.message, 'Lỗi');
      hideModal(DOM.modalCloudPicker);
      if (state.cloud.picker.resolve) state.cloud.picker.resolve(localPaths);
    } finally {
      state.cloud.picker.active = false;
    }
  });

  // Wire Studio Buttons
  DOM.btnBrowseCloudImages?.addEventListener('click', async () => {
    const cachedPaths = await openCloudPicker({ mode: 'SELECT_IMAGES', title: 'Chọn Ảnh Từ Cloud' });
    if (cachedPaths && cachedPaths.length > 0) {
      let added = 0;
      cachedPaths.forEach((p) => {
        if (!state.mediaList.includes(p)) {
          state.mediaList.push(p);
          added++;
        }
      });
      renderMediaGrid();
      showToast(`Đã nạp ${added} ảnh từ Cloud vào Studio!`, 'success', 2500);
    }
  });

  DOM.btnBrowseCloudAudio?.addEventListener('click', async () => {
    const cachedPaths = await openCloudPicker({ mode: 'SELECT_AUDIO', title: 'Chọn Âm Thanh Từ Cloud' });
    if (cachedPaths && cachedPaths.length > 0) {
      setAudioPathUI(cachedPaths[0]);
      showToast(`Đã nạp âm thanh từ Cloud: ${cachedPaths[0].split(/[/\\]/).pop()}`, 'success', 2500);
    }
  });

  // Listen to Main Process Upload Progress
  if (window.autoedit?.cloud?.onUploadProgress) {
    window.autoedit.cloud.onUploadProgress((progressData) => {
      updateUploadDrawerItem(progressData);
    });
  }
}

// -----------------------------------------------------------------------------
// App Initialization
// -----------------------------------------------------------------------------
async function checkLicenseStatus() {
  try {
    await refreshAccountState();
  } catch (e) {
    if (DOM.licenseDot) DOM.licenseDot.className = 'status-dot dot-error';
    if (DOM.licenseText) DOM.licenseText.textContent = 'Offline';
  }
}

async function checkCapCutStatus() {
  try {
    const res = await window.autoedit.detectCapCut();
    if (res) {
      state.capcutInfo = res;
      const ver = res.detected_version || '9.3.0';
      DOM.capcutDot.className = 'status-dot dot-ready';
      DOM.capcutText.textContent = `CapCut v${ver}`;
      DOM.cfgCapcutVer.textContent = `Phiên bản v${ver} (Sẵn sàng)`;
      DOM.cfgCapcutPath.textContent = res.draft_root_path || 'Mặc định OS';
    }
  } catch (e) {
    DOM.capcutDot.className = 'status-dot dot-error';
    DOM.capcutText.textContent = 'Chưa tìm thấy CapCut';
  }
}

// -----------------------------------------------------------------------------
// Priority 6: Team & Workspace Manager (Renderer Logic)
// -----------------------------------------------------------------------------

function initTeamWorkspace() {
  if (!DOM.btnWorkspaceDropdown) return;

  // Toggle Dropdown
  DOM.btnWorkspaceDropdown.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleWorkspaceDropdown();
  });

  // Close Dropdown when clicking outside in main window (capture phase)
  window.addEventListener('pointerdown', (e) => {
    if (!DOM.btnWorkspaceDropdown?.contains(e.target)) {
      closeWorkspaceDropdown();
    }
  }, true);

  // Close Dropdown when native Flow view gains focus
  if (window.autoedit?.flow?.onViewFocused) {
    window.autoedit.flow.onViewFocused(() => {
      closeWorkspaceDropdown();
    });
  }

  // Handle delegated actions from Global Native Popover
  if (window.autoedit?.popover?.onAction) {
    window.autoedit.popover.onAction(async ({ action, payload }) => {
      if (action === 'SELECT_WORKSPACE' && payload?.workspaceId) {
        await switchWorkspace(payload.workspaceId);
      } else if (action === 'CREATE_TEAM') {
        openCreateTeamModal();
      } else if (action === 'MANAGE_TEAM') {
        openManageTeamModal();
      }
    });
  }

  // Open Modals
  DOM.btnOpenCreateTeamModal?.addEventListener('click', () => {
    closeWorkspaceDropdown();
    openCreateTeamModal();
  });

  DOM.btnOpenManageTeamModal?.addEventListener('click', () => {
    closeWorkspaceDropdown();
    openManageTeamModal();
  });

  // Close Modal 14
  [DOM.btnCloseModalTeamMembers, DOM.btnCloseModalTeam].forEach((b) => {
    b?.addEventListener('click', () => hideModal(DOM.modalTeamMembers));
  });

  // Close Modal 15
  [DOM.btnCloseModalCreateTeam, DOM.btnCancelModalCreateTeam].forEach((b) => {
    b?.addEventListener('click', () => {
      resetTeamCreationModal();
      hideModal(DOM.modalCreateTeam);
    });
  });

  // Modal 14: Tabs Switching
  const teamTabs = [
    { btn: DOM.tabBtnTeamMembers, pane: DOM.teamPaneMembers },
    { btn: DOM.tabBtnTeamSeats, pane: DOM.teamPaneSeats },
    { btn: DOM.tabBtnTeamInvite, pane: DOM.teamPaneInvite },
  ];

  teamTabs.forEach((tab) => {
    tab.btn?.addEventListener('click', () => {
      teamTabs.forEach((t) => {
        t.btn?.classList.remove('active');
        if (t.pane) t.pane.style.display = 'none';
      });
      tab.btn.classList.add('active');
      if (tab.pane) tab.pane.style.display = tab.pane === DOM.teamPaneInvite ? 'flex' : 'block';
    });
  });

  // Modal 14: Generate Invite Link
  DOM.btnGenerateTeamInvite?.addEventListener('click', handleGenerateTeamInvite);
  DOM.btnCopyTeamInviteUrl?.addEventListener('click', () => {
    const url = DOM.inpTeamInviteUrl?.value;
    if (url && navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => {
        showToast('Đã sao chép liên kết mời tham gia Team!', 'success');
      });
    }
  });

  // Modal 14: Activate current device seat
  DOM.btnActivateCurrentDeviceSeat?.addEventListener('click', handleActivateCurrentDeviceSeat);

  // Modal 14: Delete Team (OWNER only)
  DOM.btnDangerDeleteTeam?.addEventListener('click', handleDeleteTeam);

  // Modal 15: Create Team Commercial Flow Events
  DOM.btnNextToStep2?.addEventListener('click', handleTeamStep1Next);
  DOM.btnBackToStep1?.addEventListener('click', () => setTeamCreationStep(1));
  DOM.btnCancelHostedCheckout?.addEventListener('click', () => {
    resetTeamCreationModal();
    hideModal(DOM.modalCreateTeam);
  });
  DOM.btnOpenHostedCheckout?.addEventListener('click', () => {
    if (teamCreationState.checkoutUrl) {
      window.autoedit.team.openCheckoutUrl(teamCreationState.checkoutUrl);
    }
  });
  DOM.btnSwitchToCreatedTeam?.addEventListener('click', handleSwitchToCreatedTeam);
  DOM.inpCreateTeamName?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleTeamStep1Next();
    }
  });

  // Delegated click on team plans grid
  DOM.teamPlansGrid?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-select-team-plan');
    if (btn && !btn.disabled) {
      const planId = btn.dataset.planId;
      if (planId) handleSelectTeamPlan(planId, btn);
    }
  });

  // Modal 14: Refresh Team Invites
  DOM.btnRefreshTeamInvites?.addEventListener('click', () => {
    const ws = state.activeWorkspace;
    if (ws && ws.space_type === 'TEAM') {
      loadTeamInvitations(ws.team_id || ws.owner_id);
    }
  });

  // Modal: Token Top-up Events
  DOM.btnCloseModalTokenTopup?.addEventListener('click', () => {
    resetTokenTopupModal();
    hideModal(DOM.modalTokenTopup);
  });
  DOM.btnCancelTokenCheckout?.addEventListener('click', () => {
    resetTokenTopupModal();
  });
  DOM.btnCloseTokenTopupSuccess?.addEventListener('click', () => {
    resetTokenTopupModal();
    hideModal(DOM.modalTokenTopup);
  });
  DOM.btnOpenTokenCheckoutUrl?.addEventListener('click', () => {
    if (tokenTopupState.checkoutUrl) {
      window.autoedit.billing.openCheckoutUrl(tokenTopupState.checkoutUrl);
    }
  });
  DOM.tokenPackagesGrid?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-select-token-pkg');
    if (btn && !btn.disabled) {
      const pkgId = btn.dataset.pkgId;
      if (pkgId) handleSelectTokenPackage(pkgId, btn);
    }
  });

  // Auto-sync workspaces & wallet on Window Focus (Cross-device and browser sync)
  window.addEventListener('focus', () => {
    syncWorkspaceState();
    refreshWalletBalance();
  });


  // Initial Sync
  syncWorkspaceState();
}

function toggleWorkspaceDropdown() {
  if (window.autoedit?.popover?.toggle && DOM.btnWorkspaceDropdown) {
    const rect = DOM.btnWorkspaceDropdown.getBoundingClientRect();
    window.autoedit.popover.toggle({
      type: 'workspace',
      anchorRect: {
        x: Math.round(rect.left),
        y: Math.round(rect.top),
        width: Math.round(rect.width),
        height: Math.round(rect.height),
      },
      model: {
        activeWorkspace: state.activeWorkspace,
        workspaces: state.workspaces || [],
      },
    });
    return;
  }

  // Fallback for non-electron / mock environments
  if (!DOM.workspaceDropdownMenu) return;
  const isShown = DOM.workspaceDropdownMenu.style.display === 'block';
  if (isShown) {
    closeWorkspaceDropdown();
  } else {
    renderWorkspaceDropdownList();
    DOM.workspaceDropdownMenu.style.display = 'block';
  }
}

function closeWorkspaceDropdown() {
  if (window.autoedit?.popover?.close) {
    window.autoedit.popover.close();
  }
  if (DOM.workspaceDropdownMenu) {
    DOM.workspaceDropdownMenu.style.display = 'none';
  }
}

async function syncWorkspaceState() {
  if (!window.autoedit?.workspace?.sync) return;
  try {
    const res = await window.autoedit.workspace.sync();
    if (res && res.ok) {
      state.activeWorkspace = res.activeWorkspace;
      state.workspaces = res.spaces || [];
      updateWorkspaceHeaderUI();
    }
  } catch (e) {
    console.warn('Error syncing workspaces:', e);
  }
}

function updateWorkspaceHeaderUI() {
  const ws = state.activeWorkspace;
  if (!ws) return;

  if (DOM.wsActiveName) {
    DOM.wsActiveName.textContent = ws.name || 'Không gian cá nhân';
  }

  if (DOM.wsIcon) {
    DOM.wsIcon.innerHTML = `<i data-lucide="${ws.space_type === 'TEAM' ? 'users' : 'user-round'}" class="icon-xs"></i>`; refreshIcons(DOM.wsIcon);
  }

  if (DOM.wsRoleBadge) {
    const role = (ws.user_role || 'OWNER').toUpperCase();
    DOM.wsRoleBadge.textContent = role;
    DOM.wsRoleBadge.className = `ws-badge-role ws-role-${role.toLowerCase()}`;
  }

  if (DOM.btnOpenManageTeamModal) {
    const canManage = ws.space_type === 'TEAM' && ['OWNER', 'ADMIN'].includes(ws.user_role);
    DOM.btnOpenManageTeamModal.style.display = canManage ? 'flex' : 'none';
  }

  // Update Cloud Explorer view permissions if VIEWER
  enforceRoleUIPermissions(ws);
}

function enforceRoleUIPermissions(ws) {
  const isViewer = ws.user_role === 'VIEWER';

  // In Cloud tab: disable upload and new folder if VIEWER
  const btnUpload = document.getElementById('btnCloudUpload');
  const btnNewFolder = document.getElementById('btnCloudNewFolder');

  if (btnUpload) {
    btnUpload.style.opacity = isViewer ? '0.4' : '1';
    btnUpload.style.pointerEvents = isViewer ? 'none' : 'auto';
    btnUpload.title = isViewer ? 'Vai trò VIEWER không có quyền tải lên' : 'Tải lên tệp mới';
  }

  if (btnNewFolder) {
    btnNewFolder.style.opacity = isViewer ? '0.4' : '1';
    btnNewFolder.style.pointerEvents = isViewer ? 'none' : 'auto';
    btnNewFolder.title = isViewer ? 'Vai trò VIEWER không có quyền tạo thư mục' : 'Tạo thư mục mới';
  }
}

function renderWorkspaceDropdownList() {
  if (!DOM.workspaceList) return;
  DOM.workspaceList.innerHTML = '';

  const spaces = state.workspaces || [];
  spaces.forEach((sp) => {
    const isAct = state.activeWorkspace && state.activeWorkspace.id === sp.id;
    const isTeam = sp.space_type === 'TEAM';
    const role = (sp.user_role || (isTeam ? 'MEMBER' : 'OWNER')).toUpperCase();

    const item = document.createElement('div');
    item.className = `ws-dropdown-item ${isAct ? 'active' : ''}`;
    item.innerHTML = `
      <div style="display:flex; align-items:center; gap:8px;">
        <span><i data-lucide="${isTeam ? 'users' : 'user-round'}" class="icon-xs"></i></span>
        <span>${escapeHtml(sp.name)}</span>
      </div>
      <span class="ws-badge-role ws-role-${role.toLowerCase()}">${role}</span>
    `;

    item.addEventListener('click', async () => {
      closeWorkspaceDropdown();
      await switchWorkspace(sp.id);
    });

    DOM.workspaceList.appendChild(item);
  });
}

async function switchWorkspace(workspaceId) {
  if (!window.autoedit?.workspace?.switch) return;
  try {
    const res = await window.autoedit.workspace.switch(workspaceId);
    if (res && res.ok) {
      state.activeWorkspace = res.activeWorkspace;
      updateWorkspaceHeaderUI();

      // If Cloud Explorer is active or current space differs, reload files
      state.cloud.currentSpaceId = workspaceId;
      if (DOM.selCloudSpace) DOM.selCloudSpace.value = workspaceId;
      await Promise.all([loadCloudQuota(), loadCloudFiles(), refreshWalletBalance()]);

      showToast(`Đã chuyển sang không gian: ${state.activeWorkspace.name}`, 'success');
    }
  } catch (e) {
    showToast(`Không thể chuyển không gian: ${e.message}`, 'error');
  }
}

// ── Modal 15: Create Team Commercial Flow ──
let teamCreationState = {
  step: 1,
  teamName: '',
  plans: [],
  selectedPlan: null,
  checkoutId: null,
  checkoutUrl: null,
  pollTimer: null,
  createdWorkspace: null,
};

function resetTeamCreationModal() {
  if (teamCreationState.pollTimer) {
    clearInterval(teamCreationState.pollTimer);
    teamCreationState.pollTimer = null;
  }
  teamCreationState = {
    step: 1,
    teamName: '',
    plans: [],
    selectedPlan: null,
    checkoutId: null,
    checkoutUrl: null,
    pollTimer: null,
    createdWorkspace: null,
  };
  setTeamCreationStep(1);
  if (DOM.inpCreateTeamName) DOM.inpCreateTeamName.value = '';
  if (DOM.createTeamError) DOM.createTeamError.style.display = 'none';
}

function showTeamError(msg) {
  if (!DOM.createTeamError) return;
  let safeMsg = msg;
  if (typeof safeMsg === 'string' && (safeMsg.includes('SQLSTATE') || safeMsg.includes('Integrity constraint') || safeMsg.includes('Duplicate entry'))) {
    safeMsg = 'Hệ thống đang xử lý yêu cầu. Vui lòng thử lại trong giây lát.';
  }
  DOM.createTeamError.textContent = safeMsg || 'Đã có lỗi xảy ra';
  DOM.createTeamError.style.display = 'block';
}

function setTeamCreationStep(step) {
  teamCreationState.step = step;

  const pill1 = document.getElementById('stepPill1');
  const pill2 = document.getElementById('stepPill2');
  const pill3 = document.getElementById('stepPill3');
  const badge2 = document.getElementById('stepPill2Badge');
  const badge3 = document.getElementById('stepPill3Badge');

  if (pill1) pill1.style.color = (step >= 1) ? 'var(--brand)' : 'var(--text-muted)';
  if (pill2) {
    pill2.style.color = (step >= 2) ? 'var(--brand)' : 'var(--text-muted)';
    if (badge2) {
      badge2.style.background = (step >= 2) ? 'var(--brand)' : 'var(--surface-3)';
      badge2.style.color = (step >= 2) ? '#000' : 'var(--text-muted)';
    }
  }
  if (pill3) {
    pill3.style.color = (step >= 3) ? 'var(--brand)' : 'var(--text-muted)';
    if (badge3) {
      badge3.style.background = (step >= 3) ? 'var(--brand)' : 'var(--surface-3)';
      badge3.style.color = (step >= 3) ? '#000' : 'var(--text-muted)';
    }
  }

  const sec1 = document.getElementById('teamStep1Section');
  const sec2 = document.getElementById('teamStep2Section');
  const sec3 = document.getElementById('teamStep3Section');
  const sec4 = document.getElementById('teamStep4Section');
  const footer = document.getElementById('teamModalFooter');
  const btnNext = document.getElementById('btnNextToStep2');
  const btnBack = document.getElementById('btnBackToStep1');

  if (sec1) sec1.style.display = (step === 1) ? 'flex' : 'none';
  if (sec2) sec2.style.display = (step === 2) ? 'flex' : 'none';
  if (sec3) sec3.style.display = (step === 3) ? 'flex' : 'none';
  if (sec4) sec4.style.display = (step === 4) ? 'flex' : 'none';

  if (footer) footer.style.display = (step === 1 || step === 2) ? 'flex' : 'none';
  if (btnNext) btnNext.style.display = (step === 1) ? 'inline-block' : 'none';
  if (btnBack) btnBack.style.display = (step === 2) ? 'inline-block' : 'none';

  if (DOM.createTeamError) DOM.createTeamError.style.display = 'none';
}

function openCreateTeamModal() {
  resetTeamCreationModal();
  showModal(DOM.modalCreateTeam);
  setTimeout(() => DOM.inpCreateTeamName?.focus(), 50);
}

async function handleTeamStep1Next() {
  const name = DOM.inpCreateTeamName?.value.trim();
  if (!name) {
    showTeamError('Vui lòng nhập tên Đội Nhóm');
    return;
  }
  if (name.length > 128) {
    showTeamError('Tên Đội Nhóm tối đa 128 ký tự');
    return;
  }

  teamCreationState.teamName = name;
  const lbl = document.getElementById('lblSelectedTeamName');
  if (lbl) lbl.textContent = name;

  setTeamCreationStep(2);
  await loadAndRenderTeamPlans();
}

async function loadAndRenderTeamPlans() {
  const container = document.getElementById('teamPlansGrid');
  if (!container) return;

  container.innerHTML = `
    <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
      <i data-lucide="loader-circle" class="icon-md spin" style="animation: spin 1s linear infinite; margin-bottom: 8px;"></i>
      <div>Đang tải bảng giá từ máy chủ...</div>
    </div>
  `;
  refreshIcons(container);

  try {
    const res = await window.autoedit.team.getPlans();
    if (!res || !res.ok || !Array.isArray(res.plans) || res.plans.length === 0) {
      container.innerHTML = `
        <div style="grid-column: 1 / -1; text-align: center; padding: 24px; color: #ef4444; font-size: 13px;">
          Không thể tải danh sách gói từ máy chủ. Vui lòng thử lại sau.
        </div>
      `;
      return;
    }

    teamCreationState.plans = res.plans;
    container.innerHTML = res.plans.map(p => {
      const formattedPrice = new Intl.NumberFormat('vi-VN').format(p.price) + 'đ';
      const periodLabel = p.billing_period === 'MONTHLY' ? '/ tháng' : '';
      const featuresHtml = (p.features || []).map(f => `
        <div style="display:flex; align-items:flex-start; gap:6px; font-size:12px; color:var(--text-dim); line-height:1.4;">
          <i data-lucide="check" class="icon-xs" style="color:var(--brand); flex-shrink:0; margin-top:2px;"></i>
          <span>${escapeHtml(f)}</span>
        </div>
      `).join('');

      return `
        <div class="team-plan-card" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between;">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <strong style="font-size: 15px; color: var(--text-main);">${escapeHtml(p.name)}</strong>
              ${p.id === 'team_starter' ? '<span style="font-size: 10px; background: rgba(255,122,0,0.15); color: var(--brand); padding: 2px 6px; border-radius: 4px; font-weight: 600;">PHỔ BIẾN</span>' : ''}
            </div>
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px; min-height: 28px;">${escapeHtml(p.description || '')}</div>
            
            <div style="margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--border);">
              <span style="font-size: 20px; font-weight: 700; color: var(--text-main);">${formattedPrice}</span>
              <span style="font-size: 12px; color: var(--text-muted);">${periodLabel}</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
              ${featuresHtml}
            </div>
          </div>

          <button type="button" class="btn-action-primary btn-select-team-plan" data-plan-id="${escapeHtml(p.id)}" style="width: 100%; justify-content: center; font-size: 13px; font-weight: 600;">
            Chọn gói này
          </button>
        </div>
      `;
    }).join('');

    refreshIcons(container);
  } catch (err) {
    container.innerHTML = `
      <div style="grid-column: 1 / -1; text-align: center; padding: 24px; color: #ef4444; font-size: 13px;">
        Lỗi kết nối máy chủ: ${escapeHtml(err.message)}
      </div>
    `;
  }
}

async function handleSelectTeamPlan(planId, btn) {
  const plan = teamCreationState.plans.find(p => p.id === planId);
  if (!plan) return;

  teamCreationState.selectedPlan = plan;
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-circle" class="icon-xs spin" style="animation: spin 1s linear infinite;"></i> Đang khởi tạo...`;
    refreshIcons(btn);
  }

  try {
    const res = await window.autoedit.team.createCheckout(teamCreationState.teamName, plan.id);
    if (!res || !res.ok) {
      showTeamError(res?.error || res?.message || 'Không thể khởi tạo phiên thanh toán');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Chọn gói này';
      }
      return;
    }

    if (res.payment_status === 'COMPLETED') {
      onTeamCreationSuccess(res.team || { name: teamCreationState.teamName }, plan);
      return;
    }

    teamCreationState.checkoutId = res.checkout_id;
    teamCreationState.checkoutUrl = res.checkout_url;

    const summaryTeam = document.getElementById('checkoutSummaryTeamName');
    const summaryPlan = document.getElementById('checkoutSummaryPlanName');
    const summaryAmt = document.getElementById('checkoutSummaryAmount');

    if (summaryTeam) summaryTeam.textContent = teamCreationState.teamName;
    if (summaryPlan) summaryPlan.textContent = plan.name;
    if (summaryAmt) summaryAmt.textContent = new Intl.NumberFormat('vi-VN').format(plan.price) + 'đ';

    setTeamCreationStep(3);

    if (res.checkout_url) {
      window.autoedit.team.openCheckoutUrl(res.checkout_url);
    }

    startCheckoutStatusPolling(res.checkout_id, plan);

  } catch (err) {
    showTeamError(err.message || 'Lỗi khi khởi tạo đơn hàng');
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Chọn gói này';
    }
  }
}

function startCheckoutStatusPolling(checkoutId, plan) {
  if (teamCreationState.pollTimer) {
    clearInterval(teamCreationState.pollTimer);
  }

  let attempts = 0;
  const maxAttempts = 120; // 5 minutes max

  teamCreationState.pollTimer = setInterval(async () => {
    attempts++;
    if (attempts > maxAttempts) {
      clearInterval(teamCreationState.pollTimer);
      teamCreationState.pollTimer = null;
      showTeamError('Phiên thanh toán đã hết thời gian chờ. Bạn có thể thử lại.');
      return;
    }

    try {
      const res = await window.autoedit.team.getCheckoutStatus(checkoutId);
      if (res && res.ok) {
        if (res.payment_status === 'COMPLETED') {
          clearInterval(teamCreationState.pollTimer);
          teamCreationState.pollTimer = null;
          onTeamCreationSuccess(res.team, plan);
        } else if (res.payment_status === 'FAILED' || res.payment_status === 'CANCELLED') {
          clearInterval(teamCreationState.pollTimer);
          teamCreationState.pollTimer = null;
          showTeamError('Giao dịch đã bị hủy hoặc thanh toán không thành công.');
        }
      }
    } catch (e) {
      // transient network failure during polling; keep polling
    }
  }, 2500);
}

async function onTeamCreationSuccess(team, plan) {
  teamCreationState.createdWorkspace = team;
  setTeamCreationStep(4);

  const titleTeam = document.getElementById('successTeamTitle');
  const titlePlan = document.getElementById('successPlanTitle');
  if (titleTeam) titleTeam.textContent = team.name || teamCreationState.teamName;
  if (titlePlan) titlePlan.textContent = plan?.name || 'Team Workspace';

  await syncWorkspaceState();
  await refreshWalletBalance();

  showToast(`Không gian Team "${team.name || teamCreationState.teamName}" đã sẵn sàng!`, 'success');
}

async function handleSwitchToCreatedTeam() {
  const ws = teamCreationState.createdWorkspace;
  hideModal(DOM.modalCreateTeam);
  resetTeamCreationModal();

  if (ws && ws.workspace_id) {
    await switchWorkspace(ws.workspace_id);
  } else {
    await syncWorkspaceState();
  }
}

// ── Modal 14: Manage Team Members & Seats ──
async function openManageTeamModal() {
  const ws = state.activeWorkspace;
  if (!ws || ws.space_type !== 'TEAM') return;

  const teamId = ws.team_id || ws.owner_id;
  if (!teamId) return;

  if (DOM.teamModalTitle) { DOM.teamModalTitle.innerHTML = `<i data-lucide="users" class="icon-sm" style="color:var(--brand);margin-right:6px;"></i>Quản Lý Team: ${escapeHtml(ws.name)}`; refreshIcons(DOM.teamModalTitle); }
  if (DOM.teamUserRoleBadge) {
    const r = (ws.user_role || 'OWNER').toUpperCase();
    DOM.teamUserRoleBadge.textContent = r;
    DOM.teamUserRoleBadge.className = `ws-badge-role ws-role-${r.toLowerCase()}`;
  }

  if (DOM.teamModalError) DOM.teamModalError.style.display = 'none';
  if (DOM.teamInviteResultWrap) DOM.teamInviteResultWrap.style.display = 'none';

  // Danger delete team button (OWNER only)
  if (DOM.btnDangerDeleteTeam) {
    DOM.btnDangerDeleteTeam.style.display = ws.user_role === 'OWNER' ? 'block' : 'none';
  }

  // Show modal and load data
  showModal(DOM.modalTeamMembers);
  await loadTeamDetails(teamId);
}

async function loadTeamDetails(teamId) {
  try {
    // 1. Fetch team info & members
    const [teamRes, membersRes, seatsRes] = await Promise.all([
      window.autoedit.team.get(teamId),
      window.autoedit.team.listMembers(teamId),
      window.autoedit.team.listSeats(teamId),
    ]);

    if (teamRes && teamRes.ok && teamRes.team) {
      if (DOM.teamMemberSlots) DOM.teamMemberSlots.textContent = teamRes.team.member_slots || 2;
      if (DOM.teamAppKeyCount) DOM.teamAppKeyCount.textContent = teamRes.team.app_key_count || 2;
    }

    if (membersRes && membersRes.ok && Array.isArray(membersRes.members)) {
      if (DOM.teamMemberCount) DOM.teamMemberCount.textContent = membersRes.members.length;
      renderTeamMembersTable(teamId, membersRes.members);
    }

    if (seatsRes && seatsRes.ok) {
      if (DOM.teamSeatCount) DOM.teamSeatCount.textContent = seatsRes.active_seats || 0;
      renderTeamSeatsTable(teamId, seatsRes.seats || []);
    }

    // 4. Fetch invitations
    await loadTeamInvitations(teamId);
  } catch (e) {
    console.error('Error loading team details:', e);
  }
}

function renderTeamMembersTable(teamId, members) {
  if (!DOM.teamMembersTableBody) return;
  DOM.teamMembersTableBody.innerHTML = '';

  const callerRole = (state.activeWorkspace?.user_role || 'MEMBER').toUpperCase();
  const isOwner = callerRole === 'OWNER';
  const isAdmin = callerRole === 'ADMIN';

  members.forEach((m) => {
    const tr = document.createElement('tr');
    const isTargetOwner = m.role === 'OWNER';
    const canChange = isOwner ? !isTargetOwner : (isAdmin && ['EDITOR', 'VIEWER'].includes(m.role));
    const canRemove = isOwner ? !isTargetOwner : (isAdmin && ['EDITOR', 'VIEWER'].includes(m.role));

    const roleOptions = isOwner
      ? `
        <option value="ADMIN" ${m.role === 'ADMIN' ? 'selected' : ''}>ADMIN</option>
        <option value="EDITOR" ${m.role === 'EDITOR' ? 'selected' : ''}>EDITOR</option>
        <option value="VIEWER" ${m.role === 'VIEWER' ? 'selected' : ''}>VIEWER</option>
      `
      : `
        <option value="EDITOR" ${m.role === 'EDITOR' ? 'selected' : ''}>EDITOR</option>
        <option value="VIEWER" ${m.role === 'VIEWER' ? 'selected' : ''}>VIEWER</option>
      `;

    tr.innerHTML = `
      <td>
        <div style="font-weight:600; color:var(--text-main);">${escapeHtml(m.fullname || m.username || m.user_id)}</div>
        <div style="font-size:11px; color:var(--text-dim);">${escapeHtml(m.email || '')}</div>
      </td>
      <td>
        ${isTargetOwner
          ? `<span class="ws-badge-role ws-role-owner">OWNER</span>`
          : canChange
          ? `<select class="role-select" data-user-id="${m.user_id}">
              ${roleOptions}
             </select>`
          : `<span class="ws-badge-role ws-role-${(m.role || '').toLowerCase()}">${m.role}</span>`
        }
      </td>
      <td style="color:var(--text-muted); font-size:11.5px;">${formatDate(m.joined_at)}</td>
      <td style="text-align:right;">
        ${canRemove
          ? `<button type="button" class="btn-cloud-mini btn-danger-mini btn-remove-member" data-user-id="${m.user_id}">
              Xóa
             </button>`
          : '-'
        }
      </td>
    `;

    // Role Change Listener
    const selRole = tr.querySelector('.role-select');
    if (selRole) {
      selRole.addEventListener('change', async (e) => {
        const newRole = e.target.value;
        try {
          const res = await window.autoedit.team.changeRole(teamId, m.user_id, newRole);
          if (res && res.ok) {
            showToast(`Đã thay đổi vai trò thành ${newRole}`, 'success');
            await loadTeamDetails(teamId);
          } else {
            showToast(res?.error || 'Không thể đổi vai trò', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Lỗi khi đổi vai trò', 'error');
        }
      });
    }

    // Remove Member Listener
    const btnRemove = tr.querySelector('.btn-remove-member');
    if (btnRemove) {
      btnRemove.addEventListener('click', async () => {
        if (!confirm(`Bạn có chắc chắn muốn xóa thành viên "${m.fullname || m.username}" khỏi Team?`)) return;
        try {
          const res = await window.autoedit.team.removeMember(teamId, m.user_id);
          if (res && res.ok) {
            showToast('Đã xóa thành viên khỏi Team', 'success');
            await loadTeamDetails(teamId);
          } else {
            showToast(res?.error || 'Không thể xóa thành viên', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Lỗi khi xóa thành viên', 'error');
        }
      });
    }

    DOM.teamMembersTableBody.appendChild(tr);
  });
}

function renderTeamSeatsTable(teamId, seats) {
  if (!DOM.teamSeatsTableBody) return;
  DOM.teamSeatsTableBody.innerHTML = '';

  if (seats.length === 0) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="4" style="text-align:center; color:var(--text-muted); padding:16px;">Chưa có thiết bị nào kích hoạt ghế bản quyền.</td>`;
    DOM.teamSeatsTableBody.appendChild(tr);
    return;
  }

  const callerRole = (state.activeWorkspace?.user_role || 'MEMBER').toUpperCase();
  const canRevoke = ['OWNER', 'ADMIN'].includes(callerRole);

  seats.forEach((s) => {
    const tr = document.createElement('tr');
    const isActive = s.status === 'ACTIVE';

    tr.innerHTML = `
      <td>
        <div style="font-weight:600;">${escapeHtml(s.fullname || s.username || s.user_id)}</div>
      </td>
      <td>
        <div>${escapeHtml(s.device_alias || 'Desktop Máy Trạm')}</div>
        <div style="font-size:11px; font-family:monospace; color:var(--text-dim);">${escapeHtml(s.device_fingerprint || '')}</div>
      </td>
      <td>
        <span class="badge" style="background:${isActive ? '#064e3b' : '#451a1a'}; color:${isActive ? '#34d399' : '#f87171'}; font-size:11px; padding:2px 6px; border-radius:4px;">
          ${isActive ? '● Đang kích hoạt' : 'Đã thu hồi'}
        </span>
      </td>
      <td style="text-align:right;">
        ${isActive && canRevoke
          ? `<button type="button" class="btn-cloud-mini btn-danger-mini btn-revoke-seat" data-seat-id="${s.id}">
              Thu hồi
             </button>`
          : '-'
        }
      </td>
    `;

    const btnRevoke = tr.querySelector('.btn-revoke-seat');
    if (btnRevoke) {
      btnRevoke.addEventListener('click', async () => {
        if (!confirm('Bạn có chắc chắn muốn thu hồi ghế bản quyền trên thiết bị này?')) return;
        try {
          const res = await window.autoedit.team.revokeSeat(teamId, s.id);
          if (res && res.ok) {
            showToast('Đã thu hồi ghế bản quyền thành công', 'success');
            await loadTeamDetails(teamId);
          } else {
            showToast(res?.error || 'Không thể thu hồi ghế', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Lỗi khi thu hồi ghế', 'error');
        }
      });
    }

    DOM.teamSeatsTableBody.appendChild(tr);
  });
}

async function loadTeamInvitations(teamId) {
  if (!DOM.teamInvitesTableBody) return;
  DOM.teamInvitesTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:10px;">Đang tải danh sách lời mời...</td></tr>';

  try {
    const res = await window.autoedit.team.listInvitations(teamId);
    if (!res || !res.ok || !Array.isArray(res.invitations) || res.invitations.length === 0) {
      DOM.teamInvitesTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:12px;">Chưa có liên kết mời nào được tạo cho Team này.</td></tr>';
      return;
    }

    const callerRole = (state.activeWorkspace?.user_role || 'MEMBER').toUpperCase();
    const canRevoke = ['OWNER', 'ADMIN'].includes(callerRole);

    DOM.teamInvitesTableBody.innerHTML = '';
    res.invitations.forEach((inv) => {
      const tr = document.createElement('tr');
      const isActive = inv.status === 'ACTIVE';
      const statusLabel = isActive ? '● Đang hoạt động' : (inv.status === 'USED' ? 'Đã tham gia' : (inv.status === 'REVOKED' ? 'Đã thu hồi' : 'Đã hết hạn'));
      const statusBg = isActive ? '#064e3b' : (inv.status === 'USED' ? 'rgba(56,189,248,0.15)' : '#451a1a');
      const statusColor = isActive ? '#34d399' : (inv.status === 'USED' ? '#38bdf8' : '#f87171');

      tr.innerHTML = `
        <td><span class="ws-badge-role ws-role-${(inv.offered_role || '').toLowerCase()}">${escapeHtml(inv.offered_role || 'EDITOR')}</span></td>
        <td style="color:var(--text-muted); font-size:11px;">${formatDate(inv.expires_at)}</td>
        <td style="color:var(--text-dim); font-size:11px;">${escapeHtml(inv.recipient_email || 'Bất kỳ ai có link')}</td>
        <td>
          <span class="badge" style="background:${statusBg}; color:${statusColor}; font-size:10.5px; padding:2px 6px; border-radius:4px;">
            ${statusLabel}
          </span>
        </td>
        <td style="text-align:right;">
          ${isActive && canRevoke
            ? `<button type="button" class="btn-cloud-mini btn-danger-mini btn-revoke-inv" data-inv-id="${inv.id}">
                Thu hồi
               </button>`
            : '-'
          }
        </td>
      `;

      const btnRev = tr.querySelector('.btn-revoke-inv');
      if (btnRev) {
        btnRev.addEventListener('click', async () => {
          if (!confirm('Bạn có chắc chắn muốn thu hồi liên kết mời này?')) return;
          try {
            const revRes = await window.autoedit.team.revokeInvitation(teamId, inv.id);
            if (revRes && revRes.ok) {
              showToast('Đã thu hồi liên kết mời thành công', 'success');
              await loadTeamInvitations(teamId);
            } else {
              showToast(revRes?.error || 'Không thể thu hồi lời mời', 'error');
            }
          } catch (e) {
            showToast(e.message || 'Lỗi khi thu hồi lời mời', 'error');
          }
        });
      }

      DOM.teamInvitesTableBody.appendChild(tr);
    });
  } catch (err) {
    DOM.teamInvitesTableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#ef4444; padding:10px;">Lỗi tải lời mời: ${escapeHtml(err.message)}</td></tr>`;
  }
}

// ── Token Top-up Commercial Flow Controller ──
let tokenTopupState = {
  packages: [],
  selectedPackage: null,
  checkoutId: null,
  checkoutUrl: null,
  pollTimer: null,
  targetType: 'PERSONAL',
  teamId: null,
};

function openTokenTopupModal() {
  resetTokenTopupModal();
  showModal(DOM.modalTokenTopup);

  // Check current workspace and active user
  const ws = state.activeWorkspace;
  const isTeam = ws && ws.space_type === 'TEAM';
  const canTeamTopup = isTeam && ['OWNER', 'ADMIN', 'EDITOR'].includes(ws.user_role);

  // Personal balance
  const personalBal = state.user?.wallet_balance ?? (document.getElementById('accTokenCount')?.textContent || '--');
  if (DOM.lblTopupPersonalBal) DOM.lblTopupPersonalBal.textContent = personalBal;

  if (canTeamTopup && DOM.lblTopupTeamOption) {
    DOM.lblTopupTeamOption.style.display = 'flex';
    if (DOM.lblTopupTeamName) DOM.lblTopupTeamName.textContent = ws.name || 'Team';
    const teamBal = ws.wallet?.balance ?? '--';
    if (DOM.lblTopupTeamBal) DOM.lblTopupTeamBal.textContent = teamBal;
    tokenTopupState.teamId = ws.team_id || ws.owner_id;
  } else if (DOM.lblTopupTeamOption) {
    DOM.lblTopupTeamOption.style.display = 'none';
    if (DOM.radioTopupPersonal) DOM.radioTopupPersonal.checked = true;
    tokenTopupState.targetType = 'PERSONAL';
    tokenTopupState.teamId = null;
  }

  loadAndRenderTokenPackages();
}

function resetTokenTopupModal() {
  if (tokenTopupState.pollTimer) {
    clearInterval(tokenTopupState.pollTimer);
    tokenTopupState.pollTimer = null;
  }
  tokenTopupState.checkoutId = null;
  tokenTopupState.checkoutUrl = null;
  tokenTopupState.selectedPackage = null;
  tokenTopupState.targetType = DOM.radioTopupTeam?.checked ? 'TEAM' : 'PERSONAL';

  if (DOM.tokenPackagesSection) DOM.tokenPackagesSection.style.display = 'block';
  if (DOM.tokenCheckoutSection) DOM.tokenCheckoutSection.style.display = 'none';
  if (DOM.tokenTopupSuccessSection) DOM.tokenTopupSuccessSection.style.display = 'none';
  if (DOM.tokenTopupError) DOM.tokenTopupError.style.display = 'none';
}

async function loadAndRenderTokenPackages() {
  if (!DOM.tokenPackagesGrid) return;
  DOM.tokenPackagesGrid.innerHTML = `
    <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
      <i data-lucide="loader-circle" class="icon-md spin" style="animation: spin 1s linear infinite; margin-bottom: 8px;"></i>
      <div>Đang tải bảng giá token từ máy chủ...</div>
    </div>
  `;
  refreshIcons(DOM.tokenPackagesGrid);

  try {
    const res = await window.autoedit.billing.getTokenPackages();
    if (!res || !res.ok || !Array.isArray(res.packages) || res.packages.length === 0) {
      DOM.tokenPackagesGrid.innerHTML = `
        <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #ef4444; font-size: 13px;">
          Không thể tải danh sách gói token. Vui lòng thử lại sau.
        </div>
      `;
      return;
    }

    tokenTopupState.packages = res.packages;
    DOM.tokenPackagesGrid.innerHTML = res.packages.map(p => {
      const isUnlimited = p.is_unlimited;
      const tokensLabel = isUnlimited ? 'Không Giới Hạn' : new Intl.NumberFormat('vi-VN').format(p.tokens) + ' Tokens';
      const bonusHtml = (!isUnlimited && p.bonus_tokens > 0)
        ? `+${new Intl.NumberFormat('vi-VN').format(p.bonus_tokens)} tặng thêm`
        : '';
      const badgeHtml = p.badge
        ? `<span class="token-pkg-badge">${escapeHtml(p.badge)}</span>`
        : '';

      return `
        <div class="token-pkg-card" data-pkg-id="${escapeHtml(p.id)}">
          <div class="token-pkg-content">
            <div class="token-pkg-header">
              <div class="token-pkg-title">
                ${escapeHtml(p.name)}
              </div>
              ${badgeHtml}
            </div>
            <div class="token-pkg-tokens">
              ${tokensLabel}
            </div>
            <div class="token-pkg-bonus">
              ${bonusHtml}
            </div>
            <div class="token-pkg-price">
              ${p.formatted_price}
            </div>
            <div class="token-pkg-desc">
              ${escapeHtml(p.description || '')}
            </div>
          </div>

          <button type="button" class="btn-action-primary btn-select-token-pkg" data-pkg-id="${escapeHtml(p.id)}">
            Nạp Gói Này
          </button>
        </div>
      `;
    }).join('');

    refreshIcons(DOM.tokenPackagesGrid);
  } catch (err) {
    DOM.tokenPackagesGrid.innerHTML = `
      <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #ef4444; font-size: 13px;">
        Lỗi kết nối máy chủ: ${escapeHtml(err.message)}
      </div>
    `;
  }
}

async function handleSelectTokenPackage(pkgId, btn) {
  const pkg = tokenTopupState.packages.find(p => p.id === pkgId);
  if (!pkg) return;

  tokenTopupState.selectedPackage = pkg;
  tokenTopupState.targetType = DOM.radioTopupTeam?.checked ? 'TEAM' : 'PERSONAL';

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-circle" class="icon-xs spin" style="animation: spin 1s linear infinite;"></i> Đang tạo...`;
    refreshIcons(btn);
  }

  try {
    const res = await window.autoedit.billing.createTokenCheckout(
      pkg.id,
      tokenTopupState.targetType,
      tokenTopupState.targetType === 'TEAM' ? tokenTopupState.teamId : ''
    );

    if (!res || !res.ok) {
      if (DOM.tokenTopupError) {
        DOM.tokenTopupError.textContent = res?.error || res?.message || 'Không thể tạo phiên thanh toán';
        DOM.tokenTopupError.style.display = 'block';
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Nạp Gói Này';
      }
      return;
    }

    tokenTopupState.checkoutId = res.checkout_id;
    tokenTopupState.checkoutUrl = res.checkout_url;

    // Switch to Checkout View
    if (DOM.tokenPackagesSection) DOM.tokenPackagesSection.style.display = 'none';
    if (DOM.tokenCheckoutSection) DOM.tokenCheckoutSection.style.display = 'flex';

    if (DOM.tokenCheckoutPkgTitle) {
      const destLabel = tokenTopupState.targetType === 'TEAM' ? ' (Ví Đội Nhóm)' : ' (Ví Cá Nhân)';
      DOM.tokenCheckoutPkgTitle.textContent = pkg.name + destLabel;
    }
    if (DOM.tokenCheckoutAmount) DOM.tokenCheckoutAmount.textContent = pkg.formatted_price;

    // Open checkout URL in browser
    if (res.checkout_url) {
      window.autoedit.billing.openCheckoutUrl(res.checkout_url);
    }

    // Start Polling Payment Status
    startTokenPaymentPolling(res.checkout_id, pkg);
  } catch (err) {
    if (DOM.tokenTopupError) {
      DOM.tokenTopupError.textContent = err.message || 'Lỗi khi khởi tạo thanh toán';
      DOM.tokenTopupError.style.display = 'block';
    }
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Nạp Gói Này';
    }
  }
}

function startTokenPaymentPolling(checkoutId, pkg) {
  if (tokenTopupState.pollTimer) clearInterval(tokenTopupState.pollTimer);

  tokenTopupState.pollTimer = setInterval(async () => {
    try {
      const res = await window.autoedit.billing.getTokenCheckoutStatus(checkoutId);
      if (res && res.ok && res.payment_status === 'COMPLETED') {
        clearInterval(tokenTopupState.pollTimer);
        tokenTopupState.pollTimer = null;
        onTokenTopupCompleted(pkg, res.wallet);
      }
    } catch (e) {
      console.warn('Error polling token checkout status:', e);
    }
  }, 2000);
}

function onTokenTopupCompleted(pkg, wallet) {
  if (DOM.tokenCheckoutSection) DOM.tokenCheckoutSection.style.display = 'none';
  if (DOM.tokenTopupSuccessSection) DOM.tokenTopupSuccessSection.style.display = 'block';

  if (DOM.tokenTopupSuccessMsg) {
    const isUnlim = pkg.is_unlimited;
    const addedText = isUnlim ? 'Gói Trọn Đời Studio (Unlimited)' : `+${new Intl.NumberFormat('vi-VN').format(pkg.total_tokens || pkg.tokens)} tokens`;
    DOM.tokenTopupSuccessMsg.textContent = `Đã thanh toán thành công đơn hàng! Số dư mới: ${wallet?.balance ? new Intl.NumberFormat('vi-VN').format(wallet.balance) : '--'} tokens (${addedText}).`;
  }

  showToast(`Nạp token thành công cho ${pkg.name}!`, 'success');
  refreshWalletBalance();
  syncWorkspaceState();
}

async function handleGenerateTeamInvite() {
  const ws = state.activeWorkspace;
  if (!ws || ws.space_type !== 'TEAM') return;

  const teamId = ws.team_id || ws.owner_id;
  const role = DOM.selTeamInviteRole?.value || 'EDITOR';
  const email = DOM.inpTeamInviteEmail?.value.trim() || '';

  if (DOM.btnGenerateTeamInvite) DOM.btnGenerateTeamInvite.disabled = true;

  try {
    const res = await window.autoedit.team.createInvitation(teamId, role, email);
    if (res && res.ok && res.invite_url) {
      if (DOM.inpTeamInviteUrl) DOM.inpTeamInviteUrl.value = res.invite_url;
      if (DOM.teamInviteResultWrap) DOM.teamInviteResultWrap.style.display = 'flex';
      showToast('Đã tạo liên kết mời tham gia Team!', 'success');
      await loadTeamInvitations(teamId);
    } else {
      showToast(res?.error || 'Không thể tạo lời mời', 'error');
    }
  } catch (e) {
    showToast(e.message || 'Lỗi khi tạo lời mời', 'error');
  } finally {
    if (DOM.btnGenerateTeamInvite) DOM.btnGenerateTeamInvite.disabled = false;
  }
}

async function handleActivateCurrentDeviceSeat() {
  const ws = state.activeWorkspace;
  if (!ws || ws.space_type !== 'TEAM') return;

  const teamId = ws.team_id || ws.owner_id;
  if (DOM.btnActivateCurrentDeviceSeat) DOM.btnActivateCurrentDeviceSeat.disabled = true;

  try {
    const res = await window.autoedit.team.activateSeat(teamId, 'Thiết Bị Cục Bộ', navigator.platform || 'mac-arm64');
    if (res && res.ok) {
      showToast('Đã kích hoạt ghế bản quyền Team cho máy này thành công!', 'success');
      await loadTeamDetails(teamId);
      await checkLicenseStatus();
    } else {
      showToast(res?.error || 'Không thể kích hoạt ghế bản quyền', 'error');
    }
  } catch (e) {
    showToast(e.message || 'Lỗi kích hoạt ghế bản quyền', 'error');
  } finally {
    if (DOM.btnActivateCurrentDeviceSeat) DOM.btnActivateCurrentDeviceSeat.disabled = false;
  }
}

async function handleDeleteTeam() {
  const ws = state.activeWorkspace;
  if (!ws || ws.space_type !== 'TEAM' || ws.user_role !== 'OWNER') return;

  const teamId = ws.team_id || ws.owner_id;
  if (!confirm(`CẢNH BÁO NGUY HIỂM: Bạn có chắc chắn muốn giải tán Team "${ws.name}"?\nToàn bộ ghế bản quyền và hồ lưu trữ sẽ bị đóng vĩnh viễn.`)) {
    return;
  }

  try {
    const res = await window.autoedit.team.delete(teamId);
    if (res && res.ok) {
      hideModal(DOM.modalTeamMembers);
      showToast('Đã giải tán Team thành công.', 'success');
      await syncWorkspaceState();
      // Switch back to Personal workspace
      if (state.workspaces && state.workspaces.length > 0) {
        await switchWorkspace(state.workspaces[0].id);
      }
    } else {
      showToast(res?.error || 'Không thể giải tán Team', 'error');
    }
  } catch (e) {
    showToast(e.message || 'Lỗi khi giải tán Team', 'error');
  }
}

// =============================================================================
// Scoped AI Access Keys (Phase 1)
// =============================================================================
async function loadAiKeys() {
  if (!DOM.aiKeysListContainer) return;
  if (!window.autoedit?.aiKeys?.list) {
    DOM.aiKeysListContainer.innerHTML = '<div style="color:var(--text-dim); font-size:12px;">Tính năng AI Keys không khả dụng.</div>';
    return;
  }

  DOM.aiKeysListContainer.innerHTML = '<div style="color:var(--text-dim); font-size:12px; font-style:italic;">Đang tải danh sách khóa...</div>';

  try {
    const res = await window.autoedit.aiKeys.list();
    if (!res || !res.ok) {
      DOM.aiKeysListContainer.innerHTML = `<div style="color:var(--text-dim); font-size:12px;">Không thể tải danh sách khóa (${res?.error || 'Đăng nhập để xem'}).</div>`;
      return;
    }

    const keys = res.keys || [];
    if (keys.length === 0) {
      DOM.aiKeysListContainer.innerHTML = `
        <div style="background:rgba(15,23,42,0.4); border:1px dashed var(--border); border-radius:6px; padding:12px; text-align:center; color:var(--text-dim); font-size:12px;">
          Chưa có AI Access Key nào được tạo. Nhấn nút <strong>Tạo Khóa AI Mới</strong> bên dưới để cấp quyền cho Cursor Agent, Codex hoặc 2TOOLNE CLI.
        </div>
      `;
      return;
    }

    let html = '';
    keys.forEach((k) => {
      const isRevoked = !k.is_active;
      const statusBadge = isRevoked
        ? '<span class="badge-revoked" style="color:var(--danger,#ef4444); background:rgba(239,68,68,0.15); padding:2px 6px; border-radius:4px; font-size:11px;">ĐÃ HỦY</span>'
        : '<span class="badge-active" style="color:var(--success,#22c55e); background:rgba(34,197,94,0.15); padding:2px 6px; border-radius:4px; font-size:11px;">HOẠT ĐỘNG</span>';

      html += `
        <div class="ai-key-item" data-key-id="${escapeHtml(k.id)}" data-key-active="${!isRevoked}" style="display:flex; justify-content:space-between; align-items:center; background:var(--bg-card,#0b1120); border:1px solid var(--border); border-radius:6px; padding:10px 12px; gap:12px;">
          <div style="display:flex; flex-direction:column; gap:3px; flex:1;">
            <div style="display:flex; align-items:center; gap:8px;">
              <strong class="ai-key-title" style="font-size:13px; color:var(--text-main);">${escapeHtml(k.display_name || 'AI Key')}</strong>
              ${statusBadge}
              <span class="ai-key-prefix" style="font-size:11px; color:var(--text-dim); font-family:monospace; background:rgba(255,255,255,0.05); padding:1px 5px; border-radius:3px;">${escapeHtml(k.masked_key || k.key_prefix || '')}</span>
            </div>
            <div style="font-size:11.5px; color:var(--text-dim);">
              Workspace: <strong>${escapeHtml(k.workspace_name || k.workspace_type || 'Personal Space')}</strong> •
              Scopes: <span style="color:var(--text-main); font-family:monospace; font-size:10.5px;">${escapeHtml((k.scopes || []).join(', ') || 'ALL')}</span>
            </div>
            <div style="font-size:11px; color:var(--text-dim);">
              Tạo lúc: ${k.created_at ? new Date(k.created_at).toLocaleDateString() : '-'} •
              Lần cuối dùng: ${k.last_used_at ? new Date(k.last_used_at).toLocaleString() : 'Chưa sử dụng'} •
              Hạn dùng: ${k.expires_at ? new Date(k.expires_at).toLocaleDateString() : 'Vô thời hạn'}
            </div>
          </div>
          <div>
            ${!isRevoked ? `
              <button type="button" class="btn-subtle btn-danger btn-revoke-key" data-revoke-ai-key="${escapeHtml(k.id)}" style="padding:4px 10px; font-size:11.5px;">
                Thu hồi
              </button>
            ` : '<span style="color:var(--text-dim); font-size:11.5px;">Đã vô hiệu</span>'}
          </div>
        </div>
      `;
    });

    DOM.aiKeysListContainer.innerHTML = html;

    // Attach revoke buttons
    DOM.aiKeysListContainer.querySelectorAll('[data-revoke-ai-key]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const keyId = btn.getAttribute('data-revoke-ai-key');
        if (!confirm('Bạn có chắc chắn muốn thu hồi AI Access Key này? Mọi script hoặc AI agent đang sử dụng khóa này sẽ lập tức bị chặn.')) {
          return;
        }
        btn.disabled = true;
        btn.textContent = 'Đang thu hồi...';
        try {
          const revRes = await window.autoedit.aiKeys.revoke(keyId);
          if (revRes && revRes.ok) {
            showToast('Đã thu hồi khóa AI thành công!', 'success');
            await loadAiKeys();
          } else {
            showToast(revRes?.error || 'Lỗi khi thu hồi khóa', 'error');
            btn.disabled = false;
            btn.textContent = 'Thu hồi';
          }
        } catch (e) {
          showToast(e.message || 'Lỗi mạng', 'error');
          btn.disabled = false;
          btn.textContent = 'Thu hồi';
        }
      });
    });
  } catch (e) {
    DOM.aiKeysListContainer.innerHTML = `<div style="color:var(--danger); font-size:12px;">Lỗi: ${e.message}</div>`;
  }
}

function initAiKeys() {
  DOM.btnRefreshAiKeys?.addEventListener('click', () => {
    loadAiKeys();
  });

  DOM.btnOpenCreateAiKeyModal?.addEventListener('click', async () => {
    // 1. Reset inputs & sections
    if (DOM.inpAiKeyName) DOM.inpAiKeyName.value = '';
    if (DOM.inpAiKeyExpiry) DOM.inpAiKeyExpiry.value = '365';
    if (DOM.aiKeyModalError) {
      DOM.aiKeyModalError.textContent = '';
      DOM.aiKeyModalError.style.display = 'none';
    }
    if (DOM.aiKeyFormSection) DOM.aiKeyFormSection.style.display = 'block';
    if (DOM.aiKeyResultSection) DOM.aiKeyResultSection.style.display = 'none';
    if (DOM.inpAiKeySecretResult) {
      DOM.inpAiKeySecretResult.value = '';
      DOM.inpAiKeySecretResult.type = 'password';
    }
    if (DOM.btnToggleAiKeyVisibility) { DOM.btnToggleAiKeyVisibility.innerHTML = '<i data-lucide="eye" class="icon-xs"></i>'; refreshIcons(DOM.btnToggleAiKeyVisibility); }
    if (DOM.btnSubmitModalCreateAiKey) {
      DOM.btnSubmitModalCreateAiKey.style.display = 'block';
      DOM.btnSubmitModalCreateAiKey.disabled = false;
      DOM.btnSubmitModalCreateAiKey.textContent = 'Tạo Khóa';
    }

    // Reset default safe scopes (checked)
    document.querySelectorAll('.chk-ai-scope').forEach((chk) => {
      chk.checked = true;
    });

    // 2. Open modal immediately for instant physical feedback
    showModal(DOM.modalCreateAiKey);

    // 3. Populate available workspaces asynchronously
    if (DOM.selAiKeyWorkspace) {
      DOM.selAiKeyWorkspace.innerHTML = '<option value="">Đang tải không gian làm việc...</option>';
      try {
        let activeWs = null;
        let spaces = [];

        if (window.autoedit?.workspace?.sync) {
          const syncRes = await window.autoedit.workspace.sync();
          if (syncRes && syncRes.ok) {
            spaces = syncRes.spaces || [];
            activeWs = syncRes.activeWorkspace;
          }
        }

        if (!activeWs && window.autoedit?.workspace?.getActive) {
          const act = await window.autoedit.workspace.getActive();
          activeWs = act?.activeWorkspace || act;
        }

        if (spaces.length === 0 && activeWs) {
          spaces = [activeWs];
        }

        DOM.selAiKeyWorkspace.innerHTML = '';
        spaces.forEach((s) => {
          const opt = document.createElement('option');
          opt.value = s.id;
          const isPers = s.is_personal || s.owner_type === 'USER' || s.name?.includes('Personal');
          opt.textContent = `${s.name || (isPers ? 'Personal Space' : 'Team Space')} (${isPers ? 'Cá nhân' : 'Team'})`;
          if (activeWs && s.id === activeWs.id) opt.selected = true;
          DOM.selAiKeyWorkspace.appendChild(opt);
        });

        if (!DOM.selAiKeyWorkspace.value && activeWs?.id) {
          DOM.selAiKeyWorkspace.value = activeWs.id;
        }
        if (!DOM.selAiKeyWorkspace.value && DOM.selAiKeyWorkspace.options.length > 0) {
          DOM.selAiKeyWorkspace.selectedIndex = 0;
        }
      } catch (err) {
        console.error('Failed to load workspaces for AI key modal:', err);
        if (DOM.selAiKeyWorkspace) {
          DOM.selAiKeyWorkspace.innerHTML = '<option value="cs_pers_74b8e67a3e32a2b6">Personal Space (Cá nhân)</option>';
        }
      }
    }
  });

  const closeAiKeyModal = () => {
    // Clear raw secret from memory immediately upon closing
    if (DOM.inpAiKeySecretResult) {
      DOM.inpAiKeySecretResult.value = '';
      DOM.inpAiKeySecretResult.type = 'password';
    }
    if (DOM.btnToggleAiKeyVisibility) { DOM.btnToggleAiKeyVisibility.innerHTML = '<i data-lucide="eye" class="icon-xs"></i>'; refreshIcons(DOM.btnToggleAiKeyVisibility); }
    hideModal(DOM.modalCreateAiKey);
    loadAiKeys();
  };

  [DOM.btnCloseModalCreateAiKey, DOM.btnCancelModalCreateAiKey].forEach((btn) => {
    btn?.addEventListener('click', closeAiKeyModal);
  });

  DOM.btnSubmitModalCreateAiKey?.addEventListener('click', async () => {
    const name = DOM.inpAiKeyName?.value.trim() || '2TOOLNE Local AI';
    const expiry = parseInt(DOM.inpAiKeyExpiry?.value || '365', 10);

    let currentWsId = DOM.selAiKeyWorkspace?.value || null;
    if (!currentWsId) {
      try {
        const ws = await window.autoedit.workspace.getActive();
        currentWsId = ws?.activeWorkspace?.id || ws?.activeWorkspace?.workspace_id || ws?.id || ws?.workspace_id;
      } catch (e) {}
    }

    if (!currentWsId) {
      if (DOM.aiKeyModalError) {
        DOM.aiKeyModalError.textContent = 'Không tìm thấy Không Gian Làm Việc hiện tại. Vui lòng thử lại sau.';
        DOM.aiKeyModalError.style.display = 'block';
      }
      return;
    }

    // Collect safe scopes
    const scopes = [];
    document.querySelectorAll('.chk-ai-scope:checked').forEach((chk) => {
      scopes.push(chk.value);
    });
    const finalScopes = scopes.length > 0 ? scopes : ['CREATE_FOLDER', 'CREATE_SUBFOLDER', 'UPLOAD', 'LIST', 'READ'];

    if (DOM.btnSubmitModalCreateAiKey) {
      DOM.btnSubmitModalCreateAiKey.disabled = true;
      DOM.btnSubmitModalCreateAiKey.textContent = 'Đang tạo khóa...';
    }
    if (DOM.aiKeyModalError) {
      DOM.aiKeyModalError.style.display = 'none';
      DOM.aiKeyModalError.textContent = '';
    }

    try {
      const res = await window.autoedit.aiKeys.create({
        displayName: name,
        workspaceId: currentWsId,
        expiresInDays: expiry,
        scopes: finalScopes,
      });

      if (res && res.ok && res.key) {
        if (DOM.aiKeyFormSection) DOM.aiKeyFormSection.style.display = 'none';
        if (DOM.aiKeyResultSection) DOM.aiKeyResultSection.style.display = 'flex';
        if (DOM.inpAiKeySecretResult) DOM.inpAiKeySecretResult.value = res.key;
        if (DOM.btnSubmitModalCreateAiKey) DOM.btnSubmitModalCreateAiKey.style.display = 'none';
        showToast('Khóa AI đã được tạo thành công!', 'success');
      } else {
        if (DOM.aiKeyModalError) {
          DOM.aiKeyModalError.textContent = res?.error || 'Không thể tạo khóa AI';
          DOM.aiKeyModalError.style.display = 'block';
        }
        if (DOM.btnSubmitModalCreateAiKey) {
          DOM.btnSubmitModalCreateAiKey.disabled = false;
          DOM.btnSubmitModalCreateAiKey.textContent = 'Tạo Khóa';
        }
      }
    } catch (e) {
      if (DOM.aiKeyModalError) {
        DOM.aiKeyModalError.textContent = e.message || 'Lỗi khi tạo khóa';
        DOM.aiKeyModalError.style.display = 'block';
      }
      if (DOM.btnSubmitModalCreateAiKey) {
        DOM.btnSubmitModalCreateAiKey.disabled = false;
        DOM.btnSubmitModalCreateAiKey.textContent = 'Tạo Khóa';
      }
    }
  });

  DOM.btnToggleAiKeyVisibility?.addEventListener('click', () => {
    const inp = DOM.inpAiKeySecretResult;
    if (!inp) return;
    if (inp.type === 'password') {
      inp.type = 'text';
      if (DOM.btnToggleAiKeyVisibility) { DOM.btnToggleAiKeyVisibility.innerHTML = '<i data-lucide="eye-off" class="icon-xs"></i>'; refreshIcons(DOM.btnToggleAiKeyVisibility); }
    } else {
      inp.type = 'password';
      if (DOM.btnToggleAiKeyVisibility) { DOM.btnToggleAiKeyVisibility.innerHTML = '<i data-lucide="eye" class="icon-xs"></i>'; refreshIcons(DOM.btnToggleAiKeyVisibility); }
    }
  });

  DOM.btnCopyAiKeySecret?.addEventListener('click', async () => {
    const keyVal = DOM.inpAiKeySecretResult?.value;
    if (keyVal) {
      try {
        await navigator.clipboard.writeText(keyVal);
        showToast('Đã sao chép khóa AI vào bộ nhớ tạm!', 'success');
      } catch (_) {
        if (DOM.inpAiKeySecretResult) {
          DOM.inpAiKeySecretResult.select();
          document.execCommand('copy');
          showToast('Đã sao chép khóa AI vào bộ nhớ tạm!', 'success');
        }
      }
    }
  });
}

// =============================================================================
// Input Bundle Engine (Phase 2)
// =============================================================================
function initInputBundle() {
  DOM.btnBrowseBundle?.addEventListener('click', async () => {
    try {
      const selectedDir = await window.autoedit.openDirectoryDialog();
      if (!selectedDir) return;
      await loadBundleFromDirectory(selectedDir);
    } catch (e) {
      showToast(e.message || 'Lỗi khi mở thư mục Bundle', 'error');
    }
  });

  DOM.btnClearBundle?.addEventListener('click', () => {
    state.currentBundle = null;
    if (DOM.bundleStatusBanner) DOM.bundleStatusBanner.style.display = 'none';
    showToast('Đã đóng Input Bundle.', 'info');
  });

  DOM.btnInspectBundlePlan?.addEventListener('click', () => {
    if (!state.currentBundle) return;
    renderBundlePlanModal(state.currentBundle);
    showModal(DOM.modalBundlePlan);
  });

  [DOM.btnCloseModalBundlePlan, DOM.btnDismissModalBundlePlan].forEach((btn) => {
    btn?.addEventListener('click', () => hideModal(DOM.modalBundlePlan));
  });
}

async function loadBundleFromDirectory(bundleDir) {
  if (!window.autoedit?.bundle?.validateLocal) {
    showToast('Input Bundle Engine không khả dụng.', 'error');
    return;
  }

  showToast('Đang quét và kiểm tra Input Bundle...', 'info', 1500);
  const res = await window.autoedit.bundle.validateLocal(bundleDir);
  if (!res || !res.ok) {
    showToast(`Không thể nạp Bundle: ${res?.error || 'Lỗi không xác định'}`, 'error');
    return;
  }

  state.currentBundle = res;

  // Update UI Banner
  if (DOM.bundleStatusBanner) DOM.bundleStatusBanner.style.display = 'block';
  if (DOM.bundleProjectTitle) DOM.bundleProjectTitle.textContent = `AI Input Bundle: ${res.project_name}`;
  
  const total = res.scenes_count;
  const imgs = res.validation_summary.images_ready;
  const vids = res.validation_summary.videos_ready;
  if (DOM.bundleSceneStats) {
    DOM.bundleSceneStats.textContent = `${total} cảnh • ${imgs}/${total} ảnh sẵn sàng • ${vids}/${total} video sẵn sàng`;
  }
  if (DOM.bundleProgressFill) {
    const pct = total > 0 ? Math.round(((imgs + vids) / (total * 2)) * 100) : 0;
    DOM.bundleProgressFill.style.width = `${pct}%`;
  }

  // Auto-set project name
  if (DOM.inpProjectName && (!DOM.inpProjectName.value || DOM.inpProjectName.value.startsWith('Dự án '))) {
    DOM.inpProjectName.value = res.project_name;
  }

  // If script present, fill Studio script box
  if (res.script && DOM.inpScriptText && !DOM.inpScriptText.value.trim()) {
    DOM.inpScriptText.value = res.script;
  }

  // Populate images into Studio Media Grid (state.mediaList)
  const validImages = [];
  if (Array.isArray(res.scenes)) {
    for (const scene of res.scenes) {
      const matched = res.assets?.mapped_scenes?.find((m) => m.scene_id === scene.scene_id);
      if (matched && matched.image_path) {
        validImages.push(matched.image_path);
      }
    }
  }

  // Fallback: check assets.parsed_assets for images
  if (validImages.length === 0 && Array.isArray(res.assets?.parsed_assets)) {
    for (const asset of res.assets.parsed_assets) {
      if (asset.type === 'image' && asset.full_path) {
        validImages.push(asset.full_path);
      }
    }
  }

  // Fallback 2: scan directory files
  if (validImages.length === 0 && res.bundle_dir) {
    try {
      const scanRes = await window.autoedit.processImportPaths([res.bundle_dir]);
      if (scanRes && Array.isArray(scanRes.images) && scanRes.images.length > 0) {
        validImages.push(...scanRes.images);
      }
    } catch (e) {}
  }

  if (validImages.length > 0) {
    for (const img of validImages) {
      if (!state.mediaList.includes(img)) {
        state.mediaList.push(img);
      }
    }
    renderMediaGrid();
  }

  // Auto-set audio if present in bundle
  const audioCandidate = res.assets?.mapped_scenes?.find((m) => m.audio_path)?.audio_path ||
                         res.audio_file ||
                         res.assets?.audio_path;
  if (audioCandidate && typeof setAudioPathUI === 'function') {
    setAudioPathUI(audioCandidate);
  }

  showToast(`Đã nạp Input Bundle: ${res.project_name} (${res.scenes_count} cảnh)`, 'success');
}

function renderBundlePlanModal(bundle) {
  if (DOM.modalBundlePlanTitle) DOM.modalBundlePlanTitle.textContent = bundle.project_name;
  if (DOM.modalBundleSummaryBadge) {
    DOM.modalBundleSummaryBadge.textContent = `${bundle.scenes_count} cảnh • Tỷ lệ ${bundle.aspect_ratio || '9:16'}`;
  }

  if (!DOM.bundlePlanScenesList) return;

  let html = '';
  bundle.scenes.forEach((sc, idx) => {
    const asset = bundle.assets?.mapped_scenes?.find((m) => m.scene_id === sc.scene_id);
    const imgReady = asset?.image_status === 'READY';
    const vidReady = asset?.video_status === 'READY';

    html += `
      <div style="background:var(--bg-card,#0b1120); border:1px solid var(--border); border-radius:8px; padding:12px; display:flex; gap:12px; align-items:flex-start;">
        <div style="font-family:monospace; font-size:14px; font-weight:700; color:var(--brand-primary,#FF7A00); min-width:36px; padding:4px 8px; background:var(--brand-soft,rgba(255,122,0,0.12)); border-radius:4px; text-align:center;">
          ${escapeHtml(sc.scene_id)}
        </div>
        <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:13px; color:var(--text-main);">${escapeHtml(sc.slug)}</strong>
            <div style="display:flex; gap:6px;">
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; ${imgReady ? 'color:#34d399; background:rgba(52,211,153,0.15);' : 'color:#f87171; background:rgba(248,113,113,0.15);'}">
                Ảnh: ${imgReady ? 'Sẵn sàng' : 'Chưa có'}
              </span>
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; ${vidReady ? 'color:#34d399; background:rgba(52,211,153,0.15);' : 'color:#94a3b8; background:rgba(148,163,184,0.15);'}">
                Video: ${vidReady ? 'Sẵn sàng' : 'Chờ tạo'}
              </span>
            </div>
          </div>
          <div style="font-size:12px; color:var(--text-dim); line-height:1.4;">
            ${escapeHtml(sc.prompt || 'Chưa có prompt')}
          </div>
          ${sc.character_refs && sc.character_refs.length > 0 ? `
            <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
              Nhân vật: <em>${escapeHtml(sc.character_refs.join(', '))}</em>
            </div>
          ` : ''}
        </div>
      </div>
    `;
  });

  DOM.bundlePlanScenesList.innerHTML = html;
}

// -----------------------------------------------------------------------------
// Pipeline Queue V2 Integration (Phase 3 - Project Build Queue)
// -----------------------------------------------------------------------------
let currentCandidateBundles = [];
let cloudBundleFolders = [];

function initPipelineQueueV2() {
  if (!window.autoedit?.pipeline) return;

  const handleRunPipeline = async () => {
    if (!state.currentBundle || !state.currentBundle.bundle_dir) {
      showToast('Vui lòng nạp Input Bundle trước khi chạy pipeline.', 'warning');
      return;
    }

    try {
      showToast('Đang khởi động Pipeline Queue V2...', 'progress', 2000);
      const res = await window.autoedit.pipeline.enqueue(state.currentBundle.bundle_dir, {
        require_character_approval: false,
      });

      if (!res || !res.ok) {
        showToast(`Không thể đưa vào Pipeline: ${res?.error || 'Lỗi không xác định'}`, 'error');
        return;
      }

      showToast(`Đã thêm "${res.job.project_name}" vào Pipeline Queue V2!`, 'success');
      hideModal(DOM.modalBundlePlan);
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } catch (err) {
      showToast(`Lỗi khởi tạo Pipeline: ${err.message}`, 'error');
    }
  };

  DOM.btnRunPipelineAll?.addEventListener('click', handleRunPipeline);
  DOM.btnModalRunPipeline?.addEventListener('click', handleRunPipeline);

  // Primary Build Queue Toolbar (Section 12)
  DOM.btnAddBuildJob?.addEventListener('click', () => {
    switchTab('studio');
    showToast('Chuyển sang Studio để thêm dự án mới', 'info');
  });

  // Primary Pipeline Queue Toolbar Event Delegation (Stable Single Listener)
  const queueToolbar = document.querySelector('#subpane-build-queue .pane-buttons');
  if (queueToolbar && !queueToolbar.__queueToolbarDelegated) {
    queueToolbar.__queueToolbarDelegated = true;
    queueToolbar.addEventListener('click', async (event) => {
      const btn = event.target.closest('[data-queue-action], #btnImportBundleQueue, #btnImportCloudBundleQueue, #btnRunAllPipelineJobs, #btnStopBuildQueue, #btnClearCompletedPipelineJobs');
      if (!btn || btn.disabled) return;
      const action = btn.dataset.queueAction || btn.id;
      console.log(`[QUEUE_TOOLBAR_CLICK] action=${action} btnId=${btn.id}`);

      try {
        if (action === 'import-bundle' || action === 'btnImportBundleQueue') {
          await handleOpenLocalBundleImport();
        } else if (action === 'import-cloud' || action === 'btnImportCloudBundleQueue') {
          await handleOpenCloudBundlePicker();
        } else if (action === 'run-all' || action === 'btnRunAllPipelineJobs') {
          showToast('Đang khởi động chạy toàn bộ tác vụ trong hàng đợi...', 'progress');
          const res = await window.autoedit.pipeline.runAll();
          if (res && res.started_job_id) {
            showToast(`Đang chạy tác vụ: ${res.started_job_id}`, 'success');
          } else if (res && !res.ok) {
            showToast(`Thông báo: ${res.error || 'Không có tác vụ nào đang chờ'}`, 'info');
          }
          await refreshPipelineQueueUI();
          await refreshPipelineFloatingSummary();
        } else if (action === 'stop-queue' || action === 'btnStopBuildQueue') {
          const summary = await window.autoedit.pipeline.getActiveSummary();
          if (summary?.summary?.has_active_job && summary.summary.can_pause) {
            await window.autoedit.pipeline.pause(summary.summary.job_id);
            showToast('Đã dừng tác vụ đang chạy.', 'info');
          } else {
            showToast('Không có tác vụ nào đang chạy.', 'info');
          }
          await refreshPipelineFloatingSummary();
          await refreshPipelineQueueUI();
        } else if (action === 'clear-completed' || action === 'btnClearCompletedPipelineJobs' || action === 'btnClearBuildQueue') {
          await window.autoedit.pipeline.clearCompleted();
          await refreshPipelineQueueUI();
          showToast('Đã dọn dẹp các tác vụ đã hoàn tất.', 'info');
        }
      } catch (err) {
        console.error(`[QUEUE_TOOLBAR_FAIL] action=${action} error=${err.message}`);
        showToast(`Lỗi: ${err.message}`, 'error');
      }
    });
  }

  DOM.btnFloatingViewQueue?.addEventListener('click', () => {
    switchTab('queue');
    DOM.tabSubQueueBuild?.click();
  });

  DOM.btnFloatingPauseResume?.addEventListener('click', async () => {
    try {
      const summary = await window.autoedit.pipeline.getActiveSummary();
      if (!summary?.summary?.has_active_job) return;
      const s = summary.summary;
      if (s.can_pause) {
        await window.autoedit.pipeline.pause(s.job_id);
        showToast('Đã tạm dừng tác vụ Pipeline.', 'info');
      } else if (s.can_resume) {
        await window.autoedit.pipeline.resume(s.job_id);
        showToast('Đang tiếp tục tác vụ Pipeline...', 'info');
      }
      await refreshPipelineFloatingSummary();
      await refreshPipelineQueueUI();
    } catch (e) {
      showToast(e.message, 'error');
    }
  });

  // Modal Bundle Import Preview Confirm Enqueue (Section 13)
  DOM.btnConfirmEnqueueBundles?.addEventListener('click', async () => {
    const toEnqueue = currentCandidateBundles.filter(b => b.selected);
    if (toEnqueue.length === 0) {
      showToast('Vui lòng chọn ít nhất một Input Bundle hợp lệ để thêm vào hàng đợi.', 'warning');
      return;
    }

    const flowAccountId = document.getElementById('bundleEnqueueFlowProfile')?.value || undefined;
    const activeProfiles = window._cachedFlowProfiles || [];
    const selectedProfile = activeProfiles.find(p => p.id === flowAccountId) || activeProfiles[0];
    const tier = (selectedProfile?.tier || 'UNKNOWN').toUpperCase();

    const aspectRatio = document.getElementById('bundleEnqueueAspectRatio')?.value || '16:9';
    const imageResolution = document.getElementById('bundleEnqueueImageRes')?.value || '1080p';

    // Pre-enqueue Capability Validation (Requirement 14)
    if (imageResolution === '4K' && tier !== 'ULTRA') {
      showToast('Tài khoản đã chọn không hỗ trợ tải ảnh 4K. Vui lòng chọn gói Ultra hoặc hạ xuống 2K/1080p.', 'error', 4000);
      return;
    }
    if (imageResolution === '2K' && tier !== 'PRO' && tier !== 'ULTRA') {
      showToast('Tài khoản đã chọn không hỗ trợ tải ảnh 2K. Vui lòng chọn gói Pro/Ultra hoặc hạ xuống 1080p.', 'error', 4000);
      return;
    }

    const imageModel = document.getElementById('bundleEnqueueImageModel')?.value || 'AUTO';
    const batchSize = parseInt(document.getElementById('bundleEnqueueBatchSize')?.value || '1', 10);
    const ttsVoiceId = document.getElementById('bundleEnqueueTtsVoice')?.value || '';
    const editingStyleId = document.getElementById('bundleEnqueueEditingStyle')?.value || 'basic_slideshow';
    const upscaleMode = document.getElementById('bundleEnqueueUpscale')?.value || 'OFF';
    const charApproval = document.getElementById('bundleEnqueueCharApproval')?.value || 'manual';
    const autoApprove = charApproval === 'auto';
    const cloudSync = !!document.getElementById('bundleEnqueueCloudSync')?.checked;
    const crossFadeEnabled = !!document.getElementById('bundleEnqueueCrossFade')?.checked;

    const enqueueOpts = {
      flow_account_id: flowAccountId,
      flow_profile_id: flowAccountId,
      flow_plan_tier: tier,
      aspect_ratio: aspectRatio,
      flow_download_resolution: imageResolution,
      image_resolution: imageResolution, // backward compatibility
      image_model: imageModel,
      image_prompt_batch_size: batchSize,
      tts_voice_id: ttsVoiceId,
      editing_style_id: editingStyleId,
      upscale_mode: upscaleMode,
      auto_approve_characters: autoApprove,
      require_character_approval: !autoApprove,
      cloud_sync: cloudSync,
      cross_fade_enabled: crossFadeEnabled,
      flow_operating_mode: 'AUTO',
    };

    let addedCount = 0;
    let dupeCount = 0;
    let failCount = 0;

    showToast(`Đang thêm ${toEnqueue.length} bundle vào Hàng Đợi Tạo Dự Án...`, 'info', 2000);

    for (const item of toEnqueue) {
      try {
        const res = await window.autoedit.pipeline.enqueue(item.bundleDir, enqueueOpts);
        if (res && res.ok) {
          addedCount++;
        } else if (res && res.duplicate) {
          dupeCount++;
        } else {
          failCount++;
        }
      } catch (e) {
        failCount++;
      }
    }

    hideModal(DOM.modalBundleImportPreview);

    if (addedCount > 0) {
      showToast(`Đã thêm ${addedCount} Input Bundle vào Hàng Đợi Tạo Dự Án!`, 'success');
    }
    if (dupeCount > 0) {
      showToast(`${dupeCount} bundle bị bỏ qua do đã có trong hàng đợi.`, 'warning');
    }
    if (failCount > 0) {
      showToast(`Có ${failCount} bundle gặp lỗi khi nạp.`, 'error');
    }

    await refreshPipelineQueueUI();
    await refreshPipelineFloatingSummary();
  });

  // Cloud Bundle Picker Refresh & Confirm (Section 14 & 15)
  DOM.btnRefreshCloudBundlePicker?.addEventListener('click', () => loadCloudBundleFolders());

  DOM.cloudBundleFoldersList?.addEventListener('change', (e) => {
    const chk = e.target.closest('input[type="checkbox"][data-folder-idx]');
    if (chk) {
      const idx = parseInt(chk.dataset.folderIdx, 10);
      window.pipelineUiToggleCloudFolder(idx, chk.checked);
    }
  });

  DOM.bundleImportPreviewList?.addEventListener('change', (e) => {
    const chk = e.target.closest('input[type="checkbox"][data-candidate-idx]');
    if (chk) {
      const idx = parseInt(chk.dataset.candidateIdx, 10);
      window.pipelineUiToggleCandidate(idx, chk.checked);
    }
  });

  DOM.btnConfirmSelectCloudBundles?.addEventListener('click', async () => {
    const selected = cloudBundleFolders.filter(f => f.selected);
    if (selected.length === 0) {
      showToast('Vui lòng chọn ít nhất một thư mục Bundle từ Cloud.', 'warning');
      return;
    }

    showToast(`Đang tải & chuẩn bị ${selected.length} thư mục Bundle từ Cloud...`, 'progress', 3000);
    hideModal(DOM.modalCloudBundlePicker);

    const downloadedDirs = [];
    for (const folder of selected) {
      try {
        const matRes = await window.autoedit.cloud.materializeBundle(folder.spaceId, folder.id, folder.name);
        if (matRes && matRes.ok && matRes.bundle_dir) {
          downloadedDirs.push(matRes.bundle_dir);
        } else {
          showToast(`Lỗi tải bundle "${folder.name}": ${matRes?.error || 'Không xác định'}`, 'error');
        }
      } catch (err) {
        showToast(`Lỗi tải bundle "${folder.name}": ${err.message}`, 'error');
      }
    }

    if (downloadedDirs.length > 0) {
      await prepareAndShowBundleImportPreview(downloadedDirs);
    }
  });

  // Listen to pipeline events
  window.autoedit.pipeline.onProgress?.(() => {
    refreshPipelineFloatingSummary();
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  window.autoedit.pipeline.onStateChanged?.(() => {
    refreshPipelineFloatingSummary();
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  window.autoedit.pipeline.onCharacterApprovalRequired?.((data) => {
    refreshPipelineFloatingSummary();
    showToast(`Nhân vật trong dự án "${data?.job?.project_name}" đang chờ bạn duyệt!`, 'warning', 6000);
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  window.autoedit.pipeline.onCompleted?.((data) => {
    refreshPipelineFloatingSummary();
    showToast(`Dự án CapCut "${data?.job?.project_name}" đã tạo thành công (PROJECT_READY)!`, 'success', 6000);
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  window.autoedit.pipeline.onFailed?.((data) => {
    refreshPipelineFloatingSummary();
    showToast(`Tác vụ Pipeline thất bại: ${data?.error || 'Lỗi không xác định'}`, 'error', 6000);
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  // Initial fetch & event delegation
  initPipelineJobsListEventDelegation();
  refreshPipelineFloatingSummary();
  refreshPipelineQueueUI();
}

// -----------------------------------------------------------------------------
// Local & Cloud Bundle Import Helpers (Sections 12, 13, 14, 15)
// -----------------------------------------------------------------------------
async function handleOpenLocalBundleImport() {
  if (!window.autoedit?.openDirectoriesDialog || !window.autoedit?.bundle?.validateLocal) {
    showToast('Tính năng nạp bundle không khả dụng trên môi trường hiện tại.', 'error');
    return;
  }
  try {
    const selectedDirs = await window.autoedit.openDirectoriesDialog();
    if (!selectedDirs || !selectedDirs.length) return;

    showToast(`Đang kiểm tra ${selectedDirs.length} thư mục Input Bundle...`, 'progress', 2000);
    await prepareAndShowBundleImportPreview(selectedDirs);
  } catch (err) {
    showToast(`Lỗi khi mở thư mục: ${err.message}`, 'error');
  }
}

function updateResolutionOptionsForSelectedProfile(profileId) {
  const profiles = window._cachedFlowProfiles || [];
  const p = profiles.find(x => x.id === profileId) || profiles.find(x => x.is_active) || profiles[0];
  const tier = (p?.tier || 'UNKNOWN').toUpperCase();
  const resSel = document.getElementById('bundleEnqueueImageRes');
  if (!resSel) return;

  const prevVal = resSel.value || '1080p';
  let maxRes = '1080p';
  let optionsHtml = '';

  if (tier === 'ULTRA') {
    maxRes = '4K';
    optionsHtml = `
      <option value="1080p">1080p (Chuẩn Google Flow)</option>
      <option value="2K">2K (Google Flow Enhanced)</option>
      <option value="4K">4K (Google Flow Ultra HD)</option>
    `;
  } else if (tier === 'PRO') {
    maxRes = '2K';
    optionsHtml = `
      <option value="1080p">1080p (Chuẩn Google Flow)</option>
      <option value="2K">2K (Google Flow Enhanced)</option>
      <option value="4K" disabled>4K — Cần gói Ultra</option>
    `;
  } else {
    maxRes = '1080p';
    optionsHtml = `
      <option value="1080p">1080p (Chuẩn Google Flow)</option>
      <option value="2K" disabled>2K — Cần gói Pro</option>
      <option value="4K" disabled>4K — Cần gói Ultra</option>
    `;
  }

  resSel.innerHTML = optionsHtml;

  // Safe downgrade handling
  if (prevVal === '4K' && tier !== 'ULTRA') {
    resSel.value = maxRes;
    showToast(`Tài khoản ${p?.name || 'hiện tại'} (Gói ${tier}) không hỗ trợ 4K, tự động chuyển về ${maxRes}.`, 'warning', 3000);
  } else if (prevVal === '2K' && tier !== 'PRO' && tier !== 'ULTRA') {
    resSel.value = '1080p';
    showToast(`Tài khoản ${p?.name || 'hiện tại'} (Gói ${tier}) không hỗ trợ 2K, tự động chuyển về 1080p.`, 'warning', 3000);
  } else {
    const targetOpt = resSel.querySelector(`option[value="${prevVal}"]:not([disabled])`);
    if (targetOpt) {
      resSel.value = prevVal;
    } else {
      resSel.value = '1080p';
    }
  }

  updateUpscaleOptionsForDownloadResolution(resSel.value);
}

function updateUpscaleOptionsForDownloadResolution(flowResolution) {
  const upscaleSel = document.getElementById('bundleEnqueueUpscale');
  if (!upscaleSel) return;

  const curUpscale = upscaleSel.value || 'OFF';
  let optionsHtml = '';

  if (flowResolution === '4K') {
    optionsHtml = `
      <option value="OFF" selected>Tắt (Đã tải trực tiếp 4K từ Flow)</option>
    `;
    upscaleSel.innerHTML = optionsHtml;
    upscaleSel.value = 'OFF';
  } else if (flowResolution === '2K') {
    optionsHtml = `
      <option value="OFF">Tắt (Giữ nguyên gốc 2K từ Flow)</option>
      <option value="4K">Phóng to 4K Ultra HD (Real-ESRGAN AI)</option>
    `;
    upscaleSel.innerHTML = optionsHtml;
    upscaleSel.value = curUpscale === '4K' ? '4K' : 'OFF';
  } else {
    optionsHtml = `
      <option value="OFF">Tắt (Giữ nguyên kích thước gốc 1080p)</option>
      <option value="2K">Phóng to 2K (Real-ESRGAN AI)</option>
      <option value="4K">Phóng to 4K Ultra HD (Real-ESRGAN AI)</option>
    `;
    upscaleSel.innerHTML = optionsHtml;
    upscaleSel.value = (curUpscale === '2K' || curUpscale === '4K') ? curUpscale : 'OFF';
  }
}
window.updateResolutionOptionsForSelectedProfile = updateResolutionOptionsForSelectedProfile;
window.updateUpscaleOptionsForDownloadResolution = updateUpscaleOptionsForDownloadResolution;

async function prepareAndShowBundleImportPreview(dirs) {
  let activePaths = new Set();
  try {
    const listRes = await window.autoedit.pipeline.listJobs();
    const activeJobs = (listRes?.jobs || []).filter(j => !['PROJECT_READY', 'CANCELLED'].includes(j.state));
    activePaths = new Set(activeJobs.map(j => (j.bundle_dir || '').trim().replace(/[/\\]+$/, '')));
  } catch (e) {}

  currentCandidateBundles = [];
  for (const dir of dirs) {
    const cleanDir = (dir || '').trim().replace(/[/\\]+$/, '');
    const isDuplicate = activePaths.has(cleanDir);
    let validation = null;
    try {
      validation = await window.autoedit.bundle.validateLocal(dir);
    } catch (e) {
      validation = { ok: false, error: e.message, scenes: [] };
    }

    currentCandidateBundles.push({
      bundleDir: dir,
      name: validation?.bundle_name || dir.split(/[/\\]/).pop(),
      validation,
      isDuplicate,
      selected: !isDuplicate && Boolean(validation?.ok),
    });
  }

  if (window.autoedit?.flow?.getProfiles) {
    window.autoedit.flow.getProfiles().then((res) => {
      const sel = document.getElementById('bundleEnqueueFlowProfile');
      if (sel && res?.profiles) {
        window._cachedFlowProfiles = res.profiles;
        sel.innerHTML = '';
        res.profiles.forEach((p) => {
          const opt = document.createElement('option');
          opt.value = p.id;
          const tierText = (p.tier || 'UNKNOWN').toUpperCase();
          const displayName = p.name || 'Tài khoản Flow';
          const emailDisplay = p.email ? `${p.email} · ` : '';
          opt.textContent = `${emailDisplay || `${displayName} · `}${tierText}${p.is_active ? ' (Mặc định)' : ''}`;
          if (p.is_active) opt.selected = true;
          sel.appendChild(opt);
        });

        if (!sel._boundResUpdate) {
          sel._boundResUpdate = true;
          sel.addEventListener('change', (e) => {
            updateResolutionOptionsForSelectedProfile(e.target.value);
          });
        }

        const flowResSel = document.getElementById('bundleEnqueueImageRes');
        if (flowResSel && !flowResSel._boundUpscaleUpdate) {
          flowResSel._boundUpscaleUpdate = true;
          flowResSel.addEventListener('change', (e) => {
            updateUpscaleOptionsForDownloadResolution(e.target.value);
          });
        }

        updateResolutionOptionsForSelectedProfile(sel.value);
      }
    }).catch(() => {});
  }

  if (window.autoedit?.flow?.getSettings) {
    window.autoedit.flow.getSettings().then((res) => {
      if (res && res.settings) {
        const s = res.settings;
        const modelSel = document.getElementById('bundleEnqueueImageModel');
        if (modelSel && s.image_model) {
          modelSel.value = s.image_model;
        }
        const batchSel = document.getElementById('bundleEnqueueBatchSize');
        if (batchSel && s.image_prompt_batch_size) {
          batchSel.value = String(s.image_prompt_batch_size);
        }
        const aspectSel = document.getElementById('bundleEnqueueAspectRatio');
        if (aspectSel && s.default_aspect) {
          aspectSel.value = s.default_aspect;
        }
      }
    }).catch(() => {});
  }

  if (window.autoedit?.tts?.listVoices) {
    window.autoedit.tts.listVoices().then((res) => {
      const voices = res?.data || (res?.presets || []).concat(res?.custom || []);
      if (voices && voices.length) {
        const selVoice = document.getElementById('bundleEnqueueTtsVoice');
        if (selVoice) {
          const currentVal = selVoice.value;
          selVoice.innerHTML = '';
          voices.forEach((v) => {
            const opt = document.createElement('option');
            opt.value = v.id || v.voice_id || v.name;
            opt.textContent = `${v.name || v.id} (${v.gender || ''} ${v.description || ''})`.trim();
            if (opt.value === currentVal) opt.selected = true;
            selVoice.appendChild(opt);
          });
        }
      }
    }).catch(() => {});
  }

  renderBundleImportPreviewList();
  showModal(DOM.modalBundleImportPreview);
}

function renderBundleImportPreviewList() {
  if (!DOM.bundleImportPreviewList) return;
  if (currentCandidateBundles.length === 0) {
    DOM.bundleImportPreviewList.innerHTML = '<div style="color:var(--text-muted); text-align:center; padding:16px;">Không có thư mục nào được chọn.</div>';
    updateBundleImportSummary();
    return;
  }

  let html = '';
  currentCandidateBundles.forEach((item, idx) => {
    const v = item.validation || {};
    const totalScenes = v.scenes?.length || 0;
    const readyImgs = (v.scenes || []).filter(s => s.has_image).length;
    const readyVids = (v.scenes || []).filter(s => s.has_video).length;
    const readyAudio = (v.scenes || []).filter(s => s.has_audio).length;

    let badgeHtml = '';
    if (item.isDuplicate) {
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:var(--warning); background:var(--warning-soft);">Đã có trong queue</span>';
    } else if (v.ok) {
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:var(--success); background:var(--success-soft);">Hợp lệ</span>';
    } else {
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:var(--danger); background:var(--danger-soft);">Lỗi cấu trúc</span>';
    }

    html += `
      <div style="background:var(--bg-card,#0b1120); border:1px solid ${item.selected ? 'var(--brand-primary,#FF7A00)' : 'var(--border)'}; border-radius:8px; padding:12px; display:flex; gap:12px; align-items:flex-start;">
        <div style="padding-top:2px;">
          <input type="checkbox" id="chkCandidate_${idx}" data-candidate-idx="${idx}" ${item.selected ? 'checked' : ''} ${item.isDuplicate ? 'disabled' : ''} onchange="window.pipelineUiToggleCandidate(${idx}, this.checked)" style="cursor:pointer; width:16px; height:16px;" />
        </div>
        <div style="flex:1; display:flex; flex-direction:column; gap:6px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
              <strong style="font-size:13.5px; color:var(--text-main);">${escapeHtml(item.name)}</strong>
              <span style="font-size:11px; font-family:monospace; color:var(--text-dim); margin-left:8px; word-break:break-all;">${escapeHtml(item.bundleDir)}</span>
            </div>
            <div>${badgeHtml}</div>
          </div>

          <!-- Checklist Details -->
          <div style="display:grid; grid-template-columns: repeat(5, 1fr); gap:8px; background:rgba(0,0,0,0.25); padding:6px 10px; border-radius:6px; font-size:11px;">
            <div>
              <span style="color:var(--text-dim); display:block;">Phân cảnh:</span>
              <strong>${totalScenes} cảnh</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block;">Kịch bản:</span>
              <strong style="${v.has_script ? 'color:var(--success);' : 'color:var(--danger);'}">${v.has_script ? 'Có' : 'Chưa'}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block;">Nhân vật:</span>
              <strong>${v.characters?.length ? `${v.characters.length} NV` : 'Không'}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block;">Audio / TTS:</span>
              <strong>${readyAudio}/${totalScenes} audio</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block;">Tài nguyên có sẵn:</span>
              <strong>${readyImgs} ảnh, ${readyVids} vid</strong>
            </div>
          </div>

          ${v.warnings && v.warnings.length ? `
            <div style="font-size:11px; color:#fbbf24;">
              Lưu ý: ${escapeHtml(v.warnings.join(' • '))}
            </div>
          ` : ''}
          ${item.isDuplicate ? `
            <div style="font-size:11px; color:#fbbf24; font-weight:600;">
              Bundle này đã có trong hàng đợi và đang được xử lý.
            </div>
          ` : ''}
          ${!v.ok && v.error ? `
            <div style="font-size:11px; color:#f87171;">
              Lỗi: ${escapeHtml(v.error)}
            </div>
          ` : ''}
        </div>
      </div>
    `;
  });

  DOM.bundleImportPreviewList.innerHTML = html;
  updateBundleImportSummary();
}

function updateBundleImportSummary() {
  if (!DOM.bundleImportSummaryText) return;
  const selectedCount = currentCandidateBundles.filter(b => b.selected).length;
  DOM.bundleImportSummaryText.textContent = `Đã chọn ${selectedCount} / ${currentCandidateBundles.length} bundle`;
}

window.pipelineUiToggleCandidate = (idx, checked) => {
  if (currentCandidateBundles[idx]) {
    currentCandidateBundles[idx].selected = checked;
    renderBundleImportPreviewList();
  }
};

window.pipelineUiShowPreviewWithCandidates = (candidates) => {
  currentCandidateBundles = candidates || [];
  renderBundleImportPreviewList();
  showModal(DOM.modalBundleImportPreview);
};

window.pipelineUiPrepareAndShowPreview = prepareAndShowBundleImportPreview;

async function handleOpenCloudBundlePicker() {
  showModal(DOM.modalCloudBundlePicker);
  await loadCloudBundleFolders();
}

async function loadCloudBundleFolders() {
  if (!window.autoedit?.cloud?.listFiles) return;
  if (DOM.cloudBundleFoldersList) {
    DOM.cloudBundleFoldersList.innerHTML = '<div style="color:var(--text-muted); text-align:center; padding:16px;">Đang tải danh sách thư mục từ Cloud...</div>';
  }

  try {
    let spaceId = state.cloud?.currentSpaceId;
    if (!spaceId && window.autoedit?.cloud?.getSpaces) {
      const spacesRes = await window.autoedit.cloud.getSpaces();
      if (spacesRes && spacesRes.spaces && spacesRes.spaces.length) {
        spaceId = spacesRes.spaces[0].id;
        state.cloud.currentSpaceId = spaceId;
      }
    }

    if (!spaceId) {
      if (DOM.cloudBundleFoldersList) {
        DOM.cloudBundleFoldersList.innerHTML = '<div style="color:#f87171; text-align:center; padding:16px;">Vui lòng mở Cloud Explorer hoặc đăng nhập để nạp dữ liệu.</div>';
      }
      return;
    }

    // List root files to check for AI Inputs folder
    const rootRes = await window.autoedit.cloud.listFiles({ spaceId, parentId: null });
    let targetParentId = null;
    let targetFolderName = 'AI Inputs/';

    if (rootRes && rootRes.folders) {
      const aiFolder = rootRes.folders.find(f => /^(ai[\s_-]?inputs)$/i.test(f.name));
      if (aiFolder) {
        targetParentId = aiFolder.id;
        targetFolderName = `${aiFolder.name}/`;
      }
    }

    if (DOM.txtCloudPickerCurrentFolder) {
      DOM.txtCloudPickerCurrentFolder.textContent = targetFolderName;
    }

    let folders = [];
    if (targetParentId) {
      const subRes = await window.autoedit.cloud.listFiles({ spaceId, parentId: targetParentId });
      folders = subRes?.folders || [];
    } else {
      folders = rootRes?.folders || [];
    }

    folders.sort((a, b) => new Date(b.created_at || b.updated_at || 0) - new Date(a.created_at || a.updated_at || 0));

    cloudBundleFolders = folders.map(f => ({ ...f, spaceId, selected: false }));
    renderCloudBundleFoldersList();
  } catch (err) {
    if (DOM.cloudBundleFoldersList) {
      DOM.cloudBundleFoldersList.innerHTML = `<div style="color:#f87171; text-align:center; padding:16px;">Lỗi: ${escapeHtml(err.message)}</div>`;
    }
  }
}

function renderCloudBundleFoldersList() {
  if (!DOM.cloudBundleFoldersList) return;
  if (cloudBundleFolders.length === 0) {
    DOM.cloudBundleFoldersList.innerHTML = '<div style="color:var(--text-muted); text-align:center; padding:16px;">Không tìm thấy thư mục Input Bundle nào trong thư mục này.</div>';
    updateCloudSelectedCount();
    return;
  }

  let html = '';
  cloudBundleFolders.forEach((f, idx) => {
    html += `
      <label style="display:flex; align-items:center; gap:10px; background:var(--bg-card,#0b1120); border:1px solid ${f.selected ? 'var(--brand-primary,#FF7A00)' : 'var(--border)'}; border-radius:6px; padding:10px 12px; cursor:pointer;">
        <input type="checkbox" id="chkCloudFolder_${idx}" data-folder-idx="${idx}" ${f.selected ? 'checked' : ''} onchange="window.pipelineUiToggleCloudFolder(${idx}, this.checked)" style="cursor:pointer;" />
        <i data-lucide="folder" class="icon-sm" style="color:var(--brand);"></i>
        <div style="flex:1;">
          <strong style="font-size:13px; color:var(--text-main);">${escapeHtml(f.name)}</strong>
          <div style="font-size:11px; color:var(--text-dim);">ID: ${escapeHtml(String(f.id))} • Cập nhật: ${f.updated_at ? new Date(f.updated_at).toLocaleDateString() : 'N/A'}</div>
        </div>
      </label>
    `;
  });

  DOM.cloudBundleFoldersList.innerHTML = html;
  updateCloudSelectedCount();
}

function updateCloudSelectedCount() {
  if (!DOM.txtCloudSelectedCount) return;
  const count = cloudBundleFolders.filter(f => f.selected).length;
  DOM.txtCloudSelectedCount.textContent = `Đã chọn ${count} thư mục`;
}

window.pipelineUiToggleCloudFolder = (idx, checked) => {
  if (cloudBundleFolders[idx]) {
    cloudBundleFolders[idx].selected = checked;
    renderCloudBundleFoldersList();
  }
};

// -----------------------------------------------------------------------------
// Draggable & Minimizable Floating Pipeline Activity Widget
// -----------------------------------------------------------------------------
let isFloatingWidgetDragging = false;
window._floatingWidgetDismissedJobId = null;
window._currentActivePipelineJobId = null;

function initDraggableFloatingWidget() {
  const widget = DOM.floatingPipelineActivity;
  const header = DOM.floatingWidgetHeader;
  if (!widget || !header || widget._dragInitialized) return;
  widget._dragInitialized = true;

  // 1. Restore saved position from localStorage if valid
  try {
    const saved = localStorage.getItem('pipeline_floating_pos');
    if (saved) {
      const pos = JSON.parse(saved);
      if (typeof pos.left === 'number' && typeof pos.top === 'number') {
        const widgetWidth = widget.offsetWidth || 330;
        const widgetHeight = widget.offsetHeight || 120;
        const maxLeft = Math.max(10, window.innerWidth - widgetWidth - 10);
        const maxTop = Math.max(10, window.innerHeight - widgetHeight - 10);
        const left = Math.min(Math.max(10, pos.left), maxLeft);
        const top = Math.min(Math.max(10, pos.top), maxTop);
        widget.style.left = `${left}px`;
        widget.style.top = `${top}px`;
        widget.style.right = 'auto';
        widget.style.bottom = 'auto';
      }
    }
  } catch (e) {}

  // 2. Restore saved minimized state
  try {
    const isMin = localStorage.getItem('pipeline_floating_minimized') === 'true';
    if (isMin) {
      widget.classList.add('is-minimized');
      if (DOM.iconFloatingMinimize) DOM.iconFloatingMinimize.textContent = '▢';
    }
  } catch (e) {}

  // 3. Header Drag Logic
  let startX = 0, startY = 0;
  let initialLeft = 0, initialTop = 0;

  const onMouseMove = (moveEvent) => {
    if (!isFloatingWidgetDragging) return;
    const dx = moveEvent.clientX - startX;
    const dy = moveEvent.clientY - startY;

    let newLeft = initialLeft + dx;
    let newTop = initialTop + dy;

    const widgetWidth = widget.offsetWidth || 330;
    const widgetHeight = widget.offsetHeight || 100;
    const maxLeft = Math.max(10, window.innerWidth - widgetWidth - 10);
    const maxTop = Math.max(10, window.innerHeight - widgetHeight - 10);

    newLeft = Math.min(Math.max(10, newLeft), maxLeft);
    newTop = Math.min(Math.max(10, newTop), maxTop);

    widget.style.left = `${newLeft}px`;
    widget.style.top = `${newTop}px`;
  };

  const onMouseUp = () => {
    if (!isFloatingWidgetDragging) return;
    isFloatingWidgetDragging = false;
    widget.classList.remove('is-dragging');
    document.body.style.userSelect = '';

    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);

    // Save final position to localStorage
    try {
      const finalRect = widget.getBoundingClientRect();
      localStorage.setItem('pipeline_floating_pos', JSON.stringify({
        left: finalRect.left,
        top: finalRect.top
      }));
    } catch (e) {}
  };

  header.addEventListener('mousedown', (e) => {
    // Ignore clicks on control buttons (minimize, close, links)
    if (e.target.closest('.btn-floating-ctrl') || e.target.closest('button')) return;

    isFloatingWidgetDragging = true;
    widget.classList.add('is-dragging');
    document.body.style.userSelect = 'none';

    const rect = widget.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    initialLeft = rect.left;
    initialTop = rect.top;

    // Switch explicitly to absolute top/left coordinates
    widget.style.left = `${initialLeft}px`;
    widget.style.top = `${initialTop}px`;
    widget.style.right = 'auto';
    widget.style.bottom = 'auto';

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
  });

  // 4. Double click header to reset to bottom-right corner
  header.addEventListener('dblclick', (e) => {
    if (e.target.closest('.btn-floating-ctrl')) return;
    widget.style.left = 'auto';
    widget.style.top = 'auto';
    widget.style.right = '24px';
    widget.style.bottom = '20px';
    try {
      localStorage.removeItem('pipeline_floating_pos');
    } catch (e) {}
    showToast('Đã đặt lại vị trí bảng tiến độ về góc dưới phải.', 'success');
  });

  // 5. Minimize / Expand button
  DOM.btnFloatingMinimize?.addEventListener('click', (e) => {
    e.stopPropagation();
    const isNowMin = widget.classList.toggle('is-minimized');
    if (DOM.iconFloatingMinimize) DOM.iconFloatingMinimize.textContent = isNowMin ? '▢' : '—';
    try {
      localStorage.setItem('pipeline_floating_minimized', isNowMin ? 'true' : 'false');
    } catch (e) {}
  });

  // 6. Dismiss / Close button
  DOM.btnFloatingDismiss?.addEventListener('click', (e) => {
    e.stopPropagation();
    widget.style.display = 'none';
    window._floatingWidgetDismissedJobId = window._currentActivePipelineJobId || 'current';
    if (DOM.badgePipelineQuickToggle) {
      DOM.badgePipelineQuickToggle.style.display = 'inline-flex';
    }
    showToast('Đã ẩn bảng tiến độ. Nhấp biểu tượng Auto Flow trên thanh công cụ để mở lại.', 'info');
  });

  // 7. Top Header Reopen Badge
  DOM.badgePipelineQuickToggle?.addEventListener('click', () => {
    window._floatingWidgetDismissedJobId = null;
    if (DOM.badgePipelineQuickToggle) DOM.badgePipelineQuickToggle.style.display = 'none';
    refreshPipelineFloatingSummary();
  });

  // 8. Adjust boundaries on window resize
  window.addEventListener('resize', () => {
    if (widget.style.left && widget.style.left !== 'auto') {
      const rect = widget.getBoundingClientRect();
      const maxLeft = Math.max(10, window.innerWidth - rect.width - 10);
      const maxTop = Math.max(10, window.innerHeight - rect.height - 10);
      if (rect.left > maxLeft) widget.style.left = `${maxLeft}px`;
      if (rect.top > maxTop) widget.style.top = `${maxTop}px`;
    }
  });
}

async function refreshPipelineFloatingSummary() {
  if (!window.autoedit?.pipeline?.getActiveSummary || !DOM.floatingPipelineActivity) return;
  initDraggableFloatingWidget();
  try {
    const res = await window.autoedit.pipeline.getActiveSummary();
    const summary = res?.summary;
    if (!summary || !summary.has_active_job) {
      DOM.floatingPipelineActivity.style.display = 'none';
      if (DOM.badgePipelineQuickToggle) DOM.badgePipelineQuickToggle.style.display = 'none';
      window._currentActivePipelineJobId = null;
      window._floatingWidgetDismissedJobId = null;
      return;
    }

    const currentJobId = summary.job_id;
    window._currentActivePipelineJobId = currentJobId;

    // If dismissed for this active job, keep hidden and show quick reopen badge
    if (window._floatingWidgetDismissedJobId === currentJobId) {
      DOM.floatingPipelineActivity.style.display = 'none';
      if (DOM.badgePipelineQuickToggle) {
        DOM.badgePipelineQuickToggle.style.display = 'inline-flex';
        if (DOM.badgePipelineQuickText) {
          DOM.badgePipelineQuickText.textContent = `Flow: ${summary.progress_pct || 0}%`;
        }
      }
      return;
    }

    // Active and not dismissed: hide quick toggle badge and show widget
    if (DOM.badgePipelineQuickToggle) {
      DOM.badgePipelineQuickToggle.style.display = 'none';
    }

    DOM.floatingPipelineActivity.style.display = 'block';
    if (DOM.floatingProjectTitle) DOM.floatingProjectTitle.textContent = summary.project_name || 'Dự Án Pipeline';
    if (DOM.floatingSceneActivity) DOM.floatingSceneActivity.textContent = summary.activity_text || summary.state_label;
    if (DOM.floatingProgressBar) DOM.floatingProgressBar.style.width = `${summary.progress_pct || 0}%`;
    if (DOM.floatingProgressPercent) DOM.floatingProgressPercent.textContent = `${summary.progress_pct || 0}%`;
    if (DOM.floatingMiniProgressBadge) DOM.floatingMiniProgressBadge.textContent = `${summary.progress_pct || 0}%`;
    if (DOM.floatingFlowAccountBadge) DOM.floatingFlowAccountBadge.textContent = summary.flow_account || 'Flow #1';

    if (DOM.btnFloatingPauseResume) {
      if (summary.can_pause) {
        DOM.btnFloatingPauseResume.innerHTML = '<i data-lucide="pause" class="icon-xs"></i> Tạm dừng'; refreshIcons(DOM.btnFloatingPauseResume);
        DOM.btnFloatingPauseResume.style.display = 'inline-block';
      } else if (summary.can_resume) {
        DOM.btnFloatingPauseResume.innerHTML = '<i data-lucide="play" class="icon-xs"></i> Tiếp tục'; refreshIcons(DOM.btnFloatingPauseResume);
        DOM.btnFloatingPauseResume.style.display = 'inline-block';
      } else {
        DOM.btnFloatingPauseResume.style.display = 'none';
      }
    }
  } catch (e) {}
}

async function refreshPipelineQueueUI() {
  if (!window.autoedit?.pipeline?.listJobs || !DOM.pipelineJobsList) return;
  try {
    const res = await window.autoedit.pipeline.listJobs();
    const jobs = res?.jobs || [];

    if (DOM.pipelineActiveCountBadge) {
      const activeCount = jobs.filter(j => !['PROJECT_READY', 'CANCELLED'].includes(j.state)).length;
      if (jobs.length === 0) {
        DOM.pipelineActiveCountBadge.textContent = '0 tác vụ';
      } else if (activeCount === 0) {
        DOM.pipelineActiveCountBadge.textContent = `0 đang chạy (${jobs.length} hoàn tất)`;
      } else {
        DOM.pipelineActiveCountBadge.textContent = `${activeCount} đang xử lý (${jobs.length} tổng số)`;
      }
    }

    if (jobs.length === 0) {
      DOM.pipelineJobsList.innerHTML = `
        <div style="background:rgba(15,23,42,0.4); border:1px dashed rgba(255,255,255,0.1); border-radius:8px; padding:20px; text-align:center; color:var(--text-muted); font-size:12.5px;">
          Chưa có tác vụ trong Hàng Đợi Tạo Dự Án. Bấm <strong>"Import Bundle"</strong> hoặc <strong>"Import từ Cloud"</strong> để nạp Input Bundle!
        </div>
      `;
      initPipelineJobsListEventDelegation();
      return;
    }

    let html = '';
    for (const job of jobs) {
      const totalScenes = job.scenes?.length || 0;
      const readyImgs = (job.scenes || []).filter(s => s.image_status === 'READY').length;
      const readyVids = (job.scenes || []).filter(s => s.video_status === 'READY').length;
      const isReady = job.state === 'PROJECT_READY';
      const isFailed = job.state === 'FAILED';
      const isPaused = job.state === 'PAUSED';
      const isQueued = job.state === 'QUEUED';
      const isWaitingUser = job.state === 'WAITING_USER';
      const isWaitingApproval = job.state === 'WAITING_CHARACTER_APPROVAL';

      let stateColor = 'var(--brand, #FF7A00)';
      let stateBg = 'var(--brand-soft, rgba(255, 122, 0, 0.12))';
      let statusLabel = job.current_activity || job.state;

      if (isReady) {
        stateColor = 'var(--success, #35C889)';
        stateBg = 'var(--success-soft, rgba(53, 200, 137, 0.12))';
        statusLabel = 'PROJECT_READY';
      } else if (isFailed) {
        stateColor = 'var(--danger, #F05252)';
        stateBg = 'var(--danger-soft, rgba(240, 82, 82, 0.12))';
        statusLabel = 'THẤT BẠI';
      } else if (isWaitingUser) {
        stateColor = 'var(--warning, #F5A623)';
        stateBg = 'var(--warning-soft, rgba(245, 166, 35, 0.12))';
        statusLabel = 'CẦN CHỌN LẠI THƯ MỤC';
      } else if (isPaused) {
        stateColor = 'var(--warning, #F5A623)';
        stateBg = 'var(--warning-soft, rgba(245, 166, 35, 0.12))';
        statusLabel = 'TẠM DỪNG';
      } else if (isQueued) {
        stateColor = 'var(--text-muted, #696D74)';
        stateBg = 'rgba(255, 255, 255, 0.06)';
        statusLabel = 'ĐANG CHỜ (QUEUED)';
      } else if (isWaitingApproval) {
        stateColor = 'var(--brand, #FF7A00)';
        stateBg = 'var(--brand-soft, rgba(255, 122, 0, 0.12))';
        statusLabel = 'CHỜ DUYỆT NHÂN VẬT';
      } else if (job.state === 'GENERATING_TTS_AUDIO') {
        stateColor = 'var(--brand, #FF7A00)';
        stateBg = 'var(--brand-soft, rgba(255, 122, 0, 0.12))';
        statusLabel = job.current_activity || 'Đang tạo giọng AI';
      } else if (job.state === 'GENERATING_SUBTITLES') {
        stateColor = 'var(--brand, #FF7A00)';
        stateBg = 'var(--brand-soft, rgba(255, 122, 0, 0.12))';
        statusLabel = job.current_activity || 'Đang tạo phụ đề (SRT)';
      } else if (job.state === 'UPSCALING_IMAGES') {
        stateColor = 'var(--brand, #FF7A00)';
        stateBg = 'var(--brand-soft, rgba(255, 122, 0, 0.12))';
        statusLabel = job.current_activity || 'Đang phóng to ảnh AI (2K/4K)';
      }

      const isRunning = !isReady && !isFailed && !isPaused && !isQueued && !isWaitingUser && !isWaitingApproval;
      let cardStateClass = '';
      if (isRunning) cardStateClass = 'is-running';
      else if (isReady) cardStateClass = 'is-ready';
      else if (isFailed) cardStateClass = 'is-failed';
      else if (isWaitingUser) cardStateClass = 'is-waiting';

      let cardIcon = '<i data-lucide="zap" class="icon-sm" style="color:var(--brand);"></i>';
      if (isReady) cardIcon = '<i data-lucide="check-circle-2" class="icon-sm" style="color:var(--success);"></i>';
      else if (isFailed) cardIcon = '<i data-lucide="alert-circle" class="icon-sm" style="color:var(--danger);"></i>';
      else if (isWaitingUser) cardIcon = '<i data-lucide="triangle-alert" class="icon-sm" style="color:var(--warning);"></i>';
      else if (isWaitingApproval) cardIcon = '<i data-lucide="user-round" class="icon-sm" style="color:var(--brand);"></i>';
      else if (job.state === 'GENERATING_TTS_AUDIO') cardIcon = '<i data-lucide="audio-lines" class="icon-sm" style="color:var(--brand);"></i>';
      else if (job.state === 'GENERATING_SUBTITLES') cardIcon = '<i data-lucide="subtitles" class="icon-sm" style="color:var(--brand);"></i>';
      else if (job.state === 'UPSCALING_IMAGES') cardIcon = '<i data-lucide="sparkles" class="icon-sm" style="color:var(--brand);"></i>';
      else if (isPaused) cardIcon = '<i data-lucide="pause" class="icon-sm" style="color:var(--warning);"></i>';

      const accountDisplay = job.options?.flow_account_id ? (job.options.flow_account_id.startsWith('flowacc_') ? 'Flow' : escapeHtml(job.options.flow_account_id)) : 'Flow #1';

      html += `
        <div class="pipeline-job-card ${cardStateClass}">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span style="display:flex; align-items:center; margin-top:2px;">${cardIcon}</span>
              <div>
                <strong style="font-size:14px; color:var(--text-primary); font-weight:600;">${escapeHtml(job.project_name || job.bundle_name || 'Dự Án')}</strong>
                <details class="pipeline-tech-details" style="margin-top:2px;">
                  <summary style="font-size:10.5px; color:var(--text-muted); cursor:pointer;">Chi tiết kỹ thuật</summary>
                  <div style="font-size:10.5px; color:var(--text-secondary); font-family:monospace; margin-top:3px; word-break:break-all;">
                    ID: ${escapeHtml(job.id)} • Thư mục: ${escapeHtml(job.bundle_dir || 'N/A')}
                  </div>
                </details>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700; color:${stateColor}; background:${stateBg};">
                ${escapeHtml(statusLabel)}
              </span>
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; background:rgba(255,255,255,0.06); color:var(--text-muted);">
                ${accountDisplay}
              </span>
            </div>
          </div>

          ${isWaitingUser ? `
            <div style="font-size:12px; color:var(--warning); background:var(--warning-soft); border:1px solid var(--warning); border-radius:6px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <span style="display:flex; align-items:center; gap:6px;"><i data-lucide="triangle-alert" class="icon-xs"></i>${escapeHtml(job.error_message || 'Không tìm thấy Input Bundle. Thư mục đã bị di chuyển hoặc xóa.')}</span>
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="change-bundle-dir" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="folder-open" class="icon-xs"></i> Chọn lại thư mục
              </button>
            </div>
          ` : ''}

          ${isFailed ? `
            <div style="font-size:12px; color:var(--danger); background:var(--danger-soft); border:1px solid var(--danger); border-radius:6px; padding:8px 12px; display:flex; align-items:center; gap:6px;">
              <i data-lucide="alert-circle" class="icon-xs"></i>${escapeHtml(job.error_message || job.error || 'Lỗi không xác định trong quá trình tạo dự án')}
            </div>
          ` : ''}

          <!-- Checklist Grid -->
          <div style="display:grid; grid-template-columns: repeat(6, 1fr); gap:8px; background:rgba(0,0,0,0.25); padding:8px 12px; border-radius:6px; font-size:11.5px;">
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Input Bundle:</span>
              <strong style="${isWaitingUser ? 'color:var(--warning);' : 'color:var(--success);'}">${isWaitingUser ? 'Thiếu' : '<i data-lucide="check" class="icon-xs" style="margin-right:2px;"></i>Hợp lệ'}</strong>
            </div>
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Nhân vật:</span>
              <strong>${job.characters?.length > 0 ? (isWaitingApproval ? '<span style="color:var(--warning);">Chờ duyệt</span>' : `<span style="color:var(--success);"><i data-lucide="check" class="icon-xs" style="margin-right:2px;"></i>${job.characters.length}/${job.characters.length}</span>`) : 'Bỏ qua'}</strong>
            </div>
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Ảnh phân cảnh:</span>
              <strong>${readyImgs}/${totalScenes}</strong>
            </div>
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Video phân cảnh:</span>
              <strong>${readyVids}/${totalScenes}</strong>
            </div>
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Phụ đề:</span>
              <strong>${job.srt_path ? '<span style="color:var(--success);"><i data-lucide="check" class="icon-xs" style="margin-right:2px;"></i>Sẵn sàng</span>' : (readyVids === totalScenes && totalScenes > 0 ? '<span style="color:var(--success);"><i data-lucide="check" class="icon-xs" style="margin-right:2px;"></i>Sẵn sàng</span>' : 'Chờ')}</strong>
            </div>
            <div>
              <span style="color:var(--text-muted); display:block; font-size:10.5px;">Dự án CapCut:</span>
              <strong style="${isReady ? 'color:var(--success);' : ''}">${isReady ? '<span style="color:var(--success);"><i data-lucide="check" class="icon-xs" style="margin-right:2px;"></i>Sẵn sàng</span>' : 'Chờ'}</strong>
            </div>
          </div>

          <!-- Progress Bar & Realtime Single-Line Status -->
          <div>
            <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px; color:var(--text-muted);">
              <span>Tiến trình tổng thể:</span>
              <strong style="color:var(--text-primary);">${job.progress_pct || 0}%</strong>
            </div>
            <div style="background:rgba(0,0,0,0.4); height:6px; border-radius:3px; overflow:hidden;">
              <div style="background:${isReady ? 'var(--success)' : (isWaitingUser ? 'var(--warning)' : 'var(--brand)')}; height:100%; width:${job.progress_pct || 0}%; transition:width 0.3s ease;"></div>
            </div>
            <div class="flow-realtime-status-box" style="margin-top:8px; background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:6px 10px; display:flex; align-items:center; gap:8px;">
              <span class="flow-status-dot"></span>
              <span style="font-size:11.5px; font-weight:500; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                ${escapeHtml(getHumanFriendlyFlowStatus(job.current_flow_task || { stage: job.state, message: job.current_activity }))}
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:4px; flex-wrap:wrap;">
            ${isWaitingUser ? `
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="change-bundle-dir" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="folder-open" class="icon-xs"></i> Chọn lại thư mục
              </button>
            ` : ''}
            ${isWaitingApproval ? `
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="open-character-approval" data-job-id="${escapeHtml(job.id)}" style="background:#a855f7; border-color:#9333ea;">
                <i data-lucide="user-round" class="icon-xs"></i> Duyệt Nhân Vật (${(job.characters || []).filter(c => c.status !== 'APPROVED').length} chờ)
              </button>
              <button type="button" class="btn-secondary btn-sm" data-pipeline-action="approve-all-characters" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="check" class="icon-xs"></i> Duyệt Tất Cả
              </button>
            ` : ''}
            <button type="button" class="btn-secondary btn-sm" data-pipeline-action="toggle-scenes" data-job-id="${escapeHtml(job.id)}">
              <i data-lucide="${openPipelineAccordionJobIds.has(job.id) ? 'chevron-up' : 'chevron-down'}" class="icon-xs"></i> Xem Cảnh (${totalScenes})
            </button>
            ${isQueued ? `
              <button type="button" class="btn-success btn-sm" data-pipeline-action="resume-job" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="play" class="icon-xs"></i> Chạy
              </button>
            ` : ''}
            ${isPaused ? `
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="resume-job" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="play" class="icon-xs"></i> Tiếp tục
              </button>
            ` : ''}
            ${(!isReady && !isFailed && job.state !== 'CANCELLED' && !isPaused && !isWaitingUser && !isQueued && !isWaitingApproval) ? `
              <button type="button" class="btn-secondary btn-sm" data-pipeline-action="pause-job" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="pause" class="icon-xs"></i> Tạm dừng
              </button>
            ` : ''}
            ${isFailed && job.failed_stage === 'GENERATING_TTS_AUDIO' ? `
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="retry-tts" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="rotate-ccw" class="icon-xs"></i> Thử lại giọng AI
              </button>
            ` : ''}
            ${isFailed ? `
              <button type="button" class="btn-primary btn-sm" data-pipeline-action="resume-job" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="rotate-ccw" class="icon-xs"></i> Thử lại
              </button>
            ` : ''}
            ${isReady ? `
              <button type="button" class="btn-success btn-sm" data-pipeline-action="open-capcut" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="clapperboard" class="icon-xs"></i> Mở CapCut
              </button>
            ` : ''}
            <button type="button" class="btn-secondary btn-sm" data-pipeline-action="show-details" data-job-id="${escapeHtml(job.id)}">
              <i data-lucide="info" class="icon-xs"></i> Chi tiết
            </button>
            <button type="button" class="btn-secondary btn-sm" data-pipeline-action="flow-diagnostics" data-job-id="${escapeHtml(job.id)}" title="Xem log kỹ thuật chi tiết của Flow">
              <i data-lucide="terminal" class="icon-xs"></i> Chi Tiết Kỹ Thuật
            </button>
            ${(job.state !== 'CANCELLED') ? `
              <button type="button" class="btn-ghost btn-sm btn-danger" data-pipeline-action="delete-job" data-job-id="${escapeHtml(job.id)}">
                <i data-lucide="trash-2" class="icon-xs"></i> Xóa
              </button>
            ` : ''}
          </div>

          <!-- Collapsible Accordion: Scenes & Character List -->
          <div id="pipelineJobAccordion_${escapeHtml(job.id)}" style="display:${openPipelineAccordionJobIds.has(job.id) ? 'flex' : 'none'}; margin-top:8px; border-top:1px solid rgba(255,255,255,0.08); padding-top:10px; font-size:11.5px; flex-direction:column; gap:8px;">
            ${(job.characters && job.characters.length > 0) ? `
              <div style="background:rgba(0,0,0,0.25); border-radius:6px; padding:8px 10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                  <strong style="color:var(--text-primary); font-size:11.5px; display:flex; align-items:center; gap:4px;"><i data-lucide="user-round" class="icon-xs"></i> Nhân vật tham chiếu (${job.characters.length}):</strong>
                  <button type="button" class="btn-secondary btn-sm" data-pipeline-action="open-character-approval" data-job-id="${escapeHtml(job.id)}" style="font-size:10.5px; padding:2px 6px;">Quản lý nhân vật</button>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                  ${job.characters.map(c => `
                    <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:4px; padding:4px 8px; font-size:11px; display:flex; align-items:center; gap:6px;">
                      <span>${escapeHtml(c.name || c.id)}</span>
                      <span style="font-size:10px; font-weight:700; color:${c.status === 'APPROVED' ? 'var(--success)' : 'var(--brand)'};">${c.status === 'APPROVED' ? 'Đã duyệt' : 'Chờ'}</span>
                    </div>
                  `).join('')}
                </div>
              </div>
            ` : ''}

            <!-- Scenes List -->
            <div style="background:rgba(0,0,0,0.25); border-radius:6px; padding:8px 10px;">
              <strong style="color:var(--text-primary); font-size:11.5px; display:flex; align-items:center; gap:4px; margin-bottom:6px;"><i data-lucide="clapperboard" class="icon-xs"></i> Danh sách phân cảnh (${totalScenes}):</strong>
              <div style="display:flex; flex-direction:column; gap:4px; max-height:180px; overflow-y:auto;">
                ${(job.scenes || []).map((sc, scIdx) => `
                  <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; padding:3px 6px; background:rgba(255,255,255,0.02); border-radius:4px;">
                    <span style="font-family:monospace; color:var(--text-muted);">${escapeHtml(sc.scene_id || String(scIdx + 1).padStart(3, '0'))}</span>
                    <span style="color:var(--text-secondary); flex:1; margin:0 8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(sc.prompt || sc.text || 'Phân cảnh')}</span>
                    <div style="display:flex; gap:6px; font-size:10.5px;">
                      <span style="${sc.image_status === 'READY' ? 'color:var(--success);' : 'color:var(--text-muted);'}">Ảnh: ${sc.image_status === 'READY' ? 'Sẵn sàng' : '—'}</span>
                      <span style="${sc.video_status === 'READY' ? 'color:var(--success);' : 'color:var(--text-muted);'}">Vid: ${sc.video_status === 'READY' ? 'Sẵn sàng' : '—'}</span>
                    </div>
                  </div>
                `).join('')}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    DOM.pipelineJobsList.innerHTML = html;
    refreshIcons(DOM.pipelineJobsList);
    initPipelineJobsListEventDelegation();
  } catch (e) {}
}

// -----------------------------------------------------------------------------
// Global Action Handlers & Event Delegation for Pipeline Queue Cards & Modals
// -----------------------------------------------------------------------------
const openPipelineAccordionJobIds = new Set();
let currentJobForModalDetails = null;

function handlePipelineQueueAction(action, jobId, targetBtn) {
  console.log(`[QUEUE_ACTION_DISPATCH] action=${action} job=${jobId || 'N/A'}`);
  switch (action) {
    case 'toggle-scenes':
      window.pipelineUiToggleAccordion(jobId);
      console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`);
      break;
    case 'open-capcut':
      window.pipelineUiOpenCapCut(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'show-details':
      window.pipelineUiShowDetails(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'flow-diagnostics':
      window.pipelineUiShowTechDetails(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'delete-job':
      window.pipelineUiDelete(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'change-bundle-dir':
      window.pipelineUiChangeBundleDir(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'open-character-approval':
      window.pipelineUiOpenCharacterApproval(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'approve-all-characters':
      window.pipelineUiApproveAll(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'resume-job':
      window.pipelineUiResume(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'pause-job':
      window.pipelineUiTogglePause(jobId, false)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    case 'retry-tts':
      window.pipelineUiRetryTts(jobId)
        .then(() => console.log(`[QUEUE_ACTION_RESULT_OK] action=${action} job=${jobId || 'N/A'}`))
        .catch((err) => console.error(`[QUEUE_ACTION_RESULT_FAIL] action=${action} job=${jobId || 'N/A'} error=${err.message}`));
      break;
    default:
      console.warn(`[QUEUE_ACTION_UNKNOWN] action=${action} job=${jobId || 'N/A'}`);
      break;
  }
}

function initPipelineJobsListEventDelegation() {
  const container = DOM.pipelineJobsList || document.getElementById('pipelineJobsList');
  if (!container || container.__pipelineEventsDelegated) return;
  container.__pipelineEventsDelegated = true;

  container.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-pipeline-action]');
    if (!btn) return;
    const action = btn.dataset.pipelineAction;
    const jobId = btn.dataset.jobId;
    console.log(`[QUEUE_ACTION_CLICK] action=${action} job=${jobId || 'N/A'}`);
    handlePipelineQueueAction(action, jobId, btn);
  });
}

window.pipelineUiApproveAll = async (jobId) => {
  try {
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:approve-all-characters job=${jobId}`);
    await window.autoedit.pipeline.approveAllCharacters(jobId);
    showToast('Đã duyệt toàn bộ nhân vật. Pipeline tiếp tục tạo cảnh!', 'success');
    await refreshPipelineQueueUI();
  } catch (e) {
    showToast(e.message, 'error');
    throw e;
  }
};

window.pipelineUiTogglePause = async (jobId, isCurrentlyPaused) => {
  try {
    if (isCurrentlyPaused) {
      console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:resume job=${jobId}`);
      await window.autoedit.pipeline.resume(jobId);
      showToast('Đã tiếp tục tác vụ Pipeline.', 'success');
    } else {
      console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:pause job=${jobId}`);
      await window.autoedit.pipeline.pause(jobId);
      showToast('Đã tạm dừng tác vụ Pipeline.', 'info');
    }
    await refreshPipelineQueueUI();
  } catch (e) {
    showToast(e.message, 'error');
    throw e;
  }
};

window.pipelineUiResume = async (jobId) => {
  try {
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:resume job=${jobId}`);
    await window.autoedit.pipeline.resume(jobId);
    showToast('Đang thực hiện tác vụ Pipeline...', 'progress');
    await refreshPipelineQueueUI();
  } catch (e) {
    showToast(e.message, 'error');
    throw e;
  }
};

window.pipelineUiRetryTts = async (jobId) => {
  try {
    showToast('Đang thử lại tạo giọng đọc AI...', 'progress');
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:retry-tts job=${jobId}`);
    await window.autoedit.pipeline.retryTts(jobId);
    await refreshPipelineQueueUI();
    if (typeof refreshPipelineFloatingSummary === 'function') {
      await refreshPipelineFloatingSummary();
    }
  } catch (e) {
    showToast(`Lỗi thử lại TTS: ${e.message}`, 'error');
    throw e;
  }
};

window.pipelineUiCancel = async (jobId) => {
  try {
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:cancel job=${jobId}`);
    await window.autoedit.pipeline.cancel(jobId);
    showToast('Đã hủy tác vụ Pipeline.', 'info');
    await refreshPipelineQueueUI();
  } catch (e) {
    showToast(e.message, 'error');
    throw e;
  }
};

window.pipelineUiDelete = async (jobId) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tác vụ này khỏi Hàng Đợi Tạo Dự Án?')) return;
  try {
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:delete-job job=${jobId}`);
    const res = await window.autoedit.pipeline.deleteJob(jobId);
    if (res && res.ok) {
      showToast('Đã xóa tác vụ khỏi hàng đợi.', 'success');
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } else {
      showToast(`Không thể xóa: ${res?.error || 'Lỗi không xác định'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
    throw err;
  }
};

window.pipelineUiChangeBundleDir = async (jobId) => {
  try {
    const newDir = await window.autoedit.openDirectoryDialog();
    if (!newDir) return;

    showToast('Đang kiểm tra và liên kết thư mục nguồn mới...', 'progress', 2000);
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:update-bundle-dir job=${jobId} dir=${newDir}`);
    const res = await window.autoedit.pipeline.updateBundleDir(jobId, newDir);
    if (res && res.ok) {
      showToast('Đã cập nhật thành công thư mục nguồn! Tác vụ sẵn sàng chạy.', 'success');
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } else {
      showToast(`Không thể cập nhật: ${res?.error || 'Thư mục không hợp lệ'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
    throw err;
  }
};

window.pipelineUiShowDetails = async (jobId) => {
  try {
    console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:get-job job=${jobId}`);
    const res = await window.autoedit.pipeline.getJob(jobId);
    const job = res?.job;
    if (!job) {
      showToast('Không tìm thấy thông tin tác vụ.', 'error');
      return;
    }
    currentJobForModalDetails = job;

    if (DOM.queueJobDetailsTitle) {
      DOM.queueJobDetailsTitle.textContent = job.project_name || job.bundle_name || job.id;
    }

    const scenes = job.scenes || [];
    let scenesRows = '';
    scenes.forEach((sc, idx) => {
      const imgReady = sc.image_status === 'READY';
      const vidReady = sc.video_status === 'READY';
      const audioReady = Boolean(sc.audio_path);
      scenesRows += `
        <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
          <td style="padding:6px 8px; font-family:monospace;">${escapeHtml(sc.scene_id || String(idx + 1).padStart(3, '0'))}</td>
          <td style="padding:6px 8px;">${escapeHtml(sc.prompt || sc.text || 'N/A')}</td>
          <td style="padding:6px 8px; ${imgReady ? 'color:var(--success);' : 'color:var(--danger);'}">${imgReady ? 'Sẵn sàng' : 'Chưa'}</td>
          <td style="padding:6px 8px; ${vidReady ? 'color:var(--success);' : 'color:var(--text-muted);'}">${vidReady ? 'Sẵn sàng' : 'Chờ'}</td>
          <td style="padding:6px 8px; ${audioReady ? 'color:#34d399;' : 'color:#94a3b8;'}">${audioReady ? 'Có' : '—'}</td>
        </tr>
      `;
    });

    if (DOM.queueJobDetailsContent) {
      DOM.queueJobDetailsContent.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:12px;">
          <div style="background:rgba(0,0,0,0.3); padding:10px 14px; border-radius:6px; display:grid; grid-template-columns:1fr 1fr; gap:8px;">
            <div><span style="color:var(--text-dim);">Mã tác vụ:</span> <strong style="font-family:monospace;">${escapeHtml(job.id)}</strong></div>
            <div><span style="color:var(--text-dim);">Trạng thái:</span> <strong>${escapeHtml(job.state)}</strong></div>
            <div style="grid-column:1 / -1;"><span style="color:var(--text-dim);">Thư mục nguồn:</span> <code style="font-size:11px; word-break:break-all;">${escapeHtml(job.bundle_dir || 'N/A')}</code></div>
            ${(job.capcut_project_path || job.capcut_draft_path) ? `
              <div style="grid-column:1 / -1;"><span style="color:var(--text-dim);">Dự án CapCut:</span> <code style="font-size:11px; color:#34d399; word-break:break-all;">${escapeHtml(job.capcut_project_path || job.capcut_draft_path)}</code></div>
            ` : ''}
            ${job.error_message ? `
              <div style="grid-column:1 / -1; color:#f87171;"><span style="color:var(--text-dim);">Chi tiết lỗi:</span> ${escapeHtml(job.error_message)}</div>
            ` : ''}
          </div>

          <h5 style="margin:4px 0 0 0; font-size:12px; color:var(--text-main);">Danh Sách Phân Cảnh (${scenes.length})</h5>
          <div style="max-height:260px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
            <table style="width:100%; border-collapse:collapse; font-size:11.5px; text-align:left;">
              <thead>
                <tr style="background:rgba(255,255,255,0.04); color:var(--text-dim);">
                  <th style="padding:6px 8px;">Cảnh</th>
                  <th style="padding:6px 8px;">Prompt / Nội dung</th>
                  <th style="padding:6px 8px;">Ảnh</th>
                  <th style="padding:6px 8px;">Video</th>
                  <th style="padding:6px 8px;">Audio</th>
                </tr>
              </thead>
              <tbody>
                ${scenesRows || '<tr><td colspan="5" style="text-align:center; padding:12px; color:var(--text-muted);">Không có phân cảnh</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }

    if (DOM.btnOpenJobBundleFolder) {
      DOM.btnOpenJobBundleFolder.onclick = () => {
        if (job.bundle_dir && window.autoedit?.openPath) {
          window.autoedit.openPath(job.bundle_dir);
        }
      };
    }

    showModal(DOM.modalQueueJobDetails);
  } catch (err) {
    showToast(`Lỗi nạp chi tiết: ${err.message}`, 'error');
    throw err;
  }
};

window.pipelineUiOpenCapCut = async (jobId) => {
  try {
    const res = await window.autoedit.pipeline.getJob(jobId);
    const p = res?.job?.capcut_project_path || res?.job?.capcut_draft_path;
    if (p) {
      console.log(`[QUEUE_ACTION_IPC_SENT] channel=sidecar:open-capcut job=${jobId} path=${p}`);
      if (window.autoedit?.openCapCut) {
        await window.autoedit.openCapCut(p);
      }
      if (window.autoedit?.openPath) {
        await window.autoedit.openPath(p);
      }
      showToast('Đang mở dự án CapCut...', 'progress', 2500);
    } else {
      showToast('Không tìm thấy đường dẫn dự án CapCut.', 'warning');
    }
  } catch (err) {
    showToast(`Lỗi mở CapCut: ${err.message}`, 'error');
    throw err;
  }
};

window.pipelineUiShowTechDetails = async (jobId) => {
  console.log(`[QUEUE_ACTION_IPC_SENT] channel=pipeline:get-job (diagnostics) job=${jobId || 'N/A'}`);
  await window.openFlowDiagnosticsModal(jobId);
};

// -----------------------------------------------------------------------------
// Character Approval & Lightbox Gate (Phase 4 Directives)
// -----------------------------------------------------------------------------
let currentApprovalJobId = null;
let currentApprovalJob = null;

window.pipelineUiToggleAccordion = (jobId) => {
  const el = document.getElementById(`pipelineJobAccordion_${jobId}`);
  const container = DOM.pipelineJobsList || document.getElementById('pipelineJobsList');
  const btn = container?.querySelector(`[data-pipeline-action="toggle-scenes"][data-job-id="${jobId}"]`);
  const isCurrentlyOpen = openPipelineAccordionJobIds.has(jobId) || (el && el.style.display === 'flex');

  if (isCurrentlyOpen) {
    openPipelineAccordionJobIds.delete(jobId);
    if (el) el.style.display = 'none';
    if (btn) {
      const icon = btn.querySelector('svg, i');
      if (icon) {
        icon.outerHTML = '<i data-lucide="chevron-down" class="icon-xs"></i>';
        refreshIcons(btn);
      }
    }
  } else {
    openPipelineAccordionJobIds.add(jobId);
    if (el) el.style.display = 'flex';
    if (btn) {
      const icon = btn.querySelector('svg, i');
      if (icon) {
        icon.outerHTML = '<i data-lucide="chevron-up" class="icon-xs"></i>';
        refreshIcons(btn);
      }
    }
  }
};

window.pipelineUiOpenCharacterApproval = async (jobId) => {
  try {
    currentApprovalJobId = jobId;
    window.currentApprovalJobId = jobId;
    const modal = document.getElementById('modalCharacterApproval');
    if (modal) modal.dataset.jobId = jobId;
    const res = await window.autoedit.pipeline.getJob(jobId);
    if (!res || !res.job) {
      showToast('Không tìm thấy thông tin tác vụ.', 'error');
      return;
    }
    currentApprovalJob = res.job;
    if (!modal) return;
    modal.style.display = 'flex';
    renderCharacterApprovalCards(currentApprovalJob);
  } catch (err) {
    showToast(`Lỗi mở bảng duyệt nhân vật: ${err.message}`, 'error');
  }
};
window.openCharacterApprovalModal = window.pipelineUiOpenCharacterApproval;

function renderCharacterApprovalCards(job) {
  const cardList = document.getElementById('charApprovalCardList');
  const countBadge = document.getElementById('charApprovalCountBadge');
  if (!cardList) return;

  const characters = job.characters || [];
  const approvedCount = characters.filter((c) => c.status === 'APPROVED').length;
  if (countBadge) {
    countBadge.textContent = `${approvedCount} / ${characters.length} đã duyệt`;
    if (approvedCount === characters.length && characters.length > 0) {
      countBadge.style.background = 'rgba(52,211,153,0.15)';
      countBadge.style.color = '#34d399';
    } else {
      countBadge.style.background = 'var(--brand-soft, rgba(255,122,0,0.12))';
      countBadge.style.color = 'var(--brand-primary, #FF7A00)';
    }
  }

  if (characters.length === 0) {
    cardList.innerHTML = '<div style="color:var(--text-muted); grid-column:1/-1; text-align:center; padding:20px;">Tác vụ không có nhân vật tham chiếu.</div>';
    return;
  }

  let html = '';
  characters.forEach((c) => {
    const isApproved = c.status === 'APPROVED';
    const isRegenerating = c.status === 'REGENERATING' || c.status === 'PENDING';
    let statusBg = 'var(--brand-soft, rgba(255,122,0,0.12))';
    let statusColor = 'var(--brand-primary, #FF7A00)';
    let statusText = 'CHỜ DUYỆT';

    if (isApproved) {
      statusBg = 'rgba(52,211,153,0.15)';
      statusColor = '#34d399';
      statusText = 'ĐÃ DUYỆT';
    } else if (isRegenerating) {
      statusBg = 'rgba(251,191,36,0.15)';
      statusColor = '#fbbf24';
      statusText = 'ĐANG TẠO';
    }

    html += `
      <div id="charCard_${escapeHtml(c.id)}" style="background:var(--bg-card,#0b1120); border:1px solid ${isApproved ? '#34d399' : 'var(--border-color,#242938)'}; border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:10px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <strong style="font-size:13.5px; color:var(--text-main);">${escapeHtml(c.name || c.id)}</strong>
          <span style="font-size:10.5px; padding:2px 7px; border-radius:4px; font-weight:700; background:${statusBg}; color:${statusColor};">
            ${statusText}
          </span>
        </div>

        <div id="charThumbContainer_${escapeHtml(c.id)}" style="height:170px; background:#070a12; border:1px solid rgba(255,255,255,0.06); border-radius:8px; display:flex; justify-content:center; align-items:center; overflow:hidden; position:relative; cursor:pointer;" onclick="window.pipelineUiInspectCharacter('${escapeHtml(job.id)}', '${escapeHtml(c.id)}', '${escapeHtml(c.name || c.id)}')">
          <div id="charThumbLoading_${escapeHtml(c.id)}" style="color:var(--text-dim); font-size:11.5px;">Đang nạp ảnh...</div>
          <img id="charThumbImg_${escapeHtml(c.id)}" src="" alt="${escapeHtml(c.name || c.id)}" style="display:none; width:100%; height:100%; object-fit:cover;" />
        </div>

        <div style="font-size:11px; color:var(--text-muted); line-height:1.4; max-height:42px; overflow:hidden; text-overflow:ellipsis;">
          ${escapeHtml(c.prompt || c.description || 'Không có mô tả')}
        </div>
        <div style="font-size:10.5px; color:var(--text-dim); display:flex; justify-content:space-between;">
          <span>Lần tạo: ${c.attempts || 1}</span>
          <span style="color:var(--text-muted); cursor:pointer; display:inline-flex; align-items:center; gap:4px;" onclick="window.pipelineUiInspectCharacter('${escapeHtml(job.id)}', '${escapeHtml(c.id)}', '${escapeHtml(c.name || c.id)}')"><i data-lucide="zoom-in" class="icon-xs"></i> Phóng to</span>
        </div>

        <div style="display:flex; gap:6px; margin-top:2px;">
          ${!isApproved ? `
            <button type="button" class="btn-action-primary" onclick="window.pipelineUiApproveCharacter('${escapeHtml(job.id)}', '${escapeHtml(c.id)}')" style="flex:1; font-size:11.5px; padding:5px 8px; background:#10b981; border-color:#059669; font-weight:700;">
              <i data-lucide="check" class="icon-xs"></i> Duyệt
            </button>
          ` : `
            <button type="button" class="btn-subtle" disabled style="flex:1; font-size:11.5px; padding:5px 8px; color:#34d399; opacity:0.8;">
              <i data-lucide="lock" class="icon-xs"></i> Đã Khóa
            </button>
          `}
          <button type="button" class="btn-subtle" onclick="window.pipelineUiRegenerateCharacter('${escapeHtml(job.id)}', '${escapeHtml(c.id)}')" style="flex:1; font-size:11.5px; padding:5px 8px;">
            <i data-lucide="rotate-ccw" class="icon-xs"></i> Tạo Lại
          </button>
        </div>
      </div>
    `;
  });

  cardList.innerHTML = html;

  characters.forEach((c) => {
    loadCharacterThumbnail(job.id, c.id);
  });
}

async function loadCharacterThumbnail(jobId, characterId) {
  const loadingEl = document.getElementById(`charThumbLoading_${characterId}`);
  const imgEl = document.getElementById(`charThumbImg_${characterId}`);
  if (!window.autoedit?.pipeline?.getCharacterPreview) return;
  try {
    const res = await window.autoedit.pipeline.getCharacterPreview(jobId, characterId);
    if (res?.ok && res?.data_url) {
      if (imgEl && loadingEl) {
        imgEl.src = res.data_url;
        imgEl.style.display = 'block';
        loadingEl.style.display = 'none';
      }
    } else {
      if (loadingEl) loadingEl.textContent = 'Chưa có ảnh preview';
    }
  } catch (err) {
    if (loadingEl) loadingEl.textContent = 'Lỗi nạp ảnh';
  }
}

window.pipelineUiInspectCharacter = async (jobId, characterId, characterName) => {
  try {
    const res = await window.autoedit.pipeline.getCharacterPreview(jobId, characterId);
    if (!res?.ok || !res?.data_url) {
      showToast('Chưa có ảnh xem trước cho nhân vật này.', 'info');
      return;
    }
    const lightboxModal = document.getElementById('modalCharacterImagePreview');
    const lightboxImg = document.getElementById('lightboxCharImg');
    const lightboxTitle = document.getElementById('lightboxCharTitle');
    const lightboxPrompt = document.getElementById('lightboxCharPrompt');

    if (lightboxModal && lightboxImg) {
      lightboxImg.src = res.data_url;
      if (lightboxTitle) lightboxTitle.textContent = `Nhân Vật: ${characterName}`;
      if (lightboxPrompt) lightboxPrompt.textContent = res.prompt || '';
      lightboxModal.style.display = 'flex';
    }
  } catch (err) {
    showToast(`Không thể mở ảnh: ${err.message}`, 'error');
  }
};

window.pipelineUiApproveCharacter = async (jobId, characterId) => {
  try {
    showToast('Đang duyệt nhân vật...', 'info', 1000);
    const res = await window.autoedit.pipeline.approveCharacter(jobId, characterId);
    if (res?.ok) {
      showToast('Đã duyệt nhân vật thành công!', 'success');
      if (res.all_approved) {
        setTimeout(() => {
          const modal = document.getElementById('modalCharacterApproval');
          if (modal) modal.style.display = 'none';
        }, 300);
      }
      if (res.job) {
        currentApprovalJob = res.job;
        renderCharacterApprovalCards(currentApprovalJob);
      } else {
        await window.pipelineUiOpenCharacterApproval(jobId);
      }
      await refreshPipelineQueueUI();
    } else {
      showToast(`Không thể duyệt: ${res?.error || 'Lỗi'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
  }
};

window.pipelineUiRegenerateCharacter = async (jobId, characterId) => {
  try {
    showToast('Đang yêu cầu tạo lại ảnh nhân vật...', 'info', 1500);
    const res = await window.autoedit.pipeline.regenerateCharacter(jobId, characterId);
    if (res?.ok) {
      showToast('Đã gửi yêu cầu tạo lại. Flow sẽ tạo ảnh mới!', 'info');
      if (res.job) {
        currentApprovalJob = res.job;
        renderCharacterApprovalCards(currentApprovalJob);
      } else {
        await window.pipelineUiOpenCharacterApproval(jobId);
      }
      await refreshPipelineQueueUI();
    } else {
      showToast(`Không thể tạo lại: ${res?.error || 'Lỗi'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
  }
};

// Character Approval Modal Listeners
document.getElementById('btnCloseModalCharApproval')?.addEventListener('click', () => {
  const modal = document.getElementById('modalCharacterApproval');
  if (modal) modal.style.display = 'none';
});

document.getElementById('btnDismissCharApproval')?.addEventListener('click', () => {
  const modal = document.getElementById('modalCharacterApproval');
  if (modal) modal.style.display = 'none';
});

document.getElementById('btnCloseModalCharImgPreview')?.addEventListener('click', () => {
  const modal = document.getElementById('modalCharacterImagePreview');
  if (modal) modal.style.display = 'none';
});

document.getElementById('btnApproveAllChars')?.addEventListener('click', async () => {
  const targetId = currentApprovalJobId || window.currentApprovalJobId || document.getElementById('modalCharacterApproval')?.dataset?.jobId || currentApprovalJob?.id;
  if (!targetId) {
    showToast('Không tìm thấy ID tác vụ để duyệt.', 'warning');
    return;
  }
  try {
    showToast('Đang duyệt tất cả nhân vật...', 'info', 1500);
    const res = await window.autoedit.pipeline.approveAllCharacters(targetId);
    if (res?.ok) {
      showToast('Đã duyệt toàn bộ nhân vật! Pipeline tiếp tục tạo các phân cảnh.', 'success');
      const modal = document.getElementById('modalCharacterApproval');
      if (modal) modal.style.display = 'none';
      await refreshPipelineQueueUI();
    } else {
      showToast(`Không thể duyệt: ${res?.error || 'Lỗi'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
  }
});

window.autoedit?.pipeline?.onCharacterApprovalRequired?.((data) => {
  const jid = data?.job_id || data?.job?.id;
  if (jid) {
    window.pipelineUiOpenCharacterApproval(jid);
  }
});

// -----------------------------------------------------------------------------
// Google Flow Embedded Browser & Automation UI (Phase 4)
// -----------------------------------------------------------------------------
let flowTabLifecycleToken = 0;
let flowPendingRaf = null;
let flowPendingTimeout = null;

async function onOpenFlowTab() {
  const token = ++flowTabLifecycleToken;
  if (state.currentTab !== 'flow') return;

  const mainScroll = document.querySelector('.main-content-scroll');
  if (mainScroll) {
    mainScroll.scrollTop = 0;
    mainScroll.scrollLeft = 0;
  }

  // 1. Double RAF to guarantee all CSS styles, flexbox layouts, and scrollbar changes have settled
  await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  if (token !== flowTabLifecycleToken || state.currentTab !== 'flow') return;

  const getContainerBounds = () => {
    const container = DOM.flowBrowserContainer || document.getElementById('flowBrowserContainer');
    if (!container) return null;
    const rect = container.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    return {
      x: Math.round(rect.left),
      y: Math.round(rect.top),
      width: Math.round(rect.width),
      height: Math.round(rect.height),
    };
  };

  const initialBounds = getContainerBounds();
  if (initialBounds && window.autoedit?.flow?.showView) {
    await window.autoedit.flow.showView(initialBounds);
  }

  // 2. Refresh profiles and auth status asynchronously without blocking view presentation
  refreshFlowProfiles().catch(() => {});
  refreshFlowStatus().catch(() => {});

  if (flowPendingRaf) cancelAnimationFrame(flowPendingRaf);
  if (flowPendingTimeout) clearTimeout(flowPendingTimeout);

  // Multi-tier bounds stabilization pass: 60ms, 150ms, 300ms
  // Automatically aligns the WebContentsView layer after any late reflow or font render
  const schedulePass = (delay) => {
    setTimeout(() => {
      if (token === flowTabLifecycleToken && state.currentTab === 'flow') {
        updateFlowBrowserBounds();
      }
    }, delay);
  };
  schedulePass(60);
  schedulePass(150);
  schedulePass(300);
}

function updateFlowBrowserBounds() {
  if (state.currentTab !== 'flow') return;
  const container = DOM.flowBrowserContainer || document.getElementById('flowBrowserContainer');
  if (!container || !window.autoedit?.flow?.updateViewBounds) return;
  const rect = container.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return;

  const bounds = {
    x: Math.round(rect.left),
    y: Math.round(rect.top),
    width: Math.round(rect.width),
    height: Math.round(rect.height),
  };
  window.autoedit.flow.updateViewBounds(bounds);
}

function onLeaveFlowTab() {
  flowTabLifecycleToken++;
  if (flowPendingRaf) {
    cancelAnimationFrame(flowPendingRaf);
    flowPendingRaf = null;
  }
  if (flowPendingTimeout) {
    clearTimeout(flowPendingTimeout);
    flowPendingTimeout = null;
  }
  if (window.autoedit?.flow?.hideView) {
    window.autoedit.flow.hideView();
  }
}

async function refreshFlowProfiles() {
  if (!window.autoedit?.flow?.getProfiles) return;
  try {
    const res = await window.autoedit.flow.getProfiles();
    const select = DOM.flowProfileSelect || document.getElementById('flowProfileSelect');
    if (!select || !res?.profiles) return;
    select.innerHTML = '';
    res.profiles.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = `${p.name} ${p.email ? `(${p.email})` : ''} ${p.is_active ? '(Mặc định)' : ''}`;
      if (p.is_active) opt.selected = true;
      select.appendChild(opt);
    });
  } catch (err) {
    console.warn('Failed refreshing flow profiles:', err);
  }
}

async function refreshFlowStatus() {
  if (!window.autoedit?.flow?.getStatus) return;
  try {
    const res = await window.autoedit.flow.getStatus();
    const modeBadge = DOM.flowModeBadge || document.getElementById('flowModeBadge');
    const authBadge = DOM.flowAuthBadge || document.getElementById('flowAuthBadge');
    const creditBadge = DOM.flowCreditBadge || document.getElementById('flowCreditBadge');
    const btnTakeover = DOM.btnFlowTakeover || document.getElementById('btnFlowTakeover');
    const btnResumeAuto = DOM.btnFlowResumeAuto || document.getElementById('btnFlowResumeAuto');

    if (modeBadge) {
      if (res.mode === 'AUTO') {
        modeBadge.innerHTML = '<i data-lucide="zap" class="icon-xs" style="margin-right:4px;"></i>CHẾ ĐỘ: TỰ ĐỘNG'; refreshIcons(modeBadge);
        modeBadge.style.background = 'var(--brand-soft)';
        modeBadge.style.color = 'var(--brand-primary)';
        modeBadge.style.borderColor = 'var(--brand-soft-border)';
        if (btnTakeover) btnTakeover.style.display = 'inline-block';
        if (btnResumeAuto) btnResumeAuto.style.display = 'none';
      } else {
        modeBadge.innerHTML = '<i data-lucide="circle-dot" class="icon-xs" style="margin-right:4px;"></i>CHẾ ĐỘ: THỦ CÔNG'; refreshIcons(modeBadge);
        modeBadge.style.background = 'rgba(53,196,106,0.15)';
        modeBadge.style.color = 'var(--success,#35C46A)';
        modeBadge.style.borderColor = 'rgba(53,196,106,0.3)';
        if (btnTakeover) btnTakeover.style.display = 'none';
        if (btnResumeAuto) btnResumeAuto.style.display = 'inline-block';
      }
      if (DOM.flowModeSelect && res.mode) {
        DOM.flowModeSelect.value = res.mode;
      }
    }

    if (authBadge) {
      const authState = res.auth?.state || (res.auth?.loggedIn ? 'LOGGED_IN' : 'UNKNOWN');
      if (authState === 'LOGGED_IN' || res.auth?.loggedIn) {
        authBadge.innerHTML = `<i data-lucide="user-round" class="icon-xs" style="margin-right:4px;"></i>${escapeHtml(res.auth.email || res.profile?.email || 'Đã Đăng Nhập')}`; refreshIcons(authBadge);
        authBadge.style.background = 'rgba(53,196,106,0.15)';
        authBadge.style.color = 'var(--success,#35C46A)';
        authBadge.style.borderColor = 'rgba(53,196,106,0.3)';
      } else if (authState === 'LOADING' || authState === 'UNKNOWN') {
        authBadge.innerHTML = '<i data-lucide="rotate-cw" class="icon-xs" style="margin-right:4px;"></i>Đang xác minh phiên Flow...'; refreshIcons(authBadge);
        authBadge.style.background = 'var(--surface-2)';
        authBadge.style.color = 'var(--text-secondary)';
        authBadge.style.borderColor = 'var(--border-default)';
      } else if (authState === 'CAPTCHA_OR_2FA') {
        authBadge.innerHTML = '<i data-lucide="shield-alert" class="icon-xs" style="margin-right:4px;"></i>Yêu cầu xác minh CAPTCHA/2FA'; refreshIcons(authBadge);
        authBadge.style.background = 'rgba(245,166,35,0.15)';
        authBadge.style.color = 'var(--warning,#F5A623)';
        authBadge.style.borderColor = 'rgba(245,166,35,0.3)';
      } else if (authState === 'AUTH_CHALLENGE') {
        authBadge.innerHTML = '<i data-lucide="lock" class="icon-xs" style="margin-right:4px;"></i>Yêu cầu đăng nhập Google'; refreshIcons(authBadge);
        authBadge.style.background = 'rgba(245,166,35,0.15)';
        authBadge.style.color = 'var(--warning,#F5A623)';
        authBadge.style.borderColor = 'rgba(245,166,35,0.3)';
      } else {
        authBadge.innerHTML = '<i data-lucide="triangle-alert" class="icon-xs" style="margin-right:4px;"></i>Chưa đăng nhập Flow'; refreshIcons(authBadge);
        authBadge.style.background = 'rgba(240,90,90,0.15)';
        authBadge.style.color = 'var(--danger,#F05A5A)';
        authBadge.style.borderColor = 'rgba(240,90,90,0.3)';
      }
    }

    if (creditBadge) {
      creditBadge.style.display = 'inline-block';
      const credits = res.auth?.credits !== null && res.auth?.credits !== undefined
        ? res.auth.credits
        : (res.profile?.credits !== null && res.profile?.credits !== undefined ? res.profile.credits : null);
      if (credits !== null && credits !== undefined) {
        creditBadge.textContent = `Token Flow: ${credits}`;
      } else {
        creditBadge.textContent = 'Token Flow: Không xác định';
      }
    }
  } catch (err) {
    console.warn('Failed refreshing flow status:', err);
  }
}

function initFlowBrowser() {
  if (!window.autoedit?.flow) return;

  DOM.btnFlowTakeover?.addEventListener('click', async () => {
    try {
      const res = await window.autoedit.flow.takeover();
      if (res?.ok) {
        showToast('Đã dừng tự động & mở khóa điều khiển Flow an toàn.', 'info');
        await refreshFlowStatus();
      }
    } catch (e) {
      showToast(`Lỗi tiếp quản Flow: ${e.message}`, 'error');
    }
  });

  DOM.btnFlowResumeAuto?.addEventListener('click', async () => {
    try {
      const res = await window.autoedit.flow.resumeAuto();
      if (res?.ok) {
        showToast('Đã kích hoạt lại chế độ tự động Flow.', 'success');
        await refreshFlowStatus();
      }
    } catch (e) {
      showToast(`Lỗi kích hoạt tự động: ${e.message}`, 'error');
    }
  });

  DOM.btnFlowReload?.addEventListener('click', async () => {
    try {
      const isJobRunning = Boolean(
        state.activeJobId &&
        !['PROJECT_READY', 'FAILED', 'CANCELLED', 'PAUSED'].includes(
          state.pipelineJobs?.find(j => j.id === state.activeJobId)?.state
        )
      );
      if (isJobRunning) {
        const confirmed = window.confirm(
          'Tác vụ tự động Google Flow đang chạy trong nền. Tải lại trang sẽ làm gián đoạn tiến trình. Bạn có chắc chắn muốn tải lại?'
        );
        if (!confirmed) return;
      }
      const res = await window.autoedit.flow.reload({ force: false });
      if (res?.requiresConfirmation) {
        const confirmed = window.confirm(
          'Tác vụ tự động đang xử lý. Bạn có chắc chắn muốn buộc tải lại trang Google Flow?'
        );
        if (!confirmed) return;
        await window.autoedit.flow.reload({ force: true });
      }
      showToast('Đang tải lại trang Google Flow...', 'progress', 1500);
    } catch (e) {
      showToast(`Lỗi tải lại Flow: ${e.message}`, 'error');
    }
  });

  DOM.btnFlowNavigate?.addEventListener('click', async () => {
    try {
      await window.autoedit.flow.navigateFlow();
      showToast('Đang mở trang chủ Google Flow...', 'progress', 1500);
    } catch (e) {}
  });

  DOM.flowProfileSelect?.addEventListener('change', async (e) => {
    const profileId = e.target.value;
    if (!profileId) return;
    try {
      showToast('Đang chuyển đổi phân vùng tài khoản Flow...', 'progress', 2000);
      await window.autoedit.flow.switchProfile(profileId);
      await refreshFlowProfiles();
      await refreshFlowStatus();
      showToast('Đã chuyển tài khoản Flow thành công.', 'success');
    } catch (err) {
      showToast(`Lỗi chuyển tài khoản: ${err.message}`, 'error');
    }
  });

  const modalAddFlowProfile = document.getElementById('modalAddFlowProfile');
  const inpFlowProfileName = document.getElementById('inpFlowProfileName');
  const btnConfirmAddFlowProfile = document.getElementById('btnConfirmAddFlowProfile');
  const btnCloseModalAddFlowProfile = document.getElementById('btnCloseModalAddFlowProfile');
  const btnCancelModalAddFlowProfile = document.getElementById('btnCancelModalAddFlowProfile');

  const closeAddFlowProfileModal = () => {
    if (modalAddFlowProfile) modalAddFlowProfile.style.display = 'none';
  };

  DOM.btnFlowAddProfile?.addEventListener('click', () => {
    if (modalAddFlowProfile) {
      if (inpFlowProfileName) {
        inpFlowProfileName.value = '';
      }
      modalAddFlowProfile.style.display = 'flex';
      setTimeout(() => inpFlowProfileName?.focus(), 50);
    }
  });

  btnCloseModalAddFlowProfile?.addEventListener('click', closeAddFlowProfileModal);
  btnCancelModalAddFlowProfile?.addEventListener('click', closeAddFlowProfileModal);

  const handleConfirmAddProfile = async () => {
    const rawName = inpFlowProfileName?.value?.trim();
    const name = rawName || undefined;
    closeAddFlowProfileModal();
    try {
      showToast('Đang tạo hồ sơ Google Flow mới...', 'progress', 2000);
      const res = await window.autoedit.flow.createProfile(name);
      if (res?.ok && res.profile) {
        showToast(`Đã tạo hồ sơ "${res.profile.name}". Đang chuyển sang tài khoản mới...`, 'success');
        await window.autoedit.flow.switchProfile(res.profile.id);
        await refreshFlowProfiles();
        await refreshFlowStatus();
      } else {
        showToast('Không thể tạo hồ sơ Flow.', 'error');
      }
    } catch (err) {
      showToast(`Lỗi tạo hồ sơ: ${err.message}`, 'error');
    }
  };

  btnConfirmAddFlowProfile?.addEventListener('click', handleConfirmAddProfile);
  inpFlowProfileName?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleConfirmAddProfile();
    } else if (e.key === 'Escape') {
      closeAddFlowProfileModal();
    }
  });

  window.autoedit.flow.onModeChanged?.(() => {
    refreshFlowStatus();
  });

  window.autoedit.flow.onStatusUpdated?.(() => {
    refreshFlowStatus();
  });

  window.autoedit.flow.onReopenSetup?.(() => {
    expandFlowSetupPanel();
  });

  window.autoedit.flow.onTakeoverAction?.(async () => {
    showToast('Đã chuyển sang chế độ thủ công (Takeover).', 'warning');
    if (DOM.flowModeSelect) DOM.flowModeSelect.value = 'MANUAL';
    await refreshFlowStatus();
  });

  window.autoedit.flow.onResumeAutoAction?.(async () => {
    showToast('Đã tiếp tục chế độ tự động.', 'success');
    if (DOM.flowModeSelect) DOM.flowModeSelect.value = 'AUTO';
    await refreshFlowStatus();
  });

  window.autoedit.flow.onPauseAction?.(() => {
    showToast('Đã gửi lệnh tạm dừng xử lý tác vụ.', 'info');
  });

  window.autoedit.flow.onOpenCharacterApproval?.(async () => {
    try {
      const summary = await window.autoedit.pipeline?.getActiveSummary?.();
      const jobId = summary?.summary?.job_id;
      if (jobId && typeof window.pipelineUiOpenCharacterApproval === 'function') {
        window.pipelineUiOpenCharacterApproval(jobId);
      } else {
        showToast('Đang chờ duyệt ảnh nhân vật...', 'info');
      }
    } catch (e) {
      console.warn('Error opening character approval modal:', e);
    }
  });

  window.autoedit.flow.onAddReference?.((payload) => {
    const mediaId = payload?.mediaId || 'Media';
    showToast(`Đã ghi nhận ảnh/video [${mediaId}] làm tham chiếu.`, 'info');
  });

  // Live Activity Log & Current Task state (Phase 4 & UI Refinement)
  const activityLogBuffer = [];
  const flowActivityLogs = activityLogBuffer;
  const MAX_LOG_ENTRIES = 100;
  let flowTaskStartTimestamp = null;
  let flowTaskTimerInterval = null;

  function formatTime(date) {
    return date.toTimeString().split(' ')[0];
  }

  /**
   * Central Single-Line Status Event Mapping
   * Translates internal event stages into friendly, clear Vietnamese sentences.
   */
  function getHumanFriendlyFlowStatus(event) {
    if (!event) return 'Sẵn sàng tiếp nhận tác vụ.';
    const stage = event.stage || '';
    const msg = (event.message || '').trim();

    switch (stage) {
      case 'FLOW_TASK_STARTED':
        return 'Bắt đầu tác vụ tạo nội dung...';
      case 'FLOW_NAVIGATE':
      case 'FLOW_NAVIGATING':
        return 'Đang điều hướng tới Google Flow...';
      case 'FLOW_CHECK_AUTH':
        return 'Đang kiểm tra đăng nhập Google Flow...';
      case 'FLOW_AUTH_OK':
      case 'FLOW_PROFILE_READY':
        return 'Tài khoản Google Flow đã sẵn sàng.';
      case 'FLOW_CONFIGURE':
      case 'FLOW_SETTINGS_CONFIGURED':
        return 'Đã cấu hình chế độ và tỷ lệ khung hình.';
      case 'FLOW_REFERENCE_UPLOAD_STARTED':
      case 'FLOW_REFERENCE_UPLOADING':
        return '2TOOLNE đang chuẩn bị ảnh tham chiếu...';
      case 'FLOW_REFERENCE_UPLOAD_COMPLETED':
      case 'FLOW_REFERENCE_UPLOADED':
        return 'Đã chuẩn bị xong ảnh tham chiếu.';
      case 'FLOW_PROMPT_SUBMITTING':
      case 'FLOW_PROMPT_TYPING':
        return '2TOOLNE đang nhập prompt kịch bản...';
      case 'FLOW_PROMPT_INSERTED':
        return 'Đã nhập prompt kịch bản.';
      case 'FLOW_SUBMITTED':
      case 'FLOW_SUBMIT_CLICKED':
        return '2TOOLNE đang gửi yêu cầu tạo...';
      case 'FLOW_REQUEST_DETECTED':
        return '2TOOLNE đã ghi nhận yêu cầu tạo.';
      case 'FLOW_OPERATION_ASSIGNED':
        return '2TOOLNE đã tiếp nhận tác vụ.';
      case 'FLOW_GENERATING':
      case 'FLOW_GENERATION_PROCESSING':
      case 'FLOW_PROCESSING':
        if (typeof event.progress === 'number' && event.progress > 0) {
          if (event.generation_type === 'video' || event.is_video) {
            return `2TOOLNE đang tự động tạo video (${event.progress}%)...`;
          }
          if (event.generation_type === 'image' || event.is_image) {
            return `2TOOLNE đang tự động tạo ảnh (${event.progress}%)...`;
          }
          return `2TOOLNE đang xử lý tự động (${event.progress}%)...`;
        }
        if (event.generation_type === 'video' || event.is_video || (event.message && event.message.includes('video'))) {
          return '2TOOLNE đang tự động tạo video...';
        }
        if (event.generation_type === 'image' || event.is_image || (event.message && event.message.includes('ảnh'))) {
          return '2TOOLNE đang tự động tạo ảnh...';
        }
        return '2TOOLNE đang xử lý tự động...';
      case 'FLOW_MEDIA_DETECTED':
        return 'Đã phát hiện kết quả media mới.';
      case 'FLOW_DOWNLOAD_START':
      case 'FLOW_DOWNLOAD_STARTED':
      case 'FLOW_DOWNLOADING':
        return '2TOOLNE đang tải kết quả...';
      case 'FLOW_DOWNLOAD_COMPLETED':
      case 'FLOW_DOWNLOAD_COMPLETE':
        return '2TOOLNE đã tải kết quả thành công.';
      case 'FLOW_MEDIA_VERIFIED':
        return 'Đã kiểm tra xác thực media hợp lệ.';
      case 'FLOW_SCENE_COMPLETED':
      case 'FLOW_TASK_COMPLETED':
        return 'Hoàn tất tác vụ.';
      case 'FLOW_TASK_FAILED':
        return 'Tác vụ thất bại. Vui lòng kiểm tra lại hoặc chuyển sang thủ công.';
      case 'WAITING_CHARACTER_APPROVAL':
        return 'Đang chờ bạn duyệt ảnh nhân vật.';
      case 'WAITING_FLOW_LOGIN':
        return 'Cần đăng nhập Google Flow để tiếp tục.';
      case 'PAUSED_NO_FLOW_CREDIT':
        return 'Tài khoản Flow không đủ credit/token.';
      case 'WAITING_USER':
        return '2TOOLNE cần thao tác thủ công từ bạn.';
      case 'RUNNING_FLOW_AUTOMATION':
        return '2TOOLNE đang xử lý tự động...';
      case 'GENERATING_TTS_AUDIO':
        return 'Đang tạo giọng đọc AI (TTS)...';
      case 'GENERATING_SUBTITLES':
        return 'Đang tạo phụ đề (SRT)...';
      case 'UPSCALING_IMAGES':
        return 'Đang phóng to ảnh AI (2K/4K)...';
      case 'VERIFYING_GENERATED_ASSETS':
        return 'Đang kiểm tra chất lượng tài nguyên...';
      case 'PREPARING_LOCAL_CACHE':
        return 'Đang chuẩn bị bộ nhớ đệm cục bộ...';
      case 'TIMELINE_BUILDING':
        return 'Đang tính toán nhịp timeline...';
      case 'CAPCUT_PROJECT_BUILDING':
      case 'BUILDING_CAPCUT_PROJECT':
        return 'Đang dựng timeline dự án CapCut...';
      case 'VERIFYING_PROJECT':
        return 'Đang kiểm tra dự án CapCut...';
      case 'SYNCING_CLOUD':
        return 'Đang đồng bộ dự án lên Cloud...';
      case 'PROJECT_READY':
        return 'Dự án CapCut đã sẵn sàng.';
      case 'QUEUED':
        return 'Đang trong hàng đợi xử lý...';
      case 'PAUSED':
        return 'Tác vụ đang tạm dừng.';
      case 'IDLE':
        return 'Không có tác vụ nào đang thực thi.';
      default:
        break;
    }

    if (msg.includes('đăng nhập') || msg.includes('login')) return 'Cần đăng nhập Google Flow để tiếp tục.';
    if (msg.includes('tham chiếu') || msg.includes('reference')) {
      return '2TOOLNE đang chuẩn bị ảnh tham chiếu...';
    }
    if (msg.includes('prompt')) return '2TOOLNE đang nhập prompt kịch bản...';
    if (msg.includes('tải') || msg.includes('download')) return '2TOOLNE đang tải kết quả...';
    if (msg.includes('xử lý video') || msg.includes('tạo video') || msg.includes('video')) return '2TOOLNE đang tự động tạo video...';
    if (msg.includes('xử lý ảnh') || msg.includes('tạo ảnh') || msg.includes('ảnh')) return '2TOOLNE đang tự động tạo ảnh...';
    if (msg.includes('chờ') && msg.includes('Flow')) return '2TOOLNE đang chờ Google Flow xử lý...';
    if (msg.includes('Google Flow đang') || msg.includes('đang xử lý')) return '2TOOLNE đang xử lý tự động...';
    if (msg.includes('xác thực') || msg.includes('hợp lệ') || msg.includes('ffprobe')) return 'Đã kiểm tra media hợp lệ.';
    if (msg.includes('hoàn tất') || msg.includes('thành công')) return 'Hoàn tất tác vụ.';
    if (msg.includes('lỗi') || msg.includes('thất bại')) return 'Tác vụ thất bại. Vui lòng thử lại.';

    if (msg && msg.length < 90 && !msg.includes('{') && !msg.includes('http') && !msg.includes('Google Flow đang')) {
      return msg;
    }
    return '2TOOLNE đang xử lý tự động...';
  }
  window.getHumanFriendlyFlowStatus = getHumanFriendlyFlowStatus;

  function appendFlowActivityLog(event) {
    if (!event) return;
    const timestamp = event.timestamp ? new Date(event.timestamp) : new Date();
    const timeStr = timestamp.toLocaleTimeString('vi-VN', { hour12: false });
    const entry = {
      time: timeStr,
      level: event.level || 'INFO',
      stage: event.stage || 'UNKNOWN',
      message: event.message || '',
      task_id: event.task_id || event.pipeline_job_id || '',
    };
    activityLogBuffer.push(entry);
    if (activityLogBuffer.length > MAX_LOG_ENTRIES) {
      activityLogBuffer.shift();
    }
    renderFlowActivityLog();
  }

  function renderFlowActivityLog() {
    const logList = document.getElementById('flowActivityLogList');
    if (!logList) return;
    if (activityLogBuffer.length === 0) {
      logList.innerHTML = '<div style="color:var(--text-muted); font-style:italic;">Chưa có hoạt động nào được ghi nhận.</div>';
      return;
    }
    let html = '';
    for (const item of activityLogBuffer) {
      let levelColor = 'var(--text-secondary, #A7ABB3)';
      let levelBg = 'var(--surface-2, #202228)';
      if (item.level === 'SUCCESS') {
        levelColor = 'var(--success, #35C46A)';
        levelBg = 'rgba(53,196,106,0.15)';
      } else if (item.level === 'WARNING') {
        levelColor = 'var(--warning, #F5A623)';
        levelBg = 'rgba(245,166,35,0.15)';
      } else if (item.level === 'ERROR') {
        levelColor = 'var(--danger, #F05A5A)';
        levelBg = 'rgba(240,90,90,0.15)';
      }
      html += `
        <div style="display:flex; align-items:flex-start; gap:6px; line-height:1.4; border-bottom:1px solid rgba(255,255,255,0.04); padding-bottom:3px;">
          <span style="color:var(--text-muted); flex-shrink:0;">[${escapeHtml(item.time)}]</span>
          <span style="color:${levelColor}; background:${levelBg}; border-radius:3px; padding:1px 5px; font-weight:700; flex-shrink:0;">${escapeHtml(item.level)}</span>
          <span style="color:var(--text-secondary); font-weight:600; flex-shrink:0;">[${escapeHtml(item.stage)}]</span>
          <span style="color:var(--text-primary); word-break:break-word;">${escapeHtml(item.message)}</span>
        </div>
      `;
    }
    logList.innerHTML = html;
    logList.scrollTop = logList.scrollHeight;
  }

  function updateFlowCurrentTaskCard(event) {
    const emptyEl = document.getElementById('flowCurrentTaskEmpty');
    const activeEl = document.getElementById('flowCurrentTaskActive');
    const stageBadge = document.getElementById('flowTaskStageBadge');
    const projName = document.getElementById('flowTaskProjectName');
    const sceneInfo = document.getElementById('flowTaskSceneInfo');
    const realtimeStatusEl = document.getElementById('flowRealtimeStatusLine');
    const progressPct = document.getElementById('flowTaskProgressPct');
    const progressFill = document.getElementById('flowTaskProgressFill');
    const elapsedEl = document.getElementById('flowTaskElapsedTime');
    const attemptEl = document.getElementById('flowTaskAttemptCount');
    const charShortcutEl = document.getElementById('flowCharApprovalShortcut');
    const charPendingCountEl = document.getElementById('flowCharApprovalPendingCount');

    if (!activeEl || !emptyEl) return;

    if (!event || event.stage === 'FLOW_ALL_COMPLETED') {
      emptyEl.style.display = 'block';
      activeEl.style.display = 'none';
      if (charShortcutEl) charShortcutEl.style.display = 'none';
      if (stageBadge) {
        stageBadge.textContent = 'IDLE';
        stageBadge.style.background = 'var(--surface-2)';
        stageBadge.style.color = 'var(--text-muted)';
        stageBadge.style.borderColor = 'var(--border-default)';
      }
      if (flowTaskTimerInterval) {
        clearInterval(flowTaskTimerInterval);
        flowTaskTimerInterval = null;
      }
      return;
    }

    emptyEl.style.display = 'none';
    activeEl.style.display = 'flex';

    if (stageBadge) {
      stageBadge.textContent = event.stage || 'PROCESSING';
      if (event.level === 'SUCCESS' || event.stage === 'FLOW_TASK_COMPLETED') {
        stageBadge.style.background = 'rgba(53,196,106,0.15)';
        stageBadge.style.color = 'var(--success, #35C46A)';
        stageBadge.style.borderColor = 'rgba(53,196,106,0.3)';
      } else if (event.level === 'ERROR' || event.stage === 'FLOW_TASK_FAILED') {
        stageBadge.style.background = 'rgba(240,90,90,0.15)';
        stageBadge.style.color = 'var(--danger, #F05A5A)';
        stageBadge.style.borderColor = 'rgba(240,90,90,0.3)';
      } else {
        stageBadge.style.background = 'var(--brand-soft)';
        stageBadge.style.color = 'var(--brand-primary, #FF7A00)';
        stageBadge.style.borderColor = 'var(--brand-soft-border)';
      }
    }

    if (projName) {
      projName.textContent = event.project_name || (event.pipeline_job_id ? `Job: ${event.pipeline_job_id.slice(-8)}` : 'Google Flow Task');
    }

    if (sceneInfo) {
      const typeLabel = event.generation_type === 'video' ? 'Video' : (event.generation_type === 'character_ref' ? 'Ảnh Nhân Vật' : 'Ảnh');
      sceneInfo.textContent = `Scene ${event.scene_id || 'REF'} • ${typeLabel} (${event.aspect_ratio || '16:9'})`;
    }

    // Single-line Realtime Status update
    if (realtimeStatusEl) {
      const friendlyStatus = getHumanFriendlyFlowStatus(event);
      realtimeStatusEl.textContent = friendlyStatus;
      realtimeStatusEl.title = friendlyStatus;
    }

    // Deterministic progress
    const pct = typeof event.progress_pct === 'number' ? event.progress_pct : (typeof event.progress === 'number' ? event.progress : 0);
    if (progressPct) progressPct.textContent = `${pct}%`;
    if (progressFill) progressFill.style.width = `${pct}%`;

    if (attemptEl) attemptEl.textContent = `Lần thử: ${event.attempt || 1}`;

    // Character Approval Shortcut check
    const pendingChars = event.pending_character_approvals || event.pending_characters || 0;
    if (charShortcutEl) {
      if (event.stage === 'WAITING_CHARACTER_APPROVAL' || pendingChars > 0) {
        charShortcutEl.style.display = 'flex';
        if (charPendingCountEl) {
          charPendingCountEl.textContent = pendingChars || 1;
        }
      } else {
        charShortcutEl.style.display = 'none';
      }
    }

    if (event.stage === 'FLOW_TASK_STARTED') {
      flowTaskStartTimestamp = Date.now();
      if (flowTaskTimerInterval) clearInterval(flowTaskTimerInterval);
      flowTaskTimerInterval = setInterval(() => {
        if (!flowTaskStartTimestamp || !elapsedEl) return;
        const sec = Math.floor((Date.now() - flowTaskStartTimestamp) / 1000);
        elapsedEl.textContent = `Thời gian: ${sec}s`;
      }, 1000);
    }
  }

  // Diagnostics Modal wiring
  window.openFlowDiagnosticsModal = async (jobId) => {
    if (DOM.modalFlowDiagnostics) {
      DOM.modalFlowDiagnostics.style.display = 'flex';
    }
    const logList = document.getElementById('flowActivityLogList');
    if (jobId && window.autoedit?.pipeline?.getJob) {
      try {
        const res = await window.autoedit.pipeline.getJob(jobId);
        const job = res?.job;
        if (job && Array.isArray(job.logs) && job.logs.length > 0) {
          const mappedLogs = job.logs.map((l) => ({
            time: l.ts ? new Date(l.ts).toTimeString().split(' ')[0] : '--',
            level: l.level || 'INFO',
            stage: l.stage || l.generation_type || 'FLOW',
            message: l.message || JSON.stringify(l)
          }));
          if (logList) {
            let html = `
              <div style="padding:6px 10px; margin-bottom:8px; background:rgba(255,122,0,0.08); border:1px solid rgba(255,122,0,0.2); border-radius:6px; font-size:11.5px; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                <span><strong>Tác vụ:</strong> ${escapeHtml(job.project_name || job.id)} &nbsp;|&nbsp; <strong>Trạng thái:</strong> <span style="color:var(--brand);">${escapeHtml(job.state)}</span></span>
                <span><strong>Bản ghi:</strong> ${mappedLogs.length}</span>
              </div>
            `;
            for (const item of mappedLogs) {
              let levelColor = 'var(--text-secondary, #A7ABB3)';
              let levelBg = 'var(--surface-2, #202228)';
              if (item.level === 'SUCCESS') {
                levelColor = 'var(--success, #35C46A)';
                levelBg = 'rgba(53,196,106,0.15)';
              } else if (item.level === 'WARNING') {
                levelColor = 'var(--warning, #F5A623)';
                levelBg = 'rgba(245,166,35,0.15)';
              } else if (item.level === 'ERROR') {
                levelColor = 'var(--danger, #F05A5A)';
                levelBg = 'rgba(240,90,90,0.15)';
              }
              html += `
                <div style="display:flex; align-items:flex-start; gap:6px; line-height:1.4; border-bottom:1px solid rgba(255,255,255,0.04); padding-bottom:3px;">
                  <span style="color:var(--text-muted); flex-shrink:0;">[${escapeHtml(item.time)}]</span>
                  <span style="color:${levelColor}; background:${levelBg}; border-radius:3px; padding:1px 5px; font-weight:700; flex-shrink:0;">${escapeHtml(item.level)}</span>
                  <span style="color:var(--text-secondary); font-weight:600; flex-shrink:0;">[${escapeHtml(item.stage)}]</span>
                  <span style="color:var(--text-primary); word-break:break-word;">${escapeHtml(item.message)}</span>
                </div>
              `;
            }
            logList.innerHTML = html;
            logList.scrollTop = logList.scrollHeight;
          }
          return;
        }
      } catch (e) {
        console.warn('Could not load specific job logs for diagnostics:', e);
      }
    }
    renderFlowActivityLog();
  };

  DOM.btnOpenFlowDiagnostics?.addEventListener('click', () => {
    window.openFlowDiagnosticsModal();
  });

  const closeDiagnostics = () => {
    if (DOM.modalFlowDiagnostics) DOM.modalFlowDiagnostics.style.display = 'none';
  };
  DOM.btnCloseModalFlowDiagnostics?.addEventListener('click', closeDiagnostics);
  DOM.btnDismissModalFlowDiagnostics?.addEventListener('click', closeDiagnostics);

  DOM.btnFlowGoToQueue?.addEventListener('click', () => {
    switchTab('queue');
    DOM.tabSubQueueBuild?.click();
  });

  DOM.btnFlowOpenApprovalShortcut?.addEventListener('click', async () => {
    try {
      const summary = await window.autoedit.pipeline?.getActiveSummary?.();
      const jobId = summary?.summary?.job_id;
      if (jobId && typeof window.pipelineUiOpenCharacterApproval === 'function') {
        window.pipelineUiOpenCharacterApproval(jobId);
      }
    } catch (e) {}
  });

  // Activity event listeners
  window.autoedit.flow?.onActivityEvent?.((event) => {
    appendFlowActivityLog(event);
    updateFlowCurrentTaskCard(event);
  });

  window.autoedit.pipeline?.onFlowActivity?.((data) => {
    if (data?.event) {
      appendFlowActivityLog(data.event);
      updateFlowCurrentTaskCard(data.event);
    }
  });

  // Action buttons for activity log
  document.getElementById('btnClearFlowLog')?.addEventListener('click', () => {
    activityLogBuffer.length = 0;
    renderFlowActivityLog();
    showToast('Đã xóa nhật ký hoạt động.', 'success', 1500);
  });

  document.getElementById('btnCopyFlowLog')?.addEventListener('click', () => {
    if (activityLogBuffer.length === 0) {
      showToast('Nhật ký đang trống.', 'info', 1500);
      return;
    }
    const text = activityLogBuffer.map((e) => `[${e.time}] [${e.level}] [${e.stage}] ${e.message}`).join('\n');
    navigator.clipboard.writeText(text).then(() => {
      showToast('Đã sao chép nhật ký chẩn đoán vào bộ nhớ tạm.', 'success');
    }).catch((err) => {
      showToast('Không thể sao chép nhật ký: ' + err.message, 'error');
    });
  });

  window.addEventListener('resize', () => {
    if (state.currentTab === 'flow') {
      updateFlowBrowserBounds();
    }
  });

  if (window.autoedit?.flow?.onWindowResized) {
    window.autoedit.flow.onWindowResized(() => {
      if (state.currentTab === 'flow') {
        updateFlowBrowserBounds();
        setTimeout(updateFlowBrowserBounds, 50);
        setTimeout(updateFlowBrowserBounds, 150);
      }
    });
  }

  const flowContainer = document.getElementById('flowBrowserContainer');
  const viewFlowPane = document.getElementById('view-flow');
  if (typeof ResizeObserver === 'function') {
    const ro = new ResizeObserver(() => {
      if (state.currentTab === 'flow') {
        updateFlowBrowserBounds();
      }
    });
    if (flowContainer) ro.observe(flowContainer);
    if (viewFlowPane) ro.observe(viewFlowPane);
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// DESKTOP TEXT-TO-SPEECH & VOICE CLONING STUDIO (PHASE 7 & 8)
// ═══════════════════════════════════════════════════════════════════════════

const desktopTtsState = {
  voices: [],
  pollingTimer: null,
  isSubmitting: false,
  cloneAudioFilePath: null,
};

function initDesktopTts() {
  const inpScript = document.getElementById('ttsDesktopScript');
  const lblCharCount = document.getElementById('ttsDesktopCharCount');
  const selLang = document.getElementById('ttsDesktopLang');
  const selVoice = document.getElementById('ttsDesktopVoice');
  const rngSpeed = document.getElementById('ttsDesktopSpeed');
  const lblSpeedVal = document.getElementById('ttsDesktopSpeedVal');
  const selFormat = document.getElementById('ttsDesktopFormat');
  const inpFilename = document.getElementById('ttsDesktopFilename');
  const selSpace = document.getElementById('ttsDesktopSpace');
  const selFolder = document.getElementById('ttsDesktopFolder');
  const btnSubmit = document.getElementById('btnTtsDesktopSubmit');
  const btnRefresh = document.getElementById('btnTtsDesktopRefreshQueue');

  // Modal elements
  const btnMyVoices = document.getElementById('btnTtsDesktopMyVoices');
  const btnCloneVoice = document.getElementById('btnTtsDesktopCloneVoice');
  const modalMyVoices = document.getElementById('modalDesktopMyVoices');
  const btnCloseMyVoices = document.getElementById('btnCloseModalDesktopMyVoices');
  const btnDismissMyVoices = document.getElementById('btnDismissModalDesktopMyVoices');
  const btnOpenCloneFromMyVoices = document.getElementById('btnModalDesktopOpenCloneVoice');

  const modalCloneVoice = document.getElementById('modalDesktopCloneVoice');
  const btnCloseCloneVoice = document.getElementById('btnCloseModalDesktopCloneVoice');
  const btnCancelCloneVoice = document.getElementById('btnCancelModalDesktopCloneVoice');
  const btnSubmitCloneVoice = document.getElementById('btnSubmitModalDesktopCloneVoice');
  const btnBrowseCloneAudio = document.getElementById('btnDesktopBrowseCloneAudio');
  const inpCloneRefAudio = document.getElementById('inpDesktopCloneRefAudio');

  // Quick link from Studio Column 2 to TTS
  const btnStudioTts = document.getElementById('btnStudioGoToTts');
  if (btnStudioTts) {
    btnStudioTts.addEventListener('click', () => {
      const script = (DOM.inpScriptText?.value || '').trim();
      if (script && inpScript) {
        inpScript.value = script;
        updateDesktopTtsCharCount();
      }
      switchTab('tts');
    });
  }

  // Event: Script input char count
  if (inpScript) {
    inpScript.addEventListener('input', updateDesktopTtsCharCount);
  }

  // Event: Speed range slider
  if (rngSpeed && lblSpeedVal) {
    rngSpeed.addEventListener('input', () => {
      lblSpeedVal.textContent = parseFloat(rngSpeed.value).toFixed(2) + 'x';
    });
  }

  // Event: Language changed
  if (selLang) {
    selLang.addEventListener('change', () => {
      renderDesktopTtsVoiceSelect(selLang.value);
    });
  }

  // Event: Format changed
  if (selFormat && inpFilename) {
    selFormat.addEventListener('change', () => {
      const fmt = selFormat.value || 'wav';
      if (inpFilename.value) {
        inpFilename.value = inpFilename.value.replace(/\.(wav|mp3|m4a|ogg)$/i, '') + '.' + fmt;
      }
    });
  }

  // Event: Space changed
  if (selSpace) {
    selSpace.addEventListener('change', () => {
      syncDesktopTtsFolders(selSpace.value);
    });
  }

  // Event: Submit TTS Job
  if (btnSubmit) {
    btnSubmit.addEventListener('click', submitDesktopTtsJob);
  }

  // Event: Refresh queue
  if (btnRefresh) {
    btnRefresh.addEventListener('click', () => loadDesktopTtsJobs(true));
  }

  // Modal events: My Voices
  if (btnMyVoices) {
    btnMyVoices.addEventListener('click', openDesktopMyVoicesModal);
  }
  if (btnCloseMyVoices) {
    btnCloseMyVoices.addEventListener('click', () => hideModal(modalMyVoices));
  }
  if (btnDismissMyVoices) {
    btnDismissMyVoices.addEventListener('click', () => hideModal(modalMyVoices));
  }
  if (btnOpenCloneFromMyVoices) {
    btnOpenCloneFromMyVoices.addEventListener('click', () => {
      hideModal(modalMyVoices);
      openDesktopCloneVoiceModal();
    });
  }

  // Modal events: Clone Voice
  if (btnCloneVoice) {
    btnCloneVoice.addEventListener('click', openDesktopCloneVoiceModal);
  }
  if (btnCloseCloneVoice) {
    btnCloseCloneVoice.addEventListener('click', () => hideModal(modalCloneVoice));
  }
  if (btnCancelCloneVoice) {
    btnCancelCloneVoice.addEventListener('click', () => hideModal(modalCloneVoice));
  }
  if (btnBrowseCloneAudio) {
    btnBrowseCloneAudio.addEventListener('click', async () => {
      const audioPath = await window.autoedit.openAudioDialog();
      if (audioPath) {
        desktopTtsState.cloneAudioFilePath = audioPath;
        if (inpCloneRefAudio) {
          inpCloneRefAudio.value = audioPath.split(/[\/\\]/).pop() + ' (' + audioPath + ')';
        }
      }
    });
  }
  if (btnSubmitCloneVoice) {
    btnSubmitCloneVoice.addEventListener('click', submitDesktopCloneVoice);
  }
}

function updateDesktopTtsCharCount() {
  const inpScript = document.getElementById('ttsDesktopScript');
  const lblCharCount = document.getElementById('ttsDesktopCharCount');
  if (!inpScript || !lblCharCount) return;
  const len = inpScript.value.length;
  lblCharCount.textContent = `${len.toLocaleString()} / 10,000`;
  if (len > 10000) {
    lblCharCount.style.color = '#ef4444';
    lblCharCount.style.fontWeight = 'bold';
  } else {
    lblCharCount.style.color = 'var(--text-dim)';
    lblCharCount.style.fontWeight = 'normal';
  }
}

async function onOpenTtsTab() {
  updateDesktopTtsCharCount();

  const inpFilename = document.getElementById('ttsDesktopFilename');
  if (inpFilename && !inpFilename.value) {
    inpFilename.value = `narration_${Date.now().toString().slice(-6)}.wav`;
  }

  // Sync Cloud spaces into select
  syncDesktopTtsSpaces();

  // Load voices & jobs
  await Promise.all([
    loadDesktopTtsVoices(),
    loadDesktopTtsJobs(false),
  ]);

  // Start queue polling
  startDesktopTtsPolling();
}

function syncDesktopTtsSpaces() {
  const selSpace = document.getElementById('ttsDesktopSpace');
  if (!selSpace) return;
  selSpace.innerHTML = '';

  const spaces = state.cloud.spaces || [];
  if (spaces.length === 0) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Cloud Cá Nhân';
    selSpace.appendChild(opt);
  } else {
    spaces.forEach((sp) => {
      const opt = document.createElement('option');
      opt.value = sp.id;
      opt.textContent = `[${sp.owner_type === 'TEAM' ? 'Team' : 'Cá Nhân'}] ${sp.name}`;
      if (state.cloud.currentSpaceId && sp.id == state.cloud.currentSpaceId) {
        opt.selected = true;
      }
      selSpace.appendChild(opt);
    });
  }
  syncDesktopTtsFolders(selSpace.value);
}

async function syncDesktopTtsFolders(spaceId) {
  const selFolder = document.getElementById('ttsDesktopFolder');
  if (!selFolder) return;
  selFolder.innerHTML = '<option value="">-- Thư mục gốc (Root) --</option>';
  if (!spaceId || !window.autoedit?.cloud?.listFiles) return;

  try {
    const res = await window.autoedit.cloud.listFiles({ spaceId, folderId: null });
    if (res && res.ok && Array.isArray(res.items)) {
      const folders = res.items.filter((item) => item.type === 'FOLDER');
      folders.forEach((f) => {
        const opt = document.createElement('option');
        opt.value = f.id;
        opt.textContent = f.name;
        selFolder.appendChild(opt);
      });
    }
  } catch (e) {}
}

async function loadDesktopTtsVoices() {
  if (!window.autoedit?.tts?.listVoices) return;
  try {
    const res = await window.autoedit.tts.listVoices();
    if (res && (res.ok || res.data || res.success)) {
      const payload = res.data || res;
      desktopTtsState.voices = payload.data || (payload.presets || []).concat(payload.custom || []);
    }
  } catch (err) {
    console.error('Error loading desktop TTS voices:', err);
  }

  const selLang = document.getElementById('ttsDesktopLang');
  const currentLang = selLang ? selLang.value : 'en';
  renderDesktopTtsVoiceSelect(currentLang);
}

function renderDesktopTtsVoiceSelect(lang) {
  const selVoice = document.getElementById('ttsDesktopVoice');
  if (!selVoice) return;
  const prevVal = selVoice.value;
  selVoice.innerHTML = '';

  const btnSubmit = document.getElementById('btnTtsDesktopSubmit');
  if (lang === 'vi') {
    if (btnSubmit) {
      btnSubmit.disabled = true;
      btnSubmit.style.opacity = '0.5';
      btnSubmit.style.cursor = 'not-allowed';
      btnSubmit.title = 'Đang phát triển (Coming Soon) - Chờ engine VoxCPM';
    }
  } else {
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.style.opacity = '1';
      btnSubmit.style.cursor = 'pointer';
      btnSubmit.title = '';
    }
  }

  const matched = desktopTtsState.voices.filter((v) => {
    if (v.status === 'COMING_SOON' || v.status === 'PLANNED') return false;
    if (v.language === lang || v.primary_language === lang) return true;
    if (Array.isArray(v.supported_languages) && v.supported_languages.includes(lang)) return true;
    if (Array.isArray(v.supported_target_languages) && v.supported_target_languages.includes(lang)) return true;
    return false;
  });

  const presets = matched.filter((v) => v.type === 'preset' || v.is_preset);
  const custom = matched.filter((v) => v.type === 'cloned' || v.type === 'CUSTOM' || (!v.is_preset && v.type !== 'preset'));

  if (presets.length > 0) {
    const grp = document.createElement('optgroup');
    grp.label = 'Giọng Mẫu Tiêu Chuẩn (Presets)';
    presets.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = `${p.name} (${p.gender || 'AI Voice'}) · ${p.engine || 'Qwen3-TTS'}`;
      grp.appendChild(opt);
    });
    selVoice.appendChild(grp);
  }

  if (custom.length > 0) {
    const grp = document.createElement('optgroup');
    grp.label = 'Giọng Của Tôi (My Cloned Voices)';
    custom.forEach((c) => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = `${c.name} (Cloned Voice)`;
      grp.appendChild(opt);
    });
    selVoice.appendChild(grp);
  }

  if (matched.length === 0) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = '-- Không có giọng đọc cho ngôn ngữ này --';
    selVoice.appendChild(opt);
  }

  if (prevVal && Array.from(selVoice.options).some((o) => o.value === prevVal)) {
    selVoice.value = prevVal;
  }
}

async function submitDesktopTtsJob() {
  if (desktopTtsState.isSubmitting) return;

  const inpScript = document.getElementById('ttsDesktopScript');
  const text = (inpScript?.value || '').trim();
  if (!text) {
    showToast('Vui lòng nhập kịch bản cần đọc!', 'error');
    inpScript?.focus();
    return;
  }
  if (text.length > 10000) {
    showToast('Kịch bản vượt quá giới hạn 10,000 ký tự!', 'error');
    return;
  }

  const selVoice = document.getElementById('ttsDesktopVoice');
  const voiceId = selVoice?.value;
  if (!voiceId) {
    showToast('Vui lòng chọn một giọng đọc AI!', 'error');
    return;
  }

  const selLang = document.getElementById('ttsDesktopLang');
  const language = selLang?.value || 'en';
  if (language === 'vi') {
    showToast('Ngôn ngữ Tiếng Việt đang trong lộ trình phát triển (chờ engine VoxCPM). Vui lòng chọn English, Japanese hoặc Korean!', 'warning');
    return;
  }
  const rngSpeed = document.getElementById('ttsDesktopSpeed');
  const speed = parseFloat(rngSpeed?.value || '1.0');
  const selFormat = document.getElementById('ttsDesktopFormat');
  const format = selFormat?.value || 'wav';

  const inpFilename = document.getElementById('ttsDesktopFilename');
  let filename = (inpFilename?.value || '').trim();
  if (!filename) {
    filename = `narration_${Date.now().toString().slice(-6)}.${format}`;
    if (inpFilename) inpFilename.value = filename;
  }

  const selSpace = document.getElementById('ttsDesktopSpace');
  const spaceId = selSpace?.value || null;
  const selFolder = document.getElementById('ttsDesktopFolder');
  const folderId = selFolder?.value || null;

  const btnSubmit = document.getElementById('btnTtsDesktopSubmit');
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<span>Đang gửi tác vụ vào hàng đợi...</span>';
  }
  desktopTtsState.isSubmitting = true;

  try {
    const res = await window.autoedit.tts.createJob({
      text,
      voice_id: voiceId,
      language,
      speed,
      format,
      output_filename: filename,
      cloud_space_id: spaceId,
      folder_id: folderId,
    });

    if (res && (res.ok || res.success || res.job)) {
      showToast('Đã gửi tác vụ TTS vào hàng đợi xử lý!', 'success');
      await loadDesktopTtsJobs(true);
      startDesktopTtsPolling();
    } else {
      showToast('Lỗi tạo tác vụ: ' + (res?.error || res?.message || 'Không xác định'), 'error');
    }
  } catch (err) {
    console.error('Error submitting desktop TTS job:', err);
    showToast('Lỗi kết nối khi tạo tác vụ TTS: ' + (err.message || err), 'error');
  } finally {
    desktopTtsState.isSubmitting = false;
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg><span>Tạo Giọng Đọc AI (Generate)</span>';
    }
  }
}

async function loadDesktopTtsJobs(manual = false) {
  const container = document.getElementById('ttsDesktopQueueContainer');
  const badge = document.getElementById('ttsDesktopActiveCount');
  const navBadge = document.getElementById('ttsDesktopBadge');

  if (!window.autoedit?.tts?.listJobs) return;

  try {
    const res = await window.autoedit.tts.listJobs({ limit: 25 });
    if (res && (res.ok || res.data || res.success)) {
      const payload = res.data || res;
      const jobs = payload.jobs || (Array.isArray(payload.data) ? payload.data : []);
      renderDesktopTtsQueue(jobs);

      const activeStatuses = ['QUEUED', 'CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING'];
      const activeJobs = jobs.filter((j) => activeStatuses.includes(j.status));

      if (badge) {
        if (activeJobs.length > 0) {
          badge.style.display = 'inline-block';
          badge.textContent = `${activeJobs.length} Đang xử lý`;
        } else {
          badge.style.display = 'none';
        }
      }

      if (navBadge) {
        if (activeJobs.length > 0) {
          navBadge.style.display = 'inline-block';
          navBadge.textContent = String(activeJobs.length);
        } else {
          navBadge.style.display = 'none';
        }
      }
    }
  } catch (err) {
    if (manual && container) {
      container.innerHTML = '<div style="text-align:center; padding:24px; color:#ef4444;">Không thể tải danh sách hàng đợi.</div>';
    }
  }
}

function startDesktopTtsPolling() {
  if (desktopTtsState.pollingTimer) clearInterval(desktopTtsState.pollingTimer);
  desktopTtsState.pollingTimer = setInterval(() => {
    if (state.currentTab === 'tts') {
      loadDesktopTtsJobs(false);
    }
  }, 3500);
}

function renderDesktopTtsQueue(jobs) {
  const container = document.getElementById('ttsDesktopQueueContainer');
  if (!container) return;

  if (!jobs || jobs.length === 0) {
    container.innerHTML = `
      <div style="text-align:center; padding:36px 20px; color:var(--text-dim);">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:8px; opacity:0.6;"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
        <div style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:4px;">Hàng đợi âm thanh đang trống</div>
        <div style="font-size:11.5px;">Nhập kịch bản ở trên và nhấn "Tạo Giọng Đọc AI" để tạo audio trực tiếp lên Cloud.</div>
      </div>
    `;
    return;
  }

  const statusColors = {
    COMPLETED: { bg: 'rgba(34, 197, 94, 0.15)', text: '#22c55e', border: 'rgba(34, 197, 94, 0.3)', label: 'HOÀN THÀNH' },
    GENERATING: { bg: 'var(--brand-soft, rgba(255, 122, 0, 0.12))', text: 'var(--brand-primary, #FF7A00)', border: 'rgba(255, 122, 0, 0.3)', label: 'ĐANG XỬ LÝ (AI)' },
    UPLOADING: { bg: 'rgba(168, 85, 247, 0.15)', text: '#a855f7', border: 'rgba(168, 85, 247, 0.3)', label: 'ĐANG UPLOAD CLOUD' },
    CLAIMED: { bg: 'rgba(234, 179, 8, 0.15)', text: '#eab308', border: 'rgba(234, 179, 8, 0.3)', label: 'WORKER ĐÃ NHẬN' },
    QUEUED: { bg: 'rgba(234, 179, 8, 0.15)', text: '#eab308', border: 'rgba(234, 179, 8, 0.3)', label: 'CHỜ XỬ LÝ' },
    FAILED: { bg: 'rgba(239, 68, 68, 0.15)', text: '#ef4444', border: 'rgba(239, 68, 68, 0.3)', label: 'THẤT BẠI' },
    CANCELLED: { bg: 'rgba(148, 163, 184, 0.15)', text: '#94a3b8', border: 'rgba(148, 163, 184, 0.3)', label: 'ĐÃ HỦY' },
  };

  let html = '<div style="display:flex; flex-direction:column; gap:10px;">';

  jobs.forEach((job) => {
    const sConf = statusColors[job.status] || { bg: 'rgba(255,255,255,0.05)', text: '#ccc', border: 'var(--border-color)', label: job.status };
    const textPreview = (job.text || '').length > 80 ? (job.text.substring(0, 80) + '...') : (job.text || '');
    const createdAt = job.created_at ? new Date(job.created_at).toLocaleTimeString() : '';
    const isActive = ['QUEUED', 'CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING'].includes(job.status);
    const voiceName = job.voice_name || job.voice_id || 'AI Voice';

    html += `
      <div style="background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:6px; padding:12px; display:flex; flex-direction:column; gap:8px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:10.5px; font-weight:700; padding:2px 6px; border-radius:4px; background:${sConf.bg}; color:${sConf.text}; border:1px solid ${sConf.border};">
              ${sConf.label}
            </span>
            <span style="font-size:12.5px; font-weight:700; color:var(--text-main);">${escapeHtml(job.output_filename || 'narration.wav')}</span>
            <span style="font-size:11px; color:var(--text-dim); font-family:monospace;">ID: ${job.id}</span>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:11px; color:var(--text-dim);">${createdAt}</span>
            ${isActive ? `
              <button type="button" class="btn-subtle" style="font-size:11px; padding:2px 8px; color:#ef4444;" onclick="cancelDesktopTtsJob('${job.id}')">Hủy</button>
            ` : ''}
          </div>
        </div>

        <div style="font-size:12px; color:var(--text-main); background:rgba(0,0,0,0.2); padding:6px 10px; border-radius:4px; border-left:2px solid var(--primary); font-style:italic;">
          "${escapeHtml(textPreview)}"
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <div style="display:flex; align-items:center; gap:8px; font-size:11px; color:var(--text-dim);">
            <span><i data-lucide="mic" class="icon-xs" style="margin-right:4px;"></i><b>${escapeHtml(voiceName)}</b></span>
            <span><i data-lucide="globe" class="icon-xs" style="margin-right:4px;"></i>${(job.language || 'en').toUpperCase()}</span>
            <span><i data-lucide="gauge" class="icon-xs" style="margin-right:4px;"></i>${(job.speed || 1.0).toFixed(2)}x</span>
            <span><i data-lucide="file-audio" class="icon-xs" style="margin-right:4px;"></i>${(job.format || 'wav').toUpperCase()}</span>
            ${job.duration_seconds ? `<span><i data-lucide="clock" class="icon-xs" style="margin-right:4px;"></i>${job.duration_seconds.toFixed(1)}s</span>` : ''}
          </div>

          ${job.status === 'COMPLETED' && job.cloud_file_id ? `
            <div style="display:flex; align-items:center; gap:8px;">
              <button type="button" class="btn-action-primary" style="font-size:11px; padding:4px 10px; display:inline-flex; align-items:center; gap:4px;" onclick='useAudioInTimeline(${JSON.stringify(job).replace(/'/g, "&apos;")})'>
                <span><i data-lucide="play" class="icon-xs" style="margin-right:4px;"></i>Dùng Âm Thanh</span>
              </button>
            </div>
          ` : ''}

          ${isActive ? `
            <div style="display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--brand-primary, #FF7A00);">
              <span class="spinner" style="width:12px; height:12px; border-width:2px;"></span>
              <span>${job.progress ? job.progress + '%' : 'Đang xử lý...'}</span>
            </div>
          ` : ''}

          ${job.status === 'FAILED' ? `
            <div style="font-size:11.5px; color:#ef4444;">
              <i data-lucide="triangle-alert" class="icon-xs" style="margin-right:4px;"></i>${escapeHtml(job.error_message || 'Xảy ra lỗi trong quá trình xử lý')}
            </div>
          ` : ''}
        </div>
      </div>
    `;
  });

  html += '</div>';
  container.innerHTML = html;
  refreshIcons(container);
}

async function useAudioInTimeline(job) {
  if (!job || !job.cloud_file_id) {
    showToast('Tác vụ chưa có file âm thanh sẵn sàng trên Cloud.', 'error');
    return;
  }
  showToast('Đang đồng bộ tệp âm thanh về máy...', 'info');

  try {
    const res = await window.autoedit.cloud.cacheAndGetPath({
      id: job.cloud_file_id,
      filename: job.output_filename || 'narration.wav',
    });

    if (res && res.ok && res.localPath) {
      setAudioPathUI(res.localPath);
      showToast('Đã nạp file âm thanh vào Studio Timeline!', 'success');
      switchTab('studio');
    } else {
      showToast('Không thể đồng bộ tệp âm thanh: ' + (res?.error || 'Lỗi tải tệp'), 'error');
    }
  } catch (err) {
    console.error('Error in useAudioInTimeline:', err);
    showToast('Lỗi khi nạp âm thanh: ' + (err.message || err), 'error');
  }
}

async function cancelDesktopTtsJob(jobId) {
  if (!confirm('Bạn có chắc muốn hủy tác vụ TTS này?')) return;
  try {
    const res = await window.autoedit.tts.cancelJob(jobId);
    if (res && (res.ok || res.success)) {
      showToast('Đã hủy tác vụ TTS!', 'success');
      await loadDesktopTtsJobs(true);
    } else {
      showToast('Không thể hủy tác vụ: ' + (res?.error || res?.message || 'Lỗi'), 'error');
    }
  } catch (err) {
    showToast('Lỗi kết nối khi hủy tác vụ: ' + (err.message || err), 'error');
  }
}

function openDesktopMyVoicesModal() {
  const modal = document.getElementById('modalDesktopMyVoices');
  showModal(modal);
  loadDesktopMyVoices();
}

async function loadDesktopMyVoices() {
  const listEl = document.getElementById('desktopMyVoicesList');
  if (!listEl) return;
  listEl.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-dim);">Đang tải danh sách giọng...</div>';

  try {
    const res = await window.autoedit.tts.listVoices();
    const payload = res.data || res;
    const all = payload.data || (payload.presets || []).concat(payload.custom || []);
    const custom = all.filter((v) => v.type === 'cloned' || v.type === 'CUSTOM' || (!v.is_preset && v.type !== 'preset'));

    if (custom.length === 0) {
      listEl.innerHTML = `
        <div style="text-align:center; padding:24px; color:var(--text-dim);">
          <div style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:4px;">Chưa có mẫu giọng nhân bản nào</div>
          <div style="font-size:11.5px; margin-bottom:12px;">Nhân bản giọng nói của bạn từ file audio mẫu 5-30 giây.</div>
          <button type="button" class="btn-action-primary" style="font-size:11.5px; padding:4px 10px;" onclick="hideModal(document.getElementById('modalDesktopMyVoices')); openDesktopCloneVoiceModal();">
            + Nhân Bản Giọng Mới
          </button>
        </div>
      `;
      return;
    }

    let html = '';
    custom.forEach((cv) => {
      html += `
        <div style="background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:6px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <div>
            <div style="display:flex; align-items:center; gap:6px; margin-bottom:2px;">
              <span style="font-size:13px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:4px;"><i data-lucide="audio-waveform" class="icon-xs" style="color:var(--brand);"></i>${escapeHtml(cv.name)}</span>
              <span class="badge" style="font-size:10px;">${(cv.language || cv.primary_language || 'en').toUpperCase()}</span>
            </div>
            <div style="font-size:11px; color:var(--text-dim); font-family:monospace;">ID: ${cv.id}</div>
          </div>
          <button type="button" class="btn-subtle" style="color:#ef4444; font-size:11px; padding:3px 8px;" onclick="deleteDesktopMyVoice('${cv.id}', '${escapeHtml(cv.name)}')">
            Xóa
          </button>
        </div>
      `;
    });
    listEl.innerHTML = html;
  } catch (err) {
    listEl.innerHTML = '<div style="text-align:center; padding:20px; color:#ef4444;">Không thể tải danh sách giọng.</div>';
  }
}

async function deleteDesktopMyVoice(voiceId, voiceName) {
  if (!confirm(`Bạn có chắc muốn xóa mẫu giọng "${voiceName}"?`)) return;
  try {
    const res = await window.autoedit.tts.deleteVoice(voiceId);
    if (res && (res.ok || res.success)) {
      showToast('Đã xóa mẫu giọng thành công!', 'success');
      loadDesktopMyVoices();
      loadDesktopTtsVoices();
    } else {
      showToast('Không thể xóa giọng: ' + (res?.error || res?.message || 'Lỗi'), 'error');
    }
  } catch (err) {
    showToast('Lỗi khi xóa mẫu giọng: ' + (err.message || err), 'error');
  }
}

function openDesktopCloneVoiceModal() {
  desktopTtsState.cloneAudioFilePath = null;
  const inpName = document.getElementById('inpDesktopCloneName');
  const inpAudio = document.getElementById('inpDesktopCloneRefAudio');
  const inpTranscript = document.getElementById('inpDesktopCloneTranscript');
  const chkConsent = document.getElementById('chkDesktopCloneConsent');

  if (inpName) inpName.value = '';
  if (inpAudio) inpAudio.value = '';
  if (inpTranscript) inpTranscript.value = '';
  if (chkConsent) chkConsent.checked = false;

  showModal(document.getElementById('modalDesktopCloneVoice'));
}

async function submitDesktopCloneVoice() {
  const chkConsent = document.getElementById('chkDesktopCloneConsent');
  if (!chkConsent?.checked) {
    showToast('Vui lòng tích cam kết bản quyền mẫu giọng!', 'error');
    return;
  }

  const inpName = document.getElementById('inpDesktopCloneName');
  const name = (inpName?.value || '').trim();
  if (!name) {
    showToast('Vui lòng nhập tên gợi nhớ cho mẫu giọng!', 'error');
    return;
  }

  const selLang = document.getElementById('selDesktopCloneLang');
  const language = selLang?.value || 'vi';
  const inpTranscript = document.getElementById('inpDesktopCloneTranscript');
  const refText = (inpTranscript?.value || '').trim();

  const localAudioPath = desktopTtsState.cloneAudioFilePath;
  if (!localAudioPath) {
    showToast('Vui lòng chọn tệp âm thanh mẫu từ máy tính!', 'error');
    return;
  }

  const btnSubmit = document.getElementById('btnSubmitModalDesktopCloneVoice');
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Đang tải file & xử lý...';
  }

  try {
    const spaceId = state.cloud.currentSpaceId || null;
    const upRes = await window.autoedit.cloud.uploadFile({
      localFilePath: localAudioPath,
      spaceId,
      folderId: null,
    });

    if (!upRes || !upRes.ok || (!upRes.fileId && !upRes.file?.id && !upRes.id)) {
      throw new Error(upRes?.error || 'Không thể tải tệp âm thanh lên Cloud');
    }

    const refFileId = upRes.fileId || upRes.file?.id || upRes.id;

    const voiceRes = await window.autoedit.tts.createVoice({
      name,
      language,
      reference_file_id: refFileId,
      reference_text: refText,
    });

    if (voiceRes && (voiceRes.ok || voiceRes.success || voiceRes.voice)) {
      showToast('Nhân bản giọng nói thành công!', 'success');
      hideModal(document.getElementById('modalDesktopCloneVoice'));
      await loadDesktopTtsVoices();
    } else {
      showToast('Lỗi tạo hồ sơ giọng: ' + (voiceRes?.error || voiceRes?.message || 'Lỗi không xác định'), 'error');
    }
  } catch (err) {
    console.error('Error submitting desktop clone voice:', err);
    showToast('Lỗi: ' + (err.message || err), 'error');
  } finally {
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Khởi Tạo & Lưu Mẫu Giọng';
    }
  }
}


window.addEventListener('DOMContentLoaded', async () => {
  // Set initial default project name
  DOM.inpProjectName.placeholder = `Để trống sẽ tự đặt: ${generateDefaultProjectName()}`;

  // Attach Navigation Listeners
  DOM.navBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      switchTab(btn.dataset.tab);
    });
  });

  DOM.btnQuickNewProject.addEventListener('click', () => {
    switchTab('studio');
    DOM.inpProjectName.value = generateDefaultProjectName();
    DOM.inpProjectName.focus();
  });

  // ════════ CANONICAL DARK-ONLY THEME ENFORCEMENT & SILENT MIGRATION ════════
  // 2TOOLNE Desktop is permanently DARK MODE ONLY. Light/System themes are obsolete.
  function initTheme() {
    try {
      const oldTheme = localStorage.getItem('2toolne_theme');
      if (oldTheme && oldTheme !== 'dark') {
        localStorage.setItem('2toolne_theme', 'dark');
      }
      if (localStorage.getItem('appearance')) {
        localStorage.removeItem('appearance');
      }
    } catch (_) {}

    document.documentElement.setAttribute('data-theme', 'dark');
    if (document.documentElement.style) {
      document.documentElement.style.colorScheme = 'dark';
    }
  }
  initTheme();

  // Initial render of Lucide vector icons
  refreshIcons();

  // Initialize i18n Localization (GAP-13)
  if (window.i18n) {
    window.i18n.init();
    if (DOM.selAppLanguage) {
      DOM.selAppLanguage.value = window.i18n.currentLang;
      DOM.selAppLanguage.addEventListener('change', (e) => {
        applyCurrentLanguage(e.target.value);
      });
    }
    applyCurrentLanguage(window.i18n.currentLang);
  }

  // Initialize Cloud Explorer (Priority 4)
  initCloudExplorer();

  // Initialize Team & Workspace (Priority 6)
  initTeamWorkspace();

  // Initialize Scoped AI Access Keys (Phase 1)
  initAiKeys();

  // Initialize Input Bundle Engine (Phase 2)
  initInputBundle();

  // Initialize Pipeline Queue V2 (Phase 3)
  initPipelineQueueV2();

  // Initialize Google Flow Browser (Phase 4)
  initFlowBrowser();

  // Initialize Desktop Auto-Update (Final Product Gate)
  initAppUpdater();

  // Initialize Desktop Text-to-Speech & Voice Cloning Studio (Phase 7 & 8)
  initDesktopTts();

  // Load persistent store
  await loadStoredState();
  updateQueueBadge();

  // Check Background Statuses
  await Promise.allSettled([
    checkLicenseStatus(),
    checkCapCutStatus(),
    refreshAccountState(),
    refreshWalletBalance(),
  ]);

  // Connect Authoritative License State
  if (window.autoedit && window.autoedit.onLicenseChanged) {
    window.autoedit.onLicenseChanged((data) => {
      updateLicenseUI(data);
    });
  }

  // Connect Authoritative Build Queue (Queue A)
  if (window.autoedit && window.autoedit.onBuildQueueUpdate) {
    window.autoedit.onBuildQueueUpdate((data) => {
      renderBuildQueueTableFromState(data);
    });
  }

  // Connect Authoritative Render Queue (Queue B)
  if (window.autoedit && window.autoedit.onRenderQueueUpdate) {
    window.autoedit.onRenderQueueUpdate((data) => {
      renderQueueTableFromState(data);
      checkRenderJobStatusChanges(data);
    });
  }

  // Initial fetch of real queues
  await Promise.allSettled([
    refreshBuildQueueUI(),
    refreshRenderQueueUI(),
  ]);

  // Heartbeat polling for queue when queue view is active or workers are running
  setInterval(() => {
    if (state.currentTab === 'queue' || state.renderQueue.status === 'RUNNING' || state.buildQueue.status === 'RUNNING') {
      refreshBuildQueueUI();
      refreshRenderQueueUI();
    }
  }, 2000);
});
