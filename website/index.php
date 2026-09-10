<?php
session_start();
require_once __DIR__ . '/storage/db.php';

// App Auth Flow Check
if (isset($_GET['cancel_auth'])) {
    unset($_SESSION['pending_app_auth']);
    unset($_SESSION['app_auth_approved']);
    header('Location: index.php');
    exit;
}
if (isset($_GET['app_auth'])) {
    $existing = $_SESSION['pending_app_auth'] ?? [];
    $new_sess = trim($_GET['session'] ?? '');
    $new_chal = trim($_GET['challenge'] ?? '');
    $new_port = intval($_GET['port'] ?? 0);
    $new_state = trim($_GET['state'] ?? '');

    $_SESSION['pending_app_auth'] = [
        'session'   => (!empty($new_sess)) ? $new_sess : ($existing['session'] ?? ('auth_' . bin2hex(random_bytes(16)))),
        'challenge' => (!empty($new_chal)) ? $new_chal : ($existing['challenge'] ?? ''),
        'port'      => ($new_port > 0) ? $new_port : ($existing['port'] ?? 0),
        'state'     => (!empty($new_state)) ? $new_state : ($existing['state'] ?? ''),
    ];
}
$pending_app_auth = (!empty($_SESSION['pending_app_auth']['session'])) ? $_SESSION['pending_app_auth'] : null;

// Check GET logout
if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    session_destroy();
    header("Location: index.php?flash_out=1");
    exit;
}

// ── AJAX ENDPOINT: Poll Pending Orders ──────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll_orders') {
    header('Content-Type: application/json');
    $u = $_SESSION['user'] ?? '';
    if (!$u) { echo json_encode(['pending_count' => 0]); exit; }
    $all_orders = db_get_orders();
    $cnt = 0;
    foreach ($all_orders as $ord) {
        if (($ord['user'] ?? '') === $u && ($ord['status'] ?? '') === 'pending') {
            $cnt++;
        }
    }
    echo json_encode(['pending_count' => $cnt]);
    exit;
}

