/**
 * apps/capcut-v2/desktop/src/main/global_popover_manager.js
 * 2TOOLNE Global Native Popover Manager.
 *
 * Responsibilities:
 * - Manages a single reusable native WebContentsView (GlobalPopoverView).
 * - Guarantees native z-order: App Shell DOM < Flow WebContentsView < GlobalPopoverView.
 * - Sized strictly to popover bounding rect + shadow margin (zero fullscreen click interception).
 * - NEVER resizes, repositions, or reloads Google Flow WebContents.
 * - Seamlessly routes actions (SELECT_WORKSPACE, CREATE_TEAM, MANAGE_TEAM) back to main app shell.
 */

const { WebContentsView, ipcMain } = require('electron');
const path = require('path');

const PRELOAD_PATH = path.join(__dirname, 'global_popover_preload.js');
const POPOVER_HTML_PATH = path.join(__dirname, '../renderer/popover.html');
const SHADOW_MARGIN = 10; // px padding around card for soft native shadow without clipping

class GlobalPopoverManager {
  /**
   * @param {Object} options
   * @param {import('electron').BrowserWindow} options.mainWindow
   * @param {import('./flow/flow_browser_manager').FlowBrowserManager} [options.flowBrowserManager]
   */
  constructor(options = {}) {
    this.mainWindow = options.mainWindow;
    this.flowBrowserManager = options.flowBrowserManager || null;

    this.popoverView = null;
    this.isOpenState = false;
    this.currentType = null;
    this.lastBounds = null;
    this.lastData = null;

    GlobalPopoverManager._instance = this;

    this.initView();
    this.registerIpcHandlers();
  }

  /**
   * Initialize long-lived WebContentsView once.
   */
  initView() {
    if (!this.mainWindow || this.mainWindow.isDestroyed()) return;

    const webPreferences = {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
      preload: PRELOAD_PATH,
      backgroundThrottling: false,
    };

    this.popoverView = new WebContentsView({ webPreferences });
    if (typeof this.popoverView.setBackgroundColor === 'function') {
      this.popoverView.setBackgroundColor('#00000000'); // Completely transparent background
    }

    const wc = this.popoverView.webContents;
    wc.on('did-finish-load', () => {
      if (this.isOpenState && this.lastData) {
        wc.send('popover:render', this.lastData);
      }
    });
    wc.loadFile(POPOVER_HTML_PATH);

    // Prevent navigation to external sites
    wc.on('will-navigate', (e) => e.preventDefault());
    wc.setWindowOpenHandler(() => ({ action: 'deny' }));
  }

  /**
   * Register IPC handlers for popover coordination.
   */
  registerIpcHandlers() {
    if (GlobalPopoverManager._ipcRegistered) return;
    GlobalPopoverManager._ipcRegistered = true;

    ipcMain.handle('popover:get-data', async () => {
      return GlobalPopoverManager._instance?.lastData || null;
    });

    ipcMain.handle('popover:open', async (_, { type, anchorRect, model }) => {
      if (GlobalPopoverManager._instance) {
        GlobalPopoverManager._instance.open({ type, anchorRect, model });
      }
      return { ok: true };
    });

    ipcMain.handle('popover:close', async () => {
      if (GlobalPopoverManager._instance) {
        GlobalPopoverManager._instance.close();
      }
      return { ok: true };
    });

    ipcMain.handle('popover:toggle', async (_, { type, anchorRect, model }) => {
      if (GlobalPopoverManager._instance) {
        GlobalPopoverManager._instance.toggle({ type, anchorRect, model });
      }
      return { ok: true, isOpen: GlobalPopoverManager._instance?.isOpen() };
    });

    ipcMain.handle('popover:is-open', async () => {
      return { isOpen: Boolean(GlobalPopoverManager._instance?.isOpen()) };
    });

    ipcMain.handle('popover:action', async (_, { action, payload }) => {
      if (GlobalPopoverManager._instance) {
        GlobalPopoverManager._instance.handlePopoverAction(action, payload);
      }
      return { ok: true };
    });
  }

  /**
   * Set global active instance for IPC dispatching.
   */
  setActiveInstance() {
    GlobalPopoverManager._instance = this;
  }

  /**
   * Check if popover is currently active.
   * @returns {boolean}
   */
  isOpen() {
    return this.isOpenState;
  }

