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
    await new Promise((r) => setTimeout(r, 1500));

    // Switch to Queue tab and select Build Queue subpane
    await win.webContents.executeJavaScript(`
      if (typeof switchTab === 'function') switchTab('queue');
      const tabBuild = document.getElementById('tabSubQueueBuild');
      if (tabBuild) tabBuild.click();
    `);
    await new Promise((r) => setTimeout(r, 1500));

    // Ensure buttons and pipeline section are rendered
    const status = await win.webContents.executeJavaScript(`
      (() => {
        const btnAdd = document.getElementById('btnAddBuildJob');
        const btnImport = document.getElementById('btnImportBundleQueue');
        const btnCloud = document.getElementById('btnImportCloudBundleQueue');
        const btnRunAll = document.getElementById('btnRunAllPipelineJobs');
        return {
          btnAddVisible: !!btnAdd && btnAdd.offsetParent !== null,
          btnImportVisible: !!btnImport && btnImport.offsetParent !== null,
          btnCloudVisible: !!btnCloud && btnCloud.offsetParent !== null,
          btnRunAllVisible: !!btnRunAll && btnRunAll.offsetParent !== null,
          btnAddText: btnAdd ? btnAdd.innerText.trim() : null,
          btnImportText: btnImport ? btnImport.innerText.trim() : null,
          btnCloudText: btnCloud ? btnCloud.innerText.trim() : null,
          btnRunAllText: btnRunAll ? btnRunAll.innerText.trim() : null,
        };
      })()
    `);
    console.log('QUEUE_BUTTONS_STATUS:', JSON.stringify(status, null, 2));

    const image = await win.webContents.capturePage();
    const targetDir1 = '/Users/2tamne/.gemini/antigravity/brain/1a778940-cb12-4abb-8dec-82d6da75a57d/screenshots';
    const targetDir2 = '/Users/2tamne/tool ffmpeg/screenshots';
    fs.mkdirSync(targetDir1, { recursive: true });
    fs.mkdirSync(targetDir2, { recursive: true });

    const p1 = path.join(targetDir1, 'project_build_queue_bundle_import.png');
    const p2 = path.join(targetDir2, 'project_build_queue_bundle_import.png');
    fs.writeFileSync(p1, image.toPNG());
    fs.writeFileSync(p2, image.toPNG());
    console.log('SCREENSHOT_SAVED_1:', p1);
    console.log('SCREENSHOT_SAVED_2:', p2);
  } catch (err) {
    console.error('Error capturing queue screenshot:', err);
  } finally {
    if (win) win.close();
    app.exit(0);
  }
});
