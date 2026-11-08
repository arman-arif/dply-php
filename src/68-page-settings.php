<?php

declare(strict_types=1);

// ============================================================================
//  Settings (admin)
// ============================================================================

function page_settings(): void
{
    $s = fn(string $k) => h(setting($k));
    $sel = fn(string $k, string $v) => setting($k) === $v ? ' selected' : '';
    $hasTok = setting('git_token') !== '';
    $keys = array_keys(list_keys());
    $keyOpts = '<option value="">— none —</option>';
    foreach ($keys as $k) {
        $keyOpts .= '<option value="' . h($k) . '"' . (setting('ssh_key') === $k ? ' selected' : '') . '>' . h($k) . '</option>';
    }
    render('Settings', '<h1>Settings</h1><form method="post" action="?a=settings">' . csrf_field() . '<div class="grid">'
        . '<div class="card"><h2>Repository</h2>'
        . '<label><span>Project root (the Laravel app to deploy)</span><input type="text" name="project_root" value="' . $s('project_root') . '" required></label>'
        . '<label><span>Repository URL — https://… for token auth, git@host:org/repo.git for SSH. Empty = keep existing origin.</span>'
        . '<input type="text" name="repo_url" value="' . $s('repo_url') . '" placeholder="https://github.com/org/repo.git"></label>'
        . '<label><span>Branch to deploy</span><input type="text" name="branch" value="' . $s('branch') . '" required></label>'
        . '<label><span>Authentication</span><select name="git_auth"><option value="none"' . $sel('git_auth', 'none') . '>None (public repo)</option>'
        . '<option value="token"' . $sel('git_auth', 'token') . '>HTTPS access token</option><option value="ssh"' . $sel('git_auth', 'ssh') . '>SSH key</option></select></label>'
        . '<label><span>Token username — GitHub: x-access-token · GitLab: oauth2 · Bitbucket: x-token-auth</span><input type="text" name="git_token_user" value="' . $s('git_token_user') . '"></label>'
        . '<label><span>Access token ' . ($hasTok ? badge('stored (encrypted)', 'ok') : '') . ' — leave blank to keep</span><input type="password" name="git_token" autocomplete="off" placeholder="' . ($hasTok ? '••••••••' : 'ghp_… / glpat-…') . '"></label>'
        . ($hasTok ? '<label class="chk"><input type="checkbox" name="clear_token" value="1"><span>Remove stored token</span></label>' : '')
        . '<label><span>SSH key (manage under SSH keys)</span><select name="ssh_key">' . $keyOpts . '</select></label>'
        . '</div>'
        . '<div class="card"><h2>Sync behaviour</h2>'
        . '<label><span>Strategy</span><select name="sync_strategy"><option value="reset"' . $sel('sync_strategy', 'reset') . '>Force-sync: make tracked files identical to origin (recommended)</option>'
        . '<option value="ff"' . $sel('sync_strategy', 'ff') . '>Fast-forward only: abort if the server has diverged / local edits conflict</option></select></label>'
        . '<label class="chk"><input type="checkbox" name="git_clean" value="1"' . (setting('git_clean') === '1' ? ' checked' : '') . '><span>Also delete untracked, non-ignored files (git clean -fd). .env, ignored files and DPLY data are kept.</span></label>'
        . '<label class="chk"><input type="checkbox" name="submodules" value="1"' . (setting('submodules') === '1' ? ' checked' : '') . '><span>Update git submodules</span></label>'
        . '<h2 style="margin-top:16px">Default post-deploy steps</h2>' . steps_checkboxes(default_steps())
        . '<label style="margin-top:10px"><span>Custom post-deploy commands — one shell command per line, run in the project root (e.g. npm ci &amp;&amp; npm run build)</span>'
        . '<textarea name="custom_commands" rows="4">' . $s('custom_commands') . '</textarea></label>'
        . '<label><span>Maintenance bypass secret (artisan down --secret) — optional</span><input type="text" name="maint_secret" value="' . $s('maint_secret') . '" pattern="[A-Za-z0-9_-]*"></label>'
        . '</div>'
        . '<div class="card"><h2>Binaries &amp; limits</h2>'
        . '<label><span>PHP CLI binary — empty = auto (' . h(php_bin()) . ')</span><input type="text" name="php_bin" value="' . $s('php_bin') . '"></label>'
        . '<label><span>Composer — command name or path to composer.phar</span><input type="text" name="composer_bin" value="' . $s('composer_bin') . '"></label>'
        . '<label><span>Git binary</span><input type="text" name="git_bin" value="' . $s('git_bin') . '"></label>'
        . '<label><span>Per-command timeout (seconds)</span><input type="number" name="cmd_timeout" min="30" value="' . $s('cmd_timeout') . '"></label>'
        . '<label><span>Terminal allow-list for restricted users — one command prefix per line. Note: prefixes such as <code>php artisan</code> can execute application code.</span>'
        . '<textarea name="term_allow" rows="10">' . $s('term_allow') . '</textarea></label></div>'
        . '</div><p class="row"><button class="primary" type="submit" name="op" value="save">Save settings</button>'
        . '<button type="submit" name="op" value="test_git">Save &amp; test repository access</button></p></form>');
}

