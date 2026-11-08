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
            . '<input type="hidden" name="op" value="unblock"><input type="hidden" name="ip" value="' . h($b['ip']) . '"><div class="row end"><button class="sm" type="submit">'
            . ($activeB ? 'Unblock' : 'Forget') . '</button></div></form></td></tr>';
    }
    $att = db()->query('SELECT * FROM attempts ORDER BY id DESC LIMIT 100')->fetchAll();
    $aHtml = '';
    foreach ($att as $a) {
        $aHtml .= '<tr><td style="white-space:nowrap">' . ago((int) $a['created_at']) . '</td><td><code>' . h($a['ip']) . '</code></td><td>' . h($a['kind']) . '</td><td>' . h((string) $a['username']) . '</td></tr>';
    }
    $num = fn(string $k, string $label, int $div = 1) => '<label><span>' . h($label) . '</span><input type="number" min="1" name="' . h($k)
        . '" value="' . (int) ((int) setting($k) / $div) . '"></label>';
    $ip = client_ip();
    $activeBlocks = count(array_filter($blocks, fn($b) => (int) $b['until'] > $now));
    render('Security', page_head('Security', 'Lockout policy, the outer HTTP gate, and blocked addresses.')
        . '<div class="stats">'
        . stat_tile('lock', 'Blocked IPs', (string) $activeBlocks, 'currently active', $activeBlocks ? 'err' : 'ok')
        . stat_tile('alert', 'Failed attempts', (string) count($att), 'in recent history', count($att) ? 'warn' : 'ok')
        . stat_tile('security', 'Your IP', '<code>' . h($ip) . '</code>', setting('trust_proxy') === '1' ? 'via trusted proxy' : 'direct connection', 'info')
        . '</div><div class="grid-main">'
        . '<form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>' . icon('security') . 'Lockout &amp; session policy</h2><input type="hidden" name="op" value="policy">'
        . '<div class="sub-h" style="margin-top:0">Brute-force protection</div>'
        . $num('max_attempts', 'Failed attempts before an IP is blocked (also locks the account)')
        . '<div class="field-row">' . $num('attempt_window', 'Counting window (minutes)', 60)
        . $num('block_seconds', 'First block (minutes) — doubles on repeat', 60)
        . $num('block_max', 'Maximum block (hours)', 3600) . '</div>'
        . '<div class="sub-h">Sessions</div><div class="field-row">'
        . $num('session_idle', 'Idle timeout (minutes)', 60)
        . $num('session_absolute', 'Absolute lifetime (hours)', 3600)
        . $num('sudo_ttl', 'Re-confirm password after (minutes)', 60) . '</div>'
        . '<div class="sub-h">Network</div>'
        . '<label><span>IP allow-list — one IP or CIDR per line; empty = allow all. Your IP: <code>' . h($ip) . '</code></span>'
        . '<textarea name="ip_allowlist" rows="4" placeholder="203.0.113.0/24">' . h(setting('ip_allowlist')) . '</textarea></label>'
        . '<label class="chk"><input type="checkbox" name="trust_proxy" value="1"' . (setting('trust_proxy') === '1' ? ' checked' : '') . '><span>Behind a reverse proxy / load balancer '
        . '(use the last X-Forwarded-For hop). <span class="mute">Enable ONLY if every request passes through your proxy, otherwise IPs can be spoofed.</span></span></label>'
        . '<div class="actions"><button class="primary" type="submit">' . icon('check') . 'Save policy</button></div></form>'
        . '<div class="stack"><form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>' . icon('lock') . 'HTTP Basic auth</h2><input type="hidden" name="op" value="http">'
        . '<p class="small mute">The outer gate, separate from user accounts. Current username: <code>' . h(setting('http_user')) . '</code></p>'
        . '<label><span>Username</span><input type="text" name="http_user" value="' . h(setting('http_user')) . '" required maxlength="64"></label>'
        . '<label><span>New password (min 12)</span><input type="password" name="http_pass" autocomplete="new-password" required minlength="12"></label>'
        . '<label><span>Repeat new password</span><input type="password" name="http_pass2" autocomplete="new-password" required minlength="12"></label>'
        . '<button type="submit" data-confirm="Change the HTTP auth credentials? Your browser will ask for the new ones.">Change credentials</button></form>'
        . '<form method="post" action="?a=security" class="card">' . csrf_field() . '<h2>' . icon('x') . 'Block an IP</h2><input type="hidden" name="op" value="block">'
        . '<label><span>IP address</span><input type="text" name="ip" placeholder="203.0.113.7" required></label>'
        . '<label><span>Duration (hours)</span><input type="number" name="hours" value="24" min="1"></label>'
        . '<button class="danger" type="submit">Block address</button></form></div></div>'
        . '<div class="card"><h2>' . icon('lock') . 'Blocked addresses</h2>' . ($bHtml ? '<div class="tbl"><table><tr><th>IP</th><th>State</th><th>Until</th><th>Strikes</th><th>Reason</th><th></th></tr>' . $bHtml . '</table></div>' : empty_state('security', 'No blocked addresses.')) . '</div>'
        . '<div class="card"><div class="card-h"><h2>' . icon('alert') . 'Recent failed attempts</h2>'
        . ($aHtml ? '<form method="post" action="?a=security" data-confirm="Clear the failed-attempt history?">' . csrf_field() . '<input type="hidden" name="op" value="clear_attempts"><button class="sm" type="submit">Clear history</button></form>' : '') . '</div>'
        . ($aHtml ? '<div class="tbl"><table><tr><th>When</th><th>IP</th><th>Type</th><th>Username tried</th></tr>' . $aHtml . '</table></div>' : empty_state('check', 'No failed attempts. All quiet.')) . '</div>');
}
