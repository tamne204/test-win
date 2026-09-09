/**
 * apps/capcut-v2/desktop/src/main/flow/flow_browser_manager.js
 * Flow Browser Manager for Electron.
 *
 * Responsibilities:
 * - Creates and manages isolated WebContentsView (or BrowserView) for Google Flow.
 * - Enforces session partition per profile (persist:2toolne-flow-<uuid>).
 * - Restricts top-level navigation: allows only Google Flow & Google Auth.
 * - Confines window.open to embedded view for Google Flow & Auth URLs.
 * - Routes only unrelated external websites to shell.openExternal.
 * - Manages view pooling and lifecycle so switching tabs never duplicates views.
 * - Implements Auto Mode input lock & safe takeover hook.
 */

const { WebContentsView, BrowserView, session, shell } = require('electron');
const path = require('path');

const ALLOWED_NAV_HOSTS = [
  'labs.google',
  'accounts.google.com',
  'myaccount.google.com',
  'apis.google.com',
  'oauth2.googleapis.com',
  'accounts.youtube.com',
  'ssl.gstatic.com',
  'gstatic.com',
  'google.com',
  'googleapis.com',
  'googleusercontent.com',
];

class FlowBrowserManager {
  /**
   * @param {Object} options
   * @param {import('electron').BrowserWindow} options.mainWindow
   * @param {import('./flow_profile_manager').FlowProfileManager} options.profileManager
   * @param {import('./flow_download_manager').FlowDownloadManager} options.downloadManager
   * @param {import('./google_flow_adapter').GoogleFlowAdapter} options.flowAdapter
   */
  constructor(options = {}) {
    this.mainWindow = options.mainWindow;
    this.profileManager = options.profileManager;
    this.downloadManager = options.downloadManager;
    this.flowAdapter = options.flowAdapter;

    this.profileViews = new Map(); // profileId -> { view, session, wcId, profileId }
    this.currentView = null;
    this.currentSession = null;
    this.isVisible = false;
    this.lastBounds = { x: 0, y: 0, width: 800, height: 600 };
    this.flowUrl = 'https://labs.google/fx/tools/flow';

    const activeProf = this.profileManager.getActiveProfile();
    if (activeProf) {
      this.currentView = this.getOrCreateView(activeProf.id);
    }

    // Connect adapter getWebContents hook
    if (this.flowAdapter) {
      this.flowAdapter.getWebContents = () => this.getWebContents();
      this.flowAdapter.on('mode-changed', ({ mode }) => {
        this.updateInputLock(mode === 'AUTO');
      });
    }
  }

  /**
   * Determine whether a URL is allowed for top-level or popup navigation inside the embedded view.
   * @param {string} url
   * @returns {boolean}
   */
  static isAllowedUrl(url) {
    try {
      const parsed = new URL(url);
      const hostname = parsed.hostname.toLowerCase();
      return ALLOWED_NAV_HOSTS.some(allowed => hostname === allowed || hostname.endsWith(`.${allowed}`));
    } catch {
      return false;
    }
  }