function do_settings(): void
{
    $op = (string) ($_POST['op'] ?? 'save');
    $errs = [];
    $root = rtrim(trim((string) ($_POST['project_root'] ?? '')), '/');
    $real = $root !== '' ? realpath($root) : false;
    if ($real === false || !is_dir($real)) {
        $errs[] = 'Project root does not exist.';
    }
    $url = trim((string) ($_POST['repo_url'] ?? ''));
    $auth = in_array($_POST['git_auth'] ?? '', ['none', 'token', 'ssh'], true) ? (string) $_POST['git_auth'] : 'none';
    if ($url !== '') {
        $parts = parse_url($url);
        if ($url[0] === '-' || preg_match('/\s/', $url)) {
            $errs[] = 'Invalid repository URL.';
        } elseif (preg_match('#^https?://#i', $url) && (isset($parts['user']) || isset($parts['pass']))) {
            $errs[] = 'Do not embed credentials in the URL — use the token field instead.';
        } elseif ($auth === 'token' && !preg_match('#^https://#i', $url)) {
            $errs[] = 'Token authentication requires an https:// URL.';
        } elseif ($auth === 'ssh' && preg_match('#^https?://#i', $url)) {
            $errs[] = 'SSH authentication requires an SSH URL (git@host:org/repo.git or ssh://…).';
        }
    }
    $branch = trim((string) ($_POST['branch'] ?? ''));
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#', $branch) || str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
        $errs[] = 'Invalid branch name.';
    }
    $tokUser = trim((string) ($_POST['git_token_user'] ?? '')) ?: 'x-access-token';
    if (str_contains($tokUser, ':')) {
        $errs[] = 'Token username must not contain ":".';
    }
    foreach (['php_bin', 'composer_bin', 'git_bin'] as $b) {
        $v = trim((string) ($_POST[$b] ?? ''));
        if ($v !== '' && ($v[0] === '-' || preg_match('/\s/', $v))) {
            $errs[] = "Invalid value for $b.";
        }
    }
    $ssh = (string) ($_POST['ssh_key'] ?? '');
    if ($ssh !== '' && (!valid_key_name($ssh) || !is_file(ssh_key_dir() . "/$ssh"))) {
        $errs[] = 'Selected SSH key does not exist.';
    }
    $secret = trim((string) ($_POST['maint_secret'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]*$/', $secret)) {
        $errs[] = 'Maintenance secret may only contain letters, digits, _ and -.';
    }
    if ($errs) {
        flash('err', implode(' ', $errs));
        redirect('?a=settings');
    }

    set_setting('project_root', (string) $real);
    set_setting('repo_url', $url);
    set_setting('branch', $branch);
    set_setting('git_auth', $auth);
    set_setting('git_token_user', $tokUser);
    $tok = trim((string) ($_POST['git_token'] ?? ''));
    if (!empty($_POST['clear_token'])) {
        set_setting('git_token', '');
    } elseif ($tok !== '') {
        set_setting('git_token', enc($tok));
    }
    set_setting('ssh_key', $ssh);
    set_setting('sync_strategy', ($_POST['sync_strategy'] ?? '') === 'ff' ? 'ff' : 'reset');
    set_setting('git_clean', !empty($_POST['git_clean']) ? '1' : '0');
    set_setting('submodules', !empty($_POST['submodules']) ? '1' : '0');
    set_setting('post_steps', implode(',', selected_steps((array) ($_POST['steps'] ?? []))));
    set_setting('custom_commands', str_replace("\r\n", "\n", trim((string) ($_POST['custom_commands'] ?? ''))));
    set_setting('maint_secret', $secret);
    set_setting('php_bin', trim((string) ($_POST['php_bin'] ?? '')));
    set_setting('composer_bin', trim((string) ($_POST['composer_bin'] ?? '')) ?: 'composer');
    set_setting('git_bin', trim((string) ($_POST['git_bin'] ?? '')) ?: 'git');
    set_setting('cmd_timeout', (string) max(30, (int) ($_POST['cmd_timeout'] ?? 900)));
    set_setting('term_allow', str_replace("\r\n", "\n", trim((string) ($_POST['term_allow'] ?? ''))));
    audit('settings.save', "repo=$url branch=$branch auth=$auth" . ($tok !== '' ? ' (token updated)' : '') . (!empty($_POST['clear_token']) ? ' (token removed)' : ''));

    if ($op === 'test_git') {
        $target = $url !== '' ? $url : (string) git_out(['remote', 'get-url', 'origin']);
        if ($target === '') {
            flash('err', 'No repository URL configured.');
            redirect('?a=settings');
        }
        $r = run_proc([git_bin(), 'ls-remote', '--heads', $target, $branch], project_root(), proc_env(true), 60);
        $found = trim($r['stdout']) !== '';
        audit('settings.test_git', "exit {$r['code']}");
        flash($r['code'] === 0 && $found ? 'ok' : 'err', $r['code'] === 0
            ? ($found ? "Settings saved. Repository reachable and branch '$branch' exists." : "Settings saved. Repository reachable, but branch '$branch' was not found.")
            : 'Settings saved, but the repository could not be reached:', $r['out']);
    } else {
        flash('ok', 'Settings saved.');
    }
    redirect('?a=settings');
}
