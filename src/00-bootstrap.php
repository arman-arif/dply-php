<?php
/**
 * ============================================================================
 *  DPLY — single-file deployment console for one or more Laravel applications
 * ============================================================================
 *  Purpose: fetch/pull updates from git into the project, then run the
 *  post-deployment artisan steps. Also: artisan runner, web terminal,
 *  SSH key management and an authorised .env editor.
 *  (No server provisioning and no database create/drop — by design.)
 *
 *  FIRST RUN — on the server, as the user PHP runs as (e.g. www-data):
 *      sudo -u www-data php app/tool/dply/index.php setup
 *  Other CLI commands:   php app/tool/dply/index.php help
 *
 *  PROJECTS: one console can deploy several apps. Add them under Projects in
 *  the web UI or with `project:add <slug> --root=/path`; pick one in the
 *  sidebar, or pass --project=<slug> on the CLI (e.g. deploy --project=shop).
 *  Repository, auth, steps and deploy history are per project; users,
 *  security, binaries and the SSH key pool are shared.
 *
 *  Security layers, in order:
 *      optional IP allow-list → automatic IP blocking (escalating) →
 *      HTTP Basic auth (own username/password) → user login (+ optional TOTP)
 *      → per-user permissions → password re-confirmation for sensitive pages.
 *
 *  EXPOSING IT: this file lives outside public/. Either point a dedicated
 *  (sub)domain at this directory, or symlink it into public/:
 *      ln -s ../app/tool/dply public/dply
 *  Runtime data (sqlite.db, keys, sessions, .env backups) lives in ./data.
 *  On Apache it is denied by generated .htaccess files. On nginx add e.g.:
 *      location ~ ^/dply/(?!index\.php) { deny all; }
 *  Serve it over HTTPS only. Optionally relocate data via env DPLY_DATA_DIR.
 * ============================================================================
 */
declare(strict_types=1);

define('DPLY_VERSION', '1.1.0');
define('DPLY_DIR', __DIR__);
define('DPLY_DATA', rtrim((string) (getenv('DPLY_DATA_DIR') ?: __DIR__ . '/data'), '/'));
define('DPLY_DB', DPLY_DATA . '/sqlite.db');
define('DPLY_MAX_OUTPUT', 1048576);

const DPLY_PERMS = [
    'admin'         => 'Administrator — everything incl. users, security & settings',
    'deploy'        => 'Run deployments, update checks & rollbacks',
    'artisan'       => 'Run artisan commands',
    'terminal'      => 'Terminal — restricted to the allow-list, no shell',
    'terminal_full' => 'Terminal — unrestricted shell',
    'env'           => 'View & edit .env',
    'keys'          => 'Manage SSH keys & known hosts',
];

const DPLY_STEPS = [
    'down'           => 'Maintenance mode ON (artisan down)',
    'composer'       => 'composer install --no-dev --optimize-autoloader',
    'migrate'        => 'artisan migrate --force',
    'storage_link'   => 'artisan storage:link',
    'optimize_clear' => 'artisan optimize:clear',
    'config_cache'   => 'artisan config:cache',
    'route_cache'    => 'artisan route:cache',
    'view_cache'     => 'artisan view:cache',
    'event_cache'    => 'artisan event:cache',
    'queue_restart'  => 'artisan queue:restart',
    'custom'         => 'Custom post-deploy commands (project settings)',
    'opcache'        => 'Reset PHP OPcache',
    'up'             => 'Maintenance mode OFF (artisan up)',
];

/** Artisan commands that are never allowed (destroy data or are interactive / never-ending). */
const DPLY_ARTISAN_DENY = [
    'db:wipe', 'migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db', 'tinker', 'serve',
    'queue:work', 'queue:listen', 'schedule:work', 'horizon', 'octane:start', 'reverb:start', 'pail',
];
