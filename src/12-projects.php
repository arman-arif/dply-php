<?php

declare(strict_types=1);

// ============================================================================
//  Projects — several apps managed from one console
// ============================================================================
//  Settings listed in DPLY_PROJECT_KEYS are stored per project (table
//  project_settings); setting()/set_setting() route them to the *current*
//  project transparently, so the rest of the code keeps calling setting('branch').
//  Everything else (users, security, binaries, SSH key pool) stays global.

const DPLY_PROJECT_KEYS = [
    'project_root', 'repo_url', 'branch', 'git_auth', 'git_token_user', 'git_token', 'ssh_key',
    'sync_strategy', 'git_clean', 'submodules', 'post_steps', 'custom_commands', 'maint_secret', 'php_bin',
];

function is_project_key(string $k): bool
{
    return in_array($k, DPLY_PROJECT_KEYS, true);
}

/** Schema upgrades for multi-project support. Idempotent; runs on every connection. */
function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS project_settings (
            project_id INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
            k TEXT NOT NULL, v TEXT NOT NULL,
            PRIMARY KEY (project_id, k)
        );
        SQL);
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    foreach ([['deployments', 'project_id', 'INTEGER'], ['audit', 'project_id', 'INTEGER'], ['users', 'projects', "TEXT NOT NULL DEFAULT ''"]] as [$t, $c, $type]) {
        if (!in_array($c, $cols($t), true)) {
            try {
                $pdo->exec("ALTER TABLE $t ADD COLUMN $c $type");
            } catch (PDOException $e) {
                // A concurrent request added it first.
            }
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS deployments_project ON deployments (project_id, id)');

    if ((int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() > 0) {
        return;
    }
    // First run after the upgrade (or a fresh install): turn the single-project
    // configuration into project #1, keeping its history and .env backups.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() === 0) {
            $legacy = [];
            foreach ($pdo->query('SELECT k, v FROM settings') as $r) {
                if (is_project_key($r['k'])) {
                    $legacy[$r['k']] = $r['v'];
                }
            }
            $root = rtrim($legacy['project_root'] ?? setting_defaults()['project_root'], '/');
            $name = basename($root) ?: 'Default';
            $pdo->prepare('INSERT INTO projects (slug, name, created_at) VALUES (?, ?, ?)')->execute([slugify($name) ?: 'default', $name, time()]);
            $pid = (int) $pdo->lastInsertId();
            $legacy['project_root'] = $root;
            $ins = $pdo->prepare('INSERT INTO project_settings (project_id, k, v) VALUES (?, ?, ?)');
            foreach ($legacy as $k => $v) {
                $ins->execute([$pid, $k, $v]);
            }
            $pdo->prepare('UPDATE deployments SET project_id = ? WHERE project_id IS NULL')->execute([$pid]);
            $old = glob(DPLY_DATA . '/env-backups/env-*') ?: [];
            if ($old) {
                $dir = DPLY_DATA . "/env-backups/p$pid";
                if (is_dir($dir) || @mkdir($dir, 0700, true)) {
                    foreach ($old as $f) {
                        @rename($f, "$dir/" . basename($f));
                    }
                }
            }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function slugify(string $s): string
{
    return substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'), 0, 40);
}

/** Character length of a UTF-8 string without requiring the mbstring extension. */
function utf8_len(string $s): int
{
    return (int) preg_match_all('/./us', $s);
}

/** Shorten a UTF-8 string to $max characters, ending with "…" when cut. */
function utf8_trim(string $s, int $max): string
{
    return utf8_len($s) <= $max ? $s : (string) preg_replace('/^(.{' . ($max - 1) . '}).*$/us', '$1', $s) . '…';
}

function valid_slug(string $s): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $s);
}

/** All projects keyed by id, sorted by name. */
function projects_all(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh || isset($GLOBALS['__dply_projects_dirty'])) {
        unset($GLOBALS['__dply_projects_dirty']);
        $cache = [];
        foreach (db()->query('SELECT * FROM projects ORDER BY name COLLATE NOCASE, id') as $p) {
            $p['id'] = (int) $p['id'];
            $cache[$p['id']] = $p;
        }
    }
    return $cache;
}

function projects_changed(): void
{
    $GLOBALS['__dply_projects_dirty'] = true;
    $GLOBALS['__dply_psettings_dirty'] = true;
}

