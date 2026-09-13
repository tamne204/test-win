/**
 * apps/capcut-v2/desktop/src/main/flow/flow_overlay_preload.js
 * 
 * Preload script for Google Flow WebContentsView.
 * Provides a secure, isolated IPC bridge between the in-page Flow Overlay Layer
 * and the Electron Main process.
 *
 * Rules:
 * - Does NOT expose Node.js or Electron APIs to the Flow webpage.
 * - Strictly validates incoming window.postMessage events.
 * - Relays user actions (takeover, resume, pause, approval, add-reference, reopen-setup) to ipcRenderer.
 * - Receives state updates from Main and forwards to the in-page overlay.
 */

const { ipcRenderer } = require('electron');
const fs = require('fs');
const path = require('path');

const VALID_ACTIONS = new Set([
  'takeover',
  'resume-auto',
  'pause',
  'open-character-approval',
  'add-reference',
  'reopen-setup',
  'get-settings',
  'save-settings',
  'close-settings',
  'open-settings',
  'create-profile',
  'GET_SETTINGS',
  'SAVE_SETTINGS',
  'OPEN_SETTINGS',
  'CLOSE_SETTINGS',
]);

// 1. Inject the overlay layer when DOM is ready
function injectOverlayLayer() {
  const currentUrl = window.location.href;
  if (!currentUrl.includes('labs.google') && !currentUrl.includes('flow.google.com')) {
    return;
  }
  try {
    const overlayPath = path.join(__dirname, 'flow_overlay_layer.js');
    if (fs.existsSync(overlayPath)) {
      const code = fs.readFileSync(overlayPath, 'utf8');
      const scriptEl = document.createElement('script');
      scriptEl.textContent = code;
      (document.head || document.documentElement).appendChild(scriptEl);
      scriptEl.remove();
    }
  } catch (err) {
    console.warn('[FlowOverlayPreload] Injection error:', err.message);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  injectOverlayLayer();
});
window.addEventListener('load', () => {
  injectOverlayLayer();
});

// 2. Listen for actions dispatched from the in-page overlay
window.addEventListener('message', (event) => {
  if (event.source !== window) return;
  const data = event.data;
  if (!data || data.source !== '2toolne-flow-overlay') return;

  const action = data.action;
  if (!VALID_ACTIONS.has(action)) {
    console.warn('[FlowOverlayPreload] Ignored unauthorized action:', action);
    return;
  }

  // Forward validated action to Main process
  ipcRenderer.send('flow:overlay-action', {
    action,
    payload: data.payload || {},
  });
});

// 3. Listen for state updates from Main and forward to the in-page overlay
ipcRenderer.on('flow:overlay-sync', (_, { action, payload }) => {
  window.postMessage({
    target: '2toolne-flow-overlay',
    action,
    payload: payload || {},
  }, '*');
});
