<?php

declare(strict_types=1);

// ============================================================================
//  Web entry, HTTP auth, sessions, CSRF
// ============================================================================

function web_main(): void
{
    ini_set('display_errors', '0');
    try {
        ensure_storage();
        db();
    } catch (Throwable $e) {
        error_log('DPLY: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'DPLY cannot open its data directory. Check that ' . DPLY_DATA . ' is writable by the PHP user.';
        return;
    }
    send_security_headers();
    if (!setup_done()) {
        http_response_code(503);
        render_bare('Setup required', '<div class="card"><div class="key-ic on" style="margin-bottom:16px">' . icon('settings') . '</div><h1>Setup required</h1><p class="lead mute">Initial setup is done on the server '
            . 'command line so nobody can claim this console over the web. Run (as the PHP user):</p>'
            . '<pre>sudo -u ' . h(proc_user()) . ' php ' . h(DPLY_DIR) . '/index.php setup</pre></div>');
        return;
    }
    $ip = client_ip();
    if (!ip_allowed($ip)) {
        http_response_code(403);
        render_bare('Forbidden', '<div class="card"><div class="key-ic" style="margin-bottom:16px;color:var(--err)">' . icon('x') . '</div><h1>Access denied</h1><p class="mute">Your IP address is not on this console\'s allow-list.</p></div>');
        return;
    }
    if ($until = blocked_until($ip)) {
        blocked_response($until);
        return;
    }
    http_auth_gate($ip);
    start_session();
    dispatch();
}

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . csp_nonce() . "'; "
        . "connect-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function csp_nonce(): string
{
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return setting('trust_proxy') === '1' && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function blocked_response(int $until): void
{
    http_response_code(403);
    header('Retry-After: ' . max(1, $until - time()));
    render_bare('Blocked', '<div class="card"><div class="key-ic" style="margin-bottom:16px;color:var(--err)">' . icon('lock') . '</div><h1>Temporarily blocked</h1><p class="mute">Too many failed authentication attempts '
        . 'from your address. Try again after ' . h(date('Y-m-d H:i:s T', $until)) . '.</p></div>');
}

function basic_credentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($hdr, 'basic ') === 0) {
        $dec = base64_decode(substr($hdr, 6), true);
        if ($dec !== false && str_contains($dec, ':')) {
            return explode(':', $dec, 2);
        }
    }
    return [null, null];
}

function http_auth_gate(string $ip): void
{
    [$u, $p] = basic_credentials();
    if ($u !== null) {
        $okUser = hash_equals(setting('http_user'), $u);
        $okPass = password_verify((string) $p, setting('http_pass_hash'));
        if ($okUser && $okPass) {
            return;
        }
        register_failure($ip, 'http', $u);
        if ($until = blocked_until($ip)) {
            blocked_response($until);
            exit;
        }
    }
    header('WWW-Authenticate: Basic realm="DPLY", charset="UTF-8"');
    http_response_code(401);
    render_bare('Authentication required', '<div class="card"><div class="key-ic" style="margin-bottom:16px">' . icon('lock') . '</div><h1>Authentication required</h1><p class="mute">Reload the page and enter the HTTP credentials.</p></div>');
    exit;
}

function start_session(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) max(3600, (int) setting('session_absolute')));
    session_save_path(DPLY_DATA . '/sessions');
    session_name(is_https() ? '__Host-dply' : 'dply_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => is_https() ? '/' : (rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/') . '/'),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function ua_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['uid'])) {
        return null; // not cached: may log in later in this request
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int) $_SESSION['uid']]);
    $row = $st->fetch();
    $now = time();
    $valid = $row
        && (int) $row['active'] === 1
        && (int) $row['session_version'] === (int) ($_SESSION['sv'] ?? 0)
        && hash_equals((string) ($_SESSION['ua'] ?? ''), ua_hash())
        && $now - (int) ($_SESSION['act'] ?? 0) <= (int) setting('session_idle')
        && $now - (int) ($_SESSION['iat'] ?? 0) <= (int) setting('session_absolute');
    if (!$valid) {
        reset_session();
        flash('warn', 'Your session has ended. Please sign in again.');
        return $cached = null;
    }
    $_SESSION['act'] = $now;
    $row['perm_list'] = array_values(array_filter(explode(',', (string) $row['perms'])));
    return $cached = $row;
}

