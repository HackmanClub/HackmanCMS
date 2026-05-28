# HackmanCMS

Web UI for managing Hexo sites and other server-side projects.

## Stack

- PHP 8.0+, no framework
- SQLite via PDO (`data/hackmancms.sqlite`)
- Bootstrap 5.3 dark theme + Bootstrap Icons
- Vanilla JS (no jQuery)

## Directory layout

```
web/                 Apache document root (index.php front controller)
web/api/             JSON API endpoints (included by front controller)
web/assets/          app.css, app.js (vanilla, no bundler)
lib/                 PHP classes loaded via bootstrap.php
lib/project-types/   one file per project type
views/               PHP templates (_header.php + _footer.php wrap each page)
views/project/       project page + _tab_*.php partials (one per tab)
sql/                 numbered migration files (001_, 002_, …)
bin/                 migrate.php, deploy.sh, run-schedules.php
config/              config.php
data/                SQLite database (gitignored)
deploylocal.sh       local rsync deploy with chown www-data + apache reload
```

## Routing

All requests go through `web/index.php`. Routes match URI paths with preg_match.
**Public routes** (no session required): `/login`, `/api/auth`, `/api/track`.
Everything else requires a valid session.

The site-visit tracker (`/api/track`) must be reachable from third-party browsers
hitting managed Hexo sites — that's why it's whitelisted alongside `/api/auth`.

## Versioning

Same pattern as `/opt/agenda` so the footer markup is identical:

- `VERSION` at the repo root holds **MAJOR.MINOR** only (e.g. `0.3`).
- The deploy script (`deploylocal.sh` and `bin/deploy.sh`) computes a patch
  number = git commit count since the last touch of `VERSION`, so bumping
  the file resets to `.0` and each commit auto-increments.
- The deploy script writes `web/BUILD` (gitignored) with key=value lines:
  ```
  version=0.3.42
  sha=ab12cd3
  branch=main
  built=2026-05-03 14:22:11
  ```
- `lib/bootstrap.php::buildInfo()` reads `web/BUILD` (cached static); falls
  back to `version=dev`, empty sha/branch/built when the file is missing.
- `views/_footer.php` renders `HackmanCMS v{version} · {sha} · build {built}`
  matching Agenda's layout.

Bump `VERSION` whenever you cut a release; redeploy and the footer updates
on the next request.

## Adding a project type

1. Create `lib/project-types/MyType.php`
2. Extend `ProjectTypeBase`, implement `typeSlug()`, `typeName()`, `typeIcon()`
3. Override `tabs()`, `commands()`, `detectFromPath()` as needed
4. No registration — auto-discovered via `get_declared_classes()` on boot

`ProjectTypeBase::tabs()` returns `['overview', 'files', 'recent', 'notes', 'settings']` by
default — every type that doesn't override gets those four universal tabs.

### Tab IDs

Universal (in default `ProjectTypeBase::tabs()`): `dashboard`, `analytics`, `files`, `notes`, `settings`.

Discord Bot-only (in `DiscordBotProject::tabs()`): `bot`, `botconfig`, `logs`.

Hexo-only (in `HexoProject::tabs()`): `posts`, `config`, `run`, `themes`, `plugins`, `git`.

Storage-only: `media`.

Tag/category cloud lives **inside** the per-project Dashboard tab — clicking
a tag links to `?tab=posts&filter=tag:<value>` (or `category:<value>`),
and the posts panel reads that query param to filter the list. The link-checker
report lives in the Settings tab. Recent files are also rendered in the
project Dashboard tab. Analytics has its own tab.

Each tab string maps 1:1 to a `<?php elseif ($tab === 'X')` branch in
`views/project/view.php` and (for non-trivial tabs) a `views/project/_tab_X.php`
include. Adding a tab string to a type's `tabs()` without a matching branch
renders an empty pane — update `view.php` and add a `_tab_X.php` partial.

