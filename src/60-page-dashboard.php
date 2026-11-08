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
    if (path_within(DPLY_DATA, DPLY_DIR) && stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'apache') === false) {
        $warn[] = 'Not running on Apache: make sure your web server denies requests to ' . DPLY_DATA . ' (see header of index.php).';
    }
    $warnHtml = '';
    foreach ($warn as $w) {
        $warnHtml .= '<div class="flash warn">' . h($w) . '</div>';
    }

    $rows = [
        'Project root'    => '<code>' . h($root) . '</code>' . (is_dir($root) ? '' : ' ' . badge('missing', 'err')),
        'Laravel'         => h(laravel_version()),
        'PHP (CLI used)'  => '<code>' . h(php_bin()) . '</code>',
        'Runs as'         => h(proc_user()),
        'Git branch'      => $branch ? h($branch) . ($branch !== setting('branch') ? ' ' . badge('deploy branch: ' . setting('branch'), 'warn') : '') : badge('not a git repo', 'warn'),
        'HEAD'            => $head ? h($head) : '—',
        'Working tree'    => $isGit ? ($changes ? badge("$changes local change(s)", 'warn') : badge('clean', 'ok')) : '—',
        'Maintenance'     => $down ? badge('DOWN', 'err') : badge('up', 'ok'),
        'Git auth'        => h(setting('git_auth')),
        'Disk free'       => is_dir($root) ? h(round((float) @disk_free_space($root) / 1073741824, 1) . ' GB') : '—',
    ];
    $info = '';
    foreach ($rows as $k => $v) {
        $info .= '<tr><td class="k">' . h($k) . '</td><td>' . $v . '</td></tr>';
    }
    $deps = db()->query('SELECT * FROM deployments ORDER BY id DESC LIMIT 6')->fetchAll();
    $dHtml = '';
    foreach ($deps as $d) {
        $dHtml .= '<tr><td>#' . (int) $d['id'] . '</td><td>' . deploy_status_badge($d['status']) . '</td><td>' . h($d['actor'])
            . '</td><td>' . h(fmt_time((int) $d['started_at'])) . '</td><td>' . (can('deploy') ? '<a href="?a=deploy_log&id=' . (int) $d['id'] . '">log</a>' : '') . '</td></tr>';
    }
    render('Dashboard', '<h1>Dashboard</h1>' . $warnHtml . '<div class="grid"><div class="card"><h2>Project</h2><div class="tbl"><table>' . $info
        . '</table></div></div><div class="card"><h2>Recent deployments</h2>'
        . ($dHtml ? '<div class="tbl"><table><tr><th>#</th><th>Status</th><th>By</th><th>Started</th><th></th></tr>' . $dHtml . '</table></div>' : '<p class="mute">None yet.</p>')
        . (can('deploy') ? '<p><a class="btn" href="?a=deploy">Go to deploy →</a></p>' : '') . '</div></div>');
}

function deploy_status_badge(string $s): string
{
    return badge($s, $s === 'success' ? 'ok' : ($s === 'failed' ? 'err' : 'warn'));
}
