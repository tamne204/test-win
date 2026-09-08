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

function showToast(message, type = 'info', duration = 3500) {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = 'position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:99999;pointer-events:none;';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.style.cssText = 'pointer-events:auto;min-width:260px;max-width:380px;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,0.5);display:flex;align-items:center;gap:10px;animation:slideInToast 0.25s ease;';
  
  if (type === 'success') {
    toast.style.background = '#064e3b';
    toast.style.color = '#34d399';
    toast.style.border = '1px solid rgba(52,211,153,0.3)';
    toast.innerHTML = `<span>✅</span><div>${message}</div>`;
  } else if (type === 'error') {
    toast.style.background = '#450a0a';
    toast.style.color = '#f87171';
    toast.style.border = '1px solid rgba(248,113,113,0.3)';
    toast.innerHTML = `<span>⚠️</span><div>${message}</div>`;
  } else {
    toast.style.background = '#0f172a';
    toast.style.color = '#38bdf8';
    toast.style.border = '1px solid rgba(56,189,248,0.3)';
    toast.innerHTML = `<span>ℹ️</span><div>${message}</div>`;
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

  // Queue View
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

  // Account View
  accUserEmail: document.getElementById('accUserEmail'),
  accMaskedKey: document.getElementById('accMaskedKey'),
  accStatus: document.getElementById('accStatus'),
  accDeviceId: document.getElementById('accDeviceId'),
  accTokenCount: document.getElementById('accTokenCount'),
  btnRefreshWallet: document.getElementById('btnRefreshWallet'),
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

  // Modal 7: Login Modal
  modalLogin: document.getElementById('modalLogin'),
  btnCloseModalLogin: document.getElementById('btnCloseModalLogin'),
  btnCancelModalLogin: document.getElementById('btnCancelModalLogin'),
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
  settings: {
    vi: { title: 'Cài Đặt Ứng Dụng', sub: 'Cấu hình liên kết CapCut và tùy chọn xuất bản' },
    en: { title: 'Application Settings', sub: 'Configure CapCut paths, language, and render preferences' },
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
    } else {
      pane.classList.remove('active');
    }
  });

  // Update Header Title with localized string
  const lang = window.i18n?.currentLang || 'vi';
  const meta = VIEW_METADATA[tabId]?.[lang] || VIEW_METADATA[tabId]?.vi || { title: '2toolne AutoEdit', sub: '' };
  DOM.viewTitle.textContent = meta.title;
  DOM.viewSub.textContent = meta.sub;

  if (tabId === 'projects') renderProjectsGrid();
  if (tabId === 'queue') renderQueueTable();
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

// Click Backdrop to Dismiss
[DOM.modalLicense, DOM.modalProgress, DOM.modalAlert, DOM.modalMissingImages].forEach((modal) => {
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
      DOM.alignProgressMsg.textContent = isAutoSub ? 'Tự động tạo phụ đề AutoSub thành công!' : 'So khớp kịch bản thành công!';
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
  });
}
syncWeightSlider(DOM.slZoomIn, DOM.valZoomIn, 'zoom_in');
syncWeightSlider(DOM.slZoomOut, DOM.valZoomOut, 'zoom_out');
syncWeightSlider(DOM.slPan, DOM.valPan, 'pan');
syncWeightSlider(DOM.slTilt, DOM.valTilt, 'tilt');

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
  if (state.mediaList.length === 0) {
    showAlert('Vui lòng chọn hoặc kéo thả ít nhất 1 hình ảnh để tạo dự án.', 'Thiếu Ảnh');
    return;
  }

  const payload = assembleCurrentProjectPayload();

  showModal(DOM.modalProgress);
  DOM.progressModalTitle.textContent = `Đang Tạo "${payload.project_name}"...`;
  DOM.progressModalPct.textContent = '10%';
  DOM.progressModalFill.style.width = '10%';
  DOM.progressModalStage.textContent = 'Chuẩn bị dữ liệu và thư viện...';

  try {
    const res = await window.autoedit.generateProject(payload);

    if (res && res.status === 'READY') {
      DOM.progressModalPct.textContent = '100%';
      DOM.progressModalFill.style.width = '100%';
      DOM.progressModalStage.textContent = 'Hoàn thành!';

      // Record in Projects Store
      const projectRecord = {
        id: res.project_id || `proj_${Date.now()}`,
        name: payload.project_name,
        aspectRatio: payload.aspect_ratio,
        imageCount: payload.images.length,
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
        },
      };
      state.projects.unshift(projectRecord);
      await saveProjects();

      setTimeout(() => {
        hideModal(DOM.modalProgress);
        showAlert(`🎉 Đã tạo thành công dự án "${payload.project_name}" vào thư viện CapCut!`, 'Thành Công');
        // Auto open if checked
        if (DOM.chkAutoOpenCapcut.checked && res.final_draft_dir) {
          window.autoedit.openCapCut(res.final_draft_dir);
        }
      }, 600);
    } else {
      throw new Error(res?.error || 'Không thể tạo dự án');
    }
  } catch (err) {
    hideModal(DOM.modalProgress);
    showAlert(`Lỗi tạo dự án CapCut:\n${err.message}`, 'Thất Bại');
  }
});

