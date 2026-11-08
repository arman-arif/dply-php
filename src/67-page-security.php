<?php

declare(strict_types=1);

// ============================================================================
//  Security (admin): blocks, attempts, HTTP auth, policies
// ============================================================================

function page_security(): void
{
    $now = time();
    $blocks = db()->query('SELECT * FROM blocks ORDER BY until DESC LIMIT 200')->fetchAll();
    $bHtml = '';
    foreach ($blocks as $b) {
        $activeB = (int) $b['until'] > $now;
        $bHtml .= '<tr><td><code>' . h($b['ip']) . '</code></td><td>' . ($activeB ? badge('blocked', 'err') : badge('expired')) . '</td><td>' . h(fmt_time((int) $b['until']))
            . '</td><td>' . (int) $b['strikes'] . '</td><td class="small">' . h($b['reason']) . '</td><td><form method="post" action="?a=security">' . csrf_field()
            . '<input type="hidden" name="op" value="unblock"><input type="hidden" name="ip" value="' . h($b['ip']) . '"><button class="sm" type="submit">'
            . ($activeB ? 'Unblock' : 'Forget') . '</button></form></td></tr>';
    }
    $att = db()->query('SELECT * FROM attempts ORDER BY id DESC LIMIT 100')->fetchAll();
    $aHtml = '';
    foreach ($att as $a) {
        $aHtml .= '<tr><td>' . h(fmt_time((int) $a['created_at'])) . '</td><td><code>' . h($a['ip']) . '</code></td><td>' . h($a['kind']) . '</td><td>' . h((string) $a['username']) . '</td></tr>';
    }
    $num = fn(string $k, string $label, int $div = 1) => '<label><span>' . h($label) . '</span><input type="number" min="1" name="' . h($k)
        . '" value="' . (int) ((int) setting($k) / $div) . '"></label>';
    $ip = client_ip();
    render('Security', '<h1>Security</h1><div class="grid">'
        . '<form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>Lockout &amp; session policy</h2><input type="hidden" name="op" value="policy">'
        . $num('max_attempts', 'Failed attempts before an IP is blocked (also locks the account)')
        . $num('attempt_window', 'Counting window (minutes)', 60)
        . $num('block_seconds', 'First block duration (minutes) — doubles for each repeat', 60)
        . $num('block_max', 'Maximum block duration (hours)', 3600)
        . $num('session_idle', 'Idle session timeout (minutes)', 60)
        . $num('session_absolute', 'Absolute session lifetime (hours)', 3600)
        . $num('sudo_ttl', 'Password re-confirmation valid for (minutes)', 60)
        . '<label><span>IP allow-list — one IP or CIDR per line; empty = allow all. Your IP: <code>' . h($ip) . '</code></span>'
        . '<textarea name="ip_allowlist" rows="4">' . h(setting('ip_allowlist')) . '</textarea></label>'
        . '<label class="chk"><input type="checkbox" name="trust_proxy" value="1"' . (setting('trust_proxy') === '1' ? ' checked' : '') . '><span>Behind a reverse proxy / load balancer '
        . '(use the last X-Forwarded-For hop). Enable ONLY if every request passes through your proxy, otherwise IPs can be spoofed.</span></label>'
        . '<button class="primary" type="submit">Save policy</button></form>'
        . '<div class="stack"><form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>HTTP Basic auth credentials</h2><input type="hidden" name="op" value="http">'
        . '<p class="small mute">The outer gate, separate from user accounts. Current username: <code>' . h(setting('http_user')) . '</code></p>'
        . '<label><span>Username</span><input type="text" name="http_user" value="' . h(setting('http_user')) . '" required maxlength="64"></label>'
        . '<label><span>New password (min 12)</span><input type="password" name="http_pass" autocomplete="new-password" required minlength="12"></label>'
        . '<label><span>Repeat new password</span><input type="password" name="http_pass2" autocomplete="new-password" required minlength="12"></label>'
        . '<button type="submit" data-confirm="Change the HTTP auth credentials? Your browser will ask for the new ones.">Change</button></form>'
        . '<form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>Block an IP manually</h2><input type="hidden" name="op" value="block"><div class="row">'
        . '<input type="text" name="ip" placeholder="203.0.113.7" required style="flex:1;min-width:140px"><input type="number" name="hours" value="24" min="1" style="width:90px"><span class="mute">hours</span>'
        . '<button class="danger" type="submit">Block</button></div></form></div></div>'
        . '<div class="card"><h2>Blocked addresses</h2>' . ($bHtml ? '<div class="tbl"><table><tr><th>IP</th><th>State</th><th>Until</th><th>Strikes</th><th>Reason</th><th></th></tr>' . $bHtml . '</table></div>' : '<p class="mute">None.</p>') . '</div>'
        . '<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">Recent failed attempts</h2>'
        . '<form method="post" action="?a=security" data-confirm="Clear the failed-attempt history?">' . csrf_field() . '<input type="hidden" name="op" value="clear_attempts"><button class="sm" type="submit">Clear</button></form></div>'
        . ($aHtml ? '<div class="tbl"><table><tr><th>When</th><th>IP</th><th>Type</th><th>Username tried</th></tr>' . $aHtml . '</table></div>' : '<p class="mute">None.</p>') . '</div>');
}

