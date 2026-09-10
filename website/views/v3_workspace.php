<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * 2TOOLNE WEB V3 — AUTHENTICATED PRODUCT WORKSPACE
 * Visual DNA: #090A0C canvas, #191B1F elevated surface, #202228 secondary,
 * #FF7A00 brand accent, subtle 2.5D depth, thin borders, Lucide SVG icons.
 * ═══════════════════════════════════════════════════════════════════════════
 */

// Determine active license and entitlement status
$has_active_entitlement = false;
$active_product_title = 'Chưa Kích Hoạt';
$active_tier_label = 'CHƯA CÓ';
$active_plan_expiry = 'Vui lòng kích hoạt gói bản quyền';
$active_device_status = 'Chưa liên kết thiết bị';

if (!empty($active_license)) {
    $has_active_entitlement = true;
    $tier = $active_license['tier'] ?? 'VIP';
    $active_tier_label = ($tier === 'TRIAL') ? 'DÙNG THỬ (3 NGÀY)' : (($tier === 'LIFETIME') ? 'VĨNH VIỄN' : 'GÓI ' . htmlspecialchars($tier));
    $is_capcut = ($active_license['product'] ?? '') === '2toolne.capcut.v2' || ($active_license['product'] ?? '') === 'CAPCUT_V2' || strpos($active_license['key'] ?? '', '2TL-CAP-') === 0;
    $active_product_title = $is_capcut ? '2TOOLNE AutoEdit for CapCut' : '2TOOLNE Studio Suite';
    
    if (!empty($active_license['expires_at'])) {
        $active_plan_expiry = (strpos($active_license['expires_at'], '2099') !== false) 
            ? 'Bản quyền vô thời hạn (Vĩnh viễn)' 
            : 'Hết hạn: ' . date('d/m/Y', strtotime($active_license['expires_at']));
    } else {
        $active_plan_expiry = ($active_license['duration_days'] ?? 30) . ' ngày sử dụng';
    }

    if (!empty($active_license['hwid'])) {
        $active_device_status = 'Đã liên kết: ' . htmlspecialchars($active_license['device_name'] ?: 'Máy tính cá nhân');
    } else {
        $active_device_status = 'Sẵn sàng kích hoạt trên máy mới';
    }
} elseif (in_array($user_info['role'] ?? '', ['admin', 'super_admin'])) {
    $has_active_entitlement = true;
    $active_tier_label = 'ADMIN';
    $active_product_title = '2TOOLNE Enterprise Master Suite';
    $active_plan_expiry = 'Đặc quyền Quản Trị Viên (Vĩnh viễn)';
    $active_device_status = 'Không giới hạn thiết bị';
}

// Calculate cloud metrics
$primary_space = !empty($user_cloud_spaces) ? $user_cloud_spaces[0] : null;
$space_used_bytes = (int)($primary_space['used_bytes'] ?? 0);
$space_quota_bytes = max((int)($primary_space['effective_quota_bytes'] ?? (5 * 1024 * 1024 * 1024)), 1);
$space_pct = min(100, round(($space_used_bytes / $space_quota_bytes) * 100, 1));
$space_used_formatted = ($space_used_bytes > 1073741824) 
    ? round($space_used_bytes / 1073741824, 2) . ' GB' 
    : round($space_used_bytes / 1048576, 1) . ' MB';
$space_quota_formatted = round($space_quota_bytes / 1073741824, 1) . ' GB';

// Primary Team
$primary_team = !empty($user_teams) ? $user_teams[0] : null;
?>

<!-- ═══ 1. COMPACT GLASS TOPBAR (58px) ═══ -->
<header class="v3-topbar">
    <div class="v3-topbar-left">
        <a href="index.php" class="v3-topbar-brand">
            <img src="assets/favicon.png" alt="2TOOLNE Logo">
            <span class="v3-topbar-brand-title">2TOOLNE</span>
        </a>
        <div class="v3-topbar-divider desktop-only"></div>
        <div class="v3-breadcrumb desktop-only">
            <span>Không Gian Làm Việc</span>
            <span style="opacity:0.35">/</span>
            <span class="v3-breadcrumb-current" id="v3-breadcrumb-text">Trang Chủ</span>
        </div>
        <div class="v3-workspace-chip" onclick="switchMainTab('tab-cloud-storage')" title="Bấm để chuyển đổi không gian làm việc">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <span id="topbar-space-label"><?= $primary_team ? htmlspecialchars($primary_team['name']) : 'Cá Nhân' ?></span>
        </div>
    </div>

    <div class="v3-topbar-right">
        <!-- Live Token Balance Chip -->
        <a href="#tab-wallet-view" onclick="switchMainTab('tab-wallet-view'); return false;" class="v3-token-pill" title="Số dư lượt ảnh AI (Tokens) — Bấm để nạp thêm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M15 9.5a3.5 3.5 0 0 0-7 0c0 4 7 2 7 6a3.5 3.5 0 0 1-7 0"/></svg>
            <span id="nav-token-balance"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span> Tokens
        </a>

        <!-- Support Link -->
        <a href="https://zalo.me/0326649304" target="_blank" class="v3-btn v3-btn-ghost v3-btn-xs desktop-only" style="color:var(--v3-text-muted);font-size:12px;text-decoration:none">
            Hỗ Trợ Zalo
        </a>

        <?php if (in_array($user_info['role'] ?? '', ['admin', 'super_admin', 'sales', 'tech_support'])): ?>
            <a href="license_admin.php" class="v3-btn v3-btn-xs v3-btn-outline desktop-only" style="border-color:rgba(255,122,0,0.4);color:var(--v3-primary);text-decoration:none">
                👑 Quản Trị
            </a>
        <?php endif; ?>

        <!-- User Profile Menu -->
        <div class="v3-user-menu-btn" title="Tài khoản @<?= htmlspecialchars($user_info['username']) ?>">
            <div class="v3-user-avatar"><?= strtoupper(substr($user_info['username'], 0, 1)) ?></div>
            <span class="desktop-only" style="font-size:12.5px;font-weight:600;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?>
            </span>
            <a href="?logout=1" class="v3-btn v3-btn-ghost v3-btn-xs" style="color:var(--v3-text-muted);padding:0 4px" title="Đăng Xuất">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            </a>
        </div>

        <!-- Mobile Drawer Toggle -->
        <button type="button" class="v3-btn v3-btn-outline v3-btn-xs mobile-only" onclick="toggleV3MobileSidebar()" aria-label="Menu" style="padding:0 8px;height:32px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
    </div>
</header>

