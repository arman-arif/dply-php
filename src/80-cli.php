<?php

declare(strict_types=1);

// ============================================================================
//  CLI
// ============================================================================

function cli_main(array $argv): int
{
    try {
        ensure_storage();
        db();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Storage error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $cmd = $argv[1] ?? 'help';
    $arg = $argv[2] ?? null;
    $opts = [];
    foreach (array_slice($argv, 2) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        }
    }
    if (isset($opts['project'])) {
        $p = is_string($opts['project']) ? project_by_ref($opts['project']) : null;
        if (!$p) {
            fwrite(STDERR, "Unknown project '" . (is_string($opts['project']) ? $opts['project'] : '') . "'. Run: php index.php projects\n");
            return 1;
        }
        use_project($p['id']);
    }
    $code = 0;
    switch ($cmd) {
        case 'projects':
            foreach (projects_all() as $p) {
                $last = project_last_deploy($p['id']);
                printf("%-20s %-28s %-12s %s\n", $p['slug'], utf8_trim($p['name'], 28), project_setting($p['id'], 'branch'),
                    project_setting($p['id'], 'project_root') . ($last ? "  (last deploy: {$last['status']} " . date('Y-m-d H:i', (int) $last['started_at']) . ')' : ''));
            }
            break;
        case 'project:add':
            $slug = strtolower((string) $arg);
            $root = is_string($opts['root'] ?? null) ? rtrim($opts['root'], '/') : '';
            $name = is_string($opts['name'] ?? null) ? trim($opts['name']) : $slug;
            $branch = is_string($opts['branch'] ?? null) ? $opts['branch'] : 'main';
            $repo = is_string($opts['repo'] ?? null) ? trim($opts['repo']) : '';
            [$real, $rootErr] = project_root_check($root);
            $err = !valid_slug($slug) ? 'Usage: project:add <slug> --root=/path [--name="Name"] [--branch=main] [--repo=URL]  (slug: a-z, 0-9, -)'
                : (project_by_ref($slug) ? "Project '$slug' already exists." : ($rootErr ?? branch_error($branch) ?? repo_url_error($repo, 'none')));
            if ($err) {
                echo $err . "\n";
                $code = 1;
                break;
            }
            db()->prepare('INSERT INTO projects (slug, name, created_at) VALUES (?, ?, ?)')->execute([$slug, $name !== '' ? $name : $slug, time()]);
            $pid = (int) db()->lastInsertId();
            projects_changed();
            foreach (['project_root' => (string) $real, 'branch' => $branch, 'repo_url' => $repo] as $k => $v) {
                set_project_setting($pid, $k, $v);
            }
            audit('project.create', "$slug root=$real", 'cli', 'cli');
            echo "✔ Project '$slug' added. Configure auth and steps in the web console, or deploy with: deploy --project=$slug\n";
            break;
        case 'setup':
            if (setup_done() && empty($opts['force'])) {
                echo "Already set up. Use http:set, user:add, user:password (or setup --force).\n";
                $code = 1;
                break;
            }
            echo "=== DPLY setup ===\nData directory: " . DPLY_DATA . "\nProject root:   " . project_root() . "\n";
            $root = cli_prompt('Project root [' . project_root() . ']: ');
            if ($root !== '') {
                if (!is_dir($root)) {
                    echo "Directory not found.\n";
                    return 1;
                }
                set_setting('project_root', (string) realpath($root));
                if (count(projects_all()) === 1 && ($name = basename((string) realpath($root))) !== '') {
                    db()->prepare('UPDATE projects SET name = ?, slug = ? WHERE id = ?')->execute([$name, slugify($name) ?: 'default', current_project_id()]);
                    projects_changed();
                }
            }
            echo "\n-- HTTP Basic auth (outer gate) --\n";
            if (!cli_set_http()) {
                return 1;
            }
            echo "\n-- Administrator account --\n";
            $u = cli_prompt('Admin username: ');
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $u)) {
                echo "Invalid username.\n";
                return 1;
            }
            $p = cli_new_password($u);
            if ($p === null) {
                return 1;
            }
            if ($row = find_user($u)) {
                db()->prepare("UPDATE users SET password_hash = ?, perms = 'admin', active = 1, session_version = session_version + 1 WHERE id = ?")->execute([hash_pw($p), $row['id']]);
            } else {
                db()->prepare("INSERT INTO users (username, password_hash, perms, created_at) VALUES (?, ?, 'admin', ?)")->execute([$u, hash_pw($p), time()]);
            }
            audit('cli.setup', "Admin $u configured", 'cli', 'cli');
            echo "\n✔ Setup complete. Open the console in your browser (HTTPS!).\n";
            break;
        case 'http:set':
            $code = cli_set_http() ? 0 : 1;
            break;
        case 'user:add':
            if (!$arg || !preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $arg) || find_user($arg)) {
                echo "Usage: user:add <username> [--perms=admin|deploy,artisan,...]  (username must be new)\n";
                $code = 1;
                break;
            }
            $perms = implode(',', array_intersect(array_keys(DPLY_PERMS), explode(',', (string) ($opts['perms'] ?? 'deploy'))));
            $p = cli_new_password($arg);
            if ($p === null) {
                return 1;
            }
            db()->prepare('INSERT INTO users (username, password_hash, perms, created_at) VALUES (?, ?, ?, ?)')->execute([$arg, hash_pw($p), $perms, time()]);
            audit('cli.user_add', "$arg [$perms]", 'cli', 'cli');
            echo "✔ User $arg created with [$perms].\n";
            break;
        case 'user:password':
            $row = $arg ? find_user($arg) : null;
            if (!$row) {
                echo "Usage: user:password <existing-username>\n";
                $code = 1;
                break;
            }
            $p = cli_new_password($row['username']);
            if ($p === null) {
                return 1;
            }
            db()->prepare('UPDATE users SET password_hash = ?, failed_count = 0, locked_until = 0, session_version = session_version + 1 WHERE id = ?')->execute([hash_pw($p), $row['id']]);
            audit('cli.user_password', $row['username'], 'cli', 'cli');
            echo "✔ Password changed.\n";
            break;
        case 'user:unlock':
        case 'user:2fa-reset':
        case 'user:enable':
            $row = $arg ? find_user($arg) : null;
            if (!$row) {
                echo "Usage: $cmd <existing-username>\n";
                $code = 1;
                break;
            }
            $sql = ['user:unlock' => 'UPDATE users SET failed_count = 0, locked_until = 0 WHERE id = ?',
                'user:2fa-reset' => 'UPDATE users SET totp_secret = NULL, totp_last = 0 WHERE id = ?',
                'user:enable' => 'UPDATE users SET active = 1 WHERE id = ?'][$cmd];
            db()->prepare($sql)->execute([$row['id']]);
            audit('cli.' . $cmd, $row['username'], 'cli', 'cli');
            echo "✔ Done.\n";
            break;
        case 'user:list':
            foreach (db()->query('SELECT username, perms, active, locked_until, totp_secret FROM users ORDER BY username') as $r) {
                printf("%-24s %-40s %s%s%s\n", $r['username'], $r['perms'], $r['active'] ? 'active' : 'disabled',
                    (int) $r['locked_until'] > time() ? ' LOCKED' : '', $r['totp_secret'] ? ' 2FA' : '');
            }
            break;
        case 'unblock':
            if ($arg === '--all') {
                db()->exec('DELETE FROM blocks; DELETE FROM attempts;');
            } elseif ($arg && filter_var($arg, FILTER_VALIDATE_IP)) {
                db()->prepare('DELETE FROM blocks WHERE ip = ?')->execute([$arg]);
                db()->prepare('DELETE FROM attempts WHERE ip = ?')->execute([$arg]);
            } else {
                echo "Usage: unblock <ip>|--all\n";
                $code = 1;
                break;
            }
            audit('cli.unblock', (string) $arg, 'cli', 'cli');
            echo "✔ Unblocked.\n";
            break;
        case 'blocks':
            foreach (db()->query('SELECT * FROM blocks WHERE until > ' . time()) as $b) {
                printf("%-40s until %s  strikes %d  %s\n", $b['ip'], date('Y-m-d H:i:s', (int) $b['until']), $b['strikes'], $b['reason']);
            }
            break;
        case 'allowlist:clear':
            set_setting('ip_allowlist', '');
            audit('cli.allowlist_clear', '', 'cli', 'cli');
            echo "✔ IP allow-list cleared.\n";
            break;
        case 'deploy':
            $steps = isset($opts['steps']) && is_string($opts['steps']) ? selected_steps(explode(',', $opts['steps'])) : default_steps();
            $r = deploy_run('cli', $steps, null, function (?string $s) {
                if ($s !== null) {
                    echo $s;
                }
            });
            echo "\n[" . (current_project()['slug'] ?? '?') . '] ' . $r['msg'] . ($r['id'] ? " (#{$r['id']})" : '') . "\n";
            $code = $r['ok'] ? 0 : 1;
            break;
        default:
            echo <<<TXT
