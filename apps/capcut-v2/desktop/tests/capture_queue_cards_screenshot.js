const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');

app.setName('2toolne-autoedit');
app.setPath('userData', '/Users/2tamne/Library/Application Support/2toolne-autoedit');

const mockJobs = [
  {
    id: 'job_tokyo_ready',
    project_name: 'Tokyo Rainy Night',
    bundle_name: 'Tokyo Rainy Night',
    bundle_dir: '/Users/2tamne/Projects/AI_Bundles/Tokyo_Rainy_Night',
    created_at: new Date().toISOString(),
    state: 'PROJECT_READY',
    current_activity: 'Dự án CapCut đã sẵn sàng!',
    progress_pct: 100,
    capcut_project_path: '/Users/2tamne/Movies/CapCut/Drafts/Tokyo_Rainy_Night.2toolne-autoedit',
    options: { flow_account_id: 'Flow #1' },
    scenes: [
      { scene_id: '001', image_status: 'READY', video_status: 'READY' },
      { scene_id: '002', image_status: 'READY', video_status: 'READY' },
      { scene_id: '003', image_status: 'READY', video_status: 'READY' }
    ],
    characters: [{ id: 'char_kenji' }]
  },
  {
    id: 'job_cyberpunk_missing',
    project_name: 'Cyberpunk Chase',
    bundle_name: 'Cyberpunk Chase',
    bundle_dir: '/Users/2tamne/ExternalDrive/Cyberpunk_Chase',
    created_at: new Date().toISOString(),
    state: 'WAITING_USER',
    error_message: 'Không tìm thấy Input Bundle. Thư mục đã bị di chuyển hoặc xóa khỏi ổ đĩa.',
    progress_pct: 15,
    options: { flow_account_id: 'Flow #2' },
    scenes: [
      { scene_id: '001', image_status: 'READY', video_status: 'PENDING' },
      { scene_id: '002', image_status: 'PENDING', video_status: 'PENDING' }
    ],
    characters: []
  },
  {
    id: 'job_anime_queued',
    project_name: 'Anime Intro Story',
    bundle_name: 'Anime Intro Story',
    bundle_dir: '/Users/2tamne/Projects/AI_Bundles/Anime_Intro',
    created_at: new Date().toISOString(),
    state: 'QUEUED',
    current_activity: 'Đang xếp hàng (Queued)...',
    progress_pct: 0,
    options: { flow_account_id: 'Flow #1' },
    scenes: [
      { scene_id: '001', image_status: 'PENDING', video_status: 'PENDING' }
    ],
    characters: [{ id: 'char_yuki' }]
  }
];

// Register IPC handlers
ipcMain.handle('pipeline:list-jobs', async () => ({ ok: true, jobs: mockJobs }));
ipcMain.handle('pipeline:get-active-summary', async () => ({ ok: true, active_jobs: 2 }));
ipcMain.handle('workspace:sync', async () => ({ ok: true, currentWorkspaceId: 'ws_default', workspaces: [{ id: 'ws_default', name: 'Không gian cá nhân', type: 'PERSONAL' }] }));
ipcMain.handle('store:get-data', async () => ({ ok: true, data: {} }));
ipcMain.handle('auth:get-state', async () => ({ ok: true, authenticated: true, user: { fullname: 'Tam Ne', email: 'tamne@example.com' } }));
ipcMain.handle('sidecar:detect-capcut', async () => ({ ok: true, detected: true, version: '5.0.0', drafts_dir: '/Users/2tamne/Movies/CapCut/Drafts' }));
ipcMain.handle('sidecar:get-build-queue-state', async () => ({ ok: true, state: { jobs: [] } }));
ipcMain.handle('sidecar:get-render-queue-state', async () => ({ ok: true, state: { jobs: [] } }));
ipcMain.handle('updater:get-state', async () => ({ ok: true, state: { status: 'idle' } }));
ipcMain.handle('flow:view-hide', async () => ({ ok: true }));

app.whenReady().then(async () => {
  let win = null;
  try {
    const desktopPath = '/Users/2tamne/tool ffmpeg/apps/capcut-v2/desktop';
    win = new BrowserWindow({
      width: 1380,
      height: 920,
      show: false,
      webPreferences: {
        preload: path.join(desktopPath, 'src/preload/preload.js'),
        nodeIntegration: false,
        contextIsolation: true,
      },
    });

    win.webContents.on('console-message', (event, level, message) => {
      console.log(`RENDERER LOG [${level}]:`, message);
    });

    await win.loadFile(path.join(desktopPath, 'src/renderer/index.html'));
    await new Promise((r) => setTimeout(r, 1200));

    // Switch to Queue tab
    const result = await win.webContents.executeJavaScript(`
      (async () => {
        try {
          if (typeof switchTab === 'function') switchTab('queue');
          const tabBuild = document.getElementById('tabSubQueueBuild');
          if (tabBuild) tabBuild.click();

          console.log('Calling refreshPipelineQueueUI...');
          await refreshPipelineQueueUI();
          console.log('Finished refreshPipelineQueueUI, pipelineJobsList childCount:', document.getElementById('pipelineJobsList')?.children?.length);
          return { ok: true, childCount: document.getElementById('pipelineJobsList')?.children?.length };
        } catch (err) {
          console.error('JS eval error:', err.stack || err.message);
          return { ok: false, error: err.stack || err.message };
        }
      })()
    `);
    console.log('EVAL RESULT:', result);
    await new Promise((r) => setTimeout(r, 1000));
    console.log('EVAL RESULT:', result);
    await new Promise((r) => setTimeout(r, 800));

    const image = await win.webContents.capturePage();
    const targetDir1 = '/Users/2tamne/.gemini/antigravity/brain/1a778940-cb12-4abb-8dec-82d6da75a57d/screenshots';
    const targetDir2 = '/Users/2tamne/tool ffmpeg/screenshots';
    fs.mkdirSync(targetDir1, { recursive: true });
    fs.mkdirSync(targetDir2, { recursive: true });

    const p1 = path.join(targetDir1, 'project_build_queue_cards_state.png');
    const p2 = path.join(targetDir2, 'project_build_queue_cards_state.png');
    fs.writeFileSync(p1, image.toPNG());
    fs.writeFileSync(p2, image.toPNG());
    console.log('CARDS_SCREENSHOT_SAVED_1:', p1);
    console.log('CARDS_SCREENSHOT_SAVED_2:', p2);
  } catch (err) {
    console.error('Error capturing cards screenshot:', err);
  } finally {
    if (win) win.close();
    app.exit(0);
  }
});
