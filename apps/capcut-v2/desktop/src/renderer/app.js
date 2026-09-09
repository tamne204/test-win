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

const _recentToasts = new Map();

function showToast(message, type = 'info', duration = 3500) {
  if (!message) return;
  const now = Date.now();
  const lastShown = _recentToasts.get(message);
  if (lastShown && (now - lastShown) < 2000) {
    return; // Suppress duplicate notifications within 2 seconds
  }
  _recentToasts.set(message, now);
  if (_recentToasts.size > 50) {
    for (const [k, v] of _recentToasts.entries()) {
      if (now - v > 5000) _recentToasts.delete(k);
    }
  }

  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = 'position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:99999;pointer-events:none;';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.style.cssText = 'pointer-events:auto;min-width:260px;max-width:400px;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,0.5);display:flex;align-items:center;gap:10px;animation:slideInToast 0.25s ease;';

  if (type === 'success') {
    toast.style.background = '#064e3b';
    toast.style.color = '#34d399';
    toast.style.border = '1px solid rgba(52,211,153,0.3)';
    toast.innerHTML = `<span>✅</span><div>${escapeHtml(message)}</div>`;
  } else if (type === 'warning') {
    toast.style.background = '#451a03';
    toast.style.color = '#fbbf24';
    toast.style.border = '1px solid rgba(251,191,36,0.3)';
    toast.innerHTML = `<span>⚠️</span><div>${escapeHtml(message)}</div>`;
  } else if (type === 'error') {
    toast.style.background = '#450a0a';
    toast.style.color = '#f87171';
    toast.style.border = '1px solid rgba(248,113,113,0.3)';
    toast.innerHTML = `<span>⚠️</span><div>${escapeHtml(message)}</div>`;
  } else {
    toast.style.background = '#0f172a';
    toast.style.color = '#38bdf8';
    toast.style.border = '1px solid rgba(56,189,248,0.3)';
    toast.innerHTML = `<span>ℹ️</span><div>${escapeHtml(message)}</div>`;
  }

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.4s ease';
    setTimeout(() => toast.remove(), 400);
  }, duration);
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
  btnSubmitCreateTeam: document.getElementById('btnSubmitCreateTeam'),
  inpCreateTeamName: document.getElementById('inpCreateTeamName'),
  createTeamError: document.getElementById('createTeamError'),

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
  floatingProjectTitle: document.getElementById('floatingProjectTitle'),
  floatingSceneActivity: document.getElementById('floatingSceneActivity'),
  floatingProgressBar: document.getElementById('floatingProgressBar'),
  floatingProgressPercent: document.getElementById('floatingProgressPercent'),
  floatingFlowAccountBadge: document.getElementById('floatingFlowAccountBadge'),
  btnFloatingPauseResume: document.getElementById('btnFloatingPauseResume'),
  btnFloatingViewQueue: document.getElementById('btnFloatingViewQueue'),
  pipelineJobsList: document.getElementById('pipelineJobsList'),
  pipelineActiveCountBadge: document.getElementById('pipelineActiveCountBadge'),
  btnClearCompletedPipelineJobs: document.getElementById('btnClearCompletedPipelineJobs'),

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

  // Flow Browser DOM (Phase 4)
  flowProfileSelect: document.getElementById('flowProfileSelect'),
  btnFlowAddProfile: document.getElementById('btnFlowAddProfile'),
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
  const meta = VIEW_METADATA[tabId]?.[lang] || VIEW_METADATA[tabId]?.vi || { title: '2toolne AutoEdit', sub: '' };
  DOM.viewTitle.textContent = meta.title;
  DOM.viewSub.textContent = meta.sub;

  if (tabId === 'projects') renderProjectsGrid();
  if (tabId === 'queue') {
    refreshBuildQueueUI();
    refreshRenderQueueUI();
  }
  if (tabId === 'cloud') {
    onOpenCloudTab();
  }
  if (tabId === 'account') {
    loadAiKeys();
  }
  const mainScroll = document.querySelector('.main-content-scroll');
  if (tabId === 'flow') {
    if (mainScroll) mainScroll.classList.add('flow-active');
    onOpenFlowTab();
  } else {
    if (mainScroll) mainScroll.classList.remove('flow-active');
    if (window.autoedit?.flow?.hideView) {
      window.autoedit.flow.hideView();
    }
  }
}

// -----------------------------------------------------------------------------
// Modal Dialog Controller (Guaranteed Dismissibility with ✕ and ESC)
// -----------------------------------------------------------------------------
function showModal(modalEl) {
  if (modalEl) modalEl.style.display = 'flex';
}

function hideModal(modalEl) {
  if (modalEl) modalEl.style.display = 'none';
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

// ESC Key Closes Any Active Modal
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') hideAllModals();
});

// -----------------------------------------------------------------------------
// Media Files Importer (Drag, Drop, Archive, Folder)
// -----------------------------------------------------------------------------
async function handleImportPaths(paths) {
  if (!paths || !paths.length) return;

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

function renderMediaGrid() {
  // Sort naturally by filename / numeric index
  state.mediaList.sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

  DOM.mediaCount.textContent = state.mediaList.length;
  DOM.btnClearMedia.style.display = state.mediaList.length > 0 ? 'inline-block' : 'none';

  updateMissingBanner();

  if (state.mediaList.length === 0) {
    DOM.mediaGrid.innerHTML = '<div class="empty-media-hint">Chưa có ảnh nào được chọn.</div>';
    return;
  }

  DOM.mediaGrid.innerHTML = '';
  state.mediaList.forEach((imgPath, idx) => {
    const card = document.createElement('div');
    card.className = 'media-thumb-card';
    card.title = imgPath;

    const img = document.createElement('img');
    img.className = 'media-thumb-img';
    img.src = `file://${imgPath}`;
    img.alt = `Ảnh ${idx + 1}`;

    const delBtn = document.createElement('button');
    delBtn.className = 'btn-thumb-remove';
    delBtn.textContent = '✕';
    delBtn.title = 'Xóa ảnh này';
    delBtn.onclick = (e) => {
      e.stopPropagation();
      state.mediaList.splice(idx, 1);
      renderMediaGrid();
    };

    card.appendChild(img);
    card.appendChild(delBtn);
    DOM.mediaGrid.appendChild(card);
  });
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
  const files = Array.from(e.dataTransfer.files).map((f) => f.path);
  await handleImportPaths(files);
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
  DOM.btnStartAlign.innerHTML = '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)';
});

DOM.tabModeSTT?.addEventListener('click', () => {
  state.subtitleWorkflowMode = 'stt';
  DOM.tabModeSTT.classList.add('active');
  DOM.tabModeFA.classList.remove('active');
  DOM.inpScriptText.disabled = true;
  DOM.inpScriptText.placeholder = 'Chế độ AutoSub: Whisper sẽ tự động nhận diện tiếng nói và tạo phụ đề từ tệp âm thanh (không cần kịch bản văn bản).';
  DOM.btnStartAlign.innerHTML = '🎙️ BẮT ĐẦU TỰ ĐỘNG TẠO PHỤ ĐỀ (AutoSub)';
});

