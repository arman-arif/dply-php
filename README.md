# DPLY

A single-file, self-hosted deployment console for Laravel apps. Drop one PHP file on your server and you get a web UI to pull code from git, run your post-deploy steps, run artisan commands, use a web terminal, manage SSH deploy keys and edit `.env` — for one app or several.

No agents, no build pipeline and no external services. Everything runs as the PHP user on the server it lives on.

> DPLY deploys code into apps that already exist. It deliberately does **not** provision servers or create or drop databases.

## Features

- **One-click deploys:** fetch `origin/<branch>`, sync the working tree, then run the steps you pick (maintenance mode, `composer install`, migrations, caches, queue restart, OPcache reset, your own commands). Output streams live in the browser.
- **Update checks and rollbacks:** see incoming commits, changed files and new migrations before deploying, and roll the code back to before any deployment.
- **Multiple projects:** manage several apps from one console. Each project has its own repository, branch, credentials, steps, history and `.env` backups.
- **Artisan runner:** common commands in one click, plus a free-form box. Destructive or never-ending commands (`migrate:fresh`, `db:wipe`, `tinker`, `queue:work`, …) are blocked.
- **Web terminal:** restricted mode (allow-listed commands, no shell) or unrestricted mode, per user.
- **`.env` editor:** validation, a conflict check if someone else edited it, timestamped backups, and an optional `config:cache` / `config:clear` afterwards.
- **SSH keys:** generate or import deploy keys, trust host keys, and test the connection.
- **Users and permissions:** per-user permissions, optional TOTP two-factor login, and limits on which projects each user can open.
- **Audit log** of every sign-in, deployment, command and settings change.
- **Light and dark themes**, and works on phones.

## Requirements

- PHP **8.1+** (CLI and web) with `pdo_sqlite`, `proc_open` enabled, and `sodium` or `openssl`. `posix` is optional and is used for nicer ownership checks.
- `git` and, if you use those steps, `composer`. `ssh-keygen` / `ssh` are needed for SSH-key auth.
- A web server that can run PHP: Apache (works out of the box via `.htaccess`) or nginx (see below).
- HTTPS. DPLY works over plain HTTP but warns you, because credentials and tokens would travel in clear text.

## Installation

DPLY is the single file `public/index.php`. Put the DPLY directory somewhere that is **not** inside your app's `public/` folder; only DPLY's own `public/` directory is exposed to the web.

**1. Get the file onto the server.** For example, inside a Laravel app:

```bash
cd /var/www/myapp
git clone https://github.com/arman-arif/dply-php.git app/tool/dply
```

You can also clone it anywhere else and point it at your app during setup.

**2. Run setup on the command line**, as the user PHP runs as. Setup is CLI-only, so nobody can claim the console over the web first.

```bash
sudo -u www-data php app/tool/dply/public/index.php setup
```

It asks for:

- the project root (the Laravel app to deploy),
- an HTTP Basic auth username and password (the outer gate),
- the first administrator account.

**3. Expose it over HTTPS.** Either point a dedicated (sub)domain at DPLY's `public/` directory, or symlink it into your app's `public/`:

```bash
ln -s ../app/tool/dply/public public/dply
```

**On Apache** nothing else is needed: a generated `public/.htaccess` allows only `index.php`. The `data/` directory sits outside `public/`, so it is never web-reachable.

**On nginx**, use [`nginx.conf.example`](nginx.conf.example). It has two ready-made options: a dedicated (sub)domain, or a `/dply/` sub-path inside your Laravel app's server block. Both serve only `index.php`, allow long deployments (`fastcgi_read_timeout`), and turn off buffering so deploy output streams live.

**4. Sign in and configure.** Pass the HTTP auth prompt, sign in with your admin account, then open **Settings** to set the repository URL, branch and authentication. Use **Save & test repository access** to check it.

## Multiple projects

One console can deploy several apps.

- **Add a project** under **Projects → New project**, or from the CLI with `project:add`. You can copy auth, sync options and post-deploy steps from an existing project.
- **Switch projects** with the picker at the top of the sidebar. The dashboard has an "All projects" overview.
- **Per project:** project root, repository, branch, git auth and token, SSH key choice, sync strategy, post-deploy steps, custom commands, maintenance secret, PHP binary, deploy history, deploy lock, `.env` backups and terminal folder.
- **Shared:** users, security settings, the SSH key pool, known hosts, composer and git binaries, timeouts and the terminal allow-list.
- **Access:** administrators can open every project. Other users can be limited to selected projects under **Users**.
- **Removing a project** deletes only DPLY's records for it (settings, history, backups). Files on disk are never touched.

