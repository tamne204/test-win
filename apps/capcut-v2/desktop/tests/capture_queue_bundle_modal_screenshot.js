const { app, BrowserWindow } = require('electron');
const path = require('path');
const fs = require('fs');

app.setName('2toolne-autoedit');
app.setPath('userData', '/Users/2tamne/Library/Application Support/2toolne-autoedit');

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

    await win.loadFile(path.join(desktopPath, 'src/renderer/index.html'));
    await new Promise((r) => setTimeout(r, 1200));

    // Switch to Queue tab and open preview modal
    const execRes = await win.webContents.executeJavaScript(`
      (() => {
        try {
          if (typeof switchTab === 'function') switchTab('queue');
          const tabBuild = document.getElementById('tabSubQueueBuild');
          if (tabBuild) tabBuild.click();

          if (typeof window.pipelineUiShowPreviewWithCandidates !== 'function') {
            return { error: 'window.pipelineUiShowPreviewWithCandidates is not a function' };
          }

          // Populate preview modal with sample candidate bundles
          window.pipelineUiShowPreviewWithCandidates([
            {
              bundleDir: '/Users/2tamne/Projects/AI_Bundles/Tokyo_Rainy_Night',
              name: 'Tokyo Rainy Night',
              selected: true,
              isDuplicate: false,
              validation: {
                ok: true,
                bundle_name: 'Tokyo Rainy Night',
                has_script: true,
                has_manifest: true,
                characters: [{ id: 'char_kenji', name: 'Kenji' }],
                scenes: [
                  { scene_id: '001', has_image: true, has_video: true, has_audio: true },
                  { scene_id: '002', has_image: true, has_video: false, has_audio: true },
                  { scene_id: '003', has_image: false, has_video: false, has_audio: false }
                ],
                warnings: []
              }
            },
            {
              bundleDir: '/Users/2tamne/Projects/AI_Bundles/Cyberpunk_Chase',
              name: 'Cyberpunk Chase',
              selected: true,
              isDuplicate: false,
              validation: {
                ok: true,
                bundle_name: 'Cyberpunk Chase',
                has_script: true,
                has_manifest: false,
                characters: [],
                scenes: [
                  { scene_id: '001', has_image: true, has_video: false, has_audio: true },
                  { scene_id: '002', has_image: false, has_video: false, has_audio: true }
                ],
                warnings: ['Không có 2toolne.json - Tự động phát hiện cấu trúc bundle']
              }
            },
            {
              bundleDir: '/Users/2tamne/Projects/AI_Bundles/Anime_Intro',
              name: 'Anime Intro',
              selected: false,
              isDuplicate: true,
              validation: {
                ok: true,
                bundle_name: 'Anime Intro',
                has_script: true,
                has_manifest: true,
                characters: [{ id: 'char_yuki', name: 'Yuki' }],
                scenes: [{ scene_id: '001', has_image: true, has_video: true, has_audio: true }],
                warnings: []
              }
            }
          ]);

          const modal = document.getElementById('modalBundleImportPreview');
          return {
            modalFound: !!modal,
            display: modal ? modal.style.display : null,
            listCards: document.getElementById('bundleImportPreviewList')?.children?.length
          };
        } catch (e) {
          return { error: e.message, stack: e.stack };
        }
      })()
    `);
    console.log('MODAL_EXEC_RESULT:', JSON.stringify(execRes, null, 2));
    await new Promise((r) => setTimeout(r, 800));

    const image = await win.webContents.capturePage();
    const targetDir1 = '/Users/2tamne/.gemini/antigravity/brain/1a778940-cb12-4abb-8dec-82d6da75a57d/screenshots';
    const targetDir2 = '/Users/2tamne/tool ffmpeg/screenshots';
    fs.mkdirSync(targetDir1, { recursive: true });
    fs.mkdirSync(targetDir2, { recursive: true });

    const p1 = path.join(targetDir1, 'bundle_import_preview_modal.png');
    const p2 = path.join(targetDir2, 'bundle_import_preview_modal.png');
    fs.writeFileSync(p1, image.toPNG());
    fs.writeFileSync(p2, image.toPNG());
    console.log('MODAL_SCREENSHOT_SAVED_1:', p1);
    console.log('MODAL_SCREENSHOT_SAVED_2:', p2);
  } catch (err) {
    console.error('Error capturing modal screenshot:', err);
  } finally {
    if (win) win.close();
    app.exit(0);
  }
});
