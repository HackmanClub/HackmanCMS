# HackmanCMS

Lightweight web UI for managing Hexo blogs and other server-side projects.

## Features

- **Dashboard** with project cards, disk-usage badges, recent-activity feed
- **Plugin-based project types** — drop a PHP file into `lib/project-types/` to add a new type
- **Per-project sidebar** with grouped, collapsible navigation; **versioning** shown in the footer
- **Posts editor (Hexo)** — Milkdown WYSIWYG with MD-source toggle, in-canvas photos banner from front-matter `photos:`, image rendering for relative `images/...` paths, image-paste upload to `source/images/`, floating Save (only when dirty), Ctrl/Cmd-S, tab dirty dot, image rewriting kept out of the markdown source so files round-trip cleanly through Hexo
- **Drafts editor** — same Milkdown body editor as posts, with title/slug/folder as inline fields
- **Files editor** — CodeMirror per file; full-pane height; Edit + Delete from a kebab on each list row; floating Save when dirty
- **File browser** path-traversal safe; deep-linkable via `?tab=files&path=...`
- **Command runner** with whitelisted commands per project type
- **Scratchpad** (auto-saving notes), **recent files** panel, **disk usage**, **zip backup** (excludes `node_modules`, `public`, `.git`)
- **Broken link checker** (in Settings tab) — scans posts/pages for dead HTTP links via parallel `curl_multi` HEAD requests
- **Scheduled builds** — cron-style triggers for any project command (requires host cron entry)
- **Theme manager** (Hexo) — list/switch/clone themes, git status + push/pull on each, deep-link to file editor
- **Plugin manager** (Hexo) — list `hexo-*` deps, npm install/uninstall from the UI
- **Site visit analytics** (own tab) — tails the web server's access log into a tiered rollup pipeline (raw 90 days → hourly 365 days → daily forever) with a **daily-rotating salt for IP hashes** so post-rotation data is anonymized for GDPR purposes. Per-project page with KPIs, current-vs-previous-period chart overlay, hour-of-day distribution, top pages, top referrers, top 404s, status-code mix.
- **Tag/category filtering** — click a tag/category in the project Dashboard to jump to Posts pre-filtered
- **Editor tab persistence** — file/post tabs survive sidebar navigation and full reloads
- **Global keyboard shortcuts** — `?` for help, `g d/a` to navigate, `n` for new post / `b` to trigger generate on project pages, `Ctrl/Cmd-S` to save the current editor
- **Audit log** for every state-changing action (file/post/draft writes, command runs, project changes, theme/plugin/git/analytics ops)
- Bootstrap 5.3 dark UI, no client-side build step. Vanilla JS; ESM modules pulled from CDN where needed (Milkdown).

## Built-in project types

| Type    | Auto-detected by                 | Type-specific tabs |
|---------|----------------------------------|--------------------|
| Hexo    | `_config.yml` + `source/`        | Posts, Config, Run, Themes, Plugins, Git |
| Website | `index.html` or `index.php`      | Git |
| Storage | `uploads/`, `files/`, `storage/` | Media |
| Generic | fallback                         | — |

All types share these tabs: **Dashboard**, **Analytics**, **Files**, **Notes**, **Settings**.

## Requirements

- PHP 8.0+ with `pdo_sqlite`, `zip`, and `curl` extensions
- Apache (`mod_rewrite`) or Nginx with the included `.htaccess` rewrite rules
- `git` and `npm` on PATH (for theme + plugin tabs)
- GNU coreutils `du` (for disk usage; standard on Debian/Ubuntu/Mint)
- System cron (only if scheduled builds or analytics importer are used)
- Modern browser with ES modules support (Milkdown is loaded as ESM)

## Quick start

```bash
git clone … /opt/hackmancms
php /opt/hackmancms/bin/migrate.php
```

Configure Apache to serve `/opt/hackmancms/web` (see `CLAUDE.md` for the full vhost config).
Visit the app and create your account on first load.

### Optional: enable scheduled builds

Schedules created in the UI only fire if a host cron entry runs the dispatcher
every minute. Install once, as the same user the web server runs as:

```bash
echo "* * * * * /usr/bin/php /opt/hackmancms/bin/run-schedules.php >/dev/null 2>&1" \
  | sudo crontab -u www-data -
```

### Optional: enable site-visit analytics

To pull live page-view data into the Analytics tab, set the access-log path
under each project's Analytics → "Server-log import setup" panel, then install
a host cron entry (as a user that can read the log files):

```bash
*/5 * * * * /usr/bin/php /opt/hackmancms/bin/import-site-logs.php
```

The importer hashes each visitor's IP with a daily-rotating salt, builds
hourly + daily rollups, and ages out raw events past 90 days and hourly
buckets past 365 days. Daily aggregates are kept forever.

## Adding a project type

Create `lib/project-types/MyType.php`:

```php
<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class MyType extends ProjectTypeBase {
    public static function typeSlug(): string { return 'mytype'; }
    public static function typeName(): string { return 'My Type'; }
    public static function typeIcon(): string { return 'bi-star'; }

    public static function tabs(): array {
        return ['dashboard', 'analytics', 'files', 'run', 'notes', 'settings'];
    }

    public static function commands(): array {
        return [
            ['id' => 'build', 'label' => 'Build', 'cmd' => 'make build'],
        ];
    }

    public static function detectFromPath(string $path): bool {
        return file_exists($path . '/Makefile');
    }
}
```

No other changes needed — it appears in the UI on next load.

## License

Internal tool, no license attached.
