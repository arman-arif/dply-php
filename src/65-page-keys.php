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
    $pname = current_project()['name'];
    $usedBy = [];
    foreach (projects_all() as $p) {
        if (($k = project_setting($p['id'], 'ssh_key')) !== '') {
            $usedBy[$k][] = $p['name'];
        }
    }
    $html = page_head('SSH keys', 'Keys are shared by all projects; each project picks the one it uses. Add a key\'s public part to the repository as a read-only deploy key.');
    if (!find_bin('ssh-keygen')) {
        $html .= '<div class="flash err">ssh-keygen was not found in PATH — install openssh-client.</div>';
    }
    $keysHtml = '';
    foreach (list_keys() as $name => $k) {
        $isActive = $name === $active;
        $others = array_values(array_diff($usedBy[$name] ?? [], [$pname]));
        $keysHtml .= '<div class="card"><div class="ucard-h"><div class="key-ic' . ($isActive ? ' on' : '') . '">' . icon('keys') . '</div><div class="n"><b>' . h($name) . ' ' . ($isActive ? badge('used by ' . $pname, 'ok') : '') . '</b>'
            . '<small><code>' . h($k['fp']) . '</code> · created ' . ago($k['mtime']) . ($others ? ' · also used by ' . h(implode(', ', $others)) : '') . '</small></div><div class="row">'
            . ($isActive ? '' : '<form method="post" action="?a=keys">' . csrf_field() . '<input type="hidden" name="op" value="activate"><input type="hidden" name="name" value="' . h($name) . '"><button class="sm" type="submit">' . icon('check') . 'Use for ' . h($pname) . '</button></form>')
            . '<form method="post" action="?a=keys" data-confirm="Delete key ' . h($name) . ' permanently?' . (!empty($usedBy[$name]) ? ' It is used by: ' . h(implode(', ', $usedBy[$name])) . '.' : '') . '">' . csrf_field() . '<input type="hidden" name="op" value="delete"><input type="hidden" name="name" value="' . h($name) . '"><button class="sm danger" type="submit">Delete</button></form>'
            . '</div></div><label style="margin:0"><span>Public key — paste this into your repository\'s deploy keys</span><textarea rows="3" readonly>' . h($k['pub']) . '</textarea></label></div>';
    }
    $html .= $keysHtml !== '' ? $keysHtml : '<div class="card">' . empty_state('keys', 'No SSH keys yet. Generate one below or import an existing key.') . '</div>';
    $kh = is_file(known_hosts()) ? (string) file_get_contents(known_hosts()) : '';
    $html .= '<div class="grid">'
        . '<form method="post" action="?a=keys" class="card">' . csrf_field() . '<h2>' . icon('plus') . 'Generate new key <span class="badge">ed25519</span></h2><input type="hidden" name="op" value="generate">'
        . '<label><span>Name</span><input type="text" name="name" value="deploy_' . date('Ymd') . '" pattern="[A-Za-z0-9_][A-Za-z0-9_.\-]{0,63}" required></label>'
        . '<label><span>Comment</span><input type="text" name="comment" value="dply@' . h((string) (gethostname() ?: 'server')) . '" maxlength="100"></label>'
        . '<button class="primary" type="submit">' . icon('plus') . 'Generate key</button></form>'
        . '<form method="post" action="?a=keys" class="card">' . csrf_field() . '<h2>' . icon('download') . 'Import existing private key</h2><input type="hidden" name="op" value="import">'
        . '<label><span>Name</span><input type="text" name="name" pattern="[A-Za-z0-9_][A-Za-z0-9_.\-]{0,63}" required></label>'
        . '<label><span>Private key (OpenSSH/PEM, without passphrase)</span><textarea name="private" rows="5" required spellcheck="false" autocomplete="off" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea></label>'
        . '<button type="submit">' . icon('download') . 'Import key</button></form></div>'
        . '<div class="card"><div class="card-h"><h2>' . icon('security') . 'Known hosts &amp; connection test</h2>'
        . ($kh !== '' ? '<form method="post" action="?a=keys" data-confirm="Clear all known host keys?">' . csrf_field() . '<input type="hidden" name="op" value="clear_hosts"><button class="sm danger" type="submit">Clear known hosts</button></form>' : '')
        . '</div><div class="grid" style="margin-bottom:14px">'
        . '<form method="post" action="?a=keys">' . csrf_field() . '<input type="hidden" name="op" value="scan"><label><span>Trust a host</span><div class="row">'
        . '<input type="text" name="host" placeholder="github.com or host:port" required style="flex:1;min-width:160px"><button type="submit">' . icon('plus') . 'Add host key</button></div></label></form>'
        . '<form method="post" action="?a=keys">' . csrf_field() . '<input type="hidden" name="op" value="test"><label><span>Test the active key</span><div class="row">'
        . '<input type="text" name="target" placeholder="git@github.com" value="git@github.com" required style="flex:1;min-width:160px"><button type="submit">' . icon('sync') . 'Test connection</button></div></label></form></div>'
        . '<p class="hint" style="margin:0 0 10px">Unknown hosts are trusted on first use (accept-new) and recorded below.</p>'
        . '<pre class="small">' . h($kh !== '' ? $kh : '(empty)') . '</pre></div>';
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
                foreach (projects_all() as $p) {
                    if (project_setting($p['id'], 'ssh_key') === $name) {
                        set_project_setting($p['id'], 'ssh_key', '');
                    }
                }
                audit('keys.delete', $name);
                flash('ok', "Key '$name' deleted.");
            }
            break;
        case 'activate':
            if (valid_key_name($name) && is_file("$dir/$name")) {
                set_setting('ssh_key', $name);
                audit('keys.activate', current_project()['slug'] . ": $name");
                flash('ok', "Key '$name' is now used by " . current_project()['name'] . ' for git over SSH.' . (setting('git_auth') !== 'ssh' ? ' Note: Git auth is not set to "ssh" in Settings.' : ''));
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