function reset_session(): void
{
    $flash = $_SESSION['flash'] ?? [];
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['flash'] = $flash;
}

function can(string $perm): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $p = $u['perm_list'];
    if (in_array('admin', $p, true)) {
        return true;
    }
    if ($perm === 'terminal') {
        return in_array('terminal', $p, true) || in_array('terminal_full', $p, true);
    }
    return in_array($perm, $p, true);
}

function sudo_fresh(): bool
{
    return time() - (int) ($_SESSION['sudo'] ?? 0) <= (int) setting('sudo_ttl');
}

function flash(string $type, string $msg, ?string $pre = null): void
{
    $_SESSION['flash'][] = [$type, $msg, $pre];
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function safe_return(string $r): string
{
    return preg_match('/^\?a=[a-z_]+(&[A-Za-z0-9_]+=[A-Za-z0-9_.%\-]*)*$/', $r) ? $r : '?a=dashboard';
}

function dispatch(): void
{
    $a = (string) ($_GET['a'] ?? 'dashboard');
    if (!preg_match('/^[a-z_]{1,32}$/', $a)) {
        $a = 'dashboard';
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($post && !csrf_ok()) {
        if ($a === 'term_exec') {
            json_out(['error' => 'Invalid security token — reload the page.'], 400);
        }
        flash('err', 'Your form expired or was invalid. Please try again.');
        redirect(current_user() ? '?a=' . $a : '?a=login');
    }

    $user = current_user();
    if (!$user) {
        if ($a === 'login') {
            $post ? do_login() : page_login();
        } elseif ($a === 'login_2fa') {
            $post ? do_login_2fa() : page_login_2fa();
        } elseif ($a === 'term_exec') {
            json_out(['error' => 'Session expired — reload and sign in.'], 401);
        } else {
            redirect('?a=login');
        }
        return;
    }

    // action => [required permission, needs fresh password confirmation]
    $routes = [
        'dashboard'  => [null, false],
        'deploy'     => ['deploy', false],
        'deploy_log' => ['deploy', false],
        'artisan'    => ['artisan', false],
        'terminal'   => ['terminal', true],
        'term_exec'  => ['terminal', true],
        'env'        => ['env', true],
        'keys'       => ['keys', true],
        'users'      => ['admin', true],
        'security'   => ['admin', true],
        'settings'   => ['admin', true],
        'audit'      => ['admin', false],
        'profile'    => [null, false],
        'reauth'     => [null, false],
        'logout'     => [null, false],
        'login'      => [null, false],
    ];
    if ($a === 'login' || $a === 'login_2fa') {
        redirect('?a=dashboard');
    }
    if (!isset($routes[$a])) {
        http_response_code(404);
        render('Not found', '<div class="card">' . empty_state('search', 'This page does not exist.') . '<p style="text-align:center"><a class="btn" href="?a=dashboard">' . icon('back') . 'Back to dashboard</a></p></div>');
        return;
    }
    [$perm, $sudo] = $routes[$a];
    if ($perm !== null && !can($perm)) {
        audit('access.denied', "action=$a");
        if ($a === 'term_exec') {
            json_out(['error' => 'Permission denied.'], 403);
        }
        http_response_code(403);
        render('Forbidden', page_head('Permission denied') . '<div class="flash err">Your account lacks the <code>'
            . h($perm) . '</code> permission. Ask an administrator if you need access.</div>');
        return;
    }
    if ($sudo && !sudo_fresh()) {
        if ($a === 'term_exec') {
            json_out(['reauth' => true]);
        }
        $ret = $post ? '?a=' . $a : ('?' . (string) ($_SERVER['QUERY_STRING'] ?? 'a=' . $a));
        redirect('?a=reauth&return=' . rawurlencode(safe_return($ret)));
    }
    $fn = ($post ? 'do_' : 'page_') . $a;
    if (!function_exists($fn)) {
        http_response_code(405);
        render('Method not allowed', page_head('Method not allowed') . '<div class="card">' . empty_state('alert', 'This action cannot be performed this way.') . '</div>');
        return;
    }
    $fn();
}
