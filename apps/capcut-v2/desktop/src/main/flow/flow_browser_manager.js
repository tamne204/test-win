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

const { WebContentsView, BrowserView, session, shell, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');

const OVERLAY_PRELOAD_PATH = path.join(__dirname, 'flow_overlay_preload.js');
const OVERLAY_LAYER_PATH = path.join(__dirname, 'flow_overlay_layer.js');

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
    this.pipelineQueue = options.pipelineQueue || null;

    this.profileViews = new Map(); // profileId -> { view, session, wcId, profileId }
    this.currentView = null;
    this.currentSession = null;
    this.isVisible = false;
    this.lastBounds = { x: 0, y: 0, width: 800, height: 600 };
    this.flowUrl = 'https://labs.google/fx/tools/flow';

    const activeProf = this.profileManager ? this.profileManager.getActiveProfile() : null;
    if (activeProf) {
      this.currentView = this.getOrCreateView(activeProf.id);
    }

    // Connect adapter getWebContents hook
    if (this.flowAdapter) {
      this.flowAdapter.getWebContents = () => this.getWebContents();
      this.flowAdapter.on('mode-changed', ({ mode }) => {
        this.updateInputLock(mode === 'AUTO');
      });
      this.flowAdapter.on('activity-event', (event) => {
        this.handleActivityEvent(event);
      });
    }

    FlowBrowserManager._activeInstance = this;
    if (!FlowBrowserManager._overlayIpcBound) {
      FlowBrowserManager._overlayIpcBound = true;
      if (ipcMain && typeof ipcMain.on === 'function') {
        ipcMain.on('flow:overlay-action', (_, { action, payload }) => {
          if (FlowBrowserManager._activeInstance) {
            FlowBrowserManager._activeInstance.handleOverlayAction(action, payload);
          }
        });
      }
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
      const existingRuntime = this.profileViews.get(profile.id);
      if (existingRuntime && existingRuntime.view && existingRuntime.webContents && !existingRuntime.webContents.isDestroyed()) {
        return existingRuntime.view;
      }
    }

    const partition = profile.partition || `persist:2toolne-flow-${profile.id}`;
    const sess = session.fromPartition(partition);
    this.downloadManager.attachToSession(sess);

    const webPreferences = {
      session: sess,
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: false,
      preload: OVERLAY_PRELOAD_PATH,
      backgroundThrottling: false, // Ensure Chromium does not freeze background timers during automation
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
    const runtime = {
      profileId: profile.id,
      view,
      webContents: wc,
      session: sess,
      attached: false,
      loaded: false,
      currentUrl: this.flowUrl,
      cdpAttached: false,
      automationState: 'IDLE',
      backgroundThrottling: false,
      isHealthy: true,
      navigationCount: 0,
      reloadCount: 0,
    };
    this.profileViews.set(profile.id, runtime);

    if (wc) {
      // Background throttling: default to false (unthrottled) during automation
      try {
        wc.setBackgroundThrottling(false);
      } catch (_) {}

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

      // 3. Track main frame navigations
      wc.on('did-start-navigation', (event, url, isInMainFrame, isSameDocument) => {
        if (isInMainFrame && !isSameDocument) {
          runtime.navigationCount = (runtime.navigationCount || 0) + 1;
          runtime.currentUrl = url;
        }
      });

      // 4. Render process crash recovery listener
      wc.on('render-process-gone', (event, details) => {
        console.warn(`[FlowBrowserManager] Render process gone for profile ${profile.id}: reason=${details?.reason}, exitCode=${details?.exitCode}`);
        runtime.isHealthy = false;
        runtime.automationState = 'ERROR';
        if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
          this.mainWindow.webContents.send('flow:render-process-gone', {
            profileId: profile.id,
            reason: details?.reason,
            exitCode: details?.exitCode,
          });
        }
      });

      // 5. Long-lived Chrome DevTools Protocol (CDP) Debugger attachment
      const attachCdpDebugger = () => {
        try {
          if (!wc.isDestroyed() && wc.debugger && !wc.debugger.isAttached()) {
            wc.debugger.attach('1.3');
            wc.debugger.sendCommand('Network.enable').catch(() => {});
            wc.debugger.sendCommand('Page.enable').catch(() => {});
            runtime.cdpAttached = true;
          }
        } catch (cdpErr) {
          console.info('[FlowBrowserManager] Debugger attach status:', cdpErr.message);
        }
      };
      attachCdpDebugger();

      if (wc.debugger) {
        wc.debugger.on('detach', (event, reason) => {
          runtime.cdpAttached = false;
          console.info('[FlowBrowserManager] CDP debugger detached:', reason);
        });
      }

      // 5.1 Notify main window when Flow WebContents gains focus
      wc.on('focus', () => {
        if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
          this.mainWindow.webContents.send('flow:view-focused');
        }
      });

      // 6. Proactively refresh auth on navigation and load completion
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
        runtime.loaded = true;
        setTimeout(notifyStatusChange, 500);
        setTimeout(() => {
          this.ensureOverlayInjected(wc);
          this.rehydrateOverlayState(wc);
        }, 600);
      });

      wc.on('did-navigate', () => {
        notifyStatusChange();
        setTimeout(() => {
          this.ensureOverlayInjected(wc);
          this.rehydrateOverlayState(wc);
        }, 400);
      });

      wc.on('did-navigate-in-page', () => {
        setTimeout(() => {
          this.ensureOverlayInjected(wc);
          this.rehydrateOverlayState(wc);
        }, 300);
      });

      // Load initial Flow page once
      wc.loadURL(this.flowUrl).catch(err => {
        console.warn('[FlowBrowserManager] Failed to load Flow URL:', err.message);
      });
    }

    // Only attach to window if view is currently visible
    if (this.mainWindow && !this.mainWindow.isDestroyed() && this.isVisible) {
      if (this.mainWindow.contentView && typeof this.mainWindow.contentView.addChildView === 'function') {
        const children = this.mainWindow.contentView.children || [];
        if (!children.includes(view)) {
          this.mainWindow.contentView.addChildView(view);
        }
        view.setBounds(this.lastBounds);
        if (typeof view.setVisible === 'function') view.setVisible(true);
        runtime.attached = true;
      }
    }

    return view;
  }

  getCurrentRuntime() {
    const activeProf = this.profileManager ? this.profileManager.getActiveProfile() : null;
    if (!activeProf) return null;
    return this.profileViews.get(activeProf.id) || null;
  }

  getRuntime(profileId) {
    return this.profileViews.get(profileId) || null;
  }

  getWebContents() {
    const runtime = this.getCurrentRuntime();
    if (runtime && runtime.webContents && !runtime.webContents.isDestroyed()) {
      return runtime.webContents;
    }
    if (this.currentView && this.currentView.webContents && !this.currentView.webContents.isDestroyed()) {
      return this.currentView.webContents;
    }
    return null;
  }

  /**
   * Check whether an automation task or pipeline job is actively executing.
   * @returns {boolean}
   */
  isAutomationRunning() {
    if (this.pipelineQueue && this.pipelineQueue.activeJobId) {
      const job = this.pipelineQueue.jobs?.get(this.pipelineQueue.activeJobId);
      if (job && !['PROJECT_READY', 'FAILED', 'CANCELLED', 'PAUSED'].includes(job.state)) {
        return true;
      }
    }
    if (this.flowAdapter) {
      if (this.flowAdapter.currentTask) return true;
      if (typeof this.flowAdapter.isWorking === 'function' && this.flowAdapter.isWorking()) return true;
    }
    for (const rt of this.profileViews.values()) {
      if (rt.automationState === 'RUNNING') return true;
    }
    return false;
  }

  /**
   * Dynamically toggles background throttling on all/active Flow runtimes.
   * When isActive=true: backgroundThrottling=false (runs at full speed in background).
   * When isActive=false: backgroundThrottling=true (conserves CPU when idle).
   * @param {boolean} isActive
   */
  setJobThrottling(isActive) {
    const throttle = !isActive;
    for (const [pId, rt] of this.profileViews.entries()) {
      const wc = rt.webContents;
      if (wc && !wc.isDestroyed()) {
        try {
          wc.setBackgroundThrottling(throttle);
          rt.backgroundThrottling = throttle;
          rt.automationState = isActive ? 'RUNNING' : 'IDLE';
        } catch (err) {
          console.warn(`[FlowBrowserManager] setBackgroundThrottling error for ${pId}:`, err.message);
        }
      }
    }
  }

  /**
   * Switch the active account partition and view.
   * Preserves both session partitions with zero cookie leakage and zero reload.
   * @param {string} profileId
   */
  switchProfile(profileId) {
    const prevProfile = this.profileManager.getActiveProfile();
    if (prevProfile && this.profileViews.has(prevProfile.id)) {
      const prevEntry = this.profileViews.get(prevProfile.id);
      if (prevEntry?.view) {
        if (this.mainWindow?.contentView && typeof this.mainWindow.contentView.removeChildView === 'function') {
          try {
            this.mainWindow.contentView.removeChildView(prevEntry.view);
          } catch (_) {}
        }
        if (typeof prevEntry.view.setVisible === 'function') {
          prevEntry.view.setVisible(false);
        }
        prevEntry.attached = false;
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
    } else if (this.currentView) {
      const wc = this.currentView.webContents;
      if (wc && !wc.isDestroyed()) {
        this.ensureOverlayInjected(wc);
        this.rehydrateOverlayState(wc);
      }
    }
  }

  /**
   * Attach and position view in mainWindow.
   * Ensures only the active profile's view is visible.
   * Reattaches the SAME WebContentsView without reloading.
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

    const activeRuntime = this.profileViews.get(activeProfile.id);

    if (this.mainWindow.contentView && typeof this.mainWindow.contentView.addChildView === 'function') {
      const children = this.mainWindow.contentView.children || [];
      if (!children.includes(this.currentView)) {
        this.mainWindow.contentView.addChildView(this.currentView);
      }
      this.currentView.setBounds(this.lastBounds);
      if (typeof this.currentView.setVisible === 'function') {
        this.currentView.setVisible(true);
      }
      if (activeRuntime) {
        activeRuntime.attached = true;
      }

      // If global popover is currently active, guarantee it stays topmost
      if (this.globalPopoverManager && typeof this.globalPopoverManager.bringToTop === 'function') {
        this.globalPopoverManager.bringToTop();
      }

      // Ensure all other inactive views are detached and hidden
      for (const [pId, entry] of this.profileViews.entries()) {
        if (pId !== activeProfile.id && entry.view) {
          if (children.includes(entry.view)) {
            try {
              this.mainWindow.contentView.removeChildView(entry.view);
            } catch (_) {}
          }
          if (typeof entry.view.setVisible === 'function') {
            entry.view.setVisible(false);
          }
          entry.attached = false;
        }
      }
    } else if (typeof this.mainWindow.setBrowserView === 'function') {
      this.mainWindow.setBrowserView(this.currentView);
      this.currentView.setBounds(this.lastBounds);
      if (activeRuntime) activeRuntime.attached = true;
    }

    const wc = this.currentView?.webContents;
    if (wc && !wc.isDestroyed()) {
      // Force Chromium GPU compositor repaint when revealing view from offscreen
      try {
        if (typeof wc.invalidate === 'function') wc.invalidate();
      } catch (_) {}

      // Automatic Micro-Nudge (25ms): forces Chromium RenderWidgetHostView to recreate swapchain
      // and triggers Angular window.resize inside Flow without user needing to drag window
      if (this._showNudgeTimer) clearTimeout(this._showNudgeTimer);
      this._showNudgeTimer = setTimeout(() => {
        if (this.isVisible && this.currentView && !this.mainWindow.isDestroyed()) {
          try {
            const targetBounds = this.lastBounds;
            const nudgeBounds = {
              ...targetBounds,
              height: Math.max(10, targetBounds.height - 1),
            };
            this.currentView.setBounds(nudgeBounds);
            setTimeout(() => {
              if (this.isVisible && this.currentView && !this.mainWindow.isDestroyed()) {
                this.currentView.setBounds(targetBounds);
                const w = this.currentView.webContents;
                if (w && !w.isDestroyed() && typeof w.invalidate === 'function') {
                  w.invalidate();
                }
              }
            }, 25);
          } catch (_) {}
        }
      }, 30);

      // Requirement 22: When user opens Flow tab, prefer showing the Flow Project belonging to the active Job
      const activeJob = this.pipelineQueue?.jobs ? Array.from(this.pipelineQueue.jobs.values()).find(j =>
        !['PROJECT_READY', 'CANCELLED', 'FAILED'].includes(j.state) && j.flow_project_id
      ) : null;

      if (activeJob && activeJob.flow_project_id) {
        const currentUrl = (typeof wc.getURL === 'function' ? wc.getURL() : '') || '';
        if (!currentUrl.includes(`/project/${activeJob.flow_project_id}`) && typeof wc.loadURL === 'function') {
          wc.loadURL(`https://flow.google.com/project/${activeJob.flow_project_id}`);
        }
      }

      this.ensureOverlayInjected(wc);
      this.rehydrateOverlayState(wc);
    }
  }

  /**
   * Hide view when user switches to other tabs (Studio, Queue, etc.).
   * WebContents continues executing background tasks at full capability!
   */
  hide() {
    this.isVisible = false;
    if (!this.mainWindow || this.mainWindow.isDestroyed() || !this.currentView) return;

    const activeRuntime = this.getCurrentRuntime();
    if (activeRuntime) {
      activeRuntime.attached = false;
    }

    // Per GEMINI.md Section 10: Detach view from window hierarchy so it NEVER occludes other tabs.
    // WebContents continues running at full capability in background!
    if (this.mainWindow.contentView && typeof this.mainWindow.contentView.removeChildView === 'function') {
      try {
        const children = this.mainWindow.contentView.children || [];
        if (children.includes(this.currentView)) {
          this.mainWindow.contentView.removeChildView(this.currentView);
        }
      } catch (_) {}
    } else if (typeof this.mainWindow.setBrowserView === 'function') {
      try {
        this.mainWindow.setBrowserView(null);
      } catch (_) {}
    }

    if (typeof this.currentView.setVisible === 'function') {
      try {
        this.currentView.setVisible(false);
      } catch (_) {}
    }

    // Set offscreen coordinates as defense in depth
    try {
      this.currentView.setBounds({ x: -10000, y: -10000, width: 800, height: 600 });
    } catch (_) {}
  }

  setBounds(bounds) {
    if (!bounds) return;
    const safeBounds = {
      x: Math.max(0, Math.round(bounds.x || 0)),
      y: Math.max(0, Math.round(bounds.y || 0)),
      width: Math.max(0, Math.round(bounds.width || 0)),
      height: Math.max(0, Math.round(bounds.height || 0)),
    };
    this.lastBounds = safeBounds;
    if (this.isVisible && this.currentView) {
      this.currentView.setBounds(safeBounds);
      const wc = this.currentView.webContents;
      if (wc && !wc.isDestroyed()) {
        try {
          if (typeof wc.invalidate === 'function') wc.invalidate();
        } catch (_) {}
      }
    }
  }

  /**
   * Reload active Flow WebContents.
   * Requires confirmation if an automation job is currently running.
   * @param {Object} [options]
   * @param {boolean} [options.force=false]
   * @returns {{ ok: boolean, requiresConfirmation?: boolean, reason?: string }}
   */
  reload(options = {}) {
    const runtime = this.getCurrentRuntime();
    if (!runtime || !runtime.webContents || runtime.webContents.isDestroyed()) {
      return { ok: false, error: 'NO_ACTIVE_FLOW_VIEW' };
    }
    if (!options.force && this.isAutomationRunning()) {
      return { ok: false, requiresConfirmation: true, reason: 'AUTOMATION_RUNNING' };
    }
    runtime.reloadCount = (runtime.reloadCount || 0) + 1;
    runtime.webContents.reload();
    return { ok: true };
  }

  navigateToFlow() {
    const runtime = this.getCurrentRuntime();
    const wc = runtime ? runtime.webContents : this.getWebContents();
    if (wc && !wc.isDestroyed()) {
      wc.loadURL(this.flowUrl).catch(() => {});
    }
  }

  /**
   * Return complete runtime status snapshot for all profiles.
   */
  getFlowRuntimeStatus() {
    const runtimes = [];
    for (const [profileId, rt] of this.profileViews.entries()) {
      const wc = rt.webContents;
      const isAlive = Boolean(wc && !wc.isDestroyed());
      runtimes.push({
        profileId,
        wcId: isAlive ? wc.id : null,
        attached: rt.attached,
        loaded: rt.loaded,
        currentUrl: isAlive ? wc.getURL() : rt.currentUrl,
        cdpAttached: isAlive ? (wc.debugger ? wc.debugger.isAttached() : false) : false,
        automationState: rt.automationState,
        backgroundThrottling: rt.backgroundThrottling,
        isHealthy: rt.isHealthy && isAlive,
        navigationCount: rt.navigationCount || 0,
        reloadCount: rt.reloadCount || 0,
      });
    }
    return {
      activeProfileId: this.profileManager?.getActiveProfile()?.id || null,
      isVisible: this.isVisible,
      lastBounds: this.lastBounds,
      isAutomationRunning: this.isAutomationRunning(),
      runtimes,
    };
  }

  /**
   * Toggle visual ambient glow scrim & mini runner state on the Flow view WebContents.
   * Uses modern 2TOOLNE clean-room overlay (no giant modal scrim).
   * @param {boolean} locked
   */
  async updateInputLock(locked) {
    const wc = this.getWebContents();
    if (!wc || wc.isDestroyed()) return;

    try {
      await wc.executeJavaScript(`
        (() => {
          // Remove obsolete legacy modal if present
          document.getElementById('toolne-flow-lock-overlay')?.remove();
          if (window.__toolneFlowOverlay) {
            window.__toolneFlowOverlay.setRunnerState({
              mode: '${locked ? 'AUTO' : 'MANUAL'}',
              executionState: '${locked ? 'RUNNING' : 'IDLE'}'
            });
          }
        })()
      `);
    } catch (err) {
      console.warn('[FlowBrowserManager] updateInputLock error:', err.message);
    }
  }

  ensureOverlayInjected(wc) {
    if (!wc || wc.isDestroyed()) return;
    const url = (typeof wc.getURL === 'function' ? wc.getURL() : '') || '';
    if (!url.includes('labs.google') && !url.includes('flow.google.com')) return;
    try {
      if (fs.existsSync(OVERLAY_LAYER_PATH)) {
        const code = fs.readFileSync(OVERLAY_LAYER_PATH, 'utf8');
        wc.executeJavaScript(code).catch(() => {});
      }
    } catch (err) {
      console.warn('[FlowBrowserManager] ensureOverlayInjected error:', err.message);
    }
  }

  sendOverlayEvent(action, payload = {}) {
    const wc = this.getWebContents();
    if (!wc || wc.isDestroyed()) return;
    try {
      wc.send('flow:overlay-sync', { action, payload });
      wc.executeJavaScript(`
        (() => {
          window.postMessage({
            target: '2toolne-flow-overlay',
            action: ${JSON.stringify(action)},
            payload: ${JSON.stringify(payload)}
          }, '*');
        })()
      `).catch(() => {});
    } catch (_) {}
  }

  handleOverlayAction(action, payload = {}) {
    if (!this.mainWindow || this.mainWindow.isDestroyed()) return;
    if (action === 'takeover') {
      if (this.flowAdapter) {
        this.flowAdapter.requestTakeover();
      }
      this.updateInputLock(false);
      this.mainWindow.webContents.send('flow:action-takeover');
    } else if (action === 'resume-auto') {
      if (this.flowAdapter) {
        this.flowAdapter.resumeAutoMode();
      }
      this.updateInputLock(true);
      this.mainWindow.webContents.send('flow:action-resume-auto');
    } else if (action === 'pause') {
      this.mainWindow.webContents.send('flow:action-pause');
    } else if (action === 'open-character-approval') {
      this.mainWindow.webContents.send('flow:open-character-approval');
    } else if (action === 'add-reference') {
      this.mainWindow.webContents.send('flow:add-reference', payload);
    } else if (action === 'reopen-setup' || action === 'open-settings' || action === 'OPEN_SETTINGS') {
      this.mainWindow.webContents.send('flow:reopen-setup');
    } else if (action === 'get-settings' || action === 'GET_SETTINGS') {
      const profiles = (this.profileManager ? this.profileManager.getProfiles() : []).map(p => ({
        id: p.id,
        name: p.name,
        email: p.email,
        is_active: p.is_active,
      }));
      const settings = this.profileManager?.getSettings?.() || {
        default_aspect: '9:16',
        mode: 'AUTO',
        char_approval_mode: 'MANUAL',
        auto_approve_valid: true,
        image_model: 'AUTO',
        image_prompt_batch_size: 1,
      };
      const isJobRunning = !!(this.pipelineQueue && this.pipelineQueue.activeJobId);
      const mode = this.flowAdapter ? this.flowAdapter.getMode() : (settings.mode || 'AUTO');
      this.sendOverlayEvent('sync-settings', {
        profiles,
        settings: { ...settings, mode },
        isJobRunning,
      });
    } else if (action === 'save-settings' || action === 'SAVE_SETTINGS') {
      const isJobRunning = !!(this.pipelineQueue && this.pipelineQueue.activeJobId);
      let updatedSettings = null;
      if (this.profileManager) {
        const s = payload.settings || payload;
        updatedSettings = this.profileManager.updateSettings({
          default_aspect: s.default_aspect || '9:16',
          mode: s.mode || 'AUTO',
          char_approval_mode: s.char_approval_mode || 'MANUAL',
          auto_approve_valid: s.auto_approve_valid !== false,
          active_profile_id: payload.profile_id || payload.active_profile_id || s.active_profile_id,
          image_model: s.image_model || 'AUTO',
          image_prompt_batch_size: Math.max(1, Math.min(4, parseInt(s.image_prompt_batch_size || 1, 10))),
        });

        const targetProfileId = payload.profile_id || payload.active_profile_id || s.active_profile_id;
        if (targetProfileId && targetProfileId !== this.profileManager.getActiveProfile()?.id) {
          if (!isJobRunning) {
            this.switchProfile(targetProfileId);
          }
        }
      }

      if (payload.mode && this.flowAdapter) {
        this.flowAdapter.setMode(payload.mode);
      }

      if (this.mainWindow && !this.mainWindow.isDestroyed() && this.mainWindow.webContents) {
        this.mainWindow.webContents.send('flow:settings-updated', updatedSettings);
      }
      this.sendOverlayEvent('save-settings-result', { ok: true, isJobRunning });
    } else if (action === 'create-profile') {
      if (this.profileManager) {
        const newProfile = this.profileManager.createProfile({ name: payload.name });
        const profiles = this.profileManager.getProfiles().map(p => ({
          id: p.id,
          name: p.name,
          email: p.email,
          is_active: p.is_active,
        }));
        this.sendOverlayEvent('sync-settings', {
          profiles,
          settings: this.profileManager.getSettings(),
          isJobRunning: !!(this.pipelineQueue && this.pipelineQueue.activeJobId),
          createdProfileId: newProfile.id,
        });
      }
    } else if (action === 'close-settings' || action === 'CLOSE_SETTINGS') {
      this.mainWindow.webContents.send('flow:settings-closed');
    }
  }

  handleActivityEvent(event) {
    if (!event) return;
    const stage = event.stage || '';
    const progress = event.progress || 0;
    const message = event.message || '';

    // 1. Map to single realtime status & dynamic throttling
    let type = 'info';
    let autoHideMs = 0;
    if (stage === 'FLOW_TASK_COMPLETED' || stage === 'FLOW_SCENE_COMPLETED') {
      type = 'success';
      autoHideMs = 3500;
      this.setJobThrottling(false);
    } else if (stage === 'FLOW_TASK_FAILED') {
      type = 'error';
      this.setJobThrottling(false);
    } else if (stage.includes('WAITING') || stage.includes('PAUSED')) {
      type = 'warning';
      this.setJobThrottling(false);
    } else {
      this.setJobThrottling(true);
    }

    this.sendOverlayEvent('set-realtime-status', {
      message: message || stage,
      type,
      autoHideMs,
    });

    // 2. Map to runner state
    const taskInfo = {
      scene_idx: event.scene_idx || 1,
      total_scenes: event.total_scenes || 1,
      scene_desc: event.scene_title || event.scene_desc || `Cảnh ${event.scene_idx || 1}`,
      progress,
    };

    let executionState = 'RUNNING';
    if (stage === 'FLOW_TASK_COMPLETED') {
      executionState = 'COMPLETE';
    } else if (stage.includes('PAUSED')) {
      executionState = 'PAUSED';
    } else if (stage.includes('WAITING')) {
      executionState = 'WAITING';
    } else if (stage === 'FLOW_TASK_FAILED') {
      executionState = 'ERROR';
    }

    this.sendOverlayEvent('set-runner-state', {
      mode: this.flowAdapter ? this.flowAdapter.getMode() : 'AUTO',
      executionState,
      task: taskInfo,
      statusText: message || stage,
      characterPending: {
        count: stage === 'WAITING_CHARACTER_APPROVAL' ? (event.pending_characters?.length || 1) : 0,
        characters: event.pending_characters || [],
      },
    });

    // 3. Highlight verified media
    if (event.media_id && (stage === 'FLOW_MEDIA_DETECTED' || stage === 'FLOW_MEDIA_VERIFIED')) {
      this.sendOverlayEvent('set-verified-media', {
        mediaIds: [event.media_id],
      });
    }
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
        const wc = entry.webContents || entry.view?.webContents;
        if (wc && !wc.isDestroyed()) {
          if (wc.debugger && wc.debugger.isAttached()) {
            try { wc.debugger.detach(); } catch (_) {}
          }
          wc.close();
        }
      } catch (_) {}
    }
    this.profileViews.clear();
    this.currentView = null;
    this.currentSession = null;
  }

  /**
   * Rehydrates the active view's in-page overlay with current pipeline & adapter state.
   * Guarantees that returning to Flow or switching profiles restores runner & execution frame.
   */
  rehydrateOverlayState(targetWc = null) {
    const wc = targetWc || this.getWebContents();
    if (!wc || wc.isDestroyed()) return;

    let mode = 'AUTO';
    let executionState = 'IDLE';
    let taskInfo = null;
    let statusText = 'Sẵn sàng';
    let characterPending = { count: 0, characters: [] };

    // Inspect pipeline queue for active job
    if (this.pipelineQueue && this.pipelineQueue.activeJobId) {
      const job = this.pipelineQueue.jobs.get(this.pipelineQueue.activeJobId);
      if (job && !['PROJECT_READY', 'FAILED', 'CANCELLED'].includes(job.state)) {
        // Job snapshot is authoritative for operating mode
        const jobMode = job.options?.flow_operating_mode || job.options?.mode || 'AUTO';
        mode = String(jobMode).toUpperCase() === 'MANUAL' ? 'MANUAL' : 'AUTO';
        if (job.state === 'WAITING_CHARACTER_APPROVAL') {
          executionState = 'WAITING';
          const chars = (job.characters || []).filter(c => c.status === 'PENDING' || c.status === 'WAITING');
          characterPending = {
            count: chars.length || 1,
            characters: chars,
          };
          statusText = 'Chờ duyệt ảnh nhân vật';
        } else if (job.state === 'PAUSED') {
          executionState = 'PAUSED';
          statusText = 'Tác vụ tạm dừng';
        } else {
          executionState = 'RUNNING';
          const totalScenes = job.scenes?.length || 1;
          const currentSceneIdx = (job.scenes || []).findIndex(s => s.video_status !== 'READY' && s.image_status !== 'READY') + 1;
          const sceneIdx = currentSceneIdx > 0 ? currentSceneIdx : 1;
          const scene = job.scenes?.[sceneIdx - 1];
          taskInfo = {
            scene_idx: sceneIdx,
            total_scenes: totalScenes,
            scene_desc: scene?.prompt || scene?.slug || `Cảnh ${sceneIdx}`,
            progress: job.progress_pct || 0,
          };
          const act = job.current_activity || '';
          if (act.includes('tự động tạo video') || act.includes('xử lý video') || scene?.generation_type === 'video') {
            statusText = '2TOOLNE đang tự động tạo video...';
          } else if (act.includes('tự động tạo ảnh') || act.includes('xử lý ảnh') || scene?.generation_type === 'image') {
            statusText = '2TOOLNE đang tự động tạo ảnh...';
          } else if (act.includes('tham chiếu')) {
            statusText = '2TOOLNE đang chuẩn bị ảnh tham chiếu...';
          } else if (act.includes('tải')) {
            statusText = '2TOOLNE đang tải kết quả...';
          } else if (act.includes('chờ') || act.includes('Google Flow')) {
            statusText = '2TOOLNE đang chờ Google Flow xử lý...';
          } else if (act && !act.includes('Google Flow')) {
            statusText = act;
          } else {
            statusText = '2TOOLNE đang xử lý tự động...';
          }
        }
      }
    }

    this.sendOverlayEvent('set-runner-state', {
      mode,
      executionState,
      task: taskInfo,
      statusText,
      characterPending,
    });
  }

  handlePipelineJobProgress(data) {
    this.rehydrateOverlayState();
  }

  handlePipelineJobState(data) {
    if (data && (data.state === 'COMPLETED' || data.state === 'FAILED' || data.state === 'CANCELLED' || data.state === 'PROJECT_READY')) {
      this.setJobThrottling(false);
    } else if (data && data.state && !['IDLE', 'PAUSED'].includes(data.state)) {
      this.setJobThrottling(true);
    }
    this.rehydrateOverlayState();
  }
}

module.exports = { FlowBrowserManager, ALLOWED_NAV_HOSTS };
