<?php
/**
 * 2TOOLNE CLOUD — PUBLIC SHARE VIEWER (PRODUCTION V2)
 * Pure client-side fragment resolution (zero token exposure in Apache access logs).
 * Decoupled from runtime DDL and server database connections.
 * Fully compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data: blob:;");

// Legacy GET/Path token migration: Redirect to fragment to sanitize URL and prevent log exposure
$legacyToken = trim((string)($_GET['token'] ?? ''));
if (empty($legacyToken)) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#/share/([a-fA-F0-9]{32,64})#', $uriPath, $m)) {
        $legacyToken = $m[1];
    }
}
if (!empty($legacyToken)) {
    header('Location: /share/#' . rawurlencode($legacyToken), true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>2TOOLNE Cloud — Chia Sẻ Tệp Tin</title>
  <meta name="theme-color" content="#FF7A00">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <style>
    :root {
      --bg-dark: #0F1012;
      --card-bg: #191B1F;
      --card-border: #2A2D33;
      --text-main: #EDEDEE;
      --text-muted: #8E95A2;
      --brand-primary: #FF7A00;
      --brand-hover: #FF8F1F;
      --accent-cyan: #FF7A00;
      --accent-blue: #FF7A00;
      --accent-green: #10B981;
      --accent-red: #EF4444;
      --btn-hover: #E56E00;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background-color: var(--bg-dark);
      color: var(--text-main);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 30px 16px;
    }
    .brand-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 24px;
      text-decoration: none;
      color: inherit;
    }
    .brand-logo { font-size: 26px; }
    .brand-title {
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.5px;
      background: linear-gradient(135deg, #38bdf8, #818cf8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .share-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 16px;
      width: 100%;
      max-width: 680px;
      overflow: hidden;
      box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
    }
    .preview-container {
      background: #020617;
      min-height: 240px;
      max-height: 480px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-bottom: 1px solid var(--card-border);
      overflow: hidden;
      position: relative;
    }
    .preview-img {
      max-width: 100%;
      max-height: 480px;
      object-fit: contain;
    }
    .preview-video, .preview-audio {
      width: 100%;
      outline: none;
    }
    .preview-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      color: var(--text-muted);
      padding: 40px 20px;
    }
    .preview-placeholder-icon { font-size: 54px; }
    .card-body { padding: 24px; }
    .file-title-wrap {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 16px;
    }
    .file-name {
      font-size: 18px;
      font-weight: 600;
      line-height: 1.4;
      word-break: break-word;
    }
    .badge {
      font-size: 11.5px;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 9999px;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .badge-view-only {
      background: rgba(148, 163, 184, 0.15);
      color: #cbd5e1;
      border: 1px solid rgba(148, 163, 184, 0.3);
    }
    .badge-allow-download {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .meta-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 12px;
      background: rgba(15, 23, 42, 0.6);
      padding: 14px 16px;
      border-radius: 10px;
      margin-bottom: 24px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .meta-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .meta-label {
      font-size: 11.5px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .meta-val {
      font-size: 13.5px;
      font-weight: 500;
      color: var(--text-main);
    }
    .action-row {
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .btn-download {
      flex: 1;
      background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
      color: #fff;
      font-size: 14.5px;
      font-weight: 600;
      padding: 13px 20px;
      border-radius: 10px;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 14px rgba(6, 182, 212, 0.3);
      border: none;
      cursor: pointer;
    }
    .btn-download:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }
    .folder-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
    }
    .folder-table th, .folder-table td {
      padding: 10px 12px;
      text-align: left;
      font-size: 13px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.07);
    }
    .folder-table th {
      color: var(--text-muted);
      font-weight: 500;
    }
    .folder-item-link {
      color: var(--text-main);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 500;
      cursor: pointer;
    }
    .folder-item-link:hover { color: var(--accent-cyan); }
    .breadcrumbs {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 14px;
      flex-wrap: wrap;
    }
    .breadcrumbs a {
      color: var(--accent-cyan);
      text-decoration: none;
      cursor: pointer;
    }
    .breadcrumbs a:hover { text-decoration: underline; }
    .state-panel { display: none; }
    .error-card {
      text-align: center;
      padding: 50px 24px;
    }
    .error-icon {
      font-size: 56px;
      margin-bottom: 16px;
    }
    .error-title {
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 8px;
      color: #f87171;
    }
    .error-desc {
      font-size: 14px;
      color: var(--text-muted);
      max-width: 420px;
      margin: 0 auto 24px;
      line-height: 1.6;
    }
    .loading-card {
      text-align: center;
      padding: 60px 24px;
      color: var(--text-muted);
    }
    .spinner {
      width: 40px;
      height: 40px;
      border: 3px solid rgba(255, 255, 255, 0.1);
      border-top-color: var(--accent-cyan);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin: 0 auto 16px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .footer-note {
      margin-top: 24px;
      font-size: 12px;
      color: var(--text-muted);
      text-align: center;
    }
  </style>
</head>
<body>

  <!-- Brand Header -->
  <div class="brand-header" style="display:flex;align-items:center;justify-content:center;gap:8px">
    <img src="/assets/favicon.png" alt="2TOOLNE Logo" style="width:28px;height:28px;border-radius:6px;object-fit:contain">
    <span class="brand-title">2TOOLNE Cloud</span>
  </div>

  <div class="share-card">
    <!-- State 1: Loading -->
    <div id="stateLoading" class="loading-card">
      <div class="spinner"></div>
      <p>Đang kiểm tra quyền truy cập tệp tin...</p>
    </div>

    <!-- State 2: Error -->
    <div id="stateError" class="state-panel">
      <div class="error-card">
        <div class="error-icon">⚠️</div>
        <h1 class="error-title" id="errTitle">Không Tìm Thấy Liên Kết</h1>
        <p class="error-desc" id="errDesc">Đường dẫn chia sẻ không tồn tại hoặc đã bị thu hồi.</p>
        <div style="font-size:12px; color:var(--text-muted);">
          Nếu bạn cho rằng đây là một lỗi, vui lòng liên hệ người đã gửi liên kết cho bạn.
        </div>
      </div>
    </div>

    <!-- State 3: File View -->
    <div id="stateFile" class="state-panel">
      <!-- Media Preview Area -->
      <div class="preview-container" id="filePreviewContainer">
        <!-- Injected via JavaScript -->
      </div>

      <!-- File Details -->
      <div class="card-body">
        <div class="file-title-wrap">
          <div>
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px;">TỆP TIN CHIA SẺ</div>
            <h1 class="file-name" id="fileName">--</h1>
          </div>
          <div id="fileAccessBadge"></div>
        </div>

        <div class="meta-grid">
          <div class="meta-item">
            <span class="meta-label">Kích thước</span>
            <span class="meta-val" id="fileSize">--</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">Định dạng</span>
            <span class="meta-val" id="fileExt">--</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">Thời hạn</span>
            <span class="meta-val" id="fileExpires">Vô thời hạn</span>
          </div>
        </div>

        <div id="fileActionArea">
          <!-- Download button or view-only disclaimer -->
        </div>
      </div>
    </div>

    <!-- State 4: Folder View -->
    <div id="stateFolder" class="state-panel">
      <div class="card-body">
        <div class="file-title-wrap">
          <div>
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px;">THƯ MỤC CHIA SẺ</div>
            <h1 class="file-name" id="folderName">📁 --</h1>
          </div>
          <div id="folderAccessBadge"></div>
        </div>

        <div class="breadcrumbs" id="folderBreadcrumbs"></div>

        <table class="folder-table">
          <thead>
            <tr>
              <th>Tên Tệp / Thư Mục</th>
              <th>Kích Thước</th>
              <th>Cập Nhật</th>
              <th id="thFolderAction" style="display:none;">Thao Tác</th>
            </tr>
          </thead>
          <tbody id="folderTableBody">
            <!-- Injected via JS -->
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="footer-note">
    Được lưu trữ và chia sẻ an toàn qua <strong>2TOOLNE Cloud</strong>.
  </div>

  <script>
    (function () {
      function formatSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
      }

      function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
      }

      function showPanel(id) {
        document.getElementById('stateLoading').style.display = 'none';
        document.getElementById('stateError').style.display = 'none';
        document.getElementById('stateFile').style.display = 'none';
        document.getElementById('stateFolder').style.display = 'none';
        const target = document.getElementById(id);
        if (target) target.style.display = 'block';
      }

      function showError(title, desc) {
        document.getElementById('errTitle').textContent = title;
        document.getElementById('errDesc').textContent = desc;
        showPanel('stateError');
      }

      // Extract raw token exclusively from URL fragment (#<raw_token>)
      const hash = window.location.hash ? window.location.hash.replace(/^#/, '').trim() : '';
      if (!hash) {
        showError('Không Tìm Thấy Liên Kết', 'Đường dẫn chia sẻ không chứa mã định danh hợp lệ.');
        return;
      }

      let activeSession = '';
      let shareInfo = null;

      // Resolve share via POST with token in JSON body (ZERO APACHE LOG EXPOSURE)
      fetch('/api/v1/cloud/public/share/resolve', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ share_token: hash })
      })
      .then(async (res) => {
        const data = await res.json();
        if (!res.ok || !data.ok) {
          if (res.status === 410) {
            showError('Liên Kết Không Còn Khả Dụng', data.error || 'Liên kết này đã hết hạn hoặc đã bị thu hồi bởi người tạo.');
          } else if (res.status === 404) {
            showError('Không Tìm Thấy Tệp Tin', data.error || 'Tệp hoặc thư mục được chia sẻ không còn tồn tại.');
          } else {
            showError('Không Thể Truy Cập', data.error || 'Yêu cầu không thể hoàn tất lúc này.');
          }
          return;
        }

        activeSession = data.share_session;
        shareInfo = data;

        if (data.item_type === 'FILE') {
          renderFile(data);
        } else {
          renderFolder(data);
        }
      })
      .catch((err) => {
        showError('Lỗi Kết Nối Máy Chủ', 'Không thể kết nối đến hệ thống 2TOOLNE Cloud. Vui lòng thử lại sau.');
      });

      function renderFile(data) {
        const item = data.item || {};
        document.title = (item.name || 'Tệp Tin') + ' — 2TOOLNE Cloud';
        document.getElementById('fileName').textContent = item.name || 'Tệp không tên';
        document.getElementById('fileSize').textContent = formatSize(item.size_bytes);
        document.getElementById('fileExt').textContent = (item.extension || 'Tệp').toUpperCase();
        document.getElementById('fileExpires').textContent = data.expires_at ? data.expires_at.slice(0, 10) : 'Vô thời hạn';

        const badgeWrap = document.getElementById('fileAccessBadge');
        if (data.access_level === 'ALLOW_DOWNLOAD') {
          badgeWrap.innerHTML = '<span class="badge badge-allow-download">✓ Cho phép tải</span>';
        } else {
          badgeWrap.innerHTML = '<span class="badge badge-view-only">👁️ Chỉ xem</span>';
        }

        // Preview container
        const previewWrap = document.getElementById('filePreviewContainer');
        previewWrap.innerHTML = '';
        const previewUrl = '/api/v1/cloud/public/share/preview?session=' + encodeURIComponent(activeSession);

        if (item.preview_type === 'IMAGE') {
          const img = document.createElement('img');
          img.className = 'preview-img';
          img.src = previewUrl;
          img.alt = item.name || '';
          previewWrap.appendChild(img);
        } else if (item.preview_type === 'VIDEO') {
          const video = document.createElement('video');
          video.className = 'preview-video';
          video.controls = true;
          video.preload = 'metadata';
          video.src = previewUrl;
          previewWrap.appendChild(video);
        } else if (item.preview_type === 'AUDIO') {
          previewWrap.style.minHeight = '120px';
          const audio = document.createElement('audio');
          audio.className = 'preview-audio';
          audio.controls = true;
          audio.src = previewUrl;
          previewWrap.appendChild(audio);
        } else {
          previewWrap.style.minHeight = '160px';
          previewWrap.innerHTML = `
            <div class="preview-placeholder">
              <span class="preview-placeholder-icon">📄</span>
              <span>Tệp định dạng ${escapeHtml((item.extension || 'FILE').toUpperCase())}</span>
            </div>
          `;
        }

        // Action area
        const actionArea = document.getElementById('fileActionArea');
        if (data.access_level === 'ALLOW_DOWNLOAD') {
          const downloadUrl = '/api/v1/cloud/public/share/download?session=' + encodeURIComponent(activeSession);
          actionArea.innerHTML = `
            <div class="action-row">
              <a href="${downloadUrl}" class="btn-download" download="${escapeHtml(item.name)}">
                <span>⬇ Tải Xuống Tệp Tin</span>
              </a>
            </div>
          `;
        } else {
          actionArea.innerHTML = `
            <div style="font-size:12.5px; color:var(--text-muted); text-align:center; padding:8px;">
              Liên kết này chỉ cấp quyền xem trước trực tuyến, không hỗ trợ tải về máy.
            </div>
          `;
        }

        showPanel('stateFile');
      }

      function renderFolder(data) {
        const item = data.item || {};
        document.title = (item.name || 'Thư Mục') + ' — 2TOOLNE Cloud';
        document.getElementById('folderName').textContent = '📁 ' + (item.name || 'Thư mục');

        const badgeWrap = document.getElementById('folderAccessBadge');
        const allowDownload = (data.access_level === 'ALLOW_DOWNLOAD');
        if (allowDownload) {
          badgeWrap.innerHTML = '<span class="badge badge-allow-download">✓ Cho phép tải tệp</span>';
          document.getElementById('thFolderAction').style.display = '';
        } else {
          badgeWrap.innerHTML = '<span class="badge badge-view-only">👁️ Chỉ xem</span>';
          document.getElementById('thFolderAction').style.display = 'none';
        }

        loadFolderContents('');
        showPanel('stateFolder');
      }

      function loadFolderContents(subfolderId) {
        const tbody = document.getElementById('folderTableBody');
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">Đang tải nội dung thư mục...</td></tr>';

        let url = '/api/v1/cloud/public/share/folder?session=' + encodeURIComponent(activeSession);
        if (subfolderId) url += '&subfolder_id=' + encodeURIComponent(subfolderId);

        fetch(url)
          .then((r) => r.json())
          .then((res) => {
            if (!res.ok) {
              tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--accent-red);">' + escapeHtml(res.error || 'Không thể tải thư mục') + '</td></tr>';
              return;
            }

            // Breadcrumbs
            const bcWrap = document.getElementById('folderBreadcrumbs');
            bcWrap.innerHTML = '';
            const crumbs = res.breadcrumbs || [];
            crumbs.forEach((c, idx) => {
              if (idx > 0) {
                const sep = document.createElement('span');
                sep.textContent = ' / ';
                bcWrap.appendChild(sep);
              }
              if (idx === crumbs.length - 1) {
                const span = document.createElement('span');
                span.textContent = c.name;
                bcWrap.appendChild(span);
              } else {
                const link = document.createElement('a');
                link.textContent = c.name;
                link.onclick = function () { loadFolderContents(c.id); };
                bcWrap.appendChild(link);
              }
            });

            // Rows
            tbody.innerHTML = '';
            const allowDownload = (res.access_level === 'ALLOW_DOWNLOAD');
            const folders = res.folders || [];
            const files = res.files || [];

            if (folders.length === 0 && files.length === 0) {
              tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:var(--text-muted);">Thư mục này hiện tại không có tệp nào.</td></tr>';
              return;
            }

            folders.forEach((fld) => {
              const tr = document.createElement('tr');
              tr.innerHTML = `
                <td>
                  <span class="folder-item-link" data-fld="${escapeHtml(fld.id)}">
                    <span>📁</span>
                    <span>${escapeHtml(fld.name)}</span>
                  </span>
                </td>
                <td style="color:var(--text-muted);">--</td>
                <td style="color:var(--text-muted); font-size:12px;">${escapeHtml((fld.updated_at || '').slice(0, 10))}</td>
                ${allowDownload ? '<td>--</td>' : ''}
              `;
              tr.querySelector('.folder-item-link').onclick = function () { loadFolderContents(fld.id); };
              tbody.appendChild(tr);
            });

            files.forEach((fl) => {
              const tr = document.createElement('tr');
              const dlUrl = `/api/v1/cloud/public/share/download?session=${encodeURIComponent(activeSession)}&file_id=${encodeURIComponent(fl.id)}`;
              tr.innerHTML = `
                <td>
                  <span class="folder-item-link" style="cursor:default;">
                    <span>📄</span>
                    <span>${escapeHtml(fl.name)}</span>
                  </span>
                </td>
                <td style="font-family:monospace; font-size:12px;">${formatSize(fl.size_bytes)}</td>
                <td style="color:var(--text-muted); font-size:12px;">${escapeHtml((fl.updated_at || '').slice(0, 10))}</td>
                ${allowDownload ? `
                  <td>
                    <a href="${dlUrl}" class="badge badge-allow-download" style="text-decoration:none;" download="${escapeHtml(fl.name)}">
                      ⬇ Tải xuống
                    </a>
                  </td>
                ` : ''}
              `;
              tbody.appendChild(tr);
            });
          })
          .catch(() => {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--accent-red);">Lỗi kết nối khi tải thư mục.</td></tr>';
          });
      }
    })();
  </script>
</body>
</html>
