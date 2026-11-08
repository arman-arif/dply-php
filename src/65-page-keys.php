<?php

declare(strict_types=1);

// ============================================================================
//  SSH keys
// ============================================================================

function valid_key_name(string $n): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/', $n) && !str_ends_with($n, '.pub');
}

function list_keys(): array
{
    $keys = [];
    foreach (glob(ssh_key_dir() . '/*.pub') ?: [] as $pub) {
        $name = basename($pub, '.pub');
        if (!valid_key_name($name) || !is_file(ssh_key_dir() . "/$name")) {
            continue;
        }
        $fp = '';
        if ($kg = find_bin('ssh-keygen')) {
            $r = run_proc([$kg, '-lf', $pub], DPLY_DATA, proc_env(), 10);
            $fp = trim($r['stdout']);
        }
        $keys[$name] = ['pub' => trim((string) file_get_contents($pub)), 'fp' => $fp, 'mtime' => (int) filemtime($pub)];
    }
    ksort($keys);
    return $keys;
}

function page_keys(): void
{
    $active = setting('ssh_key');
    $html = '<h1>SSH keys</h1>';
    if (!find_bin('ssh-keygen')) {
        $html .= '<div class="flash err">ssh-keygen was not found in PATH — install openssh-client.</div>';
    }
    $html .= '<p class="mute">Keys live in <code>' . h(ssh_key_dir()) . '</code> (0600). Add a key\'s public part to your repository as a read-only '
        . '<em>deploy key</em>, select it as active and set Git auth to <code>ssh</code> in Settings.</p>';
    foreach (list_keys() as $name => $k) {
        $isActive = $name === $active;
        $html .= '<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">' . h($name) . ' ' . ($isActive ? badge('active', 'ok') : '') . '</h2>'
            . '<div class="row">'
            . ($isActive ? '' : '<form method="post" action="?a=keys">' . csrf_field() . '<input type="hidden" name="op" value="activate"><input type="hidden" name="name" value="' . h($name) . '"><button class="sm" type="submit">Use for git</button></form>')
            . '<form method="post" action="?a=keys" data-confirm="Delete key ' . h($name) . ' permanently?">' . csrf_field() . '<input type="hidden" name="op" value="delete"><input type="hidden" name="name" value="' . h($name) . '"><button class="sm danger" type="submit">Delete</button></form>'
            . '</div></div><p class="small mute">' . h($k['fp']) . ' · created ' . h(fmt_time($k['mtime'])) . '</p>'
            . '<textarea rows="3" readonly>' . h($k['pub']) . '</textarea></div>';
    }
    $kh = is_file(known_hosts()) ? (string) file_get_contents(known_hosts()) : '';
    $html .= '<div class="grid">'
        . '<form method="post" action="?a=keys" class="card">' . csrf_field() . '<h2>Generate new key (ed25519)</h2><input type="hidden" name="op" value="generate">'
        . '<label><span>Name</span><input type="text" name="name" value="deploy_' . date('Ymd') . '" pattern="[A-Za-z0-9_][A-Za-z0-9_.\-]{0,63}" required></label>'
        . '<label><span>Comment</span><input type="text" name="comment" value="dply@' . h((string) (gethostname() ?: 'server')) . '" maxlength="100"></label>'
        . '<button class="primary" type="submit">Generate</button></form>'
        . '<form method="post" action="?a=keys" class="card">' . csrf_field() . '<h2>Import existing private key</h2><input type="hidden" name="op" value="import">'
        . '<label><span>Name</span><input type="text" name="name" pattern="[A-Za-z0-9_][A-Za-z0-9_.\-]{0,63}" required></label>'
        . '<label><span>Private key (OpenSSH/PEM, without passphrase)</span><textarea name="private" rows="6" required spellcheck="false" autocomplete="off"></textarea></label>'
        . '<button type="submit">Import</button></form>'
        . '<div class="card"><h2>Known hosts &amp; connection test</h2>'
        . '<form method="post" action="?a=keys" class="row">' . csrf_field() . '<input type="hidden" name="op" value="scan">'
        . '<input type="text" name="host" placeholder="github.com or host:port" required style="flex:1;min-width:160px"><button type="submit">Add host key</button></form>'
        . '<form method="post" action="?a=keys" class="row" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="op" value="test">'
        . '<input type="text" name="target" placeholder="git@github.com" value="git@github.com" required style="flex:1;min-width:160px"><button type="submit">Test active key</button></form>'
        . '<p class="small mute">Unknown hosts are trusted on first use (accept-new) and recorded below.</p>'
        . '<pre class="small">' . h($kh !== '' ? $kh : '(empty)') . '</pre>'
        . ($kh !== '' ? '<form method="post" action="?a=keys" data-confirm="Clear all known host keys?" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="op" value="clear_hosts"><button class="sm danger" type="submit">Clear known hosts</button></form>' : '')
        . '</div></div>';
    render('SSH keys', $html);
}

