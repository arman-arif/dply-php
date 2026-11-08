<?php

declare(strict_types=1);

// ============================================================================
//  .env editor
// ============================================================================

function dotenv_path(): string
{
    return project_root() . '/.env';
}

function env_validate(string $content): array
{
    $errs = [];
    $seen = [];
    $inQuote = false;
    $startLine = 0;
    foreach (explode("\n", $content) as $i => $line) {
        $n = $i + 1;
        if ($inQuote) {
            if (preg_match('/(?<!\\\\)"\s*(#.*)?$/', $line)) {
                $inQuote = false;
            }
            continue;
        }
        $t = trim($line);
        if ($t === '' || $t[0] === '#') {
            continue;
        }
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $t, $m)) {
            $errs[] = "Line $n: expected KEY=VALUE";
            continue;
        }
        if (isset($seen[$m[1]])) {
            $errs[] = "Line $n: {$m[1]} is already defined on line {$seen[$m[1]]}";
        }
        $seen[$m[1]] = $n;
        $v = $m[2];
        if ($v !== '' && $v[0] === '"') {
            if (!preg_match('/(?<!\\\\)"/', substr($v, 1))) {
                $inQuote = true;
                $startLine = $n;
            }
        } elseif ($v !== '' && $v[0] === "'") {
            if (!str_contains(substr($v, 1), "'")) {
                $errs[] = "Line $n: unterminated single quote";
            }
        } elseif (preg_match('/\s/', (string) preg_replace('/\s+#.*$/', '', $v))) {
            $errs[] = "Line $n: unquoted value contains whitespace — wrap it in double quotes";
        }
    }
    if ($inQuote) {
        $errs[] = "Line $startLine: unterminated double quote";
    }
    return $errs;
}

function env_parse_simple(string $content): array
{
    $out = [];
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/', $line, $m)) {
            $out[$m[1]] = trim($m[2]);
        }
    }
    return $out;
}

function env_backups(): array
{
    $files = glob(env_backup_dir() . '/env-*') ?: [];
    rsort($files);
    return $files;
}