  /**
   * Get an existing view for a profile or lazily create a new one.
   * @param {string} profileId
   * @returns {WebContentsView|BrowserView|null}
   */
  getOrCreateView(profileId) {
    if (!this.mainWindow || this.mainWindow.isDestroyed()) return null;

    const profile = this.profileManager.getProfile(profileId) || this.profileManager.getActiveProfile();
    if (!profile) return null;

    if (this.profileViews.has(profile.id)) {
      return this.profileViews.get(profile.id).view;
    }

    const partition = profile.partition || `persist:2toolne-flow-${profile.id}`;
    const sess = session.fromPartition(partition);
    this.downloadManager.attachToSession(sess);

    const webPreferences = {
      session: sess,
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    };

    let view = null;
    if (typeof WebContentsView === 'function') {
      view = new WebContentsView({ webPreferences });
    } else if (typeof BrowserView === 'function') {
      view = new BrowserView({ webPreferences });
    }

    if (!view) {
      console.warn('[FlowBrowserManager] Neither WebContentsView nor BrowserView supported.');
      return null;
    }

    const wc = view.webContents;
    if (wc) {
      // 1. Filter top-level navigation
      wc.on('will-navigate', (event, targetUrl) => {
        if (!FlowBrowserManager.isAllowedUrl(targetUrl)) {
          event.preventDefault();
          console.info(`[FlowBrowserManager] Blocked internal navigation to: ${targetUrl}. Opening externally.`);
          shell.openExternal(targetUrl).catch(() => {});
        }
      });

      // 2. Filter window.open: Keep Google Flow & Auth embedded; only external sites open externally
      wc.setWindowOpenHandler(({ url }) => {
        if (FlowBrowserManager.isAllowedUrl(url)) {
          console.info(`[FlowBrowserManager] Retaining in-view navigation for: ${url}`);
          setImmediate(() => {
            if (!wc.isDestroyed()) {
              wc.loadURL(url).catch(err => {
                console.warn('[FlowBrowserManager] Failed navigating in-view to:', url, err.message);
              });
            }
          });
          return { action: 'deny' }; // Never open a separate external window for Flow/Auth
        }
        console.info(`[FlowBrowserManager] External link routed to system browser: ${url}`);
        shell.openExternal(url).catch(() => {});
        return { action: 'deny' };
      });

      // 3. Proactively refresh auth on navigation and load completion
      const notifyStatusChange = async () => {
        if (this.flowAdapter && !wc.isDestroyed()) {
          try {
            const auth = await this.flowAdapter.checkAuthStatus(wc);
            if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
              this.mainWindow.webContents.send('flow:status-updated', {
                auth,
                profile,
                mode: this.flowAdapter.getMode(),
              });
            }
          } catch (_) {}
        }
      };

      wc.on('did-finish-load', () => {
        setTimeout(notifyStatusChange, 500);
      });

      wc.on('did-navigate', () => {
        notifyStatusChange();
      });

      // Load initial Flow page
      wc.loadURL(this.flowUrl).catch(err => {
        console.warn('[FlowBrowserManager] Failed to load Flow URL:', err.message);
      });
    }

    this.profileViews.set(profile.id, {
      view,
      session: sess,
      wcId: wc ? wc.id : null,
      profileId: profile.id,
    });

