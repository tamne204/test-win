<?php
declare(strict_types=1);

session_start();

// ── Cấu hình ─────────────────────────────────────────────────────────────────
$appRoot = __DIR__;
$keyFile = $appRoot . '/storage/admin_key.txt';

// Tự tạo key nếu chưa có (chỉ ghi ra file — không bao giờ in ra HTML)
if (!is_file($keyFile) && is_dir($appRoot . '/storage')) {
    file_put_contents($keyFile, bin2hex(random_bytes(16)), LOCK_EX);
    @chmod($keyFile, 0600);
}

$validKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';

// ── Đăng xuất ────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: adql.php');
    exit;
}

// ── Xử lý đăng nhập (POST) ───────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key'])) {
    $inputKey = trim((string) $_POST['key']);

    // Chống brute-force: chặn nếu sai > 5 lần trong 60s
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lastFail = (int) ($_SESSION['last_fail_at']   ?? 0);
    $cooldown = 60;

    if ($attempts >= 5 && (time() - $lastFail) < $cooldown) {
        $loginError = 'Quá nhiều lần thử. Vui lòng chờ ' . ($cooldown - (time() - $lastFail)) . 's.';
    } elseif (!empty($validKey) && hash_equals($validKey, $inputKey)) {
        $_SESSION['admin_authed'] = true;
        $_SESSION['login_attempts'] = 0;
        header('Location: adql.php');
        exit;
    } else {
        $_SESSION['login_attempts'] = $attempts + 1;
        $_SESSION['last_fail_at']   = time();
        $loginError = 'Key không đúng. (' . ($_SESSION['login_attempts']) . '/5)';
    }
}

$authed = !empty($_SESSION['admin_authed']);
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard – Quest Runner</title>
  <link rel="icon" type="image/png" href="assets/favicon.png?v=2">
  <link rel="shortcut icon" href="favicon.ico?v=2">
  <script src="https://cdn.tailwindcss.com"></script>

  <script>
    tailwind.config = {
      theme: { extend: { colors: {
        dc: { bg:"#000000", panel:"rgba(28,28,30,0.75)", rail:"rgba(0,0,0,0.65)", text:"#ffffff",
              muted:"rgba(235,235,245,0.6)", blurple:"#0a84ff", green:"#30d158", red:"#ff453a", yellow:"#ffd60a" }
      }}}
    };
  </script>
  <style>
    :root {
      color-scheme: dark;
      --glass-blur: blur(28px) saturate(190%);
      --glass-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.15), 0 20px 40px rgba(0, 0, 0, 0.65);
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Montserrat", sans-serif;
      background: #000000;
      background-image: radial-gradient(circle at 50% 0%, rgba(10, 132, 255, 0.18) 0%, #000000 80%);
      background-attachment: fixed;
      color: #ffffff;
      -webkit-font-smoothing: antialiased;
    }
    button, .btn {
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease !important;
    }
    button:hover:not(:disabled) { transform: scale(1.02); filter: brightness(1.08); }
    button:active:not(:disabled) { transform: scale(0.98); }
    @keyframes spin { to { transform: rotate(360deg); } }
    .spin { animation: spin .8s linear infinite; display: inline-block; }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 9999px; }
    .terminal-pre {
      font-family: -apple-system, BlinkMacSystemFont, "SF Mono", monospace;
      white-space: pre-wrap; overflow-wrap: anywhere;
      background: rgba(0,0,0,0.85); color: #64d2ff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 12px;
      font-size: .82rem; line-height: 1.65;
    }
  </style>
</head>

<body class="bg-dc-rail text-dc-text min-h-screen" style="color-scheme:dark">

<?php if (!$authed): ?>
<!-- ── Trang đăng nhập ── -->
<div class="min-h-screen flex items-center justify-center p-6">
  <div class="w-full max-w-sm bg-dc-panel rounded-xl p-8 flex flex-col gap-5"
       style="border:1px solid rgba(255,255,255,.08)">

    <div>
      <h1 class="text-xl font-bold mb-1">🔐 Admin Dashboard</h1>
      <p class="text-dc-muted text-sm">Quest Runner · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></p>
    </div>

    <?php if ($loginError): ?>
    <div class="rounded-lg px-3 py-2 text-sm" style="background:rgba(242,63,66,.12);border:1px solid rgba(242,63,66,.4);color:#fca5a5">
      ⚠️ <?= htmlspecialchars($loginError) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="flex flex-col gap-3" autocomplete="off">
      <div class="flex flex-col gap-1.5">
        <label class="text-xs font-bold uppercase tracking-wider text-dc-muted">Admin Key</label>
        <input name="key" type="password" placeholder="Nhập admin key…"
          class="w-full rounded-md bg-dc-rail text-sm px-4 py-2.5 outline-none"
          style="border:1px solid rgba(255,255,255,.12)" autofocus autocomplete="current-password">
      </div>
      <button type="submit"
        class="w-full text-white font-semibold text-sm py-2.5 rounded-md transition-all hover:brightness-110"
        style="background:#5865f2">
        Đăng nhập →
      </button>
    </form>

    <div class="rounded-lg px-3 py-2.5 text-xs text-dc-muted leading-5"
         style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07)">
      🔑 Lấy key tại:<br>
      <strong>DirectAdmin → File Manager → public_html/storage/admin_key.txt</strong>
    </div>

  </div>
</div>

<?php else: ?>
<!-- ── Dashboard Admin ── -->