// Start Alignment / AutoSub Action
DOM.btnStartAlign.addEventListener('click', async () => {
  const isAutoSub = state.subtitleWorkflowMode === 'stt';
  const script = isAutoSub ? '' : DOM.inpScriptText.value.trim();

  if (!isAutoSub && !script) {
    showAlert('Vui lòng dán hoặc nhập kịch bản lời thoại vào ô kịch bản, hoặc chuyển sang tab "🎙️ 2. Tự Động Tạo Sub (AutoSub)" để nhận diện không cần kịch bản.', 'Thiếu Kịch Bản');
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
      showToast('Đã áp dụng phong cách Tiêu Chuẩn (4.0-6.5s, 25/25/25/25)', 'info', 2000);
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
      showToast('Đã áp dụng phong cách Êm Đềm / Trầm Lặng (5.5-8.5s, 40/40/10/10)', 'info', 2000);
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
      showToast('Đã áp dụng phong cách Nhanh / Sôi Động (2.5-4.5s, 15/15/35/35)', 'info', 2000);
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
  if (analysis && analysis.missing.length > 0) {
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
  DOM.btnGenerateProject.innerHTML = '<span>⏳</span> Đang tạo dự án...';

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
      const errDetail = res?.primary_message || res?.error || 'Không thể tạo dự án CapCut lúc này.';
      throw new Error(errDetail);
    }
  } catch (err) {
    hideModal(DOM.modalProgress);
    const friendlyMsg = (err.message && !err.message.includes('Traceback') && !err.message.includes('object'))
      ? err.message
      : 'Không thể tạo dự án CapCut lúc này. Vui lòng kiểm tra lại tệp tin đầu vào hoặc thử lại.';
    showAlert(`Lỗi tạo dự án:\n${friendlyMsg}`, 'Thất Bại');
    showToast(friendlyMsg, 'error', 4000);
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
      DOM.buildQueueStatusBadge.textContent = '▶️ Đang Xử Lý';
      DOM.buildQueueStatusBadge.style.color = '#34d399';
      DOM.buildQueueStatusBadge.style.borderColor = 'rgba(52,211,153,0.3)';
    } else if (bStatus === 'STOPPING') {
      DOM.buildQueueStatusBadge.textContent = '⏹️ Đang Dừng Dần...';
      DOM.buildQueueStatusBadge.style.color = '#facc15';
      DOM.buildQueueStatusBadge.style.borderColor = 'rgba(250,204,21,0.3)';
    } else {
      DOM.buildQueueStatusBadge.textContent = '⚪ Đang Chờ';
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
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)">⏳ Đang Chờ</span>';
    } else if (st === 'PROJECT_READY') {
      statusBadge = '<span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3)">✅ Dự Án Sẵn Sàng</span>';
    } else if (st === 'FAILED') {
      statusBadge = '<span class="status-badge" style="color:#f87171;border-color:rgba(248,113,113,0.3)">❌ Lỗi</span>';
    } else if (st === 'CANCELLED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)">⏹️ Đã Hủy</span>';
    } else if (st === 'WAITING_SRT_REVIEW') {
      statusBadge = '<span class="status-badge" style="color:#fb923c;border-color:rgba(251,146,60,0.3)">📝 Chờ Duyệt SRT</span>';
    } else {
      statusBadge = `<span class="status-badge" style="color:#facc15;border-color:rgba(250,204,21,0.3)">⚡ ${escapeHtml(job.current_step || 'Đang xử lý...')}</span>`;
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
        <button class="btn-action-primary" style="padding:2px 8px; font-size:11px;" onclick="buildSingleProject('${safeJobId}')">⚡ Tạo Dự Án</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="cancelBuildJob('${safeJobId}')">✕ Hủy</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')">🗑️ Xóa</button>
      `;
    } else if (st === 'PROJECT_READY') {
      const draftDir = job.result?.final_draft_dir || '';
      const safeDraftDir = draftDir.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      const safeProjName = (job.project_name || 'project').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      actionsHtml = `
        <button class="btn-action-primary" style="padding:2px 8px; font-size:11px;" onclick="openDraftInCapCut('${safeDraftDir}')">🎬 Mở CapCut</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="renderDraftNow('${safeDraftDir}', '${safeProjName}')">⚡ Render Ngay</button>
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="addDraftToRenderQueue('${safeDraftDir}', '${safeProjName}')">➕ Render Queue</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')">🗑️</button>
      `;
    } else if (st === 'FAILED' || st === 'CANCELLED') {
      actionsHtml = `
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="retryBuildJob('${safeJobId}')">🔄 Thử Lại</button>
        <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="removeBuildJob('${safeJobId}')">🗑️ Xóa</button>
      `;
    } else {
      actionsHtml = `
        <button class="btn-subtle" style="padding:2px 8px; font-size:11px;" onclick="cancelBuildJob('${safeJobId}')">✕ Hủy</button>
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
      DOM.queueStatusBadge.textContent = '▶️ Đang Xử Lý';
      DOM.queueStatusBadge.style.color = '#34d399';
      DOM.queueStatusBadge.style.borderColor = 'rgba(52,211,153,0.3)';
    } else if (qStatus === 'PAUSED') {
      DOM.queueStatusBadge.textContent = '⏸️ Đang Tạm Dừng';
      DOM.queueStatusBadge.style.color = '#fb923c';
      DOM.queueStatusBadge.style.borderColor = 'rgba(251,146,60,0.3)';
    } else if (qStatus === 'STOPPING') {
      DOM.queueStatusBadge.textContent = '⏹️ Đang Dừng Dần...';
      DOM.queueStatusBadge.style.color = '#facc15';
      DOM.queueStatusBadge.style.borderColor = 'rgba(250,204,21,0.3)';
    } else {
      DOM.queueStatusBadge.textContent = '⚪ Đang Chờ';
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
        <td colspan="6">Hàng đợi xuất video đang trống. Hãy bấm "⚡ Render Ngay" hoặc "➕ Thêm Hàng Đợi" từ tab Dự Án!</td>
      </tr>
    `;
    return;
  }

  state.renderQueue.jobs.forEach((job) => {
    const tr = document.createElement('tr');
    const st = job.status;

    let statusBadge = '';
    if (st === 'QUEUED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)">⏳ Đang Chờ</span>';
    } else if (st === 'DONE') {
      statusBadge = '<span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3)">✅ Hoàn Thành</span>';
    } else if (st === 'FAILED') {
      statusBadge = '<span class="status-badge" style="color:#f87171;border-color:rgba(248,113,113,0.3)">❌ Lỗi</span>';
    } else if (st === 'CANCELLED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)">⏹️ Đã Hủy</span>';
    } else if (st === 'SKIPPED') {
      statusBadge = '<span class="status-badge" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)">⏭️ Bỏ Qua</span>';
    } else if (st.includes('PAUSED')) {
      statusBadge = '<span class="status-badge" style="color:#fb923c;border-color:rgba(251,146,60,0.3)">⏸️ Tạm Dừng</span>';
    } else if (st === 'VERIFYING_OUTPUT') {
      statusBadge = '<span class="status-badge" style="color:#a78bfa;border-color:rgba(167,139,250,0.3)">🔍 Xác Thực MP4</span>';
    } else {
      statusBadge = '<span class="status-badge" style="color:#facc15;border-color:rgba(250,204,21,0.3)">⚡ Đang Xuất...</span>';
    }

    // Stage text (No fake percentages, honest FSM description)
    let stageDisplay = '';
    if (st === 'DONE') stageDisplay = '✅ Video MP4 toàn vẹn';
    else if (st === 'FAILED') stageDisplay = '❌ Dừng lại do lỗi';
    else if (st === 'QUEUED') stageDisplay = 'Chờ đến lượt';
    else if (st === 'PRECHECK') stageDisplay = '🔍 Kiểm tra thư mục draft';
    else if (st === 'STARTING_CAPCUT') stageDisplay = '🚀 Kích hoạt CapCut Desktop';
    else if (st === 'OPENING_PROJECT') stageDisplay = '🎬 Mở dự án trong CapCut';
    else if (st === 'TRIGGERING_EXPORT') stageDisplay = '⌨️ Gửi phím xuất (Ctrl+E)';
    else if (st === 'CONFIRMING_EXPORT') stageDisplay = '🔘 Xác nhận xuất video (Enter)';
    else if (st === 'RENDERING') stageDisplay = '⚡ CapCut đang render ghi file...';
    else if (st === 'VERIFYING_OUTPUT') stageDisplay = '🧪 Kiểm tra ffprobe container';
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
        <button class="btn-subtle" onclick="window.openOutputFile('${escapePath(job.output_path)}')">🎬 Mở File</button>
        <button class="btn-subtle" onclick="window.openOutputFolder('${escapePath(job.output_path)}')">📁 Thư Mục</button>
      `;
    } else if (st === 'FAILED') {
      actionBtns = `
        <button class="btn-subtle" onclick="window.retryRenderJob('${job.job_id}')">🔄 Thử Lại</button>
        <button class="btn-subtle btn-danger" onclick="window.showJobError('${job.job_id}')">ℹ️ Lỗi</button>
      `;
    } else if (st === 'QUEUED' || st.includes('PAUSED')) {
      actionBtns = `
        <button class="btn-subtle" onclick="window.skipRenderJob('${job.job_id}')">⏭️ Bỏ Qua</button>
        <button class="btn-subtle btn-danger" onclick="window.cancelRenderJob('${job.job_id}')">✕ Hủy</button>
      `;
    } else {
      actionBtns = `
        <button class="btn-subtle btn-danger" onclick="window.cancelRenderJob('${job.job_id}')">✕ Dừng</button>
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
  DOM.renderResultTitle.textContent = '🎬 Xuất Video Thành Công';
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
  DOM.renderResultTitle.textContent = '⚠️ Lỗi Xuất Video CapCut';
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

// Queue Control Buttons
DOM.btnStartQueue.addEventListener('click', async () => {
  if (!window.autoedit?.controlRenderQueue) return;
  try {
    await window.autoedit.controlRenderQueue({ action: 'resume' });
    showToast('Đã kích hoạt chạy hàng đợi render!', 'success', 2500);
    await refreshRenderQueueUI();
  } catch (err) {
    showAlert('Lỗi khởi động hàng đợi: ' + err.message, 'Lỗi', true);
  }
});

DOM.btnPauseQueue.addEventListener('click', async () => {
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

DOM.btnClearQueue.addEventListener('click', async () => {
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
// Projects Grid View
// -----------------------------------------------------------------------------
let targetProjectToDelete = null;

function renderProjectsGrid() {
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
          <div style="font-size:36px;margin-bottom:12px;">📁</div>
          <div style="font-size:15px;color:#cbd5e1;font-weight:500;margin-bottom:14px;">Bạn chưa tạo dự án nào.</div>
          <button type="button" class="btn-action-primary" onclick="switchTab('studio')" style="margin:0 auto;display:inline-flex;">🎬 Tạo dự án đầu tiên</button>
        </div>
      `;
    } else {
      DOM.projectsGrid.innerHTML = `<div class="empty-projects-hint">Không tìm thấy dự án nào khớp với từ khóa "${escapeHtml(searchTerm)}".</div>`;
    }
    return;
  }

  DOM.projectsGrid.innerHTML = '';
  filteredProjects.forEach((proj) => {
    const card = document.createElement('div');
    card.className = 'project-card';

    const dateStr = new Date(proj.createdAt || Date.now()).toLocaleDateString('vi-VN');
    const safeProjId = escapeHtml(proj.id || '');
    const safeDraftDir = (proj.draftDir || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const safeProjName = (proj.name || 'project').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const durationLabel = proj.durationS ? `${Math.round(proj.durationS)}s` : '--';

    card.innerHTML = `
      <div class="project-card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
          <h4 class="project-card-title">${escapeHtml(proj.name)}</h4>
          <span class="status-badge" style="color:#34d399;border-color:rgba(52,211,153,0.3);font-size:11px;">✅ Đã Tạo</span>
        </div>
        <div class="project-card-meta">
          <span>Tỷ lệ: ${escapeHtml(proj.aspectRatio || '9:16')}</span> • 
          <span>${proj.imageCount || 0} ảnh</span> • 
          <span>⏱️ ${durationLabel}</span> • 
          <span>${dateStr}</span>
        </div>
        <div class="project-card-actions" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center;">
          <button type="button" class="btn-action-primary" onclick="openDraftInCapCut('${safeDraftDir}')">🎬 Mở CapCut</button>
          <button type="button" class="btn-subtle" onclick="renderDraftNow('${safeDraftDir}', '${safeProjName}')" title="Xuất video trực tiếp bằng CapCut">⚡ Xuất Video</button>
          <button type="button" class="btn-subtle" onclick="addDraftToRenderQueue('${safeDraftDir}', '${safeProjName}')" title="Thêm vào hàng đợi xuất video">➕ Thêm Hàng Đợi Xuất</button>
          <button type="button" class="btn-subtle" onclick="loadProjectToStudio('${safeProjId}')" title="Nạp lại vào Studio để chỉnh sửa">✏️ Nạp vào Studio</button>
          <button type="button" class="btn-subtle" onclick="openDraftFolder('${safeDraftDir}')" title="Mở thư mục dự án">📁 Thư mục</button>
          <button type="button" class="btn-subtle btn-danger" style="margin-left:auto;padding:2px 8px;font-size:11px;" onclick="promptDeleteProject('${safeProjId}')" title="Xóa dự án">🗑️ Xóa</button>
        </div>
      </div>
    `;
    DOM.projectsGrid.appendChild(card);
  });
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

    if (deleteFolder && proj.draftDir && window.autoedit?.deleteDraftFolder) {
      try {
        const delRes = await window.autoedit.deleteDraftFolder(proj.draftDir);
        if (!delRes || !delRes.ok) {
          console.warn('Draft deletion failed or protected:', delRes?.error);
        }
      } catch (err) {
        console.warn('Error deleting draft directory:', err);
      }
    }

    state.projects = state.projects.filter((p) => p.id !== proj.id);
    await saveProjects();
    renderProjectsGrid();
    showToast(`Đã xóa dự án "${proj.name}"!`, 'info', 3000);
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
    showToast(`Đang khởi động xuất video cho "${projName}"...`, 'info', 3000);

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
  await loadStoredState();
  renderProjectsGrid();
  showToast('Đã làm mới danh sách dự án!', 'info', 1500);
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
  showToast('Đã đặt lại thư mục xuất về mặc định.', 'info', 2500);
});

