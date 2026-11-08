<?php

declare(strict_types=1);

// ============================================================================
//  Login, 2FA, re-auth, logout
// ============================================================================

function page_login(): void
{
    render_bare('Sign in', '<form method="post" action="?a=login" class="card login">' . csrf_field()
        . '<h1>Welcome back</h1><p class="lead">Sign in to manage deployments.</p>'
        . '<label><span>Username</span><input type="text" name="username" autocomplete="username" required autofocus maxlength="64" placeholder="your.username"></label>'
        . '<label><span>Password</span><input type="password" name="password" autocomplete="current-password" required placeholder="••••••••••••"></label>'
        . '<button class="primary" type="submit">Sign in ' . icon('arrow') . '</button></form><p class="auth-foot">' . icon('lock', 'small') . ' Protected console · all actions are audited</p>', true);
}

function find_user(string $username): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    return $st->fetch() ?: null;
}

function dummy_hash(): string
{
    static $h = null;
    return $h ??= hash_pw(bin2hex(random_bytes(8)));
}

function do_login(): void
{
    $ip = client_ip();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $row = (strlen($username) <= 64 && strlen($password) <= 1024) ? find_user($username) : null;
    $now = time();
    // Always run a hash verification so response timing does not reveal valid usernames.
    $pwOk = password_verify($password, $row['password_hash'] ?? dummy_hash());
    $ok = $row && $pwOk && (int) $row['active'] === 1 && (int) $row['locked_until'] <= $now;
    if (!$ok) {
        register_failure($ip, 'login', $username);
        if ($row && !$pwOk) {
            user_failure($row);
        }
        if ($until = blocked_until($ip)) {
            blocked_response($until);
            exit;
        }
        flash('err', 'Invalid credentials, or the account is disabled / temporarily locked.');
        redirect('?a=login');
    }
    if (password_needs_rehash($row['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([hash_pw($password), $row['id']]);
    }
    if (!empty($row['totp_secret'])) {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int) $row['id'];
        $_SESSION['pending_at'] = $now;
        redirect('?a=login_2fa');
    }
    complete_login($row);
}

function user_failure(array $row): void
{
    $count = (int) $row['failed_count'] + 1;
    $max = max(1, (int) setting('max_attempts'));
    if ($count >= $max) {
        $until = time() + (int) setting('block_seconds');
        db()->prepare('UPDATE users SET failed_count = 0, locked_until = ? WHERE id = ?')->execute([$until, $row['id']]);
        audit('user.locked', "User '{$row['username']}' locked until " . date('Y-m-d H:i:s', $until), 'system');
    } else {
        db()->prepare('UPDATE users SET failed_count = ? WHERE id = ?')->execute([$count, $row['id']]);
    }
}

function complete_login(array $row): never
{
    $flash = $_SESSION['flash'] ?? [];
    session_regenerate_id(true);
    $_SESSION = [
        'uid'   => (int) $row['id'],
        'sv'    => (int) $row['session_version'],
        'iat'   => time(),
        'act'   => time(),
        'sudo'  => time(),
        'ua'    => ua_hash(),
        'csrf'  => bin2hex(random_bytes(32)),
        'flash' => $flash,
    ];
    db()->prepare('UPDATE users SET failed_count = 0, locked_until = 0, last_login_at = ?, last_login_ip = ? WHERE id = ?')
        ->execute([time(), client_ip(), $row['id']]);
    audit('auth.login', 'Signed in', $row['username']);
    redirect('?a=dashboard');
}

function page_login_2fa(): void
{
    if (empty($_SESSION['pending_uid'])) {
        redirect('?a=login');
    }
    render_bare('Two-factor', '<form method="post" action="?a=login_2fa" class="card login">' . csrf_field()
        . '<h1>Two-factor verification</h1><p class="lead">Enter the 6-digit code from your authenticator app.</p>'
        . '<label><span>Authentication code</span><input type="text" name="code" class="otp" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" autocomplete="one-time-code" required autofocus></label>'
        . '<button class="primary" type="submit">Verify ' . icon('arrow') . '</button><p class="auth-foot" style="margin-bottom:0"><a href="?a=login">' . icon('back', 'small') . ' Back to sign in</a></p></form>', true);
}

function do_login_2fa(): void
{
    $uid = (int) ($_SESSION['pending_uid'] ?? 0);
    if (!$uid || time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) {
        unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
        flash('err', 'Two-factor step expired. Sign in again.');
        redirect('?a=login');
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $step = $row ? totp_verify(dec((string) $row['totp_secret']), $code, (int) $row['totp_last']) : null;
    if (!$row || $step === null || (int) $row['active'] !== 1) {
        register_failure(client_ip(), 'totp', $row['username'] ?? null);
        if ($row) {
            user_failure($row);
        }
        if ($until = blocked_until(client_ip())) {
            blocked_response($until);
            exit;
        }
        flash('err', 'Invalid code.');
        redirect('?a=login_2fa');
    }
    db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $uid]);
    complete_login($row);
}

function page_reauth(): void
{
    $ret = safe_return((string) ($_GET['return'] ?? '?a=dashboard'));
    render('Confirm password', '<form method="post" action="?a=reauth" class="card login">' . csrf_field()
        . '<div class="key-ic on" style="margin-bottom:16px">' . icon('lock') . '</div>'
        . '<h1>Confirm your password</h1><p class="lead">This area is sensitive. Re-enter your password to continue — '
        . 'you won\'t be asked again for ' . (int) ceil((int) setting('sudo_ttl') / 60) . ' minutes.</p>'
        . '<input type="hidden" name="return" value="' . h($ret) . '">'
        . '<label><span>Password</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button class="primary" type="submit">Continue ' . icon('arrow') . '</button></form>');
}

function do_reauth(): void
{
    $u = current_user();
    $ret = safe_return((string) ($_POST['return'] ?? ''));
    if (!password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
        register_failure(client_ip(), 'reauth', $u['username']);
        user_failure($u);
        if ($until = blocked_until(client_ip())) {
            blocked_response($until);
            exit;
        }
        flash('err', 'Wrong password.');
        redirect('?a=reauth&return=' . rawurlencode($ret));
    }
    $_SESSION['sudo'] = time();
    audit('auth.reauth', 'Password re-confirmed');
    redirect($ret);
}

function do_logout(): void
{
    audit('auth.logout', 'Signed out');
    reset_session();
    flash('ok', 'Signed out.');
    redirect('?a=login');
}

// ---- TOTP (RFC 6238) --------------------------------------------------------

function b32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function b32_decode(string $s): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos($alpha, $c);
        if ($i === false) {
            return '';
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $step), b32_decode($secret), true);
    $o = ord($h[19]) & 0x0F;
    $n = (unpack('N', substr($h, $o, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/** Returns the matched time-step (for replay protection) or null. */
function totp_verify(string $secret, string $code, int $lastStep): ?int
{
    if ($secret === '' || strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $s = $now + $i;
        if ($s > $lastStep && hash_equals(totp_code($secret, $s), $code)) {
            return $s;
        }
    }
    return null;
}