function do_security(): void
{
    $op = (string) ($_POST['op'] ?? '');
    switch ($op) {
        case 'unblock':
            $ip = (string) ($_POST['ip'] ?? '');
            db()->prepare('DELETE FROM blocks WHERE ip = ?')->execute([$ip]);
            db()->prepare('DELETE FROM attempts WHERE ip = ?')->execute([$ip]);
            audit('security.unblock', $ip);
            flash('ok', "$ip unblocked.");
            break;
        case 'block':
            $ip = trim((string) ($_POST['ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                flash('err', 'Invalid IP address.');
            } elseif ($ip === client_ip()) {
                flash('err', 'Refusing to block your own address.');
            } else {
                block_ip($ip, 'Manual block', max(1, (int) ($_POST['hours'] ?? 24)) * 3600, current_user()['username']);
                flash('ok', "$ip blocked.");
            }
            break;
        case 'clear_attempts':
            db()->exec('DELETE FROM attempts');
            audit('security.clear_attempts', '');
            flash('ok', 'Attempt history cleared.');
            break;
        case 'policy':
            $allow = implode("\n", lines((string) ($_POST['ip_allowlist'] ?? '')));
            foreach (lines($allow) as $r) {
                if (@inet_pton(explode('/', $r)[0]) === false) {
                    flash('err', "Invalid allow-list entry: $r");
                    redirect('?a=security');
                }
            }
            if ($allow !== '' && !ip_allowed(client_ip(), $allow)) {
                flash('err', 'The allow-list must include your current IP (' . client_ip() . '), otherwise you would lock yourself out.');
                redirect('?a=security');
            }
            $map = ['max_attempts' => 1, 'attempt_window' => 60, 'block_seconds' => 60, 'block_max' => 3600, 'session_idle' => 60, 'session_absolute' => 3600, 'sudo_ttl' => 60];
            foreach ($map as $k => $mul) {
                set_setting($k, (string) (max(1, (int) ($_POST[$k] ?? 1)) * $mul));
            }
            set_setting('ip_allowlist', $allow);
            set_setting('trust_proxy', !empty($_POST['trust_proxy']) ? '1' : '0');
            audit('security.policy', 'Policy updated');
            flash('ok', 'Security policy saved.');
            break;
        case 'http':
            $u = trim((string) ($_POST['http_user'] ?? ''));
            $p = (string) ($_POST['http_pass'] ?? '');
            if ($u === '' || strlen($u) > 64 || str_contains($u, ':')) {
                flash('err', 'Username must be 1–64 characters and must not contain ":".');
            } elseif ($p !== (string) ($_POST['http_pass2'] ?? '')) {
                flash('err', 'Passwords do not match.');
            } elseif ($e = pw_policy($p, $u)) {
                flash('err', $e);
            } else {
                set_setting('http_user', $u);
                set_setting('http_pass_hash', hash_pw($p));
                audit('security.http_auth', "HTTP auth credentials changed (user $u)");
                flash('ok', 'HTTP auth credentials changed. Your browser will prompt for them on the next request.');
            }
            break;
    }
    redirect('?a=security');
}