<!-- Header -->
<header class="sticky top-0 z-20 flex items-center justify-between px-6 py-3 bg-dc-panel"
        style="border-bottom:1px solid rgba(255,255,255,.08)">
  <div class="flex items-center gap-3">
    <span class="text-lg font-bold">⚡ Quest Runner</span>
    <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
          style="background:rgba(88,101,242,.2);color:#8d96f8">Admin</span>
  </div>
  <div class="flex items-center gap-3 text-xs text-dc-muted">
    <span id="lastUpdate">–</span>
    <span id="refreshDot" class="w-2 h-2 rounded-full inline-block" style="background:#23a559"></span>
    <span>auto-refresh 5s</span>
    <button id="openBotConfigBtn" type="button"
            class="px-3 py-1 rounded text-xs text-white font-bold flex items-center gap-1.5 hover:brightness-110 shadow-md transition-all ml-2"
            style="background:#5865f2;border:1px solid rgba(88,101,242,.4)">
      <span>🤖</span>
      <span>Cấu hình Bot Discord</span>
    </button>
    <a href="index.php" class="text-dc-blurple hover:underline ml-2">← Trang chính</a>
    <a href="adql.php?logout=1"
       class="px-2.5 py-1 rounded text-xs hover:brightness-110 transition-all font-medium"
       style="background:rgba(242,63,66,.15);color:#f87171;border:1px solid rgba(242,63,66,.25)"
       onclick="return confirm('Đăng xuất khỏi Admin?')">
      Đăng xuất
    </a>
  </div>

</header>