DPLY {$GLOBALS['argv'][0]} — commands:
  setup [--force]          Initial setup: HTTP auth credentials + admin account
  http:set                 Change HTTP Basic auth username/password
  user:add <name> [--perms=deploy,artisan,terminal,terminal_full,env,keys,admin]
  user:password <name>     Set a user's password (also unlocks)
  user:unlock <name>       Clear a user's lockout
  user:enable <name>       Re-enable a disabled user
  user:2fa-reset <name>    Remove a user's two-factor secret
  user:list                List users
  blocks                   List currently blocked IPs
  unblock <ip>|--all       Remove IP blocks
  allowlist:clear          Remove the IP allow-list (if you locked yourself out)
  projects                 List projects
  project:add <slug> --root=/path [--name="Name"] [--branch=main] [--repo=URL]
  deploy [--steps=a,b,c]   Run a deployment from the command line

Project-specific commands (deploy) act on the first project unless you pass
--project=<slug>, e.g.: php index.php deploy --project=shop-api

Run as the same user PHP-FPM/Apache uses, e.g.: sudo -u www-data php index.php setup

TXT;
    }
    cli_fix_owner();
    return $code;
}

function cli_prompt(string $q, bool $hidden = false): string
{
    fwrite(STDOUT, $q);
    $tty = function_exists('posix_isatty') ? @posix_isatty(STDIN) : (function_exists('stream_isatty') && @stream_isatty(STDIN));
    if ($hidden && $tty) {
        @shell_exec('stty -echo 2>/dev/null');
        $v = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        $v = fgets(STDIN);
    }
    return $v === false ? '' : rtrim($v, "\r\n");
}

