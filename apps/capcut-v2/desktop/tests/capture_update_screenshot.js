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
      width: 1280,
      height: 900,
      show: false,
      webPreferences: {
        preload: path.join(desktopPath, 'src/preload/preload.js'),
        nodeIntegration: false,
        contextIsolation: true,
      },
    });

    await win.loadFile(path.join(desktopPath, 'src/renderer/index.html'));
    await new Promise((r) => setTimeout(r, 1200));

    // Switch to Settings tab
    await win.webContents.executeJavaScript(`
      if (typeof switchTab === 'function') switchTab('settings');
    `);
    await new Promise((r) => setTimeout(r, 1500));

    // Scroll update card into view
    await win.webContents.executeJavaScript(`
      const card = document.getElementById('btnCheckUpdate');
      if (card) card.scrollIntoView({ behavior: 'instant', block: 'center' });
    `);
    await new Promise((r) => setTimeout(r, 500));

    const image = await win.webContents.capturePage();
    const screenshotPath = path.resolve(__dirname, '../../../screenshots/auto_update_settings_tab.png');
    fs.mkdirSync(path.dirname(screenshotPath), { recursive: true });
    fs.writeFileSync(screenshotPath, image.toPNG());
    console.log('SCREENSHOT_SAVED:', screenshotPath);
  } catch (err) {
    console.error('Error capturing settings screenshot:', err);
  } finally {
    if (win) win.close();
    app.exit(0);
  }
});