// ── AJAX ENDPOINT: Get My Token Balance ─────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_my_balance') {
    header('Content-Type: application/json; charset=utf-8');
    $u = $_SESSION['user'] ?? '';
    if (!$u) { echo json_encode(['status' => 'error', 'balance' => 0]); exit; }
    $w = db_get_user_wallet($u);
    $bal = (int)($w['balance'] ?? 0);
    echo json_encode([
        'status' => 'success',
        'balance' => $bal,
        'formatted' => number_format($bal, 0, ',', '.'),
        'credit_mode' => $w['credit_mode'] ?? 'METERED',
        'plan' => $w['plan'] ?? 'free'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX ENDPOINT: Mark Broadcast Notice Read ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_notice_read') {
    $nid = trim($_GET['id'] ?? '');
    if ($nid) {
        $cur_cfg = db_get_system_config();
        if (!empty($cur_cfg['broadcast_notice']) && ($cur_cfg['broadcast_notice']['id'] ?? '') === $nid) {
            $cur_cfg['broadcast_notice']['read_count'] = intval($cur_cfg['broadcast_notice']['read_count'] ?? 0) + 1;
            db_save_system_config($cur_cfg);
        }
        setcookie("read_notice_{$nid}", "1", time() + 86400 * 60, "/");
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX ENDPOINT: Get Team Details & Members ───────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_team_details') {
    header('Content-Type: application/json; charset=utf-8');
    $team_id = trim($_GET['team_id'] ?? '');
    $u = $_SESSION['user'] ?? '';
    $u_info = $u ? db_get_user($u) : null;
    if (!$u_info || empty($u_info['id']) || empty($team_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng đăng nhập!']); exit;
    }
    $my_role = db_get_team_user_role($team_id, $u_info['id']);
    if (!$my_role && !in_array($u_info['role'] ?? '', ['admin', 'super_admin'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Bạn không phải là thành viên trong nhóm này!']); exit;
    }
    $team = db_get_team($team_id);
    $members = db_get_team_members($team_id);
    echo json_encode([
        'status'  => 'success',
        'team'    => $team,
        'members' => $members,
        'my_role' => $my_role ?: 'ADMIN',
        'current_user_id' => $u_info['id']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load databases from MySQL
$users_db    = db_get_users();
$licenses_db = db_get_licenses();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();

// Current User State
$current_user = $_SESSION['user'] ?? '';
$user_info = $current_user ? db_get_user($current_user) : null;
$user_licenses = $current_user ? array_filter($licenses_db, fn($l) => strtolower($l['owner_user'] ?? '') === strtolower($current_user)) : [];
$user_wallet = $current_user ? db_get_user_wallet($current_user) : ['balance' => 0, 'reserved' => 0, 'credit_mode' => 'METERED', 'plan' => 'free'];
$user_orders = $current_user ? array_filter($orders_db, fn($o) => strtolower($o['user'] ?? ($o['username'] ?? '')) === strtolower($current_user)) : [];
$user_pending_orders = array_filter($user_orders, fn($o) => ($o['status'] ?? '') === 'pending');
$user_tokens_tx = $current_user ? db_get_user_token_transactions($current_user, 30) : [];
$token_packages = db_get_token_packages();
$client_ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$user_cloud_spaces = ($user_info && !empty($user_info['id'])) ? db_get_cloud_spaces_for_user($user_info['id']) : [];
$user_pending_invites = ($user_info && !empty($user_info['id'])) ? db_get_user_pending_invites($user_info['id']) : [];

// ── PRG Flash Messages (Post-Redirect-Get) ──────────────────────────
$msg_success = '';
$msg_error   = '';
if (isset($_SESSION['flash_success'])) { $msg_success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $msg_error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']);   }

if (isset($_GET['payment_status'])) {
    $p_st = $_GET['payment_status'];
    $p_ord = htmlspecialchars($_GET['order_id'] ?? '');
    $order_info = $p_ord ? db_get_order($p_ord) : null;
    $is_token_order = $order_info && (($order_info['product'] ?? '') === 'TOKEN_WALLET' || stripos($order_info['package_name'] ?? '', 'Token') !== false);

    if ($p_st === 'success') {
        if ($is_token_order) {
            $msg_success = "🎉 <b>Thanh toán thành công!</b> Đơn hàng <b>{$p_ord}</b> đã hoàn tất. Số <b>lượt phóng to ảnh AI (Token)</b> đã được cộng trực tiếp vào ví tài khoản của bạn!";
        } elseif ($order_info) {
            $msg_success = "🎉 <b>Thanh toán thành công!</b> Đơn hàng <b>{$p_ord}</b> đã hoàn tất. <b>Bản quyền phần mềm (License Key)</b> của bạn đã được kích hoạt thành công! Quý khách vui lòng kiểm tra tab <b>Bản Quyền Của Tôi</b>.";
        } else {
            $msg_success = "🎉 <b>Thanh toán thành công!</b> Đơn hàng <b>{$p_ord}</b> đã được ghi nhận. Hệ thống đang tiến hành kích hoạt dịch vụ cho bạn trong giây lát!";
        }
    } elseif ($p_st === 'cancel') {
        $msg_error = "Bạn đã hủy giao dịch thanh toán cho đơn hàng <b>{$p_ord}</b>.";
    } elseif ($p_st === 'error') {
        $msg_error = "Giao dịch thanh toán cho đơn hàng <b>{$p_ord}</b> không thành công hoặc đã bị gián đoạn. Vui lòng thử lại hoặc liên hệ hỗ trợ.";
    }
}

function flash_redirect($type, $msg, $tab = '') {
    $is_ajax = !empty($_POST['ajax']) 
        || !empty($_GET['ajax'])
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $type === 'success' ? 'success' : 'error',
            'message' => strip_tags($msg),
            'html_message' => $msg,
            'tab' => $tab
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION["flash_{$type}"] = $msg;
    $url = 'index.php' . ($tab ? "?tab={$tab}" : '');
    header("Location: {$url}");
    exit;
}

// Check POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // 1. REGISTER
    if ($act === 'register') {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $p = $_POST['password'] ?? '';
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($u) || empty($p)) {
            $msg_error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!';
        } elseif (!preg_match('/^[a-z0-9_]{3,24}$/', $u)) {
            $msg_error = 'Tên đăng nhập từ 3-24 ký tự (chữ thường, số, dấu gạch dưới)!';
        } elseif (db_get_user($u)) {
            $msg_error = 'Tên đăng nhập này đã có người sử dụng!';
        } else {
            db_create_user($u, $fullname, $phone, password_hash($p, PASSWORD_DEFAULT), $client_ip);
            
            $got_trial = false;
            if (!db_is_ip_trial_claimed($client_ip, 'SLIDESHOW')) {
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $trial_key = "2TAMNE-TRIAL-{$rnd}";
                db_create_license($trial_key, 'SLIDESHOW', 'TRIAL', 3, $u, "Tặng tự động khi đăng ký (3 Ngày) - IP {$client_ip}");
                db_claim_ip_trial($client_ip, $u, 'SLIDESHOW');
                $got_trial = true;
            }

            $_SESSION['user'] = $u;
            if (!empty($_SESSION['pending_app_auth']['session'])) {
                $p_auth = $_SESSION['pending_app_auth'];
                $qs = http_build_query([
                    'app_auth'  => 1,
                    'session'   => $p_auth['session'],
                    'challenge' => $p_auth['challenge'],
                    'port'      => $p_auth['port'],
                ]);
                header("Location: index.php?" . $qs);
                exit;
            }
            header("Location: index.php?registered=" . ($got_trial ? '1' : '0'));
            exit;
        }
    }

    // 2. LOGIN
    elseif ($act === 'login') {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $p = $_POST['password'] ?? '';
        $user_row = db_get_user($u);

        if ($user_row && password_verify($p, $user_row['password_hash'])) {
            $_SESSION['user'] = $u;
            if (!empty($_SESSION['pending_app_auth']['session'])) {
                $p_auth = $_SESSION['pending_app_auth'];
                $qs = http_build_query([
                    'app_auth'  => 1,
                    'session'   => $p_auth['session'],
                    'challenge' => $p_auth['challenge'],
                    'port'      => $p_auth['port'],
                ]);
                header("Location: index.php?" . $qs);
                exit;
            }
            header("Location: index.php");
            exit;
        } else {
            $msg_error = 'Sai tên đăng nhập hoặc mật khẩu!';
        }
    }

    // 2.5 APPROVE APP AUTH
    elseif ($act === 'approve_app_auth' && $current_user) {
        $sess_id   = trim($_POST['session_id'] ?? ($_SESSION['pending_app_auth']['session'] ?? ''));
        $port      = intval($_POST['port'] ?? ($_SESSION['pending_app_auth']['port'] ?? 0));
        $challenge = trim($_POST['challenge'] ?? ($_SESSION['pending_app_auth']['challenge'] ?? ''));
        $state     = trim($_POST['state'] ?? ($_SESSION['pending_app_auth']['state'] ?? ''));

        $user_row = db_get_user($current_user);
        if ($user_row && !empty($sess_id)) {
            $uid = (string)$user_row['id'];
            $token = hash_hmac('sha256', $uid . time(), '2toolne_jwt_auth_secret_token_key_2026');
            $auth_code = 'ac_' . bin2hex(random_bytes(24));

            $db = get_db();

            // Ensure app_auth_sessions has PKCE columns
            try {
                $cols = $db->query("DESCRIBE `app_auth_sessions`")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('code', $cols)) {
                    $db->exec("ALTER TABLE `app_auth_sessions` ADD COLUMN `code` VARCHAR(64) NULL AFTER `challenge`");
                }
                if (!in_array('code_challenge', $cols)) {
                    $db->exec("ALTER TABLE `app_auth_sessions` ADD COLUMN `code_challenge` VARCHAR(128) NULL AFTER `code`");
                }
                if (!in_array('used_at', $cols)) {
                    $db->exec("ALTER TABLE `app_auth_sessions` ADD COLUMN `used_at` DATETIME NULL AFTER `status`");
                }
            } catch (Throwable $e) {}

            $stmt = $db->prepare('
                SELECT l.plan, l.credit_mode, l.expires_at, w.balance as token_balance
                FROM users u
                LEFT JOIN license_entitlements l ON u.id = l.user_id
                LEFT JOIN credit_wallets w ON u.id = w.user_id
                WHERE u.id = ?
            ');
            $stmt->execute([$uid]);
            $extra = $stmt->fetch() ?: [];

            $user_data = [
                'id' => $uid,
                'username' => $user_row['username'],
                'email' => $user_row['email'] ?: $user_row['username'],
                'full_name' => $user_row['fullname'] ?? '',
                'role' => $user_row['role'] ?? 'user',
                'plan' => $extra['plan'] ?? 'PRO',
                'credit_mode' => $extra['credit_mode'] ?? 'METERED',
                'token_balance' => (int)($extra['token_balance'] ?? 0),
                'expires_at' => $extra['expires_at'] ?? null,
            ];

            // Update or insert app_auth_sessions
            $checkStmt = $db->prepare('SELECT id FROM app_auth_sessions WHERE id = ? LIMIT 1');
            $checkStmt->execute([$sess_id]);
            if ($checkStmt->fetch()) {
                $stmt = $db->prepare('
                    UPDATE app_auth_sessions
                    SET status = "APPROVED", user_id = ?, token = ?, payload = ?, code = ?, code_challenge = ?, expires_at = DATE_ADD(NOW(), INTERVAL 120 SECOND)
                    WHERE id = ?
                ');
                $stmt->execute([$uid, $token, json_encode($user_data), $auth_code, $challenge, $sess_id]);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO app_auth_sessions (id, challenge, code, code_challenge, port, status, user_id, token, payload, expires_at)
                    VALUES (?, ?, ?, ?, ?, "APPROVED", ?, ?, ?, DATE_ADD(NOW(), INTERVAL 120 SECOND))
                ');
                $stmt->execute([$sess_id, $challenge, $auth_code, $challenge, $port, $uid, $token, json_encode($user_data)]);
            }

            unset($_SESSION['pending_app_auth']);

            $_SESSION['app_auth_approved'] = [
                'username'  => $user_row['username'],
                'plan'      => $user_data['plan'],
                'balance'   => $user_data['token_balance'],
                'port'      => $port,
                'session'   => $sess_id,
                'token'     => $token,
                'code'      => $auth_code,
                'state'     => $state,
                'uid'       => $uid,
                'email'     => $user_data['email'],
                'challenge' => $challenge,
            ];

            header("Location: index.php?app_auth_approved=1");
            exit;
        }
    }

    // 3. LOGOUT
    elseif ($act === 'logout') {
        unset($_SESSION['user']);
        header("Location: index.php");
        exit;
    }

    // 4. RESET HWID
    elseif ($act === 'reset_hwid' && $user_info) {
        $k = trim($_POST['key'] ?? '');
        $lic_item = db_get_license($k);
        if ($lic_item && ($lic_item['owner_user'] ?? '') === $current_user) {
            db_reset_license_hwid($k);
            flash_redirect('success', "✅ Đã Reset HWID cho Key <b>{$k}</b>! Bạn có thể kích hoạt trên máy tính mới.", 'keys');
        }
    }

    // 5. SUBMIT PAYMENT ORDER
    elseif ($act === 'submit_payment' && $user_info) {
        $pkg_name = trim($_POST['package_name'] ?? 'Gói 1 Tháng');
        $pkg_price = trim($_POST['package_price'] ?? '1.000.000đ');
        $pkg_days = intval($_POST['duration_days'] ?? 30);
        $pkg_tier = trim($_POST['tier'] ?? 'VIP');
        $pkg_prod = trim($_POST['product'] ?? (stripos($pkg_name, 'Token') !== false ? 'TOKEN_WALLET' : (stripos($pkg_name, '2toolne') !== false ? '2TOOLNE' : (stripos($pkg_name, 'Extension') !== false ? 'LABS_EXTENSION' : 'SLIDESHOW'))));

        $ord_id = uniqid('ORD_');
        db_create_order(
            $ord_id,
            $current_user,
            $user_info['fullname'] ?? $current_user,
            $user_info['phone'] ?? '',
            $pkg_prod,
            $pkg_name,
            $pkg_price,
            $pkg_days,
            $pkg_tier,
            "Mua tool " . $current_user
        );
        $target_tab = ($pkg_prod === 'TOKEN_WALLET') ? 'wallet' : 'keys';
        $success_msg = ($pkg_prod === 'TOKEN_WALLET') 
            ? '🎉 Đã ghi nhận thông tin thanh toán nạp lượt ảnh! Hệ thống sẽ kiểm tra và cộng lượt vào tài khoản ngay khi xác nhận thanh toán.' 
            : '🎉 Đã ghi nhận yêu cầu mua bản quyền phần mềm! Đơn hàng sẽ được kích hoạt ngay sau khi xác nhận thanh toán.';

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                   || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                   || isset($_POST['is_ajax']);

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'success',
                'message' => $success_msg,
                'order_id' => $ord_id,
                'target_tab' => $target_tab
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_redirect('success', $success_msg, $target_tab);
    }

    // 6. FEATURE REQUEST
    elseif ($act === 'add_feature' && $user_info) {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($title) || empty($desc)) {
            flash_redirect('error', '❌ Vui lòng nhập đầy đủ tiêu đề và nội dung mong muốn update!', 'features');
        } else {
            $cur_month = date('Y-m');
            $user_count = 0;
            foreach ($features_db as $f_item) {
                if (($f_item['user'] ?? '') === $current_user && strpos($f_item['created_at'] ?? '', $cur_month) === 0) {
                    $user_count++;
                }
            }

            if ($user_count >= 3) {
                flash_redirect('error', 'Bạn đã gửi tối đa 3 phiếu mong muốn update trong tháng này!', 'features');
            } else {
                db_create_feature(uniqid('ft_'), $current_user, $title, $desc);
                flash_redirect('success', '✅ Đã gửi phiếu mong muốn update! Đội ngũ phát triển 2TOOL sẽ ghi nhận và xem xét bổ sung vào bản cập nhật tới.', 'features');
            }
        }
    }

    // 7. CHANGE PASSWORD
    elseif ($act === 'change_password' && $user_info) {
        $old_pw     = $_POST['old_password'] ?? '';
        $new_pw     = $_POST['new_password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (!password_verify($old_pw, $user_info['password_hash'])) {
            flash_redirect('error', '❌ Mật khẩu cũ không chính xác!', 'settings');
        } elseif (strlen($new_pw) < 6) {
            flash_redirect('error', '❌ Mật khẩu mới phải có ít nhất 6 ký tự!', 'settings');
        } elseif ($new_pw !== $confirm_pw) {
            flash_redirect('error', '❌ Xác nhận mật khẩu mới không khớp!', 'settings');
        } else {
            db_update_user_password($current_user, password_hash($new_pw, PASSWORD_DEFAULT));
            flash_redirect('success', '✅ Đã đổi mật khẩu thành công!', 'settings');
        }
    }

    // 8. BUG REPORT
    elseif ($act === 'add_bug' && $user_info) {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $err_code = trim($_POST['error_code'] ?? '');

        if (empty($title) || empty($desc)) {
            flash_redirect('error', '❌ Vui lòng nhập tiêu đề và chi tiết lỗi gặp phải!', 'bugs');
        } else {
            db_create_bug(uniqid('bug_'), $current_user, $title, $desc, $err_code);
            flash_redirect('success', '🐞 Đã gửi báo cáo lỗi! Đội ngũ kỹ thuật sẽ kiểm tra và khắc phục sớm nhất.', 'bugs');
        }
    }

    // 9. RESPOND TEAM INVITE
    elseif ($act === 'respond_team_invite' && $user_info) {
        $invite_id = trim($_POST['invite_id'] ?? '');
        $decision = strtoupper(trim($_POST['decision'] ?? ''));
        $res = db_respond_team_invite($invite_id, $user_info['id'], $decision);
        if (!empty($res['success'])) {
            if ($decision === 'ACCEPT') {
                flash_redirect('success', '🎉 Chúc mừng! Bạn đã tham gia Team Cloud thành công. Dung lượng nhóm đã được liên kết vào tài khoản của bạn!', 'cloud-storage');
            } else {
                flash_redirect('success', 'Đã từ chối lời mời tham gia nhóm.', 'cloud-storage');
            }
        } else {
            flash_redirect('error', '❌ ' . ($res['error'] ?? 'Không thể xử lý lời mời'), 'cloud-storage');
        }
    }

    // 10. SEND TEAM INVITE
    elseif ($act === 'send_team_invite' && $user_info) {
        $team_id = trim($_POST['team_id'] ?? '');
        $target_u = trim($_POST['target_username'] ?? '');
        $my_role = db_get_team_user_role($team_id, $user_info['id']);
        if (!in_array($my_role, ['OWNER', 'ADMIN'])) {
            flash_redirect('error', '❌ Bạn không có quyền mời thành viên vào nhóm này!', 'cloud-storage');
        }
        $res = db_invite_team_member($team_id, $target_u, $user_info['id']);
        if (!empty($res['success'])) {
            flash_redirect('success', "🎉 Đã gửi lời mời tham gia Team tới tài khoản <b>@{$target_u}</b> thành công!", 'cloud-storage');
        } else {
            flash_redirect('error', '❌ ' . ($res['error'] ?? 'Không thể gửi lời mời'), 'cloud-storage');
        }
    }

    // 11. REMOVE TEAM MEMBER
    elseif ($act === 'user_remove_team_member' && $user_info) {
        $team_id = trim($_POST['team_id'] ?? '');
        $target_uid = trim($_POST['member_user_id'] ?? '');
        $my_role = db_get_team_user_role($team_id, $user_info['id']);
        $target_role = db_get_team_user_role($team_id, $target_uid);

        $can_remove = false;
        if ($my_role === 'OWNER' && $target_uid !== $user_info['id']) {
            $can_remove = true;
        } elseif ($my_role === 'ADMIN' && $target_role === 'MEMBER') {
            $can_remove = true;
        }

        if (!$can_remove) {
            flash_redirect('error', '❌ Bạn không có quyền xóa thành viên này!', 'cloud-storage');
        }

        $res = db_remove_team_member($team_id, $target_uid);
        if (!empty($res['success'])) {
            flash_redirect('success', '✅ Đã xóa thành viên khỏi Team!', 'cloud-storage');
        } else {
            flash_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi xóa thành viên'), 'cloud-storage');
        }
    }

    // 12. UPDATE TEAM MEMBER ROLE (PHÂN QUYỀN VAI TRÒ)
    elseif ($act === 'user_update_team_role' && $user_info) {
        $team_id = trim($_POST['team_id'] ?? '');
        $target_uid = trim($_POST['member_user_id'] ?? '');
        $new_role = strtoupper(trim($_POST['role'] ?? ''));
        $my_role = db_get_team_user_role($team_id, $user_info['id']);

        if ($my_role !== 'OWNER') {
            flash_redirect('error', '❌ Chỉ Trưởng nhóm (Owner) mới có quyền phân quyền vai trò thành viên!', 'cloud-storage');
        }

        $res = db_update_team_member_role($team_id, $target_uid, $new_role);
        if (!empty($res['success'])) {
            flash_redirect('success', "✅ {$res['message']}", 'cloud-storage');
        } else {
            flash_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi phân quyền thành viên'), 'cloud-storage');
        }
    }
}

// Refresh databases and pending orders for user
$users_db    = db_get_users();
$licenses_db = db_get_licenses();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();
$user_info      = $current_user ? db_get_user($current_user) : null;
$user_wallet    = $current_user ? db_get_user_wallet($current_user) : null;
$user_tokens_tx = $current_user ? db_get_user_token_transactions($current_user, 30) : [];

$user_orders = $current_user ? array_filter($orders_db, fn($o) => strtolower($o['user'] ?? ($o['username'] ?? '')) === strtolower($current_user)) : [];
$user_pending_orders = array_filter($user_orders, fn($o) => ($o['status'] ?? '') === 'pending');

// ── V3 Workspace Additional Context Data ─────────────────────────────
$user_teams = [];
$user_team_members = [];
$user_ai_keys = [];
$active_license = null;

if ($user_info && !empty($user_info['id'])) {
    $uid_str = (string)$user_info['id'];
    try {
        $db = get_db();
        $stmtT = $db->prepare("
            SELECT t.*, tm.role as my_role, u_owner.username as owner_username, u_owner.fullname as owner_fullname
            FROM teams t 
            JOIN team_members tm ON t.id = tm.team_id 
            LEFT JOIN users u_owner ON t.owner_user_id = u_owner.id
            WHERE tm.user_id = ? AND tm.status = 'ACTIVE'
            ORDER BY t.created_at DESC
        ");
        $stmtT->execute([$uid_str]);
        $user_teams = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($user_teams)) {
            $primary_tid = $user_teams[0]['id'];
            $stmtM = $db->prepare("
                SELECT tm.*, u.username, u.fullname, u.phone, u.role as user_global_role
                FROM team_members tm
                LEFT JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = ? AND tm.status != 'REMOVED'
                ORDER BY tm.joined_at ASC
            ");
            $stmtM->execute([$primary_tid]);
            $user_team_members = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmtK = $db->prepare("
            SELECT k.*, cs.name as workspace_name
            FROM ai_access_keys k
            LEFT JOIN cloud_spaces cs ON k.workspace_id = cs.id
            WHERE k.user_id = ?
            ORDER BY k.created_at DESC
        ");
        $stmtK->execute([$uid_str]);
        $user_ai_keys = $stmtK->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

if (!empty($user_info['keys'])) {
    foreach ($user_info['keys'] as $k) {
        $lic = $licenses_db[$k] ?? null;
        if ($lic && ($lic['status'] ?? '') === 'active') {
            $active_license = $lic;
            $active_license['key'] = $k;
            break;
        }
    }
}

if (isset($_GET['flash_out'])) {
    $msg_success = '👋 Bạn đã đăng xuất thành công. Hẹn gặp lại!';
}

if (isset($_GET['registered'])) {
    if ($_GET['registered'] === '1') {
        $msg_success = '🎉 Chúc mừng bạn đã đăng ký thành công! Bạn được <b>TẶNG NGAY 1 KHÓA DÙNG THỬ 3 NGÀY</b> + <b>50 LƯỢT PHÓNG TO ẢNH (TOKEN) MIỄN PHÍ</b>!';
    } else {
        $msg_success = '🎉 Đăng ký tài khoản thành công! Bạn đã được <b>TẶNG NGAY 50 LƯỢT PHÓNG TO ẢNH (TOKEN) MIỄN PHÍ</b> vào ví!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title><?= $user_info ? '2TOOLNE — Bảng Điều Khiển &amp; Bản Quyền' : '2TOOLNE — AI Video Infrastructure &amp; Automation Pipeline (V3)' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="globals.css?v=<?= filemtime(__DIR__ . '/globals.css') ?>">
    <?php if ($user_info): ?>
    <link rel="stylesheet" href="assets/css/v3-workspace.css?v=<?= filemtime(__DIR__ . '/assets/css/v3-workspace.css') ?>">
    <?php endif; ?>
    <?php if (!$user_info): ?>
    <link rel="stylesheet" href="dist/assets/index-B62LYrWu.css">
    <?php endif; ?>
    <style>
        /* ── Specific View Layout Adjustments ──────────────────────────────── */
        .site-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(9, 9, 11, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            height: 60px;
            display: flex;
            align-items: center;
        }
        .nav-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            color: var(--foreground);
            letter-spacing: -0.02em;
        }
        .nav-brand-logo {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: linear-gradient(135deg, #10b981, #0284c7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #fff;
            font-size: 13px;
        }
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .nav-item {
            font-size: 13px;
            font-weight: 500;
            color: var(--muted-foreground);
            transition: color 0.15s ease;
        }
        .nav-item:hover {
            color: var(--foreground);
        }

        /* ── Hero Section ─────────────────────────────────────────────────── */
        .hero-section {
            padding: 56px 0 36px;
            text-align: center;
        }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-1);
            border: 1px solid var(--border);
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            color: var(--muted-foreground);
            margin-bottom: 20px;
        }
        .hero-title {
            font-size: 44px;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
            color: var(--foreground);
            text-wrap: balance;
        }
        .hero-title span {
            background: linear-gradient(135deg, #fafafa 30%, #a1a1aa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        [data-theme="light"] .hero-title span {
            background: linear-gradient(135deg, #111827 30%, #4B5563 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            font-size: 15px;
            color: var(--muted-foreground);
            max-width: 640px;
            margin: 0 auto 32px;
            line-height: 1.6;
            text-wrap: balance;
        }
        .hero-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* ── Product Switcher Tabs ────────────────────────────────────────── */
        .prod-tab-bar {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 28px 0 32px;
            flex-wrap: wrap;
        }
        .prod-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            font-weight: 600;
            padding: 8px 16px;
        }

        /* ── Feature Grid ─────────────────────────────────────────────────── */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }
        .feature-card {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all 0.2s ease;
        }
        .feature-card:hover {
            border-color: var(--border-hover);
            background: var(--surface-hover);
        }
        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: var(--surface-2);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-bottom: 14px;
            color: var(--foreground);
        }
        .feature-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 6px;
        }
        .feature-desc {
            font-size: 13px;
            color: var(--muted-foreground);
            line-height: 1.5;
        }

        /* ── Pricing Matrix ───────────────────────────────────────────────── */
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 48px;
        }
        .pricing-card {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }
        .pricing-card.featured {
            border-color: var(--emerald);
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.15);
        }
        .pricing-card-badge {
            position: absolute;
            top: 14px;
            right: 14px;
        }
        .price-val {
            font-size: 28px;
            font-weight: 700;
            color: var(--foreground);
            margin: 12px 0 4px;
            letter-spacing: -0.02em;
        }
        .price-sub {
            font-size: 12px;
            color: var(--muted-foreground);
            margin-bottom: 20px;
        }
        .price-checklist {
            list-style: none;
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--foreground);
        }
        .price-checklist li {
            padding: 6px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Dashboard Layout ─────────────────────────────────────────────── */
        .dash-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 24px;
            margin-top: 24px;
            min-height: calc(100vh - 180px);
        }
        .dash-sidebar {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 16px;
            height: fit-content;
        }
        .dash-user-card {
            padding-bottom: 14px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }
        .dash-user-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--foreground);
        }
        .dash-user-sub {
            font-size: 12px;
            color: var(--muted-foreground);
            font-family: var(--font-mono);
        }
        .dash-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dash-nav-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            color: var(--muted-foreground);
            background: transparent;
            border: none;
            text-align: left;
            cursor: pointer;
            width: 100%;
            transition: all 0.15s ease;
        }
        .dash-nav-btn:hover {
            color: var(--foreground);
            background: var(--surface-2);
        }
        .dash-nav-btn.active {
            color: #ffffff;
            background: var(--surface-3);
            font-weight: 600;
        }
        .dash-main {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ── Toast Notification ───────────────────────────────────────────── */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--surface-1);
            color: var(--foreground);
            border: 1px solid var(--border-strong);
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            box-shadow: var(--shadow-lg);
            display: none;
            align-items: center;
            gap: 8px;
            z-index: 10000;
            animation: modalFadeIn 0.2s ease;
        }

        /* ── Responsive Utilities & Mobile Navigation ──────────────────────── */
        .desktop-only {
            display: flex;
        }
        .mobile-only {
            display: none;
        }
        .mobile-nav-drawer {
            position: absolute;
            top: 60px;
            left: 0;
            right: 0;
            background: rgba(18, 18, 21, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-strong);
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.7);
            z-index: 1000;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            max-height: calc(100vh - 60px);
            max-height: calc(100dvh - 60px);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .mobile-nav-drawer.open {
            opacity: 1;
            transform: translateY(0);
        }
        .mobile-nav-content {
            padding: 16px;
        }
        .mobile-nav-links {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .mobile-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 500;
            color: var(--foreground);
            background: var(--surface-1);
            border: 1px solid var(--border);
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .mobile-nav-link:hover, .mobile-nav-link:active {
            background: var(--surface-hover);
            border-color: var(--border-strong);
            color: #ffffff;
        }
        .pricing-card .btn {
            width: 100%;
            height: 42px;
            font-size: 13.5px;
            font-weight: 600;
        }

        @media (max-width: 860px) {
            .desktop-only {
                display: none !important;
            }
            .mobile-only {
                display: flex !important;
            }
            .dash-layout {
                grid-template-columns: 1fr;
                gap: 14px;
                margin-top: 14px;
                min-height: auto;
            }
            .dash-sidebar {
                padding: 10px 12px;
                position: sticky;
                top: 60px;
                z-index: 90;
                background: rgba(18, 18, 21, 0.96);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border-radius: var(--radius-sm);
                border: 1px solid var(--border);
            }
            .dash-user-card {
                display: none;
            }
            .dash-nav {
                flex-direction: row;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                gap: 6px;
                padding-bottom: 2px;
                scrollbar-width: none;
            }
            .dash-nav::-webkit-scrollbar {
                display: none;
            }
            .dash-nav-btn {
                width: auto;
                flex-shrink: 0;
                padding: 7px 12px;
                font-size: 12.5px;
                border-radius: 20px;
                background: var(--surface-2);
                border: 1px solid var(--border);
                white-space: nowrap;
            }
            .dash-nav-btn.active {
                background: var(--primary);
                color: #ffffff;
                border-color: var(--primary);
                font-weight: 600;
                box-shadow: 0 2px 8px rgba(255, 122, 0, 0.35);
            }
            .hero-section {
                padding: 36px 0 24px;
            }
            .hero-title {
                font-size: 28px;
                line-height: 1.25;
                text-wrap: balance;
            }
            .hero-desc {
                font-size: 14px;
                margin-bottom: 24px;
                text-wrap: balance;
            }
            .hero-actions .btn {
                width: 100%;
                height: 42px;
            }
            #toast {
                left: 16px;
                right: 16px;
                bottom: 16px;
                max-width: calc(100% - 32px);
                justify-content: center;
                text-align: center;
            }
        }
        @media (max-width: 640px) {
            .pricing-grid {
                grid-template-columns: 1fr;
            }
            .feature-grid {
                grid-template-columns: 1fr;
            }
            .smart-download-container {
                width: 100%;
            }
            .smart-download-btn {
                width: 100%;
            }
        }

        /* ═══════════════════════════════════════════════════════════════════════════
           2TOOLNE CLOUD STORAGE V2 DESIGN SYSTEM (INLINE PROTECTED)
           ═══════════════════════════════════════════════════════════════════════════ */
        .cloud-header-box {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(17, 24, 39, 0.95) 100%) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-lg) !important;
            padding: 18px 22px !important;
            margin-bottom: 16px !important;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }
        .cloud-header-bar {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 14px !important;
        }
        .cloud-status-pulse {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            background: rgba(16, 185, 129, 0.12) !important;
            border: 1px solid rgba(16, 185, 129, 0.35) !important;
            color: #34d399 !important;
            padding: 3px 10px !important;
            border-radius: 999px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
        }
        .cloud-pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 8px #10b981;
            animation: pulseDot 2s infinite ease-in-out;
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(0.8); }
        }
        .cloud-space-selector-wrap {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: rgba(255, 255, 255, 0.04) !important;
            padding: 6px 12px !important;
            border-radius: var(--radius-md) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }
        .cloud-space-selector {
            background: transparent !important;
            border: none !important;
            color: var(--foreground) !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            outline: none !important;
            cursor: pointer !important;
        }
        .cloud-space-selector option {
            background: #0f172a;
            color: #f1f5f9;
        }
        /* Sleek Quota Strip (1 Horizontal Compact Bar) */
        .cloud-quota-strip {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            background: rgba(17, 24, 39, 0.65) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-md) !important;
            padding: 10px 18px !important;
            margin-bottom: 14px !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .cloud-quota-strip-left {
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
            min-width: 240px !important;
            flex: 1.2 !important;
        }
        .cloud-quota-track-strip {
            width: 100% !important;
            height: 6px !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border-radius: 999px !important;
            overflow: hidden !important;
        }
        .cloud-quota-strip-right {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
            flex: 1 !important;
            justify-content: flex-end !important;
        }
        .cloud-chip-compact {
            background: rgba(255, 255, 255, 0.04) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            border-radius: 6px !important;
            padding: 5px 10px !important;
            font-size: 12px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        .cloud-quota-dashboard {
            display: grid !important;
            grid-template-columns: 1.3fr 1fr !important;
            gap: 16px !important;
            background: rgba(17, 24, 39, 0.7) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-lg) !important;
            padding: 16px 20px !important;
            margin-bottom: 16px !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .cloud-quota-track {
            width: 100% !important;
            height: 8px !important;
            background: rgba(255, 255, 255, 0.06) !important;
            border-radius: 999px !important;
            overflow: hidden !important;
            margin: 10px 0 6px 0 !important;
            border: 1px solid rgba(255, 255, 255, 0.04) !important;
        }
        .cloud-quota-fill {
            height: 100% !important;
            border-radius: 999px !important;
            background: linear-gradient(90deg, #10b981 0%, #06b6d4 50%, #3b82f6 100%) !important;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.4) !important;
            transition: width 0.4s ease !important;
        }
        .cloud-quota-fill.over-quota {
            background: linear-gradient(90deg, #f59e0b 0%, #ef4444 100%) !important;
        }
        .cloud-stat-chips {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 8px !important;
        }
        .cloud-chip {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            border-radius: var(--radius-md) !important;
            padding: 8px 12px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }
        .cloud-chip-icon {
            font-size: 18px !important;
        }
        .cloud-chip-label {
            font-size: 10.5px !important;
            color: var(--muted-foreground) !important;
            text-transform: uppercase !important;
            font-weight: 700 !important;
            letter-spacing: 0.03em !important;
        }
        .cloud-chip-val {
            font-size: 13.5px !important;
            font-weight: 700 !important;
            color: var(--foreground) !important;
            font-family: var(--font-mono) !important;
        }
        .cloud-toolbar-v2 {
            background: rgba(17, 24, 39, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-md) !important;
            padding: 10px 14px !important;
            margin-bottom: 16px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 12px !important;
            flex-wrap: wrap !important;
            backdrop-filter: blur(8px);
        }
        .cloud-toolbar-row1 {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 10px !important;
        }
        .cloud-toolbar-row2 {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 10px !important;
            padding-top: 10px !important;
            border-top: 1px solid rgba(255, 255, 255, 0.05) !important;
        }
        .cloud-breadcrumbs {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 13px !important;
            flex-wrap: wrap !important;
        }
        .cloud-crumb {
            color: var(--muted-foreground) !important;
            cursor: pointer !important;
            padding: 3px 8px !important;
            border-radius: var(--radius-sm) !important;
            transition: all 0.15s !important;
        }
        .cloud-crumb:hover {
            color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.08) !important;
        }
        .cloud-crumb.active {
            color: var(--foreground) !important;
            font-weight: 600 !important;
            cursor: default !important;
            background: rgba(255, 255, 255, 0.06) !important;
        }
        .cloud-search-box {
            display: flex !important;
            align-items: center !important;
            background: rgba(0, 0, 0, 0.25) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: var(--radius-md) !important;
            padding: 4px 10px !important;
            gap: 8px !important;
            min-width: 220px !important;
        }
        .cloud-search-input {
            background: transparent !important;
            border: none !important;
            outline: none !important;
            color: var(--foreground) !important;
            font-size: 12.5px !important;
            width: 100% !important;
        }
        .cloud-filter-pills {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
        }
        .cloud-filter-pill {
            padding: 4px 10px !important;
            border-radius: 999px !important;
            font-size: 11.5px !important;
            font-weight: 500 !important;
            color: var(--muted-foreground) !important;
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            cursor: pointer !important;
            transition: all 0.15s ease !important;
        }
        .cloud-filter-pill:hover {
            color: var(--foreground) !important;
            border-color: rgba(255, 255, 255, 0.15) !important;
        }
        .cloud-filter-pill.active {
            color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.12) !important;
            border-color: rgba(56, 189, 248, 0.4) !important;
            font-weight: 600 !important;
        }
        .cloud-view-toggle {
            display: inline-flex !important;
            background: rgba(0, 0, 0, 0.25) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-sm) !important;
            padding: 2px !important;
        }
        .cloud-view-btn {
            padding: 4px 8px !important;
            border: none !important;
            background: transparent !important;
            color: var(--muted-foreground) !important;
            cursor: pointer !important;
            border-radius: 4px !important;
            font-size: 13px !important;
        }
        .cloud-view-btn.active {
            background: rgba(255, 255, 255, 0.1) !important;
            color: var(--foreground) !important;
        }
        .cloud-empty-dropzone, .cloud-dropzone {
            border: 2px dashed rgba(56, 189, 248, 0.3) !important;
            border-radius: var(--radius-lg) !important;
            padding: 44px 20px !important;
            text-align: center !important;
            background: rgba(15, 23, 42, 0.45) !important;
            backdrop-filter: blur(8px) !important;
            cursor: pointer !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .cloud-empty-dropzone:hover, .cloud-empty-dropzone.dragover,
        .cloud-dropzone:hover, .cloud-dropzone.dragover {
            border-color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.08) !important;
            box-shadow: 0 0 25px rgba(56, 189, 248, 0.15) !important;
            transform: translateY(-2px) !important;
        }
        .cloud-dropzone-icon {
            font-size: 44px !important;
            margin-bottom: 10px !important;
            filter: drop-shadow(0 4px 12px rgba(56, 189, 248, 0.3)) !important;
        }
        .cloud-table-wrap {
            background: rgba(17, 24, 39, 0.7) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-md) !important;
            overflow: hidden !important;
        }
        .cloud-table-v2 {
            width: 100% !important;
            border-collapse: collapse !important;
            margin: 0 !important;
            font-size: 13px !important;
        }
        .cloud-table-v2 th {
            padding: 12px 14px !important;
            text-align: left !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            color: var(--muted-foreground) !important;
            background: rgba(0, 0, 0, 0.2) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06) !important;
        }
        .cloud-table-v2 td {
            padding: 12px 14px !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04) !important;
            color: var(--foreground) !important;
            vertical-align: middle !important;
        }
        .cloud-table-v2 tr:hover td {
            background: rgba(255, 255, 255, 0.02) !important;
        }
        .cloud-file-badge {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 32px !important;
            height: 32px !important;
            border-radius: var(--radius-sm) !important;
            font-size: 16px !important;
            flex-shrink: 0 !important;
        }
        .cloud-badge-img { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        .cloud-badge-vid { background: rgba(56, 189, 248, 0.15) !important; color: #38bdf8 !important; }
        .cloud-badge-zip { background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; }
        .cloud-badge-doc { background: rgba(168, 85, 247, 0.15) !important; color: #c084fc !important; }
        .cloud-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)) !important;
            gap: 12px !important;
            margin-bottom: 20px !important;
        }
        .cloud-card-item {
            background: rgba(17, 24, 39, 0.8) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: var(--radius-md) !important;
            padding: 14px !important;
            cursor: pointer !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            min-height: 105px !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .cloud-card-item:hover {
            border-color: rgba(56, 189, 248, 0.4) !important;
            background: rgba(30, 41, 59, 0.85) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35) !important;
        }

        @media (max-width: 900px) {
            .cloud-quota-dashboard {
                grid-template-columns: 1fr !important;
            }
        }
        @media (max-width: 768px) {
            .cloud-header-bar {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .cloud-space-selector-wrap {
                width: 100% !important;
            }
            .cloud-toolbar-v2 {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .cloud-stat-chips {
                grid-template-columns: 1fr 1fr !important;
            }
        }
    </style>
</head>
<body class="<?= $user_info ? 'v3-workspace-body' : '' ?>">

    <!-- ═══ 2TOOLNE WEB V3 AUTHENTICATED WORKSPACE ═══ -->
    <?php if ($user_info): ?>
        <?php require __DIR__ . '/views/v3_workspace.php'; ?>
        <?php if (false): /* Legacy v2 dashboard layout bypassed in favor of V3 Workspace */ ?>
    <header class="site-nav">
        <div class="container nav-inner">
            <a href="index.php" class="nav-brand">
                <img src="assets/favicon.png" alt="2TOOLNE Logo" style="width:26px;height:26px;border-radius:6px;object-fit:contain;">
                <span>2TOOLNE</span>
                <span class="badge desktop-only" style="font-size:10px;padding:1px 6px">v2.0</span>
            </a>

            <!-- DESKTOP NAV MENU -->
            <nav class="nav-menu desktop-only">
                <?php if ($user_info): ?>
                    <a href="#tab-buy-key" class="nav-item" onclick="switchMainTab('tab-buy-key')">Sản Phẩm</a>
                    <a href="#tab-downloads" class="nav-item" onclick="switchMainTab('tab-downloads')">Tải Về</a>
                    <a href="#tab-wallet-view" class="nav-item" onclick="switchMainTab('tab-wallet-view')">Ví Lượt Ảnh (Upscale)</a>
                    <a href="#tab-cloud-storage" class="nav-item" onclick="switchMainTab('tab-cloud-storage')">Cloud Lưu Trữ</a>
                    <a href="#tab-buy-key" class="nav-item" onclick="switchMainTab('tab-buy-key')">Bảng Giá</a>
                <?php else: ?>
                    <a href="#products" class="nav-item">Sản Phẩm</a>
                    <a href="#products" class="nav-item" onclick="switchProductTab('ptab-cloud', 'ptab-btn-cloud'); location.href='#products'">Cloud Lưu Trữ</a>
                    <a href="#downloads" class="nav-item" onclick="openModal('modal-login'); return false;">Tải Về</a>
                    <a href="#pricing" class="nav-item">Bảng Giá</a>
                <?php endif; ?>
                <a href="https://zalo.me/0326649304" target="_blank" class="nav-item" style="color:var(--emerald)">Hỗ Trợ Zalo</a>
            </nav>

            <!-- DESKTOP ACTIONS -->
            <div class="desktop-only" style="align-items:center;gap:10px">
                <?php if ($user_info): ?>
                    <a href="#tab-wallet-view" onclick="switchMainTab('tab-wallet-view')" class="badge" style="background:rgba(234, 179, 8, 0.15);border:1px solid rgba(234, 179, 8, 0.45);color:#facc15;font-weight:700;font-size:12px;padding:4px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border-radius:20px;cursor:pointer" title="Số dư lượt phóng to ảnh AI (Tokens): Bấm để nạp thêm hoặc xem lịch sử">
                        🪙 <span id="nav-token-balance"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span> Lượt ảnh
                    </a>
                    <?php if (in_array($user_info['role'] ?? '', ['admin', 'super_admin', 'sales', 'tech_support', 'content_manager', 'custom'])): ?>
                        <a href="license_admin.php" class="btn btn-outline btn-xs" style="border-color:var(--primary);color:var(--primary)" title="Trang Quản Trị Hệ Thống">
                            👑 Quản Trị
                        </a>
                    <?php endif; ?>
                    <a href="?logout=1" class="btn btn-outline btn-sm">
                        <span>Đăng Xuất (<?= htmlspecialchars($user_info['username']) ?>)</span>
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline btn-sm" onclick="openModal('modal-login')">Đăng Nhập</button>
                    <button class="btn btn-primary btn-sm" onclick="openModal('modal-register')">🎁 Nhận Key + 50 Lượt Ảnh</button>
                <?php endif; ?>
            </div>

            <!-- MOBILE NAV CONTROLS -->
            <div class="mobile-only" style="align-items:center;gap:8px">
                <?php if ($user_info): ?>
                    <a href="#tab-wallet-view" onclick="switchMainTab('tab-wallet-view')" class="badge" style="background:rgba(234, 179, 8, 0.15);border:1px solid rgba(234, 179, 8, 0.45);color:#facc15;font-weight:700;font-size:11.5px;padding:3px 8px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border-radius:16px">
                        🪙 <span id="nav-token-balance-mobile"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span>
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline btn-sm" id="btn-mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Menu" style="padding:0 10px;height:34px">
                    <span id="mobile-menu-icon" style="font-size:16px;line-height:1">☰</span>
                </button>
            </div>
        </div>

        <!-- MOBILE DROPDOWN DRAWER -->
        <div id="mobile-nav-panel" class="mobile-nav-drawer" style="display:none">
            <div class="mobile-nav-content">
                <?php if ($user_info): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--surface-2);border-radius:var(--radius-sm);margin-bottom:12px;border:1px solid var(--border)">
                        <div>
                            <div style="font-weight:600;font-size:13.5px;color:var(--foreground)">👤 <?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?></div>
                            <div style="font-size:11.5px;color:var(--muted-foreground)">@<?= htmlspecialchars($user_info['username']) ?></div>
                        </div>
                        <span class="badge badge-active" style="font-size:11px"><?= strtoupper(htmlspecialchars($user_info['role'] ?? 'USER')) ?></span>
                    </div>
                    <nav class="mobile-nav-links">
                        <a href="#tab-my-keys" class="mobile-nav-link" onclick="mobileSwitchTab('tab-my-keys')">🔑 Bản Quyền Của Tôi</a>
                        <a href="#tab-orders-history" class="mobile-nav-link" onclick="mobileSwitchTab('tab-orders-history')">🧾 Lịch Sử Giao Dịch (<?= count($user_orders) ?>)</a>
                        <a href="#tab-wallet-view" class="mobile-nav-link" onclick="mobileSwitchTab('tab-wallet-view')">🪙 Ví Lượt Ảnh (<?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?>)</a>
                        <a href="#tab-cloud-storage" class="mobile-nav-link" onclick="mobileSwitchTab('tab-cloud-storage')">☁️ 2TOOLNE Cloud (Lưu Trữ)</a>
                        <a href="#tab-buy-key" class="mobile-nav-link" onclick="mobileSwitchTab('tab-buy-key')">🛒 Mua Gói Bản Quyền & Bảng Giá</a>
                        <a href="#tab-downloads" class="mobile-nav-link" onclick="mobileSwitchTab('tab-downloads')">📥 Tải Về Phần Mềm</a>
                        <a href="#tab-features-view" class="mobile-nav-link" onclick="mobileSwitchTab('tab-features-view')">💡 Góp Ý Tính Năng</a>
                        <a href="#tab-bugs-view" class="mobile-nav-link" onclick="mobileSwitchTab('tab-bugs-view')">🐞 Báo Lỗi Phần Mềm</a>
                        <a href="#tab-settings" class="mobile-nav-link" onclick="mobileSwitchTab('tab-settings')">⚙️ Cài Đặt Tài Khoản</a>
                        <a href="https://zalo.me/0326649304" target="_blank" class="mobile-nav-link" style="color:var(--emerald)">💬 Hỗ Trợ Zalo Kỹ Thuật (0326649304)</a>
                        <?php if (in_array($user_info['role'] ?? '', ['admin', 'super_admin', 'sales', 'tech_support', 'content_manager', 'custom'])): ?>
                            <a href="license_admin.php" class="mobile-nav-link" style="color:#60a5fa">👑 Trang Quản Trị Hệ Thống</a>
                        <?php endif; ?>
                    </nav>
                    <div style="margin-top:14px;display:flex;gap:8px">
                        <a href="?logout=1" class="btn btn-outline" style="width:100%;height:38px;color:var(--danger)">Đăng Xuất</a>
                    </div>
                <?php else: ?>
                    <nav class="mobile-nav-links">
                        <a href="#products" class="mobile-nav-link" onclick="toggleMobileMenu()">🚀 Sản Phẩm Phần Mềm</a>
                        <a href="#products" class="mobile-nav-link" onclick="toggleMobileMenu();switchProductTab('ptab-cloud', 'ptab-btn-cloud')">☁️ 2TOOLNE Cloud (Lưu Trữ)</a>
                        <a href="#downloads" class="mobile-nav-link" onclick="toggleMobileMenu();openModal('modal-login'); return false;">📥 Tải Về Bộ Cài Đặt</a>
                        <a href="#pricing" class="mobile-nav-link" onclick="toggleMobileMenu()">🏷️ Bảng Giá Dịch Vụ</a>
                        <a href="https://zalo.me/0326649304" target="_blank" class="mobile-nav-link" style="color:var(--emerald)">💬 Hỗ Trợ Zalo Kỹ Thuật</a>
                    </nav>
                    <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px">
                        <button class="btn btn-outline" style="width:100%;height:40px" onclick="toggleMobileMenu();openModal('modal-login')">🔐 Đăng Nhập Tài Khoản</button>
                        <button class="btn btn-primary" style="width:100%;height:40px" onclick="toggleMobileMenu();openModal('modal-register')">🎁 Nhận Key + 50 Lượt Ảnh</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- FLASH ALERTS -->
        <?php if ($msg_success): ?>
            <div class="alert alert-success" style="margin-top:20px"><?= $msg_success ?></div>
        <?php endif; ?>
        <?php if ($msg_error): ?>
            <div class="alert alert-danger" style="margin-top:20px"><?= $msg_error ?></div>
        <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════════
                 LOGGED-IN USER ACCOUNT PORTAL / DASHBOARD
                 ═══════════════════════════════════════════════════════════════ -->
            
            <!-- PENDING ORDERS POLLING BANNER -->
            <?php if (!empty($user_pending_orders)): ?>
                <?php foreach ($user_pending_orders as $p_ord): 
                    $is_tok = (($p_ord['product'] ?? '') === 'TOKEN_WALLET' || stripos($p_ord['package_name'] ?? '', 'Token') !== false);
                ?>
                    <div class="alert alert-warning" style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div>
                            ⏳ <b>Đơn hàng đang chờ xử lý:</b> Đơn mua <b><?= htmlspecialchars($p_ord['package_name']) ?> (<?= htmlspecialchars($p_ord['package_price']) ?>)</b>.<br>
                            <?php if ($is_tok): ?>
                                🪙 Lượt phóng to ảnh sẽ tự động được cộng vào <b>"Ví Lượt Ảnh (Upscale)"</b> ngay khi hoàn tất.
                            <?php else: ?>
                                🔑 Khóa bản quyền sẽ tự động xuất hiện tại mục <b>"Bản Quyền Của Tôi"</b> ngay khi hoàn tất.
                            <?php endif; ?>
                            (Hệ thống tự động kiểm tra sau <b id="poll-sec">10</b>s...)
                        </div>
                        <a href="https://zalo.me/0326649304" target="_blank" class="btn btn-outline btn-xs" style="border-color:var(--warning);color:var(--warning)">
                            💬 Hỗ Trợ Kích Hoạt Nhanh
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- PENDING TEAM INVITES BANNER -->
            <?php if (!empty($user_pending_invites)): ?>
                <?php foreach ($user_pending_invites as $invite): ?>
                    <div class="alert alert-info" style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;background:linear-gradient(135deg, rgba(56,189,248,0.12), rgba(16,185,129,0.12));border:1px solid rgba(56,189,248,0.35);border-radius:12px;padding:14px 18px">
                        <div style="display:flex;align-items:center;gap:12px">
                            <span style="font-size:26px">👥</span>
                            <div>
                                <div style="font-size:14px;font-weight:700;color:var(--foreground)">
                                    🎉 Bạn nhận được lời mời tham gia Đội Nhóm: <span style="color:#38bdf8"><?= htmlspecialchars($invite['team_name']) ?></span>
                                </div>
                                <div style="font-size:12.5px;color:var(--muted-foreground);margin-top:2px">
                                    Lời mời từ Trưởng nhóm <b><?= htmlspecialchars($invite['owner_fullname'] ?: $invite['owner_username']) ?></b> (@<?= htmlspecialchars($invite['owner_username']) ?>). Khi tham gia, bạn sẽ được dùng chung bộ nhớ Cloud và không gian làm việc của Team!
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="respond_team_invite">
                                <input type="hidden" name="invite_id" value="<?= htmlspecialchars($invite['invite_id']) ?>">
                                <input type="hidden" name="decision" value="ACCEPT">
                                <button type="submit" class="btn btn-emerald btn-sm" style="font-weight:700">
                                    <span>✅</span> Đồng Ý Tham Gia
                                </button>
                            </form>
                            <form method="POST" style="margin:0" onsubmit="return confirm('Bạn có chắc chắn muốn từ chối lời mời vào nhóm này?')">
                                <input type="hidden" name="action" value="respond_team_invite">
                                <input type="hidden" name="invite_id" value="<?= htmlspecialchars($invite['invite_id']) ?>">
                                <input type="hidden" name="decision" value="REJECT">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger)">
                                    <span>❌</span> Từ Chối
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="dash-layout">
                <!-- SIDEBAR -->
                <aside class="dash-sidebar">
                    <div class="dash-user-card">
                        <div class="dash-user-name"><?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?></div>
                        <div class="dash-user-sub">@<?= htmlspecialchars($user_info['username']) ?></div>
                    </div>
                    <nav class="dash-nav">
                        <button class="dash-nav-btn active" id="btn-tab-keys" onclick="switchMainTab('tab-my-keys')">
                            <span>🔑</span> Bản Quyền Của Tôi
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-orders" onclick="switchMainTab('tab-orders-history')">
                            <span>🧾</span> Lịch Sử Giao Dịch (<?= count($user_orders) ?>)
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-wallet" onclick="switchMainTab('tab-wallet-view')">
                            <span>🪙</span> Ví Lượt Ảnh Upscale (<?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?>)
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-cloud" onclick="switchMainTab('tab-cloud-storage')">
                            <span>☁️</span> 2TOOLNE Cloud (Lưu Trữ)
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-downloads" onclick="switchMainTab('tab-downloads')">
                            <span>📥</span> Tải Phần Mềm
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-buy" onclick="switchMainTab('tab-buy-key')">
                            <span>🛒</span> Mua Gói Bản Quyền
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-features" onclick="switchMainTab('tab-features-view')">
                            <span>💡</span> Góp Ý Tính Năng
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-bugs" onclick="switchMainTab('tab-bugs-view')">
                            <span>🐞</span> Báo Lỗi Phần Mềm
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-settings" onclick="switchMainTab('tab-settings')">
                            <span>⚙️</span> Cài Đặt Tài Khoản
                        </button>
                    </nav>
                </aside>

                <!-- MAIN WORKSPACE -->
                <div class="dash-main">

                    <!-- TAB 1: MY KEYS -->
                    <div id="tab-my-keys" class="tab-pane active">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🔑 Danh Sách Bản Quyền Đã Sở Hữu</div>
                                <button class="btn btn-emerald btn-sm" onclick="switchMainTab('tab-buy-key')">+ Mua Bản Quyền Mới</button>
                            </div>
                            <div class="card-body" style="padding:0">
                                <?php 
                                $my_keys = $user_info['keys'] ?? [];
                                if (empty($my_keys)): 
                                ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🔑</div>
                                        <div class="empty-state-title">Chưa Có Bản Quyền Phần Mềm Nào</div>
                                        <div class="empty-state-desc">Bạn chưa kích hoạt mã bản quyền nào trên tài khoản. Hãy bấm sang tab "Mua Gói Bản Quyền" để lựa chọn phần mềm phù hợp.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
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
                                                    $is_ext = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos($k, '2TAMNE-LABS-') === 0;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:6px">
                                                                <code class="font-mono" style="color:var(--info);font-size:12.5px;font-weight:600"><?= htmlspecialchars($k) ?></code>
                                                                <button class="btn btn-outline btn-xs" onclick="copyText('<?= htmlspecialchars($k) ?>')" title="Sao chép">📋</button>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($is_capcut): ?>
                                                                <span class="badge" style="background:#7c3aed;color:#fff">🎬 CapCut AutoEdit V2</span>
                                                            <?php elseif ($is_2toolne): ?>
                                                                <span class="badge badge-active">🚀 2toolne Studio</span>
                                                            <?php elseif ($is_ext): ?>
                                                                <span class="badge badge-info">🖼️ Labs Extension</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-purple">🎬 Slideshow AI</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?= $tier === 'TRIAL' ? 'badge-trial' : 'badge-active' ?>"><?= $tier === 'TRIAL' ? 'DÙNG THỬ' : ($tier === 'LIFETIME' ? 'VĨNH VIỄN' : htmlspecialchars($tier)) ?></span>
                                                        </td>
                                                        <td style="font-size:12px;color:var(--muted-foreground)">
                                                            <?= $lic['expires_at'] ? (strpos($lic['expires_at'], '2099') !== false ? '👑 Vĩnh viễn' : htmlspecialchars($lic['expires_at'])) : ($lic['duration_days'] . ' ngày') ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-danger' ?>">
                                                                <?= $status === 'active' ? 'ĐANG DÙNG' : 'HẾT HẠN' ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($hwid): ?>
                                                                <div style="font-size:11.5px">
                                                                    <code style="color:var(--info)"><?= substr($hwid, 0, 10) ?>...</code>
                                                                    <div style="color:var(--muted-subtle)"><?= htmlspecialchars($lic['device_name'] ?: 'Máy tính cá nhân') ?></div>
                                                                </div>
                                                            <?php else: ?>
                                                                <span style="color:var(--emerald);font-size:12px">Chưa kích hoạt (Sẵn sàng)</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                                                <?php if ($is_capcut): ?>
                                                                    <button type="button" class="btn btn-emerald btn-xs" title="Tải 2TOOLNE AutoEdit cho macOS (.dmg)" onclick="requestSecureDownload('AUTOEDIT', 'macos')">🍎 Mac</button>
                                                                    <button type="button" class="btn btn-accent btn-xs" title="Tải 2TOOLNE AutoEdit cho Windows (.exe)" onclick="requestSecureDownload('AUTOEDIT', 'windows-x64')">🪟 Win</button>
                                                                <?php elseif ($is_2toolne): ?>
                                                                    <button type="button" class="btn btn-emerald btn-xs" title="Tải cho macOS" onclick="requestSecureDownload('SLIDESHOW', 'macos')">🍎 Mac</button>
                                                                    <button type="button" class="btn btn-accent btn-xs" title="Tải cho Windows" onclick="requestSecureDownload('SLIDESHOW', 'windows-x64')">🪟 Win</button>
                                                                <?php elseif ($is_ext): ?>
                                                                    <button type="button" class="btn btn-outline btn-xs" title="Tải Labs Extension" onclick="requestSecureDownload('EXTENSION', 'all')">🧩 Extension</button>
                                                                <?php else: ?>
                                                                    <button type="button" class="btn btn-emerald btn-xs" title="Tải Slideshow AI cho macOS" onclick="requestSecureDownload('SLIDESHOW', 'macos')">🍎 Mac</button>
                                                                    <button type="button" class="btn btn-accent btn-xs" title="Tải Slideshow AI cho Windows" onclick="requestSecureDownload('SLIDESHOW', 'windows-x64')">🪟 Win</button>
                                                                <?php endif; ?>
                                                                <?php if ($hwid): ?>
                                                                    <button type="button" class="btn btn-outline btn-xs" style="color:var(--info);border-color:var(--info)" onclick="openResetHwidModal('<?= htmlspecialchars($k) ?>')">
                                                                        🔄 Đổi Thiết Bị
                                                                    </button>
                                                                <?php else: ?>
                                                                    <span class="text-subtle" style="font-size:11px">Sẵn sàng</span>
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
                    </div>

                    <!-- TAB: USER ORDERS & TRANSACTIONS HISTORY -->
                    <div id="tab-orders-history" class="tab-pane" style="display:none">
                        <div class="card">
                            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div class="card-title">🧾 Lịch Sử Giao Dịch & Đơn Hàng Của Bạn</div>
                                    <span class="badge badge-info" id="user-order-filter-status">Tất cả (<?= count($user_orders) ?>)</span>
                                </div>
                                <button class="btn btn-emerald btn-xs" onclick="switchMainTab('tab-buy-key')">+ Mua Bản Quyền Mới</button>
                            </div>

                            <!-- DATE & STATUS FILTER TOOLBAR -->
                            <div style="padding:12px 16px;background:var(--surface-2);border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                                    <span style="font-size:12px;font-weight:600;color:var(--muted-foreground);display:inline-flex;align-items:center;gap:4px">
                                        📅 Duyệt theo ngày:
                                    </span>
                                    <div style="display:flex;align-items:center;gap:6px">
                                        <input type="date" id="user-filter-date-from" class="form-input" style="font-size:12px;padding:5px 8px;width:130px" onchange="filterUserOrderTable()" title="Từ ngày">
                                        <span style="color:var(--muted-foreground);font-size:12px">→</span>
                                        <input type="date" id="user-filter-date-to" class="form-input" style="font-size:12px;padding:5px 8px;width:130px" onchange="filterUserOrderTable()" title="Đến ngày">
                                    </div>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                                        <button type="button" class="btn btn-outline btn-xs filter-date-preset-user" onclick="setUserDatePreset('today', this)">Hôm nay</button>
                                        <button type="button" class="btn btn-outline btn-xs filter-date-preset-user" onclick="setUserDatePreset('yesterday', this)">Hôm qua</button>
                                        <button type="button" class="btn btn-outline btn-xs filter-date-preset-user" onclick="setUserDatePreset('7days', this)">7 ngày qua</button>
                                        <button type="button" class="btn btn-outline btn-xs filter-date-preset-user" onclick="setUserDatePreset('this_month', this)">Tháng này</button>
                                        <button type="button" class="btn btn-outline btn-xs filter-date-preset-user active" onclick="setUserDatePreset('all', this)">Tất cả</button>
                                    </div>
                                </div>

                                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                                    <select id="user-filter-status" class="form-input" style="font-size:12px;padding:5px 8px;width:135px" onchange="filterUserOrderTable()">
                                        <option value="">Tất cả trạng thái</option>
                                        <option value="pending">⏳ Chờ duyệt</option>
                                        <option value="approved">✅ Đã duyệt</option>
                                        <option value="rejected">❌ Đã hủy</option>
                                    </select>
                                    <input type="text" id="user-order-search" class="form-input" style="font-size:12px;padding:5px 10px;width:170px" placeholder="🔍 Tìm mã đơn, gói..." onkeyup="filterUserOrderTable()">
                                </div>
                            </div>

                            <div class="card-body" style="padding:0">
                                <?php if (empty($user_orders)): ?>
                                    <div class="empty-state" style="padding:32px;text-align:center">
                                        <div class="empty-state-icon" style="font-size:32px;margin-bottom:8px">🧾</div>
                                        <div class="empty-state-title" style="font-size:15px;font-weight:700">Chưa Có Lịch Sử Giao Dịch</div>
                                        <div class="empty-state-desc" style="font-size:13px;color:var(--muted-foreground);margin-top:4px">Bạn chưa phát sinh đơn đặt mua bản quyền phần mềm hoặc nạp token nào.</div>
                                        <div style="margin-top:14px">
                                            <button class="btn btn-emerald btn-sm" onclick="switchMainTab('tab-buy-key')">🛒 Mua Bản Quyền Ngay</button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="data-table" id="user-orders-table">
                                            <thead>
                                                <tr>
                                                    <th>Mã Đơn</th>
                                                    <th>Sản Phẩm / Gói</th>
                                                    <th>Số Tiền</th>
                                                    <th>Thời Gian Tạo</th>
                                                    <th>Trạng Thái</th>
                                                    <th>Kết Quả / Key Cấp</th>
                                                </tr>
                                            </thead>
                                            <tbody id="user-orders-tbody">
                                                <?php foreach ($user_orders as $u_ord): 
                                                    $st = $u_ord['status'] ?? 'pending';
                                                    $dt = substr($u_ord['created_at'] ?? '', 0, 10);
                                                    $search_str = strtolower(($u_ord['id'] ?? '') . ' ' . ($u_ord['package_name'] ?? '') . ' ' . ($u_ord['package_price'] ?? '') . ' ' . ($u_ord['issued_key'] ?? '') . ' ' . ($u_ord['product'] ?? ''));
                                                ?>
                                                <tr class="user-order-row" data-date="<?= $dt ?>" data-status="<?= htmlspecialchars($st) ?>" data-search="<?= htmlspecialchars($search_str) ?>">
                                                    <td><code class="font-mono text-primary" style="font-weight:700"><?= htmlspecialchars($u_ord['id']) ?></code></td>
                                                    <td>
                                                        <b><?= htmlspecialchars($u_ord['package_name']) ?></b>
                                                        <div style="font-size:11px"><span class="badge"><?= htmlspecialchars($u_ord['product'] ?? 'SLIDESHOW') ?></span> · <?= $u_ord['duration_days'] ?> ngày</div>
                                                    </td>
                                                    <td><b style="color:var(--emerald)"><?= htmlspecialchars($u_ord['package_price']) ?></b></td>
                                                    <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($u_ord['created_at']) ?></td>
                                                    <td>
                                                        <?php if ($st === 'approved'): ?>
                                                            <span class="badge badge-active">✅ ĐÃ DUYỆT</span>
                                                        <?php elseif ($st === 'rejected'): ?>
                                                            <span class="badge badge-danger">❌ ĐÃ HỦY</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning" style="animation:pulseGlow 2s infinite">⏳ CHỜ DUYỆT</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($u_ord['issued_key'])): ?>
                                                            <div style="display:flex;align-items:center;gap:6px">
                                                                <code class="font-mono" style="font-size:11.5px;color:var(--emerald);font-weight:700"><?= htmlspecialchars($u_ord['issued_key']) ?></code>
                                                                <button type="button" class="btn btn-outline btn-xs" onclick="copyText('<?= htmlspecialchars($u_ord['issued_key']) ?>')" title="Sao chép Key">📋</button>
                                                            </div>
                                                        <?php elseif ($st === 'approved'): ?>
                                                            <span class="badge badge-active" style="font-size:11px">🪙 ĐÃ CỘNG VÀO VÍ</span>
                                                        <?php elseif ($st === 'rejected'): ?>
                                                            <span class="text-subtle" style="font-size:11.5px">Đơn đã bị hủy</span>
                                                        <?php else: ?>
                                                            <span class="text-subtle" style="font-size:11.5px;color:var(--warning)">Đang chờ đối soát</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div id="user-orders-no-match" class="empty-state" style="display:none;padding:24px;text-align:center;color:var(--muted-foreground);font-size:13px">
                                        🔍 Không tìm thấy giao dịch nào phù hợp với bộ lọc ngày hoặc từ khóa tìm kiếm.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 1.2: WALLET & TOKENS (VÍ & NẠP TOKEN UPSCALE) -->
                    <div id="tab-wallet-view" class="tab-pane" style="display:none">
                        <!-- QUICK DOWNLOAD BANNER FOR UPSCALE APP -->
                        <div class="card" style="margin-bottom:24px;border-color:rgba(250, 204, 21, 0.4);background:linear-gradient(135deg, rgba(250, 204, 21, 0.1) 0%, var(--surface-1) 100%)">
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div style="flex:1;min-width:280px">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                                        <span class="badge" style="background:#facc15;color:#000;font-weight:800">CÀI ĐẶT ỨNG DỤNG</span>
                                        <span class="badge badge-info">v1.1.2 Stable</span>
                                    </div>
                                    <h4 style="font-size:17px;margin:0 0 4px">Tải Ứng Dụng 2toolne Upscale 4K Về Máy Tính</h4>
                                    <p class="text-muted" style="margin:0;font-size:13px">Sử dụng số dư <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> lượt ảnh của bạn để phóng to ảnh 2K & 4K siêu nét bằng AI on-device trên Windows và macOS.</p>
                                </div>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                                    <div style="display:flex;gap:6px">
                                        <button type="button" class="btn btn-accent btn-sm" title="Tải Cài đặt .exe cho Windows (149 MB)" onclick="requestSecureDownload('UPSCALE', 'windows-x64')">🪟 Windows (.exe 149 MB)</button>
                                    </div>
                                    <div style="display:flex;gap:6px">
                                        <button type="button" class="btn btn-emerald btn-sm" title="Tải Gói .dmg cho Mac (125 MB)" onclick="requestSecureDownload('UPSCALE', 'macos')">🍏 Mac (.dmg 125 MB)</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STATS ROW -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px">
                            <div class="card" style="padding:18px;border-color:rgba(234, 179, 8, 0.35);background:linear-gradient(135deg, rgba(234, 179, 8, 0.08) 0%, var(--surface-1) 100%)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">🪙 Số Dư Lượt Khả Dụng</div>
                                <div style="font-size:28px;font-weight:800;color:#facc15">
                                    <span id="wallet-page-token-balance"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span> <span style="font-size:14px;font-weight:600;color:var(--foreground)">Tokens (Lượt)</span>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Dùng để phóng to ảnh 2K & 4K siêu nét</div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">⏳ Đang Xử Lý (Render)</div>
                                <div style="font-size:28px;font-weight:800;color:var(--info)">
                                    <?= number_format($user_wallet['reserved'] ?? 0, 0, ',', '.') ?> <span style="font-size:14px;font-weight:600;color:var(--foreground)">Lượt</span>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Lượt ảnh đang trong tiến trình xuất</div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">🛡️ Hình Thức Trừ Phí</div>
                                <div style="font-size:24px;font-weight:800;color:<?= ($user_wallet['credit_mode'] ?? '') === 'UNLIMITED' ? 'var(--emerald)' : 'var(--primary)' ?>">
                                    <?= ($user_wallet['credit_mode'] ?? '') === 'UNLIMITED' ? 'GÓI KHÔNG GIỚI HẠN' : 'THEO LƯỢT ẢNH XUẤT' ?>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px"><?= ($user_wallet['credit_mode'] ?? '') === 'UNLIMITED' ? 'Thoải mái xuất ảnh 24/7' : 'Chỉ trừ khi xuất ảnh thành công' ?></div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">👑 Hạng Tài Khoản</div>
                                <div style="font-size:24px;font-weight:800;color:var(--foreground)">
                                    <?= strtoupper(htmlspecialchars($user_wallet['plan'] ?? 'FREE')) ?>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Gói dịch vụ kích hoạt</div>
                            </div>
                        </div>

                        <!-- RULE EXPLANATION -->
                        <div class="card" style="margin-bottom:24px;border-color:rgba(59, 130, 246, 0.3);background:linear-gradient(180deg, rgba(59, 130, 246, 0.06) 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span style="font-size:20px">💡</span>
                                    <h4 style="font-size:15px;margin:0">Quy Tắc Tính Lượt Phóng To Ảnh & Cam Kết Bảo Đảm Quyền Lợi</h4>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;font-size:13px;color:var(--muted-foreground);margin-top:10px">
                                    <div>🖼️ <b>Ảnh Phân Giải 2K:</b> Tiêu thụ <b>1 Lượt (Token)</b> / ảnh xuất thành công.</div>
                                    <div>🌟 <b>Ảnh Phân Giải 4K Ultra HD:</b> Tiêu thụ <b>2 Lượt (Tokens)</b> / ảnh xuất thành công.</div>
                                    <div>🔒 <b>Cam kết an toàn tuyệt đối:</b> Hệ thống chỉ trừ số dư khi ảnh đã xuất xong về máy tính của bạn. Nếu gặp sự cố mạng hay tắt ứng dụng, toàn bộ lượt đang xử lý sẽ được tự động hoàn lại 100%! Không bao giờ bị trừ oan.</div>
                                </div>
                            </div>
                        </div>

                        <!-- TOKEN PACKAGES -->
                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🛒 Mua Thêm Gói Lượt Phóng To Ảnh Upscale 4K</div>
                                <span class="badge badge-active">Quét VietQR Tự Động 24/7</span>
                            </div>
                            <div class="card-body">
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <!-- PACKAGE 1: STARTER -->
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Starter (1.000 Lượt)</h4>
                                            <div class="price-val" style="color:#facc15">100.000đ</div>
                                            <div class="price-sub">Chi phí: 100đ / lượt • Không hạn dùng</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>1.000 Lượt (Tokens)</b> vào ví ngay lập tức</li>
                                                <li>🖼️ Tương đương 1.000 ảnh 2K hoặc 500 ảnh 4K</li>
                                                <li>⚡ Phù hợp trải nghiệm sáng tạo cá nhân</li>
                                                <li>✅ Lượt ảnh vĩnh viễn không bao giờ hết hạn</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#facc15;color:#facc15" onclick="openQrPayment('Gói 1.000 Token (Starter)', '100.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 100.000đ</button>
                                    </div>

                                    <!-- PACKAGE 2: PRO CREATOR -->
                                    <div class="pricing-card featured" style="border-color:#facc15">
                                        <span class="badge pricing-card-badge" style="background:#facc15;color:#000;font-weight:800">TẶNG 500 LƯỢT</span>
                                        <div>
                                            <h4>Gói Pro Creator (3.500 Lượt)</h4>
                                            <div class="price-val" style="color:#facc15">250.000đ</div>
                                            <div class="price-sub">Chi phí: ~71đ / lượt (Đã gồm 500 lượt tặng)</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>3.500 Lượt</b> (3.000 gốc + 500 lượt tặng)</li>
                                                <li>🖼️ Tương đương 3.500 ảnh 2K hoặc 1.750 ảnh 4K</li>
                                                <li>⚡ Dành cho Content Creator làm kênh thường xuyên</li>
                                                <li>✅ Lượt ảnh vĩnh viễn, không có hạn sử dụng</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-emerald" onclick="openQrPayment('Gói 3.000 Token (Pro Creator)', '250.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 250.000đ</button>
                                    </div>

                                    <!-- PACKAGE 3: STUDIO PACK -->
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Studio (13.000 Lượt)</h4>
                                            <div class="price-val" style="color:#facc15">700.000đ</div>
                                            <div class="price-sub">Chi phí: ~54đ / lượt (Tiết kiệm tới 45%)</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>13.000 Lượt</b> (10.000 gốc + 3.000 lượt tặng)</li>
                                                <li>🖼️ Tương đương 13.000 ảnh 2K hoặc 6.500 ảnh 4K</li>
                                                <li>⚡ Dành cho Studio & Team sản xuất lớn</li>
                                                <li>👑 Ưu tiên tốc độ xử lý GPU cao nhất</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#facc15;color:#facc15" onclick="openQrPayment('Gói 10.000 Token (Studio)', '700.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 700.000đ</button>
                                    </div>

                                    <!-- PACKAGE 4: UNLIMITED STUDIO -->
                                    <div class="pricing-card" style="border-color:var(--purple-500)">
                                        <div>
                                            <span class="badge badge-purple" style="margin-bottom:8px">KHÔNG GIỚI HẠN</span>
                                            <h4>Unlimited Studio (30 Ngày)</h4>
                                            <div class="price-val" style="color:var(--purple-400)">1.800.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>👑 <b>Không giới hạn số lượng ảnh 2K / 4K</b></li>
                                                <li>🚀 Render liên tục 24/7 không trừ số dư lượt</li>
                                                <li>⚡ Tối ưu cho xưởng sản xuất video công nghiệp</li>
                                                <li>✅ Hỗ trợ kỹ thuật ưu tiên 1-1 từ chuyên viên 2TOOL</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-primary" onclick="openQrPayment('Gói Unlimited Studio (30 Ngày)', '1.800.000đ', 30, 'UNLIMITED', 'TOKEN_WALLET')">👑 Mua Gói Unlimited</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TOKEN TRANSACTIONS HISTORY -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📜 Lịch Sử Sử Dụng & Biến Động Lượt Ảnh</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <?php if (empty($user_tokens_tx)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🪙</div>
                                        <div class="empty-state-title">Chưa Có Biến Động Lượt Ảnh Nào</div>
                                        <div class="empty-state-desc">Tài khoản chưa phát sinh giao dịch nạp hoặc tiêu thụ lượt ảnh Upscale 4K.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Mã GD</th>
                                                    <th>Thời Gian</th>
                                                    <th>Loại Tác Vụ</th>
                                                    <th>Biến Động</th>
                                                    <th>Số Dư Cuối</th>
                                                    <th>Nội Dung / Lý Do</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($user_tokens_tx as $tx): 
                                                    $d = (int)($tx['delta'] ?? 0);
                                                    $t_type = $tx['type'] ?? 'N/A';
                                                    $delta_str = ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') . ' Lượt';
                                                    $delta_color = $d > 0 ? 'var(--emerald)' : ($d < 0 ? 'var(--danger)' : 'var(--muted-foreground)');
                                                ?>
                                                    <tr>
                                                        <td><code style="font-size:11.5px">#<?= htmlspecialchars($tx['id']) ?></code></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($tx['created_at']) ?></td>
                                                        <td>
                                                            <?php 
                                                                $t_upper = strtoupper((string)$t_type);
                                                                $desc = $tx['reason'] ?: ($tx['description'] ?? ($tx['metadata_json'] ?: '-'));
                                                                if ($desc === 'Local image upscale completed') {
                                                                    $desc = ($d === -2) ? '4K upscale completed' : (($d === -1) ? '2K upscale completed' : $desc);
                                                                }
                                                            ?>
                                                            <?php if ($t_upper === 'UPSCALE 4K' || ($t_upper === 'UPSCALE' && $d === -2)): ?>
                                                                <span class="badge badge-purple" style="font-weight:700">🚀 Upscale 4K</span>
                                                            <?php elseif ($t_upper === 'UPSCALE 2K' || ($t_upper === 'UPSCALE' && $d === -1)): ?>
                                                                <span class="badge badge-info" style="font-weight:700">⚡ Upscale 2K</span>
                                                            <?php elseif ($t_upper === 'UPSCALE' || $t_upper === 'COMMIT'): ?>
                                                                <span class="badge badge-purple">🖼️ Xuất ảnh xong</span>
                                                            <?php elseif ($t_upper === 'RESERVE'): ?>
                                                                <span class="badge badge-warning">⏳ Đang xử lý</span>
                                                            <?php elseif ($t_upper === 'RELEASE' || $t_upper === 'RESERVATION_RELEASE'): ?>
                                                                <span class="badge badge-info">↩️ Hoàn lại lượt</span>
                                                            <?php elseif ($t_upper === 'PROMOTION'): ?>
                                                                <span class="badge badge-active">🎁 Quà tặng đăng ký</span>
                                                            <?php elseif ($t_upper === 'UPGRADE' || $t_upper === 'TOPUP' || $t_upper === 'ADMIN_ADJUSTMENT'): ?>
                                                                <span class="badge badge-active">💳 Biến động token</span>
                                                            <?php else: ?>
                                                                <span class="badge"><?= htmlspecialchars($t_type) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="font-weight:700;color:<?= $delta_color ?>"><?= $delta_str ?></td>
                                                        <td><b><?= number_format($tx['balance_after'] ?? 0, 0, ',', '.') ?> Lượt</b></td>
                                                        <td style="font-size:12.5px;color:var(--muted-foreground)">
                                                            <?= htmlspecialchars($desc) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 1.3: 2TOOLNE CLOUD STORAGE -->
                    <div id="tab-cloud-storage" class="tab-pane" style="display:none">
                        <!-- HEADER & SPACE SELECTOR -->
                        <div class="cloud-header-box">
                            <div class="cloud-header-bar">
                                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                                    <h3 style="font-size:18px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;letter-spacing:-0.02em">
                                        <span style="font-size:20px">☁️</span> 2TOOLNE Cloud Storage
                                    </h3>
                                    <div id="cloud-space-status-badge" class="cloud-status-pulse">
                                        <span class="cloud-pulse-dot"></span>
                                        <span id="cloud-status-text">Đang Hoạt Động</span>
                                    </div>
                                    <span class="text-muted" style="font-size:12.5px">Lưu trữ đám mây 2TOOL Cloud tốc độ cao, kết nối trực tiếp AI Upscale</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <div class="cloud-space-selector-wrap">
                                        <label style="font-size:11px;color:var(--muted-foreground);font-weight:700;letter-spacing:0.04em">KHÔNG GIAN:</label>
                                        <select id="cloud-space-select" class="cloud-space-selector" onchange="onCloudSpaceChanged(this.value)">
                                            <?php if (!empty($user_cloud_spaces)): ?>
                                                <?php foreach ($user_cloud_spaces as $sp): ?>
                                                    <option value="<?= htmlspecialchars($sp['id']) ?>" 
                                                            data-status="<?= htmlspecialchars($sp['status']) ?>"
                                                            data-owner-type="<?= htmlspecialchars($sp['owner_type']) ?>"
                                                            data-owner-id="<?= htmlspecialchars($sp['owner_id']) ?>"
                                                            data-user-role="<?= htmlspecialchars($sp['user_role'] ?? 'MEMBER') ?>"
                                                            data-space-name="<?= htmlspecialchars($sp['name']) ?>">
                                                        <?= ($sp['owner_type'] === 'TEAM' ? '👥 ' : '👤 ') . htmlspecialchars($sp['name']) ?> (<?= $sp['owner_type'] === 'TEAM' ? 'Team Space - ' . ($sp['user_role'] ?? 'MEMBER') : 'Cá Nhân' ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">(Chưa có không gian)</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <button class="btn btn-primary btn-sm" onclick="openCloudUploadModal()" id="btn-cloud-upload" style="box-shadow:0 0 15px rgba(56,189,248,0.25);font-weight:600">
                                        <span>⬆️</span> Tải Tệp Lên
                                    </button>
                                    <button class="btn btn-outline btn-sm" onclick="promptCreateCloudFolder()" id="btn-cloud-mkdir">
                                        <span>📁</span> Tạo Thư Mục
                                    </button>
                                    <button class="btn btn-outline btn-sm" onclick="openCloudTrashModal()">
                                        <span>🗑️</span> Thùng Rác (<span id="cloud-trash-badge">0</span>)
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="openModal('modal-cloud-buy-quota')" style="font-weight:700;background:linear-gradient(135deg, #f59e0b, #d97706);border:none;color:#fff;box-shadow:0 0 12px rgba(245,158,11,0.25)">
                                        <span>⚡</span> Mua Dung Lượng
                                    </button>
                                    <button class="btn btn-emerald btn-sm" onclick="openModal('modal-cloud-create-team')" style="font-weight:700;background:linear-gradient(135deg, #10b981, #059669);border:none;color:#fff;box-shadow:0 0 12px rgba(16,185,129,0.25)">
                                        <span>🏢</span> Khởi Tạo Team Cloud
                                    </button>
                                    <button class="btn btn-outline btn-sm" id="btn-cloud-team-manage" onclick="openTeamManagementModal()" style="display:none;font-weight:600;border-color:#38bdf8;color:#38bdf8">
                                        <span>👥</span> Thành Viên Team
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- OVER-QUOTA BANNER (Hidden by default, toggled via JS) -->
                        <div id="cloud-overquota-banner" class="cloud-overquota-banner" style="display:none">
                            <span style="font-size:22px;line-height:1">⚠️</span>
                            <div style="flex:1">
                                <b style="color:#ef4444;font-size:14px">Cảnh báo: Không gian lưu trữ đã vượt hạn mức dung lượng!</b>
                                <div style="margin-top:4px">
                                    Không gian này hiện đang ở chế độ <b>Chỉ Đọc (Read-only)</b>. Bạn tạm thời không thể tải thêm tệp mới hoặc tạo thư mục.
                                    <br><span style="color:#10b981;font-weight:600">🛡️ Lưu ý an toàn:</span> Hàng đợi GPU xử lý tác vụ Upscale AI của ứng dụng máy tính vẫn hoạt động bình thường và <b>không bị gián đoạn</b>. Hãy dọn bớt file trong Thùng Rác hoặc bấm "Mua Dung Lượng" để mở rộng dung lượng!
                                </div>
                            </div>
                        </div>

                        <!-- SLEEK QUOTA STRIP (Compact 1-row dashboard) -->
                        <div class="cloud-quota-strip">
                            <div class="cloud-quota-strip-left">
                                <div style="display:flex;align-items:center;gap:8px;font-size:12.5px">
                                    <span style="font-weight:700;color:var(--muted-foreground)">💾 DUNG LƯỢNG:</span>
                                    <span id="cloud-quota-usage-text" style="font-weight:700;font-family:var(--font-mono);color:var(--foreground)">0 B</span>
                                    <span style="color:var(--muted-subtle)">/</span>
                                    <span id="cloud-quota-total-text" style="font-weight:600;font-family:var(--font-mono);color:var(--muted-foreground)">5 GB</span>
                                    <span id="cloud-quota-percent" style="font-weight:800;font-family:var(--font-mono);color:var(--emerald);margin-left:4px">0%</span>
                                </div>
                                <div class="cloud-quota-track-strip">
                                    <div id="cloud-quota-fill-bar" class="cloud-quota-fill" style="width: 0%"></div>
                                </div>
                            </div>
                            <div class="cloud-quota-strip-right">
                                <div class="cloud-chip-compact">
                                    <span class="text-muted">Còn Trống:</span>
                                    <b id="cloud-chip-free" style="color:var(--emerald)">--</b>
                                </div>
                                <div class="cloud-chip-compact">
                                    <span class="text-muted">Tổng Số Tệp:</span>
                                    <b id="cloud-chip-files">0</b>
                                </div>
                                <div class="cloud-chip-compact" id="cloud-quota-status-sub">
                                    <span class="text-muted">Trạng Thái:</span>
                                    <b style="color:var(--emerald)">Bình Thường</b>
                                </div>
                                <!-- Hidden elements kept for JS compatibility -->
                                <span id="cloud-chip-quota" style="display:none">--</span>
                                <span id="cloud-chip-used" style="display:none">--</span>
                                <span id="cloud-quota-reserved-wrapper" style="display:none;color:#38bdf8;font-size:11.5px">
                                    ⏳ Đang đẩy lên: <b id="cloud-quota-reserved-text">0 B</b>
                                </span>
                            </div>
                        </div>

                        <!-- EXPLORER TOOLBAR V2 (Integrated 1 Row) -->
                        <div class="cloud-toolbar-v2">
                            <div class="cloud-breadcrumbs" id="cloud-breadcrumbs-container">
                                <span class="cloud-crumb active" onclick="navigateCloudFolder(null)">🏠 Thư mục gốc</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <div class="cloud-search-box">
                                    <span style="font-size:13px;color:var(--muted-foreground)">🔍</span>
                                    <input type="text" id="cloud-search-input" class="cloud-search-input" placeholder="Tìm kiếm tệp..." oninput="onCloudSearchInput(this.value)">
                                </div>
                                <div class="cloud-filter-pills">
                                    <button type="button" class="cloud-filter-pill active" data-cat="all" onclick="setCloudCategoryFilter('all')">Tất cả</button>
                                    <button type="button" class="cloud-filter-pill" data-cat="images" onclick="setCloudCategoryFilter('images')">🖼️ Ảnh</button>
                                    <button type="button" class="cloud-filter-pill" data-cat="videos" onclick="setCloudCategoryFilter('videos')">🎬 Video</button>
                                    <button type="button" class="cloud-filter-pill" data-cat="archives" onclick="setCloudCategoryFilter('archives')">📦 Nén</button>
                                    <button type="button" class="cloud-filter-pill" data-cat="docs" onclick="setCloudCategoryFilter('docs')">📄 Tài liệu</button>
                                </div>
                                <div class="cloud-view-toggle">
                                    <button type="button" id="btn-cloud-view-list" class="cloud-view-btn active" onclick="setCloudViewMode('list')" title="Dạng danh sách">
                                        <span>☰</span>
                                    </button>
                                    <button type="button" id="btn-cloud-view-grid" class="cloud-view-btn" onclick="setCloudViewMode('grid')" title="Dạng lưới thẻ">
                                        <span>☵</span>
                                    </button>
                                    <button type="button" class="cloud-view-btn" onclick="refreshCloudView()" title="Làm mới">
                                        <span>🔄</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- EXPLORER CONTENT -->
                        <div id="cloud-explorer-loading" style="display:none;text-align:center;padding:50px;color:var(--muted-foreground)">
                            <div style="font-size:28px;margin-bottom:10px;animation:spin 1.5s linear infinite">⏳</div>
                            <div style="font-weight:600">Đang đồng bộ dữ liệu đám mây...</div>
                        </div>

                        <div id="cloud-explorer-content">
                            <!-- FOLDERS LIST -->
                            <div id="cloud-folders-section" style="margin-bottom:20px;display:none">
                                <div style="font-size:12px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;margin-bottom:12px;letter-spacing:0.04em">Thư Mục</div>
                                <div class="cloud-grid" id="cloud-folders-grid"></div>
                            </div>

                            <!-- EMPTY DROPZONE (Shown when no files in current view) -->
                            <div id="cloud-empty-dropzone" class="cloud-empty-dropzone" style="display:none" onclick="openCloudUploadModal()">
                                <div class="cloud-dropzone-icon">☁️</div>
                                <div style="font-size:16px;font-weight:700;color:var(--foreground);margin-bottom:6px">Kéo thả tệp tin vào đây hoặc bấm để Tải lên</div>
                                <div style="font-size:13px;color:var(--muted-foreground);max-width:440px;margin:0 auto">
                                    Hỗ trợ tải lên đa tệp cùng lúc trực tiếp vào 2TOOL Cloud qua cơ chế Stream tốc độ cao.
                                </div>
                            </div>

                            <!-- FILES LIST (TABLE VIEW) -->
                            <div id="cloud-files-table-section">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                                    <div style="font-size:12px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:0.04em">
                                        Tệp Tin (<span id="cloud-files-count-badge">0</span>)
                                    </div>
                                </div>
                                <div class="cloud-table-wrap">
                                    <table class="cloud-table-v2">
                                        <thead>
                                            <tr>
                                                <th style="min-width:240px">Tên Tệp</th>
                                                <th>Kích Thước</th>
                                                <th>Phân Loại</th>
                                                <th>Thời Gian</th>
                                                <th style="text-align:right">Thao Tác</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cloud-files-tbody">
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- FILES LIST (GRID VIEW) -->
                            <div id="cloud-files-grid-section" style="display:none">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                                    <div style="font-size:12px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:0.04em">
                                        Tệp Tin (<span id="cloud-files-count-badge-grid">0</span>)
                                    </div>
                                </div>
                                <div class="cloud-grid" id="cloud-files-grid"></div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 1.5: DOWNLOADS (TRUNG TÂM TẢI PHẦN MỀM) -->
                    <div id="tab-downloads" class="tab-pane" style="display:none">
                        <!-- 0. 2TOOLNE AUTOEDIT FOR CAPCUT V2 (FLAGSHIP DESKTOP SUITE) -->
                        <div class="card" style="margin-bottom:24px;border-color:#ff7a00;background:linear-gradient(180deg, rgba(255, 122, 0, 0.08) 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                                    <div style="flex:1;min-width:280px">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                            <span class="badge" style="background:#ff7a00;color:#fff;font-weight:800">🔥 SẢN PHẨM CHÍNH THỨC 2026</span>
                                            <span class="badge" style="background:rgba(255, 122, 0, 0.2);color:#ff9e42;border:1px solid rgba(255, 122, 0, 0.4)">v2.0.1 Stable</span>
                                            <span class="badge badge-info">Tương thích chính xác CapCut 9.3.0.3970</span>
                                        </div>
                                        <h3 style="font-size:22px;margin:0 0 6px">2TOOLNE AutoEdit for CapCut (Native Desktop Suite)</h3>
                                        <p class="text-muted" style="margin:0;font-size:13.5px">Phần mềm Desktop độc lập điều phối và tự động hóa biên tập CapCut Desktop: Tạo Timeline, Native Scale/Position Keyframes mượt mà, Rãnh âm thanh & Phụ đề XML tự động.</p>
                                    </div>
                                    <!-- SMART 1-CLICK DOWNLOAD BUTTON -->
                                    <div style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                        <button type="button" 
                                                class="btn btn-accent btn-lg" 
                                                style="background:#ff7a00;border-color:#ff7a00;font-weight:700;text-align:center;box-shadow:0 0 20px rgba(255, 122, 0, 0.35)"
                                                onclick="requestSecureDownload('AUTOEDIT', 'windows-x64')">
                                            <span>🪟</span> <span>Tải Cho Windows (.exe - Khuyên dùng)</span>
                                        </button>
                                        <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Bảo mật xác thực bản quyền • Tự động tương thích hệ điều hành</div>
                                    </div>
                                </div>

                                <!-- DOWNLOAD OPTIONS GRID -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                                    <!-- WINDOWS SUITE -->
                                    <div class="os-option-box" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🪟</span> Bản Dành Cho Windows (10/11 64-bit):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Phiên bản v2.0.1: Tương thích chính xác CapCut Desktop 9.3.0.3970, tự động phân giải com.lveditor.draft, NVIDIA RTX Tensor & Intel/AMD 64-bit.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-accent btn-sm" style="flex:1;text-align:center;background:#ff7a00;border-color:#ff7a00" onclick="requestSecureDownload('AUTOEDIT', 'windows-x64')">
                                                ⚙️ Bộ cài đặt .exe (523 MB)
                                            </button>
                                            <?php if ($user_info && in_array($user_info['role'] ?? '', ['admin', 'super_admin'], true)): ?>
                                                <button type="button" class="btn btn-outline btn-sm" style="flex:1;text-align:center;border-color:#ff7a00;color:#ff9e42" onclick="requestSecureDownload('AUTOEDIT', 'windows-x64', 'customer_test')">
                                                    🧪 Customer Test (.zip - 971 MB)
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- MACOS SUITE -->
                                    <div class="os-option-box" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🍏</span> Bản Dành Cho macOS (Monterey 12+):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal Binary: Apple Silicon (M1/M2/M3/M4) & Intel Core.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-emerald btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('AUTOEDIT', 'macos')">
                                                🍏 Gói cài .dmg (macOS)
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 1. SMART AUTO-DETECT OS: 2TOOLNE UPSCALE 4K -->
                        <div class="card" style="margin-bottom:24px;border-color:#facc15;background:linear-gradient(180deg, #221a05 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                                    <div style="flex:1;min-width:280px">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                            <span class="badge" style="background:#facc15;color:#000;font-weight:800">✨ AI SIÊU PHÂN GIẢI 2K & 4K</span>
                                            <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                        </div>
                                        <h3 style="font-size:22px;margin:0 0 6px">2toolne Upscale 4K — AI Super-Resolution App (v1.1.2 Stable)</h3>
                                        <p class="text-muted" style="margin:0;font-size:13.5px">Ứng dụng phóng to và phục chế ảnh 2K/4K siêu nét bằng AI on-device (Vulkan, DirectML, Apple Silicon Metal). Tiêu thụ theo số ảnh xuất hoàn tất (1 ảnh 2K = 1 lượt, 1 ảnh 4K = 2 lượt).</p>
                                    </div>
                                    <!-- PROMINENT SMART 1-CLICK BUTTON -->
                                    <div class="smart-download-container" data-app-name="2toolne Upscale 4K" data-product="UPSCALE" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                        <button type="button" 
                                                class="smart-download-btn btn btn-accent btn-lg" 
                                                style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(250, 204, 21, 0.25)"
                                                onclick="requestSecureDownload('UPSCALE', 'windows-x64')">
                                            <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.exe - 149 MB)</span>
                                        </button>
                                        <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Bản chính thức v1.1.2 • Tự động tương thích thiết bị</div>
                                    </div>
                                </div>

                                <!-- ALL DOWNLOAD OPTIONS (PRIMARY & SECONDARY) -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                                    <!-- WINDOWS SUITE -->
                                    <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🪟</span> Bản Dành Cho Windows (10/11 64-bit):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tăng tốc phần cứng DirectML, Vulkan, NVIDIA RTX Tensor.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-accent btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('UPSCALE', 'windows-x64')">⚙️ Bộ cài .exe (149 MB - Khuyên dùng)</button>
                                        </div>
                                    </div>

                                    <!-- MACOS SUITE -->
                                    <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🍏</span> Bản Dành Cho macOS (Monterey 12+):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal Binary: Apple Silicon (M1/M2/M3/M4 Metal) & Intel Core.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-emerald btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('UPSCALE', 'macos')">🍏 Gói cài .dmg (Mac)</button>
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top:14px;padding:8px 12px;background:rgba(250, 204, 21, 0.08);border-radius:var(--radius-sm);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                                    <span style="font-size:12.5px;color:var(--foreground)">🪙 Số dư lượt ảnh hiện tại: <b style="color:#facc15"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> Lượt (Tokens)</b></span>
                                    <a href="#tab-wallet-view" class="btn btn-outline btn-xs" onclick="switchMainTab('tab-wallet-view')">Nạp Thêm Lượt Ảnh ➔</a>
                                </div>
                            </div>
                        </div>

                        <!-- 2. SMART AUTO-DETECT OS: 2TOOLNE STUDIO -->
                        <div class="card" style="margin-bottom:24px;border-color:var(--emerald);background:linear-gradient(180deg, #09261e 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                                    <div style="flex:1;min-width:280px">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                            <span class="badge badge-active">🚀 SẢN PHẨM MỚI 2026</span>
                                            <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                        </div>
                                        <h3 style="font-size:22px;margin:0 0 6px">2toolne Studio — AI YouTube Production Suite (v1.1.2 RC)</h3>
                                        <p class="text-muted" style="margin:0;font-size:13.5px">Hệ thống sản xuất phim tài liệu tự động 200–250 shots, Edge TTS, Căn chỉnh lời chính xác, Upscale 4K & Multi-Track DAW.</p>
                                    </div>
                                    <!-- PROMINENT SMART 1-CLICK BUTTON -->
                                    <div class="smart-download-container" data-app-name="2toolne Studio" data-product="SLIDESHOW" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                        <button type="button" 
                                                class="smart-download-btn btn btn-emerald btn-lg" 
                                                style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(16, 185, 129, 0.3)"
                                                onclick="requestSecureDownload('SLIDESHOW', 'windows-x64')">
                                            <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.zip)</span>
                                        </button>
                                        <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Bản chính thức v1.1.2 RC • Tự động tương thích thiết bị</div>
                                    </div>
                                </div>

                                <!-- ALL DOWNLOAD OPTIONS (PRIMARY & SECONDARY) -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                                    <!-- WINDOWS SUITE -->
                                    <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🪟</span> Bản Dành Cho Windows (10/11 64-bit):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tối ưu NVIDIA RTX / CUDA, Intel & AMD đa nhân.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-accent btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('SLIDESHOW', 'windows-x64')">📥 Tải .zip (Portable 64-bit)</button>
                                        </div>
                                    </div>

                                    <!-- MACOS SUITE -->
                                    <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🍏</span> Bản Dành Cho macOS (Monterey 12+):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal Binary: Apple Silicon (M1/M2/M3/M4) & Intel Core.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-emerald btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('SLIDESHOW', 'macos')">🍏 Tải .zip (Universal Mac)</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
                            <!-- SLIDESHOW BUILDER AI -->
                            <div class="card">
                                <div class="card-header">
                                    <div class="card-title">🎬 Slideshow Builder AI (v2.3.9 Stable)</div>
                                    <span class="badge badge-purple">Subpixel Affine 60FPS</span>
                                </div>
                                <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:calc(100% - 55px)">
                                    <div>
                                        <p class="text-muted" style="font-size:13px;margin-bottom:14px">
                                            Phần mềm tạo video chuyển cảnh Ken Burns 4K mượt mà 60 FPS, phụ đề tự động Pill/Noonnu, lồng tiếng phát thanh viên đa giọng đọc.
                                        </p>
                                        <div class="text-subtle" style="font-size:12px;margin-bottom:16px">
                                            ✅ Động cơ Subpixel Affine không rung giật, tích hợp GPU Hardware Acceleration.
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                                        <button type="button" class="btn btn-emerald btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('SLIDESHOW', 'macos')">🍎 Tải macOS (.zip)</button>
                                        <button type="button" class="btn btn-accent btn-sm" style="flex:1;text-align:center" onclick="requestSecureDownload('SLIDESHOW', 'windows-x64')">🪟 Tải Windows (.zip)</button>
                                    </div>
                                </div>
                            </div>

                            <!-- LABS EXTENSION -->
                            <div class="card">
                                <div class="card-header">
                                    <div class="card-title">🖼️ Google Labs Flow Extension (v1.5.0)</div>
                                    <span class="badge badge-info">Chrome & Edge</span>
                                </div>
                                <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:calc(100% - 55px)">
                                    <div>
                                        <p class="text-muted" style="font-size:13px;margin-bottom:14px">
                                            Tiện ích tự động tải hàng loạt ảnh 2K/4K từ Google Labs, tự động sắp xếp và đánh số thứ tự chuẩn xác 001→xxx.
                                        </p>
                                        <div class="text-subtle" style="font-size:12px;margin-bottom:16px">
                                            ⚡ Cài đặt nhanh trong 30 giây qua Developer Mode trên Chrome / Brave / Edge / Cốc Cốc.
                                        </div>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-outline btn-sm" style="width:100%;text-align:center" onclick="requestSecureDownload('EXTENSION', 'all')">📥 Tải Extension (.zip)</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SYSTEM REQUIREMENTS & GUIDE -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📖 Hướng Dẫn Kích Hoạt & Cài Đặt Nhanh</div>
                            </div>
                            <div class="card-body" style="font-size:13px;color:var(--muted-foreground);line-height:1.7">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                                    <div>
                                        <h4 style="color:var(--foreground);font-size:14px;margin-bottom:8px">🪟 Dành cho Windows:</h4>
                                        <ol style="padding-left:18px;margin:0">
                                            <li>Tải file <code>.zip</code> tương ứng về máy và giải nén (Extract All).</li>
                                            <li>Khởi chạy file <code>start_windows.bat</code> (hoặc <code>run.bat</code>).</li>
                                            <li>Tại giao diện phần mềm, dán <b>License Key</b> từ mục <b>"Bản Quyền Của Tôi"</b> để kích hoạt.</li>
                                        </ol>
                                    </div>
                                    <div>
                                        <h4 style="color:var(--foreground);font-size:14px;margin-bottom:8px">🍎 Dành cho macOS:</h4>
                                        <ol style="padding-left:18px;margin:0">
                                            <li>Tải file <code>.zip</code> về máy, giải nén và mở thư mục phần mềm.</li>
                                            <li>Nhấp đúp vào <code>start_mac.command</code> để khởi động ứng dụng.</li>
                                            <li>Nhập <b>License Key</b> để mở khóa toàn bộ tính năng.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: BUY VIP KEY -->
                    <div id="tab-buy-key" class="tab-pane" style="display:none">
                        <!-- PRODUCT: 2TOOLNE AUTOEDIT FOR CAPCUT (V2) -->
                        <div class="card" style="margin-bottom:24px;border-color:#8b5cf6;background:linear-gradient(180deg, rgba(139, 92, 246, 0.06) 0%, var(--surface-1) 100%)">
                            <div class="card-header" style="border-bottom:1px solid rgba(139, 92, 246, 0.2)">
                                <div class="card-title" style="color:#c084fc">🎬 2TOOLNE AutoEdit for CapCut (Product V2 — Native Desktop Edition)</div>
                                <span class="badge" style="background:#7c3aed;color:#fff;font-weight:700">HOT V2.0.0 RC</span>
                            </div>
                            <div class="card-body">
                                <p class="text-muted" style="margin-bottom:20px">Phần mềm Desktop độc lập điều phối và tự động hóa biên tập CapCut Desktop: Tạo Timeline, Native Scale/Position Keyframes mượt mà, Rãnh âm thanh & Phụ đề XML tự động.</p>
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <div class="pricing-card">
                                        <div>
                                            <h4>CapCut AutoEdit (1 Tháng)</h4>
                                            <div class="price-val" style="color:#c084fc">800.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>✅ Tự động xuất bản nháp CapCut Desktop</li>
                                                <li>✅ Native Keyframes (Scale/Position) mượt mà</li>
                                                <li>✅ Hỗ trợ âm thanh & phụ đề tự động</li>
                                                <li>✅ Ứng dụng Desktop độc lập (macOS + Windows)</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#8b5cf6;color:#c084fc" onclick="openQrPayment('CapCut AutoEdit (1 Tháng)', '800.000đ', 30, 'VIP', '2toolne.capcut.v2')">⚡ Mua Gói 1 Tháng</button>
                                    </div>

                                    <div class="pricing-card featured" style="border-color:#8b5cf6">
                                        <span class="badge pricing-card-badge" style="background:#7c3aed;color:#fff">KHUYÊN DÙNG 1 NĂM</span>
                                        <div>
                                            <h4>CapCut AutoEdit (1 Năm VIP)</h4>
                                            <div class="price-val" style="color:#c084fc">6.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 365 ngày (Tiết kiệm 3.6 Tr)</div>
                                            <ul class="price-checklist">
                                                <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                                <li>✅ Tự động update mọi bản CapCut Desktop mới</li>
                                                <li>✅ Hỗ trợ ưu tiên 1-1 từ kỹ thuật viên</li>
                                                <li>✅ Đổi thiết bị linh hoạt không giới hạn</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-emerald" style="background:#7c3aed;border-color:#7c3aed" onclick="openQrPayment('CapCut AutoEdit (1 Năm VIP)', '6.000.000đ', 365, 'VIP', '2toolne.capcut.v2')">⚡ Mua Gói 1 Năm (VIP)</button>
                                    </div>

                                    <div class="pricing-card">
                                        <div>
                                            <h4>CapCut AutoEdit (Vĩnh Viễn)</h4>
                                            <div class="price-val" style="color:#c084fc">10.000.000đ</div>
                                            <div class="price-sub">Sở hữu trọn đời (Lifetime)</div>
                                            <ul class="price-checklist">
                                                <li>👑 <b>Sở hữu bản quyền trọn đời</b></li>
                                                <li>👑 Miễn phí cập nhật trọn đời</li>
                                                <li>👑 Hỗ trợ kỹ thuật ưu tiên 24/7</li>
                                                <li>👑 Bảo mật mã hóa phần cứng DPAPI & Ed25519</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#8b5cf6;color:#c084fc" onclick="openQrPayment('CapCut AutoEdit (Vĩnh Viễn)', '10.000.000đ', 36500, 'LIFETIME', '2toolne.capcut.v2')">👑 Mua Gói Vĩnh Viễn</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🚀 2TOOLNE — AI YouTube Production Studio (Khuyên Dùng)</div>
                                <span class="badge badge-active">NEW v1.1.2</span>
                            </div>
                            <div class="card-body">
                                <p class="text-muted" style="margin-bottom:20px">Hệ thống sản xuất video tài liệu tự động 200–250 shots, Edge TTS, Cắt ghép Opencut Multi-Track & Radar phân tích tăng trưởng.</p>
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <div class="pricing-card">
                                        <div>
                                            <h4>2TOOLNE Studio (1 Tháng)</h4>
                                            <div class="price-val" style="color:var(--emerald)">1.200.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>✅ 225-Shot Parallel Flow DAG</li>
                                                <li>✅ Edge TTS + Căn Chỉnh Khớp Lời</li>
                                                <li>✅ DAW Audio Ducking DSP</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:var(--emerald);color:var(--emerald)" onclick="openQrPayment('2TOOLNE Studio (1 Tháng)', '1.200.000đ', 30, 'VIP', '2TOOLNE')">⚡ Mua Gói 1 Tháng</button>
                                    </div>

                                    <div class="pricing-card featured">
                                        <span class="badge badge-active pricing-card-badge">TIẾT KIỆM 2 TRIỆU</span>
                                        <div>
                                            <h4>2TOOLNE Studio (1 Năm VIP)</h4>
                                            <div class="price-val" style="color:var(--emerald)">12.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 365 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                                <li>✅ Hỗ trợ ưu tiên 1-1 từ chuyên viên 2TOOL</li>
                                                <li>✅ YouTube Uploader & Growth Radar</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-emerald" onclick="openQrPayment('2TOOLNE Studio (1 Năm VIP)', '12.000.000đ', 365, 'VIP', '2TOOLNE')">⚡ Mua Gói 1 Năm (VIP)</button>
                                    </div>

                                    <div class="pricing-card">
                                         <div>
                                            <h4>2TOOLNE Studio (Vĩnh Viễn)</h4>
                                            <div class="price-val" style="color:var(--emerald)">18.000.000đ</div>
                                            <div class="price-sub">Sở hữu trọn đời (Lifetime)</div>
                                            <ul class="price-checklist">
                                                <li>👑 <b>Cập nhật tính năng trọn đời</b></li>
                                                <li>👑 Quyền lợi VIP cao cấp nhất</li>
                                                <li>👑 Hỗ trợ kỹ thuật 24/7 trực tiếp</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:var(--emerald);color:var(--emerald)" onclick="openQrPayment('2TOOLNE Studio (Vĩnh Viễn)', '18.000.000đ', 36500, 'LIFETIME', '2TOOLNE')">👑 Mua Gói Vĩnh Viễn</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🎬 Tool Video AI (Slideshow Builder)</div>
                            </div>
                            <div class="card-body">
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói 1 Tháng</h4>
                                            <div class="price-val">1.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày</div>
                                        </div>
                                        <button class="btn btn-outline" onclick="openQrPayment('Slideshow Builder (1 Tháng)', '1.000.000đ', 30, 'VIP', 'SLIDESHOW')">⚡ Mua Gói 1 Tháng</button>
                                    </div>
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói 1 Năm (VIP)</h4>
                                            <div class="price-val">10.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 365 ngày</div>
                                        </div>
                                        <button class="btn btn-primary" onclick="openQrPayment('Slideshow Builder (1 Năm)', '10.000.000đ', 365, 'VIP', 'SLIDESHOW')">⚡ Mua Gói 1 Năm</button>
                                    </div>
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Vĩnh Viễn</h4>
                                            <div class="price-val">15.000.000đ</div>
                                            <div class="price-sub">Sở hữu trọn đời</div>
                                        </div>
                                        <button class="btn btn-outline" onclick="openQrPayment('Slideshow Builder (Vĩnh Viễn)', '15.000.000đ', 36500, 'LIFETIME', 'SLIDESHOW')">👑 Mua Gói Vĩnh Viễn</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🖼️ Extension Google Labs (Tải Ảnh 2K/4K)</div>
                                <span class="badge badge-info">HOT DEAL 100K</span>
                            </div>
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div>
                                    <p class="text-muted">Tự động tải hàng loạt ảnh 2K/4K từ Google Labs Flow, tự động đánh số 001→xxx.</p>
                                    <div class="price-val" style="color:var(--info);margin:8px 0 0">100.000đ <span style="font-size:13px;color:var(--muted-foreground)">/ Sở hữu vĩnh viễn</span></div>
                                </div>
                                <button class="btn btn-accent" onclick="openQrPayment('Extension Google Labs (Vĩnh Viễn)', '100.000đ', 36500, 'LIFETIME', 'LABS_EXTENSION')">
                                    ⚡ Mua Key Extension (100k)
                                </button>
                            </div>
                        </div>

                        <!-- TOKEN PACKAGES SHORTCUT -->
                        <div class="card" style="margin-top:24px;border-color:rgba(234, 179, 8, 0.35);background:linear-gradient(135deg, rgba(234, 179, 8, 0.08) 0%, var(--surface-1) 100%)">
                            <div class="card-header">
                                <div class="card-title">🪙 Nạp Lượt Phóng To Ảnh AI (Upscale 2K / 4K)</div>
                                <span class="badge badge-active">Mới 2026</span>
                            </div>
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div>
                                    <p class="text-muted">Nạp thêm lượt phóng to ảnh cho ứng dụng 2toolne Upscale 4K. 1.000 lượt chỉ 100k, 3.500 lượt chỉ 250k, hoặc Gói Không Giới Hạn 30 ngày.</p>
                                    <div style="font-size:12.5px;color:#facc15;font-weight:600;margin-top:4px">Số dư hiện tại: <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> Lượt (Tokens)</div>
                                </div>
                                <button class="btn btn-emerald" onclick="switchMainTab('tab-wallet-view')">
                                    🪙 Xem & Mua Gói Lượt Ảnh
                                </button>
                            </div>
                        </div>

                        <!-- CLOUD STORAGE PACKAGES SHORTCUT -->
                        <div class="card" style="margin-top:24px;border-color:rgba(56, 189, 248, 0.35);background:linear-gradient(135deg, rgba(56, 189, 248, 0.08) 0%, var(--surface-1) 100%)">
                            <div class="card-header">
                                <div class="card-title">☁️ 2TOOLNE Cloud Storage (Lưu Trữ & Đồng Bộ Đám Mây)</div>
                                <span class="badge badge-info">2TOOL Cloud Core</span>
                            </div>
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div>
                                    <p class="text-muted">Kho lưu trữ đám mây tốc độ cao kết nối tự động với AI Upscale, chia sẻ link tải trực tiếp không giới hạn băng thông. Dung lượng từ 15GB đến 2TB+.</p>
                                    <div style="font-size:12.5px;color:#38bdf8;font-weight:600;margin-top:4px">Đồng bộ trực tiếp tệp tin dự án & ảnh upscale từ máy tính lên Cloud</div>
                                </div>
                                <button class="btn btn-primary" onclick="switchMainTab('tab-cloud-storage')">
                                    ☁️ Mở Kho Cloud Storage
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: FEATURE REQUESTS -->
                    <div id="tab-features-view" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">💡 Góp Ý / Mong Muốn Update Tính Năng</div>
                                <span class="badge">Tối đa 3 phiếu / tháng</span>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_feature">
                                    <div class="form-group">
                                        <label class="form-label">TIÊU ĐỀ TÍNH NĂNG:</label>
                                        <input type="text" name="title" class="form-input" placeholder="Ví dụ: Tích hợp thêm bộ lọc giọng nữ tiếng Anh..." required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÔ TẢ CHI TIẾT MONG MUỐN:</label>
                                        <textarea name="description" class="form-textarea" rows="3" placeholder="Mô tả cụ thể cách tính năng hoạt động..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Gửi Góp Ý Cho 2TOOL</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📋 Lịch Sử Góp Ý Của Bạn</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Tiêu Đề</th>
                                                <th>Nội Dung</th>
                                                <th>Trạng Thái</th>
                                                <th>Ngày Gửi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $my_features = array_filter($features_db, fn($x) => ($x['user'] ?? '') === $current_user);
                                            if (empty($my_features)): ?>
                                                <tr><td colspan="4" class="empty-state">Bạn chưa gửi phiếu góp ý nào.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($my_features as $f): ?>
                                                    <tr>
                                                        <td><b><?= htmlspecialchars($f['title']) ?></b></td>
                                                        <td class="text-muted"><?= htmlspecialchars($f['description']) ?></td>
                                                        <td><span class="badge badge-info"><?= htmlspecialchars($f['status'] ?? 'Đang xem xét') ?></span></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($f['created_at']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: BUG REPORTS -->
                    <div id="tab-bugs-view" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">🐞 Báo Cáo Lỗi Phần Mềm</div>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_bug">
                                    <div class="form-group">
                                        <label class="form-label">TIÊU ĐỀ LỖI:</label>
                                        <input type="text" name="title" class="form-input" placeholder="Ví dụ: Lỗi không xuất được video ở độ phân giải 4K..." required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÃ LỖI / ERROR CODE (NẾU CÓ):</label>
                                        <input type="text" name="error_code" class="form-input" placeholder="Ví dụ: ERR_FFMPEG_ENCODER_TIMEOUT">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÔ TẢ CHI TIẾT CÁC BƯỚC BỊ LỖI:</label>
                                        <textarea name="description" class="form-textarea" rows="3" placeholder="Mô tả cụ thể cấu hình máy, thao tác trước khi xảy ra lỗi..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-danger">Gửi Báo Cáo Lỗi Kỹ Thuật</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📋 Lịch Sử Báo Lỗi</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Tiêu Đề</th>
                                                <th>Mã Lỗi</th>
                                                <th>Trạng Thái</th>
                                                <th>Ngày Gửi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $my_bugs = array_filter($bugs_db, fn($x) => ($x['user'] ?? '') === $current_user);
                                            if (empty($my_bugs)): ?>
                                                <tr><td colspan="4" class="empty-state">Không có báo cáo lỗi nào từ bạn.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($my_bugs as $b): ?>
                                                    <tr>
                                                        <td><b><?= htmlspecialchars($b['title']) ?></b></td>
                                                        <td><code><?= htmlspecialchars($b['error_code'] ?: 'N/A') ?></code></td>
                                                        <td><span class="badge badge-warning"><?= htmlspecialchars($b['status'] ?? 'Đã tiếp nhận') ?></span></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($b['created_at']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 5: SETTINGS -->
                    <div id="tab-settings" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">👤 Thông Tin Tài Khoản</div>
                            </div>
                            <div class="card-body">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:13px">
                                    <div>Tên đăng nhập: <b class="text-primary"><?= htmlspecialchars($user_info['username']) ?></b></div>
                                    <div>Họ và tên: <b><?= htmlspecialchars($user_info['fullname'] ?: '(Chưa cập nhật)') ?></b></div>
                                    <div>SĐT / Zalo: <b><?= htmlspecialchars($user_info['phone'] ?: '(Chưa cập nhật)') ?></b></div>
                                    <div>Ngày tham gia: <span class="text-muted"><?= $user_info['created_at'] ?? '?' ?></span></div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🔐 Đổi Mật Khẩu</div>
                            </div>
                            <div class="card-body">
                                <form method="POST" style="max-width:400px">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label">MẬT KHẨU HIỆN TẠI:</label>
                                        <input type="password" name="old_password" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MẬT KHẨU MỚI (từ 6 ký tự):</label>
                                        <input type="password" name="new_password" class="form-input" required minlength="6">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">XÁC NHẬN MẬT KHẨU MỚI:</label>
                                        <input type="password" name="confirm_password" class="form-input" required minlength="6">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Lưu Mật Khẩu Mới</button>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

    </main>

    <!-- ═══ FOOTER (DASHBOARD) ═══ -->
    <footer style="border-top:1px solid var(--border);padding:32px 0;text-align:center;font-size:13px;color:var(--muted-foreground);margin-top:64px">
        <div class="container">
            <p>© 2026 <b>2tamne.site</b> — Commercial AI Software Suite. Mọi quyền được bảo lưu.</p>
            <p style="margin-top:6px">Hotline &amp; Zalo Kỹ Thuật: <a href="https://zalo.me/0326649304" target="_blank" style="color:var(--emerald);font-weight:600">0326649304</a> &bull; WhatsApp: <a href="https://wa.me/84326649304" target="_blank" style="color:#35C46A;font-weight:600">+84 326 649 304</a></p>
        </div>
    </footer>
    <?php endif; /* End legacy bypassed block */ ?>
    <?php else: ?>
        <!-- FLASH ALERTS (GUEST) -->
        <?php if ($msg_success): ?>
            <div class="container" style="position:relative;z-index:90;padding-top:70px"><div class="alert alert-success" style="margin-top:20px"><?= $msg_success ?></div></div>
        <?php endif; ?>
        <?php if ($msg_error): ?>
            <div class="container" style="position:relative;z-index:90;padding-top:70px"><div class="alert alert-danger" style="margin-top:20px"><?= $msg_error ?></div></div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════
             2TOOLNE WEB V3 — 2.5D PRODUCT CINEMA EXPERIENCE
             ═══════════════════════════════════════════════════════════════ -->
        <div id="root"></div>
        <script type="module" src="dist/assets/index-bojx5Pj4.js"></script>
    <?php endif; ?>

    <!-- ═══ MODALS & DIALOGS ═══ -->

    <!-- MODAL: PAYMENT GATEWAY & VIETQR CHECKOUT -->
    <div id="modal-qr-pay" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:480px;width:100%">
            <div class="modal-header">
                <div class="modal-title" style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:16px">⚡</span>
                    <span>Thanh Toán Đơn Hàng</span>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('modal-qr-pay')" aria-label="Đóng">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:12.5px;color:var(--muted-foreground)">Sản phẩm / Gói:</span>
                    <b id="qr-pkg-title" style="color:var(--emerald);font-size:13.5px">...</b>
                </div>

                <!-- ═══ PHƯƠNG THỨC 1: CỔNG THANH TOÁN CHÍNH SEPAY (TỰ ĐỘNG DUYỆT 24/7) ═══ -->
                <div style="background:rgba(2, 132, 199, 0.08);border:1.5px solid #0284c7;border-radius:var(--radius-sm);padding:14px;margin-bottom:14px;text-align:left">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <span style="font-size:12px;font-weight:700;color:#38bdf8">⚡ CỔNG THANH TOÁN CHÍNH (SEPAY)</span>
                        <span class="badge" style="background:rgba(16, 185, 129, 0.2);color:#10b981;border:1px solid rgba(16, 185, 129, 0.4);font-size:10px">TỰ ĐỘNG 24/7</span>
                    </div>
                    <p style="font-size:12px;color:var(--foreground);line-height:1.5;margin-bottom:12px">
                        Quét mã VietQR động qua SePay — Tự động nhận diện và <b>kích hoạt Key / nạp Token ngay lập tức</b> trong 3 giây!
                    </p>
                    <form action="/sepay_checkout.php" method="POST">
                        <input type="hidden" name="package_name" id="sepay-form-pkg-name">
                        <input type="hidden" name="package_price" id="sepay-form-pkg-price">
                        <input type="hidden" name="duration_days" id="sepay-form-pkg-days">
                        <input type="hidden" name="tier" id="sepay-form-pkg-tier">
                        <input type="hidden" name="product" id="sepay-form-pkg-product" value="SLIDESHOW">
                        <button type="submit" class="btn" style="width:100%;height:44px;font-size:14px;font-weight:700;background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border:none;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 14px rgba(2,132,199,0.35);border-radius:var(--radius-sm);cursor:pointer">
                            <span>⚡ Thanh Toán Tự Động Qua Cổng SePay</span>
                        </button>
                    </form>
                    <div style="font-size:11px;color:#94a3b8;text-align:center;margin-top:7px">
                        💳 Hỗ trợ tất cả ứng dụng ngân hàng & ví điện tử tại Việt Nam
                    </div>
                </div>

                <!-- ═══ PHƯƠNG THỨC 2: CHUYỂN KHOẢN THỦ CÔNG (COLLAPSIBLE EXPANSION) ═══ -->
                <details id="details-manual-transfer" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);text-align:left">
                    <summary style="padding:10px 14px;cursor:pointer;font-size:12px;color:var(--muted-foreground);font-weight:600;display:flex;justify-content:space-between;align-items:center;user-select:none">
                        <span>🏦 Hoặc chuyển khoản thủ công (Duyệt nhanh trong 5 phút)</span>
                        <span style="font-size:11px;color:var(--info)">Xem chi tiết ▼</span>
                    </summary>
                    <div style="padding:14px;border-top:1px solid var(--border)">
                        <!-- QR IMAGE -->
                        <div style="text-align:center;margin-bottom:12px">
                            <div style="background:#fff;padding:6px;border-radius:var(--radius-sm);display:inline-block;box-shadow:var(--shadow-sm)">
                                <img src="/assets/vietqr_tamne.png" alt="VietQR" style="max-width:160px;width:100%;height:auto;border-radius:4px;display:block">
                            </div>
                        </div>

                        <!-- BANK DETAILS -->
                        <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;font-size:12px;margin-bottom:12px">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span class="text-muted">Ngân hàng:</span>
                                <b>VietinBank (PGD Thủ Đô)</b>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span class="text-muted">Chủ tài khoản:</span>
                                <b>HOANG LUONG TAM</b>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                                <span class="text-muted">Số tài khoản:</span>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <b class="font-mono" style="color:var(--info)">101876965948</b>
                                    <button type="button" class="btn btn-outline btn-xs" onclick="copyText('101876965948')" title="Sao chép">📋</button>
                                </div>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span class="text-muted">Số tiền:</span>
                                <b id="qr-pkg-price" style="color:var(--emerald)">...</b>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center">
                                <span class="text-muted">Nội dung CK:</span>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <b id="qr-memo-text" class="font-mono" style="color:var(--warning)">...</b>
                                    <button type="button" class="btn btn-outline btn-xs" onclick="copyText(document.getElementById('qr-memo-text').textContent)" title="Sao chép">📋</button>
                                </div>
                            </div>
                        </div>

                        <!-- 2-MINUTE COUNTDOWN -->
                        <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius-sm);padding:6px 10px;font-size:11.5px;font-weight:600;color:var(--foreground);margin-bottom:12px;text-align:center">
                            ⏱️ Thời gian giữ đơn: <span id="countdown-timer" style="color:var(--danger);font-size:13px">02:00</span>
                        </div>

                        <form method="POST" onsubmit="handleAjaxSubmitPayment(event, this)">
                            <input type="hidden" name="action" value="submit_payment">
                            <input type="hidden" name="package_name" id="form-pkg-name">
                            <input type="hidden" name="package_price" id="form-pkg-price">
                            <input type="hidden" name="duration_days" id="form-pkg-days">
                            <input type="hidden" name="tier" id="form-pkg-tier">
                            <input type="hidden" name="product" id="form-pkg-product" value="SLIDESHOW">
                            <button type="submit" class="btn btn-outline" style="width:100%;height:38px;font-size:13px;font-weight:600">
                                ✅ Tôi Đã Chuyển Khoản (Chờ Xác Nhận)
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <!-- MODAL: APP AUTHENTICATION -->
    <?php if (!empty($pending_app_auth) && $current_user): ?>
    <div id="modal-app-auth" class="modal-backdrop active" style="z-index:99999;display:flex;align-items:center;justify-content:center">
        <div class="modal-dialog" style="max-width:440px;background:#12131a;border:1px solid rgba(59,130,246,0.3);box-shadow:0 25px 60px rgba(0,0,0,0.85);border-radius:18px">
            <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,0.08);padding:18px 20px">
                <div class="modal-title" style="font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px">
                    <span style="font-size:22px">🔐</span> Phê Duyệt Đăng Nhập Ứng Dụng
                </div>
                <a href="index.php?cancel_auth=1" class="modal-close">&times;</a>
            </div>
            <div class="modal-body" style="padding:22px 20px">
                <div style="text-align:center;margin-bottom:20px">
                    <div style="display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;border-radius:18px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.25);margin-bottom:12px;font-size:28px">
                        ⚡
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#fff">2toolne Upscale Desktop</div>
                    <div style="font-size:12px;color:rgba(255,255,255,0.6);margin-top:4px">Đang yêu cầu kết nối với tài khoản trên web của bạn</div>
                </div>

                <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px 16px;margin-bottom:20px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:13px">
                        <span style="color:rgba(255,255,255,0.5)">Tài khoản:</span>
                        <strong style="color:#60a5fa;font-size:14px"><?= htmlspecialchars($current_user) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:13px">
                        <span style="color:rgba(255,255,255,0.5)">Gói dịch vụ:</span>
                        <span class="badge" style="background:#2563eb;color:#fff;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">PRO</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px">
                        <span style="color:rgba(255,255,255,0.5)">Số dư Lượt ảnh:</span>
                        <strong style="color:#10b981;font-size:14px"><?= number_format($user_wallet['balance'] ?? 0) ?> Lượt</strong>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="approve_app_auth">
                    <input type="hidden" name="session_id" value="<?= htmlspecialchars($pending_app_auth['session'] ?? '') ?>">
                    <input type="hidden" name="port" value="<?= intval($pending_app_auth['port'] ?? 0) ?>">
                    <input type="hidden" name="challenge" value="<?= htmlspecialchars($pending_app_auth['challenge'] ?? '') ?>">
                    <input type="hidden" name="state" value="<?= htmlspecialchars($pending_app_auth['state'] ?? '') ?>">
                    <button type="submit" class="btn btn-accent btn-lg" style="width:100%;font-weight:700;margin-bottom:10px;font-size:14px;padding:12px">
                        ✅ Duyệt Đăng Nhập Cho App
                    </button>
                    <a href="index.php?cancel_auth=1" class="btn btn-outline btn-sm" style="width:100%;text-align:center;display:block">
                        ❌ Hủy Bỏ
                    </a>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL: APP AUTHENTICATION SUCCESS CELEBRATION -->
    <?php if (isset($_GET['app_auth_approved']) && !empty($_SESSION['app_auth_approved'])): 
        $approved_info = $_SESSION['app_auth_approved'];
        unset($_SESSION['app_auth_approved']);
    ?>
    <div id="modal-app-auth-success" class="modal-backdrop active" style="z-index:99999;display:flex;align-items:center;justify-content:center">
        <div class="modal-dialog" style="max-width:440px;background:#12131a;border:1px solid rgba(16,185,129,0.4);box-shadow:0 25px 60px rgba(0,0,0,0.85);border-radius:18px;text-align:center;padding:32px 24px">
            <div style="font-size:54px;margin-bottom:12px">🎉</div>
            <h2 style="font-size:20px;font-weight:700;color:#fff;margin:0 0 8px">Đã Duyệt Đăng Nhập Thành Công!</h2>
            <p style="font-size:14px;color:rgba(255,255,255,0.7);line-height:1.5;margin:0 0 20px">
                Ứng dụng <b>2TOOLNE AutoEdit Desktop</b> đã được cấp quyền kết nối với tài khoản <b><?= htmlspecialchars($approved_info['username']) ?></b>.
            </p>
            <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:12px;padding:12px;margin-bottom:20px">
                <div style="font-size:15px;font-weight:700;color:#34d399"><?= htmlspecialchars($approved_info['username']) ?></div>
                <div style="font-size:13px;color:#a7f3d0;margin-top:2px"><?= number_format($approved_info['balance']) ?> Token • Gói <?= htmlspecialchars($approved_info['plan']) ?></div>
            </div>
            
            <?php 
                $cb_params = [
                    'status' => 'success',
                    'code' => $approved_info['code'] ?? '',
                    'state' => $approved_info['state'] ?? '',
                ];
                $port_num = intval($approved_info['port'] ?? 0);
                $loopback_url = ($port_num > 1024) ? "http://127.0.0.1:{$port_num}/callback?" . http_build_query($cb_params) : '';
                $deeplink_url = "toolne://auth/callback?" . http_build_query($cb_params);
            ?>

            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;">
                <a href="<?= htmlspecialchars($deeplink_url) ?>" class="btn btn-accent" style="width:100%;padding:12px;font-weight:700;text-decoration:none;display:inline-block;border-radius:10px;">
                    🚀 Quay Lại Ứng Dụng Desktop
                </a>
                <a href="index.php" class="btn btn-outline btn-sm" style="width:100%;padding:8px;text-align:center;text-decoration:none;display:inline-block;">
                    Quay Lại Trang Chủ Web
                </a>
            </div>

            <p style="font-size:12px;color:rgba(255,255,255,0.4);margin:0">
                Nếu ứng dụng chưa tự mở, vui lòng bấm nút "Quay Lại Ứng Dụng Desktop" ở trên.
            </p>
        </div>
    </div>
    <?php if (!empty($loopback_url)): ?>
    <script>
        setTimeout(function() {
            try {
                window.location.href = <?= json_encode($loopback_url) ?>;
            } catch(e) {}
        }, 200);
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <!-- MODAL: LOGIN -->
    <div id="modal-login" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:400px">
            <div class="modal-header">
                <div class="modal-title">🔐 Đăng Nhập Tài Khoản</div>
                <button class="modal-close" onclick="closeModal('modal-login')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label class="form-label">TÊN ĐĂNG NHẬP:</label>
                        <input type="text" name="username" class="form-input" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">MẬT KHẨU:</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;height:38px">Đăng Nhập Ngay</button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: REGISTER -->
    <div id="modal-register" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">🎁 Đăng Ký Nhận Bản Quyền Dùng Thử</div>
                <button class="modal-close" onclick="closeModal('modal-register')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:12.5px;color:var(--muted-foreground);margin-bottom:14px">
                    Tự động tặng 1 Khóa dùng thử 3 ngày + 50 lượt phóng to ảnh 2K/4K miễn phí cho tài khoản của bạn.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="form-group">
                        <label class="form-label">TÊN ĐĂNG NHẬP (chữ thường, số):</label>
                        <input type="text" name="username" class="form-input" placeholder="Ví dụ: hoangtam2026" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">HỌ VÀ TÊN:</label>
                        <input type="text" name="fullname" class="form-input" placeholder="Ví dụ: Hoàng Tâm" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SỐ ĐIỆN THOẠI / ZALO:</label>
                        <input type="text" name="phone" class="form-input" placeholder="Ví dụ: 09xx xxx xxx" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">MẬT KHẨU:</label>
                        <input type="password" name="password" class="form-input" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-emerald" style="width:100%;height:40px">
                        🚀 Tạo Tài Khoản & Nhận Quà Ngay
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: CONFIRM HWID RESET (DESTRUCTIVE ACTION CONFIRM DIALOG) -->
    <div id="modal-reset-hwid-confirm" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">🔄 Xác Nhận Đổi Thiết Bị</div>
                <button class="modal-close" onclick="closeModal('modal-reset-hwid-confirm')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--foreground);line-height:1.6;margin-bottom:14px">
                    Bạn có chắc chắn muốn <b>gỡ liên kết máy tính cũ</b> cho mã bản quyền này để kích hoạt trên thiết bị mới?
                </p>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px;font-size:12.5px;color:var(--muted-foreground);margin-bottom:16px">
                    Mã bản quyền: <b id="hwid-reset-key-label" class="font-mono text-primary">...</b><br>
                    Sau khi gỡ liên kết, bạn có thể nhập mã này trên máy tính mới để tiếp tục sử dụng.
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_hwid">
                    <input type="hidden" name="key" id="hwid-reset-key-input">
                    <div style="display:flex;justify-content:flex-end;gap:10px">
                        <button type="button" class="btn btn-outline" onclick="closeModal('modal-reset-hwid-confirm')">Hủy Bỏ</button>
                        <button type="submit" class="btn btn-accent">Xác Nhận Đổi Thiết Bị</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: DOWNLOAD ENTITLEMENT REQUIRED ═══ -->
    <div id="modal-download-entitlement" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:460px;background:#12131a;border:1px solid rgba(255,122,0,0.3);box-shadow:0 25px 60px rgba(0,0,0,0.85);border-radius:18px">
            <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,0.08);padding:18px 20px">
                <div class="modal-title" style="font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;color:#ff8f1f">
                    <span>🛡️</span> <span>Xác Thực Quyền Tải Phần Mềm</span>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('modal-download-entitlement')">&times;</button>
            </div>
            <div class="modal-body" style="padding:24px 20px;text-align:center">
                <div style="font-size:48px;margin-bottom:12px">🔒</div>
                <h4 id="entitlement-modal-title" style="margin:0 0 10px;font-size:16px;color:#fff">Cần Bản Quyền Hoặc Dùng Thử</h4>
                <p id="entitlement-modal-msg" class="text-muted" style="font-size:13.5px;line-height:1.6;margin-bottom:24px">
                    Bạn cần kích hoạt bản dùng thử 3 ngày hoặc sở hữu gói bản quyền để tải bộ cài đặt phần mềm này.
                </p>
                <div id="entitlement-modal-actions" style="display:flex;flex-direction:column;gap:10px">
                    <button type="button" id="btn-claim-trial" class="btn btn-accent" style="width:100%;font-weight:700;background:#ff7a00;border-color:#ff7a00;padding:12px" onclick="handleClaimTrialFromModal()">
                        ✨ Kích Hoạt Dùng Thử 3 Ngày (Miễn Phí)
                    </button>
                    <a href="#tab-buy-key" class="btn btn-outline" style="width:100%;padding:10px" onclick="closeModal('modal-download-entitlement'); switchMainTab('tab-buy-key');">
                        💳 Mua Gói Bản Quyền Chính Thức
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: CLOUD UPLOAD FILE (DIRECT RESUMABLE ENGINE) ═══ -->
    <div id="modal-cloud-upload" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:540px">
            <div class="modal-header">
                <h3 class="modal-title" style="font-size:16px;margin:0;display:flex;align-items:center;gap:6px">
                    <span>⬆️</span> Tải Tệp Lên 2TOOLNE Cloud
                </h3>
                <button class="modal-close" onclick="closeModal('modal-cloud-upload')">&times;</button>
            </div>
            <div class="modal-body" style="padding:20px">
                <div class="cloud-dropzone" id="cloud-dropzone" onclick="document.getElementById('cloud-file-input').click()">
                    <div style="font-size:36px;margin-bottom:8px">☁️</div>
                    <div style="font-weight:600;font-size:14px;margin-bottom:4px">Chọn tệp tin hoặc kéo thả vào đây</div>
                    <div style="font-size:12px;color:var(--muted-foreground)">Hỗ trợ ảnh JPG/PNG/WEBP, video MP4/MKV, zip, v.v. Tối đa theo hạn mức lưu trữ.</div>
                    <input type="file" id="cloud-file-input" multiple style="display:none" onchange="onCloudFileSelected(this.files)">
                </div>

                <div id="cloud-selected-files-list" style="margin-top:16px;display:none">
                    <div style="font-size:12px;font-weight:600;color:var(--muted-foreground);margin-bottom:8px">Tệp đã chọn:</div>
                    <div id="cloud-file-items-container" style="max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:6px"></div>
                </div>

                <div id="cloud-upload-progress-container" style="margin-top:16px;display:none">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:12.5px">
                        <span id="cloud-upload-status-text" style="color:var(--foreground);font-weight:500">Đang khởi tạo phiên tải lên...</span>
                        <span id="cloud-upload-percent-text" style="font-family:var(--font-mono);font-weight:700;color:var(--primary)">0%</span>
                    </div>
                    <div class="cloud-quota-track" style="height:10px">
                        <div id="cloud-upload-bar-fill" class="cloud-quota-fill" style="width:0%"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted-subtle);margin-top:4px">
                        <span id="cloud-upload-bytes-text">0 / 0 MB</span>
                        <span id="cloud-upload-speed-text">2TOOL Cloud Stream tốc độ cao</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 20px;display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modal-cloud-upload')" id="btn-cloud-cancel-upload">Đóng</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="startCloudUploadQueue()" id="btn-cloud-start-upload">Bắt Đầu Tải Lên</button>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: CLOUD TRASH (THÙNG RÁC) ═══ -->
    <div id="modal-cloud-trash" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:700px">
            <div class="modal-header">
                <h3 class="modal-title" style="font-size:16px;margin:0;display:flex;align-items:center;gap:6px">
                    <span>🗑️</span> Thùng Rác 2TOOLNE Cloud
                </h3>
                <button class="modal-close" onclick="closeModal('modal-cloud-trash')">&times;</button>
            </div>
            <div class="modal-body" style="padding:20px">
                <div style="background:rgba(234, 179, 8, 0.08);border:1px solid rgba(234, 179, 8, 0.25);border-radius:var(--radius-sm);padding:10px 14px;font-size:12px;color:#facc15;margin-bottom:16px">
                    ℹ️ Tệp trong Thùng Rác vẫn chiếm dung lượng của Không gian lưu trữ cho đến khi bạn bấm <b>Xóa Vĩnh Viễn</b>. Bạn có thể khôi phục lại tệp bất kỳ lúc nào.
                </div>
                <div class="table-responsive" style="max-height:350px;overflow-y:auto">
                    <table class="table" style="margin:0">
                        <thead>
                            <tr>
                                <th>Tên Tệp</th>
                                <th>Kích Thước</th>
                                <th>Thời Gian Xóa</th>
                                <th style="text-align:right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody id="cloud-trash-tbody">
                            <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted-foreground)">Đang tải thùng rác...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 20px;display:flex;justify-content:flex-end">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modal-cloud-trash')">Đóng</button>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: BUY CLOUD QUOTA (MUA DUNG LƯỢNG) ═══ -->
    <div id="modal-cloud-buy-quota" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:640px;width:95%">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="font-size:17px;margin:0;display:flex;align-items:center;gap:8px">
                        <span>⚡</span> Mua Dung Lượng 2TOOLNE Cloud
                    </h3>
                    <div style="font-size:12px;color:var(--muted-foreground);margin-top:3px">
                        Lưu trữ 2TOOL Cloud tốc độ cao, đồng bộ tức thì với AI Upscale • Thời hạn: <b>3 Tháng (90 ngày)</b>
                    </div>
                </div>
                <button class="modal-close" onclick="closeModal('modal-cloud-buy-quota')">&times;</button>
            </div>
            <div class="modal-body" style="padding:16px 20px">
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:12px;margin-bottom:16px">
                    <!-- 20GB -->
                    <div style="background:var(--surface-2);border:1px solid rgba(56,189,248,0.3);border-radius:var(--radius-md);padding:14px;text-align:center;display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:var(--muted-foreground)">GÓI CƠ BẢN (MIN)</div>
                            <div style="font-size:22px;font-weight:800;color:#38bdf8;margin:6px 0">20 GB</div>
                            <div style="font-size:14px;font-weight:700;color:var(--foreground)">50.000đ</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">Dùng trong 3 tháng</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-top:12px" onclick="closeModal('modal-cloud-buy-quota');openQrPayment('Gói Cloud 20GB (3 Tháng)', '50.000đ', 90, 'CLOUD_20GB', 'CLOUD_STORAGE')">
                            ⚡ Mua 20GB
                        </button>
                    </div>
                    <!-- 40GB -->
                    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;text-align:center;display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:var(--muted-foreground)">GÓI PHỔ BIẾN</div>
                            <div style="font-size:22px;font-weight:800;color:#38bdf8;margin:6px 0">40 GB</div>
                            <div style="font-size:14px;font-weight:700;color:var(--foreground)">100.000đ</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">Dùng trong 3 tháng</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" style="width:100%;margin-top:12px;border-color:#38bdf8;color:#38bdf8" onclick="closeModal('modal-cloud-buy-quota');openQrPayment('Gói Cloud 40GB (3 Tháng)', '100.000đ', 90, 'CLOUD_40GB', 'CLOUD_STORAGE')">
                            ⚡ Mua 40GB
                        </button>
                    </div>
                    <!-- 60GB -->
                    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;text-align:center;display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:var(--muted-foreground)">GÓI NÂNG CAO</div>
                            <div style="font-size:22px;font-weight:800;color:#38bdf8;margin:6px 0">60 GB</div>
                            <div style="font-size:14px;font-weight:700;color:var(--foreground)">150.000đ</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">Dùng trong 3 tháng</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" style="width:100%;margin-top:12px;border-color:#38bdf8;color:#38bdf8" onclick="closeModal('modal-cloud-buy-quota');openQrPayment('Gói Cloud 60GB (3 Tháng)', '150.000đ', 90, 'CLOUD_60GB', 'CLOUD_STORAGE')">
                            ⚡ Mua 60GB
                        </button>
                    </div>
                    <!-- 80GB -->
                    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;text-align:center;display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:var(--muted-foreground)">GÓI STUDIO</div>
                            <div style="font-size:22px;font-weight:800;color:#38bdf8;margin:6px 0">80 GB</div>
                            <div style="font-size:14px;font-weight:700;color:var(--foreground)">200.000đ</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">Dùng trong 3 tháng</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" style="width:100%;margin-top:12px;border-color:#38bdf8;color:#38bdf8" onclick="closeModal('modal-cloud-buy-quota');openQrPayment('Gói Cloud 80GB (3 Tháng)', '200.000đ', 90, 'CLOUD_80GB', 'CLOUD_STORAGE')">
                            ⚡ Mua 80GB
                        </button>
                    </div>
                    <!-- 100GB -->
                    <div style="background:linear-gradient(135deg, rgba(245,158,11,0.1), rgba(217,119,6,0.05));border:1px solid rgba(245,158,11,0.4);border-radius:var(--radius-md);padding:14px;text-align:center;display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:var(--warning)">GÓI TỐI ĐA (MAX)</div>
                            <div style="font-size:22px;font-weight:800;color:var(--warning);margin:6px 0">100 GB</div>
                            <div style="font-size:14px;font-weight:700;color:var(--foreground)">250.000đ</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">Dùng trong 3 tháng</div>
                        </div>
                        <button type="button" class="btn btn-warning btn-sm" style="width:100%;margin-top:12px;font-weight:700" onclick="closeModal('modal-cloud-buy-quota');openQrPayment('Gói Cloud 100GB (3 Tháng)', '250.000đ', 90, 'CLOUD_100GB', 'CLOUD_STORAGE')">
                            👑 Mua 100GB
                        </button>
                    </div>
                </div>
                <div style="font-size:11.5px;color:var(--muted-foreground);line-height:1.5;background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                    💡 <b>Lưu ý:</b> Thanh toán tự động qua mã VietQR SePay. Ngay khi chuyển khoản thành công, dung lượng sẽ được tự động kích hoạt ngay lập tức vào Không gian lưu trữ của bạn.
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: CREATE TEAM CLOUD (KHỞI TẠO TEAM CLOUD) ═══ -->
    <div id="modal-cloud-create-team" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:540px;width:95%">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="font-size:17px;margin:0;display:flex;align-items:center;gap:8px">
                        <span>🏢</span> Khởi Tạo Không Gian Team Cloud
                    </h3>
                    <div style="font-size:12px;color:var(--muted-foreground);margin-top:3px">
                        Bộ nhớ dùng chung cho đội ngũ và xưởng sản xuất
                    </div>
                </div>
                <button class="modal-close" onclick="closeModal('modal-cloud-create-team')">&times;</button>
            </div>
            <div class="modal-body" style="padding:16px 20px">
                <div style="background:linear-gradient(135deg, rgba(16,185,129,0.12), rgba(56,189,248,0.1));border:1px solid rgba(16,185,129,0.35);border-radius:var(--radius-md);padding:16px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <span style="font-size:14px;font-weight:700;color:var(--foreground)">GÓI KHỞI ĐỘNG TEAM CLOUD</span>
                        <span style="font-size:18px;font-weight:800;color:var(--emerald)">50.000đ</span>
                    </div>
                    <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--foreground);line-height:1.7">
                        <li>👥 <b>Bao gồm 2 Slot tài khoản</b> (tính cả tài khoản đăng ký gói).</li>
                        <li>💾 <b>20 GB dung lượng Cloud</b> dùng chung cho toàn bộ thành viên.</li>
                        <li>⏱️ <b>Thời hạn sử dụng:</b> 3 Tháng (90 ngày).</li>
                        <li>🔒 <b>Phân quyền rõ ràng:</b> Trưởng nhóm (Owner), Quản lý nhóm (Manager), Thành viên (Member).</li>
                        <li>🛡️ <b>Cách ly không gian độc lập:</b> Mỗi người dùng và Đội nhóm được cấp không gian riêng biệt trên 2TOOL Cloud, loại bỏ nguy cơ trùng lặp hay ghi đè file.</li>
                        <li>➕ <b>Mua thêm Slot linh hoạt:</b> 20k/1 slot, 100k/5 slots, 150k/10 slots bất kỳ lúc nào.</li>
                    </ul>
                </div>
                <button type="button" class="btn btn-emerald" style="width:100%;height:42px;font-weight:700;font-size:14px" onclick="closeModal('modal-cloud-create-team');openQrPayment('Khởi Tạo Team Cloud (3 Tháng)', '50.000đ', 90, 'TEAM_INIT', 'TEAM_CLOUD')">
                    🚀 Đăng Ký Khởi Tạo Team Cloud Ngay (50.000đ)
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL: TEAM MEMBERS & PERMISSIONS (QUẢN LÝ THÀNH VIÊN TEAM) ═══ -->
    <div id="modal-cloud-team-members" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:680px;width:95%">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="font-size:17px;margin:0;display:flex;align-items:center;gap:8px">
                        <span>👥</span> Quản Lý Đội Nhóm & Thành Viên
                    </h3>
                    <div style="font-size:12px;color:var(--muted-foreground);margin-top:3px" id="user-team-modal-sub">
                        Đang tải thông tin Team...
                    </div>
                </div>
                <button class="modal-close" onclick="closeModal('modal-cloud-team-members')">&times;</button>
            </div>
            <div class="modal-body" style="padding:16px 20px">
                <!-- SLOT OVERVIEW -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;background:var(--surface-2);padding:10px 14px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                    <div>
                        <span style="font-size:11px;color:var(--muted-foreground);display:block">TÌNH TRẠNG CHỖ NGỒI (SLOTS)</span>
                        <span style="font-size:14px;font-weight:700;color:#38bdf8" id="user-team-modal-slots">Đang kiểm tra...</span>
                    </div>
                    <div id="user-team-buy-slots-btn-wrap" style="display:none">
                        <button type="button" class="btn btn-primary btn-xs" onclick="toggleUserTeamSlotOptions()">
                            ➕ Mua Thêm Slot
                        </button>
                    </div>
                </div>

                <!-- BUY EXTRA SLOTS DRAWER (HIDDEN BY DEFAULT) -->
                <div id="user-team-slot-options-box" style="display:none;background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px;margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:#38bdf8">⚡ Mua Thêm Slot Thành Viên Team (Thời hạn theo nhóm):</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:10px">
                        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px;text-align:center">
                            <div style="font-weight:700;font-size:13px">+1 Slot</div>
                            <div style="font-weight:800;color:var(--foreground);margin:4px 0">20.000đ</div>
                            <button type="button" class="btn btn-outline btn-xs" style="width:100%" onclick="closeModal('modal-cloud-team-members');openQrPayment('Mua Thêm 1 Slot Team', '20.000đ', 90, 'TEAM_SLOT_1', 'TEAM_CLOUD')">Mua 1 Slot</button>
                        </div>
                        <div style="background:var(--surface-2);border:1px solid rgba(56,189,248,0.4);border-radius:var(--radius-sm);padding:10px;text-align:center">
                            <div style="font-weight:700;font-size:13px;color:#38bdf8">Combo +5 Slot</div>
                            <div style="font-weight:800;color:var(--foreground);margin:4px 0">100.000đ</div>
                            <button type="button" class="btn btn-primary btn-xs" style="width:100%" onclick="closeModal('modal-cloud-team-members');openQrPayment('Mua Thêm 5 Slot Team', '100.000đ', 90, 'TEAM_SLOT_5', 'TEAM_CLOUD')">Mua 5 Slot</button>
                        </div>
                        <div style="background:var(--surface-2);border:1px solid rgba(245,158,11,0.4);border-radius:var(--radius-sm);padding:10px;text-align:center">
                            <div style="font-weight:700;font-size:13px;color:var(--warning)">Combo +10 Slot</div>
                            <div style="font-weight:800;color:var(--foreground);margin:4px 0">150.000đ</div>
                            <button type="button" class="btn btn-warning btn-xs" style="width:100%;font-weight:700" onclick="closeModal('modal-cloud-team-members');openQrPayment('Mua Thêm 10 Slot Team', '150.000đ', 90, 'TEAM_SLOT_10', 'TEAM_CLOUD')">Mua 10 Slot</button>
                        </div>
                    </div>
                </div>

                <!-- INVITE FORM (SHOWN FOR OWNER / ADMIN) -->
                <div id="user-team-invite-box" style="display:none;background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px;margin-bottom:14px">
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="action" value="send_team_invite">
                        <input type="hidden" name="team_id" id="user-team-invite-team-id">
                        <div style="font-size:12px;font-weight:700;margin-bottom:6px">📨 Mời Người Dùng Vào Team:</div>
                        <div style="display:flex;gap:8px">
                            <input type="text" name="target_username" class="form-input" placeholder="Gõ chính xác username tài khoản (ví dụ: hieunekkk)..." required style="font-size:12.5px;height:36px">
                            <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap;font-weight:600">Gửi Lời Mời</button>
                        </div>
                        <div style="font-size:11px;color:var(--muted-foreground);margin-top:4px">
                            Người được mời sẽ nhận được banner thông báo trên màn hình để xác nhận tham gia.
                        </div>
                    </form>
                </div>

                <!-- MEMBERS TABLE -->
                <div class="table-responsive" style="max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
                    <table class="data-table" style="margin:0">
                        <thead>
                            <tr>
                                <th>Thành Viên</th>
                                <th>Phân Quyền Vai Trò</th>
                                <th>Trạng Thái</th>
                                <th style="text-align:right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody id="user-team-members-tbody">
                            <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--muted-foreground)">Đang tải danh sách thành viên...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 20px;display:flex;justify-content:flex-end">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modal-cloud-team-members')">Đóng</button>
            </div>
        </div>
    </div>

    <!-- GLOBAL BROADCAST NOTICE MODAL -->
    <?php
    $active_broadcast = $sys_config['broadcast_notice'] ?? null;
    $has_active_broadcast = $active_broadcast && !empty($active_broadcast['active']) && !empty($active_broadcast['title']);
    if ($has_active_broadcast):
    ?>
    <div id="modal-global-broadcast" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:520px;text-align:center">
            <div class="modal-header" style="justify-content:center">
                <span class="badge badge-info">📢 THÔNG BÁO TỪ HỆ THỐNG</span>
            </div>
            <div class="modal-body">
                <h3 style="font-size:18px;margin-bottom:12px"><?= htmlspecialchars($active_broadcast['title']) ?></h3>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;font-size:13px;color:var(--muted-foreground);line-height:1.6;text-align:left;white-space:pre-wrap;max-height:260px;overflow-y:auto;margin-bottom:16px">
                    <?= htmlspecialchars($active_broadcast['content']) ?>
                </div>
                <?php if (!empty($active_broadcast['button_text'])): ?>
                    <a href="<?= htmlspecialchars($active_broadcast['button_url'] ?: '#') ?>" target="_blank" class="btn btn-outline" style="width:100%;margin-bottom:10px">
                        <?= htmlspecialchars($active_broadcast['button_text']) ?>
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" style="width:100%" onclick="confirmNoticeRead('<?= htmlspecialchars($active_broadcast['id']) ?>')">
                    ✅ Tôi Đã Hiểu (Đóng Thông Báo)
                </button>
            </div>
        </div>
    </div>
    <script>
    (function() {
        const noticeId = '<?= htmlspecialchars($active_broadcast['id']) ?>';
        const storageKey = 'read_notice_' + noticeId;
        const isReadLocal = localStorage.getItem(storageKey);
        const isReadCookie = document.cookie.split('; ').some(row => row.startsWith(storageKey + '='));
        if (!isReadLocal && !isReadCookie) {
            window.addEventListener('DOMContentLoaded', () => {
                const m = document.getElementById('modal-global-broadcast');
                if (m) m.classList.add('active');
            });
        }
    })();
    function confirmNoticeRead(noticeId) {
        localStorage.setItem('read_notice_' + noticeId, '1');
        document.cookie = 'read_notice_' + noticeId + '=1; path=/; max-age=' + (86400 * 60);
        const m = document.getElementById('modal-global-broadcast');
        if (m) m.classList.remove('active');
        fetch('index.php?ajax=mark_notice_read&id=' + encodeURIComponent(noticeId)).catch(() => {});
    }
    </script>
    <?php endif; ?>

    <!-- TOAST NOTIFICATION -->
    <div id="toast">📋 Đã sao chép vào bộ nhớ đệm!</div>

    <!-- SCRIPTS -->
    <script>
        let timerInterval = null;

        function toggleMobileMenu() {
            const p = document.getElementById('mobile-nav-panel');
            const icon = document.getElementById('mobile-menu-icon');
            if (!p) return;
            const isHidden = p.style.display === 'none' || !p.classList.contains('open');
            if (isHidden) {
                p.style.display = 'block';
                setTimeout(() => p.classList.add('open'), 10);
                if (icon) icon.textContent = '✕';
            } else {
                p.classList.remove('open');
                setTimeout(() => { p.style.display = 'none'; }, 200);
                if (icon) icon.textContent = '☰';
            }
        }

        function mobileSwitchTab(tabId) {
            toggleMobileMenu();
            switchMainTab(tabId);
        }

        function openModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        }
        function closeModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.remove('active');
            if (id === 'modal-qr-pay' && timerInterval) clearInterval(timerInterval);
        }
        let toastTimer = null;
        function showToast(text, duration = 3000) {
            const t = document.getElementById('toast');
            if (t) {
                t.textContent = text;
                t.style.display = 'flex';
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(() => { t.style.display = 'none'; }, duration);
            }
        }
        async function handleAjaxSubmitPayment(e, form) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const origText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang gửi đơn hàng...';
            }

            try {
                const fd = new FormData(form);
                fd.append('is_ajax', '1');
                const res = await fetch('index.php', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                if (data && data.status === 'success') {
                    closeModal('modal-qr-pay');
                    showToast(data.message, 6000);
                    setTimeout(() => {
                        window.location.href = 'index.php?tab=' + (data.target_tab || 'keys');
                    }, 800);
                } else {
                    alert(data.message || 'Lỗi khi gửi thông tin đơn hàng');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                }
            } catch (err) {
                form.submit();
            }
        }
        function copyText(txt) {
            navigator.clipboard.writeText(txt).then(() => {
                showToast('📋 Đã sao chép: ' + txt);
            }).catch(() => {
                showToast('📋 Đã sao chép: ' + txt);
            });
        }
        function openResetHwidModal(key) {
            document.getElementById('hwid-reset-key-label').textContent = key;
            document.getElementById('hwid-reset-key-input').value = key;
            openModal('modal-reset-hwid-confirm');
        }

        const TAB_BTN_MAP = {
            'tab-overview': 'btn-tab-overview',
            'tab-my-keys': 'btn-tab-keys',
            'tab-orders-history': 'btn-tab-orders',
            'tab-wallet-view': 'btn-tab-wallet',
            'tab-cloud-storage': 'btn-tab-cloud',
            'tab-team': 'btn-tab-team',
            'tab-ai-connection': 'btn-tab-ai',
            'tab-downloads': 'btn-tab-downloads',
            'tab-buy-key': 'btn-tab-buy',
            'tab-features-view': 'btn-tab-features',
            'tab-bugs-view': 'btn-tab-bugs',
            'tab-settings': 'btn-tab-settings'
        };

        // User Orders Filter (Text, Date Range, Status)
        function filterUserOrderTable() {
            const q = (document.getElementById('user-order-search')?.value || '').toLowerCase().trim();
            const fromDate = document.getElementById('user-filter-date-from')?.value || '';
            const toDate = document.getElementById('user-filter-date-to')?.value || '';
            const status = document.getElementById('user-filter-status')?.value || '';

            let visibleCount = 0;
            const rows = document.querySelectorAll('.user-order-row');
            rows.forEach(r => {
                const s = r.getAttribute('data-search') || '';
                const d = r.getAttribute('data-date') || '';
                const st = r.getAttribute('data-status') || '';

                const matchQ = !q || s.includes(q);
                const matchFrom = !fromDate || d >= fromDate;
                const matchTo = !toDate || d <= toDate;
                const matchStatus = !status || st === status;

                if (matchQ && matchFrom && matchTo && matchStatus) {
                    r.style.display = '';
                    visibleCount++;
                } else {
                    r.style.display = 'none';
                }
            });

            const statusEl = document.getElementById('user-order-filter-status');
            if (statusEl) {
                if (q || fromDate || toDate || status) {
                    statusEl.textContent = `Khớp ${visibleCount} / ${rows.length}`;
                    statusEl.className = 'badge badge-warning';
                } else {
                    statusEl.textContent = `Tất cả (${rows.length})`;
                    statusEl.className = 'badge badge-info';
                }
            }

            const noMatch = document.getElementById('user-orders-no-match');
            if (noMatch) {
                noMatch.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
            }
        }

        function setUserDatePreset(preset, btn) {
            document.querySelectorAll('.filter-date-preset-user').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            const fromInput = document.getElementById('user-filter-date-from');
            const toInput = document.getElementById('user-filter-date-to');
            if (!fromInput || !toInput) return;

            const today = new Date();
            const formatDate = (d) => {
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            if (preset === 'today') {
                const todayStr = formatDate(today);
                fromInput.value = todayStr;
                toInput.value = todayStr;
            } else if (preset === 'yesterday') {
                const yest = new Date(today);
                yest.setDate(yest.getDate() - 1);
                const yestStr = formatDate(yest);
                fromInput.value = yestStr;
                toInput.value = yestStr;
            } else if (preset === '7days') {
                const past = new Date(today);
                past.setDate(past.getDate() - 6);
                fromInput.value = formatDate(past);
                toInput.value = formatDate(today);
            } else if (preset === 'this_month') {
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                fromInput.value = formatDate(firstDay);
                toInput.value = formatDate(today);
            } else { // 'all'
                fromInput.value = '';
                toInput.value = '';
            }

            filterUserOrderTable();
        }

        function switchProductTab(tabId, btnId) {
            document.querySelectorAll('.prod-tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.prod-tab-btn').forEach(btn => {
                btn.classList.remove('active', 'btn-emerald', 'btn-primary');
                btn.classList.add('btn-outline');
            });
            const targetEl = document.getElementById(tabId);
            const targetBtn = document.getElementById(btnId);
            if (targetEl) targetEl.style.display = 'block';
            if (targetBtn) {
                targetBtn.classList.add('active', 'btn-emerald');
                targetBtn.classList.remove('btn-outline');
            }
        }

        function switchMainTab(tabId) {
            document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.dash-nav-btn, .v3-nav-item').forEach(el => el.classList.remove('active'));
            const target = document.getElementById(tabId);
            if (target) target.style.display = 'block';
            if (TAB_BTN_MAP[tabId]) {
                const b = document.getElementById(TAB_BTN_MAP[tabId]);
                if (b) b.classList.add('active');
            }
            const breadcrumbEl = document.getElementById('v3-breadcrumb-text');
            const titleMap = {
                'tab-overview': 'Trang Chủ',
                'tab-my-keys': 'Bản Quyền',
                'tab-orders-history': 'Lịch Sử Giao Dịch',
                'tab-wallet-view': 'Ví & Token',
                'tab-cloud-storage': 'Cloud Storage',
                'tab-team': 'Đội Nhóm (Team)',
                'tab-ai-connection': 'AI Connection',
                'tab-downloads': 'Tải Phần Mềm',
                'tab-buy-key': 'Mua Bản Quyền',
                'tab-features-view': 'Tính Năng Mới',
                'tab-bugs-view': 'Báo Lỗi',
                'tab-settings': 'Cài Đặt'
            };
            if (breadcrumbEl && titleMap[tabId]) {
                breadcrumbEl.textContent = titleMap[tabId];
            }
            if (tabId === 'tab-cloud-storage') {
                initCloudUI();
            }
            const sidebar = document.getElementById('v3-app-sidebar');
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        }

        function openV3Modal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'flex';
        }
        function closeV3Modal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }
        function toggleV3MobileSidebar() {
            const sidebar = document.getElementById('v3-app-sidebar');
            if (sidebar) {
                sidebar.classList.toggle('mobile-open');
            }
        }
        function openCreateAiKeyModal() {
            openV3Modal('modal-v3-create-ai-key');
        }
        function submitCreateAiKey() {
            const name = (document.getElementById('v3-new-aikey-name')?.value || '').trim();
            const spaceId = document.getElementById('v3-new-aikey-space')?.value || '';
            if (!name) {
                alert('Vui lòng nhập tên gợi nhớ cho AI Key');
                return;
            }
            if (!spaceId) {
                alert('Vui lòng chọn Không gian làm việc');
                return;
            }
            fetch('/api/v1/ai/keys', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ display_name: name, workspace_id: spaceId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.key) {
                    closeV3Modal('modal-v3-create-ai-key');
                    const secretInput = document.getElementById('v3-revealed-secret-input');
                    if (secretInput) {
                        secretInput.value = typeof data.key === 'string' ? data.key : (data.key.key || data.key.secret || '');
                    }
                    openV3Modal('modal-v3-show-ai-secret');
                } else {
                    alert('Lỗi: ' + (data.error || data.message || 'Không thể tạo khóa'));
                }
            })
            .catch(err => {
                alert('Lỗi kết nối máy chủ khi tạo AI key');
            });
        }
        function copyRevealedAiSecret() {
            const input = document.getElementById('v3-revealed-secret-input');
            if (!input) return;
            navigator.clipboard.writeText(input.value).then(() => {
                if (typeof showToast === 'function') {
                    showToast('Đã sao chép AI Secret Key vào bộ nhớ tạm!', 'success');
                } else {
                    alert('Đã sao chép AI Secret Key!');
                }
            }).catch(() => {
                input.select();
                document.execCommand('copy');
                if (typeof showToast === 'function') {
                    showToast('Đã sao chép AI Secret Key!', 'success');
                }
            });
        }
        function revokeAiKey(keyId) {
            if (!confirm('Bạn có chắc chắn muốn thu hồi AI Access Key này? Mọi ứng dụng đang kết nối bằng khóa này sẽ lập tức mất quyền truy cập!')) {
                return;
            }
            fetch('/api/v1/ai/keys/' + encodeURIComponent(keyId) + '/revoke', {
                method: 'POST'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success || data.ok) {
                    if (typeof showToast === 'function') {
                        showToast('Đã thu hồi AI Access Key thành công!', 'success');
                    } else {
                        alert('Đã thu hồi AI Access Key!');
                    }
                    setTimeout(() => location.reload(), 800);
                } else {
                    alert('Lỗi: ' + (data.error || data.message || 'Không thể thu hồi khóa'));
                }
            })
            .catch(() => {
                alert('Lỗi kết nối khi thu hồi AI Key');
            });
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // ☁️ 2TOOLNE CLOUD STORAGE ENGINE (RESTFUL & DIRECT RESUMABLE UPLOAD)
        // ═══════════════════════════════════════════════════════════════════════════
        let currentCloudSpaceId = null;
        let currentCloudFolderId = null;
        let cloudBreadcrumbs = [{ id: null, name: '🏠 Thư mục gốc' }];
        let cloudSelectedFiles = [];
        let isCloudUploading = false;
        let cloudAllFiles = [];
        let cloudAllFolders = [];
        let cloudSearchQuery = '';
        let cloudCategoryFilter = 'all';
        let cloudViewMode = 'list';

        function initCloudUI() {
            const sel = document.getElementById('cloud-space-select');
            if (sel && sel.value) {
                currentCloudSpaceId = sel.value;
            }
            if (!currentCloudSpaceId) {
                fetch('/api/v1/cloud/spaces')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.spaces && data.spaces.length > 0) {
                            currentCloudSpaceId = data.spaces[0].id;
                            if (sel) {
                                sel.innerHTML = data.spaces.map(s => `
                                    <option value="${s.id}" data-status="${s.status}">
                                        ${s.owner_type === 'TEAM' ? '👥 ' : '👤 '}${escapeHtml(s.name)} (${s.owner_type === 'TEAM' ? 'Team Space' : 'Cá Nhân'})
                                    </option>
                                `).join('');
                                sel.value = currentCloudSpaceId;
                            }
                            refreshCloudView();
                        }
                    })
                    .catch(() => {});
                return;
            }
            refreshCloudView();
        }

        let currentActiveTeamId = null;
        let currentActiveTeamRole = null;
        let currentActiveTeamName = '';

        function onCloudSpaceChanged(spaceId) {
            currentCloudSpaceId = spaceId;
            currentCloudFolderId = null;
            cloudBreadcrumbs = [{ id: null, name: '🏠 Thư mục gốc' }];

            // Check if selected space is a Team Space
            const selectEl = document.getElementById('cloud-space-select');
            const opt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
            const isTeam = opt && opt.getAttribute('data-owner-type') === 'TEAM';
            const teamBtn = document.getElementById('btn-cloud-team-manage');
            if (teamBtn) {
                teamBtn.style.display = isTeam ? 'inline-flex' : 'none';
            }
            if (isTeam) {
                currentActiveTeamId = opt.getAttribute('data-owner-id');
                currentActiveTeamRole = opt.getAttribute('data-user-role') || 'MEMBER';
                currentActiveTeamName = opt.getAttribute('data-space-name') || opt.text || 'Team Space';
            } else {
                currentActiveTeamId = null;
                currentActiveTeamRole = null;
                currentActiveTeamName = '';
            }

            refreshCloudView();
        }

        function toggleUserTeamSlotOptions() {
            const box = document.getElementById('user-team-slot-options-box');
            if (box) {
                box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
            }
        }

        function openTeamManagementModal() {
            if (!currentActiveTeamId) {
                alert('Không gian hiện tại không phải là Team Space!');
                return;
            }
            const teamId = currentActiveTeamId;
            document.getElementById('user-team-modal-sub').textContent = `Mã nhóm: ${teamId} • Không gian: ${currentActiveTeamName}`;
            document.getElementById('user-team-modal-slots').textContent = 'Đang kiểm tra...';
            document.getElementById('user-team-invite-team-id').value = teamId;
            document.getElementById('user-team-slot-options-box').style.display = 'none';

            // Reset permissions UI
            const inviteBox = document.getElementById('user-team-invite-box');
            const buySlotsBtn = document.getElementById('user-team-buy-slots-btn-wrap');
            if (inviteBox) inviteBox.style.display = 'none';
            if (buySlotsBtn) buySlotsBtn.style.display = 'none';

            document.getElementById('user-team-members-tbody').innerHTML = `
                <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted-foreground)">⏳ Đang tải dữ liệu thành viên...</td></tr>
            `;

            openModal('modal-cloud-team-members');

            fetch(`?ajax=get_team_details&team_id=${encodeURIComponent(teamId)}`)
                .then(res => res.json())
                .then(data => {
                    if (!data || data.status !== 'success' || !data.team) {
                        document.getElementById('user-team-members-tbody').innerHTML = `
                            <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--danger)">❌ ${escapeHtml(data.message || 'Không thể tải thông tin Team')}</td></tr>
                        `;
                        return;
                    }

                    const t = data.team;
                    const members = data.members || [];
                    const myRole = data.my_role || 'MEMBER';
                    const activeCount = members.filter(m => m.status === 'ACTIVE').length;
                    const remainingSlots = Math.max(0, parseInt(t.member_slots || 0) - activeCount);

                    document.getElementById('user-team-modal-slots').textContent = `${activeCount} / ${t.member_slots} slots (${remainingSlots} slot trống)`;

                    // Show invite & buy slots if OWNER or ADMIN
                    const isOwner = myRole === 'OWNER';
                    const isAdmin = myRole === 'ADMIN';
                    if (isOwner || isAdmin) {
                        if (inviteBox) inviteBox.style.display = 'block';
                        if (buySlotsBtn) buySlotsBtn.style.display = 'block';
                    }

                    if (members.length === 0) {
                        document.getElementById('user-team-members-tbody').innerHTML = `
                            <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted-foreground)">Chưa có thành viên nào.</td></tr>
                        `;
                        return;
                    }

                    document.getElementById('user-team-members-tbody').innerHTML = members.map(m => {
                        const mRole = m.role || 'MEMBER';
                        const isThisOwner = mRole === 'OWNER';
                        const isThisAdmin = mRole === 'ADMIN';

                        let roleBadge = isThisOwner 
                            ? '<span class="badge badge-emerald" style="font-size:10.5px">👑 Trưởng Nhóm</span>' 
                            : (isThisAdmin ? '<span class="badge badge-purple" style="font-size:10.5px">🛡️ Quản Lý Nhóm</span>' : '<span class="badge" style="font-size:10.5px;color:var(--muted-foreground)">👤 Thành Viên</span>');

                        // Role Selector for OWNER
                        let roleHtml = roleBadge;
                        if (isOwner) {
                            roleHtml = `
                                <select class="form-input" style="padding:2px 6px;font-size:11px;height:26px;width:auto;display:inline-block;border-radius:4px" onchange="userChangeTeamRole('${escapeHtml(teamId)}', '${escapeHtml(m.user_id)}', this.value, '${escapeHtml(m.username)}')">
                                    <option value="MEMBER" ${mRole === 'MEMBER' ? 'selected' : ''}>👤 Thành Viên</option>
                                    <option value="ADMIN" ${mRole === 'ADMIN' ? 'selected' : ''}>🛡️ Quản Lý Nhóm</option>
                                    <option value="OWNER" ${mRole === 'OWNER' ? 'selected' : ''}>👑 Trưởng Nhóm</option>
                                </select>
                            `;
                        }

                        const statusBadge = m.status === 'ACTIVE' 
                            ? '<span class="badge badge-emerald" style="font-size:10px">🟢 Hoạt Động</span>' 
                            : '<span class="badge badge-warning" style="font-size:10px">🟡 Đã Mời</span>';

                        // Removal permission
                        let removeBtn = '<span class="text-subtle" style="font-size:11px">--</span>';
                        if (isOwner && !isThisOwner) {
                            removeBtn = `
                                <form method="POST" style="display:inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa thành viên @${escapeHtml(m.username)} khỏi nhóm?')">
                                    <input type="hidden" name="action" value="user_remove_team_member">
                                    <input type="hidden" name="team_id" value="${escapeHtml(teamId)}">
                                    <input type="hidden" name="member_user_id" value="${escapeHtml(m.user_id)}">
                                    <button type="submit" class="btn btn-danger btn-xs" title="Xóa khỏi nhóm">🗑️ Xóa</button>
                                </form>
                            `;
                        } else if (isAdmin && mRole === 'MEMBER') {
                            removeBtn = `
                                <form method="POST" style="display:inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa thành viên @${escapeHtml(m.username)} khỏi nhóm?')">
                                    <input type="hidden" name="action" value="user_remove_team_member">
                                    <input type="hidden" name="team_id" value="${escapeHtml(teamId)}">
                                    <input type="hidden" name="member_user_id" value="${escapeHtml(m.user_id)}">
                                    <button type="submit" class="btn btn-danger btn-xs" title="Xóa khỏi nhóm">🗑️ Xóa</button>
                                </form>
                            `;
                        }

                        return `
                            <tr>
                                <td>
                                    <b style="color:var(--foreground);font-size:13px">${escapeHtml(m.fullname || m.username)}</b>
                                    <div style="font-size:11px;color:var(--muted-foreground)">@${escapeHtml(m.username)}</div>
                                </td>
                                <td>${roleHtml}</td>
                                <td>${statusBadge}</td>
                                <td style="text-align:right">${removeBtn}</td>
                            </tr>
                        `;
                    }).join('');
                })
                .catch(err => {
                    document.getElementById('user-team-members-tbody').innerHTML = `
                        <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--danger)">❌ Lỗi kết nối tải thành viên: ${escapeHtml(err.message)}</td></tr>
                    `;
                });
        }

        function userChangeTeamRole(teamId, memberUserId, newRole, username) {
            let confirmMsg = `Đổi vai trò của @${username} sang ${newRole}?`;
            if (newRole === 'OWNER') {
                confirmMsg = `⚠️ Chuyển giao quyền Trưởng nhóm cho @${username}?\nBạn sẽ trở thành Quản Lý Nhóm. Bạn chắc chắn muốn chuyển giao?`;
            }
            if (!confirm(confirmMsg)) {
                openTeamManagementModal();
                return;
            }

            const formData = new FormData();
            formData.append('action', 'user_update_team_role');
            formData.append('team_id', teamId);
            formData.append('member_user_id', memberUserId);
            formData.append('role', newRole);

            fetch('', { method: 'POST', body: formData })
                .then(res => res.text())
                .then(() => {
                    openTeamManagementModal();
                })
                .catch(err => {
                    alert('Lỗi: ' + err.message);
                });
        }

        function refreshCloudView() {
            if (!currentCloudSpaceId) return;
            loadCloudQuota(currentCloudSpaceId);
            loadCloudFolder(currentCloudSpaceId, currentCloudFolderId);
            loadCloudTrashCount(currentCloudSpaceId);
        }

        function loadCloudQuota(spaceId) {
            fetch(`/api/v1/cloud/spaces/${spaceId}/quota`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.quota) return;
                    const q = data.quota;
                    const usedBytes = q.used_bytes || 0;
                    const reservedBytes = q.reserved_bytes || 0;
                    const effectiveBytes = q.effective_quota_bytes || 1;
                    const isOverQuota = q.is_over_quota || (usedBytes >= effectiveBytes);

                    const usageTextEl = document.getElementById('cloud-quota-usage-text');
                    const totalTextEl = document.getElementById('cloud-quota-total-text');
                    const percentEl = document.getElementById('cloud-quota-percent');
                    const fillBarEl = document.getElementById('cloud-quota-fill-bar');
                    const bannerEl = document.getElementById('cloud-overquota-banner');
                    const badgeEl = document.getElementById('cloud-space-status-badge');
                    const statusTextEl = document.getElementById('cloud-status-text');
                    const resWrapperEl = document.getElementById('cloud-quota-reserved-wrapper');
                    const resTextEl = document.getElementById('cloud-quota-reserved-text');

                    const percent = Math.min(100, Math.round((usedBytes / effectiveBytes) * 1000) / 10);
                    if (usageTextEl) usageTextEl.textContent = formatBytes(usedBytes);
                    if (totalTextEl) totalTextEl.textContent = formatBytes(effectiveBytes);
                    if (percentEl) {
                        percentEl.textContent = percent + '%';
                        percentEl.style.color = isOverQuota ? '#ef4444' : (percent > 85 ? '#f59e0b' : 'var(--emerald)');
                    }
                    if (fillBarEl) {
                        fillBarEl.style.width = percent + '%';
                        if (isOverQuota) {
                            fillBarEl.classList.add('over-quota');
                        } else {
                            fillBarEl.classList.remove('over-quota');
                        }
                    }

                    // Stat chips
                    const chipQuota = document.getElementById('cloud-chip-quota');
                    const chipUsed = document.getElementById('cloud-chip-used');
                    const chipFree = document.getElementById('cloud-chip-free');
                    if (chipQuota) chipQuota.textContent = formatBytes(effectiveBytes);
                    if (chipUsed) chipUsed.textContent = formatBytes(usedBytes);
                    if (chipFree) {
                        const freeBytes = Math.max(0, effectiveBytes - usedBytes);
                        chipFree.textContent = formatBytes(freeBytes);
                        chipFree.style.color = isOverQuota ? '#ef4444' : 'var(--emerald)';
                    }

                    const statusSubEl = document.getElementById('cloud-quota-status-sub');
                    if (statusSubEl) {
                        statusSubEl.innerHTML = isOverQuota 
                            ? 'Trạng thái: <b style="color:#ef4444">Vượt hạn mức (Chỉ đọc)</b>' 
                            : 'Trạng thái: <b style="color:var(--emerald)">Bình thường</b>';
                    }

                    if (resWrapperEl && resTextEl) {
                        if (reservedBytes > 0) {
                            resWrapperEl.style.display = 'inline';
                            resTextEl.textContent = formatBytes(reservedBytes);
                        } else {
                            resWrapperEl.style.display = 'none';
                        }
                    }

                    if (bannerEl) bannerEl.style.display = isOverQuota ? 'flex' : 'none';
                    if (statusTextEl) {
                        statusTextEl.textContent = isOverQuota ? 'Vượt Hạn Mức (Chỉ Đọc)' : 'Đang Hoạt Động';
                    }
                    if (badgeEl) {
                        if (isOverQuota) {
                            badgeEl.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                            badgeEl.style.background = 'rgba(239, 68, 68, 0.12)';
                            badgeEl.style.color = '#ef4444';
                        } else {
                            badgeEl.style.borderColor = 'rgba(16, 185, 129, 0.35)';
                            badgeEl.style.background = 'rgba(16, 185, 129, 0.12)';
                            badgeEl.style.color = '#34d399';
                        }
                    }

                    const upBtn = document.getElementById('btn-cloud-upload');
                    const mkBtn = document.getElementById('btn-cloud-mkdir');
                    if (upBtn) upBtn.disabled = isOverQuota;
                    if (mkBtn) mkBtn.disabled = isOverQuota;
                })
                .catch(() => {});
        }

        function loadCloudFolder(spaceId, folderId) {
            const loading = document.getElementById('cloud-explorer-loading');
            const content = document.getElementById('cloud-explorer-content');
            if (loading) loading.style.display = 'block';
            if (content) content.style.display = 'none';

            const url = `/api/v1/cloud/spaces/${spaceId}/files` + (folderId ? `?folder_id=${encodeURIComponent(folderId)}` : '');
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (loading) loading.style.display = 'none';
                    if (content) content.style.display = 'block';
                    if (!data.success) {
                        showToast(data.message || 'Lỗi tải danh sách tệp tin');
                        return;
                    }

                    cloudAllFolders = data.folders || [];
                    cloudAllFiles = data.files || [];
                    const chipFiles = document.getElementById('cloud-chip-files');
                    if (chipFiles) chipFiles.textContent = cloudAllFiles.length;

                    renderBreadcrumbs();
                    renderFolders(cloudAllFolders);
                    filterAndRenderCloudFiles();
                })
                .catch(err => {
                    if (loading) loading.style.display = 'none';
                    if (content) content.style.display = 'block';
                    showToast('Không thể kết nối đến máy chủ Cloud');
                });
        }

        function onCloudSearchInput(val) {
            cloudSearchQuery = (val || '').trim().toLowerCase();
            filterAndRenderCloudFiles();
        }

        function setCloudCategoryFilter(cat) {
            cloudCategoryFilter = cat;
            document.querySelectorAll('.cloud-filter-pill').forEach(el => {
                if (el.getAttribute('data-cat') === cat) el.classList.add('active');
                else el.classList.remove('active');
            });
            filterAndRenderCloudFiles();
        }

        function setCloudViewMode(mode) {
            cloudViewMode = mode;
            const btnList = document.getElementById('btn-cloud-view-list');
            const btnGrid = document.getElementById('btn-cloud-view-grid');
            if (btnList) btnList.classList.toggle('active', mode === 'list');
            if (btnGrid) btnGrid.classList.toggle('active', mode === 'grid');
            filterAndRenderCloudFiles();
        }

        function getCloudFileCat(ext) {
            ext = (ext || '').toLowerCase();
            if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'].includes(ext)) return 'images';
            if (['mp4', 'mkv', 'avi', 'mov', 'webm', 'wmv'].includes(ext)) return 'videos';
            if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2'].includes(ext)) return 'archives';
            if (['pdf', 'doc', 'docx', 'txt', 'csv', 'xlsx', 'xls', 'json'].includes(ext)) return 'docs';
            return 'other';
        }

        function filterAndRenderCloudFiles() {
            let files = cloudAllFiles;
            if (cloudCategoryFilter !== 'all') {
                files = files.filter(f => getCloudFileCat(f.extension) === cloudCategoryFilter);
            }
            if (cloudSearchQuery) {
                files = files.filter(f => {
                    const name = (f.filename || '').toLowerCase();
                    const ext = (f.extension || '').toLowerCase();
                    return name.includes(cloudSearchQuery) || ext.includes(cloudSearchQuery);
                });
            }

            const countBadge = document.getElementById('cloud-files-count-badge');
            const countBadgeGrid = document.getElementById('cloud-files-count-badge-grid');
            if (countBadge) countBadge.textContent = files.length;
            if (countBadgeGrid) countBadgeGrid.textContent = files.length;

            const emptyDropzone = document.getElementById('cloud-empty-dropzone');
            const secTable = document.getElementById('cloud-files-table-section');
            const secGrid = document.getElementById('cloud-files-grid-section');

            // If empty folder & no active search
            if (cloudAllFiles.length === 0 && !cloudSearchQuery && cloudCategoryFilter === 'all') {
                if (emptyDropzone) emptyDropzone.style.display = 'block';
                if (secTable) secTable.style.display = 'none';
                if (secGrid) secGrid.style.display = 'none';
                return;
            }

            if (emptyDropzone) emptyDropzone.style.display = 'none';
            if (cloudViewMode === 'grid') {
                if (secGrid) secGrid.style.display = 'block';
                if (secTable) secTable.style.display = 'none';
                renderFilesGrid(files);
            } else {
                if (secTable) secTable.style.display = 'block';
                if (secGrid) secGrid.style.display = 'none';
                renderFilesTable(files);
            }
        }

        function renderBreadcrumbs() {
            const container = document.getElementById('cloud-breadcrumbs-container');
            if (!container) return;
            let html = '';
            cloudBreadcrumbs.forEach((crumb, idx) => {
                const isLast = (idx === cloudBreadcrumbs.length - 1);
                if (isLast) {
                    html += `<span class="cloud-crumb active">${escapeHtml(crumb.name)}</span>`;
                } else {
                    html += `<span class="cloud-crumb" onclick="navigateCloudCrumb(${idx})">${escapeHtml(crumb.name)}</span>`;
                    html += `<span class="cloud-crumb-sep">/</span>`;
                }
            });
            container.innerHTML = html;
        }

        function navigateCloudCrumb(index) {
            cloudBreadcrumbs = cloudBreadcrumbs.slice(0, index + 1);
            currentCloudFolderId = cloudBreadcrumbs[cloudBreadcrumbs.length - 1].id;
            refreshCloudView();
        }

        function navigateCloudFolder(folderId, folderName) {
            if (folderId === null) {
                cloudBreadcrumbs = [{ id: null, name: '🏠 Thư mục gốc' }];
            } else {
                cloudBreadcrumbs.push({ id: folderId, name: folderName || 'Thư mục con' });
            }
            currentCloudFolderId = folderId;
            refreshCloudView();
        }

        function renderFolders(folders) {
            const sec = document.getElementById('cloud-folders-section');
            const grid = document.getElementById('cloud-folders-grid');
            if (!sec || !grid) return;

            if (folders.length === 0) {
                sec.style.display = 'none';
                grid.innerHTML = '';
                return;
            }

            sec.style.display = 'block';
            grid.innerHTML = folders.map(f => `
                <div class="cloud-card-item" onclick="navigateCloudFolder('${f.id}', '${escapeJsStr(f.name)}')">
                    <div class="cloud-item-header">
                        <div class="cloud-item-icon">📁</div>
                        <div>
                            <div class="cloud-item-name">${escapeHtml(f.name)}</div>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px">${f.created_at || ''}</div>
                        </div>
                    </div>
                    <div class="cloud-item-footer">
                        <span>Thư mục</span>
                        <span style="color:var(--primary)">Mở ➔</span>
                    </div>
                </div>
            `).join('');
        }

        function renderFilesTable(files) {
            const tbody = document.getElementById('cloud-files-tbody');
            if (!tbody) return;

            if (files.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center;padding:36px;color:var(--muted-foreground)">
                            🔍 Không tìm thấy tệp tin nào phù hợp với bộ lọc hoặc từ khóa.
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = files.map(f => {
                const ext = (f.extension || '').toLowerCase();
                const cat = getCloudFileCat(ext);
                let icon = '📄';
                let badgeClass = 'cloud-badge-doc';
                if (cat === 'images') { icon = '🖼️'; badgeClass = 'cloud-badge-img'; }
                else if (cat === 'videos') { icon = '🎬'; badgeClass = 'cloud-badge-vid'; }
                else if (cat === 'archives') { icon = '📦'; badgeClass = 'cloud-badge-zip'; }

                const isImgOrVid = ['images', 'videos'].includes(cat);

                return `
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <span class="cloud-file-badge ${badgeClass}">${icon}</span>
                                <div>
                                    <div style="font-weight:600;color:var(--foreground);word-break:break-word">${escapeHtml(f.filename)}</div>
                                    <div style="font-size:11px;color:var(--muted-subtle)">ID: ${f.id.substring(0, 8)}...</div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:var(--font-mono);font-size:12.5px;color:var(--foreground)">${formatBytes(f.size_bytes)}</td>
                        <td>
                            <span class="badge ${f.app_id === 'UPSCALE' ? 'badge-info' : 'badge-emerald'}" style="font-size:10.5px">
                                ${escapeHtml(f.app_id || 'CLOUD')}
                            </span>
                        </td>
                        <td style="font-size:12px;color:var(--muted-foreground)">${f.created_at ? f.created_at.substring(0, 16) : ''}</td>
                        <td style="text-align:right">
                            <div style="display:inline-flex;gap:6px;align-items:center">
                                <a href="/api/v1/cloud/files/${f.id}/download" class="btn btn-outline btn-xs" title="Tải về máy tính">
                                    ⬇️ Tải Về
                                </a>
                                ${isImgOrVid ? `
                                    <a href="/api/v1/cloud/files/${f.id}/preview" target="_blank" class="btn btn-outline btn-xs" title="Xem trước trong tab mới">
                                        👁️
                                    </a>
                                ` : ''}
                                <button type="button" class="btn btn-outline btn-xs" style="color:var(--danger)" onclick="trashCloudFile('${f.id}')" title="Chuyển vào thùng rác">
                                    🗑️
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderFilesGrid(files) {
            const grid = document.getElementById('cloud-files-grid');
            if (!grid) return;

            if (files.length === 0) {
                grid.innerHTML = `
                    <div style="grid-column:1/-1;text-align:center;padding:36px;color:var(--muted-foreground)">
                        🔍 Không tìm thấy tệp tin nào phù hợp với bộ lọc hoặc từ khóa.
                    </div>
                `;
                return;
            }

            grid.innerHTML = files.map(f => {
                const ext = (f.extension || '').toLowerCase();
                const cat = getCloudFileCat(ext);
                let icon = '📄';
                let badgeClass = 'cloud-badge-doc';
                if (cat === 'images') { icon = '🖼️'; badgeClass = 'cloud-badge-img'; }
                else if (cat === 'videos') { icon = '🎬'; badgeClass = 'cloud-badge-vid'; }
                else if (cat === 'archives') { icon = '📦'; badgeClass = 'cloud-badge-zip'; }

                const isImgOrVid = ['images', 'videos'].includes(cat);

                return `
                    <div class="cloud-card-item">
                        <div style="display:flex;align-items:flex-start;gap:10px">
                            <span class="cloud-file-badge ${badgeClass}" style="width:36px;height:36px;font-size:18px">${icon}</span>
                            <div style="overflow:hidden;flex:1">
                                <div style="font-weight:600;font-size:13px;color:var(--foreground);white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escapeHtml(f.filename)}">
                                    ${escapeHtml(f.filename)}
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-foreground);font-family:var(--font-mono);margin-top:2px">
                                    ${formatBytes(f.size_bytes)}
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.06)">
                            <span style="font-size:11px;color:var(--muted-subtle)">${f.created_at ? f.created_at.substring(5, 16) : ''}</span>
                            <div style="display:flex;gap:4px">
                                <a href="/api/v1/cloud/files/${f.id}/download" class="btn btn-outline btn-xs" title="Tải về máy tính">⬇️</a>
                                ${isImgOrVid ? `<a href="/api/v1/cloud/files/${f.id}/preview" target="_blank" class="btn btn-outline btn-xs" title="Xem">👁️</a>` : ''}
                                <button type="button" class="btn btn-outline btn-xs" style="color:var(--danger)" onclick="trashCloudFile('${f.id}')" title="Xóa">🗑️</button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function promptCreateCloudFolder() {
            const name = prompt('Nhập tên thư mục mới:');
            if (!name || !name.trim()) return;
            if (!currentCloudSpaceId) {
                showToast('Vui lòng chọn Không gian lưu trữ');
                return;
            }

            fetch(`/api/v1/cloud/spaces/${currentCloudSpaceId}/folders`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name.trim(),
                    parent_id: currentCloudFolderId || null
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('✅ Đã tạo thư mục thành công!');
                    refreshCloudView();
                } else {
                    showToast(data.message || 'Không thể tạo thư mục');
                }
            })
            .catch(() => showToast('Lỗi mạng khi tạo thư mục'));
        }

        function trashCloudFile(fileId) {
            if (!confirm('Bạn có chắc chắn muốn chuyển tệp này vào Thùng Rác?')) return;
            fetch(`/api/v1/cloud/files/${fileId}/trash`, { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('🗑️ Đã chuyển tệp vào Thùng Rác');
                        refreshCloudView();
                    } else {
                        showToast(data.message || 'Lỗi khi xóa tệp');
                    }
                })
                .catch(() => showToast('Lỗi mạng khi xóa tệp'));
        }

        function openCloudUploadModal() {
            cloudSelectedFiles = [];
            const inp = document.getElementById('cloud-file-input');
            if (inp) inp.value = '';
            document.getElementById('cloud-selected-files-list').style.display = 'none';
            document.getElementById('cloud-upload-progress-container').style.display = 'none';
            document.getElementById('btn-cloud-start-upload').disabled = false;
            openModal('modal-cloud-upload');
        }

        function onCloudFileSelected(files) {
            if (!files || files.length === 0) return;
            cloudSelectedFiles = Array.from(files);
            const listCont = document.getElementById('cloud-selected-files-list');
            const itemsCont = document.getElementById('cloud-file-items-container');
            listCont.style.display = 'block';
            itemsCont.innerHTML = cloudSelectedFiles.map(f => `
                <div style="display:flex;justify-content:space-between;background:var(--surface-2);padding:6px 10px;border-radius:4px;font-size:12px;border:1px solid var(--border)">
                    <span style="font-weight:500;color:var(--foreground)">${escapeHtml(f.name)}</span>
                    <span style="color:var(--muted-foreground);font-family:var(--font-mono)">${formatBytes(f.size)}</span>
                </div>
            `).join('');
        }

        async function startCloudUploadQueue() {
            if (cloudSelectedFiles.length === 0) {
                showToast('Vui lòng chọn ít nhất một tệp để tải lên');
                return;
            }
            if (!currentCloudSpaceId) {
                showToast('Chưa chọn không gian lưu trữ');
                return;
            }

            const startBtn = document.getElementById('btn-cloud-start-upload');
            const cancelBtn = document.getElementById('btn-cloud-cancel-upload');
            const progCont = document.getElementById('cloud-upload-progress-container');
            const statusText = document.getElementById('cloud-upload-status-text');
            const percentText = document.getElementById('cloud-upload-percent-text');
            const barFill = document.getElementById('cloud-upload-bar-fill');
            const bytesText = document.getElementById('cloud-upload-bytes-text');

            startBtn.disabled = true;
            cancelBtn.disabled = true;
            progCont.style.display = 'block';
            isCloudUploading = true;

            for (let i = 0; i < cloudSelectedFiles.length; i++) {
                const file = cloudSelectedFiles[i];
                statusText.textContent = `[${i + 1}/${cloudSelectedFiles.length}] Đang tải lên: ${file.name}`;
                percentText.textContent = '0%';
                barFill.style.width = '0%';
                bytesText.textContent = `0 / ${formatBytes(file.size)}`;

                let uploadId = null;
                try {
                    // Step 1: Init Direct Resumable Upload
                    const initRes = await fetch(`/api/v1/cloud/spaces/${currentCloudSpaceId}/uploads/create`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            folder_id: currentCloudFolderId || null,
                            file_name: file.name,
                            file_size: file.size,
                            mime_type: file.type || 'application/octet-stream'
                        })
                    });
                    const initData = await initRes.json();
                    if (!initRes.ok || !initData.success) {
                        throw new Error(initData.message || 'Không thể khởi tạo phiên tải lên');
                    }

                    uploadId = initData.upload_id;
                    const sessionUrl = initData.session_url;
                    let realDriveFileId = null;

                    // Step 2: Stream / Put File
                    if (sessionUrl && (sessionUrl.includes('google.com') || sessionUrl.includes('googleapis.com'))) {
                        try {
                            // Direct stream to 2TOOL Cloud Storage Resumable Endpoint
                            const driveRespText = await uploadGoogleDriveResumable(sessionUrl, file, (loaded, total) => {
                                const p = Math.round((loaded / total) * 100);
                                percentText.textContent = p + '%';
                                barFill.style.width = p + '%';
                                bytesText.textContent = `${formatBytes(loaded)} / ${formatBytes(total)}`;
                            });
                            try {
                                const parsed = JSON.parse(driveRespText);
                                if (parsed && parsed.id) realDriveFileId = parsed.id;
                            } catch (eP) {}
                        } catch (directErr) {
                            console.warn('Direct upload notice, transitioning to 2TOOL Cloud Relay:', directErr);
                            statusText.textContent = `[${i + 1}/${cloudSelectedFiles.length}] Đang đồng bộ luồng qua 2TOOL Cloud Stream...`;
                            // Seamless Relay Fallback
                            const relayRes = await fetch(`/api/v1/cloud/uploads/${uploadId}/relay`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': file.type || 'application/octet-stream'
                                },
                                body: file
                            });
                            const relayData = await relayRes.json();
                            if (!relayRes.ok || !relayData.success) {
                                throw new Error(relayData.message || directErr.message || 'Lỗi kết nối tải lên 2TOOL Cloud');
                            }
                            if (relayData.provider_file_id) {
                                realDriveFileId = relayData.provider_file_id;
                            }
                        }
                    } else {
                        // Simulated session
                        for (let p = 10; p <= 100; p += 30) {
                            await new Promise(r => setTimeout(r, 100));
                            percentText.textContent = Math.min(100, p) + '%';
                            barFill.style.width = Math.min(100, p) + '%';
                        }
                    }

                    // Step 3: Finalize
                    statusText.textContent = `[${i + 1}/${cloudSelectedFiles.length}] Đang chốt giao dịch đám mây...`;
                    const finRes = await fetch(`/api/v1/cloud/uploads/${uploadId}/finalize`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            provider_file_id: realDriveFileId || ('cf_file_' + Date.now())
                        })
                    });
                    const finData = await finRes.json();
                    if (!finRes.ok || !finData.success) {
                        throw new Error(finData.message || 'Không thể chốt phiên tải lên');
                    }
                } catch (err) {
                    if (uploadId) {
                        fetch(`/api/v1/cloud/uploads/${uploadId}/abort`, { method: 'POST' }).catch(() => {});
                    }
                    showToast(`Lỗi tải tệp "${file.name}": ${err.message}`);
                    startBtn.disabled = false;
                    cancelBtn.disabled = false;
                    isCloudUploading = false;
                    return;
                }
            }

            isCloudUploading = false;
            startBtn.disabled = false;
            cancelBtn.disabled = false;
            closeModal('modal-cloud-upload');
            showToast('🎉 Tải tất cả tệp lên đám mây thành công!');
            refreshCloudView();
        }

        function uploadGoogleDriveResumable(sessionUrl, file, onProgress) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', sessionUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable && onProgress) {
                        onProgress(e.loaded, e.total);
                    }
                };
                xhr.onload = () => {
                    if (xhr.status === 200 || xhr.status === 201) {
                        resolve(xhr.responseText);
                    } else {
                        reject(new Error('Lỗi truyền tải 2TOOL Cloud: HTTP ' + xhr.status));
                    }
                };
                xhr.onerror = () => reject(new Error('Lỗi kết nối mạng khi truyền luồng lên 2TOOL Cloud'));
                xhr.send(file);
            });
        }

        function loadCloudTrashCount(spaceId) {
            fetch(`/api/v1/cloud/spaces/${spaceId}/trash`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && Array.isArray(data.trash)) {
                        const badge = document.getElementById('cloud-trash-badge');
                        if (badge) badge.textContent = data.trash.length;
                    }
                })
                .catch(() => {});
        }

        function openCloudTrashModal() {
            if (!currentCloudSpaceId) return;
            const tbody = document.getElementById('cloud-trash-tbody');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted-foreground)">Đang tải thùng rác...</td></tr>';
            openModal('modal-cloud-trash');

            fetch(`/api/v1/cloud/spaces/${currentCloudSpaceId}/trash`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.trash) || data.trash.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted-foreground)">Thùng rác rỗng.</td></tr>';
                        return;
                    }

                    tbody.innerHTML = data.trash.map(t => `
                        <tr>
                            <td style="font-weight:500;color:var(--foreground)">${escapeHtml(t.filename)}</td>
                            <td style="font-family:var(--font-mono);font-size:12px">${formatBytes(t.size_bytes)}</td>
                            <td style="font-size:12px;color:var(--muted-foreground)">${t.deleted_at || ''}</td>
                            <td style="text-align:right">
                                <button type="button" class="btn btn-outline btn-xs" style="color:var(--emerald)" onclick="restoreCloudFile('${t.id}')">
                                    Khôi Phục
                                </button>
                                <button type="button" class="btn btn-outline btn-xs" style="color:var(--danger)" onclick="permanentDeleteCloudFile('${t.id}')">
                                    Xóa Vĩnh Viễn
                                </button>
                            </td>
                        </tr>
                    `).join('');
                })
                .catch(() => {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--danger)">Lỗi tải thùng rác.</td></tr>';
                });
        }

        function restoreCloudFile(fileId) {
            fetch(`/api/v1/cloud/files/${fileId}/restore`, { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('✅ Đã khôi phục tệp thành công');
                        openCloudTrashModal();
                        refreshCloudView();
                    } else {
                        showToast(data.message || 'Không thể khôi phục tệp');
                    }
                })
                .catch(() => showToast('Lỗi mạng'));
        }

        function permanentDeleteCloudFile(fileId) {
            if (!confirm('CẢNH BÁO: Tệp sẽ bị xóa vĩnh viễn khỏi 2TOOL Cloud. Không thể hoàn tác! Bạn có chắc không?')) return;
            fetch(`/api/v1/cloud/files/${fileId}/permanent`, { method: 'DELETE' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('🗑️ Đã xóa vĩnh viễn tệp');
                        openCloudTrashModal();
                        refreshCloudView();
                    } else {
                        showToast(data.message || 'Không thể xóa tệp');
                    }
                })
                .catch(() => showToast('Lỗi mạng'));
        }

        window.addEventListener('DOMContentLoaded', () => {
            const dropzone = document.getElementById('cloud-empty-dropzone');
            if (dropzone) {
                ['dragenter', 'dragover'].forEach(name => {
                    dropzone.addEventListener(name, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.add('dragover');
                    });
                });
                ['dragleave', 'drop'].forEach(name => {
                    dropzone.addEventListener(name, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.remove('dragover');
                    });
                });
                dropzone.addEventListener('drop', (e) => {
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        openCloudUploadModal();
                        onCloudFileSelected(e.dataTransfer.files);
                    }
                });
            }

            const spaceSel = document.getElementById('cloud-space-select');
            if (spaceSel && spaceSel.value) {
                onCloudSpaceChanged(spaceSel.value);
            }
        });

        function formatBytes(bytes, decimals = 2) {
            if (!bytes || bytes <= 0) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }

        function escapeJsStr(str) {
            if (!str) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        function openQrPayment(pkgName, pkgPrice, days, tier, product = '2TOOLNE') {
            <?php if (!$user_info): ?>
                openModal('modal-login');
                return;
            <?php endif; ?>
            document.getElementById('qr-pkg-title').textContent = pkgName;
            document.getElementById('qr-pkg-price').textContent = pkgPrice;
            document.getElementById('qr-memo-text').textContent = 'Mua tool ' + '<?= $current_user ?>';
            
            document.getElementById('form-pkg-name').value = pkgName;
            document.getElementById('form-pkg-price').value = pkgPrice;
            document.getElementById('form-pkg-days').value = days;
            document.getElementById('form-pkg-tier').value = tier;
            document.getElementById('form-pkg-product').value = product;

            if (document.getElementById('sepay-form-pkg-name')) {
                document.getElementById('sepay-form-pkg-name').value = pkgName;
                document.getElementById('sepay-form-pkg-price').value = pkgPrice;
                document.getElementById('sepay-form-pkg-days').value = days;
                document.getElementById('sepay-form-pkg-tier').value = tier;
                document.getElementById('sepay-form-pkg-product').value = product;
            }

            const manualDetails = document.getElementById('details-manual-transfer');
            if (manualDetails) manualDetails.removeAttribute('open');

            openModal('modal-qr-pay');
            startCountdown(120);
        }

        function startCountdown(durationSec) {
            if (timerInterval) clearInterval(timerInterval);
            let left = durationSec;
            const timerEl = document.getElementById('countdown-timer');

            function updateDisplay() {
                let m = Math.floor(left / 60);
                let s = left % 60;
                if (timerEl) timerEl.textContent = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }
            updateDisplay();

            timerInterval = setInterval(() => {
                left--;
                if (left < 0) {
                    clearInterval(timerInterval);
                    if (timerEl) timerEl.textContent = 'Hết hạn';
                } else {
                    updateDisplay();
                }
            }, 1000);
        }

        function initSmartOSDetection() {
            const ua = (navigator.userAgent || '').toLowerCase();
            const platform = (navigator.platform || '').toLowerCase();
            const isMac = platform.includes('mac') || ua.includes('macintosh') || ua.includes('mac os');

            const badges = document.querySelectorAll('.os-detect-badge');
            const containers = document.querySelectorAll('.smart-download-container');
            const winBoxes = document.querySelectorAll('.os-box-windows');
            const macBoxes = document.querySelectorAll('.os-box-macos');

            if (isMac) {
                badges.forEach(b => {
                    b.innerHTML = '🍏 Thiết bị của bạn: <b>macOS (Apple Silicon & Intel)</b>';
                    b.className = 'os-detect-badge badge badge-active';
                });
                containers.forEach(c => {
                    const btn = c.querySelector('.smart-download-btn');
                    const icon = c.querySelector('.smart-download-icon');
                    const text = c.querySelector('.smart-download-text');
                    const prod = c.getAttribute('data-product') || 'AUTOEDIT';
                    if (btn) {
                        btn.onclick = () => requestSecureDownload(prod, 'macos');
                        btn.className = 'smart-download-btn btn btn-emerald btn-lg';
                        if (text) text.textContent = 'Tải Cho macOS (Bản Chính Thức)';
                    }
                    if (icon) icon.textContent = '🍏';
                });
                macBoxes.forEach(box => {
                    box.style.borderColor = 'var(--emerald)';
                    box.style.background = 'rgba(16, 185, 129, 0.08)';
                });
            } else {
                badges.forEach(b => {
                    b.innerHTML = '🪟 Thiết bị của bạn: <b>Windows 10/11 64-bit</b>';
                    b.className = 'os-detect-badge badge badge-info';
                });
                containers.forEach(c => {
                    const btn = c.querySelector('.smart-download-btn');
                    const icon = c.querySelector('.smart-download-icon');
                    const text = c.querySelector('.smart-download-text');
                    const prod = c.getAttribute('data-product') || 'AUTOEDIT';
                    if (btn) {
                        btn.onclick = () => requestSecureDownload(prod, 'windows-x64');
                        btn.className = 'smart-download-btn btn btn-accent btn-lg';
                        if (text) text.textContent = 'Tải Cho Windows (.exe Chính Thức)';
                    }
                    if (icon) icon.textContent = '🪟';
                });
                winBoxes.forEach(box => {
                    box.style.borderColor = 'var(--accent)';
                    box.style.background = 'rgba(99, 102, 241, 0.08)';
                });
            }
        }

        window.addEventListener('DOMContentLoaded', initSmartOSDetection);

        // ═══════════════════════════════════════════════════════════════
        // SECURE AUTHENTICATED SOFTWARE DOWNLOAD GATE (CLIENT ENGINE)
        // ═══════════════════════════════════════════════════════════════
        let currentEntitlementProduct = 'AUTOEDIT';
        let currentEntitlementPlatform = 'windows-x64';

        async function requestSecureDownload(product = 'AUTOEDIT', platform = 'windows-x64', packageType = 'installer') {
            currentEntitlementProduct = product;
            currentEntitlementPlatform = platform;

            const btn = (typeof event !== 'undefined' && event && event.currentTarget) ? event.currentTarget : null;
            let oldContent = '';
            if (btn) {
                oldContent = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang xác thực...';
            }

            try {
                const response = await fetch('/api/v1/downloads/request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        product: product,
                        platform: platform,
                        package_type: packageType
                    })
                });

                const res = await response.json();

                if (response.ok && res.success && res.download_url) {
                    if (btn) {
                        btn.innerHTML = '✅ Bắt đầu tải...';
                        setTimeout(() => {
                            btn.innerHTML = oldContent;
                            btn.disabled = false;
                        }, 3500);
                    }
                    // Download via short-lived signed token URL
                    window.location.href = res.download_url;
                    return;
                }

                if (response.status === 401 || res.error === 'AUTH_REQUIRED') {
                    if (typeof openModal === 'function') {
                        openModal('modal-login');
                    } else {
                        alert(res.message || 'Vui lòng đăng nhập hoặc đăng ký tài khoản để tải phần mềm.');
                    }
                    return;
                }

                if (response.status === 403 || res.error === 'ENTITLEMENT_REQUIRED' || res.error === 'EXPIRED_TRIAL' || res.error === 'EXPIRED_LICENSE' || res.error === 'FORBIDDEN_INTERNAL_ONLY') {
                    showDownloadEntitlementModal(res);
                    return;
                }

                alert(res.message || 'Không thể tạo liên kết tải xuống. Vui lòng thử lại sau.');
            } catch (err) {
                console.error('Download gate error:', err);
                alert('Lỗi kết nối máy chủ khi yêu cầu tải về. Vui lòng thử lại.');
            } finally {
                if (btn && btn.disabled) {
                    btn.innerHTML = oldContent;
                    btn.disabled = false;
                }
            }
        }

        function showDownloadEntitlementModal(res) {
            const modal = document.getElementById('modal-download-entitlement');
            if (!modal) {
                alert(res.message || 'Bạn cần bản quyền hoặc kích hoạt dùng thử để tải phần mềm.');
                return;
            }
            const titleEl = document.getElementById('entitlement-modal-title');
            const msgEl = document.getElementById('entitlement-modal-msg');
            const claimBtn = document.getElementById('btn-claim-trial');

            if (titleEl) {
                if (res.error === 'EXPIRED_TRIAL') {
                    titleEl.textContent = 'Bản Dùng Thử Đã Hết Hạn';
                } else if (res.error === 'EXPIRED_LICENSE') {
                    titleEl.textContent = 'Gói Bản Quyền Đã Hết Hạn';
                } else if (res.error === 'FORBIDDEN_INTERNAL_ONLY') {
                    titleEl.textContent = 'Gói Kiểm Thử Nội Bộ';
                } else {
                    titleEl.textContent = 'Cần Bản Quyền Hoặc Dùng Thử';
                }
            }

            if (msgEl) {
                msgEl.textContent = res.message || 'Bạn cần kích hoạt bản dùng thử 3 ngày hoặc sở hữu bản quyền để tải phần mềm.';
            }

            if (claimBtn) {
                claimBtn.style.display = (res.can_claim_trial && res.error === 'ENTITLEMENT_REQUIRED') ? 'block' : 'none';
            }

            openModal('modal-download-entitlement');
        }

        async function handleClaimTrialFromModal() {
            const btn = document.getElementById('btn-claim-trial');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Đang kích hoạt dùng thử...';
            }

            try {
                const response = await fetch('/api/v1/downloads/claim-trial', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                });
                const res = await response.json();

                if (response.ok && res.success) {
                    alert('🎉 ' + (res.message || 'Kích hoạt thành công bản dùng thử 3 ngày! Bắt đầu tải phần mềm...'));
                    closeModal('modal-download-entitlement');
                    requestSecureDownload(currentEntitlementProduct, currentEntitlementPlatform);
                    setTimeout(() => { location.reload(); }, 2000);
                } else {
                    alert(res.message || 'Không thể kích hoạt bản dùng thử. Vui lòng liên hệ hỗ trợ hoặc mua bản quyền.');
                }
            } catch (err) {
                console.error('Claim trial error:', err);
                alert('Lỗi kết nối khi kích hoạt dùng thử.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '✨ Kích Hoạt Dùng Thử 3 Ngày (Miễn Phí)';
                }
            }
        }

        // Auto-open tab from URL ?tab= parameter or #hash (PRG & Hash Router)
        (function() {
            const urlTab = new URLSearchParams(location.search).get('tab');
            const rawHash = (location.hash || '').replace('#', '');
            const tabMap = {
                'keys':            'tab-my-keys',
                'my-keys':         'tab-my-keys',
                'orders':          'tab-orders-history',
                'order':           'tab-orders-history',
                'history':         'tab-orders-history',
                'transactions':    'tab-orders-history',
                'tab-orders-history': 'tab-orders-history',
                'wallet':          'tab-wallet-view',
                'tokens':          'tab-wallet-view',
                'token':           'tab-wallet-view',
                'tab-wallet-view': 'tab-wallet-view',
                'cloud':           'tab-cloud-storage',
                'cloud-storage':   'tab-cloud-storage',
                'tab-cloud-storage': 'tab-cloud-storage',
                'downloads':       'tab-downloads',
                'download':        'tab-downloads',
                'tab-downloads':   'tab-downloads',
                'buy':             'tab-buy-key',
                'buy-key':         'tab-buy-key',
                'pricing':         'tab-buy-key',
                'products':        'tab-buy-key',
                'features':        'tab-features-view',
                'bugs':            'tab-bugs-view',
                'settings':        'tab-settings'
            };
            const target = urlTab || rawHash;
            function handleTabRouting(t) {
                if (!t || !tabMap[t]) return;
                const destId = tabMap[t];
                const el = document.getElementById(destId);
                if (el) {
                    switchMainTab(destId);
                } else if (t === 'downloads' || t === 'download' || t === 'tab-downloads') {
                    // Guest fallback: open login/register modal
                    openModal('modal-login');
                } else if (t === 'cloud' || t === 'cloud-storage' || t === 'tab-cloud-storage') {
                    // Guest fallback: switch to public cloud product tab
                    switchProductTab('ptab-cloud', 'ptab-btn-cloud');
                    const pSec = document.getElementById('products');
                    if (pSec) pSec.scrollIntoView({ behavior: 'smooth' });
                }
            }
            if (target) {
                window.addEventListener('DOMContentLoaded', () => handleTabRouting(target));
            }
            window.addEventListener('hashchange', () => {
                const h = (location.hash || '').replace('#', '');
                handleTabRouting(h);
            });
        })();

        // Click outside to close modal
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                e.target.classList.remove('active');
                if (timerInterval) clearInterval(timerInterval);
            }
        });

        // ESC key to close modal & mobile nav
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.active').forEach(m => {
                    m.classList.remove('active');
                });
                const p = document.getElementById('mobile-nav-panel');
                const icon = document.getElementById('mobile-menu-icon');
                if (p && p.classList.contains('open')) {
                    p.classList.remove('open');
                    setTimeout(() => { p.style.display = 'none'; }, 200);
                    if (icon) icon.textContent = '☰';
                }
                if (timerInterval) clearInterval(timerInterval);
            }
        });

        // Auto-polling for pending orders
        <?php if (!empty($user_pending_orders)): ?>
        (function() {
            let pollSec = 10;
            const secEl = document.getElementById('poll-sec');
            setInterval(function() {
                pollSec--;
                if (secEl) secEl.textContent = pollSec;
                if (pollSec <= 0) pollSec = 10;
            }, 1000);

            setInterval(function() {
                fetch('index.php?ajax=poll_orders')
                .then(r => r.json())
                .then(data => {
                    if (data.pending_count === 0) location.reload();
                })
                .catch(() => {});
            }, 10000);
        })();
        <?php endif; ?>

        // Live User Token Balance Auto-Sync
        <?php if (!empty($current_user)): ?>
        (function() {
            function syncUserBalance() {
                if (document.hidden) return;
                fetch('index.php?ajax=get_my_balance')
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.status === 'success') {
                            const navBal = document.getElementById('nav-token-balance');
                            if (navBal && navBal.textContent !== data.formatted) {
                                navBal.textContent = data.formatted;
                            }
                            const navBalMob = document.getElementById('nav-token-balance-mobile');
                            if (navBalMob && navBalMob.textContent !== data.formatted) {
                                navBalMob.textContent = data.formatted;
                            }
                            const walletBal = document.getElementById('wallet-page-token-balance');
                            if (walletBal && walletBal.textContent !== data.formatted) {
                                walletBal.textContent = data.formatted;
                            }
                        }
                    })
                    .catch(() => {});
            }
            setInterval(syncUserBalance, 25000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) syncUserBalance();
            });
        })();
        <?php endif; ?>
    </script>
<?php if (!empty($pending_app_auth) && !$current_user): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    openModal('modal-login');
    showToast('Vui lòng đăng nhập tài khoản 2tamne.site để duyệt kết nối ứng dụng!');
});
</script>
<?php endif; ?>
<?php if (isset($_GET['app_auth_approved'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast('🎉 Đã duyệt đăng nhập cho ứng dụng 2toolne Upscale thành công!');
});
</script>
<?php endif; ?>
</body>
</html>