  /**
   * Open popover overlay positioned relative to anchor element.
   * Flow WebContents bounds remain 100% UNTOUCHED.
   * @param {{ type: string, anchorRect: { x: number, y: number, width: number, height: number }, model: any }} param0
   */
  open({ type, anchorRect, model = {} }) {
    if (!this.mainWindow || this.mainWindow.isDestroyed() || !this.popoverView) return;

    const winBounds = this.mainWindow.getContentBounds();
    const anchorX = Math.round(anchorRect?.x ?? anchorRect?.left ?? 0);
    const anchorY = Math.round(anchorRect?.y ?? anchorRect?.top ?? 0);
    const anchorW = Math.round(anchorRect?.width ?? 0);
    const anchorH = Math.round(anchorRect?.height ?? 0);

    // Compute dimensions according to content type
    let cardWidth = Math.max(260, anchorW);
    let cardHeight = 160; // fallback

    if (type === 'workspace') {
      const spaces = model.workspaces || [];
      const isCurrentTeam = model.activeWorkspace?.space_type === 'TEAM';
      const listHeight = Math.min(220, Math.max(1, spaces.length) * 38);
      // Header (30) + List + Divider (9) + Create Team (38) + [Manage Team (38)] + Padding (14)
      cardHeight = 30 + listHeight + 9 + 38 + (isCurrentTeam ? 38 : 0) + 14;
    }

    // Boundary check: align right if exceeding window right edge
    let cardLeft = anchorX;
    if (cardLeft + cardWidth > winBounds.width - 12) {
      cardLeft = Math.max(12, winBounds.width - cardWidth - 12);
    }
    const cardTop = anchorY + anchorH + 6; // 6px gap below anchor

    // View bounds contain the 10px shadow margin so box-shadow renders cleanly
    const viewBounds = {
      x: Math.max(0, cardLeft - SHADOW_MARGIN),
      y: Math.max(0, cardTop - SHADOW_MARGIN),
      width: cardWidth + (SHADOW_MARGIN * 2),
      height: cardHeight + (SHADOW_MARGIN * 2),
    };

    this.lastBounds = viewBounds;
    this.popoverView.setBounds(viewBounds);

    // Enforce Native Z-Order: PopoverView must be the top-most child of contentView
    if (this.mainWindow.contentView && typeof this.mainWindow.contentView.addChildView === 'function') {
      const children = this.mainWindow.contentView.children || [];
      if (children.includes(this.popoverView)) {
        try {
          this.mainWindow.contentView.removeChildView(this.popoverView);
        } catch (_) {}
      }
      this.mainWindow.contentView.addChildView(this.popoverView);
      if (typeof this.popoverView.setVisible === 'function') {
        this.popoverView.setVisible(true);
      }
    }

    this.lastData = { type, model };
    this.isOpenState = true;
    this.currentType = type;

    // Dispatch data to popover renderer
    const wc = this.popoverView.webContents;
    if (wc && !wc.isDestroyed()) {
      if (wc.isLoading()) {
        wc.once('did-finish-load', () => {
          if (this.isOpenState && this.lastData) {
            wc.send('popover:render', this.lastData);
          }
        });
      } else {
        wc.send('popover:render', { type, model });
      }
    }
  }

  /**
   * Close popover overlay and detach from contentView.
   */
  close() {
    if (!this.isOpenState) return;
    this.lastData = null;

    if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.contentView) {
      try {
        this.mainWindow.contentView.removeChildView(this.popoverView);
      } catch (_) {}
    }

    if (this.popoverView && typeof this.popoverView.setVisible === 'function') {
      this.popoverView.setVisible(false);
    }

    this.isOpenState = false;
    this.currentType = null;

    if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
      this.mainWindow.webContents.send('popover:closed');
    }
  }

  /**
   * Toggle popover between open and closed.
   */
  toggle({ type, anchorRect, model }) {
    if (this.isOpenState && this.currentType === type) {
      this.close();
    } else {
      this.open({ type, anchorRect, model });
    }
  }

  /**
   * Re-assert top-most native z-order (e.g., when Flow view re-attaches on tab return).
   */
  bringToTop() {
    if (!this.isOpenState || !this.mainWindow?.contentView || !this.popoverView) return;
    const children = this.mainWindow.contentView.children || [];
    if (children.includes(this.popoverView)) {
      try {
        this.mainWindow.contentView.removeChildView(this.popoverView);
      } catch (_) {}
    }
    this.mainWindow.contentView.addChildView(this.popoverView);
  }

  /**
   * Handle user action from popover view.
   * Closes popover and forwards action to main window renderer.
   * @param {string} action
   * @param {any} payload
   */
  handlePopoverAction(action, payload) {
    this.close();
    if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
      this.mainWindow.webContents.send('popover:action', { action, payload });
    }
  }

  /**
   * Destroy popover view upon app exit.
   */
  destroy() {
    this.close();
    if (this.popoverView) {
      const wc = this.popoverView.webContents;
      if (wc && !wc.isDestroyed()) {
        wc.destroy();
      }
      this.popoverView = null;
    }
  }
}

GlobalPopoverManager._ipcRegistered = false;
GlobalPopoverManager._instance = null;

module.exports = { GlobalPopoverManager };
