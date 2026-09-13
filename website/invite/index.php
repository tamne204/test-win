<?php
/**
 * 2TOOLNE CLOUD — UNIVERSAL TEAM WORKSPACE INVITE ACCEPTANCE PAGE
 * Pure client-side fragment resolution (zero token exposure in Apache access logs).
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data: blob:;");

// Legacy GET/Path token migration: Redirect to fragment to sanitize URL and prevent Apache log exposure
$legacyToken = trim((string)($_GET['token'] ?? ''));
if (empty($legacyToken)) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#/invite/([a-fA-F0-9]{32,64})#', $uriPath, $m)) {
        $legacyToken = $m[1];
    }
}
if (!empty($legacyToken)) {
    header('Location: /invite/#' . rawurlencode($legacyToken), true, 302);
    exit;
}

require_once __DIR__ . '/../api/v1/storage/CloudAuthHelper.php';
$currentUser = CloudAuthHelper::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="vi" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tham Gia Đội Nhóm — 2TOOLNE Workspace</title>
  <meta name="theme-color" content="#FF7A00">
  <link rel="icon" type="image/png" href="/assets/favicon-16.png">
  <style>
    :root {
      --bg-app: #0E0F11;
      --surface-1: #15171A;
      --surface-2: #1A1C20;
      --surface-3: #202228;
      --border: #292C31;
      --brand: #FF7A00;
      --brand-hover: #E56E00;
      --text-main: #FFFFFF;
      --text-muted: #8E929B;
      --text-dim: #60646C;
      --radius: 12px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background-color: var(--bg-app);
      color: var(--text-main);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
    }
    .invite-container {
      width: 100%;
      max-width: 480px;
    }
    .brand-header {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-bottom: 24px;
      text-decoration: none;
      color: inherit;
    }
    .brand-logo {
      width: 32px;
      height: 32px;
      border-radius: 8px;
    }
    .brand-title {
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.5px;
      color: var(--text-main);
    }
    .card {
      background: var(--surface-1);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 28px 24px;
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
      position: relative;
    }
    .loading-state, .error-state, .success-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .spinner {
      width: 36px;
      height: 36px;
      border: 3px solid var(--border);
      border-top-color: var(--brand);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin-bottom: 16px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .badge-role-admin { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
    .badge-role-editor { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
    .badge-role-viewer { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 20px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      border: none;
      width: 100%;
    }
    .btn-primary {
      background: var(--brand);
      color: #000;
    }
    .btn-primary:hover {
      background: var(--brand-hover);
    }
    .btn-secondary {
      background: var(--surface-2);
      color: var(--text-main);
      border: 1px solid var(--border);
    }
    .btn-secondary:hover {
      background: var(--surface-3);
    }
    .meta-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
    }
    .meta-row:last-child {
      border-bottom: none;
    }
    .meta-label { color: var(--text-muted); }
    .meta-value { font-weight: 600; color: var(--text-main); }
  </style>
</head>
<body>

<div class="invite-container">
  <div class="brand-header">
    <img src="/assets/brand-logo.png" alt="2TOOLNE" class="brand-logo" onerror="this.style.display='none'">
    <span class="brand-title">2TOOLNE Workspace</span>
  </div>

  <div class="card" id="mainCard">
    <!-- State 1: Loading -->
    <div id="stateLoading" class="loading-state">
      <div class="spinner"></div>
      <div style="font-size:14px; color:var(--text-muted);">Đang xác thực liên kết mời...</div>
    </div>

    <!-- State 2: Error -->
    <div id="stateError" class="error-state" style="display:none;">
      <div style="font-size:40px; margin-bottom:12px;">⚠️</div>
      <h2 style="font-size:18px; font-weight:700; margin-bottom:8px;" id="errorTitle">Lời Mời Không Hợp Lệ</h2>
      <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:20px;" id="errorDesc">
        Liên kết mời này có thể đã hết hạn, bị thu hồi hoặc đã được sử dụng.
      </p>
      <a href="/index.php" class="btn btn-secondary">Về Trang Chủ 2TOOLNE</a>
    </div>

    <!-- State 3: Ready to Accept -->
    <div id="stateReady" style="display:none;">
      <div style="text-align:center; margin-bottom:20px;">
        <div style="font-size:36px; margin-bottom:8px;">👥</div>
        <div style="font-size:12px; color:var(--brand); font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">
          LỜI MỜI THAM GIA ĐỘI NHÓM
        </div>
        <h1 style="font-size:20px; font-weight:700;" id="teamName">Đang tải tên Team...</h1>
      </div>

      <div style="background:var(--surface-2); border-radius:8px; padding:12px 16px; margin-bottom:20px;">
        <div class="meta-row">
          <span class="meta-label">Người mời:</span>
          <span class="meta-value" id="inviterName">—</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Vai trò được giao:</span>
          <span class="meta-value" id="offeredRoleBadge">—</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Quy mô nhóm:</span>
          <span class="meta-value" id="teamSlots">—</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Thời hạn lời mời:</span>
          <span class="meta-value" id="expiresAt">—</span>
        </div>
      </div>

      <!-- Action Button Area -->
      <div id="actionArea">
        <?php if ($currentUser): ?>
          <button type="button" class="btn btn-primary" id="btnAcceptInvite">
            Chấp Nhận Lời Mời &amp; Vào Nhóm
          </button>
          <div style="font-size:11.5px; color:var(--text-dim); text-align:center; margin-top:10px;">
            Đang đăng nhập dưới tài khoản: <b>@<?= htmlspecialchars($currentUser['username'] ?? 'User') ?></b>
          </div>
        <?php else: ?>
          <a href="#" id="btnLoginToAccept" class="btn btn-primary">
            Đăng Nhập Để Tham Gia Team
          </a>
          <div style="font-size:11.5px; color:var(--text-dim); text-align:center; margin-top:10px;">
            Chưa có tài khoản? <a href="#" id="btnRegisterToAccept" style="color:var(--brand); text-decoration:none;">Đăng ký ngay</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- State 4: Already Member -->
    <div id="stateAlreadyMember" style="display:none; text-align:center;">
      <div style="font-size:36px; margin-bottom:12px;">✅</div>
      <h2 style="font-size:18px; font-weight:700; margin-bottom:8px;">Bạn Đã Là Thành Viên</h2>
      <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:20px;">
        Bạn hiện đang là thành viên của Đội Nhóm này trên 2TOOLNE.
      </p>
      <a href="/index.php" class="btn btn-primary">Vào Không Gian Làm Việc</a>
    </div>

    <!-- State 5: Success -->
    <div id="stateSuccess" class="success-state" style="display:none;">
      <div style="font-size:44px; margin-bottom:12px;">🎉</div>
      <h2 style="font-size:18px; font-weight:700; margin-bottom:8px;">Gia Nhập Team Thành Công!</h2>
      <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin-bottom:20px;" id="successMsg">
        Bạn đã trở thành thành viên chính thức của Đội Nhóm.
      </p>
      <a href="/index.php" class="btn btn-primary" style="margin-bottom:10px;">
        Mở Không Gian Team Trên Web
      </a>
      <div style="font-size:12px; color:var(--text-dim);">
        Nếu đang mở 2TOOLNE Desktop, ứng dụng sẽ tự động cập nhật ngay khi bạn chuyển cửa sổ.
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const rawToken = window.location.hash.substring(1).trim();
  const stateLoading = document.getElementById('stateLoading');
  const stateError = document.getElementById('stateError');
  const stateReady = document.getElementById('stateReady');
  const stateAlreadyMember = document.getElementById('stateAlreadyMember');
  const stateSuccess = document.getElementById('stateSuccess');

  if (!rawToken) {
    showError('Thiếu Mã Lời Mời', 'Liên kết mời không chứa mã token hợp lệ. Vui lòng kiểm tra lại liên kết được gửi cho bạn.');
    return;
  }

  // Setup login / register redirect preserving fragment
  const returnUrl = encodeURIComponent(window.location.pathname + window.location.hash);
  const btnLogin = document.getElementById('btnLoginToAccept');
  const btnReg = document.getElementById('btnRegisterToAccept');
  if (btnLogin) btnLogin.href = '/index.php?action=login&return_to=' + returnUrl;
  if (btnReg) btnReg.href = '/index.php?action=register&return_to=' + returnUrl;

  // Inspect Invitation safely
  fetch('/api/v1/teams/invitations/inspect?token=' + encodeURIComponent(rawToken))
    .then(r => r.json())
    .then(res => {
      if (!res.ok) {
        showError(
          res.error === 'INVITE_ALREADY_USED' ? 'Lời Mời Đã Sử Dụng' :
          res.error === 'INVITE_REVOKED' ? 'Lời Mời Đã Bị Thu Hồi' :
          res.error === 'INVITE_EXPIRED' ? 'Lời Mời Đã Hết Hạn' : 'Liên Kết Không Khả Dụng',
          res.message || 'Không thể sử dụng lời mời này.'
        );
        return;
      }

      if (res.already_member) {
        stateLoading.style.display = 'none';
        stateAlreadyMember.style.display = 'block';
        return;
      }

      // Populate Ready State
      document.getElementById('teamName').textContent = res.team_name || 'Đội Nhóm 2TOOLNE';
      document.getElementById('inviterName').textContent = res.inviter_name || 'Trưởng nhóm';
      
      const role = (res.offered_role || 'EDITOR').toUpperCase();
      const roleCls = role === 'ADMIN' ? 'badge-role-admin' : (role === 'VIEWER' ? 'badge-role-viewer' : 'badge-role-editor');
      document.getElementById('offeredRoleBadge').innerHTML = `<span class="badge ${roleCls}">${role}</span>`;
      
      document.getElementById('teamSlots').textContent = `${res.member_count} / ${res.member_slots} thành viên`;
      if (res.expires_at) {
        const d = new Date(res.expires_at.replace(/-/g, '/'));
        document.getElementById('expiresAt').textContent = d.toLocaleDateString('vi-VN');
      }

      if (res.is_full) {
        const area = document.getElementById('actionArea');
        area.innerHTML = `
          <div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; padding:12px; border-radius:8px; font-size:13px; text-align:center;">
            Team đã đạt giới hạn thành viên tối đa cho gói hiện tại (${res.member_count}/${res.member_slots}). Vui lòng liên hệ Chủ nhóm để nâng cấp thêm chỗ ngồi.
          </div>
        `;
      }

      stateLoading.style.display = 'none';
      stateReady.style.display = 'block';
    })
    .catch(err => {
      showError('Lỗi Kết Nối', 'Không thể kết nối đến máy chủ 2TOOLNE: ' + err.message);
    });

  // Accept Action Handler
  const btnAccept = document.getElementById('btnAcceptInvite');
  if (btnAccept) {
    btnAccept.addEventListener('click', function() {
      btnAccept.disabled = true;
      btnAccept.textContent = 'Đang tham gia...';

      fetch('/api/v1/teams/invitations/accept', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invite_token: rawToken })
      })
      .then(r => r.json())
      .then(res => {
        if (res.ok) {
          stateReady.style.display = 'none';
          document.getElementById('successMsg').textContent = `Chúc mừng bạn đã trở thành thành viên của "${res.team_name || 'Team'}" với vai trò ${res.role || 'EDITOR'}!`;
          stateSuccess.style.display = 'block';
        } else {
          alert(res.error || res.message || 'Không thể chấp nhận lời mời');
          btnAccept.disabled = false;
          btnAccept.textContent = 'Chấp Nhận Lời Mời & Vào Nhóm';
        }
      })
      .catch(e => {
        alert('Lỗi kết nối máy chủ: ' + e.message);
        btnAccept.disabled = false;
        btnAccept.textContent = 'Chấp Nhận Lời Mời & Vào Nhóm';
      });
    });
  }

  function showError(title, desc) {
    stateLoading.style.display = 'none';
    document.getElementById('errorTitle').textContent = title;
    document.getElementById('errorDesc').textContent = desc;
    stateError.style.display = 'flex';
  }
})();
</script>

</body>
</html>
