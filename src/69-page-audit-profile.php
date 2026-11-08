<?php

declare(strict_types=1);

// ============================================================================
//  Audit log & profile
// ============================================================================

function page_audit(): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare("SELECT * FROM audit WHERE action LIKE ? OR actor LIKE ? OR ip LIKE ? OR detail LIKE ? ORDER BY id DESC LIMIT 500");
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $st->execute([$like, $like, $like, $like]);
        $rows = $st->fetchAll();
    } else {
        $rows = db()->query('SELECT * FROM audit ORDER BY id DESC LIMIT 500')->fetchAll();
    }
    $html = '';
    foreach ($rows as $r) {
        $cls = str_starts_with($r['action'], 'auth.fail') || str_starts_with($r['action'], 'security.block') || str_ends_with($r['action'], 'denied') ? 'err'
            : (str_starts_with($r['action'], 'deploy') ? 'ok' : '');
        $html .= '<tr><td class="small">' . h(fmt_time((int) $r['created_at'])) . '</td><td>' . h($r['actor']) . '</td><td><code>' . h($r['ip']) . '</code></td><td>'
            . badge($r['action'], $cls) . '</td><td class="small">' . h($r['detail']) . '</td></tr>';
    }
    render('Audit log', '<h1>Audit log</h1><form method="get" class="row card"><input type="hidden" name="a" value="audit">'
        . '<input type="text" name="q" value="' . h($q) . '" placeholder="Filter by action, user, IP or detail" style="flex:1;min-width:200px"><button type="submit">Filter</button></form>'
        . '<div class="card tbl"><table><tr><th>When</th><th>User</th><th>IP</th><th>Action</th><th>Detail</th></tr>' . $html . '</table></div>');
}

function page_profile(): void
{
    $u = current_user();
    $totp = '';
    if ($u['totp_secret']) {
        $totp = '<p>' . badge('enabled', 'ok') . ' Two-factor authentication is on.</p><form method="post" action="?a=profile" data-confirm="Disable two-factor authentication?">' . csrf_field()
            . '<input type="hidden" name="op" value="totp_disable"><label><span>Current password</span><input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button class="danger" type="submit">Disable 2FA</button></form>';
    } elseif (!empty($_SESSION['totp_pending'])) {
        $sec = $_SESSION['totp_pending'];
        $uri = 'otpauth://totp/' . rawurlencode('DPLY:' . $u['username']) . '?secret=' . $sec . '&issuer=DPLY&algorithm=SHA1&digits=6&period=30';
        $totp = '<p>Add this secret to your authenticator app (Google Authenticator, 1Password, Aegis…), then enter the current code.</p>'
            . '<p>Secret: <code>' . h(trim(chunk_split($sec, 4, ' '))) . '</code></p><p class="small mute break">URI: <code>' . h($uri) . '</code></p>'
            . '<form method="post" action="?a=profile">' . csrf_field() . '<input type="hidden" name="op" value="totp_enable">'
            . '<label><span>6-digit code</span><input type="text" name="code" inputmode="numeric" required autocomplete="one-time-code"></label>'
            . '<button class="primary" type="submit">Enable 2FA</button></form>';
    } else {
        $totp = '<p class="mute">Protect your account with a time-based one-time code.</p><form method="post" action="?a=profile">' . csrf_field()
            . '<input type="hidden" name="op" value="totp_start"><button type="submit">Set up 2FA</button></form>';
    }
    render('Profile', '<h1>Profile — ' . h($u['username']) . '</h1><div class="grid">'
        . '<form method="post" action="?a=profile" class="card">' . csrf_field() . '<h2>Change password</h2><input type="hidden" name="op" value="password">'
        . '<label><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label><span>New password (min 12)</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label><span>Repeat new password</span><input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>'
        . '<button class="primary" type="submit">Change password</button></form>'
        . '<div class="card"><h2>Two-factor authentication</h2>' . $totp . '</div>'
        . '<div class="card"><h2>Your permissions</h2><p>' . implode(' ', array_map(fn($p) => badge($p), $u['perm_list'])) . '</p></div></div>');
}

function do_profile(): void
{
    $u = current_user();
    $op = (string) ($_POST['op'] ?? '');
    $pdo = db();
    switch ($op) {
        case 'password':
            if (!password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) {
                register_failure(client_ip(), 'reauth', $u['username']);
                flash('err', 'Current password is wrong.');
            } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                flash('err', 'New passwords do not match.');
            } elseif ($e = pw_policy((string) $_POST['password'], $u['username'])) {
                flash('err', $e);
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([hash_pw((string) $_POST['password']), $u['id']]);
                $_SESSION['sv'] = (int) $u['session_version'] + 1;
                audit('profile.password', 'Password changed');
                flash('ok', 'Password changed. Other sessions were signed out.');
            }
            break;
        case 'totp_start':
            $_SESSION['totp_pending'] = b32_encode(random_bytes(20));
            break;
        case 'totp_enable':
            $sec = (string) ($_SESSION['totp_pending'] ?? '');
            $step = $sec !== '' ? totp_verify($sec, preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')), 0) : null;
            if ($step === null) {
                flash('err', 'Code did not match — check the time on your phone and try again.');
                break;
            }
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_last = ? WHERE id = ?')->execute([enc($sec), $step, $u['id']]);
            unset($_SESSION['totp_pending']);
            audit('profile.2fa_on', '');
            flash('ok', 'Two-factor authentication enabled.');
            break;
        case 'totp_disable':
            if (!password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
                register_failure(client_ip(), 'reauth', $u['username']);
                flash('err', 'Wrong password.');
                break;
            }
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_last = 0 WHERE id = ?')->execute([$u['id']]);
            audit('profile.2fa_off', '');
            flash('ok', 'Two-factor authentication disabled.');
            break;
    }
    redirect('?a=profile');
}
