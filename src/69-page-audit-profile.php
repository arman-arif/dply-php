<?php

declare(strict_types=1);

// ============================================================================
//  Audit log & profile
// ============================================================================

function page_audit(): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare("SELECT a.*, p.name AS project FROM audit a LEFT JOIN projects p ON p.id = a.project_id"
            . " WHERE a.action LIKE ? OR a.actor LIKE ? OR a.ip LIKE ? OR a.detail LIKE ? OR p.name LIKE ? ORDER BY a.id DESC LIMIT 500");
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $st->execute([$like, $like, $like, $like, $like]);
        $rows = $st->fetchAll();
    } else {
        $rows = db()->query('SELECT a.*, p.name AS project FROM audit a LEFT JOIN projects p ON p.id = a.project_id ORDER BY a.id DESC LIMIT 500')->fetchAll();
    }
    $html = '';
    foreach ($rows as $r) {
        $cls = str_starts_with($r['action'], 'auth.fail') || str_starts_with($r['action'], 'security.block') || str_ends_with($r['action'], 'denied') ? 'err'
            : (str_starts_with($r['action'], 'deploy') ? 'ok' : '');
        $html .= '<tr class="r"><td class="small" style="white-space:nowrap">' . ago((int) $r['created_at']) . '</td><td style="white-space:nowrap">' . h($r['actor']) . '</td><td><code>' . h($r['ip']) . '</code></td><td class="small" style="white-space:nowrap">' . h($r['project'] ?? '—') . '</td><td>'
            . badge($r['action'], $cls) . '</td><td class="small">' . h($r['detail']) . '</td></tr>';
    }
    render('Audit log', page_head('Audit log', 'Every sign-in, deployment, command and setting change — newest first (up to 500 entries).')
        . '<form method="get" class="row" style="margin-bottom:18px"><input type="hidden" name="a" value="audit"><div class="searchbox">' . icon('search')
        . '<input type="text" name="q" value="' . h($q) . '" data-search="#audit-rows tr.r" placeholder="Search action, user, IP, project or detail — Enter searches the full log" aria-label="Search audit log"></div>'
        . '<button type="submit">Search</button>' . ($q !== '' ? '<a class="btn ghost" href="?a=audit">' . icon('x') . 'Clear</a>' : '') . '</form>'
        . ($html !== '' ? '<div class="card tbl"><table id="audit-rows"><tr><th>When</th><th>User</th><th>IP</th><th>Project</th><th>Action</th><th>Detail</th></tr>' . $html . '</table></div>'
            : '<div class="card">' . empty_state('search', $q !== '' ? 'No entries match "' . $q . '".' : 'The audit log is empty.') . '</div>'));
}

function page_profile(): void
{
    $u = current_user();
    $totp = '';
    if ($u['totp_secret']) {
        $totp = '<p>' . badge('enabled', 'ok') . ' &nbsp;Your account is protected by an authenticator code.</p><form method="post" action="?a=profile" data-confirm="Disable two-factor authentication?">' . csrf_field()
            . '<input type="hidden" name="op" value="totp_disable"><label><span>Current password</span><input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button class="danger" type="submit">Disable 2FA</button></form>';
    } elseif (!empty($_SESSION['totp_pending'])) {
        $sec = $_SESSION['totp_pending'];
        $uri = 'otpauth://totp/' . rawurlencode('DPLY:' . $u['username']) . '?secret=' . $sec . '&issuer=DPLY&algorithm=SHA1&digits=6&period=30';
        $totp = '<p>Add this secret to your authenticator app (Google Authenticator, 1Password, Aegis…), then enter the current code.</p>'
            . '<label><span>Secret key</span><pre class="small" style="font-size:15px;letter-spacing:.08em">' . h(trim(chunk_split($sec, 4, ' '))) . '</pre></label>'
            . '<details style="margin-bottom:16px"><summary class="small mute" style="cursor:pointer">Show otpauth:// URI</summary><pre class="small break" style="margin-top:8px">' . h($uri) . '</pre></details>'
            . '<form method="post" action="?a=profile">' . csrf_field() . '<input type="hidden" name="op" value="totp_enable">'
            . '<label><span>6-digit code</span><input type="text" name="code" class="otp" inputmode="numeric" maxlength="7" placeholder="000000" required autocomplete="one-time-code"></label>'
            . '<button class="primary" type="submit">Enable 2FA</button></form>';
    } else {
        $totp = '<p>' . badge('off', 'warn') . ' &nbsp;<span class="mute">Add a second step to sign-in with a time-based one-time code.</span></p><form method="post" action="?a=profile">' . csrf_field()
            . '<input type="hidden" name="op" value="totp_start"><button class="primary" type="submit">' . icon('lock') . 'Set up 2FA</button></form>';
    }
    $permRows = '';
    foreach ($u['perm_list'] as $p) {
        $permRows .= '<li><span class="dot ok"></span><span class="who"><b>' . h($p) . '</b> <span class="mute small">' . h(DPLY_PERMS[$p] ?? '') . '</span></span></li>';
    }
    render('Profile', page_head('<span class="row" style="gap:14px">' . avatar($u['username']) . h($u['username']) . '</span>', 'Manage your password, two-factor authentication and see what you can access.')
        . '<div class="grid">'
        . '<form method="post" action="?a=profile" class="card">' . csrf_field() . '<h2>' . icon('lock') . 'Change password</h2><input type="hidden" name="op" value="password">'
        . '<label><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label><span>New password (min 12)</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label><span>Repeat new password</span><input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>'
        . '<button class="primary" type="submit">' . icon('check') . 'Change password</button></form>'
        . '<div class="stack"><div class="card"><h2>' . icon('security') . 'Two-factor authentication</h2>' . $totp . '</div>'
        . '<div class="card"><h2>' . icon('keys') . 'Your permissions</h2>' . ($permRows ? '<ul class="deps">' . $permRows . '</ul>' : empty_state('lock', 'No permissions assigned.')) . '</div></div></div>');
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