If you switch projects in one tab, forms still open in another tab are refused instead of acting on the wrong project.

Upgrading from a single-project version is automatic: your existing configuration, deploy history and `.env` backups become the first project.

## Permissions

| Permission      | Allows                                                    |
| --------------- | --------------------------------------------------------- |
| `admin`         | Everything, including users, projects, security, settings |
| `deploy`        | Deployments, update checks and rollbacks                  |
| `artisan`       | Running artisan commands                                  |
| `terminal`      | Terminal restricted to the allow-list, without a shell    |
| `terminal_full` | Unrestricted shell                                        |
| `env`           | Viewing and editing `.env`                                |
| `keys`          | Managing SSH keys and known hosts                         |

The terminal, `.env`, SSH keys, users, security, settings and projects pages ask you to re-enter your password if you haven't recently (10 minutes by default).

## Security model

Requests pass through these layers in order:

1. Optional **IP allow-list**
2. **Automatic IP blocking** after repeated failures, with escalating block times
3. **HTTP Basic auth**, with its own credentials separate from user accounts
4. **User login**, plus optional **TOTP two-factor**
5. **Per-user permissions** and project access
6. **Password re-confirmation** for sensitive pages

Also:

- Strict CSP with per-request nonces, CSRF tokens on every form, and `SameSite=Strict` session cookies
- Idle and absolute session timeouts
- Git tokens encrypted at rest
- Artisan and the restricted terminal run commands without a shell. A shell is used only for `terminal_full` users and for custom post-deploy commands, which only admins can set.
- An append-only audit log

Policy values (attempts, block durations, session lifetimes) are configurable under **Security**.

## Command line

Run commands as the PHP user:

```bash
php public/index.php <command>
```

| Command                                                        | Purpose                                            |
| -------------------------------------------------------------- | -------------------------------------------------- |
| `setup [--force]`                                              | Initial setup: HTTP auth and the admin account     |
| `http:set`                                                     | Change the HTTP Basic auth credentials             |
| `user:add <name> [--perms=deploy,artisan,…]`                   | Add a user                                         |
| `user:password <name>`                                         | Set a password (also unlocks the account)          |
| `user:unlock <name>`                                           | Clear a lockout                                    |
| `user:enable <name>`                                           | Re-enable a disabled user                          |
| `user:2fa-reset <name>`                                        | Remove a user's two-factor secret                  |
| `user:list`                                                    | List users                                         |
| `blocks` / `unblock <ip>` / `unblock --all`                    | Show or remove IP blocks                           |
| `allowlist:clear`                                              | Remove the IP allow-list if you locked yourself out |
| `projects`                                                     | List projects                                      |
| `project:add <slug> --root=/path [--name=] [--branch=] [--repo=]` | Add a project                                   |
| `deploy [--steps=a,b,c]`                                       | Run a deployment                                   |

Project-specific commands act on the first project unless you pass `--project=<slug>`:

```bash
php public/index.php deploy --project=shop-api
php public/index.php deploy --project=shop-api --steps=composer,migrate,config_cache
```

That makes it easy to deploy from cron or a CI job over SSH.

## Runtime data

Everything DPLY stores lives in `data/` in the DPLY directory (next to `public/`, outside the web root). To keep it elsewhere, set the `DPLY_DATA_DIR` environment variable.

- `sqlite.db`: settings, projects, users, deployments, audit log
- `keys/`: encryption key, SSH keys, known hosts
- `sessions/`
- `env-backups/p<id>/`: `.env` backups for each project

Back this directory up, and never serve it over the web. It is in `.gitignore`.

## Development

The source is split into modules in `src/`, which are compiled into the single deployable `public/index.php`. Modules are concatenated in filename order; `00-bootstrap.php` comes first and `99-run.php` last.

```bash
php build.php           # rebuild public/index.php (lints before writing)
php build.php --check   # exit 1 if public/index.php is out of date (CI / pre-commit)
```

Edit files in `src/`, never `public/index.php` directly, and commit the rebuilt `public/index.php` together with your changes.

To try it locally:

```bash
mkdir -p /tmp/dply-data
DPLY_DATA_DIR=/tmp/dply-data php public/index.php setup
DPLY_DATA_DIR=/tmp/dply-data php -S 127.0.0.1:8080
```