    return view;
  }

  getWebContents() {
    if (!this.currentView) return null;
    return this.currentView.webContents || null;
  }

  /**
   * Switch the active account partition and view.
   * Preserves both session partitions with zero cookie leakage.
   * @param {string} profileId
   */
  switchProfile(profileId) {
    const prevProfile = this.profileManager.getActiveProfile();
    if (prevProfile && this.profileViews.has(prevProfile.id)) {
      const prevEntry = this.profileViews.get(prevProfile.id);
      if (prevEntry?.view && typeof prevEntry.view.setVisible === 'function') {
        prevEntry.view.setVisible(false);
      }
    }

    this.profileManager.setActiveProfile(profileId);
    this.currentView = this.getOrCreateView(profileId);

    // Update adapter's webContents reference
    if (this.flowAdapter) {
      this.flowAdapter.getWebContents = () => this.getWebContents();
    }

    if (this.isVisible) {
      this.show(this.lastBounds);
    }
  }

  /**
   * Attach and position view in mainWindow.
   * Ensures only the active profile's view is visible.
   * @param {{x: number, y: number, width: number, height: number}} bounds
   */
  show(bounds) {
    if (!this.mainWindow || this.mainWindow.isDestroyed()) return;

    const activeProfile = this.profileManager.getActiveProfile();
    if (!activeProfile) return;

    this.currentView = this.getOrCreateView(activeProfile.id);
    if (!this.currentView) return;

    this.lastBounds = bounds || this.lastBounds;
    this.isVisible = true;

    if (this.mainWindow.contentView && typeof this.mainWindow.contentView.addChildView === 'function') {
      const children = this.mainWindow.contentView.children || [];
      if (!children.includes(this.currentView)) {
        this.mainWindow.contentView.addChildView(this.currentView);
      }
      this.currentView.setBounds(this.lastBounds);
      if (typeof this.currentView.setVisible === 'function') {
        this.currentView.setVisible(true);
      }

      // Ensure all other inactive views are hidden
      for (const [pId, entry] of this.profileViews.entries()) {
        if (pId !== activeProfile.id && entry.view) {
          if (typeof entry.view.setVisible === 'function') {
            entry.view.setVisible(false);
          }
        }
      }
    } else if (typeof this.mainWindow.setBrowserView === 'function') {
      this.mainWindow.setBrowserView(this.currentView);
      this.currentView.setBounds(this.lastBounds);
    }
  }

  /**
   * Hide view when user switches to other tabs (Studio, Queue, etc.).
   * WebContents continues executing background tasks!
   */
  hide() {
    this.isVisible = false;
    if (!this.mainWindow || this.mainWindow.isDestroyed() || !this.currentView) return;

    if (typeof this.currentView.setVisible === 'function') {
      this.currentView.setVisible(false);
    } else if (this.mainWindow.contentView && typeof this.mainWindow.contentView.removeChildView === 'function') {
      this.mainWindow.contentView.removeChildView(this.currentView);
    } else if (typeof this.mainWindow.removeBrowserView === 'function') {
      this.mainWindow.removeBrowserView(this.currentView);
    }
  }

  setBounds(bounds) {
    this.lastBounds = bounds;
    if (this.isVisible && this.currentView) {
      this.currentView.setBounds(bounds);
    }
  }

  reload() {
    const wc = this.getWebContents();
    if (wc && !wc.isDestroyed()) {
      wc.reload();
    }
  }

  navigateToFlow() {
    const wc = this.getWebContents();
    if (wc && !wc.isDestroyed()) {
      wc.loadURL(this.flowUrl).catch(() => {});
    }
  }

  /**
   * Toggle visual scrim / input lock on the Flow view WebContents.
   * @param {boolean} locked
   */
  async updateInputLock(locked) {
    const wc = this.getWebContents();
    if (!wc || wc.isDestroyed()) return;

    try {
      if (locked) {
        await wc.executeJavaScript(`
          (() => {
            let scrim = document.getElementById('toolne-flow-lock-overlay');
            if (!scrim) {
              scrim = document.createElement('div');
              scrim.id = 'toolne-flow-lock-overlay';
              scrim.style.cssText = 'position:fixed; inset:0; z-index:2147483647; background:rgba(15,23,42,0.65); backdrop-filter:blur(2px); display:flex; flex-direction:column; align-items:center; justify-content:center; color:#fff; font-family:system-ui, sans-serif; pointer-events:all; user-select:none;';
              scrim.innerHTML = \`
                <div style="background:#1e293b; border:1px solid #38bdf8; border-radius:12px; padding:24px 32px; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
                  <div style="font-size:28px; margin-bottom:8px;">⚡</div>
                  <div style="font-size:16px; font-weight:700; color:#38bdf8; margin-bottom:6px;">2TOOLNE đang điều khiển Google Flow</div>
                  <div style="font-size:13px; color:#94a3b8; margin-bottom:16px;">Thao tác chuột và bàn phím trên bề mặt Flow đã được khóa an toàn.</div>
                  <div style="font-size:11px; color:#64748b;">(Bạn có thể chuyển sang tab khác hoặc bấm "Dừng tự động & Điều khiển" trên thanh công cụ 2TOOLNE)</div>
                </div>
              \`;
              document.documentElement.appendChild(scrim);
            }
            scrim.style.display = 'flex';
          })()
        `);
      } else {
        await wc.executeJavaScript(`
          (() => {
            const scrim = document.getElementById('toolne-flow-lock-overlay');
            if (scrim) scrim.style.display = 'none';
          })()
        `);
      }
    } catch {}
  }

  getActiveViewsCount() {
    return this.profileViews.size;
  }

  getVisibleViewProfileId() {
    if (!this.isVisible) return null;
    const activeProfile = this.profileManager.getActiveProfile();
    return activeProfile ? activeProfile.id : null;
  }

  destroyView() {
    this.hide();
    for (const [pId, entry] of this.profileViews.entries()) {
      try {
        if (this.mainWindow?.contentView && typeof this.mainWindow.contentView.removeChildView === 'function') {
          this.mainWindow.contentView.removeChildView(entry.view);
        }
        if (entry.view?.webContents && !entry.view.webContents.isDestroyed()) {
          entry.view.webContents.close();
        }
      } catch (_) {}
    }
    this.profileViews.clear();
    this.currentView = null;
    this.currentSession = null;
  }
}

module.exports = { FlowBrowserManager, ALLOWED_NAV_HOSTS };