DOM.btnExportDiagnostics?.addEventListener('click', async () => {
  if (!window.autoedit?.exportDiagnosticBundle) return;
  try {
    showToast('Đang tạo gói chẩn đoán hệ thống...', 'info', 3000);
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
          DOM.btnCheckUpdate.textContent = '⏳ Đang kiểm tra...';
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
          DOM.btnCheckUpdate.textContent = '🔄 Kiểm Tra Cập Nhật';
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
          DOM.btnCheckUpdate.textContent = '🔄 Kiểm Tra Lại';
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `⚡ Có phiên bản mới: v${availableUpdate?.version}!`;
          DOM.txtUpdateStatus.style.color = '#38bdf8';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) {
          DOM.boxUpdateActions.style.display = 'flex';
          if (DOM.btnDownloadUpdate) {
            DOM.btnDownloadUpdate.style.display = 'inline-flex';
            DOM.btnDownloadUpdate.disabled = false;
            DOM.btnDownloadUpdate.textContent = `📥 Tải Bản Cập Nhật (v${availableUpdate?.version})`;
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
          DOM.txtUpdateStatus.style.color = '#38bdf8';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'block';
        if (DOM.boxUpdateActions) DOM.boxUpdateActions.style.display = 'none';
        break;

      case 'DOWNLOADED':
      case 'INSTALL_READY':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.textContent = '🔄 Kiểm Tra Cập Nhật';
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = '✅ Đã tải xong bản cập nhật. Sẵn sàng cài đặt!';
          DOM.txtUpdateStatus.style.color = 'var(--success)';
        }
        if (DOM.boxUpdateProgress) DOM.boxUpdateProgress.style.display = 'none';
        if (DOM.boxUpdateActions) {
          DOM.boxUpdateActions.style.display = 'flex';
          if (DOM.btnDownloadUpdate) DOM.btnDownloadUpdate.style.display = 'none';
          if (DOM.btnInstallUpdate) {
            DOM.btnInstallUpdate.style.display = 'inline-flex';
            DOM.btnInstallUpdate.disabled = false;
            DOM.btnInstallUpdate.textContent = '🚀 Khởi Động Lại & Cập Nhật';
          }
          if (DOM.btnDismissUpdate) DOM.btnDismissUpdate.style.display = 'inline-flex';
        }
        showToast('Bản cập nhật đã tải xong và sẵn sàng cài đặt.', 'success', 5000);
        break;

      case 'INSTALLING':
        if (DOM.btnCheckUpdate) DOM.btnCheckUpdate.disabled = true;
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = '⚙️ Đang cài đặt bản cập nhật và khởi động lại...';
          DOM.txtUpdateStatus.style.color = '#f59e0b';
        }
        if (DOM.btnInstallUpdate) {
          DOM.btnInstallUpdate.disabled = true;
          DOM.btnInstallUpdate.textContent = '⏳ Đang cài đặt...';
        }
        break;

      case 'ERROR':
        if (DOM.btnCheckUpdate) {
          DOM.btnCheckUpdate.disabled = false;
          DOM.btnCheckUpdate.textContent = '🔄 Thử Lại';
        }
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `❌ Lỗi cập nhật: ${lastError || 'Không xác định'}`;
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
        <button type="button" class="btn-link-danger" data-idx="${idx}" style="font-size: 11px; cursor: pointer; padding: 0 4px;">✕</button>
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
  const dropped = Array.from(e.dataTransfer.files || []).map(f => f.path).filter(Boolean);
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
      if (res?.code === 'INSUFFICIENT_TOKENS') {
        showAlert(res.error || 'Số dư token không đủ để thực hiện upscale.', 'Thiếu Token');
        if (typeof refreshWalletBalance === 'function') await refreshWalletBalance();
        if (DOM.upscaleProgressWrap) DOM.upscaleProgressWrap.style.display = 'none';
        return;
      }
      throw new Error(res?.error || 'Lỗi không xác định khi xử lý upscale.');
    }
  } catch (err) {
    showAlert(`Lỗi trong quá trình phóng to ảnh: ${err.message}`, 'Lỗi Upscale');
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
  DOM.btnSubmitModalLogin.textContent = '⏳ Đang đăng nhập...';

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

// Topup Tokens Link
DOM.btnTopupToken?.addEventListener('click', async () => {
  const portalUrl = 'https://www.2tamne.site/account/wallet';
  if (window.autoedit?.openPath) {
    await window.autoedit.openPath(portalUrl);
  } else {
    window.open(portalUrl, '_blank');
  }
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
  if (isFolder) return '📁';
  const name = (item.name || '').toLowerCase();
  if (/\.(png|jpe?g|webp|bmp|tiff|gif|svg)$/i.test(name)) return '🖼️';
  if (/\.(mp3|wav|m4a|aac|flac|ogg|wma)$/i.test(name)) return '🎵';
  if (/\.(mp4|mov|mkv|avi|webm|flv)$/i.test(name)) return '🎬';
  if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return '📦';
  if (/\.(txt|srt|ass|vtt|json|csv|pdf|docx?)$/i.test(name)) return '📄';
  return '📎';
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
    btn.textContent = (idx === 0 ? '☁️ ' : '📁 ') + crumb.name;
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
          <span class="cloud-item-icon">📁</span>
          <span class="cloud-item-title" title="${escapeHtml(folder.name)}">${escapeHtml(folder.name)}</span>
          ${folder.has_share ? '<span class="cloud-share-badge" title="Đang có liên kết chia sẻ">🔗</span>' : ''}
        </div>
      </td>
      <td><span style="color:var(--text-dim);">--</span></td>
      <td><span style="color:var(--text-dim);">Thư mục</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(folder.updated_at || folder.created_at)}</span></td>
      <td>
        <div class="cloud-table-actions">
          <button type="button" class="btn-cloud-mini btn-open-folder" title="Mở thư mục">Mở</button>
          <button type="button" class="btn-cloud-mini btn-share-item" title="Chia sẻ liên kết">🔗</button>
          <button type="button" class="btn-cloud-mini btn-rename-item" title="Đổi tên">✏️</button>
          <button type="button" class="btn-cloud-mini btn-move-item" title="Di chuyển">📦</button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-trash-item" title="Xóa vào thùng rác">🗑️</button>
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
          ${file.has_share ? '<span class="cloud-share-badge" title="Đang có liên kết chia sẻ">🔗</span>' : ''}
        </div>
      </td>
      <td><span style="font-family:monospace;font-size:11.5px;">${formatBytes(file.size_bytes)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${escapeHtml(ext)}</span></td>
      <td><span style="font-size:11.5px;color:var(--text-muted);">${formatCloudDate(file.updated_at || file.created_at)}</span></td>
      <td>
        <div class="cloud-table-actions">
          <button type="button" class="btn-cloud-mini btn-open-file" title="Mở trực tiếp">↗️</button>
          <button type="button" class="btn-cloud-mini btn-download-file" title="Tải về máy">📥</button>
          <button type="button" class="btn-cloud-mini btn-share-item" title="Chia sẻ liên kết">🔗</button>
          <button type="button" class="btn-cloud-mini btn-rename-item" title="Đổi tên">✏️</button>
          <button type="button" class="btn-cloud-mini btn-move-item" title="Di chuyển">📦</button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-trash-item" title="Xóa vào thùng rác">🗑️</button>
        </div>
      </td>
    `;

    tr.querySelector('.btn-open-file').onclick = async (e) => {
      e.stopPropagation();
      showToast(`Đang mở ${file.name}...`, 'info', 2000);
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
        showToast(`Bắt đầu tải về: ${file.name}`, 'info', 2500);
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
    DOM.selCloudMoveDestination.innerHTML = '<option value="">☁️ Thư mục gốc (Gốc không gian)</option>';
    // Populate folders except current folder itself (if target is folder)
    state.cloud.folders.forEach((f) => {
      if (isFolder && f.id == item.id) return;
      const opt = document.createElement('option');
      opt.value = f.id;
      opt.textContent = `📁 ${f.name}`;
      DOM.selCloudMoveDestination.appendChild(opt);
    });
  }

  showModal(DOM.modalCloudMove);
}

let currentDeleteTarget = null;
function openDeleteModal(item, isFolder, isPermanent) {
  currentDeleteTarget = { item, isFolder, isPermanent };
  if (DOM.cloudDeleteModalTitle) {
    DOM.cloudDeleteModalTitle.textContent = isPermanent ? '🗑️ Xác Nhận Xóa Vĩnh Viễn' : '🗑️ Chuyển Vào Thùng Rác';
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
    DOM.cloudShareItemIcon.textContent = isFolder ? '📁' : getFileIcon(item, false);
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
          DOM.inpCloudActiveShareUrl.value = activeShareRecord.share_url || `https://www.2tamne.site/share/${activeShareRecord.id}`;
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
    const icon = isFolder ? '📁' : getFileIcon(item, false);

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
          <button type="button" class="btn-cloud-mini btn-restore-item" title="Khôi phục">🔄 Khôi phục</button>
          <button type="button" class="btn-cloud-mini btn-danger-mini btn-delete-permanent" title="Xóa vĩnh viễn">❌ Xóa hẳn</button>
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
  showToast(`Bắt đầu tải lên: ${fileName}`, 'info', 2500);

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
        <button type="button" class="upload-item-cancel" title="Hủy tải lên">✕</button>
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
      statusEl.textContent = '✅ Hoàn tất';
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
      statusEl.textContent = '❌ ' + (data.error || 'Lỗi');
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
      DOM.cloudPickerTitle.textContent = options.title || (state.cloud.picker.mode === 'SELECT_IMAGES' ? '☁️ Chọn Ảnh Từ Cloud' : '☁️ Chọn Âm Thanh Từ Cloud');
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
    btn.textContent = (idx === 0 ? '☁️ ' : '📁 ') + crumb.name;
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
          <span class="cloud-item-icon">📁</span>
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
    showToast('Đang làm mới Cloud...', 'info', 1500);
    await Promise.all([loadCloudQuota(), state.cloud.isTrashOpen ? loadCloudTrash() : loadCloudFiles()]);
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
          if (f.path) startCloudUpload(f.path);
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
            DOM.inpCloudActiveShareUrl.value = res.share.share_url || `https://www.2tamne.site/share/${res.share.raw_token}`;
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
        showToast('Đã thu hồi liên kết chia sẻ thành công.', 'info', 2500);
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

  // Close Dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!DOM.workspaceSwitcherWrapper?.contains(e.target)) {
      closeWorkspaceDropdown();
    }
  });

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
    b?.addEventListener('click', () => hideModal(DOM.modalCreateTeam));
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

  // Modal 15: Create Team Form Submit
  DOM.btnSubmitCreateTeam?.addEventListener('click', handleCreateTeamSubmit);

  // Initial Sync
  syncWorkspaceState();
}