The Files tab respects `?path=...` for deep-linking (used by the theme
manager's "Edit files" button).

## SQL migrations

Files in `sql/` run in sort order. `DB::autoMigrate()` is called on every bootstrap — idempotent, uses `schema_migrations` table.

**Never edit an applied migration.** Add a new file instead.

Tables in play (beyond core `users`/`projects`/`scan_paths`/`settings`):
`project_settings`, `command_history`, `audit_log`, `post_templates`,
`snippets`, `drafts`, `recent_files`, `scheduled_builds`, `link_check_runs`,
`link_check_results`, `site_visits`, `site_visits_hourly`,
`site_visits_daily`, plus a `scratchpad TEXT` column on `projects` (added in
`004_scratchpad.sql`).

## Deployment

| Target  | Command                  | What it does |
|---------|--------------------------|--------------|
| local   | `sudo ./deploylocal.sh`  | rsync to `/var/www/hackmancms`, chown www-data, migrate, reload Apache (port 8082) |
| server  | `./bin/deploy.sh`        | git pull, write BUILD, migrate — run directly on the server |

`deploylocal.sh` is the one to use day-to-day on bashyMint — it sets www-data ownership
so the schedules cron and npm/git child processes can write to project paths and to
`data/hackmancms.sqlite`.

On the server: Apache doc root = `/opt/hackmancms/web`. SQLite at `/opt/hackmancms/data/hackmancms.sqlite`.
Web server process needs write access to `/opt/hackmancms/data/`.

## Apache vhost (server, /opt/hackmancms)

```apache
<VirtualHost *:80>
    ServerName hackmancms.bashynx.com
    DocumentRoot /opt/hackmancms/web
    <Directory /opt/hackmancms/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The `.htaccess` in `web/` handles rewrite rules — `mod_rewrite` must be enabled.

## Local dev (bashyMint)

```bash
./bin/deploy.sh local        # rsync to /var/www/hackmancms, run migrate
```

Apache vhost locally:

```apache
<VirtualHost *:80>
    ServerName hackmancms.local
    DocumentRoot /var/www/hackmancms/web
    <Directory /var/www/hackmancms/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 hackmancms.local` to `/etc/hosts`.

## Discord Bot integration (DiscordBotProject)

Project type `discord-bot` manages a bashyBot instance. Auto-detected from a path containing `main.py` + `cogs/` + `config.json`.

**Tabs:** `bot` (process control), `botconfig` (structured config.json editor), `logs` (log tail).

**Bot control** (`web/api/botcontrol.php`) runs `sudo systemctl start|stop|restart|is-active|show <service>`.
The service name is stored in `project_settings` as `bot_service_name` (default: `bashybot`).
`www-data` requires passwordless sudo for these commands:

```
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start bashybot, /usr/bin/systemctl stop bashybot, /usr/bin/systemctl restart bashybot, /usr/bin/systemctl is-active bashybot, /usr/bin/systemctl show bashybot
```

**Config editor** (`web/api/botconfig.php`) reads/writes the project's `config.json` atomically (`.tmp` → rename).
Supports: `add_streamer`, `save_streamer`, `remove_streamer`, `add_rss`, `save_rss`, `remove_rss`, `save_linkedin`.
Never wipes existing tokens with an empty string submission.

**Log viewer** (`web/api/botlogs.php`) tails the log file.
Log path stored in `project_settings` as `bot_log_file` (default: `logs/bashybot.log`, relative to project path).

## Scheduled builds

`bin/run-schedules.php` is a cron-driven dispatcher: it iterates `scheduled_builds`,
matches each row's 5-field cron expression against the current minute, and runs any
that are due via the project type's whitelisted commands. Output goes to
`command_history`; `last_run_at` / `last_status` are updated on the schedule row.

It only fires if a host cron entry runs it every minute. As www-data on the box that
serves the app:

```bash
echo "* * * * * /usr/bin/php /opt/hackmancms/bin/run-schedules.php >/dev/null 2>&1" \
  | sudo crontab -u www-data -
```

UI for managing schedules lives in the Settings tab of any Hexo project (see
`views/project/_tab_settings.php` → "Scheduled builds" section).

## Audit log

`audit_log` is the source of truth for the dashboard activity feed and `/audit`.
Write entries via `Audit::log($db, $action, $project_id = null, $detail = null)` —
the helper swallows DB exceptions so a logging failure can't break the calling op.

**Convention: every state-changing action gets logged.** The only deliberate exception
is the per-project scratchpad (auto-saves several times per minute would flood the
feed; `recent_files` covers "what was the user touching" already).

Currently logged action strings:

| Source                 | Actions                                                                                                |
|------------------------|--------------------------------------------------------------------------------------------------------|
| `web/api/git.php`      | `git_stage`, `git_unstage`, `git_discard`, `git_pull`, `git_push`, `git_fetch`, `git_commit` (detail=message), `git_merge` (detail=branch), `git_reset` |
| `web/api/backup.php`   | `backup_download`                                                                                      |
| `web/api/links.php`    | `link_scan` (detail=`broken=N of M`)                                                                   |
| `web/api/themes.php`   | `theme_switch`, `theme_clone`, `theme_delete`, `theme_git_pull`, `theme_git_push`, `theme_git_fetch` (detail=theme name) |
| `web/api/plugins.php`  | `plugin_install`, `plugin_uninstall` (detail=package name)                                             |
| `web/api/files.php`    | `file_write`, `file_delete` (detail=relative path)                                                     |
| `web/api/upload.php`   | `file_upload` (detail=relative path)                                                                   |
| `web/api/posts.php`    | `post_create`, `post_delete`, `post_publish`, `post_duplicate` (detail=path)                           |
| `web/api/drafts.php`   | `draft_create`, `draft_update`, `draft_delete` (detail=title), `draft_publish` (detail=published path) |
| `web/api/run.php`      | `command_run` (detail=`cmd_id exit=N`)                                                                 |
| `web/api/projects.php` | `project_add`, `project_delete`, `project_rename`, `project_type_change`, `project_pin`, `project_unpin`, `project_setting` (detail=key), `scan_path_add`, `scan_path_delete` |
| `web/api/schedules.php`| `schedule_create`, `schedule_update`, `schedule_delete`                                                |
| `web/api/templates.php`| `template_create`, `template_update`, `template_delete` (detail=name)                                  |
| `web/api/snippets.php` | `snippet_create`, `snippet_update`, `snippet_delete` (detail=name)                                     |
| `bin/run-schedules.php`| `scheduled_build` (detail=`cmd_id status=...`)                                                         |
| `web/api/botcontrol.php` | `bot_start`, `bot_stop`, `bot_restart` (detail=service name)                                         |
| `web/api/botconfig.php`  | `botconfig_streamer_add`, `botconfig_streamer_save`, `botconfig_streamer_remove` (detail=name), `botconfig_rss_add`, `botconfig_rss_save`, `botconfig_rss_remove` (detail=name), `botconfig_linkedin_save` |

The activity feed renderer (`#activityFeed` in `app.js`) maps these to labels + icons
in `ACTION_LABELS` / `ACTION_ICONS`. Unmapped actions still render — they just show the
raw string and a fallback circle icon. **When adding a new logged action, also add it
to both maps in `app.js`.**

## Site visit analytics

Analytics is **server-log based**, not pixel based. HackmanCMS tails the web
server's access log, parses each line, and inserts rows into `site_visits`.
This means zero footprint on the managed Hexo site — no JS, no pixel, no
client-side change required.

**Per-project Analytics → "Server-log import setup"** captures the import
config; the importer + cull track their own state. Keys in `project_settings`:

| Key                       | Purpose                                                          |
|---------------------------|------------------------------------------------------------------|
| `analytics_log_path`      | absolute path to the access log (e.g. `/var/log/apache2/foo_access.log`) |
| `analytics_log_format`    | `combined` (Apache) or `nginx` — same field layout for our parser |
| `analytics_log_filter`    | optional URL-path prefix; lines whose path doesn't start with it are skipped |
| `analytics_last_size`     | byte cursor — last position read; reset on rotation              |
| `analytics_last_inode`    | inode of the file at last read; mismatch ⇒ rotation detected     |
| `analytics_imported_at`   | last import timestamp (display only)                             |
| `analytics_imported_count`| running total of rows imported (display only)                    |
| `analytics_last_rollup`   | last time the rollup + prune ran (throttled to once / 24h)       |

`bin/import-site-logs.php`:
- Iterates active projects (or one with `--project=N`)
- Opens each project's log, seeks to last byte cursor
- Parses Combined Log Format (works for nginx default too)
- Drops asset hits (`.css/.js/.png/...`), non-GETs, and obvious bots (`bot`, `curl`, `wget`, `headless`, ...). Keeps 2xx + 3xx + **404** so the analytics tab can surface broken-path hits.
- Hashes UA + IP with a fixed salt (truncated SHA-256, 16 chars) so we can count uniques without retaining raw values
- Inserts into `site_visits` (with `status` column tracked from the log line) and audit-logs as `analytics_import`
- **Runs the tiered cull** at the end of each project's import (no-op if <24h since last cull)

**Tiered rollup pipeline** (`maybeRollupAndPrune()` in the importer):

| Age window       | Storage                          | What's preserved                  |
|------------------|----------------------------------|-----------------------------------|
| today            | `site_visits` (raw events)       | full sub-hour timestamps          |
| 1–90 days        | `site_visits` (raw) + rollups    | full timestamps, plus rollups     |
| 91–365 days      | `site_visits_hourly` + `_daily`  | hour-granular path/status/referrer |
| > 365 days       | `site_visits_daily` only         | day-granular path/status/referrer |

Rollups are built nightly (throttled to once per project per 24h) by
aggregating raw rows GROUP BY (hour or day, path, status, referrer). The
INSERT OR REPLACE on the rollup tables' UNIQUE constraint makes the rollup
**idempotent** — re-rolling a day produces the same rows.

Aging-out drops raw rows >90d and hourly rows >365d. By the time a row is
dropped, the equivalent aggregate is already in the next tier — no count
information is lost. Audit-logged as `analytics_rollup`.

**Daily-rotating salt for IP hashes.** The importer hashes IPs with
`base_salt + visit_date`, so the same IP gets a different `ip_hash` on
different days. Within a day, distinct counts are exact; across days,
visitors look like new visitors. This makes the stored data anonymized
rather than pseudonymized for GDPR purposes — once the salt has rotated
past, no one (including the controller) can re-link yesterday's hashes to
today's visits. Trade-off: "unique visitors over multiple days" is the
sum of per-day unique counts (each visitor counted once per day they
visited), not deduplicated across days. UI surfaces this in a tooltip.

**Cron entry** (run on the box hosting both HackmanCMS and the web server, as a
user with read access to the log files — typically root or a member of `adm`):

```bash
*/5 * * * * /usr/bin/php /opt/hackmancms/bin/import-site-logs.php
```

`web/api/analytics_import.php` (auth required) is the same code path with
three actions: `run` (the "Import now" button), `reset` (clear cursors so
the next run reimports from start), `wipe` (drop all visits + cursors).

`web/api/analytics.php` aggregates over a configurable window (7/30/90/365
days) and returns: window + previous-window totals (for delta KPIs),
all-time totals, top pages, top referrers, daily series (current + previous
period for chart overlay), hour-of-day distribution, top 404s, and
status-code mix.

**Note:** `web/api/track.php` (the old 1×1 pixel endpoint) is no longer wired
into the public route table in `index.php`. The file is left in place as a
dormant fallback for cases where the managed site is *not* on the same box —
re-add the whitelist line in `index.php` to bring it back.

## Markdown editor (Milkdown)

`*.md` and `*.markdown` files open in a **Milkdown** WYSIWYG editor mounted
via the Agenda-style `mk-mount.js` pattern. Milkdown is loaded as ESM from
`esm.sh`'s pre-compiled `/es2022/` paths — same trick Agenda uses to keep
all `@milkdown/*` sub-packages on a single shared `core` instance (otherwise
ProseMirror's `SchemaReady` timer fails). The loader lives in `view.php`:

```html
<script type="module">
  const H = "https://esm.sh/@milkdown/";
  const V = "@7.20.0/es2022/";
  Promise.all([
    import(H + "core"              + V + "core.mjs"),
    import(H + "preset-commonmark" + V + "preset-commonmark.mjs"),
    import(H + "preset-gfm"        + V + "preset-gfm.mjs"),
    import(H + "plugin-history"    + V + "plugin-history.mjs"),
    import(H + "utils"             + V + "utils.mjs"),
  ]).then(([core, cm, gfmPkg, hist, utils]) => {
    window.MilkdownKit = { Editor: core.Editor, ... };
    window.dispatchEvent(new CustomEvent("milkdown-ready"));
  });
</script>
<script src="/assets/js/milkdown-mount.js"></script>
```

`mk-mount.js` auto-mounts on any `<textarea class="mk-mount">` it sees
(MutationObserver), wraps it with a toolbar (Bold/Italic/.../MD-toggle) and
exposes `ta.mkMount = { getContent, setContent, getMode, setMode }`.

**Front matter is NOT fed through Milkdown.** The earlier Milkdown
`plugin-frontmatter` approach mangled YAML on save — Milkdown was rendering
`---\n…\n---` as horizontal rules + paragraph text and round-tripping it
back as bullet lists. We now strip FM in `_openMdEditorTab()` via
`_parseMdSource()` and edit it in a separate small CodeMirror toggled by an
**FM** button injected next to mk-mount's MD button. On save, the FM string
is re-merged with the body. A `tab.hadFm` flag prevents an empty `---`
block from being added to files that didn't originally have one.

**Image URL handling — non-destructive.** Markdown source is left unchanged
(`images/foo.png` stays as-is, so Hexo sees what you typed). A
`MutationObserver` on the rendered ProseMirror DOM rewrites each `<img>`'s
`src` to `/api/files?project_id=<pid>&action=serve&path=source/<relpath>`
just for display. ProseMirror's model is untouched, so `getMarkdown()` on
save returns the original relative paths. See `_attachImgSrcRewriter()`
and `_resolveImagePathForEditor()`.

**Photos banner.** `photos:` (inline `[a, b]`, block list `\n- a\n- b`, or
single-line `photos: foo.jpg`) is parsed off the FM and rendered as a banner
of `<img>` elements **inside** `.ie-mk-body`, prepended above the
ProseMirror content so it reads like the rendered Hexo post. CSS sizes
banner images to 75% width, natural aspect, centered.

**Sizing — JS-driven.** Percentage `min-height` doesn't cascade reliably
through Milkdown's `.milkdown` → `.editor` → `.ProseMirror` wrappers, so
`_reflowMdEditor()` measures the live pane (via `ResizeObserver` on
`.md-editor-mount`) and writes explicit pixel `style.height` to the wrap
and body, plus `min-height` on the inner ProseMirror. This is what makes
the body's `overflow-y: auto` fire reliably so long posts scroll within
the canvas.

**Floating Save + Ctrl-S.** Save is a pinned floating pill at the
bottom-right of the pane, hidden via `.d-none` until `tab.dirty` is set
(`_markDirty(id)` → `_updateSaveBtnState(id)`). Ctrl/Cmd-S anywhere in the
pane saves. The tab strip dirty dot is driven by the same flag.

**Kebab in mk-mount toolbar.** `_injectMkToolbarExtras` appends a
`mk-pane-kebab` dropdown (Delete + Publish for drafts) to the right end of
mk-mount's toolbar after `mk-ready`, so destructive actions live inside the
editor's own row instead of squatting between the file-tab strip and the
canvas. The plain-file (non-md) editor uses the same floating-Save pattern
and exposes Edit/Delete on each row of the file list (no in-editor kebab).

## Editor tab persistence

Open file/post editor tabs are saved to `localStorage` per-project,
per-page (keys `hackmancms_tabs_<pid>_files` and `hackmancms_tabs_<pid>_posts`),
and replayed on page load. Drafts (DB-backed, kind=`draft`) are excluded
from persistence.

## Security notes

- File browser validates all paths stay within the project root (realpath check)
- Command runner only executes the exact `cmd` string defined in the project type class — no user input reaches the shell
- Sessions: httponly + strict mode; regenerated on login
