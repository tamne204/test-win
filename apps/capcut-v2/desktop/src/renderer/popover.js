/**
 * apps/capcut-v2/desktop/src/renderer/popover.js
 * Renderer controller for 2TOOLNE Global Native Popover Overlay.
 * Complies with strict Content Security Policy (script-src 'self').
 */

const container = document.getElementById('popoverContainer');
const card = document.getElementById('popoverCard');

function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

const SVG_ICONS = {
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
};

function renderWorkspaceMenu(model) {
  if (!card) return;
  const activeWs = model?.activeWorkspace || null;
  const spaces = model?.workspaces || [];
  const isCurrentTeam = activeWs && activeWs.space_type === 'TEAM';

  let itemsHtml = '';
  spaces.forEach(sp => {
    const isAct = activeWs && activeWs.id === sp.id;
    const isTeam = sp.space_type === 'TEAM';
    const role = (sp.user_role || (isTeam ? 'MEMBER' : 'OWNER')).toUpperCase();
    const iconSvg = isTeam ? SVG_ICONS.users : SVG_ICONS.user;

    itemsHtml += `
      <div class="ws-item ${isAct ? 'active' : ''}" data-workspace-id="${escapeHtml(sp.id)}">
        <div class="ws-item-left">
          <span class="ws-item-icon">${iconSvg}</span>
          <span class="ws-item-name">${escapeHtml(sp.name)}</span>
        </div>
        <span class="ws-badge-role ws-role-${role.toLowerCase()}">${role}</span>
      </div>
    `;
  });

  card.innerHTML = `
    <div class="ws-header">Không gian làm việc</div>
    <div class="ws-list" id="wsList">
      ${itemsHtml}
    </div>
    <div class="ws-divider"></div>
    <button type="button" class="ws-action" id="btnCreateTeam">
      <span class="ws-action-icon">${SVG_ICONS.plus}</span>
      <span>Tạo Nhóm Mới</span>
    </button>
    ${isCurrentTeam ? `
      <button type="button" class="ws-action" id="btnManageTeam">
        <span class="ws-action-icon">${SVG_ICONS.users}</span>
        <span>Quản Lý Thành Viên Nhóm</span>
      </button>
    ` : ''}
  `;

  // Item click listeners
  card.querySelectorAll('.ws-item').forEach(el => {
    el.addEventListener('click', (e) => {
      e.stopPropagation();
      const wsId = el.dataset.workspaceId;
      if (window.popoverApi?.sendAction) {
        window.popoverApi.sendAction('SELECT_WORKSPACE', { workspaceId: wsId });
      }
    });
  });

  // Actions
  const btnCreateTeam = document.getElementById('btnCreateTeam');
  btnCreateTeam?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (window.popoverApi?.sendAction) {
      window.popoverApi.sendAction('CREATE_TEAM');
    }
  });

  const btnManageTeam = document.getElementById('btnManageTeam');
  btnManageTeam?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (window.popoverApi?.sendAction) {
      window.popoverApi.sendAction('MANAGE_TEAM');
    }
  });
}

// Handle render events from Main process
if (window.popoverApi?.onRender) {
  window.popoverApi.onRender(({ type, model }) => {
    if (type === 'workspace') {
      renderWorkspaceMenu(model || {});
    }
  });
}

// Close when clicking in shadow padding outside the card
container?.addEventListener('click', (e) => {
  if (e.target === container) {
    if (window.popoverApi?.close) {
      window.popoverApi.close();
    }
  }
});

// Close on Escape key
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    if (window.popoverApi?.close) {
      window.popoverApi.close();
    }
  }
});

// Auto-fetch data on initial load
async function initPopover() {
  if (window.popoverApi && typeof window.popoverApi.getData === 'function') {
    try {
      const initialData = await window.popoverApi.getData();
      if (initialData && initialData.type === 'workspace') {
        renderWorkspaceMenu(initialData.model || {});
      }
    } catch (_) {}
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPopover);
} else {
  initPopover();
}
