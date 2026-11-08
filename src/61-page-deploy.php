<?php

declare(strict_types=1);

// ============================================================================
//  Deploy
// ============================================================================

function page_deploy(): void
{
    $root = project_root();
    $isGit = is_dir("$root/.git");
    $branch = setting('branch');
    $remote = setting('repo_url') ?: ($isGit ? (string) git_out(['remote', 'get-url', 'origin']) : '');
    $head = $isGit ? git_out(['log', '-1', '--format=%H%n%h — %s%n%an, %cr']) : null;
    $status = $isGit ? (string) git_out(['status', '--porcelain']) : '';
    [$hash, $subject, $meta] = array_pad(explode("\n", (string) $head), 3, '');

    $statusHtml = $status === '' ? badge('clean', 'ok')
        : badge(count(explode("\n", $status)) . ' local change(s)', 'warn')
        . '<pre class="small">' . h(implode("\n", array_slice(explode("\n", $status), 0, 40))) . '</pre>'
        . '<p class="small mute">With the "reset" strategy these tracked changes are overwritten on deploy.</p>';

    $deps = db()->query('SELECT id, actor, kind, branch, status, commit_before, commit_after, started_at, finished_at FROM deployments ORDER BY id DESC LIMIT 30')->fetchAll();
    $hist = '';
    foreach ($deps as $d) {
        $dur = $d['finished_at'] ? ((int) $d['finished_at'] - (int) $d['started_at']) . 's' : '…';
        $rb = '';
        if ($d['status'] !== 'running' && preg_match('/^[0-9a-f]{40}$/', (string) $d['commit_before']) && $d['commit_before'] !== $d['commit_after']) {
            $rb = '<form method="post" action="?a=deploy" data-once data-confirm="Roll back the code to ' . h(substr($d['commit_before'], 0, 12))
                . ' and re-run your default post-deploy steps? Database migrations are NOT reverted.">' . csrf_field()
                . '<input type="hidden" name="op" value="rollback"><input type="hidden" name="id" value="' . (int) $d['id'] . '">'
                . '<button class="sm danger" type="submit">Roll back to before</button></form>';
        }
        $hist .= '<tr><td>#' . (int) $d['id'] . '</td><td>' . h($d['kind']) . '</td><td>' . deploy_status_badge($d['status']) . '</td><td>' . h($d['actor'])
            . '</td><td><code>' . h(substr((string) $d['commit_before'], 0, 8) ?: '—') . '</code> → <code>' . h(substr((string) $d['commit_after'], 0, 8) ?: '—') . '</code></td><td>'
            . h(fmt_time((int) $d['started_at'])) . '</td><td>' . h($dur) . '</td><td class="row"><a href="?a=deploy_log&id=' . (int) $d['id'] . '">log</a>' . $rb . '</td></tr>';
    }

    render('Deploy', '<h1>Deploy</h1><div class="grid">'
        . '<div class="card"><h2>Current state</h2><div class="tbl"><table>'
        . '<tr><td class="k">Repository</td><td>' . ($remote ? '<code>' . h($remote) . '</code>' : badge('not configured', 'warn')) . '</td></tr>'
        . '<tr><td class="k">Deploy branch</td><td><code>' . h($branch) . '</code></td></tr>'
        . '<tr><td class="k">Auth</td><td>' . h(setting('git_auth')) . (setting('git_auth') === 'ssh' ? ' (' . h(setting('ssh_key') ?: 'no key selected') . ')' : '') . '</td></tr>'
        . '<tr><td class="k">Strategy</td><td>' . (setting('sync_strategy') === 'ff' ? 'fast-forward only' : 'force-sync (reset to remote)') . '</td></tr>'
        . '<tr><td class="k">HEAD</td><td>' . ($head ? h($subject) . '<br><span class="mute small">' . h($meta) . ' · <code>' . h(substr($hash, 0, 12)) . '</code></span>' : '—') . '</td></tr>'
        . '<tr><td class="k">Working tree</td><td>' . ($isGit ? $statusHtml : badge('not a git repo yet — first deploy will initialise it in place', 'warn')) . '</td></tr>'
        . '</table></div>'
        . '<form method="post" action="?a=deploy" data-once class="row" style="margin-top:12px">' . csrf_field()
        . '<input type="hidden" name="op" value="check"><button type="submit">Check for updates</button></form></div>'
        . '<div class="card"><h2>Deploy now</h2><form method="post" action="?a=deploy" data-once data-confirm="Deploy origin/' . h($branch) . ' now?">' . csrf_field()
        . '<input type="hidden" name="op" value="deploy"><p class="mute small">1. fetch origin/' . h($branch) . ' → 2. sync files → 3. selected steps:</p>'
        . steps_checkboxes(default_steps()) . '<p><button class="primary" type="submit">Deploy</button></p></form></div></div>'
        . '<div class="card"><h2>History</h2>' . ($hist ? '<div class="tbl"><table><tr><th>#</th><th>Type</th><th>Status</th><th>By</th><th>Commits</th><th>Started</th><th>Took</th><th></th></tr>'
            . $hist . '</table></div>' : '<p class="mute">No deployments yet.</p>') . '</div>');
}