<main class="max-w-7xl mx-auto p-6 flex flex-col gap-6">

  <!-- Stat cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php
    $cards = [
      ['id'=>'statTotal',    'label'=>'Tổng Users',            'color'=>'rgba(148,155,164,.15)', 'text'=>'#dbdee1', 'icon'=>'👥'],
      ['id'=>'statActive',   'label'=>'Đang chạy',            'color'=>'rgba(88,101,242,.15)',  'text'=>'#8d96f8', 'icon'=>'▶'],
      ['id'=>'statDone',     'label'=>'Tổng Quest đã xong',   'color'=>'rgba(35,165,89,.15)',   'text'=>'#57d98b', 'icon'=>'🎯'],
      ['id'=>'statFailed',   'label'=>'Đã dừng / Lỗi',        'color'=>'rgba(240,177,50,.15)',  'text'=>'#fbbf24', 'icon'=>'⏸'],
    ];
    foreach ($cards as $c): ?>
    <div class="rounded-xl p-4 flex flex-col gap-1" style="background:<?= $c['color'] ?>;border:1px solid rgba(255,255,255,.07)">
      <span class="text-2xl"><?= $c['icon'] ?></span>
      <div id="<?= $c['id'] ?>" class="text-3xl font-bold" style="color:<?= $c['text'] ?>">–</div>
      <div class="text-xs text-dc-muted"><?= $c['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Task list -->
  <div>
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-bold text-dc-muted uppercase tracking-wider">Danh sách người dùng (1 token = 1 user)</h2>
      <div class="flex gap-2 text-xs">
        <select id="filterStatus" class="bg-dc-panel text-dc-muted rounded px-3 py-1.5 text-xs outline-none"
                style="border:1px solid rgba(255,255,255,.1)">
          <option value="">Tất cả trạng thái</option>
          <option value="running">Đang chạy</option>
          <option value="queued">Queued</option>
          <option value="completed">Hoàn thành</option>
          <option value="stopped">Đã dừng</option>
          <option value="banned">🚫 Đã bị cấm (Banned)</option>
          <option value="failed">Lỗi</option>
        </select>
      </div>
    </div>
    <div id="taskGrid" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <div class="text-dc-muted text-sm col-span-full text-center py-8">Đang tải danh sách…</div>
    </div>
  </div>

</main>

<!-- ── POPUP LOG MODAL ── -->
<div id="logModal" class="fixed inset-0 z-50 hidden bg-black/75 flex items-center justify-center p-4">
  <div class="w-full max-w-4xl bg-dc-panel rounded-xl flex flex-col overflow-hidden shadow-2xl"
       style="height:80vh;border:1px solid rgba(255,255,255,.12)">

    <!-- Modal Header -->
    <div class="flex items-center justify-between px-5 py-3 bg-dc-rail"
         style="border-bottom:1px solid rgba(255,255,255,.08)">
      <div class="flex items-center gap-2">
        <span class="text-sm font-bold">📜 Terminal Logs:</span>
        <code id="modalTaskId" class="text-xs text-dc-blurple font-mono bg-black/30 px-2 py-0.5 rounded"></code>
        <span id="modalPollBadge" class="text-[11px] font-mono bg-dc-green/20 text-dc-green px-2 py-0.5 rounded-full">live: on</span>
      </div>
      <div class="flex items-center gap-2">
        <button id="modalClearBtn" type="button"
          class="text-xs px-2.5 py-1 rounded bg-white/5 hover:bg-white/10 text-dc-muted hover:text-white transition-colors">
          🗑 Clear
        </button>
        <button id="modalCloseBtn" type="button"
          class="text-sm px-2.5 py-1 rounded bg-white/5 hover:bg-dc-red hover:text-white transition-colors font-bold">
          ✕ Đóng
        </button>
      </div>
    </div>

    <!-- Modal Log Body -->
    <div class="flex-1 p-4 bg-[#111214] overflow-hidden flex flex-col">
      <pre id="modalLogContent" class="terminal-pre flex-1 overflow-y-auto m-0 p-2"></pre>
    </div>

    <!-- Modal Footer -->
    <div class="px-5 py-2.5 bg-dc-rail flex items-center justify-between text-xs text-dc-muted"
         style="border-top:1px solid rgba(255,255,255,.08)">
      <span>Tự động cập nhật log & thúc đẩy xử lý mỗi 2 giây</span>
      <span id="modalLogLines" class="font-mono"></span>
    </div>

  </div>
</div>

<!-- ── Modal Cấu Hình Bot Discord ── -->
<div id="botConfigModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background:rgba(0,0,0,.75);backdrop-filter:blur(4px)">
  <div class="w-full max-w-xl rounded-xl shadow-2xl overflow-hidden flex flex-col"
       style="background:#2b2d31;border:1px solid rgba(88,101,242,.35)">
    
    <!-- Modal Header -->
    <div class="px-5 py-3.5 bg-dc-rail flex items-center justify-between"
         style="border-bottom:1px solid rgba(255,255,255,.08)">
      <div class="flex items-center gap-2">
        <span class="text-xl">🤖</span>
        <div>
          <h3 class="text-sm font-bold text-white">Cấu Hình Bot Discord Thông Báo Server</h3>
          <p class="text-[11px] text-dc-muted">Server: <a href="https://discord.gg/agYPxY98N" target="_blank" class="text-dc-blurple underline">discord.gg/agYPxY98N</a></p>
        </div>
      </div>
      <button id="closeBotConfigBtn" type="button"
              class="text-sm px-2.5 py-1 rounded bg-white/5 hover:bg-dc-red hover:text-white transition-colors font-bold">
        ✕ Đóng
      </button>
    </div>

    <!-- Modal Body -->
    <form id="botConfigForm" class="p-5 flex flex-col gap-4 overflow-y-auto max-h-[75vh]">
      
      <!-- Enable Checkbox -->
      <div class="flex items-center justify-between p-3 rounded-lg bg-dc-rail border border-white/5">
        <div>
          <div class="text-xs font-bold text-white">Bật Thông Báo Tự Động</div>
          <div class="text-[11px] text-dc-muted">Tự động gửi tin nhắn Embed vào Discord Server khi có sự kiện</div>
        </div>
        <input type="checkbox" id="botEnabledInput" class="w-4 h-4 accent-indigo-600 rounded cursor-pointer" checked>
      </div>

      <!-- BOT TOKEN SECTION -->
      <div class="p-3.5 rounded-xl flex flex-col gap-2.5" style="background:#1e1f22;border:1px solid rgba(88,101,242,.35)">
        <div class="flex items-center justify-between">
          <label class="text-xs font-bold text-white flex items-center gap-1.5">
            <span>🔑</span>
            <span>Discord Bot Token:</span>
          </label>
          <button id="verifyTokenBtn" type="button"
                  class="text-[11px] px-2.5 py-1 rounded bg-dc-blurple hover:brightness-110 text-white font-bold transition-all shadow-sm">
            🔍 Kiểm Tra Token
          </button>
        </div>
        <div class="relative">
          <input id="botTokenInput" type="password" placeholder="Nhập Token Bot từ Discord Developer Portal…"
                 class="w-full text-xs px-3 py-2 pr-16 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-indigo-500 font-mono">
          <button type="button" id="toggleBotTokenVisibility"
                  class="absolute right-2 top-2 text-[10px] text-dc-muted hover:text-white font-bold">
            👁 Hiện
          </button>
        </div>
        <div id="botVerifyStatus" class="hidden text-xs px-2.5 py-1.5 rounded flex items-center gap-2"></div>
        <span class="text-[10px] text-dc-muted">Lấy tại: <strong>Discord Developer Portal ➔ Applications ➔ Bot ➔ Reset Token</strong>.</span>
      </div>

      <!-- CHANNEL IDS / WEBHOOKS SECTION -->
      <div class="flex flex-col gap-3">
        
        <!-- 1. Quests & ORB -->
        <div class="flex flex-col gap-1">
          <div class="flex items-center justify-between">
            <label class="text-xs font-semibold text-white flex items-center gap-1.5">
              <span>🎯</span>
              <span>Kênh Hoàn Thành Nhiệm Vụ & Nhận ORB:</span>
            </label>
            <button type="button" class="test-bot-btn text-[11px] px-2 py-0.5 rounded bg-white/5 hover:bg-white/10 text-dc-blurple font-bold" data-type="quest">
              🧪 Gửi Thử
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <input id="botChannelQuests" type="text" placeholder="Channel ID (vd: 1234567890...)"
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-indigo-500 font-mono">
            <input id="botWebhookQuests" type="url" placeholder="Hoặc Webhook URL..."
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-indigo-500 font-mono">
          </div>
          <span class="text-[10px] text-dc-muted">Báo ai vừa cày xong nhiệm vụ và nhận bao nhiêu ORB / quà tặng.</span>
        </div>

        <!-- 2. Welcome -->
        <div class="flex flex-col gap-1">
          <div class="flex items-center justify-between">
            <label class="text-xs font-semibold text-white flex items-center gap-1.5">
              <span>👋</span>
              <span>Kênh Chào Mừng Người Mới Đăng Ký:</span>
            </label>
            <button type="button" class="test-bot-btn text-[11px] px-2 py-0.5 rounded bg-white/5 hover:bg-white/10 text-emerald-400 font-bold" data-type="welcome">
              🧪 Gửi Thử
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <input id="botChannelWelcome" type="text" placeholder="Channel ID (vd: 1234567890...)"
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-emerald-500 font-mono">
            <input id="botWebhookWelcome" type="url" placeholder="Hoặc Webhook URL..."
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-emerald-500 font-mono">
          </div>
          <span class="text-[10px] text-dc-muted">Báo chào mừng thành viên mới đăng ký web và nhận 5 Xu trải nghiệm.</span>
        </div>

        <!-- 3. Xu Changes -->
        <div class="flex flex-col gap-1">
          <div class="flex items-center justify-between">
            <label class="text-xs font-semibold text-white flex items-center gap-1.5">
              <span>💰</span>
              <span>Kênh Biến Động Số Dư (+ / - Xu):</span>
            </label>
            <button type="button" class="test-bot-btn text-[11px] px-2 py-0.5 rounded bg-white/5 hover:bg-white/10 text-amber-400 font-bold" data-type="xu">
              🧪 Gửi Thử
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <input id="botChannelXu" type="text" placeholder="Channel ID (vd: 1234567890...)"
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-amber-500 font-mono">
            <input id="botWebhookXu" type="url" placeholder="Hoặc Webhook URL..."
                   class="text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 focus:border-amber-500 font-mono">
          </div>
          <span class="text-[10px] text-dc-muted">Báo nạp Xu, trừ Xu khi làm nhiệm vụ hoặc chỉnh sửa từ Admin.</span>
        </div>

      </div>

      <!-- Bot Name & Avatar (Optional overrides) -->
      <div class="grid grid-cols-2 gap-2 pt-1">
        <div>
          <label class="text-[11px] font-semibold text-dc-muted block mb-1">Tên Bot hiển thị:</label>
          <input id="botNameInput" type="text" value="Quest Runner Bot"
                 class="w-full text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10">
        </div>
        <div>
          <label class="text-[11px] font-semibold text-dc-muted block mb-1">Link Avatar Bot:</label>
          <input id="botAvatarInput" type="url" value="https://www.2tamne.site/favicon.png"
                 class="w-full text-xs px-3 py-2 rounded bg-dc-rail text-white outline-none border border-white/10 font-mono">
        </div>
      </div>

      <!-- Message status box -->
      <div id="botConfigMsg" class="hidden rounded text-xs px-3 py-2"></div>

      <!-- Submit button -->
      <div class="pt-2 flex gap-2">
        <button id="saveBotConfigBtn" type="submit"
                class="flex-1 py-2.5 rounded text-xs font-bold text-white hover:brightness-110 shadow-md transition-all flex items-center justify-center gap-1.5"
                style="background:#5865f2">
          <span>💾</span>
          <span>Lưu Cấu Hình Bot Discord</span>
        </button>
      </div>

    </form>

  </div>
</div>

<script>
const API_STATS  = 'api/admin-stats.php';
const API_ACTION = 'api/admin-action.php';
const API_BOT_CFG= 'api/bot-config.php';


const PHASE = {
  init:'Khởi động', validate:'Xác thực token', fetch_quests:'Tải quest',
  enroll:'Đăng ký quest', process_quest:'Xử lý quest', wait_poll:'Chờ quét lại',
};
const STATUS_STYLE = {
  idle:      ['MỚI ĐĂNG KÝ','rgba(148,155,164,.2)', '#cbd5e1'],
  running:   ['ĐANG CHẠY',  'rgba(88,101,242,.2)',  '#8d96f8'],
  queued:    ['QUEUED',     'rgba(88,101,242,.2)',  '#8d96f8'],
  stopping:  ['DỪNG…',     'rgba(240,177,50,.15)', '#fbbf24'],
  completed: ['HOÀN THÀNH','rgba(35,165,89,.2)',   '#57d98b'],
  failed:    ['LỖI',       'rgba(242,63,66,.2)',   '#f87171'],
  stopped:   ['ĐÃ DỪNG',   'rgba(240,177,50,.15)', '#fbbf24'],
  banned:    ['ĐÃ BỊ CẤM', 'rgba(239,68,68,.25)',  '#ef4444'],
};


let allTasks = [];

// ── Lấy thống kê ────────────────────────────────────────────────────────────
async function fetchStats() {
  try {
    const res = await fetch(API_STATS);
    if (res.status === 401 || res.status === 403) {
      document.getElementById('taskGrid').innerHTML = '<div class="text-dc-red text-sm col-span-full text-center py-8">⚠️ Phiên đăng nhập Admin đã hết hạn. <a href="adql.php?logout=1" class="underline text-dc-blurple font-bold">Bấm vào đây để đăng nhập lại</a>.</div>';
      return;
    }
    const data = await res.json();
    if (!data.ok) {
      document.getElementById('taskGrid').innerHTML = `<div class="text-dc-red text-sm col-span-full text-center py-8">⚠️ Lỗi: ${data.message || 'Không thể tải dữ liệu'}</div>`;
      return;
    }

    allTasks = data.tasks || [];

    // Stat cards
    const s = data.summary;
    document.getElementById('statTotal').textContent  = s.total;
    document.getElementById('statActive').textContent = s.active;
    document.getElementById('statDone').textContent   = s.total_quests_done + (s.total_quests_all > 0 ? ' / ' + s.total_quests_all : ' quest');
    document.getElementById('statFailed').textContent = (s.failed + s.stopped + (s.banned || 0));

    // Timestamp
    document.getElementById('lastUpdate').textContent =
      'Cập nhật: ' + new Date().toLocaleTimeString('vi-VN');

    renderGrid();
  } catch (e) {
    console.error(e);
  }
}


// ── Render danh sách card ────────────────────────────────────────────────────
function renderGrid() {
  const filter = document.getElementById('filterStatus').value;
  const tasks  = filter ? allTasks.filter(t => t.status === filter || (filter === 'banned' && t.is_banned)) : allTasks;
  const grid   = document.getElementById('taskGrid');

  if (!tasks.length) {
    grid.innerHTML = '<div class="text-dc-muted text-sm col-span-full text-center py-8">Không có task nào.</div>';
    return;
  }

  grid.innerHTML = tasks.map(t => taskCard(t)).join('');

  // Gắn event cho các select chuyển quyền Admin
  grid.querySelectorAll('.admin-select').forEach(sel => {
    sel.onchange = async () => {
      const tid = sel.dataset.id;
      const adminName = sel.value;
      sel.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_admin', task_id: tid, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        sel.disabled = false;
      }
    };
  });

  // Gắn event cho các nút Stop
  grid.querySelectorAll('.btn-stop').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      btn.disabled = true;
      btn.textContent = '…';
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'stop', task_id: tid, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
        btn.textContent = '⏹ Stop';
      }
    };
  });

  // Gắn event cho các nút Resume (Tiếp tục)
  grid.querySelectorAll('.btn-resume').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      btn.disabled = true;
      btn.textContent = '…';
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'resume', task_id: tid, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
        btn.textContent = '▶ Tiếp tục';
      }
    };
  });

  // Gắn event cho nút Ban
  grid.querySelectorAll('.btn-ban').forEach(btn => {
    btn.onclick = async () => {
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      if (!confirm(`Bạn có chắc muốn CẤM (Ban) user này bởi ${adminName}?`)) return;
      const tid = btn.dataset.id;
      btn.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'ban', task_id: tid, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
      }
    };
  });

  // Gắn event cho nút Gỡ Ban (Unban)
  grid.querySelectorAll('.btn-unban').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      btn.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'unban', task_id: tid, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
      }
    };
  });

  // Gắn event cho nút Cộng / Trừ Xu nhanh
  grid.querySelectorAll('.btn-xu-adj').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      const amount = parseInt(btn.dataset.amount, 10);
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      btn.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'add_xu', task_id: tid, amount: amount, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
      }
    };
  });

  // Gắn event cho nút Đặt Xu tùy chỉnh
  grid.querySelectorAll('.btn-xu-custom').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      const curr = parseInt(btn.dataset.curr, 10) || 0;
      const card = btn.closest('.user-card');
      const adminName = card?.querySelector('.admin-select')?.value || 'Admin';
      const input = prompt(`Nhập số Xu mới cho user này (Hiện có: ${curr} Xu):`, curr);
      if (input === null || input.trim() === '') return;
      const amount = parseInt(input.trim(), 10);
      if (isNaN(amount) || amount < 0) {
        alert('Vui lòng nhập số Xu hợp lệ (>= 0).');
        return;
      }
      btn.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_xu', task_id: tid, amount: amount, admin_name: adminName }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
      }
    };
  });

  // Gắn event đổi chu kỳ quét tự động
  grid.querySelectorAll('.scan-interval-select').forEach(sel => {
    sel.onchange = async () => {
      const tid = sel.dataset.id;
      const days = parseInt(sel.value, 10);
      sel.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_interval', task_id: tid, days: days }),
        });
        await fetchStats();
      } catch (e) {
        sel.disabled = false;
      }
    };
  });

  // Gắn event bấm Quét ngay
  grid.querySelectorAll('.btn-trigger-scan').forEach(btn => {
    btn.onclick = async () => {
      const tid = btn.dataset.id;
      btn.disabled = true;
      btn.textContent = '⏳ Đang quét…';
      try {
        await fetch(API_ACTION, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'trigger_scan', task_id: tid }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
        btn.textContent = '🔄 Quét ngay';
      }
    };
  });

  // Gắn event cho nút Xóa
  grid.querySelectorAll('.btn-del').forEach(btn => {
    btn.onclick = async () => {
      if (!confirm('Bạn có chắc muốn xóa task này khỏi danh sách?')) return;
      const tid = btn.dataset.id;
      btn.disabled = true;
      try {
        await fetch(API_ACTION, {
          method: 'POST',

          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', task_id: tid }),
        });
        await fetchStats();
      } catch (e) {
        btn.disabled = false;
      }
    };
  });

  // Gắn event cho nút Xem Log
  grid.querySelectorAll('.btn-log').forEach(btn => {
    btn.onclick = () => {
      openLogModal(btn.dataset.id);
    };
  });
}

