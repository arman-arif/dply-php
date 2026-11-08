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
        . '<pre class="small" style="margin-top:10px;max-height:220px">' . h(implode("\n", array_slice(explode("\n", $status), 0, 40))) . '</pre>'
        . '<p class="hint">With the "reset" strategy these tracked changes are overwritten on deploy.</p>';

    $st = db()->prepare('SELECT id, actor, kind, branch, status, commit_before, commit_after, started_at, finished_at FROM deployments WHERE project_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([current_project_id()]);
    $deps = $st->fetchAll();
    $hist = '';
    foreach ($deps as $d) {
        $dur = $d['finished_at'] ? ((int) $d['finished_at'] - (int) $d['started_at']) . 's' : '…';
        $rb = '';
        if ($d['status'] !== 'running' && preg_match('/^[0-9a-f]{40}$/', (string) $d['commit_before']) && $d['commit_before'] !== $d['commit_after']) {
            $rb = '<form method="post" action="?a=deploy" data-once data-confirm="Roll back the code to ' . h(substr($d['commit_before'], 0, 12))
                . ' and re-run your default post-deploy steps? Database migrations are NOT reverted.">' . csrf_field()
                . '<input type="hidden" name="op" value="rollback"><input type="hidden" name="id" value="' . (int) $d['id'] . '">'
                . '<button class="sm danger" type="submit" title="Roll back to the commit before this deployment">' . icon('back') . 'Roll back</button></form>';
        }
        $hist .= '<tr><td><a href="?a=deploy_log&id=' . (int) $d['id'] . '"><b>#' . (int) $d['id'] . '</b></a></td><td>' . h(ucfirst((string) $d['kind'])) . '</td><td>' . deploy_status_badge($d['status'])
            . '</td><td><span class="row" style="flex-wrap:nowrap;gap:8px">' . avatar((string) $d['actor']) . h($d['actor']) . '</span></td><td style="white-space:nowrap"><code>' . h(substr((string) $d['commit_before'], 0, 8) ?: '—')
            . '</code> <span class="mute">→</span> <code>' . h(substr((string) $d['commit_after'], 0, 8) ?: '—') . '</code></td><td style="white-space:nowrap">'
            . ago((int) $d['started_at']) . '</td><td>' . h($dur) . '</td><td><div class="row end"><a class="btn sm" href="?a=deploy_log&id=' . (int) $d['id'] . '">View log</a>' . $rb . '</div></td></tr>';
    }

    $checkBtn = '<form method="post" action="?a=deploy" data-once>' . csrf_field()
        . '<input type="hidden" name="op" value="check"><button type="submit">' . icon('refresh') . 'Check for updates</button></form>';
    $kv = [
        'Repository'    => $remote ? '<code>' . h($remote) . '</code>' : badge('not configured', 'warn'),
        'Deploy branch' => '<code>' . h($branch) . '</code>',
        'Auth'          => h(setting('git_auth')) . (setting('git_auth') === 'ssh' ? ' <span class="mute">(' . h(setting('ssh_key') ?: 'no key selected') . ')</span>' : ''),
        'Strategy'      => setting('sync_strategy') === 'ff' ? 'Fast-forward only' : 'Force-sync (reset to remote)',
        'HEAD'          => $head ? h($subject) . '<br><span class="mute small">' . h($meta) . ' · <code>' . h(substr($hash, 0, 12)) . '</code></span>' : '—',
        'Working tree'  => $isGit ? $statusHtml : badge('not a git repo yet — first deploy will initialise it in place', 'warn'),
    ];
    $kvHtml = '';
    foreach ($kv as $k => $v) {
        $kvHtml .= '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
    }

    render('Deploy', page_head('Deploy ' . h(current_project()['name']), 'Pull the latest code from origin/' . $branch . ' into ' . $root . ' and run your post-deploy steps.', $checkBtn)
        . '<div class="grid-main">'
        . '<form method="post" action="?a=deploy" class="card" data-once data-confirm="Deploy origin/' . h($branch) . ' of ' . h(current_project()['name']) . ' now?">' . csrf_field()
        . '<input type="hidden" name="op" value="deploy"><h2>' . icon('deploy') . 'Deploy now</h2>'
        . '<ol class="pipeline"><li><b>Fetch</b>origin/' . h($branch) . '</li><li><b>Sync</b>update files</li><li><b>Run steps</b>selected below</li></ol>'
        . '<div class="sub-h" style="margin-top:0">Post-deploy steps</div>' . steps_checkboxes(default_steps())
        . '<p class="hint">Defaults come from Settings. Untick anything you want to skip for this run.</p>'
        . '<div class="actions"><button class="primary btn-lg" type="submit">' . icon('deploy') . 'Deploy ' . h($branch) . '</button></div></form>'
        . '<div class="card"><h2>' . icon('branch') . 'Current state</h2><dl class="kv">' . $kvHtml . '</dl></div></div>'
        . '<div class="card"><div class="card-h"><h2>' . icon('clock') . 'History</h2><span class="small mute">Last 30 runs</span></div>'
        . ($hist ? '<div class="tbl"><table><tr><th>#</th><th>Type</th><th>Status</th><th>By</th><th>Commits</th><th>Started</th><th>Took</th><th></th></tr>'
            . $hist . '</table></div>' : empty_state('deploy', 'No deployments yet — your first run will appear here.')) . '</div>');
}

function do_deploy(): void
{
    $op = (string) ($_POST['op'] ?? '');
    $actor = current_user()['username'];
    $back = back_link('?a=deploy', 'Back to deploy');

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
            $st = db()->prepare('SELECT commit_before FROM deployments WHERE id = ? AND project_id = ?');
            $st->execute([(int) ($_POST['id'] ?? 0), current_project_id()]);
            $target = (string) $st->fetchColumn();
            if (!preg_match('/^[0-9a-f]{40}$/', $target)) {
                flash('err', 'That deployment has no commit to roll back to.');
                redirect('?a=deploy');
            }
        }
        stream_begin($op === 'deploy' ? 'Deploying' : 'Rolling back');
        $r = deploy_run($actor, $steps, $target, 'stream_out');
        stream_end('<div class="flash ' . ($r['ok'] ? 'ok' : 'err') . '">' . h($r['msg'])
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
    if ($d && (int) $d['project_id'] !== current_project_id()) {
        // Belongs to another project: follow it if allowed, so shared links keep working.
        if (user_can_project(current_user(), (int) $d['project_id'])) {
            use_project((int) $d['project_id']);
        } else {
            $d = null;
        }
    }
    if (!$d) {
        flash('err', 'Deployment not found.');
        redirect('?a=deploy');
    }
    $dur = $d['finished_at'] ? ((int) $d['finished_at'] - (int) $d['started_at']) . 's' : null;
    render('Deployment #' . $d['id'], page_head(h(ucfirst($d['kind'])) . ' #' . (int) $d['id'] . ' ' . deploy_status_badge($d['status']),
        'By ' . $d['actor'] . ' · started ' . fmt_time((int) $d['started_at']) . ($dur !== null ? ' · took ' . $dur : ''),
        '<a class="btn" href="?a=deploy">' . icon('back') . 'All deployments</a>')
        . '<div class="tips"><span>' . icon('list') . 'Steps: ' . h(str_replace(',', ', ', (string) $d['steps'])) . '</span></div>'
        . '<pre class="log">' . h((string) $d['log']) . '</pre>');
}