function do_deploy(): void
{
    $op = (string) ($_POST['op'] ?? '');
    $actor = current_user()['username'];
    $back = '<p class="row" style="margin-top:12px"><a class="btn" href="?a=deploy">← Back to deploy</a></p>';

    if ($op === 'check') {
        $root = project_root();
        if (!is_dir("$root/.git")) {
            flash('warn', 'Not a git repository yet. Configure the repository in Settings and run a deploy to initialise it.');
            redirect('?a=deploy');
        }
        $branch = setting('branch');
        stream_begin('Check for updates');
        $genv = proc_env(true);
        if (($url = trim(setting('repo_url'))) !== '' && git_out(['remote', 'get-url', 'origin']) !== $url) {
            stream_out("Note: origin URL differs from settings; it will be updated on next deploy. Fetching configured URL.\n");
            $fetch = [git_bin(), 'fetch', '--prune', $url, "+refs/heads/$branch:refs/remotes/origin/$branch"];
        } else {
            $fetch = [git_bin(), 'fetch', '--prune', 'origin', "+refs/heads/$branch:refs/remotes/origin/$branch"];
        }
        stream_out('$ ' . cmd_display($fetch) . "\n");
        $r = run_proc($fetch, $root, $genv, 300, 'stream_out');
        stream_out("[exit {$r['code']}]\n\n");
        if ($r['code'] === 0) {
            $log = (string) git_out(['log', '--format=%h  %<(16,trunc)%an  %s', "HEAD..origin/$branch"]);
            $behind = $log === '' ? 0 : count(explode("\n", $log));
            $ahead = (string) git_out(['rev-list', '--count', "origin/$branch..HEAD"]);
            stream_out($behind ? "$behind incoming commit(s) on origin/$branch:\n\n$log\n\n" : "Up to date with origin/$branch.\n");
            if ((int) $ahead > 0) {
                stream_out("⚠ Local HEAD has $ahead commit(s) not on origin/$branch (they would be discarded by a force-sync).\n");
            }
            if ($behind) {
                stream_out("\nChanged files:\n" . (string) git_out(['diff', '--stat=120', "HEAD", "origin/$branch"]) . "\n");
                $files = (string) git_out(['diff', '--name-only', 'HEAD', "origin/$branch"]);
                if (preg_match('#^database/migrations/#m', $files)) {
                    stream_out("\n→ New/changed migrations detected — keep the migrate step enabled.\n");
                }
                if (preg_match('#^composer\.(json|lock)$#m', $files)) {
                    stream_out("→ composer.json/lock changed — keep the composer step enabled.\n");
                }
            }
        }
        audit('deploy.check', "origin/$branch exit {$r['code']}");
        stream_end($back);
        return;
    }

    if ($op === 'deploy' || $op === 'rollback') {
        $target = null;
        $steps = $op === 'deploy' ? selected_steps((array) ($_POST['steps'] ?? [])) : default_steps();
        if ($op === 'rollback') {
            $st = db()->prepare('SELECT commit_before FROM deployments WHERE id = ?');
            $st->execute([(int) ($_POST['id'] ?? 0)]);
            $target = (string) $st->fetchColumn();
            if (!preg_match('/^[0-9a-f]{40}$/', $target)) {
                flash('err', 'That deployment has no commit to roll back to.');
                redirect('?a=deploy');
            }
        }
        stream_begin($op === 'deploy' ? 'Deploying' : 'Rolling back');
        $r = deploy_run($actor, $steps, $target, 'stream_out');
        stream_end('<div class="flash ' . ($r['ok'] ? 'ok' : 'err') . '" style="margin-top:12px">' . h($r['msg'])
            . ($r['id'] ? ' <a href="?a=deploy_log&id=' . $r['id'] . '">Permanent log #' . $r['id'] . '</a>' : '') . '</div>' . $back);
        return;
    }
    redirect('?a=deploy');
}

function page_deploy_log(): void
{
    $st = db()->prepare('SELECT * FROM deployments WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $d = $st->fetch();
    if (!$d) {
        flash('err', 'Deployment not found.');
        redirect('?a=deploy');
    }
    render('Deployment #' . $d['id'], '<h1>' . h(ucfirst($d['kind'])) . ' #' . (int) $d['id'] . ' ' . deploy_status_badge($d['status']) . '</h1>'
        . '<p class="mute">By ' . h($d['actor']) . ' · ' . h(fmt_time((int) $d['started_at'])) . ' → ' . h(fmt_time((int) $d['finished_at']))
        . ' · steps: ' . h((string) $d['steps']) . '</p><pre class="log">' . h((string) $d['log']) . '</pre>'
        . '<p style="margin-top:12px"><a class="btn" href="?a=deploy">← Back</a></p>');
}