function formatNextScan(ts) {
  if (!ts || Number(ts) <= 0) return 'Chưa lên lịch';
  const dt = new Date(Number(ts) * 1000);
  const pad = n => String(n).padStart(2, '0');
  return `${pad(dt.getDate())}/${pad(dt.getMonth()+1)} ${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
}

function taskCard(t) {
  const isBanned = Boolean(t.is_banned || t.status === 'banned');

  const [statusLabel, statusBg, statusColor] = STATUS_STYLE[t.status] || ['Unknown', 'rgba(148,155,164,.15)', '#949ba4'];
  const pct      = Math.max(0, Math.min(100, Number(t.progress) || 0));
  const barColor = isBanned ? '#ef4444' : (pct >= 100 ? '#23a559' : '#5865f2');
  const phase    = isBanned ? 'Đã bị khóa quyền truy cập' : (PHASE[t.phase] || t.phase || '–');
  const isActive = !isBanned && ['running','queued','stopping'].includes(t.status);

  // Timestamps
  const created = t.created_at ? new Date(t.created_at).toLocaleString('vi-VN') : '–';
  const updated = t.updated_at ? new Date(t.updated_at).toLocaleString('vi-VN') : '–';

  // Quest summary
  const questInfo = (t.quest_count > 0)
    ? `<span style="color:#57d98b">${t.quest_done}</span>/<span>${t.quest_count}</span> quest`
    : '–';

  const currentQuest = (!isBanned && t.quest_current)
    ? `<div class="mt-1 text-xs truncate" style="color:#949ba4" title="${esc(t.quest_current)}">
        ${isActive ? '<span class="spin" style="font-size:.8rem">⟳</span> ' : ''}${esc(t.quest_current)}
       </div>` : '';

  // Nút hành động góc trên
  let actionBtn = '';
  if (isBanned) {
    actionBtn = `<button class="btn-unban text-xs px-2.5 py-1 rounded font-semibold transition-all hover:brightness-125 disabled:opacity-50"
                         data-id="${t.task_id}"
                         style="background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(34,197,94,.3)">
                   🔓 Gỡ Ban
                 </button>`;
  } else if (isActive) {
    actionBtn = `<button class="btn-stop text-xs px-2.5 py-1 rounded font-semibold transition-all hover:brightness-125 disabled:opacity-50"
                         data-id="${t.task_id}"
                         style="background:rgba(242,63,66,.2);color:#f87171;border:1px solid rgba(242,63,66,.3)">
                   ⏹ Stop
                 </button>`;
  } else {
    actionBtn = `<button class="btn-resume text-xs px-2.5 py-1 rounded font-semibold transition-all hover:brightness-125 disabled:opacity-50"
                         data-id="${t.task_id}"
                         style="background:rgba(35,165,89,.2);color:#57d98b;border:1px solid rgba(35,165,89,.3)">
                   ▶ Tiếp tục
                 </button>`;
  }

  // Nút Ban / Gỡ Ban ở hàng dưới
  let banBtn = '';
  if (!isBanned) {
    banBtn = `<button class="btn-ban text-xs px-2 py-1 rounded transition-colors font-medium"
                      data-id="${t.task_id}"
                      style="background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25)"
                      title="Cấm user này sử dụng hệ thống">
                🚫 Ban
              </button>`;
  }

  const borderLeft = isBanned ? 'border-left: 3px solid #ef4444;' : '';
  const assigned = t.assigned_admin || 'Chưa gán';
  const xuAmount = t.xu || 0;

  return `
    <div class="user-card" style="background:#2b2d31;border:1px solid rgba(255,255,255,.07);${borderLeft}border-radius:12px;padding:1.1rem;display:flex;flex-direction:column;gap:.7rem">

      <!-- Header row: Admin Selector & Status -->
      <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
        <div style="display:flex;align-items:center;gap:.35rem">
          <span style="font-size:.7rem;color:#949ba4;font-weight:600">👑 Quản lý:</span>
          <select class="admin-select text-xs font-semibold rounded px-2 py-0.5 outline-none transition-colors cursor-pointer"
                  data-id="${t.task_id}"
                  style="background:#1e1f22;color:#c4c9ff;border:1px solid rgba(88,101,242,.35)">
            <option value="Chưa gán" ${assigned === 'Chưa gán' ? 'selected' : ''}>Chưa gán</option>
            <option value="admin Tâm" ${assigned === 'admin Tâm' || assigned === 'Admin Tâm' ? 'selected' : ''}>admin Tâm</option>
            <option value="admin Hiếu" ${assigned === 'admin Hiếu' || assigned === 'Admin Hiếu' ? 'selected' : ''}>admin Hiếu</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem">
          <span style="background:${statusBg};color:${statusColor};border-radius:99px;font-size:.65rem;font-weight:700;letter-spacing:.04em;padding:.2rem .55rem;text-transform:uppercase">
            ${statusLabel}
          </span>
          ${actionBtn}
        </div>
      </div>

      <!-- Progress -->
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#949ba4;margin-bottom:.35rem">
          <span>${phase}</span>
          <span style="font-family:monospace;font-weight:700;color:${barColor}">${Math.round(pct)}%</span>
        </div>
        <div style="height:6px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:${pct}%;background:${barColor};border-radius:99px;transition:width .5s"></div>
        </div>
        ${currentQuest}
      </div>

      <!-- Quest & Discord User Info -->
      <div style="display:flex;align-items:center;justify-content:space-between;font-size:.75rem;gap:.5rem">
        <span style="color:#949ba4;white-space:nowrap">Quest: ${questInfo}</span>
        <span style="color:#949ba4;max-width:65%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:right" title="${esc(t.discord_name || '')}">
          Discord: <strong style="color:#8d96f8">${esc(t.discord_name || 'Đang kết nối…')}</strong>
        </span>
      </div>

      <!-- Xu Balance Row -->
      <div style="display:flex;align-items:center;justify-content:space-between;background:#1e1f22;padding:.45rem .65rem;border-radius:8px;font-size:.75rem">
        <div style="display:flex;align-items:center;gap:.35rem">
          <span style="color:#fbbf24;font-weight:700">💰 Số dư:</span>
          <span class="font-bold font-mono" style="color:#fbbf24;font-size:.9rem">${xuAmount}</span>
          <span style="color:#949ba4">Xu</span>
        </div>
        <div style="display:flex;align-items:center;gap:.25rem">
          <button class="btn-xu-adj text-xs px-1.5 py-0.5 rounded font-bold hover:brightness-125 transition-all"
                  data-id="${t.task_id}" data-amount="-1"
                  style="background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.3)" title="Trừ 1 Xu">-1</button>
          <button class="btn-xu-adj text-xs px-1.5 py-0.5 rounded font-bold hover:brightness-125 transition-all"
                  data-id="${t.task_id}" data-amount="1"
                  style="background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(34,197,94,.3)" title="Cộng 1 Xu">+1</button>
          <button class="btn-xu-adj text-xs px-1.5 py-0.5 rounded font-bold hover:brightness-125 transition-all"
                  data-id="${t.task_id}" data-amount="5"
                  style="background:rgba(240,177,50,.2);color:#fbbf24;border:1px solid rgba(240,177,50,.3)" title="Cộng 5 Xu">+5</button>
          <button class="btn-xu-custom text-xs px-1.5 py-0.5 rounded font-semibold hover:brightness-125 transition-all"
                  data-id="${t.task_id}" data-curr="${xuAmount}"
                  style="background:rgba(88,101,242,.2);color:#8d96f8;border:1px solid rgba(88,101,242,.3)" title="Đặt số Xu tùy chỉnh">✏️</button>
        </div>
      </div>

      <!-- Auto-Scan Schedule Row -->
      <div style="display:flex;align-items:center;justify-content:space-between;background:#1e1f22;padding:.45rem .65rem;border-radius:8px;font-size:.72rem">
        <div style="display:flex;align-items:center;gap:.35rem">
          <span style="color:#949ba4;font-weight:600">🔄 Quét:</span>
          <select class="scan-interval-select text-xs font-semibold rounded px-1.5 py-0.5 outline-none transition-colors cursor-pointer"
                  data-id="${t.task_id}"
                  style="background:#2b2d31;color:#c4c9ff;border:1px solid rgba(88,101,242,.25)">
            <option value="1" ${(t.scan_interval_days||1)==1?'selected':''}>1 ngày/lần</option>
            <option value="2" ${(t.scan_interval_days||1)==2?'selected':''}>2 ngày/lần</option>
            <option value="3" ${(t.scan_interval_days||1)==3?'selected':''}>3 ngày/lần</option>
            <option value="4" ${(t.scan_interval_days||1)==4?'selected':''}>4 ngày/lần</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem">
          <span style="color:#8d96f8;font-family:monospace;font-size:.68rem" title="Thời gian quét tiếp theo">⏳ ${formatNextScan(t.next_scan_at)}</span>
          <button class="btn-trigger-scan text-xs px-2 py-0.5 rounded font-semibold hover:brightness-125 transition-all"
                  data-id="${t.task_id}"
                  style="background:rgba(88,101,242,.2);color:#8d96f8;border:1px solid rgba(88,101,242,.3)"
                  title="Quét nhiệm vụ mới ngay lập tức">🔄 Quét ngay</button>
        </div>
      </div>


      <!-- Bottom row: Timestamps & Action Buttons -->

      <div style="font-size:.68rem;color:#6b7280;border-top:1px solid rgba(255,255,255,.05);padding-top:.6rem;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;flex-direction:column;gap:.1rem">
          <span>🕐 Tạo: ${created}</span>
          <span>🔄 Cập nhật: ${updated}</span>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem">
          ${banBtn}
          <button class="btn-log text-xs px-2.5 py-1 rounded bg-dc-blurple/20 text-dc-blurple hover:bg-dc-blurple/30 font-medium transition-colors"
                  data-id="${t.task_id}">
            📜 Log
          </button>
          <button class="btn-del text-xs px-2 py-1 rounded bg-white/5 text-dc-muted hover:text-dc-red transition-colors"
                  data-id="${t.task_id}" title="Xóa task">
            🗑
          </button>
        </div>
      </div>

    </div>`;
}


// ── LOG MODAL POLLING ────────────────────────────────────────────────────────
let modalTimer  = null;
let modalOffset = 0;
let currentModalTaskId = null;

function openLogModal(taskId) {
  currentModalTaskId = taskId;
  modalOffset = 0;

  document.getElementById('modalTaskId').textContent = taskId.slice(0, 16) + '…';
  document.getElementById('modalLogContent').textContent = 'Đang kết nối live log…\n';
  document.getElementById('logModal').classList.remove('hidden');

  pollModalLog();
  if (modalTimer) clearInterval(modalTimer);
  modalTimer = setInterval(pollModalLog, 2000);
}

function closeLogModal() {
  document.getElementById('logModal').classList.add('hidden');
  if (modalTimer) {
    clearInterval(modalTimer);
    modalTimer = null;
  }
  currentModalTaskId = null;
  // Làm mới stats sau khi đóng log
  fetchStats();
}

async function pollModalLog() {
  if (!currentModalTaskId) return;
  try {
    const res = await fetch(`api/logs.php?task_id=${currentModalTaskId}&offset=${modalOffset}`);
    const d   = await res.json();

    const logEl = document.getElementById('modalLogContent');
    if (d.content) {
      logEl.textContent += d.content;
      logEl.scrollTop = logEl.scrollHeight;
    }
    if (d.offset != null) {
      modalOffset = d.offset;
    }
  } catch (e) {
    console.error(e);
  }
}

document.getElementById('modalCloseBtn').onclick = closeLogModal;
document.getElementById('modalClearBtn').onclick = () => {
  document.getElementById('modalLogContent').textContent = '';
};
document.getElementById('logModal').onclick = (e) => {
  if (e.target.id === 'logModal') closeLogModal();
};

// ── BOT CONFIG MODAL ────────────────────────────────────────────────────────
const botConfigModal   = document.getElementById('botConfigModal');
const openBotConfigBtn = document.getElementById('openBotConfigBtn');
const closeBotConfigBtn= document.getElementById('closeBotConfigBtn');
const botConfigForm    = document.getElementById('botConfigForm');
const botConfigMsg     = document.getElementById('botConfigMsg');
const botTokenInput    = document.getElementById('botTokenInput');
const verifyTokenBtn   = document.getElementById('verifyTokenBtn');
const botVerifyStatus  = document.getElementById('botVerifyStatus');
const toggleTokenBtn   = document.getElementById('toggleBotTokenVisibility');

if (toggleTokenBtn && botTokenInput) {
  toggleTokenBtn.onclick = () => {
    if (botTokenInput.type === 'password') {
      botTokenInput.type = 'text';
      toggleTokenBtn.textContent = '🙈 Ẩn';
    } else {
      botTokenInput.type = 'password';
      toggleTokenBtn.textContent = '👁 Hiện';
    }
  };
}

function showBotMsg(text, isOk) {
  if (!botConfigMsg) return;
  botConfigMsg.textContent = text;
  botConfigMsg.className = `rounded text-xs px-3 py-2 ${isOk ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-red-500/20 text-red-300 border border-red-500/40'}`;
}

async function loadBotConfig() {
  try {
    const res  = await fetch(API_BOT_CFG);
    const data = await res.json();
    if (data.ok && data.config) {
      const c = data.config;
      document.getElementById('botEnabledInput').checked  = Boolean(c.enabled !== false);
      if (botTokenInput) botTokenInput.value              = c.bot_token || '';
      document.getElementById('botChannelQuests').value   = c.channel_quests || '';
      document.getElementById('botChannelWelcome').value  = c.channel_welcome || '';
      document.getElementById('botChannelXu').value       = c.channel_xu || '';
      document.getElementById('botWebhookQuests').value   = c.webhook_quests || '';
      document.getElementById('botWebhookWelcome').value  = c.webhook_welcome || '';
      document.getElementById('botWebhookXu').value       = c.webhook_xu || '';
      document.getElementById('botNameInput').value       = c.bot_name || 'Quest Runner Bot';
      document.getElementById('botAvatarInput').value     = c.bot_avatar || 'https://www.2tamne.site/favicon.png';
    }
  } catch (e) {
    console.error(e);
  }
}

if (verifyTokenBtn) {
  verifyTokenBtn.onclick = async () => {
    const token = botTokenInput ? botTokenInput.value.trim() : '';
    if (!token) {
      alert('Vui lòng nhập Bot Token trước khi kiểm tra!');
      return;
    }
    verifyTokenBtn.disabled = true;
    verifyTokenBtn.textContent = '⏳ Đang kiểm tra…';
    try {
      const res = await fetch(API_BOT_CFG, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'verify_token', bot_token: token })
      });
      const data = await res.json();
      if (botVerifyStatus) {
        botVerifyStatus.classList.remove('hidden');
        if (data.ok) {
          botVerifyStatus.className = 'text-xs px-2.5 py-1.5 rounded flex items-center gap-2 bg-emerald-500/15 border border-emerald-500/30 text-emerald-300';
          botVerifyStatus.innerHTML = `<span>✅</span><span><strong>${esc(data.bot_name)}</strong> (ID: ${data.bot_id})</span>`;
          if (data.bot_name)   document.getElementById('botNameInput').value   = data.bot_name;
          if (data.bot_avatar) document.getElementById('botAvatarInput').value = data.bot_avatar;
        } else {
          botVerifyStatus.className = 'text-xs px-2.5 py-1.5 rounded flex items-center gap-2 bg-red-500/15 border border-red-500/30 text-red-300';
          botVerifyStatus.innerHTML = `<span>❌</span><span>${esc(data.message)}</span>`;
        }
      }
    } catch (e) {
      alert('Lỗi kiểm tra token: ' + e.message);
    } finally {
      verifyTokenBtn.disabled = false;
      verifyTokenBtn.textContent = '🔍 Kiểm Tra Token';
    }
  };
}

