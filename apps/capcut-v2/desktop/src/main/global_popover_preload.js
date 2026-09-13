/**
 * apps/capcut-v2/desktop/src/main/global_popover_preload.js
 * Secure context bridge for 2TOOLNE Global Native Popover Overlay.
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('popoverApi', {
  /**
   * Listen for render data dispatched from GlobalPopoverManager.
   * @param {function({ type: string, model: any }): void} callback
   */
  onRender: (callback) => {
    const listener = (_, data) => {
      if (typeof callback === 'function') callback(data);
    };
    ipcRenderer.on('popover:render', listener);
    return () => ipcRenderer.removeListener('popover:render', listener);
  },

  /**
   * Fetch current popover data on initial mount.
   */
  getData: () => {
    return ipcRenderer.invoke('popover:get-data');
  },

  /**
   * Send a user action from popover to main/app shell.
   * @param {string} action
   * @param {any} [payload]
   */
  sendAction: (action, payload) => {
    return ipcRenderer.invoke('popover:action', { action, payload });
  },

  /**
   * Request closing the popover overlay.
   */
  close: () => {
    return ipcRenderer.invoke('popover:close');
  },
});
