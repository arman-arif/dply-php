<?php

declare(strict_types=1);

// ============================================================================
//  Network identity, IP allow-list and automatic blocking
// ============================================================================

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (setting('trust_proxy') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // The right-most entry is the one appended by the (trusted) proxy itself.
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) {
            $ip = $last;
        }
    }
    return $ip;
}

function ip_match(string $ip, string $rule): bool
{
    $rule = trim($rule);
    $a = @inet_pton($ip);
    if ($rule === '' || $a === false) {
        return false;
    }
    if (!str_contains($rule, '/')) {
        return @inet_pton($rule) === $a;
    }
    [$net, $bits] = explode('/', $rule, 2);
    $b = @inet_pton($net);
    if ($b === false || strlen($a) !== strlen($b) || !ctype_digit($bits)) {
        return false;
    }
    $bits = min((int) $bits, strlen($a) * 8);
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

function ip_allowed(string $ip, ?string $list = null): bool
{
    $rules = lines($list ?? setting('ip_allowlist'));
    if (!$rules) {
        return true;
    }
    foreach ($rules as $r) {
        if (ip_match($ip, $r)) {
            return true;
        }
    }
    return false;
}

function blocked_until(string $ip): int
{
    $st = db()->prepare('SELECT until FROM blocks WHERE ip = ? AND until > ?');
    $st->execute([$ip, time()]);
    return (int) ($st->fetchColumn() ?: 0);
}

function register_failure(string $ip, string $kind, ?string $username = null): void
{
    $pdo = db();
    $now = time();
    $username = $username !== null ? substr($username, 0, 64) : null;
    $pdo->prepare('INSERT INTO attempts (ip, username, kind, created_at) VALUES (?, ?, ?, ?)')->execute([$ip, $username, $kind, $now]);
    audit('auth.fail', $kind . ($username ? " (user '$username')" : ''), $username ?: '-', $ip);

    // Count failures inside the window, but only those after the last block was issued.
    $st = $pdo->prepare('SELECT created_at FROM blocks WHERE ip = ?');
    $st->execute([$ip]);
    $since = max($now - (int) setting('attempt_window'), (int) ($st->fetchColumn() ?: 0));
    $st = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE ip = ? AND created_at > ?');
    $st->execute([$ip, $since]);
    $max = max(1, (int) setting('max_attempts'));
    if ((int) $st->fetchColumn() >= $max) {
        block_ip($ip, "Automatic: $max failed attempts", null);
    }
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM attempts WHERE created_at < ?')->execute([$now - 30 * 86400]);
    }
}

function block_ip(string $ip, string $reason, ?int $seconds, ?string $actor = null): int
{
    $pdo = db();
    $now = time();
    $st = $pdo->prepare('SELECT until, strikes FROM blocks WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch();
    // Repeat offenders get exponentially longer blocks; strikes decay after 30 quiet days.
    $strikes = ($row && (int) $row['until'] > $now - 30 * 86400) ? (int) $row['strikes'] + 1 : 1;
    if ($seconds === null) {
        $seconds = (int) min((int) setting('block_max'), (int) setting('block_seconds') * (2 ** min($strikes - 1, 20)));
    }
    $until = $now + max(60, $seconds);
    $pdo->prepare('INSERT INTO blocks (ip, until, strikes, reason, created_at) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(ip) DO UPDATE SET until = excluded.until, strikes = excluded.strikes,
                   reason = excluded.reason, created_at = excluded.created_at')
        ->execute([$ip, $until, $strikes, $reason, $now]);
    audit('security.block', "$ip blocked until " . date('Y-m-d H:i:s', $until) . " (strike $strikes): $reason", $actor ?? 'system', $ip);
    return $until;
}
