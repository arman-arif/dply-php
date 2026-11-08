<?php

declare(strict_types=1);

// ============================================================================
//  Dashboard
// ============================================================================

function page_dashboard(): void
{
    $root = project_root();
    $isGit = is_dir("$root/.git");
    $head = $isGit ? git_out(['log', '-1', '--format=%h — %s (%an, %cr)']) : null;
    $branch = $isGit ? git_out(['rev-parse', '--abbrev-ref', 'HEAD']) : null;
    $status = $isGit ? git_out(['status', '--porcelain']) : null;
    $changes = $status === null || $status === '' ? 0 : count(explode("\n", $status));
    $down = is_file("$root/storage/framework/down");

    $warn = [];
    if (!is_https()) {
        $warn[] = 'This page is not served over HTTPS — credentials and tokens travel in clear text.';
    }
    if (is_dir($root) && owner_name($root) !== proc_user()) {
        $warn[] = 'PHP runs as "' . proc_user() . '" but the project is owned by "' . owner_name($root) . '". Git/composer may fail on permissions.';
    }
    if (is_file("$root/.env") && (fileperms("$root/.env") & 0004)) {
        $warn[] = '.env is world-readable. Consider: chmod 640 .env';
    }
    if (path_within(DPLY_DATA, DPLY_PUBLIC) && stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'apache') === false) {
        $warn[] = 'Not running on Apache: make sure your web server denies requests to ' . DPLY_DATA . ' (see header of index.php).';
    }
    $warnHtml = '';
    foreach ($warn as $w) {
        $warnHtml .= '<div class="flash warn">' . h($w) . '</div>';
    }

    $rows = [
        'Project root'   => '<code>' . h($root) . '</code>' . (is_dir($root) ? '' : ' ' . badge('missing', 'err')),
        'HEAD'           => $head ? h($head) : '<span class="mute">—</span>',
        'Laravel'        => h(laravel_version()),
        'PHP (CLI used)' => '<code>' . h(php_bin()) . '</code>',
        'Runs as'        => h(proc_user()),
        'Git auth'       => h(setting('git_auth')),
    ];
    $info = '';
    foreach ($rows as $k => $v) {
        $info .= '<tr><td class="k">' . h($k) . '</td><td>' . $v . '</td></tr>';
    }

    $st = db()->prepare('SELECT * FROM deployments WHERE project_id = ? ORDER BY id DESC LIMIT 6');
    $st->execute([current_project_id()]);
    $deps = $st->fetchAll();
    $last = $deps[0] ?? null;
    $canDeploy = can('deploy');
    $dHtml = '';
    foreach ($deps as $d) {
        $tone = $d['status'] === 'success' ? 'ok' : ($d['status'] === 'failed' ? 'err' : 'warn');
        $idTxt = '<b>' . h(ucfirst((string) $d['kind'])) . ' #' . (int) $d['id'] . '</b>';
        $dHtml .= '<li><span class="dot ' . $tone . '"></span><span class="who">'
            . ($canDeploy ? '<a href="?a=deploy_log&id=' . (int) $d['id'] . '">' . $idTxt . '</a>' : $idTxt)
            . ' <span class="mute">by ' . h($d['actor']) . '</span></span>' . deploy_status_badge($d['status'])
            . '<span class="when">' . ago((int) $d['started_at']) . '</span></li>';
    }

    $freeGb = is_dir($root) ? round((float) @disk_free_space($root) / 1073741824, 1) : null;
    $stats = '<div class="stats">'
        . stat_tile('power', 'Application', $down ? 'Maintenance' : 'Online', $down ? 'artisan down is active' : 'Serving requests', $down ? 'err' : 'ok')
        . stat_tile('branch', 'Git branch', $branch ? h($branch) : 'Not a repo',
            $branch && $branch !== setting('branch') ? 'deploys <b>' . h(setting('branch')) . '</b>' : 'deploy branch: ' . h(setting('branch')),
            !$isGit || ($branch && $branch !== setting('branch')) ? 'warn' : 'info')
        . stat_tile('files', 'Working tree', !$isGit ? '—' : ($changes ? $changes . ' change' . ($changes === 1 ? '' : 's') : 'Clean'),
            $changes ? 'uncommitted local edits' : 'matches HEAD', !$isGit ? '' : ($changes ? 'warn' : 'ok'))
        . ($last
            ? stat_tile('deploy', 'Last deployment', h(ucfirst((string) $last['status'])), ago((int) $last['started_at']) . ' by ' . h($last['actor']),
                $last['status'] === 'success' ? 'ok' : ($last['status'] === 'failed' ? 'err' : 'info'))
            : stat_tile('deploy', 'Last deployment', 'Never', 'no deployments yet'))
        . stat_tile('disk', 'Disk free', $freeGb !== null ? h($freeGb . ' GB') : '—', 'on the project volume', $freeGb !== null && $freeGb < 2 ? 'warn' : '')
        . '</div>';

    $qa = '';
    if ($canDeploy) {
        $qa .= '<a class="btn primary" href="?a=deploy">' . icon('deploy') . 'Deploy now' . icon('arrow', 'go') . '</a>'
            . '<form method="post" action="?a=deploy" data-once>' . csrf_field() . '<input type="hidden" name="op" value="check">'
            . '<button type="submit">' . icon('refresh') . 'Check for updates' . icon('arrow', 'go') . '</button></form>';
    }
    foreach (['artisan' => ['artisan', 'Run artisan command'], 'terminal' => ['terminal', 'Open terminal'], 'env' => ['env', 'Edit environment'], 'keys' => ['keys', 'Manage SSH keys']] as $a => [$perm, $label]) {
        if (can($perm)) {
            $qa .= '<a class="btn" href="?a=' . $a . '">' . icon($a) . h($label) . icon('arrow', 'go') . '</a>';
        }
    }

    $u = current_user();
    $proj = current_project();
    $others = '';
    $plist = accessible_projects($u);
    if (count($plist) > 1) {
        foreach ($plist as $p) {
            $last = project_last_deploy($p['id']);
            $pRoot = rtrim(project_setting($p['id'], 'project_root'), '/');
            $pDown = is_file("$pRoot/storage/framework/down");
            $tone = !$last ? '' : ($last['status'] === 'success' ? 'ok' : ($last['status'] === 'failed' ? 'err' : 'warn'));
            $on = $p['id'] === $proj['id'];
            $others .= '<li><span class="dot ' . ($pDown ? 'err' : $tone) . '"></span><span class="who"><b>' . h($p['name']) . '</b> <span class="mute small">'
                . h(project_setting($p['id'], 'branch')) . '</span>' . ($pDown ? ' ' . badge('maintenance', 'err') : '') . '</span>'
                . ($last ? deploy_status_badge($last['status']) . '<span class="when">' . ago((int) $last['started_at']) . '</span>' : '<span class="when">never deployed</span>')
                . ($on ? badge('current', 'ok') : project_switch_button($p['id'], 'dashboard', 'Open')) . '</li>';
        }
        $others = '<div class="card"><div class="card-h"><h2>' . icon('folder') . 'All projects <span class="badge">' . count($plist) . '</span></h2>'
            . (can('admin') ? '<a class="small" href="?a=projects">Manage</a>' : '') . '</div><ul class="deps">' . $others . '</ul></div>';
    }
    render('Dashboard', page_head(h($proj['name']), 'Welcome back, ' . $u['username'] . ' — here is how ' . $proj['name'] . ' is doing.')
        . $warnHtml . $stats . $others
        . '<div class="' . ($qa ? 'grid-main' : '') . '"><div class="stack"><div class="card"><h2>' . icon('dashboard') . 'Project</h2><div class="tbl"><table>' . $info . '</table></div></div>'
        . '<div class="card"><div class="card-h"><h2>' . icon('clock') . 'Recent deployments</h2>'
        . ($canDeploy && $deps ? '<a class="small" href="?a=deploy">View all</a>' : '') . '</div>'
        . ($dHtml ? '<ul class="deps">' . $dHtml . '</ul>' : empty_state('deploy', 'No deployments yet.')) . '</div></div>'
        . ($qa ? '<div class="card"><h2>' . icon('artisan') . 'Quick actions</h2><div class="qa">' . $qa . '</div></div>' : '')
        . '</div>');
}

function deploy_status_badge(string $s): string
{
    return badge($s, $s === 'success' ? 'ok' : ($s === 'failed' ? 'err' : ($s === 'running' ? 'run' : 'warn')));
}
