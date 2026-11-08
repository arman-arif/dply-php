<?php

declare(strict_types=1);

// ============================================================================
//  Projects (admin)
// ============================================================================

function project_switch_button(int $pid, string $return, string $label, string $cls = 'sm'): string
{
    return '<form method="post" action="?a=project_switch">' . csrf_field() . '<input type="hidden" name="id" value="' . $pid . '">'
        . '<input type="hidden" name="return" value="' . h($return) . '"><button class="' . h($cls) . '" type="submit">' . $label . '</button></form>';
}

function page_projects(): void
{
    $cur = current_project_id();
    $all = projects_all();
    $restricted = 0;
    foreach (db()->query("SELECT perms, projects FROM users WHERE projects != ''") as $u) {
        $restricted += user_project_ids($u) !== null ? 1 : 0;
    }
    $cards = '';
    foreach ($all as $p) {
        $pid = $p['id'];
        $root = project_setting($pid, 'project_root');
        $last = project_last_deploy($pid);
        $down = is_file(rtrim($root, '/') . '/storage/framework/down');
        $on = $pid === $cur;
        $count = (int) db()->query('SELECT COUNT(*) FROM deployments WHERE project_id = ' . $pid)->fetchColumn();
        $repo = project_setting($pid, 'repo_url');
        $cards .= '<div class="pcard' . ($on ? ' on' : '') . '"><div class="pcard-h"><div class="key-ic' . ($on ? ' on' : '') . '">' . icon('folder') . '</div>'
            . '<div class="n"><b>' . h($p['name']) . '</b><small>' . h($p['slug']) . '</small></div>' . ($on ? badge('current', 'ok') : '') . '</div>'
            . '<div class="pmeta"><span title="' . h($root) . '">' . icon('disk') . h(strlen($root) > 38 ? '…' . substr($root, -37) : $root)
            . (is_dir($root) ? '' : ' ' . badge('missing', 'err')) . '</span></div>'
            . '<div class="pmeta"><span>' . icon('branch') . h(project_setting($pid, 'branch')) . '</span>'
            . '<span>' . icon('lock') . h(project_setting($pid, 'git_auth')) . '</span>'
            . ($down ? '<span>' . badge('maintenance', 'err') . '</span>' : '')
            . '<span>' . icon('deploy') . ($last ? deploy_status_badge($last['status']) . ' ' . ago((int) $last['started_at']) : 'never deployed') . '</span></div>'
            . ($repo !== '' ? '<div class="pmeta"><span class="break">' . icon('sync') . '<code>' . h($repo) . '</code></span></div>' : '')
            . '<div class="row">'
            . ($on ? '<a class="btn sm primary" href="?a=dashboard">' . icon('dashboard') . 'Open</a><a class="btn sm" href="?a=settings">' . icon('settings') . 'Settings</a>'
                : project_switch_button($pid, 'dashboard', icon('arrow') . 'Open', 'sm primary') . project_switch_button($pid, 'settings', icon('settings') . 'Settings'))
            . '<details class="pmore" style="margin-left:auto"><summary class="btn sm ghost">Edit</summary><div class="pmore-b">'
            . '<form method="post" action="?a=projects">' . csrf_field() . '<input type="hidden" name="op" value="rename"><input type="hidden" name="id" value="' . $pid . '">'
            . '<label><span>Name</span><input type="text" name="name" value="' . h($p['name']) . '" required maxlength="60"></label>'
            . '<label><span>Slug (used by the CLI: --project=…)</span><input type="text" name="slug" value="' . h($p['slug']) . '" required pattern="[a-z0-9][a-z0-9\-]{0,39}"></label>'
            . '<button class="sm" type="submit">' . icon('check') . 'Save</button></form>'
            . (count($all) > 1 ? '<hr class="divider"><form method="post" action="?a=projects" data-confirm="Remove project &quot;' . h($p['name']) . '&quot; from DPLY? Its settings, ' . $count
                . ' deployment record(s) and .env backups are deleted. Files in ' . h($root) . ' are NOT touched.">' . csrf_field()
                . '<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . $pid . '"><button class="sm danger" type="submit">Remove project</button></form>'
                : '<p class="hint">The only project cannot be removed.</p>')
            . '</div></details></div></div>';
    }

    $copyOpts = '<option value="">— start with defaults —</option>';
    foreach ($all as $p) {
        $copyOpts .= '<option value="' . $p['id'] . '">' . h($p['name']) . '</option>';
    }
    render('Projects', page_head('Projects', 'Every app this console deploys. Settings, deploy history, .env backups and the terminal folder are kept separately per project.',
        '<a class="btn primary" href="#new-project">' . icon('plus') . 'New project</a>')
        . '<div class="pgrid" style="margin-bottom:18px">' . $cards . '</div>'
        . '<p class="hint" style="margin:0 0 22px">' . icon('users', 'small') . ' Administrators can open every project. '
        . ($restricted ? $restricted . ' user(s) are limited to specific projects — see ' : 'Other users can be limited to specific projects under ')
        . '<a href="?a=users">Users</a>.</p>'
        . '<form method="post" action="?a=projects" class="card" id="new-project">' . csrf_field() . '<input type="hidden" name="op" value="create">'
        . '<h2>' . icon('plus') . 'New project</h2>'
        . '<div class="grid" style="margin-bottom:6px"><div>'
        . '<label><span>Name</span><input type="text" name="name" required maxlength="60" placeholder="Shop API"></label>'
        . '<label><span>Slug — optional, derived from the name</span><input type="text" name="slug" pattern="[a-z0-9][a-z0-9\-]{0,39}" placeholder="shop-api"></label>'
        . '<label><span>Project root — the Laravel app\'s directory on this server</span><input type="text" name="project_root" required placeholder="/var/www/shop-api"></label>'
        . '</div><div>'
        . '<label><span>Repository URL — optional, can be set later</span><input type="text" name="repo_url" placeholder="git@github.com:org/shop-api.git"></label>'
        . '<label><span>Branch to deploy</span><input type="text" name="branch" value="main" required></label>'
        . '<label><span>Copy auth, sync options and post-deploy steps from</span><select name="copy_from">' . $copyOpts . '</select></label>'
        . '</div></div><div class="actions" style="margin-top:0"><button class="primary" type="submit">' . icon('plus') . 'Create project</button>'
        . '<span class="small mute">You\'ll be switched to the new project and taken to its settings.</span></div></form>');
}