if (openBotConfigBtn) {
  openBotConfigBtn.onclick = () => {
    loadBotConfig();
    if (botConfigModal) botConfigModal.classList.remove('hidden');
  };
}

if (closeBotConfigBtn) {
  closeBotConfigBtn.onclick = () => {
    if (botConfigModal) botConfigModal.classList.add('hidden');
  };
}

if (botConfigModal) {
  botConfigModal.onclick = (e) => {
    if (e.target.id === 'botConfigModal') botConfigModal.classList.add('hidden');
  };
}

// Save form
if (botConfigForm) {
  botConfigForm.onsubmit = async (e) => {
    e.preventDefault();
    const saveBtn = document.getElementById('saveBotConfigBtn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '⏳ Đang lưu…'; }

    try {
      const payload = {
        action: 'save',
        enabled: document.getElementById('botEnabledInput').checked,
        bot_token: botTokenInput ? botTokenInput.value.trim() : '',
        channel_quests: document.getElementById('botChannelQuests').value.trim(),
        channel_welcome: document.getElementById('botChannelWelcome').value.trim(),
        channel_xu: document.getElementById('botChannelXu').value.trim(),
        webhook_quests: document.getElementById('botWebhookQuests').value.trim(),
        webhook_welcome: document.getElementById('botWebhookWelcome').value.trim(),
        webhook_xu: document.getElementById('botWebhookXu').value.trim(),
        bot_name: document.getElementById('botNameInput').value.trim(),
        bot_avatar: document.getElementById('botAvatarInput').value.trim(),
      };

      const res = await fetch(API_BOT_CFG, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      showBotMsg(data.message || 'Đã lưu cấu hình!', data.ok);
    } catch (err) {
      showBotMsg(err.message, false);
    } finally {
      if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<span>💾</span><span>Lưu Cấu Hình Bot Discord</span>'; }
    }
  };
}

// Test Buttons
document.querySelectorAll('.test-bot-btn').forEach(btn => {
  btn.onclick = async () => {
    const type = btn.dataset.type;
    const botToken = botTokenInput ? botTokenInput.value.trim() : '';
    let channelId = '';
    let webhook = '';

    if (type === 'welcome') {
      channelId = document.getElementById('botChannelWelcome').value.trim();
      webhook   = document.getElementById('botWebhookWelcome').value.trim();
    } else if (type === 'xu') {
      channelId = document.getElementById('botChannelXu').value.trim();
      webhook   = document.getElementById('botWebhookXu').value.trim();
    } else {
      channelId = document.getElementById('botChannelQuests').value.trim();
      webhook   = document.getElementById('botWebhookQuests').value.trim();
    }

    if (!webhook && (!botToken || !channelId)) {
      alert('Vui lòng nhập Bot Token + Channel ID (hoặc dán Webhook URL) trước khi thử nghiệm!');
      return;
    }

    const origText = btn.textContent;
    btn.textContent = '⏳ Đang gửi…';
    btn.disabled = true;

    try {
      const res = await fetch(API_BOT_CFG, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'test', type, bot_token: botToken, channel_id: channelId, webhook })
      });
      const data = await res.json();
      showBotMsg(data.message, data.ok);
    } catch (err) {
      showBotMsg('Lỗi gửi: ' + err.message, false);
    } finally {
      btn.textContent = origText;
      btn.disabled = false;
    }
  };
});


function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Filter change
document.getElementById('filterStatus').onchange = renderGrid;

// Auto-refresh stats
fetchStats();
let dot = true;
setInterval(() => {
  fetchStats();
  const d = document.getElementById('refreshDot');
  if (d) { dot = !dot; d.style.background = dot ? '#23a559' : '#949ba4'; }
}, 5000);
</script>

<?php endif; ?>

</body>
</html>
