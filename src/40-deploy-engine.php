<?php

declare(strict_types=1);

// ============================================================================
//  Deployment engine
// ============================================================================

function selected_steps(array $in): array
{
    $in = array_map('strval', $in);
    return array_values(array_filter(array_keys(DPLY_STEPS), fn($k) => in_array($k, $in, true)));
}

function default_steps(): array
{
    return selected_steps(explode(',', setting('post_steps')));
}

function repo_rel_path(string $abs): ?string
{
    $root = project_root();
    $real = realpath($abs) ?: $abs;
    return path_within($real, $root) ? ltrim(substr($real, strlen(rtrim($root, '/'))), '/') : null;
}

/**
 * Fetch + sync the working tree, then run the post-deploy steps.
 * $rollbackTo: commit hash to reset to instead of fetching.
 */
function deploy_run(string $actor, array $steps, ?string $rollbackTo = null, ?callable $echo = null): array
{
    $lock = fopen(DPLY_DATA . '/deploy.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'id' => 0, 'msg' => 'Another deployment is already running.'];
    }
    @set_time_limit(0);
    ignore_user_abort(true);

    $pdo = db();
    $root = project_root();
    $branch = setting('branch');
    $pdo->prepare('INSERT INTO deployments (actor, kind, branch, status, steps, started_at, log) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$actor, $rollbackTo ? 'rollback' : 'deploy', $branch, 'running', implode(',', $steps), time(), '']);
    $id = (int) $pdo->lastInsertId();
    audit($rollbackTo ? 'deploy.rollback' : 'deploy.start', "#$id " . ($rollbackTo ? "to $rollbackTo" : "branch $branch"), $actor);

    $log = '';
    $L = function (?string $s) use (&$log, $echo): void {
        if ($s !== null) {
            $log .= $s;
        }
        if ($echo) {
            $echo($s);
        }
    };
    $git = [git_bin()];
    $genv = proc_env(true);
    $exec = function (string $label, array $cmd, bool $fatal = true, bool $withGit = false) use ($L, $root, $genv): bool {
        $L("\n▶ $label\n\$ " . cmd_display($cmd) . "\n");
        $r = run_proc($cmd, $root, $withGit ? $genv : proc_env(), cmd_timeout(), function ($c) use ($L) {
            $L($c === null ? null : strip_ansi($c));
        });
        $L(($r['timeout'] ? "\n[timed out]" : '') . "\n[exit {$r['code']}]\n");
        if ($r['code'] !== 0 && $fatal) {
            throw new RuntimeException("Step failed: $label");
        }
        return $r['code'] === 0;
    };

    $ok = true;
    $before = $after = '';
    $L(sprintf("DPLY %s — %s #%d by %s at %s\nProject: %s\n", DPLY_VERSION, $rollbackTo ? 'rollback' : 'deployment', $id, $actor, date('Y-m-d H:i:s'), $root));
    try {
        if (!is_dir($root)) {
            throw new RuntimeException("Project root not found: $root");
        }
        $url = trim(setting('repo_url'));
        if (!is_dir("$root/.git")) {
            if ($rollbackTo) {
                throw new RuntimeException('Not a git repository — nothing to roll back.');
            }
            if ($url === '') {
                throw new RuntimeException('The project is not a git repository and no repository URL is configured (Settings).');
            }
            $L("\nNo .git directory: initialising a repository in place (existing files are kept).\n");
            $exec('Initialise repository', array_merge($git, ['init', '-q', '-b', $branch]), true, true);
            $exec('Add origin remote', array_merge($git, ['remote', 'add', 'origin', $url]), true, true);
        } elseif ($url !== '') {
            $cur = git_out(['remote', 'get-url', 'origin']);
            if ($cur === null) {
                $exec('Add origin remote', array_merge($git, ['remote', 'add', 'origin', $url]), true, true);
            } elseif ($cur !== $url) {
                $exec('Update origin URL', array_merge($git, ['remote', 'set-url', 'origin', $url]), true, true);
            }
        }
        $before = (string) git_out(['rev-parse', 'HEAD']);

        if ($rollbackTo) {
            if (git_out(['cat-file', '-e', $rollbackTo . '^{commit}']) === null) {
                throw new RuntimeException("Commit $rollbackTo does not exist locally.");
            }
        } else {
            $exec("Fetch origin/$branch", array_merge($git, ['fetch', '--prune', '--tags', '--force', 'origin',
                "+refs/heads/$branch:refs/remotes/origin/$branch"]), true, true);
            $remote = (string) git_out(['rev-parse', "origin/$branch"]);
            $L($remote === $before ? "\nAlready at origin/$branch ($remote) — running post-deploy steps anyway.\n"
                : "\nUpdating " . ($before ? substr($before, 0, 12) : '(none)') . ' → ' . substr($remote, 0, 12) . "\n");
        }

        if (in_array('down', $steps, true)) {
            $exec('Maintenance mode ON', artisan_cmd(down_args()), false);
        }

        if ($rollbackTo) {
            $exec('Reset working tree to ' . substr($rollbackTo, 0, 12), array_merge($git, ['reset', '--hard', $rollbackTo]), true, true);
        } elseif (setting('sync_strategy') === 'ff' && $before !== '') {
            $curBranch = (string) git_out(['rev-parse', '--abbrev-ref', 'HEAD']);
            if ($curBranch !== $branch) {
                $exec("Switch to branch $branch", array_merge($git, ['checkout', '-B', $branch, "origin/$branch"]), true, true);
            } else {
                $exec('Fast-forward merge', array_merge($git, ['merge', '--ff-only', "origin/$branch"]), true, true);
            }
        } else {
            // Force-sync tracked files to the remote; untracked & ignored files (.env, vendor, storage) are untouched.
            $exec("Sync working tree to origin/$branch", array_merge($git, ['checkout', '--force', '-B', $branch, "origin/$branch"]), true, true);
        }

        if (setting('git_clean') === '1') {
            $clean = array_merge($git, ['clean', '-fd', '-e', '/.env']);
            foreach ([DPLY_DATA, DPLY_DIR . '/.htaccess'] as $keep) {
                if (($rel = repo_rel_path($keep)) !== null && $rel !== '') {
                    array_push($clean, '-e', '/' . $rel);
                }
            }
            $exec('Remove untracked files (git clean)', $clean, false, true);
        }
        if (setting('submodules') === '1' && is_file("$root/.gitmodules")) {
            $exec('Update submodules', array_merge($git, ['submodule', 'update', '--init', '--recursive']), true, true);
        }
        $after = (string) git_out(['rev-parse', 'HEAD']);
        $L("\nNow at: " . (string) git_out(['log', '-1', '--format=%h %s (%an, %cr)']) . "\n");

        foreach ($steps as $s) {
            switch ($s) {
                case 'composer':
                    if (!is_file("$root/composer.json")) {
                        $L("\n(no composer.json — skipping composer)\n");
                        break;
                    }
                    $exec('Composer install', composer_cmd(['install', '--no-dev', '--optimize-autoloader', '--prefer-dist',
                        '--no-interaction', '--no-progress', '--no-ansi']));
                    break;
                case 'migrate':
                    $exec('Run migrations', artisan_cmd(['migrate', '--force']));
                    break;
                case 'storage_link':
                    $exec('Storage link', artisan_cmd(['storage:link']), false);
                    break;
                case 'optimize_clear':
                    $exec('Clear all caches', artisan_cmd(['optimize:clear']), false);
                    break;
                case 'config_cache':
                    $exec('Cache config', artisan_cmd(['config:cache']));
                    break;
                case 'route_cache':
                    $exec('Cache routes', artisan_cmd(['route:cache']), false);
                    break;
                case 'view_cache':
                    $exec('Cache views', artisan_cmd(['view:cache']), false);
                    break;
                case 'event_cache':
                    $exec('Cache events', artisan_cmd(['event:cache']), false);
                    break;
                case 'queue_restart':
                    $exec('Restart queue workers', artisan_cmd(['queue:restart']), false);
                    break;
                case 'custom':
                    foreach (lines(setting('custom_commands')) as $line) {
                        $exec('Custom command', ['/bin/sh', '-c', $line]);
                    }
                    break;
                case 'opcache':
                    if (function_exists('opcache_reset') && PHP_SAPI !== 'cli') {
                        $L("\n▶ Reset OPcache\n" . (@opcache_reset() ? "OPcache reset.\n" : "OPcache not active.\n"));
                    }
                    break;
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $L("\n✖ " . $e->getMessage() . "\n");
    }
    if (in_array('up', $steps, true)) {
        try {
            $exec('Maintenance mode OFF', artisan_cmd(['up']), false);
        } catch (Throwable $e) {
            $L("\n✖ " . $e->getMessage() . "\n");
        }
    }
    if ($after === '') {
        $after = (string) git_out(['rev-parse', 'HEAD']);
    }
    $L("\n" . ($ok ? '✔ Finished successfully.' : '✖ Finished with errors.') . "\n");
    $pdo->prepare('UPDATE deployments SET status = ?, commit_before = ?, commit_after = ?, log = ?, finished_at = ? WHERE id = ?')
        ->execute([$ok ? 'success' : 'failed', $before, $after, substr($log, -2000000), time(), $id]);
    audit('deploy.finish', "#$id " . ($ok ? 'success' : 'failed') . ' ' . substr($before, 0, 12) . ' → ' . substr($after, 0, 12), $actor);
    flock($lock, LOCK_UN);
    fclose($lock);
    return ['ok' => $ok, 'id' => $id, 'msg' => $ok ? 'Deployment succeeded.' : 'Deployment failed.'];
}