// -----------------------------------------------------------------------------
// Job Queue Manager
// -----------------------------------------------------------------------------
DOM.btnAddToQueue.addEventListener('click', async () => {
  if (state.mediaList.length === 0) {
    showAlert('Vui lòng chọn ít nhất 1 hình ảnh trước khi thêm vào hàng đợi.', 'Thiếu Ảnh');
    return;
  }

  const payload = assembleCurrentProjectPayload();
  const queueItem = {
    id: `job_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
    name: payload.project_name,
    payload,
    status: 'WAITING', // WAITING, RUNNING, COMPLETED, ERROR
    progress: 0,
    error: null,
    draftDir: null,
    addedAt: Date.now(),
  };

  state.queue.push(queueItem);
  await saveQueue();
  updateQueueBadge();
  showAlert(`Đã thêm dự án "${queueItem.name}" vào hàng đợi!`, 'Đã Thêm Vào Queue');

  // Reset name for next project
  DOM.inpProjectName.value = generateDefaultProjectName();
});

// -----------------------------------------------------------------------------
// Authoritative Render Queue Controller (GAP-02 & GAP-03)
// Connected 1:1 to Python RenderQueueManager FSM
// -----------------------------------------------------------------------------

function updateQueueBadge() {
  if (!state.renderQueue || !state.renderQueue.jobs) {
    if (DOM.queueBadge) DOM.queueBadge.style.display = 'none';
    return;
  }
  const pendingCount = state.renderQueue.jobs.filter((j) =>
    ['QUEUED', 'PRECHECK', 'STARTING_CAPCUT', 'OPENING_PROJECT', 'TRIGGERING_EXPORT', 'CONFIRMING_EXPORT', 'RENDERING', 'VERIFYING_OUTPUT'].includes(j.status)
  ).length;
  if (DOM.queueBadge) {
    DOM.queueBadge.textContent = String(pendingCount);
    DOM.queueBadge.style.display = pendingCount > 0 ? 'inline-block' : 'none';
  }
}

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
  const filteredProjects = state.projects.filter((p) => {
    if (!searchTerm) return true;
    return (p.name || '').toLowerCase().includes(searchTerm) || (p.draftDir || '').toLowerCase().includes(searchTerm);
  });

  if (filteredProjects.length === 0) {
    if (state.projects.length === 0) {
      DOM.projectsGrid.innerHTML = '<div class="empty-projects-hint">Chưa có dự án nào được tạo trong lịch sử.</div>';
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

    card.innerHTML = `
      <div class="project-card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
          <h4 class="project-card-title">${escapeHtml(proj.name)}</h4>
          <button class="btn-subtle btn-danger" style="padding:2px 8px; font-size:11px;" onclick="promptDeleteProject('${safeProjId}')" title="Xóa dự án">🗑️ Xóa</button>
        </div>
        <div class="project-card-meta">
          <span>Tỷ lệ: ${escapeHtml(proj.aspectRatio || '9:16')}</span> • 
          <span>${proj.imageCount || 0} ảnh</span> • 
          <span>${dateStr}</span>
        </div>
        <div class="project-card-actions" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:8px;">
          <button class="btn-action-primary" onclick="openDraftInCapCut('${safeDraftDir}')">🎬 Mở CapCut</button>
          <button class="btn-subtle" onclick="loadProjectToStudio('${safeProjId}')" title="Nạp lại kịch bản, ảnh và thiết lập vào Studio">✏️ Nạp vào Studio</button>
          <button class="btn-subtle" onclick="renderDraftNow('${safeDraftDir}', '${safeProjName}')">⚡ Render Ngay</button>
          <button class="btn-subtle" onclick="addDraftToRenderQueue('${safeDraftDir}', '${safeProjName}')">➕ Thêm Hàng Đợi</button>
          <button class="btn-subtle" onclick="openDraftFolder('${safeDraftDir}')">📁 Thư mục</button>
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

DOM.btnRefreshProjects?.addEventListener('click', () => {
  renderProjectsGrid();
});

DOM.inpProjectSearch?.addEventListener('input', () => {
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

DOM.btnCheckUpdate?.addEventListener('click', async () => {
  if (!window.autoedit?.checkForUpdates) return;
  DOM.btnCheckUpdate.disabled = true;
  DOM.btnCheckUpdate.textContent = '⏳ Đang kiểm tra...';
  if (DOM.txtUpdateStatus) {
    DOM.txtUpdateStatus.textContent = 'Đang liên hệ máy chủ cập nhật...';
  }

  try {
    const res = await window.autoedit.checkForUpdates();
    if (res && res.ok) {
      if (res.has_update) {
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `⚡ Có bản cập nhật mới: v${res.latest_version}!`;
          DOM.txtUpdateStatus.style.color = '#38bdf8';
        }
        showAlert(
          `🚀 Phát hiện bản cập nhật mới v${res.latest_version}!\n\n` +
          `Ghi chú: ${res.release_notes || 'Tối ưu hóa và sửa lỗi'}\n\n` +
          `Vui lòng truy cập portal hoặc link sau để tải về cài đặt:\n${res.download_url}`,
          'Có Bản Cập Nhật Mới'
        );
      } else {
        if (DOM.txtUpdateStatus) {
          DOM.txtUpdateStatus.textContent = `Bạn đang sử dụng phiên bản mới nhất (v${res.current_version}).`;
          DOM.txtUpdateStatus.style.color = 'var(--success)';
        }
        showToast(`Bạn đang sử dụng phiên bản mới nhất (v${res.current_version}).`, 'success', 3000);
      }
    } else {
      if (DOM.txtUpdateStatus) {
        DOM.txtUpdateStatus.textContent = 'Không thể kiểm tra cập nhật lúc này.';
      }
      showToast('Không thể kết nối máy chủ cập nhật: ' + (res?.error || 'Lỗi mạng'), 'warning', 3000);
    }
  } catch (err) {
    if (DOM.txtUpdateStatus) {
      DOM.txtUpdateStatus.textContent = 'Lỗi kết nối máy chủ.';
    }
    showToast('Lỗi kiểm tra cập nhật: ' + err.message, 'error', 3000);
  } finally {
    DOM.btnCheckUpdate.disabled = false;
    DOM.btnCheckUpdate.textContent = '🔄 Kiểm Tra Cập Nhật';
  }
});

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

  // Check token balance if authenticated
  if (state.tokenBalance !== null && state.tokenBalance > 0 && state.tokenBalance < totalCost) {
    showAlert(`Số dư token trong ví không đủ (Cần: ${totalCost} token cho ${state.upscaleFiles.length} ảnh ${is4K ? '4K' : '2K'}, hiện có: ${state.tokenBalance} token). Vui lòng nạp thêm token.`, 'Thiếu Token');
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
// User Account & Live Wallet Helpers (GAP-09 & GAP-10)
// -----------------------------------------------------------------------------
async function refreshWalletBalance() {
  try {
    if (!window.autoedit?.getWalletBalance) return;
    const res = await window.autoedit.getWalletBalance();
    if (res && res.ok) {
      state.tokenBalance = res.balance !== undefined ? res.balance : 0;
      if (DOM.tokenBalance) DOM.tokenBalance.textContent = state.tokenBalance;
      if (DOM.accTokenCount) DOM.accTokenCount.textContent = state.tokenBalance;
    }
  } catch (err) {
    console.warn('Error refreshing wallet balance:', err);
  }
}

async function refreshUserSession() {
  try {
    if (!window.autoedit?.getUser) return;
    const res = await window.autoedit.getUser();
    if (res && res.ok && res.user) {
      state.currentUser = res.user;
      if (DOM.accUserEmail) {
        DOM.accUserEmail.textContent = res.user.email || res.user.username || 'Đã đăng nhập';
      }
      if (DOM.btnOpenLoginModal) DOM.btnOpenLoginModal.style.display = 'none';
      if (DOM.btnLogoutAccount) DOM.btnLogoutAccount.style.display = 'inline-block';
    } else {
      state.currentUser = null;
      if (DOM.accUserEmail) {
        DOM.accUserEmail.textContent = 'Chưa đăng nhập';
      }
      if (DOM.btnOpenLoginModal) DOM.btnOpenLoginModal.style.display = 'inline-block';
      if (DOM.btnLogoutAccount) DOM.btnLogoutAccount.style.display = 'none';
    }
  } catch (err) {
    console.warn('Error checking user session:', err);
  }
}

// User Login & Account Actions
DOM.btnOpenLoginModal?.addEventListener('click', () => {
  if (DOM.loginModalError) DOM.loginModalError.style.display = 'none';
  if (DOM.inpLoginEmail) DOM.inpLoginEmail.value = '';
  if (DOM.inpLoginPassword) DOM.inpLoginPassword.value = '';
  showModal(DOM.modalLogin);
});

[DOM.btnCloseModalLogin, DOM.btnCancelModalLogin].forEach((btn) => {
  btn?.addEventListener('click', () => hideModal(DOM.modalLogin));
});

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
      await refreshUserSession();
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
    DOM.btnSubmitModalLogin.textContent = 'Đăng Nhập';
  }
});

DOM.btnLogoutAccount?.addEventListener('click', async () => {
  if (confirm('Bạn có chắc chắn muốn đăng xuất tài khoản này?')) {
    try {
      await window.autoedit.logout();
      showToast('Đã đăng xuất tài khoản.', 'info', 2500);
      await refreshUserSession();
      await refreshWalletBalance();
    } catch (err) {
      showAlert(`Lỗi đăng xuất: ${err.message}`, 'Lỗi');
    }
  }
});

DOM.btnRefreshWallet?.addEventListener('click', async () => {
  await refreshWalletBalance();
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
// App Initialization
// -----------------------------------------------------------------------------
async function checkLicenseStatus() {
  try {
    const res = await window.autoedit.getLicenseStatus();
    if (res) {
      state.licenseInfo = res;
      const isAuth = !!(res.authorized || res.state === 'ACTIVE' || res.active);
      if (isAuth) {
        DOM.licenseDot.className = 'status-dot dot-active';
        DOM.licenseText.textContent = res.masked_key || 'Bản quyền hợp lệ';
        DOM.accStatus.textContent = 'ĐÃ KÍCH HOẠT';
        DOM.accStatus.style.color = 'var(--success)';
        DOM.accMaskedKey.textContent = res.masked_key || 'Đã kích hoạt';
      } else {
        DOM.licenseDot.className = 'status-dot dot-error';
        DOM.licenseText.textContent = 'Chưa kích hoạt';
        DOM.accStatus.textContent = 'CHƯA KÍCH HOẠT';
        DOM.accStatus.style.color = 'var(--warning)';
        DOM.accMaskedKey.textContent = 'Chưa có bản quyền';
      }
      DOM.accDeviceId.textContent = res.device_id || 'Chưa xác định';
    }
    await Promise.all([
      refreshUserSession(),
      refreshWalletBalance(),
    ]);
  } catch (e) {
    DOM.licenseDot.className = 'status-dot dot-error';
    DOM.licenseText.textContent = 'Offline';
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

  // Connect Authoritative Render Queue (GAP-02 & GAP-03)
  if (window.autoedit && window.autoedit.onRenderQueueUpdate) {
    window.autoedit.onRenderQueueUpdate((data) => {
      renderQueueTableFromState(data);
      checkRenderJobStatusChanges(data);
    });
  }

  // Initial fetch of real render queue
  await refreshRenderQueueUI();

  // Heartbeat polling for queue when queue view is active or worker is running
  setInterval(() => {
    if (state.currentTab === 'queue' || state.renderQueue.status === 'RUNNING') {
      refreshRenderQueueUI();
    }
  }, 2000);
});
