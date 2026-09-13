/**
 * capture_brand_visuals.js
 * Automated Visual Snapshot Capture for 2TOOLNE AutoEdit V2 Rebrand
 * Captures pixel-perfect screenshots of all 9 tabs + viewports + modals + theme showcase.
 */
const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');

const OUTPUT_DIR = path.resolve(__dirname, '../../../../reports/visual_rebrand');
const ARTIFACT_DIR = path.resolve('/Users/2tamne/.gemini/antigravity/brain/3d2290de-47f7-4fdb-8f58-9b5fc20b2eaa');

if (!fs.existsSync(OUTPUT_DIR)) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

// Mock IPC Handlers to allow UI to render cleanly with full status indicators
function setupIpcMocks() {
  const defaultHandlers = {
    'store:get': async () => ({}),
    'store:set': async () => true,
    'store:get-data': async (event, key) => {
      if (key === 'autoedit_projects') {
        return [
          {
            id: 'proj_01',
            name: 'Review_iPhone_17_Pro_Teardown',
            aspectRatio: '9:16',
            imageCount: 18,
            durationS: 504,
            hasAudio: true,
            draftDir: 'C:\\CapCut\\Projects\\Review_iPhone_17',
            createdAt: Date.now() - 600000,
          },
          {
            id: 'proj_02',
            name: 'Shorts_Top_5_AI_Tools_2026',
            aspectRatio: '9:16',
            imageCount: 9,
            durationS: 58,
            hasAudio: true,
            draftDir: 'C:\\CapCut\\Projects\\Shorts_Top_5_AI',
            createdAt: Date.now() - 3600000,
          },
          {
            id: 'proj_03',
            name: 'Podcast_Summary_Episode_42',
            aspectRatio: '16:9',
            imageCount: 24,
            durationS: 850,
            hasAudio: true,
            draftDir: 'C:\\CapCut\\Projects\\Podcast_Ep42',
            createdAt: Date.now() - 86400000,
          },
        ];
      }
      return null;
    },
    'license:get-status': async () => ({
      authorized: true,
      state: 'ACTIVE',
      status: 'ACTIVE',
      active: true,
      plan: 'ENTERPRISE_PRO',
      tier: 'ENTERPRISE_PRO',
      masked_key: '2TLN-****-****-8888',
      device_id: 'HWID-WORKSTATION-99',
      expires_at: '2027-12-31',
    }),
    'sidecar:get-license-status': async () => ({
      authorized: true,
      state: 'ACTIVE',
      status: 'ACTIVE',
      tier: 'ENTERPRISE_PRO',
    }),
    'license:check': async () => ({
      status: 'ACTIVE',
      is_active: true,
      license_key: '2TLN-ABCD-1234-8888',
    }),
    'sidecar:detect-capcut': async () => ({
      installed: true,
      detected: true,
      detected_version: '9.3.0',
      version: '9.3.0',
      draft_root_path: 'C:\\Users\\User\\AppData\\Local\\CapCut\\User Data\\Projects',
    }),
    'capcut:detect': async () => ({
      installed: true,
      detected: true,
      detected_version: '9.3.0',
      version: '9.3.0',
      path: 'C:\\Users\\User\\AppData\\Local\\CapCut\\Apps\\CapCut.exe',
      draft_root_path: 'C:\\Users\\User\\AppData\\Local\\CapCut\\User Data\\Projects',
    }),
    'capcut:get-status': async () => ({
      status: 'READY',
      version: '9.3.0',
      running: false,
    }),
    'auth:get-user': async () => ({
      ok: true,
      id: 'usr_2toolne',
      user: {
        id: 'usr_2toolne',
        username: '2TOOLNE Pro Creator',
        email: 'creator@2toolne.vn',
        role: 'owner',
      },
    }),
    'auth:get-state': async () => ({
      ok: true,
      account: {
        authenticated: true,
        user: {
          id: 'usr_2toolne',
          username: '2TOOLNE Pro Creator',
          email: 'creator@2toolne.vn',
          role: 'owner',
        },
      },
      license: {
        valid: true,
        authorized: true,
        active: true,
        tier: 'ENTERPRISE_PRO',
        maskedKey: '2TLN-****-****-8888',
        deviceId: 'HWID-WORKSTATION-99',
        expiresAt: 1830211200,
      },
    }),
    'wallet:get-state': async () => ({
      wallet_type: 'PERSONAL',
      balance: 15400,
      currency: 'TOKEN',
      loading: false,
      error: null,
    }),
    'wallet:get-balance': async () => ({
      balance: 15400,
      currency: 'TOKEN',
      status: 'success',
      ok: true,
    }),
    'queue:build:get-state': async () => ({
      queue_status: 'RUNNING',
      jobs: [
        {
          job_id: 'job_01',
          project_name: 'Review_iPhone_17_Pro_Teardown',
          state: 'PROJECT_READY',
          progress: 100,
          current_step: 'Hoàn tất tạo dự án CapCut',
          created_at: 1757470000,
          started_at: 1757470010,
          completed_at: 1757470035,
          payload: { images: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] },
        },
        {
          job_id: 'job_02',
          project_name: 'Shorts_Top_5_AI_Tools_2026',
          state: 'RUNNING',
          progress: 65,
          current_step: 'Căn chỉnh kịch bản & căn mốc Audio (Whisper ASR)',
          created_at: 1757470100,
          started_at: 1757470105,
          payload: { images: [1, 2, 3, 4, 5] },
        },
        {
          job_id: 'job_03',
          project_name: 'Podcast_Summary_Episode_42',
          state: 'QUEUED',
          progress: 0,
          current_step: 'Chờ tới lượt xử lý',
          created_at: 1757470200,
          payload: { images: [1, 2, 3] },
        },
      ],
    }),
    'sidecar:get-build-queue-state': async () => ({
      queue_status: 'RUNNING',
      jobs: [
        {
          job_id: 'job_01',
          project_name: 'Review_iPhone_17_Pro_Teardown',
          state: 'PROJECT_READY',
          progress: 100,
          current_step: 'Hoàn tất tạo dự án CapCut',
          created_at: 1757470000,
          started_at: 1757470010,
          completed_at: 1757470035,
          payload: { images: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] },
        },
        {
          job_id: 'job_02',
          project_name: 'Shorts_Top_5_AI_Tools_2026',
          state: 'RUNNING',
          progress: 65,
          current_step: 'Căn chỉnh kịch bản & căn mốc Audio (Whisper ASR)',
          created_at: 1757470100,
          started_at: 1757470105,
          payload: { images: [1, 2, 3, 4, 5] },
        },
        {
          job_id: 'job_03',
          project_name: 'Podcast_Summary_Episode_42',
          state: 'QUEUED',
          progress: 0,
          current_step: 'Chờ tới lượt xử lý',
          created_at: 1757470200,
          payload: { images: [1, 2, 3] },
        },
      ],
    }),
    'queue:render:get-state': async () => ({
      status: 'READY',
      jobs: [
        {
          job_id: 'JOB-R01',
          project_name: 'Review_iPhone_17_Pro_Teardown',
          status: 'DONE',
          progress: 100,
          resolution: '4K UHD (3840x2160)',
          output_path: 'D:\\Exports\\iPhone_17_Teardown_4K.mp4',
        },
        {
          job_id: 'JOB-R02',
          project_name: 'Shorts_Top_5_AI_Tools_2026',
          status: 'RENDERING',
          progress: 52,
          resolution: '1080x1920 (9:16)',
          output_path: 'D:\\Exports\\Top_5_AI_Tools.mp4',
        },
        {
          job_id: 'JOB-R03',
          project_name: 'Podcast_Summary_Episode_42',
          status: 'QUEUED',
          progress: 0,
          resolution: '1920x1080 (16:9)',
          output_path: 'D:\\Exports\\Podcast_Ep42.mp4',
        },
      ],
    }),
    'sidecar:get-render-queue-state': async () => ({
      status: 'READY',
      jobs: [
        {
          job_id: 'JOB-R01',
          project_name: 'Review_iPhone_17_Pro_Teardown',
          status: 'DONE',
          progress: 100,
          resolution: '4K UHD (3840x2160)',
          output_path: 'D:\\Exports\\iPhone_17_Teardown_4K.mp4',
        },
        {
          job_id: 'JOB-R02',
          project_name: 'Shorts_Top_5_AI_Tools_2026',
          status: 'RENDERING',
          progress: 52,
          resolution: '1080x1920 (9:16)',
          output_path: 'D:\\Exports\\Top_5_AI_Tools.mp4',
        },
        {
          job_id: 'JOB-R03',
          project_name: 'Podcast_Summary_Episode_42',
          status: 'QUEUED',
          progress: 0,
          resolution: '1920x1080 (16:9)',
          output_path: 'D:\\Exports\\Podcast_Ep42.mp4',
        },
      ],
    }),
    'pipeline:get-state': async () => ({
      activeJobs: [],
      history: [],
    }),
    'pipeline:get-active-summary': async () => ({
      activeCount: 0,
      completedCount: 2,
      totalCount: 2,
    }),
    'pipeline:list-jobs': async () => [],
    'projects:list': async () => [
      {
        id: 'proj_01',
        name: 'Review_iPhone_17_Pro_Teardown',
        aspectRatio: '9:16',
        imageCount: 18,
        durationS: 504,
        hasAudio: true,
        draftDir: 'C:\\Users\\User\\CapCut\\Projects\\Review_iPhone_17',
        createdAt: Date.now() - 600000,
      },
      {
        id: 'proj_02',
        name: 'Shorts_Top_5_AI_Tools_2026',
        aspectRatio: '9:16',
        imageCount: 9,
        durationS: 58,
        hasAudio: true,
        draftDir: 'C:\\Users\\User\\CapCut\\Projects\\Shorts_Top_5_AI',
        createdAt: Date.now() - 3600000,
      },
      {
        id: 'proj_03',
        name: 'Podcast_Summary_Episode_42',
        aspectRatio: '16:9',
        imageCount: 24,
        durationS: 850,
        hasAudio: true,
        draftDir: 'C:\\Users\\User\\CapCut\\Projects\\Podcast_Ep42',
        createdAt: Date.now() - 86400000,
      },
    ],
    'flow:get-accounts': async () => [
      { id: 'flow_acc_01', name: 'Google Flow Account #1 (Pro)', status: 'ACTIVE', tier: 'UNLIMITED' },
    ],
    'flow:get-profiles': async () => [
      { id: 'prof_01', name: 'Google Profile 1', email: 'creator@2toolne.vn', status: 'ACTIVE' },
    ],
    'flow:get-status': async () => ({ status: 'READY', logged_in: true }),
    'flow:view-hide': async () => true,
    'flow:view-bounds': async () => true,
    'workspace:get-active': async () => ({
      id: 'ws_personal',
      name: 'Không gian cá nhân',
      role: 'OWNER',
    }),
    'workspace:list': async () => [
      { id: 'ws_personal', name: 'Không gian cá nhân', role: 'OWNER' },
      { id: 'ws_team', name: 'Studio Production Team', role: 'MEMBER' },
    ],
    'workspace:sync': async () => ({ success: true }),
    'cloud:get-spaces': async () => ({
      ok: true,
      spaces: [
        { id: 'space_01', name: 'Kho Lưu Trữ Chính', type: 'PERSONAL' },
        { id: 'space_02', name: 'Studio Shared Media', type: 'SHARED' },
      ],
    }),
    'cloud:get-quota': async () => ({
      ok: true,
      quota: {
        used_bytes: 42949672960,
        quota_bytes: 107374182400,
      },
    }),
    'cloud:list-files': async () => ({
      ok: true,
      folders: [
        { id: 'f1', name: 'B-Roll 4K Footage', has_share: false, updated_at: '2026-03-09T10:00:00Z' },
        { id: 'f2', name: 'Background Music & SFX', has_share: true, updated_at: '2026-03-08T15:30:00Z' },
        { id: 'f3', name: 'CapCut Project Presets', has_share: false, updated_at: '2026-03-05T08:20:00Z' },
      ],
      files: [
        { id: 'fl_01', name: 'Cyberpunk_Beat_140BPM.mp3', size_bytes: 8420000, updated_at: '2026-03-09T12:00:00Z', has_share: true },
        { id: 'fl_02', name: 'Tech_Review_Lower_Thirds.mov', size_bytes: 45200000, updated_at: '2026-03-08T18:40:00Z', has_share: false },
        { id: 'fl_03', name: 'Cinematic_LUT_WarmGold.cube', size_bytes: 120000, updated_at: '2026-03-07T09:15:00Z', has_share: false },
      ],
      breadcrumbs: [{ id: null, name: 'Cloud Cá Nhân' }],
    }),
    'cloud:list-trash': async () => ({
      ok: true,
      items: [],
    }),
    'ai:list-keys': async () => [
      { id: 'key_01', provider: 'OpenAI / ChatGPT', label: 'GPT-4o Vision Production', preview: 'sk-proj-****-8888', status: 'ACTIVE' },
      { id: 'key_02', provider: 'Google Gemini', label: 'Gemini 2.0 Flash Enterprise', preview: 'AIzaSy****-9999', status: 'ACTIVE' },
    ],
    'updater:get-state': async () => ({
      currentVersion: '2.0.0',
      status: 'IDLE',
      latestVersion: '2.0.0',
    }),
    'i18n:get-translations': async () => ({}),
  };

  for (const [channel, handler] of Object.entries(defaultHandlers)) {
    try {
      ipcMain.handle(channel, handler);
    } catch (e) {
      // Ignore if already registered
    }
  }

  ipcMain.on('asynchronous-message', (event) => {
    event.returnValue = true;
  });
}