<!-- ═══ 2. WORKSPACE CONTAINER (SIDEBAR + MAIN) ═══ -->
<div class="v3-app-shell">
    <div class="v3-workspace-container">
        <!-- SIDEBAR (232px Full-Height) -->
        <aside class="v3-sidebar" id="v3-app-sidebar">
            <div class="v3-sidebar-nav-scroll">
                <!-- Group 1: TỔNG QUAN -->
                <div class="v3-nav-group-title">TỔNG QUAN</div>
                <ul class="v3-nav-list">
                    <li>
                        <button type="button" class="v3-nav-item active" id="btn-tab-overview" onclick="switchMainTab('tab-overview')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <span>Trang Chủ</span>
                        </button>
                    </li>
                </ul>

                <!-- Group 2: TÀI KHOẢN -->
                <div class="v3-nav-group-title">TÀI KHOẢN</div>
                <ul class="v3-nav-list">
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-keys" onclick="switchMainTab('tab-my-keys')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                            <span>Bản Quyền</span>
                            <?php if ($has_active_entitlement): ?>
                                <span class="v3-nav-badge" style="background:rgba(16,185,129,0.15);color:#10B981">Active</span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-wallet" onclick="switchMainTab('tab-wallet-view')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                            <span>Ví &amp; Token</span>
                            <span class="v3-nav-badge"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Group 3: LÀM VIỆC -->
                <div class="v3-nav-group-title">LÀM VIỆC</div>
                <ul class="v3-nav-list">
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-cloud" onclick="switchMainTab('tab-cloud-storage')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                            <span>Cloud Storage</span>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-team" onclick="switchMainTab('tab-team')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span>Đội Nhóm (Team)</span>
                            <?php if ($primary_team): ?>
                                <span class="v3-nav-badge"><?= count($user_team_members) ?>/<?= $primary_team['member_slots'] ?? 5 ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-ai" onclick="switchMainTab('tab-ai-connection')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="M2 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M12 22v-4"/><path d="m19.07 19.07-2.83-2.83"/><path d="M22 12h-4"/><path d="m19.07 4.93-2.83 2.83"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>AI Connection</span>
                        </button>
                    </li>
                </ul>

                <!-- Group 4: HỆ THỐNG -->
                <div class="v3-nav-group-title">HỆ THỐNG</div>
                <ul class="v3-nav-list">
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-downloads" onclick="switchMainTab('tab-downloads')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Tải Phần Mềm</span>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="v3-nav-item" id="btn-tab-settings" onclick="switchMainTab('tab-settings')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            <span>Cài Đặt</span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Sidebar User Profile Footer -->
            <div class="v3-sidebar-footer">
                <div class="v3-sidebar-user-card">
                    <div class="v3-user-avatar"><?= strtoupper(substr($user_info['username'], 0, 1)) ?></div>
                    <div class="v3-sidebar-user-info">
                        <div class="v3-sidebar-user-name"><?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?></div>
                        <div class="v3-sidebar-user-role">@<?= htmlspecialchars($user_info['username']) ?></div>
                    </div>
                    <a href="?logout=1" class="v3-btn v3-btn-ghost v3-btn-xs" style="color:var(--v3-text-subtle);padding:2px" title="Đăng Xuất">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                    </a>
                </div>
            </div>
        </aside>

        <!-- MAIN WORKSPACE CONTENT -->
        <main class="v3-main-content">
            <!-- Flash Alerts -->
            <?php if ($msg_success): ?>
                <div class="v3-card v3-card-glow" style="margin-bottom:20px;border-color:rgba(16,185,129,0.3);background:rgba(16,185,129,0.06);display:flex;align-items:center;gap:12px;padding:14px 18px">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <div style="font-size:13.5px;color:#fff"><?= $msg_success ?></div>
                </div>
            <?php endif; ?>
            <?php if ($msg_error): ?>
                <div class="v3-card" style="margin-bottom:20px;border-color:rgba(239,68,68,0.3);background:rgba(239,68,68,0.06);display:flex;align-items:center;gap:12px;padding:14px 18px">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div style="font-size:13.5px;color:#fff"><?= $msg_error ?></div>
                </div>
            <?php endif; ?>

            <!-- Pending Team Invites Banner -->
            <?php if (!empty($user_pending_invites)): ?>
                <?php foreach ($user_pending_invites as $invite): ?>
                    <div class="v3-card" style="margin-bottom:20px;border-color:rgba(56,189,248,0.35);background:linear-gradient(135deg, rgba(56,189,248,0.08), rgba(25,27,31,0.95));display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:36px;height:36px;border-radius:9px;background:rgba(56,189,248,0.15);display:flex;align-items:center;justify-content:center;color:#38BDF8">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
                            </div>
                            <div>
                                <div style="font-size:14px;font-weight:700;color:#fff">
                                    Lời mời tham gia Đội Nhóm: <span style="color:#38bdf8"><?= htmlspecialchars($invite['team_name']) ?></span>
                                </div>
                                <div style="font-size:12px;color:var(--v3-text-muted)">
                                    Từ Trưởng nhóm <b><?= htmlspecialchars($invite['owner_fullname'] ?: $invite['owner_username']) ?></b> (@<?= htmlspecialchars($invite['owner_username']) ?>). Dùng chung bộ nhớ Cloud và không gian làm việc Team.
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="respond_team_invite">
                                <input type="hidden" name="invite_id" value="<?= htmlspecialchars($invite['invite_id']) ?>">
                                <input type="hidden" name="decision" value="ACCEPT">
                                <button type="submit" class="v3-btn v3-btn-primary v3-btn-sm">Đồng Ý Tham Gia</button>
                            </form>
                            <form method="POST" style="margin:0" onsubmit="return confirm('Bạn có chắc chắn muốn từ chối lời mời vào nhóm này?')">
                                <input type="hidden" name="action" value="respond_team_invite">
                                <input type="hidden" name="invite_id" value="<?= htmlspecialchars($invite['invite_id']) ?>">
                                <input type="hidden" name="decision" value="REJECT">
                                <button type="submit" class="v3-btn v3-btn-outline v3-btn-sm" style="color:var(--v3-danger);border-color:rgba(239,68,68,0.3)">Từ Chối</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 1: OVERVIEW / HOME (#tab-overview)
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-overview" class="tab-pane active">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            Xin chào, <?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?> 👋
                        </h1>
                        <p class="v3-module-subtitle">
                            Không gian điều phối tự động hóa &amp; AI 2TOOLNE Studio 2026. Quản lý bản quyền, token và tài nguyên đám mây.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px">
                        <button type="button" class="v3-btn v3-btn-primary" onclick="switchMainTab('tab-downloads')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Tải AutoEdit 2.0</span>
                        </button>
                    </div>
                </div>

                <!-- 4 High-Value Metric Cards Grid -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;margin-bottom:24px">
                    <!-- Card 1: License Status -->
                    <div class="v3-card <?= $has_active_entitlement ? 'v3-card-glow' : '' ?>">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                            <span style="font-size:11.5px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em">BẢN QUYỀN CHÍNH</span>
                            <span class="v3-badge <?= $has_active_entitlement ? 'v3-badge-active' : 'v3-badge-neutral' ?>">
                                <?= $active_tier_label ?>
                            </span>
                        </div>
                        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:4px"><?= $active_product_title ?></div>
                        <div style="font-size:12px;color:var(--v3-text-muted);margin-bottom:14px"><?= $active_plan_expiry ?></div>
                        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--v3-border-subtle);padding-top:10px">
                            <span style="font-size:11.5px;color:var(--v3-text-subtle)"><?= $active_device_status ?></span>
                            <a href="#tab-my-keys" onclick="switchMainTab('tab-my-keys'); return false;" style="font-size:12px;font-weight:600;color:var(--v3-primary);text-decoration:none">Chi tiết &rarr;</a>
                        </div>
                    </div>

                    <!-- Card 2: AI Token Balance -->
                    <div class="v3-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                            <span style="font-size:11.5px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em">SỐ DƯ TOKEN AI</span>
                            <span class="v3-badge v3-badge-brand">On-Device AI</span>
                        </div>
                        <div style="font-size:26px;font-weight:800;color:#fff;font-family:'JetBrains Mono', monospace;margin-bottom:4px">
                            <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?>
                        </div>
                        <div style="font-size:12px;color:var(--v3-text-muted);margin-bottom:14px">Khả dụng cho AI Upscale 2K/4K</div>
                        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--v3-border-subtle);padding-top:10px">
                            <span style="font-size:11.5px;color:var(--v3-text-subtle)">Tự động bảo lưu</span>
                            <a href="#tab-wallet-view" onclick="switchMainTab('tab-wallet-view'); return false;" style="font-size:12px;font-weight:600;color:var(--v3-primary);text-decoration:none">+ Nạp Thêm &rarr;</a>
                        </div>
                    </div>

                    <!-- Card 3: Cloud Storage -->
                    <div class="v3-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                            <span style="font-size:11.5px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em">DUNG LƯỢNG CLOUD</span>
                            <span class="v3-badge v3-badge-neutral"><?= $space_pct ?>%</span>
                        </div>
                        <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:6px">
                            <span style="font-size:20px;font-weight:700;color:#fff;font-family:'JetBrains Mono', monospace"><?= $space_used_formatted ?></span>
                            <span style="font-size:12px;color:var(--v3-text-subtle)">/ <?= $space_quota_formatted ?></span>
                        </div>
                        <div style="height:4px;background:rgba(255,255,255,0.06);border-radius:2px;overflow:hidden;margin-bottom:12px">
                            <div style="height:100%;background:var(--v3-primary);width:<?= $space_pct ?>%;transition:width 300ms ease"></div>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--v3-border-subtle);padding-top:10px">
                            <span style="font-size:11.5px;color:var(--v3-text-subtle)"><?= $primary_space ? htmlspecialchars($primary_space['name']) : 'Chưa kích hoạt' ?></span>
                            <a href="#tab-cloud-storage" onclick="switchMainTab('tab-cloud-storage'); return false;" style="font-size:12px;font-weight:600;color:var(--v3-primary);text-decoration:none">Mở Cloud &rarr;</a>
                        </div>
                    </div>

                    <!-- Card 4: Team & AI Gateway -->
                    <div class="v3-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                            <span style="font-size:11.5px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em">ĐỘI NHÓM &amp; KẾT NỐI</span>
                            <span class="v3-badge v3-badge-neutral"><?= count($user_ai_keys) ?> Keys</span>
                        </div>
                        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:4px">
                            <?= $primary_team ? htmlspecialchars($primary_team['name']) : 'Không gian cá nhân' ?>
                        </div>
                        <div style="font-size:12px;color:var(--v3-text-muted);margin-bottom:14px">
                            <?= $primary_team ? (count($user_team_members) . ' Thành viên hoạt động') : 'Chưa có đội nhóm cộng tác' ?>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--v3-border-subtle);padding-top:10px">
                            <span style="font-size:11.5px;color:var(--v3-text-subtle)">API Gateway</span>
                            <a href="#tab-team" onclick="switchMainTab('tab-team'); return false;" style="font-size:12px;font-weight:600;color:var(--v3-primary);text-decoration:none">Quản lý &rarr;</a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Strip -->
                <div class="v3-card" style="margin-bottom:24px">
                    <div class="v3-card-header">
                        <div class="v3-card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            <span>Thao Tác Nhanh</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <button type="button" class="v3-btn v3-btn-secondary" onclick="switchMainTab('tab-downloads')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Tải AutoEdit 2.0</span>
                        </button>
                        <button type="button" class="v3-btn v3-btn-secondary" onclick="switchMainTab('tab-wallet-view')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                            <span>Mua Thêm Token</span>
                        </button>
                        <button type="button" class="v3-btn v3-btn-secondary" onclick="switchMainTab('tab-cloud-storage')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                            <span>Mở 2TOOLNE Cloud</span>
                        </button>
                        <button type="button" class="v3-btn v3-btn-secondary" onclick="openCreateAiKeyModal()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="M2 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M12 22v-4"/><path d="m19.07 19.07-2.83-2.83"/><path d="M22 12h-4"/><path d="m19.07 4.93-2.83 2.83"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>Tạo AI Key Mới</span>
                        </button>
                    </div>
                </div>

                <!-- Product Ecosystem Status -->
                <div class="v3-card">
                    <div class="v3-card-header">
                        <div class="v3-card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            <span>Hệ Sinh Thái Phần Mềm 2TOOLNE 2026</span>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px">
                        <div style="padding:14px;border-radius:var(--v3-radius-md);background:var(--v3-surface-2);border:1px solid var(--v3-border-subtle)">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <b style="font-size:14px;color:#fff">2TOOLNE AutoEdit for CapCut</b>
                                <span class="v3-badge v3-badge-active">v2.0.1 Stable</span>
                            </div>
                            <p style="font-size:12.5px;color:var(--v3-text-muted);margin:0 0 12px;line-height:1.5">
                                Native Desktop Suite tự động hóa CapCut Desktop. Tương thích chính xác CapCut 9.3.0.3970. Tự sinh Timeline, Scale/Position mượt mà, rãnh âm thanh và phụ đề XML.
                            </p>
                            <button type="button" class="v3-btn v3-btn-xs v3-btn-outline" onclick="switchMainTab('tab-downloads')">Tải Bộ Cài Đặt</button>
                        </div>

                        <div style="padding:14px;border-radius:var(--v3-radius-md);background:var(--v3-surface-2);border:1px solid var(--v3-border-subtle)">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <b style="font-size:14px;color:#fff">2TOOLNE Upscale 4K</b>
                                <span class="v3-badge v3-badge-active">v1.1.2</span>
                            </div>
                            <p style="font-size:12.5px;color:var(--v3-text-muted);margin:0 0 12px;line-height:1.5">
                                Trí tuệ nhân tạo phục chế và siêu phân giải ảnh 2K/4K tốc độ cao, tận dụng phần cứng GPU máy tính (Vulkan / DirectML / Metal).
                            </p>
                            <button type="button" class="v3-btn v3-btn-xs v3-btn-outline" onclick="switchMainTab('tab-wallet-view')">Xem Số Dư &amp; Nạp</button>
                        </div>

                        <div style="padding:14px;border-radius:var(--v3-radius-md);background:var(--v3-surface-2);border:1px solid var(--v3-border-subtle)">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <b style="font-size:14px;color:#fff">2TOOLNE Cloud &amp; AI Gateway</b>
                                <span class="v3-badge v3-badge-brand">Connected</span>
                            </div>
                            <p style="font-size:12.5px;color:var(--v3-text-muted);margin:0 0 12px;line-height:1.5">
                                Hệ thống lưu trữ đám mây tốc độ cao và API Gateway phân quyền hỗ trợ làm việc cộng tác đội nhóm và tự động hóa pipeline.
                            </p>
                            <button type="button" class="v3-btn v3-btn-xs v3-btn-outline" onclick="switchMainTab('tab-cloud-storage')">Truy Cập Không Gian</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 2: LICENSE (#tab-my-keys)
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-my-keys" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                            <span>Bản Quyền 2TOOLNE</span>
                        </h1>
                        <p class="v3-module-subtitle">Quản lý mã bản quyền phần mềm, liên kết máy tính và trạng thái kích hoạt.</p>
                    </div>
                    <button type="button" class="v3-btn v3-btn-primary" onclick="switchMainTab('tab-buy-key')">
                        + Mua Bản Quyền Mới
                    </button>
                </div>

                <!-- Hero Status Panel -->
                <div class="v3-card v3-card-glow" style="margin-bottom:24px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
                        <div>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                <span class="v3-badge <?= $has_active_entitlement ? 'v3-badge-active' : 'v3-badge-expired' ?>" style="font-size:12.5px;padding:3px 10px">
                                    <?= $has_active_entitlement ? '● ACTIVE — ĐANG HOẠT ĐỘNG' : 'CHƯA KÍCH HOẠT' ?>
                                </span>
                                <span style="font-size:12.5px;color:var(--v3-text-muted)"><?= $active_tier_label ?></span>
                            </div>
                            <div style="font-size:20px;font-weight:700;color:#fff;margin-bottom:6px"><?= $active_product_title ?></div>
                            <div style="font-size:13px;color:var(--v3-text-muted)">
                                Thời hạn: <b style="color:#fff"><?= $active_plan_expiry ?></b> &bull; Trạng thái máy tính: <b style="color:var(--v3-primary)"><?= $active_device_status ?></b>
                            </div>
                        </div>
                        <div>
                            <button type="button" class="v3-btn v3-btn-primary" onclick="switchMainTab('tab-downloads')">
                                Tải Bộ Cài Ngay
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Keys & Devices Table -->
                <div class="v3-card">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Danh Sách Mã Bản Quyền Đã Sở Hữu</div>
                    </div>
                    <?php 
                    $my_keys = $user_info['keys'] ?? [];
                    if (empty($my_keys)): 
                    ?>
                        <div class="v3-empty-state">
                            <div class="v3-empty-state-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                            </div>
                            <div class="v3-empty-state-title">Chưa Có Khóa Bản Quyền Nào</div>
                            <div class="v3-empty-state-desc">Bạn chưa kích hoạt mã bản quyền nào trên tài khoản. Hãy nhấn nút bên dưới để chọn gói phần mềm phù hợp.</div>
                            <button type="button" class="v3-btn v3-btn-primary" onclick="switchMainTab('tab-buy-key')">Mua Bản Quyền Mới</button>
                        </div>
                    <?php else: ?>
                        <div class="v3-table-wrapper">
                            <table class="v3-table">
                                <thead>
                                    <tr>
                                        <th>Khóa Bản Quyền</th>
                                        <th>Sản Phẩm</th>
                                        <th>Gói Dịch Vụ</th>
                                        <th>Thời Hạn</th>
                                        <th>Trạng Thái</th>
                                        <th>Thiết Bị Kích Hoạt</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_keys as $k): 
                                        $lic = $licenses_db[$k] ?? null;
                                        if (!$lic) continue;
                                        $status = $lic['status'] ?? 'active';
                                        $tier = $lic['tier'] ?? 'VIP';
                                        $hwid = $lic['hwid'] ?? '';
                                        $is_capcut = ($lic['product'] ?? '') === '2toolne.capcut.v2' || ($lic['product'] ?? '') === 'CAPCUT_V2' || strpos($k, '2TL-CAP-') === 0;
                                        $is_2toolne = !$is_capcut && (($lic['product'] ?? '') === '2TOOLNE' || strpos($k, '2TOOLNE-') === 0);
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:8px">
                                                    <code style="font-family:'JetBrains Mono', monospace;font-size:12.5px;color:var(--v3-primary);font-weight:600"><?= htmlspecialchars($k) ?></code>
                                                    <button type="button" class="v3-btn v3-btn-ghost v3-btn-xs" onclick="copyText('<?= htmlspecialchars($k) ?>')" title="Sao chép">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($is_capcut): ?>
                                                    <span class="v3-badge v3-badge-brand">AutoEdit CapCut V2</span>
                                                <?php elseif ($is_2toolne): ?>
                                                    <span class="v3-badge v3-badge-active">2TOOLNE Studio</span>
                                                <?php else: ?>
                                                    <span class="v3-badge v3-badge-neutral">Slideshow AI</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="v3-badge <?= $tier === 'TRIAL' ? 'v3-badge-trial' : 'v3-badge-active' ?>">
                                                    <?= $tier === 'TRIAL' ? 'DÙNG THỬ' : ($tier === 'LIFETIME' ? 'VĨNH VIỄN' : htmlspecialchars($tier)) ?>
                                                </span>
                                            </td>
                                            <td style="font-size:12.5px;color:var(--v3-text-muted)">
                                                <?= $lic['expires_at'] ? (strpos($lic['expires_at'], '2099') !== false ? 'Vĩnh viễn' : htmlspecialchars($lic['expires_at'])) : ($lic['duration_days'] . ' ngày') ?>
                                            </td>
                                            <td>
                                                <span class="v3-badge <?= $status === 'active' ? 'v3-badge-active' : 'v3-badge-expired' ?>">
                                                    <?= $status === 'active' ? 'HOẠT ĐỘNG' : 'HẾT HẠN' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($hwid): ?>
                                                    <div style="font-size:11.5px">
                                                        <code style="color:var(--v3-text-muted)"><?= substr($hwid, 0, 10) ?>...</code>
                                                        <div style="color:var(--v3-text-subtle)"><?= htmlspecialchars($lic['device_name'] ?: 'Máy tính cá nhân') ?></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:var(--v3-success);font-size:12px">Sẵn sàng (Chưa gắn máy)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:6px">
                                                    <?php if ($hwid): ?>
                                                        <button type="button" class="v3-btn v3-btn-outline v3-btn-xs" onclick="openResetHwidModal('<?= htmlspecialchars($k) ?>')">
                                                            Đổi Máy
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="font-size:11.5px;color:var(--v3-text-subtle)">—</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 3: WALLET & TOKENS (#tab-wallet-view)
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-wallet-view" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                            <span>Ví &amp; Token AI</span>
                        </h1>
                        <p class="v3-module-subtitle">Số dư lượt phóng to ảnh AI siêu phân giải (Upscale 2K/4K) và lịch sử giao dịch.</p>
                    </div>
                </div>

                <!-- Hero Balance Panel -->
                <div class="v3-card v3-card-glow" style="margin-bottom:24px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px">
                                SỐ DƯ TOKEN HIỆN CÓ
                            </div>
                            <div style="display:flex;align-items:baseline;gap:10px">
                                <span style="font-size:36px;font-weight:800;color:#fff;font-family:'JetBrains Mono', monospace">
                                    <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?>
                                </span>
                                <span style="font-size:14px;color:var(--v3-primary);font-weight:600">Lượt phóng to</span>
                            </div>
                            <div style="font-size:12.5px;color:var(--v3-text-muted);margin-top:4px">
                                1 Lượt = 1 Ảnh 2K siêu nét &bull; 2 Lượt = 1 Ảnh 4K Ultra-HD
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <button type="button" class="v3-btn v3-btn-primary" onclick="document.getElementById('wallet-packages-section').scrollIntoView({behavior:'smooth'})">
                                + Nạp Thêm Token
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recharge Packages Grid -->
                <div id="wallet-packages-section" style="margin-bottom:24px">
                    <div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:14px;display:flex;align-items:center;gap:8px">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M15 9.5a3.5 3.5 0 0 0-7 0c0 4 7 2 7 6a3.5 3.5 0 0 1-7 0"/></svg>
                        <span>Chọn Gói Nạp Lượt Ảnh (Kích Hoạt Tự Động Qua SePay)</span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px">
                        <!-- Package 1 -->
                        <div class="v3-card" style="text-align:center">
                            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px">Gói 50 Lượt</div>
                            <div style="font-size:24px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:4px">50.000đ</div>
                            <div style="font-size:11.5px;color:var(--v3-text-muted);margin-bottom:14px">1.000đ / lượt phóng to</div>
                            <button type="button" class="v3-btn v3-btn-secondary" style="width:100%" onclick="openQrPayment('Gói 50 Lượt Token', '50.000đ', 365, 'VIP', 'TOKEN_WALLET')">
                                Nạp Gói Này
                            </button>
                        </div>

                        <!-- Package 2 (Popular) -->
                        <div class="v3-card v3-card-glow" style="text-align:center;position:relative">
                            <span class="v3-badge v3-badge-brand" style="position:absolute;top:10px;right:10px">Phổ biến</span>
                            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px">Gói 200 Lượt</div>
                            <div style="font-size:24px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:4px">150.000đ</div>
                            <div style="font-size:11.5px;color:#10B981;margin-bottom:14px">Tiết kiệm 25% (750đ / lượt)</div>
                            <button type="button" class="v3-btn v3-btn-primary" style="width:100%" onclick="openQrPayment('Gói 200 Lượt Token', '150.000đ', 365, 'VIP', 'TOKEN_WALLET')">
                                Nạp Gói Này
                            </button>
                        </div>

                        <!-- Package 3 -->
                        <div class="v3-card" style="text-align:center">
                            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px">Gói 500 Lượt</div>
                            <div style="font-size:24px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:4px">300.000đ</div>
                            <div style="font-size:11.5px;color:#10B981;margin-bottom:14px">Tiết kiệm 40% (600đ / lượt)</div>
                            <button type="button" class="v3-btn v3-btn-secondary" style="width:100%" onclick="openQrPayment('Gói 500 Lượt Token', '300.000đ', 365, 'VIP', 'TOKEN_WALLET')">
                                Nạp Gói Này
                            </button>
                        </div>

                        <!-- Package 4 -->
                        <div class="v3-card" style="text-align:center">
                            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px">Gói 1.000 Lượt</div>
                            <div style="font-size:24px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:4px">500.000đ</div>
                            <div style="font-size:11.5px;color:#10B981;margin-bottom:14px">Tiết kiệm 50% (500đ / lượt)</div>
                            <button type="button" class="v3-btn v3-btn-secondary" style="width:100%" onclick="openQrPayment('Gói 1.000 Lượt Token', '500.000đ', 365, 'VIP', 'TOKEN_WALLET')">
                                Nạp Gói Này
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Transactions Ledger Table -->
                <div class="v3-card">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Lịch Sử Biến Động Token</div>
                    </div>
                    <?php if (empty($user_tokens_tx)): ?>
                        <div class="v3-empty-state">
                            <div class="v3-empty-state-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
                            </div>
                            <div class="v3-empty-state-title">Chưa Có Giao Dịch Nào</div>
                            <div class="v3-empty-state-desc">Bạn chưa thực hiện giao dịch nạp hoặc tiêu thụ token nào.</div>
                        </div>
                    <?php else: ?>
                        <div class="v3-table-wrapper">
                            <table class="v3-table">
                                <thead>
                                    <tr>
                                        <th>Mã Giao Dịch</th>
                                        <th>Loại</th>
                                        <th>Số Lượng</th>
                                        <th>Số Dư Sau</th>
                                        <th>Nội Dung</th>
                                        <th>Thời Gian</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_tokens_tx as $tx): 
                                        $amt = (int)($tx['amount'] ?? 0);
                                        $is_plus = $amt > 0;
                                    ?>
                                        <tr>
                                            <td><code style="font-family:'JetBrains Mono', monospace;font-size:12px;color:var(--v3-text-muted)"><?= htmlspecialchars(substr($tx['id'] ?? '', 0, 12)) ?></code></td>
                                            <td>
                                                <span class="v3-badge <?= $is_plus ? 'v3-badge-active' : 'v3-badge-neutral' ?>">
                                                    <?= htmlspecialchars($tx['type'] ?? 'TRANSACTION') ?>
                                                </span>
                                            </td>
                                            <td style="font-weight:700;font-family:'JetBrains Mono', monospace;color:<?= $is_plus ? 'var(--v3-success)' : 'var(--v3-danger)' ?>">
                                                <?= $is_plus ? '+' . number_format($amt) : number_format($amt) ?>
                                            </td>
                                            <td style="font-family:'JetBrains Mono', monospace;color:var(--v3-text-main)">
                                                <?= number_format($tx['balance_after'] ?? 0) ?>
                                            </td>
                                            <td style="font-size:12.5px;color:var(--v3-text-muted)">
                                                <?= htmlspecialchars($tx['description'] ?? '') ?>
                                            </td>
                                            <td style="font-size:12px;color:var(--v3-text-subtle)">
                                                <?= htmlspecialchars($tx['created_at'] ?? '') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 4: CLOUD STORAGE (#tab-cloud-storage)
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-cloud-storage" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                            <span>2TOOLNE Cloud Storage</span>
                        </h1>
                        <p class="v3-module-subtitle">Không gian lưu trữ đám mây tốc độ cao kết nối trực tiếp bộ xử lý AI Desktop.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px">
                        <button type="button" class="v3-btn v3-btn-primary" onclick="openCloudUploadModal()" id="btn-cloud-upload">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                            <span>Tải Tệp Lên</span>
                        </button>
                    </div>
                </div>

                <!-- Sleek Single-Row Toolbar -->
                <div class="v3-card" style="padding:12px 18px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:260px">
                            <!-- Breadcrumbs -->
                            <div id="cloud-breadcrumbs-bar" style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--v3-text-muted)">
                                <span>🏠 Gốc</span>
                            </div>
                        </div>

                        <!-- Center: Search -->
                        <div style="min-width:200px;max-width:320px;flex:1">
                            <input type="text" id="cloud-search-input" class="v3-input" placeholder="Tìm kiếm tệp..." oninput="onCloudSearch(this.value)">
                        </div>

                        <!-- Right Actions -->
                        <div style="display:flex;align-items:center;gap:8px">
                            <!-- Space Selector Dropdown -->
                            <select id="cloud-space-select" class="v3-select" style="width:auto;height:34px;font-size:12px" onchange="onCloudSpaceChanged(this.value)">
                                <?php if (!empty($user_cloud_spaces)): ?>
                                    <?php foreach ($user_cloud_spaces as $sp): ?>
                                        <option value="<?= htmlspecialchars($sp['id']) ?>">
                                            <?= ($sp['owner_type'] === 'TEAM' ? '👥 ' : '👤 ') . htmlspecialchars($sp['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">(Chưa có không gian)</option>
                                <?php endif; ?>
                            </select>

                            <button type="button" class="v3-btn v3-btn-secondary v3-btn-xs" onclick="promptCreateCloudFolder()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                <span>Tạo Thư Mục</span>
                            </button>
                            <button type="button" class="v3-btn v3-btn-ghost v3-btn-xs" onclick="openCloudTrashModal()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                <span>Thùng Rác</span>
                            </button>
                            <button type="button" class="v3-btn v3-btn-secondary v3-btn-xs" onclick="openModal('modal-cloud-buy-quota')" style="color:var(--v3-primary)">
                                + Dung Lượng
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sleek Quota Strip -->
                <div class="v3-card" style="padding:14px 20px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:240px">
                            <span style="font-size:11.5px;font-weight:700;color:var(--v3-text-muted);text-transform:uppercase;letter-spacing:0.04em">DUNG LƯỢNG:</span>
                            <span id="cloud-quota-usage-text" style="font-size:13px;font-weight:700;color:#fff;font-family:'JetBrains Mono', monospace"><?= $space_used_formatted ?></span>
                            <span style="color:var(--v3-text-subtle)">/</span>
                            <span id="cloud-quota-total-text" style="font-size:13px;color:var(--v3-text-muted);font-family:'JetBrains Mono', monospace"><?= $space_quota_formatted ?></span>
                            <span id="cloud-quota-percent" style="font-size:12px;font-weight:700;color:var(--v3-primary)">(<?= $space_pct ?>%)</span>
                            <div style="flex:1;max-width:200px;height:4px;background:rgba(255,255,255,0.06);border-radius:2px;overflow:hidden">
                                <div id="cloud-quota-fill-bar" style="height:100%;background:var(--v3-primary);width:<?= $space_pct ?>%"></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:16px;font-size:12px;color:var(--v3-text-muted)">
                            <div>Còn Trống: <b id="cloud-chip-free" style="color:#10B981">--</b></div>
                            <div>Tổng Số Tệp: <b id="cloud-chip-files" style="color:#fff">--</b></div>
                            <!-- Hidden elements for JS engine compatibility -->
                            <span id="cloud-chip-quota" style="display:none">--</span>
                            <span id="cloud-chip-used" style="display:none">--</span>
                            <span id="cloud-trash-badge" style="display:none">0</span>
                            <span id="cloud-status-text" style="display:none">Đang Hoạt Động</span>
                        </div>
                    </div>
                </div>

                <!-- Modern File Explorer Table -->
                <div class="v3-card" style="padding:0">
                    <div class="v3-table-wrapper" style="border:none">
                        <table class="v3-table" id="cloud-files-table">
                            <thead>
                                <tr>
                                    <th style="width:40%">Tên Tệp / Thư Mục</th>
                                    <th>Kích Thước</th>
                                    <th>Ngày Tải Lên</th>
                                    <th>Định Dạng</th>
                                    <th style="text-align:right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody id="cloud-files-tbody">
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:32px;color:var(--v3-text-muted)">
                                        Đang tải dữ liệu tệp đám mây...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 5: TEAM (#tab-team) — FIRST-CLASS PAGE
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-team" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span>Đội Nhóm (Team Workspace)</span>
                        </h1>
                        <p class="v3-module-subtitle">Cộng tác sản xuất nội dung, phân quyền thành viên và dùng chung kho lưu trữ.</p>
                    </div>
                    <?php if (!$primary_team): ?>
                        <button type="button" class="v3-btn v3-btn-primary" onclick="openModal('modal-cloud-create-team')">
                            + Khởi Tạo Team Mới
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$primary_team): ?>
                    <div class="v3-card">
                        <div class="v3-empty-state">
                            <div class="v3-empty-state-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <div class="v3-empty-state-title">Bạn Chưa Tham Gia Đội Nhóm Nào</div>
                            <div class="v3-empty-state-desc">Khởi tạo đội nhóm để mở rộng slot thành viên, dùng chung bộ nhớ Cloud và phân quyền vai trò chuyên nghiệp.</div>
                            <button type="button" class="v3-btn v3-btn-primary" onclick="openModal('modal-cloud-create-team')">Khởi Tạo Team Cloud (3 Tháng)</button>
                        </div>
                    </div>
                <?php else: 
                    $team_slots = (int)($primary_team['member_slots'] ?? 5);
                    $used_slots = count($user_team_members);
                    $slot_pct = min(100, round(($used_slots / max($team_slots, 1)) * 100));
                ?>
                    <!-- Team Overview Hero Card -->
                    <div class="v3-card v3-card-glow" style="margin-bottom:24px">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
                            <div>
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                                    <span class="v3-badge v3-badge-role-owner">TEAM ACTIVE</span>
                                    <span style="font-size:12px;color:var(--v3-text-muted)">ID: <?= htmlspecialchars($primary_team['id']) ?></span>
                                </div>
                                <div style="font-size:22px;font-weight:700;color:#fff;margin-bottom:4px"><?= htmlspecialchars($primary_team['name']) ?></div>
                                <div style="font-size:13px;color:var(--v3-text-muted)">
                                    Trưởng nhóm: <b style="color:#fff"><?= htmlspecialchars(($primary_team['owner_fullname'] ?? '') ?: (($primary_team['owner_username'] ?? '') ?: 'Trưởng nhóm')) ?></b> &bull; Hạn mức thành viên: <b style="color:var(--v3-primary)"><?= $used_slots ?> / <?= $team_slots ?> Slots</b>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px">
                                <button type="button" class="v3-btn v3-btn-secondary v3-btn-sm" onclick="openModal('modal-cloud-team-members')">
                                    Mua Thêm Slot
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Invite Member Bar -->
                    <div class="v3-card" style="margin-bottom:24px">
                        <div class="v3-card-header">
                            <div class="v3-card-title">Mời Thành Viên Mới Vào Nhóm</div>
                        </div>
                        <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:0">
                            <input type="hidden" name="action" value="send_team_invite">
                            <input type="hidden" name="team_id" value="<?= htmlspecialchars($primary_team['id']) ?>">
                            <div style="flex:1;min-width:240px">
                                <input type="text" name="target_username" class="v3-input" placeholder="Nhập tên đăng nhập (username) thành viên..." required>
                            </div>
                            <button type="submit" class="v3-btn v3-btn-primary">
                                Gửi Lời Mời
                            </button>
                        </form>
                    </div>

                    <!-- Members List Table -->
                    <div class="v3-card">
                        <div class="v3-card-header">
                            <div class="v3-card-title">Danh Sách Thành Viên (<?= count($user_team_members) ?>)</div>
                        </div>
                        <div class="v3-table-wrapper">
                            <table class="v3-table">
                                <thead>
                                    <tr>
                                        <th>Thành Viên</th>
                                        <th>Vai Trò</th>
                                        <th>Số Điện Thoại</th>
                                        <th>Ngày Tham Gia</th>
                                        <th style="text-align:right">Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_team_members as $m): 
                                        $m_role = strtoupper($m['role'] ?? 'MEMBER');
                                        $m_uname = $m['username'] ?? 'user';
                                        $m_name = ($m['fullname'] ?? '') ?: $m_uname;
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <div class="v3-user-avatar" style="width:28px;height:28px;font-size:11px">
                                                        <?= strtoupper(substr($m_uname, 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight:600;color:#fff"><?= htmlspecialchars($m_name) ?></div>
                                                        <div style="font-size:11.5px;color:var(--v3-text-muted)">@<?= htmlspecialchars($m_uname) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($m_role === 'OWNER'): ?>
                                                    <span class="v3-badge v3-badge-role-owner">Trưởng Nhóm (Owner)</span>
                                                <?php elseif ($m_role === 'ADMIN'): ?>
                                                    <span class="v3-badge v3-badge-role-admin">Quản Trị (Admin)</span>
                                                <?php else: ?>
                                                    <span class="v3-badge v3-badge-role-member">Thành Viên (Member)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:12.5px;color:var(--v3-text-muted)">
                                                <?= htmlspecialchars($m['phone'] ?: '—') ?>
                                            </td>
                                            <td style="font-size:12px;color:var(--v3-text-subtle)">
                                                <?= htmlspecialchars($m['joined_at'] ?? '—') ?>
                                            </td>
                                            <td style="text-align:right">
                                                <?php if ($m_role !== 'OWNER' && $primary_team['my_role'] === 'OWNER'): ?>
                                                    <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Bạn có chắc chắn muốn xóa thành viên này khỏi nhóm?')">
                                                        <input type="hidden" name="action" value="user_remove_team_member">
                                                        <input type="hidden" name="team_id" value="<?= htmlspecialchars($primary_team['id']) ?>">
                                                        <input type="hidden" name="target_uid" value="<?= htmlspecialchars($m['user_id']) ?>">
                                                        <button type="submit" class="v3-btn v3-btn-danger v3-btn-xs">Xóa</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="font-size:12px;color:var(--v3-text-subtle)">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 6: AI CONNECTION (#tab-ai-connection) — DEVELOPER GATEWAY
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-ai-connection" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="M2 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M12 22v-4"/><path d="m19.07 19.07-2.83-2.83"/><path d="M22 12h-4"/><path d="m19.07 4.93-2.83 2.83"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>AI Connection Gateway</span>
                        </h1>
                        <p class="v3-module-subtitle">Kết nối an toàn ứng dụng máy tính (Desktop CLI, SDK, Pipeline) với không gian 2TOOLNE qua API Key.</p>
                    </div>
                    <button type="button" class="v3-btn v3-btn-primary" onclick="openCreateAiKeyModal()">
                        + Tạo AI Key Mới
                    </button>
                </div>

                <!-- AI Gateway Intro Card -->
                <div class="v3-card v3-card-glow" style="margin-bottom:24px">
                    <div style="display:flex;align-items:flex-start;gap:14px">
                        <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,122,0,0.12);border:1px solid rgba(255,122,0,0.25);display:flex;align-items:center;justify-content:center;color:var(--v3-primary);flex-shrink:0">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        </div>
                        <div>
                            <div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:4px">Bảo Mật Xác Thực Một Chiều (One-Way Hash)</div>
                            <div style="font-size:13px;color:var(--v3-text-muted);line-height:1.5">
                                Mỗi AI Access Key được mã hóa chuẩn mật mã và liên kết trực tiếp với một không gian Cloud (Cá nhân hoặc Team). Khóa bí mật chỉ hiển thị duy nhất một lần khi khởi tạo.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI Keys Table -->
                <div class="v3-card">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Danh Sách AI Access Keys (<?= count($user_ai_keys) ?>)</div>
                    </div>
                    <?php if (empty($user_ai_keys)): ?>
                        <div class="v3-empty-state">
                            <div class="v3-empty-state-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="M2 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M12 22v-4"/><path d="m19.07 19.07-2.83-2.83"/><path d="M22 12h-4"/><path d="m19.07 4.93-2.83 2.83"/><circle cx="12" cy="12" r="3"/></svg>
                            </div>
                            <div class="v3-empty-state-title">Chưa Có AI Access Key Nào</div>
                            <div class="v3-empty-state-desc">Tạo API Key đầu tiên để liên kết phần mềm desktop hoặc script tự động hóa với tài khoản 2TOOLNE của bạn.</div>
                            <button type="button" class="v3-btn v3-btn-primary" onclick="openCreateAiKeyModal()">Tạo AI Key Mới</button>
                        </div>
                    <?php else: ?>
                        <div class="v3-table-wrapper">
                            <table class="v3-table">
                                <thead>
                                    <tr>
                                        <th>Tên Gợi Nhớ (Alias)</th>
                                        <th>Tiền Tố Khóa (Prefix)</th>
                                        <th>Không Gian Liên Kết</th>
                                        <th>Ngày Tạo</th>
                                        <th>Sử Dụng Gần Nhất</th>
                                        <th>Trạng Thái</th>
                                        <th style="text-align:right">Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_ai_keys as $ak): 
                                        $is_revoked = !empty($ak['revoked_at']);
                                    ?>
                                        <tr>
                                            <td style="font-weight:600;color:#fff"><?= htmlspecialchars($ak['display_name']) ?></td>
                                            <td>
                                                <code style="font-family:'JetBrains Mono', monospace;font-size:12px;color:var(--v3-primary)">
                                                    <?= htmlspecialchars($ak['key_prefix']) ?>_••••••••
                                                </code>
                                            </td>
                                            <td style="font-size:12.5px;color:var(--v3-text-muted)">
                                                <?= htmlspecialchars($ak['workspace_name'] ?: ($ak['workspace_type'] === 'TEAM' ? 'Team Space' : 'Personal Space')) ?>
                                            </td>
                                            <td style="font-size:12px;color:var(--v3-text-subtle)">
                                                <?= htmlspecialchars(date('d/m/Y H:i', strtotime($ak['created_at']))) ?>
                                            </td>
                                            <td style="font-size:12px;color:var(--v3-text-subtle)">
                                                <?= !empty($ak['last_used_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($ak['last_used_at']))) : 'Chưa sử dụng' ?>
                                            </td>
                                            <td>
                                                <span class="v3-badge <?= $is_revoked ? 'v3-badge-expired' : 'v3-badge-active' ?>">
                                                    <?= $is_revoked ? 'ĐÃ THU HỒI' : 'HOẠT ĐỘNG' ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right">
                                                <?php if (!$is_revoked): ?>
                                                    <button type="button" class="v3-btn v3-btn-danger v3-btn-xs" onclick="revokeAiKey('<?= htmlspecialchars($ak['id']) ?>')">
                                                        Thu Hồi
                                                    </button>
                                                <?php else: ?>
                                                    <span style="font-size:11.5px;color:var(--v3-text-subtle)">Đã vô hiệu</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 7: DOWNLOADS (#tab-downloads) — ENTITLEMENT GATED
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-downloads" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span>Tải Phần Mềm Chính Thức 2026</span>
                        </h1>
                        <p class="v3-module-subtitle">Bộ cài đặt bảo mật cao, xác thực bản quyền hai lớp và quét mã độc tự động.</p>
                    </div>
                </div>

                <!-- Entitlement Eligibility Banner -->
                <div class="v3-card <?= $has_active_entitlement ? 'v3-card-glow' : '' ?>" style="margin-bottom:24px;border-color:<?= $has_active_entitlement ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)' ?>">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:36px;height:36px;border-radius:9px;background:<?= $has_active_entitlement ? 'rgba(16,185,129,0.15)' : 'rgba(245,158,11,0.15)' ?>;display:flex;align-items:center;justify-content:center;color:<?= $has_active_entitlement ? '#10B981' : '#F59E0B' ?>">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div>
                                <div style="font-size:14.5px;font-weight:700;color:#fff">
                                    <?= $has_active_entitlement ? 'Xác Thực Bản Quyền Hợp Lệ — Bạn Đủ Điều Kiện Tải Xuống' : 'Yêu Cầu Bản Quyền Hoặc Gói Dùng Thử Để Tải Phần Mềm' ?>
                                </div>
                                <div style="font-size:12.5px;color:var(--v3-text-muted)">
                                    <?= $has_active_entitlement ? 'Đặc quyền tải các bản cài đặt chính thức tốc độ cao, token tạm thời ký số an toàn.' : 'Bạn chưa có bản quyền đang hoạt động. Hãy đăng ký dùng thử 3 ngày hoặc mua bản quyền để mở khóa tải về.' ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!$has_active_entitlement): ?>
                            <button type="button" class="v3-btn v3-btn-primary" onclick="switchMainTab('tab-buy-key')">
                                Mua Bản Quyền Ngay
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Product Showcase Grid -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px">
                    <!-- Flagship 1: AutoEdit for CapCut -->
                    <div class="v3-card v3-card-glow">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                            <span class="v3-badge v3-badge-brand">CHÍNH THỨC 2026</span>
                            <span class="v3-badge v3-badge-active">v2.0.1 Stable</span>
                        </div>
                        <h3 style="font-size:18px;font-weight:700;color:#fff;margin:0 0 8px">2TOOLNE AutoEdit for CapCut</h3>
                        <p style="font-size:13px;color:var(--v3-text-muted);margin:0 0 16px;line-height:1.5">
                            Phần mềm Desktop độc lập điều phối và tự động hóa biên tập CapCut Desktop: Tạo Timeline, Keyframe Scale/Position mượt mà, Rãnh âm thanh &amp; Phụ đề XML tự động.
                        </p>
                        <div style="padding:12px;background:var(--v3-surface-2);border-radius:var(--v3-radius-sm);margin-bottom:16px;font-size:12px;color:var(--v3-text-muted)">
                            <div>Hệ điều hành: <b>Windows 10/11 64-bit</b> &bull; Phiên bản: <b>v2.0.1</b> &bull; Dung lượng: <b>523 MB</b></div>
                            <div style="margin-top:6px;color:#ff9e42">✨ <b>Tương thích chính xác CapCut 9.3.0.3970</b> &bull; Tự động phân giải com.lveditor.draft</div>
                        </div>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button type="button" class="v3-btn v3-btn-primary" style="flex:1" onclick="requestSecureDownload('AUTOEDIT', 'windows-x64')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                <span>Tải Cho Windows (.exe)</span>
                            </button>
                            <button type="button" class="v3-btn v3-btn-secondary" onclick="requestSecureDownload('AUTOEDIT', 'macos')">
                                macOS (.dmg)
                            </button>
                        </div>
                    </div>

                    <!-- Flagship 2: Upscale 4K -->
                    <div class="v3-card">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                            <span class="v3-badge v3-badge-brand">AI ON-DEVICE</span>
                            <span class="v3-badge v3-badge-active">v1.1.2 Stable</span>
                        </div>
                        <h3 style="font-size:18px;font-weight:700;color:#fff;margin:0 0 8px">2TOOLNE Upscale 4K</h3>
                        <p style="font-size:13px;color:var(--v3-text-muted);margin:0 0 16px;line-height:1.5">
                            Ứng dụng AI phục chế và phóng to ảnh 2K/4K siêu nét on-device (Vulkan, DirectML, Apple Silicon Metal). Tiêu thụ theo số ảnh xuất hoàn tất.
                        </p>
                        <div style="padding:12px;background:var(--v3-surface-2);border-radius:var(--v3-radius-sm);margin-bottom:16px;font-size:12px;color:var(--v3-text-muted)">
                            <div>Hệ điều hành: <b>Windows &amp; macOS</b> &bull; Dung lượng: <b>149 MB</b></div>
                            <div style="margin-top:4px">Tương thích: <b>NVIDIA RTX, Intel Arc, Apple M1/M2/M3/M4</b></div>
                        </div>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button type="button" class="v3-btn v3-btn-primary" style="flex:1" onclick="requestSecureDownload('UPSCALE', 'windows-x64')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                <span>Tải Cho Windows (.exe)</span>
                            </button>
                            <button type="button" class="v3-btn v3-btn-secondary" onclick="requestSecureDownload('UPSCALE', 'macos')">
                                macOS (.dmg)
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 MODULE 8: SETTINGS (#tab-settings)
                 ═══════════════════════════════════════════════════════════════ -->
            <div id="tab-settings" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            <span>Cài Đặt Tài Khoản &amp; Giao Diện</span>
                        </h1>
                        <p class="v3-module-subtitle">Tùy biến giao diện, thông tin hồ sơ và bảo mật tài khoản cá nhân.</p>
                    </div>
                </div>

                <!-- Appearance Section -->
                <div class="v3-card" style="margin-bottom:24px">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Giao Diện Không Gian Làm Việc (Appearance)</div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px">
                        <!-- Dark Theme Card (Default) -->
                        <div class="v3-card v3-card-glow" style="padding:16px;text-align:center;cursor:pointer;background:#0F1012">
                            <div style="width:100%;height:60px;background:#090A0C;border-radius:6px;border:1px solid rgba(255,255,255,0.08);margin-bottom:10px;display:flex;align-items:center;justify-content:center">
                                <div style="width:30px;height:6px;background:var(--v3-primary);border-radius:3px"></div>
                            </div>
                            <div style="font-weight:700;color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px">
                                <span>Dark V3 (Mặc định)</span>
                                <span style="color:var(--v3-primary)">✓</span>
                            </div>
                            <div style="font-size:11.5px;color:var(--v3-text-muted);margin-top:4px">Tối ưu cho studio &amp; sáng tạo</div>
                        </div>

                        <!-- Light Theme Card -->
                        <div class="v3-card" style="padding:16px;text-align:center;cursor:pointer;background:#202228;opacity:0.7">
                            <div style="width:100%;height:60px;background:#F6F4F1;border-radius:6px;border:1px solid rgba(255,255,255,0.08);margin-bottom:10px;display:flex;align-items:center;justify-content:center">
                                <div style="width:30px;height:6px;background:#FF7A00;border-radius:3px"></div>
                            </div>
                            <div style="font-weight:600;color:#fff;font-size:13px">Sáng (Light Mode)</div>
                            <div style="font-size:11.5px;color:var(--v3-text-muted);margin-top:4px">Độ tương phản cao ban ngày</div>
                        </div>

                        <!-- System Auto Card -->
                        <div class="v3-card" style="padding:16px;text-align:center;cursor:pointer;background:#202228;opacity:0.7">
                            <div style="width:100%;height:60px;background:linear-gradient(90deg, #090A0C 50%, #F6F4F1 50%);border-radius:6px;border:1px solid rgba(255,255,255,0.08);margin-bottom:10px"></div>
                            <div style="font-weight:600;color:#fff;font-size:13px">Hệ Thống (Auto)</div>
                            <div style="font-size:11.5px;color:var(--v3-text-muted);margin-top:4px">Tự động đồng bộ hệ điều hành</div>
                        </div>
                    </div>
                </div>

                <!-- Profile Section -->
                <div class="v3-card" style="margin-bottom:24px">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Hồ Sơ Tài Khoản</div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:20px;font-size:13px">
                        <div>
                            <span style="font-size:11px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase">TÊN ĐĂNG NHẬP</span>
                            <div style="font-size:15px;font-weight:700;color:var(--v3-primary);margin-top:4px">@<?= htmlspecialchars($user_info['username']) ?></div>
                        </div>
                        <div>
                            <span style="font-size:11px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase">HỌ VÀ TÊN</span>
                            <div style="font-size:15px;font-weight:600;color:#fff;margin-top:4px"><?= htmlspecialchars($user_info['fullname'] ?: '(Chưa cập nhật)') ?></div>
                        </div>
                        <div>
                            <span style="font-size:11px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase">SỐ ĐIỆN THOẠI / ZALO</span>
                            <div style="font-size:15px;font-weight:600;color:#fff;margin-top:4px"><?= htmlspecialchars($user_info['phone'] ?: '(Chưa cập nhật)') ?></div>
                        </div>
                        <div>
                            <span style="font-size:11px;font-weight:600;color:var(--v3-text-muted);text-transform:uppercase">NGÀY THAM GIA</span>
                            <div style="font-size:14px;color:var(--v3-text-muted);margin-top:4px"><?= htmlspecialchars($user_info['created_at'] ?? '2026') ?></div>
                        </div>
                    </div>
                </div>

                <!-- Security / Password Section -->
                <div class="v3-card">
                    <div class="v3-card-header">
                        <div class="v3-card-title">Đổi Mật Khẩu Đăng Nhập</div>
                    </div>
                    <form method="POST" style="max-width:440px">
                        <input type="hidden" name="action" value="change_password">
                        <div class="v3-form-group">
                            <label class="v3-form-label">MẬT KHẨU HIỆN TẠI</label>
                            <input type="password" name="old_password" class="v3-input" required>
                        </div>
                        <div class="v3-form-group">
                            <label class="v3-form-label">MẬT KHẨU MỚI (TỪ 6 KÝ TỰ)</label>
                            <input type="password" name="new_password" class="v3-input" required minlength="6">
                        </div>
                        <div class="v3-form-group">
                            <label class="v3-form-label">XÁC NHẬN MẬT KHẨU MỚI</label>
                            <input type="password" name="confirm_password" class="v3-input" required minlength="6">
                        </div>
                        <button type="submit" class="v3-btn v3-btn-primary">Lưu Mật Khẩu Mới</button>
                    </form>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 HELPER TABS (PRESERVED FOR COMPATIBILITY)
                 ═══════════════════════════════════════════════════════════════ -->
            <!-- Tab: Buy Key -->
            <div id="tab-buy-key" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">Mua Gói Bản Quyền Phần Mềm</h1>
                        <p class="v3-module-subtitle">Kích hoạt tự động ngay sau khi thanh toán qua mã SePay QR.</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:20px">
                    <div class="v3-card v3-card-glow" style="text-align:center">
                        <span class="v3-badge v3-badge-brand" style="margin-bottom:12px">HOT</span>
                        <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px">AutoEdit CapCut — Gói 1 Tháng</div>
                        <div style="font-size:26px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:8px">1.000.000đ</div>
                        <div style="font-size:12.5px;color:var(--v3-text-muted);margin-bottom:16px">Sử dụng đầy đủ tính năng 30 ngày</div>
                        <button type="button" class="v3-btn v3-btn-primary" style="width:100%" onclick="openQrPayment('Gói 1 Tháng', '1.000.000đ', 30, 'VIP', '2TOOLNE')">
                            Mua Ngay Qua QR
                        </button>
                    </div>
                    <div class="v3-card" style="text-align:center">
                        <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px">AutoEdit CapCut — Gói 3 Tháng</div>
                        <div style="font-size:26px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:8px">2.500.000đ</div>
                        <div style="font-size:12.5px;color:var(--v3-text-muted);margin-bottom:16px">Tiết kiệm 500.000đ</div>
                        <button type="button" class="v3-btn v3-btn-secondary" style="width:100%" onclick="openQrPayment('Gói 3 Tháng', '2.500.000đ', 90, 'VIP', '2TOOLNE')">
                            Mua Ngay Qua QR
                        </button>
                    </div>
                    <div class="v3-card v3-card-glow" style="text-align:center">
                        <span class="v3-badge v3-badge-active" style="margin-bottom:12px">KHUYÊN DÙNG</span>
                        <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px">AutoEdit CapCut — Gói 1 Năm</div>
                        <div style="font-size:26px;font-weight:800;color:var(--v3-primary);font-family:'JetBrains Mono', monospace;margin-bottom:8px">7.000.000đ</div>
                        <div style="font-size:12.5px;color:#10B981;margin-bottom:16px">Tiết kiệm 5.000.000đ / năm</div>
                        <button type="button" class="v3-btn v3-btn-primary" style="width:100%" onclick="openQrPayment('Gói 1 Năm', '7.000.000đ', 365, 'VIP', '2TOOLNE')">
                            Mua Ngay Qua QR
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab: Orders History -->
            <div id="tab-orders-history" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">Lịch Sử Giao Dịch &amp; Đơn Hàng</h1>
                        <p class="v3-module-subtitle">Theo dõi trạng thái duyệt đơn và xuất hóa đơn nạp/mua.</p>
                    </div>
                </div>
                <div class="v3-card">
                    <?php if (empty($user_orders)): ?>
                        <div class="v3-empty-state">
                            <div class="v3-empty-state-title">Chưa Có Đơn Hàng Nào</div>
                            <div class="v3-empty-state-desc">Bạn chưa tạo đơn hàng nào trên hệ thống.</div>
                        </div>
                    <?php else: ?>
                        <div class="v3-table-wrapper">
                            <table class="v3-table">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn</th>
                                        <th>Gói Dịch Vụ</th>
                                        <th>Giá Tiền</th>
                                        <th>Thời Gian</th>
                                        <th>Trạng Thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_orders as $ord): 
                                        $o_st = $ord['status'] ?? 'pending';
                                    ?>
                                        <tr>
                                            <td><code style="color:var(--v3-primary)"><?= htmlspecialchars($ord['order_id'] ?? '') ?></code></td>
                                            <td style="font-weight:600;color:#fff"><?= htmlspecialchars($ord['package_name'] ?? '') ?></td>
                                            <td style="font-family:'JetBrains Mono', monospace"><?= htmlspecialchars($ord['package_price'] ?? '') ?></td>
                                            <td style="font-size:12px;color:var(--v3-text-muted)"><?= htmlspecialchars($ord['created_at'] ?? '') ?></td>
                                            <td>
                                                <span class="v3-badge <?= $o_st === 'approved' ? 'v3-badge-active' : ($o_st === 'rejected' ? 'v3-badge-expired' : 'v3-badge-trial') ?>">
                                                    <?= $o_st === 'approved' ? 'HOÀN TẤT' : ($o_st === 'rejected' ? 'ĐÃ HỦY' : 'CHỜ DUYỆT') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: Feature Requests -->
            <div id="tab-features-view" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">Góp Ý Tính Năng</h1>
                        <p class="v3-module-subtitle">Gửi đề xuất tính năng trực tiếp tới đội ngũ kỹ thuật 2TOOLNE.</p>
                    </div>
                </div>
                <div class="v3-card">
                    <form method="POST" style="max-width:540px">
                        <input type="hidden" name="action" value="add_feature">
                        <div class="v3-form-group">
                            <label class="v3-form-label">TIÊU ĐỀ TÍNH NĂNG ĐỀ XUẤT</label>
                            <input type="text" name="title" class="v3-input" required placeholder="VD: Bổ sung rãnh keyframe easing curve...">
                        </div>
                        <div class="v3-form-group">
                            <label class="v3-form-label">MÔ TẢ CHI TIẾT</label>
                            <textarea name="description" class="v3-input" style="height:100px;padding:10px" required placeholder="Mô tả cụ thể cách tính năng hoạt động..."></textarea>
                        </div>
                        <button type="submit" class="v3-btn v3-btn-primary">Gửi Đề Xuất</button>
                    </form>
                </div>
            </div>

            <!-- Tab: Bug Reports -->
            <div id="tab-bugs-view" class="tab-pane" style="display:none">
                <div class="v3-module-header">
                    <div>
                        <h1 class="v3-module-title">Báo Lỗi Phần Mềm</h1>
                        <p class="v3-module-subtitle">Báo cáo lỗi kỹ thuật để được đội ngũ hỗ trợ xử lý nhanh chóng.</p>
                    </div>
                </div>
                <div class="v3-card">
                    <form method="POST" style="max-width:540px">
                        <input type="hidden" name="action" value="add_bug">
                        <div class="v3-form-group">
                            <label class="v3-form-label">TIÊU ĐỀ LỖI GẶP PHẢI</label>
                            <input type="text" name="title" class="v3-input" required placeholder="VD: Lỗi khi đồng bộ timeline CapCut...">
                        </div>
                        <div class="v3-form-group">
                            <label class="v3-form-label">MÃ LỖI (NẾU CÓ)</label>
                            <input type="text" name="error_code" class="v3-input" placeholder="VD: ERR_EXPORT_KEYFRAME">
                        </div>
                        <div class="v3-form-group">
                            <label class="v3-form-label">MÔ TẢ CHI TIẾT CÁC BƯỚC GẶP LỖI</label>
                            <textarea name="description" class="v3-input" style="height:100px;padding:10px" required placeholder="Mô tả các thao tác dẫn tới phát sinh lỗi..."></textarea>
                        </div>
                        <button type="submit" class="v3-btn v3-btn-danger">Gửi Báo Lỗi</button>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ═══ V3 MODALS ═══ -->
<!-- Create AI Key Modal -->
<div id="modal-v3-create-ai-key" class="v3-modal-overlay" style="display:none">
    <div class="v3-modal-card">
        <div class="v3-modal-header">
            <h3 class="v3-modal-title">Tạo AI Access Key Mới</h3>
            <button type="button" class="v3-btn v3-btn-ghost v3-btn-xs" onclick="closeV3Modal('modal-v3-create-ai-key')">&times;</button>
        </div>
        <div class="v3-modal-body">
            <div class="v3-form-group">
                <label class="v3-form-label">TÊN GỢI NHỚ (ALIAS)</label>
                <input type="text" id="v3-new-aikey-name" class="v3-input" placeholder="VD: AutoEdit Production CLI" required>
            </div>
            <div class="v3-form-group">
                <label class="v3-form-label">LIÊN KẾT KHÔNG GIAN LÀM VIỆC (WORKSPACE)</label>
                <select id="v3-new-aikey-space" class="v3-select">
                    <?php if (!empty($user_cloud_spaces)): ?>
                        <?php foreach ($user_cloud_spaces as $sp): ?>
                            <option value="<?= htmlspecialchars($sp['id']) ?>">
                                <?= ($sp['owner_type'] === 'TEAM' ? '👥 ' : '👤 ') . htmlspecialchars($sp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div style="font-size:12px;color:var(--v3-text-muted);line-height:1.4">
                Khóa này sẽ cấp quyền đọc và ghi dữ liệu tệp trong thư mục "AI Inputs" thuộc không gian đã chọn.
            </div>
        </div>
        <div class="v3-modal-footer">
            <button type="button" class="v3-btn v3-btn-outline" onclick="closeV3Modal('modal-v3-create-ai-key')">Hủy</button>
            <button type="button" class="v3-btn v3-btn-primary" onclick="submitCreateAiKey()">Khởi Tạo Khóa</button>
        </div>
    </div>
</div>

<!-- One-Time Secret Revealed Modal -->
<div id="modal-v3-show-ai-secret" class="v3-modal-overlay" style="display:none">
    <div class="v3-modal-card">
        <div class="v3-modal-header">
            <h3 class="v3-modal-title" style="color:#10B981">🎉 Khởi Tạo AI Key Thành Công!</h3>
            <button type="button" class="v3-btn v3-btn-ghost v3-btn-xs" onclick="closeV3Modal('modal-v3-show-ai-secret')">&times;</button>
        </div>
        <div class="v3-modal-body">
            <div class="v3-card" style="border-color:rgba(245,158,11,0.3);background:rgba(245,158,11,0.06);padding:12px;margin-bottom:16px;font-size:12.5px;color:#F59E0B">
                ⚠️ <b>LƯU Ý BẢO MẬT:</b> Khóa bí mật chỉ hiển thị <b>MỘT LẦN DUY NHẤT</b>. Hãy sao chép và lưu vào file an toàn ngay bây giờ.
            </div>
            <div class="v3-form-group">
                <label class="v3-form-label">SECRET ACCESS KEY</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="v3-revealed-secret-input" class="v3-input" readonly style="font-family:'JetBrains Mono', monospace;color:var(--v3-primary);font-size:12px;background:#0C0D0F">
                    <button type="button" class="v3-btn v3-btn-primary" onclick="copyRevealedAiSecret()">
                        Sao Chép
                    </button>
                </div>
            </div>
        </div>
        <div class="v3-modal-footer">
            <button type="button" class="v3-btn v3-btn-secondary" onclick="closeV3Modal('modal-v3-show-ai-secret'); location.reload();">
                Đã Lưu Xong &amp; Đóng
            </button>
        </div>
    </div>
</div>