function toggleWorkspaceDropdown() {
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
    DOM.wsIcon.textContent = ws.space_type === 'TEAM' ? '👥' : '👤';
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
        <span>${isTeam ? '👥' : '👤'}</span>
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

      showToast(`Đã chuyển sang không gian: ${state.activeWorkspace.name}`, 'info');
    }
  } catch (e) {
    showToast(`Không thể chuyển không gian: ${e.message}`, 'error');
  }
}

// ── Modal 15: Create Team ──
function openCreateTeamModal() {
  if (DOM.inpCreateTeamName) DOM.inpCreateTeamName.value = '';
  if (DOM.createTeamError) DOM.createTeamError.style.display = 'none';
  showModal(DOM.modalCreateTeam);
  setTimeout(() => DOM.inpCreateTeamName?.focus(), 50);
}

async function handleCreateTeamSubmit() {
  const name = DOM.inpCreateTeamName?.value.trim();
  if (!name) {
    if (DOM.createTeamError) {
      DOM.createTeamError.textContent = 'Vui lòng nhập tên Đội Nhóm';
      DOM.createTeamError.style.display = 'block';
    }
    return;
  }

  if (DOM.btnSubmitCreateTeam) DOM.btnSubmitCreateTeam.disabled = true;

  try {
    const res = await window.autoedit.team.create(name);
    if (res && res.ok) {
      hideModal(DOM.modalCreateTeam);
      showToast(`Đã tạo không gian Team "${name}" thành công!`, 'success');
      await syncWorkspaceState();
      await refreshWalletBalance();
    } else {
      if (DOM.createTeamError) {
        DOM.createTeamError.textContent = res?.error || 'Không thể tạo Team';
        DOM.createTeamError.style.display = 'block';
      }
    }
  } catch (e) {
    if (DOM.createTeamError) {
      DOM.createTeamError.textContent = e.message || 'Lỗi khi tạo Team';
      DOM.createTeamError.style.display = 'block';
    }
  } finally {
    if (DOM.btnSubmitCreateTeam) DOM.btnSubmitCreateTeam.disabled = false;
  }
}