function do_keys(): void
{
    $op = (string) ($_POST['op'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $dir = ssh_key_dir();
    $kg = find_bin('ssh-keygen');
    switch ($op) {
        case 'generate':
            if (!valid_key_name($name) || is_file("$dir/$name") || !$kg) {
                flash('err', $kg ? 'Invalid or existing key name.' : 'ssh-keygen not found.');
                break;
            }
            $comment = substr((string) preg_replace('/[^A-Za-z0-9@._ -]/', '', (string) ($_POST['comment'] ?? 'dply')), 0, 100);
            $r = run_proc([$kg, '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', "$dir/$name"], $dir, proc_env(), 30);
            if ($r['code'] === 0) {
                @chmod("$dir/$name", 0600);
                if (setting('ssh_key') === '') {
                    set_setting('ssh_key', $name);
                }
                audit('keys.generate', $name);
                flash('ok', "Key '$name' generated. Copy its public key to your repository's deploy keys.");
            } else {
                flash('err', 'ssh-keygen failed.', $r['out']);
            }
            break;
        case 'import':
            $priv = str_replace(["\r\n", "\r"], "\n", trim((string) ($_POST['private'] ?? ''))) . "\n";
            if (!valid_key_name($name) || is_file("$dir/$name") || !$kg || !str_contains($priv, 'PRIVATE KEY')) {
                flash('err', 'Invalid name, existing key, missing ssh-keygen, or not a private key.');
                break;
            }
            file_put_contents("$dir/$name", $priv, LOCK_EX);
            @chmod("$dir/$name", 0600);
            $r = run_proc([$kg, '-y', '-P', '', '-f', "$dir/$name"], $dir, proc_env(), 10);
            if ($r['code'] !== 0 || !str_starts_with(trim($r['stdout']), 'ssh-')) {
                @unlink("$dir/$name");
                flash('err', 'Key rejected (passphrase-protected or invalid).', $r['out']);
                break;
            }
            file_put_contents("$dir/$name.pub", trim($r['stdout']) . "\n");
            audit('keys.import', $name);
            flash('ok', "Key '$name' imported.");
            break;
        case 'delete':
            if (valid_key_name($name) && is_file("$dir/$name")) {
                @unlink("$dir/$name");
                @unlink("$dir/$name.pub");
                if (setting('ssh_key') === $name) {
                    set_setting('ssh_key', '');
                }
                audit('keys.delete', $name);
                flash('ok', "Key '$name' deleted.");
            }
            break;
        case 'activate':
            if (valid_key_name($name) && is_file("$dir/$name")) {
                set_setting('ssh_key', $name);
                audit('keys.activate', $name);
                flash('ok', "Key '$name' is now used for git over SSH." . (setting('git_auth') !== 'ssh' ? ' Note: Git auth is not set to "ssh" in Settings.' : ''));
            }
            break;
        case 'scan':
            $host = trim((string) ($_POST['host'] ?? ''));
            $port = null;
            if (preg_match('/^([A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', $host, $m)) {
                $host = $m[1];
                $port = $m[2] ?? null;
            } else {
                flash('err', 'Invalid host.');
                break;
            }
            $ks = find_bin('ssh-keyscan');
            if (!$ks) {
                flash('err', 'ssh-keyscan not found.');
                break;
            }
            $cmd = [$ks, '-T', '10', '-t', 'ed25519,ecdsa,rsa'];
            if ($port) {
                array_push($cmd, '-p', $port);
            }
            $cmd[] = $host;
            $r = run_proc($cmd, DPLY_DATA, proc_env(), 30);
            if (trim($r['stdout']) === '') {
                flash('err', "No host keys received from $host.", $r['out']);
                break;
            }
            file_put_contents(known_hosts(), trim($r['stdout']) . "\n", FILE_APPEND | LOCK_EX);
            @chmod(known_hosts(), 0600);
            $fps = '';
            if ($kg) {
                $tmp = DPLY_DATA . '/keys/.scan-' . bin2hex(random_bytes(4));
                file_put_contents($tmp, $r['stdout']);
                $fps = run_proc([$kg, '-lf', $tmp], DPLY_DATA, proc_env(), 10)['stdout'];
                @unlink($tmp);
            }
            audit('keys.known_host', $host);
            flash('ok', "Host keys for $host added. Verify these fingerprints against your provider's published ones:", $fps);
            break;
        case 'clear_hosts':
            @unlink(known_hosts());
            audit('keys.known_hosts_clear', '');
            flash('ok', 'Known hosts cleared.');
            break;
        case 'test':
            $target = trim((string) ($_POST['target'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/', $target)) {
                flash('err', 'Target must look like user@host.');
                break;
            }
            $key = active_key_path();
            if (!$key) {
                flash('err', 'No active key selected.');
                break;
            }
            $ssh = find_bin('ssh') ?? 'ssh';
            $r = run_proc([$ssh, '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . known_hosts(),
                '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=10', '-i', $key, $target], DPLY_DATA, proc_env(), 30);
            $okish = preg_match('/successfully authenticated|welcome to gitlab|authenticated via|logged in as/i', $r['out']);
            audit('keys.test', "$target exit {$r['code']}");
            flash($okish || $r['code'] === 0 ? 'ok' : 'warn', "SSH test to $target finished (exit {$r['code']}).", $r['out']);
            break;
    }
    redirect('?a=keys');
}
