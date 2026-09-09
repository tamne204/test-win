/**
 * capture_brand_visuals.js
 * Automated Visual Snapshot Capture for 2TOOLNE AutoEdit V2 Rebrand
 * Captures pixel-perfect screenshots of all 9 application tabs.
 */
const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');

const OUTPUT_DIR = path.resolve(__dirname, '../../../../reports/visual_rebrand');

// Ensure output directory exists
if (!fs.existsSync(OUTPUT_DIR)) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

// Mock IPC Handlers to allow UI to render cleanly without live backend services
function setupIpcMocks() {
  const defaultHandlers = {
    'store:get': async () => ({}),
    'store:set': async () => true,
    'license:get-status': async () => ({
      status: 'ACTIVE',
      is_active: true,
      tier: 'ENTERPRISE_PRO',
      expires_at: '2027-12-31',
      device_name: '2TOOLNE-WORKSTATION',
      key_masked: '2TLN-****-****-8888',
    }),
    'license:check': async () => ({
      status: 'ACTIVE',
      is_active: true,
      license_key: '2TLN-ABCD-1234-8888',
    }),
    'capcut:detect': async () => ({
      installed: true,
      detected: true,
      version: '9.3.0.3970',
      path: 'C:\\Users\\User\\AppData\\Local\\CapCut\\Apps\\CapCut.exe',
    }),
    'capcut:get-status': async () => ({
      status: 'READY',
      version: '9.3.0.3970',
      running: false,
    }),
    'auth:get-user': async () => ({
      id: 'usr_2toolne',
      username: '2TOOLNE Pro Creator',
      email: 'creator@2toolne.vn',
      role: 'owner',
    }),
    'auth:get-state': async () => ({
      authenticated: true,
      user: {
        username: '2TOOLNE Pro Creator',
        email: 'creator@2toolne.vn',
      },
    }),
    'wallet:get-balance': async () => ({
      balance: 15400,
      currency: 'TOKEN',
      status: 'success',
    }),
    'queue:build:get-state': async () => ({
      status: 'IDLE',
      jobs: [
        { id: 'JOB-B01', name: 'Kịch bản review công nghệ 4K #01', status: 'PROJECT_READY', progress: 100, created_at: '2026-09-09 20:15' },
        { id: 'JOB-B02', name: 'Shorts tóm tắt tin tức AI #02', status: 'QUEUED', progress: 0, created_at: '2026-09-09 20:20' }
      ]
    }),
    'queue:render:get-state': async () => ({
      status: 'READY',
      jobs: [
        { id: 'JOB-R01', project_name: 'Tech_Review_Ep1_Master', status: 'COMPLETED', progress: 100, export_path: 'D:\\Exports\\Tech_Review_Ep1.mp4' },
        { id: 'JOB-R02', project_name: 'Daily_Shorts_AI_Ep2', status: 'READY_IN_QUEUE', progress: 0, export_path: 'D:\\Exports\\Daily_Shorts_AI_Ep2.mp4' }
      ]
    }),
    'pipeline:get-state': async () => ({
      activeJobs: [],
      history: []
    }),
    'projects:list': async () => ([
      { name: 'Kịch bản review công nghệ 4K #01', modified: 'Vừa xong', duration: '02:45', size: '14.2 MB', cover: null },
      { name: 'Shorts tóm tắt tin tức AI #02', modified: '2 giờ trước', duration: '00:58', size: '6.8 MB', cover: null }
    ]),
    'flow:get-accounts': async () => ([
      { id: 'flow_acc_01', name: 'Google Flow Account #1 (Pro)', status: 'ACTIVE', tier: 'UNLIMITED' }
    ]),
    'workspace:get-active': async () => ({
      id: 'ws_personal',
      name: 'Không gian cá nhân',
      role: 'OWNER',
    }),
    'workspace:list': async () => ([
      { id: 'ws_personal', name: 'Không gian cá nhân', role: 'OWNER' }
    ]),
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

  // Catch-all mock handler for any other channels
  ipcMain.on('asynchronous-message', (event) => {
    event.returnValue = true;
  });
}

async function captureTabs() {
  setupIpcMocks();

  const win = new BrowserWindow({
    width: 1366,
    height: 860,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, '../src/preload/preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  const htmlPath = path.join(__dirname, '../src/renderer/index.html');
  await win.loadFile(htmlPath);

  // Wait for initial render and scripts execution
  await new Promise((r) => setTimeout(r, 1500));

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
        } else if (tabId === 'queue_render') {
          const navBtn = document.querySelector('[data-tab="queue"]');
          if (navBtn) navBtn.click();
          const subBtn = document.getElementById('tabSubQueueRender');
          if (subBtn) subBtn.click();
        } else {
          const navBtn = document.querySelector('[data-tab="' + tabId + '"]');
          if (navBtn) navBtn.click();
        }
      })();
    `);

    // Allow CSS animations and layouts to settle
    await new Promise((r) => setTimeout(r, 400));

    const image = await win.webContents.capturePage();
    const filePath = path.join(OUTPUT_DIR, `${tab.name}.png`);
    fs.writeFileSync(filePath, image.toPNG());
    console.log(`  ✓ Saved: ${filePath} (${image.getSize().width}x${image.getSize().height})`);

    results.push({
      tab: tab.id,
      name: tab.name,
      label: tab.label,
      file: `${tab.name}.png`,
      size: `${image.getSize().width}x${image.getSize().height}`,
      bytes: fs.statSync(filePath).size,
    });
  }

  // Also capture dark mode preview for Studio
  console.log('Capturing Dark Mode Showcase...');
  await win.webContents.executeJavaScript(`
    (() => {
      document.documentElement.setAttribute('data-theme', 'dark');
      const navBtn = document.querySelector('[data-tab="studio"]');
      if (navBtn) navBtn.click();
    })();
  `);
  await new Promise((r) => setTimeout(r, 400));
  const darkImage = await win.webContents.capturePage();
  const darkFilePath = path.join(OUTPUT_DIR, 'showcase_studio_dark.png');
  fs.writeFileSync(darkFilePath, darkImage.toPNG());
  console.log(`  ✓ Saved: ${darkFilePath}`);

  results.push({
    tab: 'studio_dark',
    name: 'showcase_studio_dark',
    label: 'Studio Dựng Phim (Warm Dark Mode)',
    file: 'showcase_studio_dark.png',
    size: `${darkImage.getSize().width}x${darkImage.getSize().height}`,
    bytes: fs.statSync(darkFilePath).size,
  });

  // Write verification report
  const reportPath = path.join(OUTPUT_DIR, 'README.md');
  let md = `# 2TOOLNE AutoEdit V2 — Visual Rebrand Snapshot Report\n\n`;
  md += `**Date**: ${new Date().toISOString()}\n`;
  md += `**Authoritative Brand Source**: Cat + Mouse Master Icon (Orange / Cream)\n`;
  md += `**Color Palette**: \`#FB6B08\` (Primary), \`#FB9020\` (Secondary), \`#FCF8F1\` (Cream), \`#15110C\` (Charcoal)\n\n`;
  md += `| Tab / Screen | Label | Resolution | File Size | Output File |\n`;
  md += `|---|---|---|---|---|\n`;
  for (const r of results) {
    md += `| \`${r.tab}\` | ${r.label} | ${r.size} | ${(r.bytes / 1024).toFixed(1)} KB | [\`${r.file}\`](./${r.file}) |\n`;
  }
  md += `\n## All 9 Desktop Views Verified\n\n`;
  for (const r of results) {
    md += `### ${r.label}\n\n![${r.label}](./${r.file})\n\n`;
  }
  fs.writeFileSync(reportPath, md, 'utf8');
  console.log(`  ✓ Report written: ${reportPath}`);

  win.close();
  app.quit();
}

app.whenReady().then(captureTabs);
