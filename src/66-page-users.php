<?php

declare(strict_types=1);

// ============================================================================
//  Users (admin)
// ============================================================================

function admin_count(?int $excludeId = null): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM users WHERE active = 1 AND (',' || perms || ',') LIKE '%,admin,%' AND id != ?");
    $st->execute([$excludeId ?? 0]);
    return (int) $st->fetchColumn();
}

function perms_from_post(): string
{
    $in = array_map('strval', (array) ($_POST['perms'] ?? []));
    return implode(',', array_values(array_filter(array_keys(DPLY_PERMS), fn($p) => in_array($p, $in, true))));
}

function perm_checkboxes(array $selected, bool $disableAdmin = false): string
{
    $o = '';
    foreach (DPLY_PERMS as $k => $label) {
        $dis = $disableAdmin && $k === 'admin';
        $o .= '<label class="chk"><input type="checkbox" name="perms[]" value="' . h($k) . '"' . (in_array($k, $selected, true) ? ' checked' : '')
            . ($dis ? ' disabled' : '') . '><span><b style="font-weight:600">' . h($k) . '</b><br><span class="mute small">' . h($label) . '</span></span></label>';
        if ($dis && in_array($k, $selected, true)) {
            $o .= '<input type="hidden" name="perms[]" value="admin">';
        }
    }
    return $o;
}

function page_users(): void
{
    $me = current_user();
    $all = db()->query('SELECT * FROM users ORDER BY username')->fetchAll();
    $html = page_head('Users', 'Who can sign in, and what each person is allowed to do.', '<a class="btn primary" href="#add-user">' . icon('plus') . 'Add user</a>');
    foreach ($all as $u) {
        $id = (int) $u['id'];
        $self = $id === (int) $me['id'];
        $perms = array_filter(explode(',', (string) $u['perms']));
        $locked = (int) $u['locked_until'] > time();
        $hid = csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<div class="card"><div class="ucard-h">' . avatar((string) $u['username']) . '<div class="n"><b>' . h($u['username']) . ' '
            . ((int) $u['active'] ? badge('active', 'ok') : badge('disabled', 'err')) . ($u['totp_secret'] ? badge('2FA', 'ok') : '')
            . ($locked ? badge('locked until ' . date('H:i', (int) $u['locked_until']), 'err') : '') . ($self ? badge('you') : '') . '</b>'
            . '<small>Last login ' . ($u['last_login_at'] ? ago((int) $u['last_login_at']) : 'never') . ($u['last_login_ip'] ? ' from ' . h($u['last_login_ip']) : '') . '</small></div>'
            . '<div class="row">'
            . ($locked || (int) $u['failed_count'] ? '<form method="post" action="?a=users">' . $hid . '<input type="hidden" name="op" value="unlock"><button class="sm" type="submit">' . icon('lock') . 'Unlock</button></form>' : '')
            . ($u['totp_secret'] ? '<form method="post" action="?a=users" data-confirm="Remove 2FA from this user?">' . $hid . '<input type="hidden" name="op" value="reset2fa"><button class="sm" type="submit">Reset 2FA</button></form>' : '')
            . '<form method="post" action="?a=users" data-confirm="Sign ' . h($u['username']) . ' out of every session?">' . $hid . '<input type="hidden" name="op" value="kill"><button class="sm" type="submit">' . icon('logout') . 'Sign out everywhere</button></form>'
            . ($self ? '' : '<form method="post" action="?a=users" data-confirm="Delete user ' . h($u['username']) . '? This cannot be undone.">' . $hid . '<input type="hidden" name="op" value="delete"><button class="sm danger" type="submit">Delete</button></form>')
            . '</div></div>'
            . '<div class="grid-main" style="margin:0"><form method="post" action="?a=users">' . $hid . '<input type="hidden" name="op" value="update">'
            . '<div class="sub-h" style="margin-top:0">Permissions</div><div class="chks">' . perm_checkboxes($perms, $self)
            . '<label class="chk"><input type="checkbox" name="active" value="1"' . ((int) $u['active'] ? ' checked' : '') . ($self ? ' disabled' : '') . '><span><b style="font-weight:600">Account enabled</b><br><span class="mute small">Disabled accounts cannot sign in</span></span></label></div>'
            . ($self ? '<input type="hidden" name="active" value="1">' : '')
            . '<button type="submit">' . icon('check') . 'Save permissions</button></form>'
            . '<form method="post" action="?a=users">' . $hid . '<input type="hidden" name="op" value="password"><div class="sub-h" style="margin-top:0">Reset password</div>'
            . '<label><span>New password (min 12 characters)</span><input type="password" name="password" autocomplete="new-password" required minlength="12"></label>'
            . '<button type="submit">' . icon('lock') . 'Set password</button></form></div></div>';
    }
    $html .= '<form method="post" action="?a=users" class="card" id="add-user">' . csrf_field() . '<h2>' . icon('plus') . 'Add user</h2><input type="hidden" name="op" value="create">'
        . '<div class="grid-main" style="margin-bottom:6px"><div><div class="sub-h" style="margin-top:0">Permissions</div><div class="chks">' . perm_checkboxes(['deploy']) . '</div></div>'
        . '<div><div class="sub-h" style="margin-top:0">Credentials</div><label><span>Username</span><input type="text" name="username" pattern="[A-Za-z0-9_.@-]{3,64}" required placeholder="jane.doe"></label>'
        . '<label><span>Password (min 12 characters)</span><input type="password" name="password" autocomplete="new-password" required minlength="12"></label>'
        . '<button class="primary" type="submit">' . icon('plus') . 'Create user</button></div></div></form>';
    render('Users', $html);
}

