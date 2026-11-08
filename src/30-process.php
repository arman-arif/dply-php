<?php

declare(strict_types=1);

// ============================================================================
//  Process execution helpers
// ============================================================================

function env_path(): string
{
    $p = (string) (getenv('PATH') ?: '');
    $dirs = array_filter(array_merge(explode(':', $p), [PHP_BINDIR, '/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin']));
    return implode(':', array_unique($dirs));
}

function find_bin(string $name): ?string
{
    if ($name === '') {
        return null;
    }
    if (str_contains($name, '/')) {
        return is_file($name) && is_executable($name) ? $name : null;
    }
    foreach (explode(':', env_path()) as $d) {
        $f = rtrim($d, '/') . '/' . $name;
        if (is_file($f) && is_executable($f)) {
            return $f;
        }
    }
    return null;
}

function php_bin(): string
{
    $s = trim(setting('php_bin'));
    return $s !== '' ? $s : php_bin_auto();
}

/** Best-guess PHP CLI binary matching the PHP version running DPLY. */
function php_bin_auto(): string
{
    $c = [];
    if (PHP_BINARY !== '' && !preg_match('/fpm|cgi|apache|httpd|litespeed|lsphp|frankenphp/i', basename(PHP_BINARY))) {
        $c[] = PHP_BINARY;
    }
    $c[] = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $c[] = PHP_BINDIR . '/php';
    foreach ($c as $f) {
        if (is_file($f) && is_executable($f)) {
            return $f;
        }
    }
    return find_bin('php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?? find_bin('php') ?? 'php';
}

function git_bin(): string
{
    return find_bin(setting('git_bin') ?: 'git') ?? 'git';
}

function composer_cmd(array $args): array
{
    $c = trim(setting('composer_bin')) ?: 'composer';
    if (str_ends_with($c, '.phar')) {
        return array_merge([php_bin(), $c], $args);
    }
    return array_merge([find_bin($c) ?? $c], $args);
}

function artisan_cmd(array $args): array
{
    return array_merge([php_bin(), 'artisan'], $args, ['--no-interaction', '--no-ansi']);
}

function project_root(): string
{
    return rtrim(setting('project_root'), '/') ?: '/';
}

function known_hosts(): string
{
    return DPLY_DATA . '/keys/known_hosts';
}

function ssh_key_dir(): string
{
    return DPLY_DATA . '/keys/ssh';
}

function active_key_path(): ?string
{
    $k = setting('ssh_key');
    $f = ssh_key_dir() . '/' . $k;
    return ($k !== '' && valid_key_name($k) && is_file($f)) ? $f : null;
}

function ssh_command(?string $key): string
{
    $s = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg(known_hosts());
    if ($key) {
        $s .= ' -o IdentitiesOnly=yes -i ' . escapeshellarg($key);
    }
    return $s;
}

/** Environment for git: token goes in an in-memory config header (never into URLs, argv or .git/config). */
function git_env(): array
{
    $cfg = [['safe.directory', project_root()]];
    $env = [];
    $auth = setting('git_auth');
    if ($auth === 'token' && ($tok = dec(setting('git_token'))) !== '') {
        $cfg[] = ['credential.helper', ''];
        $cfg[] = ['http.extraHeader', 'Authorization: Basic ' . base64_encode((setting('git_token_user') ?: 'x-access-token') . ':' . $tok)];
    }
    $env['GIT_SSH_COMMAND'] = ssh_command($auth === 'ssh' ? active_key_path() : null);
    $env['GIT_CONFIG_COUNT'] = (string) count($cfg);
    foreach ($cfg as $i => [$k, $v]) {
        $env["GIT_CONFIG_KEY_$i"] = $k;
        $env["GIT_CONFIG_VALUE_$i"] = $v;
    }
    return $env;
}

function proc_env(bool $withGit = false): array
{
    $home = DPLY_DATA . '/home';
    $env = [
        'PATH'                    => env_path(),
        'HOME'                    => $home,
        'COMPOSER_HOME'           => $home . '/.composer',
        'COMPOSER_NO_INTERACTION' => '1',
        'LANG'                    => 'C.UTF-8',
        'TERM'                    => 'dumb',
        'GIT_TERMINAL_PROMPT'     => '0',
        'GIT_PAGER'               => 'cat',
        'PAGER'                   => 'cat',
    ];
    return $withGit ? array_merge($env, git_env()) : $env;
}

/**
 * Run a command (argv array = no shell). Returns ['code', 'out' (stdout+stderr), 'stdout', 'timeout'].
 */
function run_proc(array $cmd, string $cwd, array $env, int $timeout = 900, ?callable $onChunk = null): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($cmd, $spec, $pipes, is_dir($cwd) ? $cwd : null, $env);
    if (!is_resource($p)) {
        $msg = 'Failed to start process: ' . ($cmd[0] ?? '?') . "\n";
        if ($onChunk) {
            $onChunk($msg);
        }
        return ['code' => 127, 'out' => $msg, 'stdout' => '', 'timeout' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $stdout = '';
    $start = $lastData = microtime(true);
    $timedOut = false;
    while (true) {
        $r = [];
        foreach ([1, 2] as $i) {
            if (!feof($pipes[$i])) {
                $r[] = $pipes[$i];
            }
        }
        if (!$r) {
            break;
        }
        $w = $e = null;
        if (@stream_select($r, $w, $e, 1) === false) {
            break;
        }
        foreach ($r as $s) {
            $chunk = (string) fread($s, 65536);
            if ($chunk === '') {
                continue;
            }
            $lastData = microtime(true);
            if (strlen($out) < DPLY_MAX_OUTPUT) {
                $out .= $chunk;
            }
            if ($s === $pipes[1] && strlen($stdout) < DPLY_MAX_OUTPUT) {
                $stdout .= $chunk;
            }
            if ($onChunk) {
                $onChunk($chunk);
            }
        }
        if ($onChunk && microtime(true) - $lastData > 15) {
            $onChunk(null); // keep-alive for proxies
            $lastData = microtime(true);
        }
        if (microtime(true) - $start > $timeout) {
            $timedOut = true;
            proc_terminate($p, 9);
            break;
        }
    }
    $st = proc_get_status($p);
    while ($st['running'] && microtime(true) - $start <= $timeout + 5) {
        usleep(50000);
        $st = proc_get_status($p);
    }
    if ($st['running']) {
        proc_terminate($p, 9);
        $timedOut = true;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = (int) $st['exitcode'];
    $closed = proc_close($p);
    if ($code === -1) {
        $code = $closed;
    }
    if (strlen($out) >= DPLY_MAX_OUTPUT) {
        $out .= "\n[output truncated]\n";
    }
    return ['code' => $timedOut ? 124 : $code, 'out' => $out, 'stdout' => $stdout, 'timeout' => $timedOut];
}

function git_out(array $args, int $timeout = 30): ?string
{
    if (!is_dir(project_root() . '/.git')) {
        return null;
    }
    $r = run_proc(array_merge([git_bin()], $args), project_root(), proc_env(true), $timeout);
    return $r['code'] === 0 ? rtrim($r['stdout']) : null;
}

function cmd_display(array $cmd): string
{
    $s = implode(' ', array_map(fn($a) => preg_match('#^[A-Za-z0-9_/.:=,@+%-]+$#', (string) $a) ? $a : escapeshellarg((string) $a), $cmd));
    return (string) preg_replace('/(--secret=)\S+/', '$1***', $s);
}

function strip_ansi(string $s): string
{
    return (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a]*\a|\r(?!\n)/', '', $s);
}

function parse_args(string $s): ?array
{
    $args = [];
    $cur = '';
    $has = false;
    $q = null;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($q === "'") {
            if ($c === "'") {
                $q = null;
            } else {
                $cur .= $c;
            }
        } elseif ($q === '"') {
            if ($c === '\\' && $i + 1 < $len && in_array($s[$i + 1], ['"', '\\'], true)) {
                $cur .= $s[++$i];
            } elseif ($c === '"') {
                $q = null;
            } else {
                $cur .= $c;
            }
        } elseif ($c === "'" || $c === '"') {
            $q = $c;
            $has = true;
        } elseif ($c === '\\' && $i + 1 < $len) {
            $cur .= $s[++$i];
            $has = true;
        } elseif (ctype_space($c)) {
            if ($has || $cur !== '') {
                $args[] = $cur;
            }
            $cur = '';
            $has = false;
        } else {
            $cur .= $c;
        }
    }
    if ($q !== null) {
        return null;
    }
    if ($has || $cur !== '') {
        $args[] = $cur;
    }
    return $args;
}

function lines(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $s) ?: []), fn($l) => $l !== '' && $l[0] !== '#'));
}

function path_within(string $path, string $root): bool
{
    $root = rtrim($root, '/');
    return $path === $root || str_starts_with($path, $root . '/');
}

function proc_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $p = posix_getpwuid(posix_geteuid());
        return $p['name'] ?? (string) posix_geteuid();
    }
    return get_current_user();
}

function owner_name(string $f): string
{
    $uid = @fileowner($f);
    if ($uid === false) {
        return '?';
    }
    if (function_exists('posix_getpwuid') && ($p = posix_getpwuid($uid))) {
        return $p['name'];
    }
    return (string) $uid;
}

function cmd_timeout(): int
{
    return max(30, (int) setting('cmd_timeout'));
}

function down_args(): array
{
    $a = ['down', '--retry=60'];
    if (($s = setting('maint_secret')) !== '') {
        $a[] = '--secret=' . $s;
    }
    return $a;
}
