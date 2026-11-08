<?php

declare(strict_types=1);

// ============================================================================
//  Artisan
// ============================================================================

function artisan_catalog(): array
{
    // key => [label, args, confirm message|null]
    return [
        'Deployment' => [
            'migrate'          => ['migrate --force', ['migrate', '--force'], 'Run pending migrations on this environment?'],
            'migrate_status'   => ['migrate:status', ['migrate:status'], null],
            'migrate_rollback' => ['migrate:rollback --force', ['migrate:rollback', '--force'], 'Roll back the LAST migration batch? This may drop columns/tables created by it.'],
            'db_seed'          => ['db:seed --force', ['db:seed', '--force'], 'Run the database seeders on this environment?'],
            'storage_link'     => ['storage:link', ['storage:link'], null],
            'queue_restart'    => ['queue:restart', ['queue:restart'], null],
            'down'             => ['down (maintenance on)', null, 'Put the application into maintenance mode?'],
            'up'               => ['up (maintenance off)', ['up'], null],
            'key_generate'     => ['key:generate --force', ['key:generate', '--force'], 'Generate a NEW APP_KEY? Existing sessions, encrypted cookies and encrypted data become unreadable.'],
        ],
        'Optimise & caches' => [
            'optimize'       => ['optimize', ['optimize'], null],
            'optimize_clear' => ['optimize:clear', ['optimize:clear'], null],
            'config_cache'   => ['config:cache', ['config:cache'], null],
            'config_clear'   => ['config:clear', ['config:clear'], null],
            'route_cache'    => ['route:cache', ['route:cache'], null],
            'route_clear'    => ['route:clear', ['route:clear'], null],
            'view_cache'     => ['view:cache', ['view:cache'], null],
            'view_clear'     => ['view:clear', ['view:clear'], null],
            'event_cache'    => ['event:cache', ['event:cache'], null],
            'event_clear'    => ['event:clear', ['event:clear'], null],
            'cache_clear'    => ['cache:clear', ['cache:clear'], 'Flush the application cache store?'],
            'clear_compiled' => ['clear-compiled', ['clear-compiled'], null],
            'package_discover' => ['package:discover', ['package:discover'], null],
        ],
        'Queue' => [
            'queue_failed'    => ['queue:failed', ['queue:failed'], null],
            'queue_retry_all' => ['queue:retry all', ['queue:retry', 'all'], 'Retry ALL failed jobs?'],
            'queue_flush'     => ['queue:flush', ['queue:flush'], 'Delete ALL failed jobs?'],
        ],
        'Information' => [
            'about'         => ['about', ['about'], null],
            'version'       => ['--version', ['--version'], null],
            'env'           => ['env', ['env'], null],
            'route_list'    => ['route:list', ['route:list'], null],
            'schedule_list' => ['schedule:list', ['schedule:list'], null],
            'list'          => ['list (all commands)', ['list'], null],
        ],
    ];
}

function page_artisan(): void
{
    $html = '<h1>Artisan</h1><div class="stack">';
    foreach (artisan_catalog() as $group => $cmds) {
        $html .= '<div class="card"><h2>' . h($group) . '</h2><div class="agrid">';
        foreach ($cmds as $k => [$label, , $confirm]) {
            $html .= '<form method="post" action="?a=artisan" data-once' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field()
                . '<input type="hidden" name="op" value="run"><input type="hidden" name="cmd" value="' . h($k) . '">'
                . '<button type="submit"' . ($confirm ? ' class="danger"' : '') . '>' . h($label) . '</button></form>';
        }
        $html .= '</div></div>';
    }
    $html .= '<div class="card"><h2>Custom command</h2><form method="post" action="?a=artisan" data-once>' . csrf_field()
        . '<input type="hidden" name="op" value="custom"><div class="row"><code>php artisan</code>'
        . '<input type="text" name="args" placeholder="e.g. cache:forget some-key" required maxlength="500" style="flex:1;min-width:200px">'
        . '<button class="primary" type="submit">Run</button></div>'
        . '<p class="small mute">Runs without a shell (arguments are passed literally). Blocked: ' . h(implode(', ', DPLY_ARTISAN_DENY)) . '.</p></form></div></div>';
    render('Artisan', $html);
}

function do_artisan(): void
{
    $op = (string) ($_POST['op'] ?? '');
    $args = null;
    if ($op === 'run') {
        $key = (string) ($_POST['cmd'] ?? '');
        foreach (artisan_catalog() as $cmds) {
            if (isset($cmds[$key])) {
                $args = $key === 'down' ? down_args() : $cmds[$key][1];
            }
        }
    } elseif ($op === 'custom') {
        $args = parse_args(trim((string) ($_POST['args'] ?? '')));
        if (!$args) {
            flash('err', 'Could not parse the arguments (unbalanced quotes?).');
            redirect('?a=artisan');
        }
        $name = strtolower($args[0]);
        if (!preg_match('/^[a-z0-9][a-z0-9:_-]*$/', $name) && !in_array($name, ['--version', '-v'], true)) {
            flash('err', 'The first argument must be an artisan command name.');
            redirect('?a=artisan');
        }
        if (in_array($name, DPLY_ARTISAN_DENY, true)) {
            audit('artisan.denied', implode(' ', $args));
            flash('err', "artisan $name is not allowed from this console.");
            redirect('?a=artisan');
        }
    }
    if (!$args) {
        redirect('?a=artisan');
    }
    $cmd = artisan_cmd($args);
    audit('artisan.run', cmd_display($args));
    stream_begin('php artisan ' . cmd_display($args));
    stream_out('$ ' . cmd_display($cmd) . "\n\n");
    $r = run_proc($cmd, project_root(), proc_env(), cmd_timeout(), 'stream_out');
    stream_out(($r['timeout'] ? "\n[timed out]" : '') . "\n[exit {$r['code']}]\n");
    stream_end('<p class="row" style="margin-top:12px"><a class="btn" href="?a=artisan">← Back to artisan</a></p>');
}