function do_users(): void
{
    $me = current_user();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $pdo = db();
    $target = null;
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $target = $st->fetch() ?: null;
        if (!$target) {
            flash('err', 'User not found.');
            redirect('?a=users');
        }
    }
    $self = $target && (int) $target['id'] === (int) $me['id'];
    switch ($op) {
        case 'create':
            $u = trim((string) ($_POST['username'] ?? ''));
            $p = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $u)) {
                flash('err', 'Username: 3–64 chars of letters, digits, _ . @ -');
            } elseif ($e = pw_policy($p, $u)) {
                flash('err', $e);
            } elseif (find_user($u)) {
                flash('err', 'That username exists.');
            } else {
                $pdo->prepare('INSERT INTO users (username, password_hash, perms, created_at) VALUES (?, ?, ?, ?)')->execute([$u, hash_pw($p), perms_from_post(), time()]);
                audit('users.create', "$u [" . perms_from_post() . ']');
                flash('ok', "User $u created.");
            }
            break;
        case 'update':
            $perms = perms_from_post();
            $active = !empty($_POST['active']) ? 1 : 0;
            if ($self) {
                $active = 1;
                if (!str_contains(",$perms,", ',admin,')) {
                    $perms = $perms === '' ? 'admin' : "admin,$perms";
                }
            }
            $wasAdmin = str_contains(',' . $target['perms'] . ',', ',admin,');
            if ($wasAdmin && (!str_contains(",$perms,", ',admin,') || !$active) && admin_count($id) === 0) {
                flash('err', 'Cannot remove the last active administrator.');
                break;
            }
            $pdo->prepare('UPDATE users SET perms = ?, active = ?, session_version = session_version + (CASE WHEN active != ? THEN 1 ELSE 0 END) WHERE id = ?')
                ->execute([$perms, $active, $active, $id]);
            audit('users.update', "{$target['username']}: perms=[$perms] active=$active");
            flash('ok', "Saved {$target['username']}.");
            break;
        case 'password':
            $p = (string) ($_POST['password'] ?? '');
            if ($e = pw_policy($p, $target['username'])) {
                flash('err', $e);
                break;
            }
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1, failed_count = 0, locked_until = 0 WHERE id = ?')->execute([hash_pw($p), $id]);
            if ($self) {
                $_SESSION['sv'] = (int) $target['session_version'] + 1;
            }
            audit('users.password', $target['username']);
            flash('ok', "Password for {$target['username']} changed; their other sessions were signed out.");
            break;
        case 'unlock':
            $pdo->prepare('UPDATE users SET failed_count = 0, locked_until = 0 WHERE id = ?')->execute([$id]);
            audit('users.unlock', $target['username']);
            flash('ok', "{$target['username']} unlocked.");
            break;
        case 'reset2fa':
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_last = 0 WHERE id = ?')->execute([$id]);
            audit('users.reset2fa', $target['username']);
            flash('ok', "2FA removed for {$target['username']}.");
            break;
        case 'kill':
            $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$id]);
            audit('users.kill_sessions', $target['username']);
            if ($self) {
                reset_session();
                flash('ok', 'All your sessions were signed out.');
                redirect('?a=login');
            }
            flash('ok', "All sessions of {$target['username']} were signed out.");
            break;
        case 'delete':
            if ($self) {
                flash('err', 'You cannot delete yourself.');
            } elseif (str_contains(',' . $target['perms'] . ',', ',admin,') && admin_count($id) === 0) {
                flash('err', 'Cannot delete the last administrator.');
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                audit('users.delete', $target['username']);
                flash('ok', "User {$target['username']} deleted.");
            }
            break;
    }
    redirect('?a=users');
}