function cli_new_password(string $username): ?string
{
    $p = cli_prompt('Password (min 12 chars): ', true);
    if ($e = pw_policy($p, $username)) {
        echo $e . "\n";
        return null;
    }
    if (cli_prompt('Repeat password: ', true) !== $p) {
        echo "Passwords do not match.\n";
        return null;
    }
    return $p;
}

function cli_set_http(): bool
{
    $u = cli_prompt('HTTP auth username: ');
    if ($u === '' || strlen($u) > 64 || str_contains($u, ':')) {
        echo "Invalid username (1-64 chars, no ':').\n";
        return false;
    }
    $p = cli_new_password($u);
    if ($p === null) {
        return false;
    }
    set_setting('http_user', $u);
    set_setting('http_pass_hash', hash_pw($p));
    audit('cli.http_auth', "HTTP auth set (user $u)", 'cli', 'cli');
    echo "✔ HTTP auth credentials saved.\n";
    return true;
}

/** If run as root, hand the data directory to the owner of this script so the web server can use it. */
function cli_fix_owner(): void
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    $uid = (int) fileowner(__FILE__);
    $gid = (int) filegroup(__FILE__);
    if ($uid === 0) {
        fwrite(STDERR, "Warning: running as root and index.php is owned by root — make sure " . DPLY_DATA . " is owned by the web server user.\n");
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DPLY_DATA, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    @chown(DPLY_DATA, $uid);
    @chgrp(DPLY_DATA, $gid);
    foreach ($it as $f) {
        @chown((string) $f, $uid);
        @chgrp((string) $f, $gid);
    }
    fwrite(STDERR, "Note: ran as root — data directory ownership handed to uid $uid (owner of index.php).\n");
}