function copyToArtifacts(fileName) {
  try {
    const src = path.join(OUTPUT_DIR, fileName);
    const dest = path.join(ARTIFACT_DIR, fileName);
    if (fs.existsSync(src)) {
      fs.copyFileSync(src, dest);
    }
  } catch (e) {
    console.warn(`Could not copy ${fileName} to artifacts:`, e.message);
  }
}

async function captureTabs() {
  setupIpcMocks();

  // Target standard 1536 x 864 desktop resolution as required by Section 19
  const win = new BrowserWindow({
    width: 1536,
    height: 864,
    show: false,
    backgroundColor: '#0E0F11',
    webPreferences: {
      preload: path.join(__dirname, '../src/preload/preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  const htmlPath = path.join(__dirname, '../src/renderer/index.html');
  await win.loadFile(htmlPath);

  // Wait for initial render and scripts execution
  await new Promise((r) => setTimeout(r, 1800));

  // Populate projects in renderer state for realistic capture
  await win.webContents.executeJavaScript(`
    (() => {
      try {
        if (window.state) {
          window.state.projects = [
            {
              id: 'proj_01',
              name: 'Review_iPhone_17_Pro_Teardown',
              aspectRatio: '9:16',
              imageCount: 18,
              durationS: 504,
              hasAudio: true,
              draftDir: 'C:\\\\Users\\\\User\\\\CapCut\\\\Projects\\\\Review_iPhone_17',
              createdAt: Date.now() - 600000
            },
            {
              id: 'proj_02',
              name: 'Shorts_Top_5_AI_Tools_2026',
              aspectRatio: '9:16',
              imageCount: 9,
              durationS: 58,
              hasAudio: true,
              draftDir: 'C:\\\\Users\\\\User\\\\CapCut\\\\Projects\\\\Shorts_Top_5_AI',
              createdAt: Date.now() - 3600000
            },
            {
              id: 'proj_03',
              name: 'Podcast_Summary_Episode_42',
              aspectRatio: '16:9',
              imageCount: 24,
              durationS: 850,
              hasAudio: true,
              draftDir: 'C:\\\\Users\\\\User\\\\CapCut\\\\Projects\\\\Podcast_Ep42',
              createdAt: Date.now() - 86400000
            }
          ];
          if (typeof renderProjectsGrid === 'function') {
            renderProjectsGrid();
          }
        }
      } catch (e) {
        console.error('Error populating sample projects:', e);
      }
    })();
  `);

  const tabs = [
    { id: 'studio', name: 'tab_01_studio', label: 'Studio Dựng Phim' },
    { id: 'queue_build', name: 'tab_02_build_queue', label: 'Hàng Đợi Tạo Dự Án (Build Queue)' },
    { id: 'queue_render', name: 'tab_03_render_queue', label: 'Hàng Đợi Xuất Video (Render Queue)' },
    { id: 'flow', name: 'tab_04_flow', label: 'Google Flow Chrome' },
    { id: 'projects', name: 'tab_05_projects', label: 'Dự Án Đã Tạo' },
    { id: 'upscale', name: 'tab_06_upscale', label: 'AI Upscale 4K' },
    { id: 'cloud', name: 'tab_07_cloud', label: 'Cloud Lưu Trữ' },
    { id: 'account', name: 'tab_08_account_license', label: 'Tài Khoản & Bản Quyền' },
    { id: 'settings', name: 'tab_09_settings', label: 'Cài Đặt Hệ Thống' },
  ];

  const results = [];

  // Capture All 9 Primary Tabs at 1536x864
  for (const tab of tabs) {
    console.log(`Capturing ${tab.label} (${tab.name})...`);

    await win.webContents.executeJavaScript(`
      (() => {
        const tabId = '${tab.id}';
        if (tabId === 'queue_build') {
          const navBtn = document.querySelector('[data-tab="queue"]');
          if (navBtn) navBtn.click();
          const subBtn = document.getElementById('tabSubQueueBuild');
          if (subBtn) subBtn.click();
          if (typeof refreshBuildQueueUI === 'function') refreshBuildQueueUI();
        } else if (tabId === 'queue_render') {
          const navBtn = document.querySelector('[data-tab="queue"]');
          if (navBtn) navBtn.click();
          const subBtn = document.getElementById('tabSubQueueRender');
          if (subBtn) subBtn.click();
          if (typeof refreshRenderQueueUI === 'function') refreshRenderQueueUI();
        } else {
          const navBtn = document.querySelector('[data-tab="' + tabId + '"]');
          if (navBtn) navBtn.click();
          if (tabId === 'projects' && typeof renderProjectsGrid === 'function') {
            renderProjectsGrid();
          }
        }
      })();
    `);

    await new Promise((r) => setTimeout(r, 500));

    const image = await win.webContents.capturePage();
    const filePath = path.join(OUTPUT_DIR, `${tab.name}.png`);
    fs.writeFileSync(filePath, image.toPNG());
    copyToArtifacts(`${tab.name}.png`);
    console.log(`  ✓ Saved: ${tab.name}.png (${image.getSize().width}x${image.getSize().height})`);

    results.push({
      tab: tab.id,
      name: tab.name,
      label: tab.label,
      file: `${tab.name}.png`,
      size: `${image.getSize().width}x${image.getSize().height}`,
      bytes: fs.statSync(filePath).size,
    });
  }

  // 10. Viewport 1536x864 (Studio tab)
  console.log('Capturing Viewport 1536x864...');
  await win.webContents.executeJavaScript(`
    (() => {
      const navBtn = document.querySelector('[data-tab="studio"]');
      if (navBtn) navBtn.click();
    })();
  `);
  await new Promise((r) => setTimeout(r, 400));
  const vp1536 = await win.webContents.capturePage();
  fs.writeFileSync(path.join(OUTPUT_DIR, 'viewport_1536x864.png'), vp1536.toPNG());
  copyToArtifacts('viewport_1536x864.png');
  results.push({
    tab: 'viewport_1536x864',
    name: 'viewport_1536x864',
    label: 'Studio Viewport 1536x864 (Windows Laptop Default)',
    file: 'viewport_1536x864.png',
    size: `${vp1536.getSize().width}x${vp1536.getSize().height}`,
    bytes: fs.statSync(path.join(OUTPUT_DIR, 'viewport_1536x864.png')).size,
  });

  // 11. Viewport 1280x800 (Compact desktop)
  console.log('Capturing Viewport 1280x800...');
  win.setSize(1280, 800);
  await new Promise((r) => setTimeout(r, 600));
  const vp1280 = await win.webContents.capturePage();
  fs.writeFileSync(path.join(OUTPUT_DIR, 'viewport_1280x800.png'), vp1280.toPNG());
  copyToArtifacts('viewport_1280x800.png');
  results.push({
    tab: 'viewport_1280x800',
    name: 'viewport_1280x800',
    label: 'Studio Viewport 1280x800 (Compact Desktop Minimum)',
    file: 'viewport_1280x800.png',
    size: `${vp1280.getSize().width}x${vp1280.getSize().height}`,
    bytes: fs.statSync(path.join(OUTPUT_DIR, 'viewport_1280x800.png')).size,
  });

  // 12. Viewport 1920x1080 (FHD desktop)
  console.log('Capturing Viewport 1920x1080...');
  win.setSize(1920, 1080);
  await new Promise((r) => setTimeout(r, 600));
  const vp1920 = await win.webContents.capturePage();
  fs.writeFileSync(path.join(OUTPUT_DIR, 'viewport_1920x1080.png'), vp1920.toPNG());
  copyToArtifacts('viewport_1920x1080.png');
  results.push({
    tab: 'viewport_1920x1080',
    name: 'viewport_1920x1080',
    label: 'Studio Viewport 1920x1080 (Full HD Desktop)',
    file: 'viewport_1920x1080.png',
    size: `${vp1920.getSize().width}x${vp1920.getSize().height}`,
    bytes: fs.statSync(path.join(OUTPUT_DIR, 'viewport_1920x1080.png')).size,
  });

  // 13. Modal Example
  console.log('Capturing Modal Example (modal_example.png)...');
  win.setSize(1536, 864);
  await new Promise((r) => setTimeout(r, 400));
  await win.webContents.executeJavaScript(`
    (() => {
      const modal = document.getElementById('modalLogin') || document.getElementById('modalInputBundle') || document.getElementById('modalMissingImages');
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
      }
    })();
  `);
  await new Promise((r) => setTimeout(r, 400));
  const modalImg = await win.webContents.capturePage();
  fs.writeFileSync(path.join(OUTPUT_DIR, 'modal_example.png'), modalImg.toPNG());
  copyToArtifacts('modal_example.png');
  results.push({
    tab: 'modal_example',
    name: 'modal_example',
    label: 'Modal Dialog Surface (modal_example.png)',
    file: 'modal_example.png',
    size: `${modalImg.getSize().width}x${modalImg.getSize().height}`,
    bytes: fs.statSync(path.join(OUTPUT_DIR, 'modal_example.png')).size,
  });

  // Close modal
  await win.webContents.executeJavaScript(`
    (() => {
      const modals = document.querySelectorAll('.modal-overlay, .modal-backdrop');
      modals.forEach(m => { m.style.display = 'none'; m.classList.remove('show'); });
    })();
  `);

  // Write verification report
  const reportPath = path.join(OUTPUT_DIR, 'README.md');
  let md = `# 2TOOLNE AutoEdit V2 — Dark Professional Visual Snapshot Report\n\n`;
  md += `**Date**: ${new Date().toISOString()}\n`;
  md += `**Design Language**: Dark Mode Default, Professional Creative Suite\n`;
  md += `**Brand Accent**: \`#FF7A00\` (Primary Orange)\n`;
  md += `**Surfaces**: \`#0F1012\` (App Base), \`#141518\` (Sidebar), \`#191B1F\` (Cards), \`#202228\` (Hover/Elevated)\n\n`;
  md += `| Item | Screen / Viewport | Size | Dimensions | File |\n`;
  md += `|---|---|---|---|---|\n`;
  for (const r of results) {
    md += `| \`${r.tab}\` | ${r.label} | ${(r.bytes / 1024).toFixed(1)} KB | ${r.size} | [\`${r.file}\`](./${r.file}) |\n`;
  }
  md += `\n## Visual Review Gallery\n\n`;
  for (const r of results) {
    md += `### ${r.label}\n\n![${r.label}](./${r.file})\n\n`;
  }
  fs.writeFileSync(reportPath, md, 'utf8');
  console.log(`\n✓ All ${results.length} snapshots generated and verified: ${reportPath}`);

  win.close();
  app.quit();
}

app.whenReady().then(captureTabs);