function project_by_id(int $id): ?array
{
    return projects_all()[$id] ?? null;
}

/** Look a project up by slug or numeric id (CLI --project=). */
function project_by_ref(string $ref): ?array
{
    foreach (projects_all() as $p) {
        if (strcasecmp($p['slug'], $ref) === 0 || (ctype_digit($ref) && $p['id'] === (int) $ref)) {
            return $p;
        }
    }
    return null;
}

function project_settings_raw(int $pid): array
{
    static $cache = [];
    if (isset($GLOBALS['__dply_psettings_dirty'])) {
        unset($GLOBALS['__dply_psettings_dirty']);
        $cache = [];
    }
    if (!isset($cache[$pid])) {
        $st = db()->prepare('SELECT k, v FROM project_settings WHERE project_id = ?');
        $st->execute([$pid]);
        $cache[$pid] = [];
        foreach ($st as $r) {
            $cache[$pid][$r['k']] = $r['v'];
        }
    }
    return $cache[$pid];
}

function project_setting(int $pid, string $k): string
{
    return (string) (project_settings_raw($pid)[$k] ?? setting_defaults()[$k] ?? '');
}

function set_project_setting(int $pid, string $k, string $v): void
{
    db()->prepare('INSERT INTO project_settings (project_id, k, v) VALUES (?, ?, ?) ON CONFLICT(project_id, k) DO UPDATE SET v = excluded.v')
        ->execute([$pid, $k, $v]);
    $GLOBALS['__dply_psettings_dirty'] = true;
}

/** Project ids the user may open, or null for "every project" (admins and unrestricted users). */
function user_project_ids(?array $u): ?array
{
    if (!$u) {
        return null;
    }
    if (in_array('admin', array_filter(explode(',', (string) $u['perms'])), true) || trim((string) ($u['projects'] ?? '')) === '') {
        return null;
    }
    return array_values(array_unique(array_map('intval', array_filter(explode(',', (string) $u['projects']), 'ctype_digit'))));
}

function user_can_project(?array $u, int $pid): bool
{
    if (!project_by_id($pid)) {
        return false;
    }
    $ids = user_project_ids($u);
    return $ids === null || in_array($pid, $ids, true);
}

function accessible_projects(?array $u): array
{
    return array_filter(projects_all(), fn($p) => user_can_project($u, $p['id']));
}

/** Make $id the current project for this request (and, on the web, for the session). */
function use_project(?int $id): void
{
    $GLOBALS['__dply_project'] = $id !== null ? project_by_id($id) : null;
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['project'] = $id;
    }
}

/** The current project. Web: the session's choice if still allowed, else the first allowed. CLI: the first project. */
function current_project(): ?array
{
    if (!array_key_exists('__dply_project', $GLOBALS)) {
        $u = PHP_SAPI === 'cli' ? null : current_user();
        $want = PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE ? (int) ($_SESSION['project'] ?? 0) : 0;
        if ($want && user_can_project($u, $want)) {
            $GLOBALS['__dply_project'] = project_by_id($want);
        } else {
            $list = accessible_projects($u);
            $first = $list ? reset($list) : null;
            use_project($first['id'] ?? null);
        }
    }
    return $GLOBALS['__dply_project'];
}

function current_project_id(): int
{
    return (int) (current_project()['id'] ?? 0);
}

/** The project id already chosen for this request, without triggering resolution (used by audit()). */
function resolved_project_id(): ?int
{
    return isset($GLOBALS['__dply_project']['id']) ? (int) $GLOBALS['__dply_project']['id'] : null;
}

/** Another project whose root is $root, if any (two projects must not share a directory). */
function project_using_root(string $root, int $exceptId = 0): ?array
{
    foreach (projects_all() as $p) {
        if ($p['id'] !== $exceptId && rtrim(project_setting($p['id'], 'project_root'), '/') === rtrim($root, '/')) {
            return $p;
        }
    }
    return null;
}

function project_last_deploy(int $pid): ?array
{
    $st = db()->prepare('SELECT id, status, actor, started_at FROM deployments WHERE project_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$pid]);
    return $st->fetch() ?: null;
}

function env_backup_dir(): string
{
    $d = DPLY_DATA . '/env-backups/p' . current_project_id();
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

function deploy_lock_file(): string
{
    return DPLY_DATA . '/deploy-p' . current_project_id() . '.lock';
}