// ── Modal 14: Manage Team Members & Seats ──
async function openManageTeamModal() {
  const ws = state.activeWorkspace;
  if (!ws || ws.space_type !== 'TEAM') return;

  const teamId = ws.team_id || ws.owner_id;
  if (!teamId) return;

  if (DOM.teamModalTitle) DOM.teamModalTitle.textContent = `👥 Quản Lý Team: ${ws.name}`;
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
      showToast('Đã giải tán Team thành công.', 'info');
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
    if (DOM.btnToggleAiKeyVisibility) DOM.btnToggleAiKeyVisibility.textContent = '👁️';
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
    if (DOM.btnToggleAiKeyVisibility) DOM.btnToggleAiKeyVisibility.textContent = '👁️';
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
      if (DOM.btnToggleAiKeyVisibility) DOM.btnToggleAiKeyVisibility.textContent = '🔒';
    } else {
      inp.type = 'password';
      if (DOM.btnToggleAiKeyVisibility) DOM.btnToggleAiKeyVisibility.textContent = '👁️';
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

  // If script present, fill script box
  if (res.script && DOM.txtScript && !DOM.txtScript.value.trim()) {
    DOM.txtScript.value = res.script;
    updateScriptWordCount();
  }

  // Populate images into Studio Media Grid
  const validImages = [];
  for (const scene of res.scenes) {
    const matched = res.assets.mapped_scenes.find((m) => m.scene_id === scene.scene_id);
    if (matched && matched.image_path) {
      validImages.push({
        id: `bndl_img_${scene.scene_id}`,
        name: matched.image_file,
        path: matched.image_path,
        scene_id: scene.scene_id,
        slug: scene.slug,
      });
    }
  }

  if (validImages.length > 0) {
    state.images = validImages;
    renderMediaGrid();
    updateMediaCount();
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
        <div style="font-family:monospace; font-size:14px; font-weight:700; color:var(--accent-primary,#38bdf8); min-width:36px; padding:4px 8px; background:rgba(56,189,248,0.1); border-radius:4px; text-align:center;">
          ${escapeHtml(sc.scene_id)}
        </div>
        <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:13px; color:var(--text-main);">${escapeHtml(sc.slug)}</strong>
            <div style="display:flex; gap:6px;">
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; ${imgReady ? 'color:#34d399; background:rgba(52,211,153,0.15);' : 'color:#f87171; background:rgba(248,113,113,0.15);'}">
                Ảnh: ${imgReady ? '✓ Sẵn sàng' : '✕ Chưa có'}
              </span>
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; ${vidReady ? 'color:#34d399; background:rgba(52,211,153,0.15);' : 'color:#94a3b8; background:rgba(148,163,184,0.15);'}">
                Video: ${vidReady ? '✓ Sẵn sàng' : '⏳ Chờ tạo'}
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
      showToast('Đang khởi động Pipeline Queue V2...', 'info', 2000);
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

  DOM.btnImportBundleQueue?.addEventListener('click', handleOpenLocalBundleImport);
  DOM.btnImportCloudBundleQueue?.addEventListener('click', handleOpenCloudBundlePicker);

  DOM.btnRunAllPipelineJobs?.addEventListener('click', async () => {
    try {
      showToast('Đang khởi động chạy toàn bộ tác vụ trong hàng đợi...', 'info');
      const res = await window.autoedit.pipeline.runAll();
      if (res && res.started_job_id) {
        showToast(`Đang chạy tác vụ: ${res.started_job_id}`, 'success');
      } else if (res && !res.ok) {
        showToast(`Thông báo: ${res.error || 'Không có tác vụ nào đang chờ'}`, 'info');
      }
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } catch (err) {
      showToast(`Lỗi chạy hàng đợi: ${err.message}`, 'error');
    }
  });

  DOM.btnStopBuildQueue?.addEventListener('click', async () => {
    try {
      const summary = await window.autoedit.pipeline.getActiveSummary();
      if (summary?.summary?.has_active_job && summary.summary.can_pause) {
        await window.autoedit.pipeline.pause(summary.summary.job_id);
        showToast('Đã dừng tác vụ đang chạy.', 'info');
      } else {
        showToast('Không có tác vụ nào đang chạy.', 'info');
      }
      await refreshPipelineFloatingSummary();
      await refreshPipelineQueueUI();
    } catch (err) {
      showToast(err.message, 'error');
    }
  });

  const handleClearCompleted = async () => {
    try {
      await window.autoedit.pipeline.clearCompleted();
      await refreshPipelineQueueUI();
      showToast('Đã dọn dẹp các tác vụ đã hoàn tất.', 'info');
    } catch (e) {}
  };
  DOM.btnClearCompletedPipelineJobs?.addEventListener('click', handleClearCompleted);
  DOM.btnClearBuildQueue?.addEventListener('click', handleClearCompleted);

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

    let addedCount = 0;
    let dupeCount = 0;
    let failCount = 0;

    showToast(`Đang thêm ${toEnqueue.length} bundle vào Hàng Đợi Tạo Dự Án...`, 'info', 2000);

    for (const item of toEnqueue) {
      try {
        const res = await window.autoedit.pipeline.enqueue(item.bundleDir, { no_auto_start: true });
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
      showToast(`🎉 Đã thêm ${addedCount} Input Bundle vào Hàng Đợi Tạo Dự Án!`, 'success');
    }
    if (dupeCount > 0) {
      showToast(`⚠️ ${dupeCount} bundle bị bỏ qua do đã có trong hàng đợi.`, 'warning');
    }
    if (failCount > 0) {
      showToast(`❌ Có ${failCount} bundle gặp lỗi khi nạp.`, 'error');
    }

    await refreshPipelineQueueUI();
    await refreshPipelineFloatingSummary();
  });

  // Cloud Bundle Picker Refresh & Confirm (Section 14 & 15)
  DOM.btnRefreshCloudBundlePicker?.addEventListener('click', () => loadCloudBundleFolders());

  DOM.btnConfirmSelectCloudBundles?.addEventListener('click', async () => {
    const selected = cloudBundleFolders.filter(f => f.selected);
    if (selected.length === 0) {
      showToast('Vui lòng chọn ít nhất một thư mục Bundle từ Cloud.', 'warning');
      return;
    }

    showToast(`Đang tải & chuẩn bị ${selected.length} thư mục Bundle từ Cloud...`, 'info', 3000);
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
    showToast(`🎉 Dự án CapCut "${data?.job?.project_name}" đã tạo thành công (PROJECT_READY)!`, 'success', 6000);
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  window.autoedit.pipeline.onFailed?.((data) => {
    refreshPipelineFloatingSummary();
    showToast(`⚠️ Tác vụ Pipeline thất bại: ${data?.error || 'Lỗi không xác định'}`, 'error', 6000);
    if (state.currentTab === 'queue') refreshPipelineQueueUI();
  });

  // Initial fetch
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

    showToast(`Đang kiểm tra ${selectedDirs.length} thư mục Input Bundle...`, 'info', 2000);
    await prepareAndShowBundleImportPreview(selectedDirs);
  } catch (err) {
    showToast(`Lỗi khi mở thư mục: ${err.message}`, 'error');
  }
}

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
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:#fbbf24; background:rgba(251,191,36,0.15);">⚠️ Đã có trong queue</span>';
    } else if (v.ok) {
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:#34d399; background:rgba(52,211,153,0.15);">✓ Hợp lệ</span>';
    } else {
      badgeHtml = '<span style="font-size:11px; padding:2px 7px; border-radius:4px; font-weight:600; color:#f87171; background:rgba(248,113,113,0.15);">❌ Lỗi cấu trúc</span>';
    }

    html += `
      <div style="background:var(--bg-card,#0b1120); border:1px solid ${item.selected ? 'var(--accent-primary,#38bdf8)' : 'var(--border)'}; border-radius:8px; padding:12px; display:flex; gap:12px; align-items:flex-start;">
        <div style="padding-top:2px;">
          <input type="checkbox" id="chkCandidate_${idx}" ${item.selected ? 'checked' : ''} ${item.isDuplicate ? 'disabled' : ''} onchange="window.pipelineUiToggleCandidate(${idx}, this.checked)" style="cursor:pointer; width:16px; height:16px;" />
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
              <strong style="${v.has_script ? 'color:#34d399;' : 'color:#f87171;'}">${v.has_script ? '✓ Có' : '✕ Chưa'}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block;">Nhân vật:</span>
              <strong>${v.characters?.length ? `✓ ${v.characters.length} NV` : 'Không'}</strong>
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
              ⚠️ Bundle này đã có trong hàng đợi và đang được xử lý.
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
      <label style="display:flex; align-items:center; gap:10px; background:var(--bg-card,#0b1120); border:1px solid ${f.selected ? 'var(--accent-primary,#38bdf8)' : 'var(--border)'}; border-radius:6px; padding:10px 12px; cursor:pointer;">
        <input type="checkbox" id="chkCloudFolder_${idx}" ${f.selected ? 'checked' : ''} onchange="window.pipelineUiToggleCloudFolder(${idx}, this.checked)" style="cursor:pointer;" />
        <span style="font-size:16px;">📁</span>
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

async function refreshPipelineFloatingSummary() {
  if (!window.autoedit?.pipeline?.getActiveSummary || !DOM.floatingPipelineActivity) return;
  try {
    const res = await window.autoedit.pipeline.getActiveSummary();
    const summary = res?.summary;
    if (!summary || !summary.has_active_job) {
      DOM.floatingPipelineActivity.style.display = 'none';
      return;
    }

    DOM.floatingPipelineActivity.style.display = 'block';
    if (DOM.floatingProjectTitle) DOM.floatingProjectTitle.textContent = summary.project_name || 'Dự án Pipeline';
    if (DOM.floatingSceneActivity) DOM.floatingSceneActivity.textContent = summary.activity_text || summary.state_label;
    if (DOM.floatingProgressBar) DOM.floatingProgressBar.style.width = `${summary.progress_pct || 0}%`;
    if (DOM.floatingProgressPercent) DOM.floatingProgressPercent.textContent = `${summary.progress_pct || 0}%`;
    if (DOM.floatingFlowAccountBadge) DOM.floatingFlowAccountBadge.textContent = summary.flow_account || 'Flow #1';

    if (DOM.btnFloatingPauseResume) {
      if (summary.can_pause) {
        DOM.btnFloatingPauseResume.textContent = '⏸ Tạm dừng';
        DOM.btnFloatingPauseResume.style.display = 'inline-block';
      } else if (summary.can_resume) {
        DOM.btnFloatingPauseResume.textContent = '▶ Tiếp tục';
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
      DOM.pipelineActiveCountBadge.textContent = `${activeCount} tác vụ`;
    }

    if (jobs.length === 0) {
      DOM.pipelineJobsList.innerHTML = `
        <div style="background:rgba(15,23,42,0.4); border:1px dashed rgba(255,255,255,0.1); border-radius:8px; padding:20px; text-align:center; color:var(--text-muted); font-size:12.5px;">
          Chưa có tác vụ trong Hàng Đợi Tạo Dự Án. Bấm <strong>"📦 Import Bundle"</strong> hoặc <strong>"☁ Import từ Cloud"</strong> để nạp Input Bundle!
        </div>
      `;
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

      let stateColor = '#38bdf8';
      let stateBg = 'rgba(56,189,248,0.15)';
      let statusLabel = job.current_activity || job.state;

      if (isReady) {
        stateColor = '#34d399';
        stateBg = 'rgba(52,211,153,0.15)';
        statusLabel = '✓ PROJECT_READY';
      } else if (isFailed) {
        stateColor = '#f87171';
        stateBg = 'rgba(248,113,113,0.15)';
        statusLabel = '❌ THẤT BẠI';
      } else if (isWaitingUser) {
        stateColor = '#fb923c';
        stateBg = 'rgba(251,146,60,0.15)';
        statusLabel = '⚠️ CẦN CHỌN LẠI THƯ MỤC';
      } else if (isPaused) {
        stateColor = '#fbbf24';
        stateBg = 'rgba(251,191,36,0.15)';
        statusLabel = '⏸ TẠM DỪNG';
      } else if (isQueued) {
        stateColor = '#60a5fa';
        stateBg = 'rgba(96,165,250,0.15)';
        statusLabel = '⏳ ĐANG CHỜ (QUEUED)';
      } else if (isWaitingApproval) {
        stateColor = '#a855f7';
        stateBg = 'rgba(168,85,247,0.15)';
        statusLabel = '👤 CHỜ DUYỆT NHÂN VẬT';
      }

      html += `
        <div style="background:var(--bg-card,#0b1120); border:1px solid ${isWaitingUser ? '#fb923c' : (isReady ? '#34d399' : 'var(--border)')}; border-radius:8px; padding:14px; display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <span style="font-size:20px;">${isReady ? '✅' : (isFailed ? '❌' : (isWaitingUser ? '⚠️' : (isWaitingApproval ? '👤' : (isPaused ? '⏸' : '⚡'))))}</span>
              <div>
                <strong style="font-size:14px; color:var(--text-main);">${escapeHtml(job.project_name || job.bundle_name || 'Dự Án')}</strong>
                <div style="font-size:11px; color:var(--text-dim); font-family:monospace; margin-top:1px; word-break:break-all;">
                  ID: ${escapeHtml(job.id)} • Thư mục: ${escapeHtml(job.bundle_dir || 'N/A')}
                </div>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700; color:${stateColor}; background:${stateBg};">
                ${escapeHtml(statusLabel)}
              </span>
              <span style="font-size:11px; padding:2px 6px; border-radius:4px; background:rgba(255,255,255,0.06); color:var(--text-muted);">
                ${escapeHtml(job.options?.flow_account_id || 'Flow #1')}
              </span>
            </div>
          </div>

          ${isWaitingUser ? `
            <div style="font-size:12px; color:#fb923c; background:rgba(251,146,60,0.1); border:1px solid rgba(251,146,60,0.25); border-radius:6px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <span>⚠️ ${escapeHtml(job.error_message || 'Không tìm thấy Input Bundle. Thư mục đã bị di chuyển hoặc xóa.')}</span>
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiChangeBundleDir('${escapeHtml(job.id)}')" style="background:#f59e0b; color:#000; font-weight:700; font-size:11.5px; padding:4px 10px;">
                📁 Chọn lại thư mục
              </button>
            </div>
          ` : ''}

          ${isFailed ? `
            <div style="font-size:12px; color:#f87171; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); border-radius:6px; padding:8px 12px;">
              ❌ ${escapeHtml(job.error_message || 'Lỗi không xác định trong quá trình tạo dự án')}
            </div>
          ` : ''}

          <!-- Checklist Grid (Section 16 / Directive 7.5) -->
          <div style="display:grid; grid-template-columns: repeat(6, 1fr); gap:8px; background:rgba(0,0,0,0.25); padding:8px 12px; border-radius:6px; font-size:11.5px;">
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Input Bundle:</span>
              <strong style="${isWaitingUser ? 'color:#fb923c;' : 'color:#34d399;'}">${isWaitingUser ? '⚠️ Thiếu' : '✓ Hợp lệ'}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Nhân vật:</span>
              <strong>${job.characters?.length > 0 ? (isWaitingApproval ? '⏳ Chờ duyệt' : `✓ ${job.characters.length}/${job.characters.length}`) : 'Bỏ qua'}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Ảnh phân cảnh:</span>
              <strong>${readyImgs}/${totalScenes}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Video phân cảnh:</span>
              <strong>${readyVids}/${totalScenes}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Phụ đề:</span>
              <strong>${job.srt_path ? '✓ Sẵn sàng' : (readyVids === totalScenes && totalScenes > 0 ? '✓ Sẵn sàng' : 'Chờ')}</strong>
            </div>
            <div>
              <span style="color:var(--text-dim); display:block; font-size:10.5px;">Dự án CapCut:</span>
              <strong style="${isReady ? 'color:#34d399;' : ''}">${isReady ? '✓ Sẵn sàng' : 'Chờ'}</strong>
            </div>
          </div>

          <!-- Progress Bar -->
          <div>
            <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px; color:var(--text-dim);">
              <span>Tiến trình tổng thể:</span>
              <strong style="color:var(--text-main);">${job.progress_pct || 0}%</strong>
            </div>
            <div style="background:rgba(0,0,0,0.4); height:6px; border-radius:3px; overflow:hidden;">
              <div style="background:${isReady ? '#34d399' : (isWaitingUser ? '#fb923c' : 'var(--accent-primary,#38bdf8)')}; height:100%; width:${job.progress_pct || 0}%; transition:width 0.3s ease;"></div>
            </div>
          </div>

          <!-- Actions (Section 16 & 17) -->
          <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:4px; flex-wrap:wrap;">
            ${isWaitingUser ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiChangeBundleDir('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 10px; background:#f59e0b; color:#000; font-weight:700;">
                📁 Chọn lại thư mục
              </button>
            ` : ''}
            ${isWaitingApproval ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiApproveAll('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 10px;">
                ✓ Duyệt Tất Cả Nhân Vật
              </button>
            ` : ''}
            ${isQueued ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiResume('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px; background:#10b981; color:#fff; font-weight:600;">
                ▶ Chạy
              </button>
            ` : ''}
            ${isPaused ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiResume('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px;">
                ▶ Tiếp tục
              </button>
            ` : ''}
            ${(!isReady && !isFailed && job.state !== 'CANCELLED' && !isPaused && !isWaitingUser && !isQueued && !isWaitingApproval) ? `
              <button type="button" class="btn-subtle" onclick="window.pipelineUiTogglePause('${escapeHtml(job.id)}', false)" style="font-size:11px; padding:3px 8px;">
                ⏸ Tạm dừng
              </button>
            ` : ''}
            ${isFailed ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiResume('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px;">
                🔄 Thử lại
              </button>
            ` : ''}
            ${isReady ? `
              <button type="button" class="btn-action-primary" onclick="window.pipelineUiOpenCapCut('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px; background:#34d399; color:#000; font-weight:600;">
                🎬 Mở CapCut
              </button>
            ` : ''}
            <button type="button" class="btn-subtle" onclick="window.pipelineUiShowDetails('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px;">
              📋 Chi tiết
            </button>
            ${(job.state !== 'CANCELLED') ? `
              <button type="button" class="btn-link-danger" onclick="window.pipelineUiDelete('${escapeHtml(job.id)}')" style="font-size:11px; padding:3px 8px;">
                🗑️ Xóa
              </button>
            ` : ''}
          </div>
        </div>
      `;
    }

    DOM.pipelineJobsList.innerHTML = html;
  } catch (e) {}
}

// -----------------------------------------------------------------------------
// Global Action Handlers for Pipeline Queue Cards & Modals (Section 16 & 17)
// -----------------------------------------------------------------------------
let currentJobForModalDetails = null;

window.pipelineUiApproveAll = async (jobId) => {
  try {
    await window.autoedit.pipeline.approveAllCharacters(jobId);
    showToast('Đã duyệt toàn bộ nhân vật. Pipeline tiếp tục tạo cảnh!', 'success');
    await refreshPipelineQueueUI();
  } catch (e) { showToast(e.message, 'error'); }
};

window.pipelineUiTogglePause = async (jobId, isCurrentlyPaused) => {
  try {
    if (isCurrentlyPaused) {
      await window.autoedit.pipeline.resume(jobId);
      showToast('Đã tiếp tục tác vụ Pipeline.', 'info');
    } else {
      await window.autoedit.pipeline.pause(jobId);
      showToast('Đã tạm dừng tác vụ Pipeline.', 'info');
    }
    await refreshPipelineQueueUI();
  } catch (e) { showToast(e.message, 'error'); }
};

window.pipelineUiResume = async (jobId) => {
  try {
    await window.autoedit.pipeline.resume(jobId);
    showToast('Đang thực hiện tác vụ Pipeline...', 'info');
    await refreshPipelineQueueUI();
  } catch (e) { showToast(e.message, 'error'); }
};

window.pipelineUiCancel = async (jobId) => {
  try {
    await window.autoedit.pipeline.cancel(jobId);
    showToast('Đã hủy tác vụ Pipeline.', 'info');
    await refreshPipelineQueueUI();
  } catch (e) { showToast(e.message, 'error'); }
};

window.pipelineUiDelete = async (jobId) => {
  if (!confirm('Bạn có chắc chắn muốn xóa tác vụ này khỏi Hàng Đợi Tạo Dự Án?')) return;
  try {
    const res = await window.autoedit.pipeline.deleteJob(jobId);
    if (res && res.ok) {
      showToast('Đã xóa tác vụ khỏi hàng đợi.', 'info');
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } else {
      showToast(`Không thể xóa: ${res?.error || 'Lỗi không xác định'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
  }
};

window.pipelineUiChangeBundleDir = async (jobId) => {
  try {
    const newDir = await window.autoedit.openDirectoryDialog();
    if (!newDir) return;

    showToast('Đang kiểm tra và liên kết thư mục nguồn mới...', 'info', 2000);
    const res = await window.autoedit.pipeline.updateBundleDir(jobId, newDir);
    if (res && res.ok) {
      showToast('🎉 Đã cập nhật thành công thư mục nguồn! Tác vụ sẵn sàng chạy.', 'success');
      await refreshPipelineQueueUI();
      await refreshPipelineFloatingSummary();
    } else {
      showToast(`Không thể cập nhật: ${res?.error || 'Thư mục không hợp lệ'}`, 'error');
    }
  } catch (err) {
    showToast(`Lỗi: ${err.message}`, 'error');
  }
};

window.pipelineUiShowDetails = async (jobId) => {
  try {
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
          <td style="padding:6px 8px; ${imgReady ? 'color:#34d399;' : 'color:#f87171;'}">${imgReady ? '✓ Sẵn sàng' : '✕ Chưa'}</td>
          <td style="padding:6px 8px; ${vidReady ? 'color:#34d399;' : 'color:#94a3b8;'}">${vidReady ? '✓ Sẵn sàng' : '⏳ Chờ'}</td>
          <td style="padding:6px 8px; ${audioReady ? 'color:#34d399;' : 'color:#94a3b8;'}">${audioReady ? '✓ Có' : '—'}</td>
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
            ${job.capcut_project_path ? `
              <div style="grid-column:1 / -1;"><span style="color:var(--text-dim);">Dự án CapCut:</span> <code style="font-size:11px; color:#34d399; word-break:break-all;">${escapeHtml(job.capcut_project_path)}</code></div>
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
  }
};

window.pipelineUiOpenCapCut = async (jobId) => {
  try {
    const res = await window.autoedit.pipeline.getJob(jobId);
    const p = res?.job?.capcut_project_path;
    if (p) {
      if (window.autoedit.openCapCut) {
        await window.autoedit.openCapCut(p);
      }
      if (window.autoedit.openPath) {
        await window.autoedit.openPath(p);
      }
      showToast('Đang mở dự án CapCut...', 'success');
    } else {
      showToast('Không tìm thấy đường dẫn dự án CapCut.', 'warning');
    }
  } catch (err) {
    showToast(`Lỗi mở CapCut: ${err.message}`, 'error');
  }
};


// -----------------------------------------------------------------------------
// Google Flow Embedded Browser & Automation UI (Phase 4)
// -----------------------------------------------------------------------------
async function onOpenFlowTab() {
  await refreshFlowProfiles();
  await refreshFlowStatus();
  updateFlowBrowserBounds();
  requestAnimationFrame(updateFlowBrowserBounds);
  setTimeout(updateFlowBrowserBounds, 80);
}

function updateFlowBrowserBounds() {
  const container = DOM.flowBrowserContainer || document.getElementById('flowBrowserContainer');
  if (!container || !window.autoedit?.flow?.updateViewBounds) return;
  const rect = container.getBoundingClientRect();
  if (rect.width > 0 && rect.height > 0) {
    window.autoedit.flow.updateViewBounds({
      x: Math.round(rect.left),
      y: Math.round(rect.top),
      width: Math.round(rect.width),
      height: Math.round(rect.height),
    });
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
      opt.textContent = `${p.name} ${p.email ? `(${p.email})` : ''} ${p.is_active ? '★' : ''}`;
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
        modeBadge.textContent = '⚡ CHẾ ĐỘ: TỰ ĐỘNG';
        modeBadge.style.background = 'rgba(56,189,248,0.15)';
        modeBadge.style.color = '#38bdf8';
        modeBadge.style.borderColor = 'rgba(56,189,248,0.3)';
        if (btnTakeover) btnTakeover.style.display = 'inline-block';
        if (btnResumeAuto) btnResumeAuto.style.display = 'none';
      } else {
        modeBadge.textContent = '🟢 CHẾ ĐỘ: THỦ CÔNG';
        modeBadge.style.background = 'rgba(34,197,94,0.15)';
        modeBadge.style.color = '#22c55e';
        modeBadge.style.borderColor = 'rgba(34,197,94,0.3)';
        if (btnTakeover) btnTakeover.style.display = 'none';
        if (btnResumeAuto) btnResumeAuto.style.display = 'inline-block';
      }
    }

    if (authBadge) {
      const authState = res.auth?.state || (res.auth?.loggedIn ? 'LOGGED_IN' : 'UNKNOWN');
      if (authState === 'LOGGED_IN' || res.auth?.loggedIn) {
        authBadge.textContent = `👤 ${res.auth.email || res.profile?.email || 'Đã Đăng Nhập'}`;
        authBadge.style.background = 'rgba(34,197,94,0.15)';
        authBadge.style.color = '#22c55e';
      } else if (authState === 'LOADING' || authState === 'UNKNOWN') {
        authBadge.textContent = '🔄 Đang xác minh phiên Flow...';
        authBadge.style.background = 'rgba(56,189,248,0.15)';
        authBadge.style.color = '#38bdf8';
      } else if (authState === 'CAPTCHA_OR_2FA') {
        authBadge.textContent = '🛡️ Yêu cầu xác minh CAPTCHA/2FA';
        authBadge.style.background = 'rgba(245,158,11,0.15)';
        authBadge.style.color = '#f59e0b';
      } else if (authState === 'AUTH_CHALLENGE') {
        authBadge.textContent = '🔐 Yêu cầu đăng nhập Google';
        authBadge.style.background = 'rgba(245,158,11,0.15)';
        authBadge.style.color = '#f59e0b';
      } else {
        authBadge.textContent = '⚠️ Chưa đăng nhập Flow';
        authBadge.style.background = 'rgba(239,68,68,0.15)';
        authBadge.style.color = '#ef4444';
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
      await window.autoedit.flow.reload();
      showToast('Đang tải lại trang Google Flow...', 'info', 1500);
    } catch (e) {}
  });

  DOM.btnFlowNavigate?.addEventListener('click', async () => {
    try {
      await window.autoedit.flow.navigateFlow();
      showToast('Đang mở trang chủ Google Flow...', 'info', 1500);
    } catch (e) {}
  });

  DOM.flowProfileSelect?.addEventListener('change', async (e) => {
    const profileId = e.target.value;
    if (!profileId) return;
    try {
      showToast('Đang chuyển đổi phân vùng tài khoản Flow...', 'info', 2000);
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
      showToast('Đang tạo hồ sơ Google Flow mới...', 'info', 2000);
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

  window.addEventListener('resize', () => {
    if (state.currentTab === 'flow') {
      updateFlowBrowserBounds();
    }
  });

  const flowContainer = document.getElementById('flowBrowserContainer');
  if (flowContainer && typeof ResizeObserver === 'function') {
    const ro = new ResizeObserver(() => {
      if (state.currentTab === 'flow') {
        updateFlowBrowserBounds();
      }
    });
    ro.observe(flowContainer);
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

  // Load persistent store
  await loadStoredState();
  updateQueueBadge();

  // Check Background Statuses
  await Promise.all([
    checkLicenseStatus(),
    checkCapCutStatus(),
    refreshUserSession(),
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