function do_projects(): void
{
    $op = (string) ($_POST['op'] ?? '');
    $pdo = db();
    $id = (int) ($_POST['id'] ?? 0);
    $target = $id ? project_by_id($id) : null;
    if ($op !== 'create' && !$target) {
        flash('err', 'Project not found.');
        redirect('?a=projects');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
    switch ($op) {
        case 'create':
            $slug = $slug !== '' ? $slug : slugify($name);
            $root = rtrim(trim((string) ($_POST['project_root'] ?? '')), '/');
            [$real, $rootErr] = project_root_check($root);
            $url = trim((string) ($_POST['repo_url'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? 'main'));
            $copy = (int) ($_POST['copy_from'] ?? 0);
            $copy = $copy && project_by_id($copy) ? $copy : 0;
            $auth = $copy ? project_setting($copy, 'git_auth') : 'none';
            $err = $name === '' || utf8_len($name) > 60 ? 'Name is required (max 60 characters).'
                : (!valid_slug($slug) ? 'Slug: lowercase letters, digits and dashes (max 40).'
                : (project_by_ref($slug) ? "Slug '$slug' is already taken." : ($rootErr ?? repo_url_error($url, $auth) ?? branch_error($branch))));
            if ($err) {
                flash('err', $err);
                redirect('?a=projects#new-project');
            }
            $pdo->prepare('INSERT INTO projects (slug, name, created_at) VALUES (?, ?, ?)')->execute([$slug, $name, time()]);
            $pid = (int) $pdo->lastInsertId();
            projects_changed();
            if ($copy) {
                foreach (project_settings_raw($copy) as $k => $v) {
                    if (!in_array($k, ['project_root', 'repo_url', 'branch'], true)) {
                        set_project_setting($pid, $k, $v);
                    }
                }
            }
            set_project_setting($pid, 'project_root', (string) $real);
            set_project_setting($pid, 'repo_url', $url);
            set_project_setting($pid, 'branch', $branch);
            use_project($pid);
            audit('project.create', "$slug root=$real" . ($copy ? ' (copied from ' . project_by_id($copy)['slug'] . ')' : ''));
            flash('ok', "Project \"$name\" created. Review its settings below, then deploy.");
            redirect('?a=settings');
            // no break — redirect() never returns
        case 'rename':
            if ($name === '' || utf8_len($name) > 60) {
                flash('err', 'Name is required (max 60 characters).');
            } elseif (!valid_slug($slug)) {
                flash('err', 'Slug: lowercase letters, digits and dashes (max 40).');
            } elseif (($o = project_by_ref($slug)) && $o['id'] !== $id) {
                flash('err', "Slug '$slug' is already taken.");
            } else {
                $pdo->prepare('UPDATE projects SET name = ?, slug = ? WHERE id = ?')->execute([$name, $slug, $id]);
                projects_changed();
                audit('project.rename', "{$target['slug']} → $slug ($name)");
                flash('ok', 'Project saved.');
            }
            break;
        case 'delete':
            if (count(projects_all()) <= 1) {
                flash('err', 'The only project cannot be removed.');
                break;
            }
            $lock = fopen(DPLY_DATA . "/deploy-p$id.lock", 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                flash('err', 'A deployment of this project is running — try again when it has finished.');
                break;
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM project_settings WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM deployments WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            // Drop the id from users' project restrictions.
            foreach ($pdo->query("SELECT id, projects FROM users WHERE projects != ''")->fetchAll() as $u) {
                // '' means "all projects", so a user restricted to only this project becomes '0' (no project), never ''.
                $left = array_values(array_diff(array_filter(explode(',', (string) $u['projects'])), [(string) $id, '0']));
                $pdo->prepare('UPDATE users SET projects = ? WHERE id = ?')->execute([$left ? implode(',', $left) : '0', $u['id']]);
            }
            $pdo->commit();
            foreach (glob(DPLY_DATA . "/env-backups/p$id/env-*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(DPLY_DATA . "/env-backups/p$id");
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink(DPLY_DATA . "/deploy-p$id.lock");
            projects_changed();
            if (current_project_id() === $id) {
                $first = accessible_projects(current_user());
                use_project($first ? reset($first)['id'] : null);
            }
            audit('project.delete', "{$target['slug']} ({$target['name']})");
            flash('ok', "Project \"{$target['name']}\" removed. Its files on disk were not touched.");
            break;
    }
    redirect('?a=projects');
}