function page_env(): void
{
    $f = dotenv_path();
    $exists = is_file($f);
    $content = $exists ? (string) file_get_contents($f) : '';
    $hash = $exists ? sha1($content) : 'none';
    $note = '';
    if (isset($_GET['load'])) {
        $b = basename((string) $_GET['load']);
        $bf = env_backup_dir() . '/' . $b;
        if (preg_match('/^env-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $b) && is_file($bf)) {
            $content = (string) file_get_contents($bf);
            $note = '<div class="flash warn">Backup <code>' . h($b) . '</code> loaded into the editor — review it and press Save to restore it.</div>';
        }
    } elseif (!$exists && is_file(project_root() . '/.env.example')) {
        $content = (string) file_get_contents(project_root() . '/.env.example');
        $note = '<div class="flash warn">No .env exists yet — the editor is pre-filled from .env.example. Saving creates .env.</div>';
    }
    $cached = is_file(project_root() . '/bootstrap/cache/config.php');
    $bk = '';
    foreach (array_slice(env_backups(), 0, 20) as $b) {
        $bn = basename($b);
        $bk .= '<tr><td><code>' . h($bn) . '</code></td><td style="white-space:nowrap">' . ago((int) filemtime($b)) . '</td><td><div class="row end"><a class="btn sm" href="?a=env&load=' . h(rawurlencode($bn)) . '">' . icon('download') . 'Load</a></div></td></tr>';
    }
    audit('env.view', 'Opened .env editor');
    $lineCount = $content === '' ? 0 : substr_count($content, "\n") + 1;
    render('.env', page_head('Environment', 'Edit ' . $f . ' — a timestamped backup is taken before every save.') . $note
        . ($cached ? '<div class="flash warn">Config is cached — changes take effect only after config:cache / config:clear (choose below).</div>' : '')
        . '<div class="grid-main"><form method="post" action="?a=env" class="card">' . csrf_field()
        . '<input type="hidden" name="op" value="save"><input type="hidden" name="hash" value="' . h($hash) . '">'
        . '<div class="card-h"><h2>' . icon('env') . '.env</h2><span class="small mute">' . $lineCount . ' lines</span></div>'
        . '<textarea name="content" rows="28" spellcheck="false" autocomplete="off" aria-label=".env contents">' . h($content) . '</textarea>'
        . '<div class="actions"><label class="chk" style="margin:0"><input type="checkbox" name="force" value="1"><span>Save even if validation reports problems</span></label>'
        . '<div class="row" style="margin-left:auto"><select name="after" style="width:auto" aria-label="After saving"><option value="config_cache"' . ($cached ? ' selected' : '') . '>Then run config:cache</option>'
        . '<option value="config_clear">Then run config:clear</option><option value="none"' . ($cached ? '' : ' selected') . '>Do nothing else</option></select>'
        . '<button class="primary" type="submit" data-confirm="Write these changes to .env?">' . icon('check') . 'Save .env</button></div></div></form>'
        . '<div class="card"><h2>' . icon('clock') . 'Backups</h2>' . ($bk ? '<div class="tbl"><table>' . $bk . '</table></div>' : empty_state('clock', 'No backups yet.')) . '</div></div>');
}

function do_env(): void
{
    $f = dotenv_path();
    $content = str_replace(["\r\n", "\r"], "\n", (string) ($_POST['content'] ?? ''));
    if (strlen($content) > 512000) {
        flash('err', '.env content is too large.');
        redirect('?a=env');
    }
    if ($content !== '' && !str_ends_with($content, "\n")) {
        $content .= "\n";
    }
    $old = is_file($f) ? (string) file_get_contents($f) : null;
    $oldHash = $old === null ? 'none' : sha1($old);
    if (!hash_equals($oldHash, (string) ($_POST['hash'] ?? ''))) {
        flash('err', '.env was changed by someone else since you opened it. Your edit was NOT saved — reload and re-apply.');
        redirect('?a=env');
    }
    $errs = env_validate($content);
    if ($errs && empty($_POST['force'])) {
        flash('err', 'Not saved — validation problems (tick "Save even if…" to override):', implode("\n", array_slice($errs, 0, 30)));
        redirect('?a=env');
    }
    if ($old === $content) {
        flash('ok', 'No changes.');
        redirect('?a=env');
    }
    if ($old !== null) {
        $bf = env_backup_dir() . '/env-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($bf, $old, LOCK_EX);
        @chmod($bf, 0600);
        foreach (array_slice(env_backups(), 50) as $extra) {
            @unlink($extra);
        }
    }
    $perms = $old !== null ? (fileperms($f) & 0777) : 0640;
    $tmp = dirname($f) . '/.env.dply-' . bin2hex(random_bytes(4));
    $written = file_put_contents($tmp, $content, LOCK_EX) !== false;
    if ($written) {
        @chmod($tmp, $perms);
        $written = @rename($tmp, $f);
    }
    if (!$written) {
        @unlink($tmp);
        flash('err', 'Could not write .env (permissions?).');
        redirect('?a=env');
    }
    $a = env_parse_simple($old ?? '');
    $b = env_parse_simple($content);
    $changed = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
            $changed[] = $k . (!isset($a[$k]) ? ' (added)' : (!isset($b[$k]) ? ' (removed)' : ''));
        }
    }
    audit('env.save', 'Changed keys: ' . (implode(', ', $changed) ?: '(formatting only)'));
    $after = (string) ($_POST['after'] ?? 'none');
    $pre = null;
    if (in_array($after, ['config_cache', 'config_clear'], true)) {
        $r = run_proc(artisan_cmd([$after === 'config_cache' ? 'config:cache' : 'config:clear']), project_root(), proc_env(), 120);
        $pre = $r['out'] . "\n[exit {$r['code']}]";
    }
    flash('ok', '.env saved. Changed: ' . (implode(', ', $changed) ?: 'formatting only') . ($errs ? ' (saved despite validation warnings)' : ''), $pre);
    redirect('?a=env');
}
