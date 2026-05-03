<?php
/**
 * Tail web server access logs into site_visits.
 *
 * Run as the user that can read the configured log files (typically root or
 * a member of adm). Cron entry — every 5 minutes, all projects:
 *
 *   *\/5 * * * * /usr/bin/php /opt/hackmancms/bin/import-site-logs.php
 *
 * Or one project at a time (used by the "Import now" button):
 *
 *   php /opt/hackmancms/bin/import-site-logs.php --project=42
 *
 * Tracks per-project state in project_settings:
 *   analytics_log_path        configured log file
 *   analytics_log_format      'combined' | 'nginx'
 *   analytics_log_filter      optional path prefix filter
 *   analytics_last_size       last byte position
 *   analytics_last_inode      last file inode (for rotation detection)
 *   analytics_imported_at     last import timestamp
 *   analytics_imported_count  cumulative rows imported
 */

if (php_sapi_name() !== 'cli' && empty($_GET['project_id'])) {
    // Allow inclusion via API endpoint too.
}

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
    require ROOT . '/lib/bootstrap.php';
}

$onlyProject = null;
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--project=(\d+)$/', $a, $m)) $onlyProject = (int)$m[1];
}

$result = importAllProjects($db, $onlyProject);
if (php_sapi_name() === 'cli') {
    foreach ($result as $r) {
        printf("[%s] project=%d imported=%d skipped=%d %s\n",
            date('Y-m-d H:i:s'), $r['project_id'],
            $r['imported'], $r['skipped'],
            $r['error'] ? 'ERROR: ' . $r['error'] : '');
    }
}

function importAllProjects(PDO $db, ?int $onlyProject = null): array {
    $args = [];
    $sql  = 'SELECT id, name FROM projects WHERE is_active = 1';
    if ($onlyProject !== null) { $sql .= ' AND id = ?'; $args[] = $onlyProject; }
    $st = $db->prepare($sql); $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $out[] = importOne($db, (int)$p['id']);
    }
    return $out;
}

function importOne(PDO $db, int $pid): array {
    $cfg  = readProjectSettings($db, $pid);
    $path = trim((string)($cfg['analytics_log_path'] ?? ''));
    $fmt  = (string)($cfg['analytics_log_format'] ?? 'combined');
    $pre  = trim((string)($cfg['analytics_log_filter'] ?? ''));
    if ($path === '') return ['project_id' => $pid, 'imported' => 0, 'skipped' => 0, 'error' => null];
    if (!is_readable($path)) {
        return ['project_id' => $pid, 'imported' => 0, 'skipped' => 0,
                'error' => "log not readable: $path"];
    }

    $stat  = stat($path);
    $size  = $stat['size']  ?? 0;
    $inode = $stat['ino']   ?? 0;
    $lastSize  = (int)($cfg['analytics_last_size']  ?? 0);
    $lastInode = (int)($cfg['analytics_last_inode'] ?? 0);

    // Detect rotation: file replaced or shrank.
    if ($inode !== $lastInode || $size < $lastSize) {
        $lastSize = 0;
    }
    if ($size === $lastSize) {
        return ['project_id' => $pid, 'imported' => 0, 'skipped' => 0, 'error' => null];
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) return ['project_id' => $pid, 'imported' => 0, 'skipped' => 0,
                      'error' => 'fopen failed'];
    fseek($fh, $lastSize);

    $imported = 0; $skipped = 0;
    $ins = $db->prepare(
        'INSERT INTO site_visits (project_id, path, referrer, ua_hash, ip_hash, status, visited_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)');
    $baseSalt = 'hackmancms-site-visits';

    $db->beginTransaction();
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line === false) break;
        $row  = parseLogLine($line, $fmt);
        if (!$row) { $skipped++; continue; }
        if (!isInteresting($row, $pre)) { $skipped++; continue; }
        // Rotating salt: hash with the visit's date, so the same IP gets a
        // different hash on different days. Same-day uniques are exact;
        // cross-day visitors look like new visitors → counts cease to be PII.
        $daySalt = $baseSalt . substr($row['ts'], 0, 10);
        $uaHash = $row['ua'] ? substr(hash('sha256', $daySalt . $row['ua']), 0, 16) : null;
        $ipHash = $row['ip'] ? substr(hash('sha256', $daySalt . $row['ip']), 0, 16) : null;
        $ins->execute([$pid, $row['path'], $row['ref'] ?: null, $uaHash, $ipHash,
                       $row['status'], $row['ts']]);
        $imported++;
    }
    $newSize = ftell($fh);
    fclose($fh);
    $db->commit();

    $totalCount = (int)($cfg['analytics_imported_count'] ?? 0) + $imported;
    saveSetting($db, $pid, 'analytics_last_size',       (string)$newSize);
    saveSetting($db, $pid, 'analytics_last_inode',      (string)$inode);
    saveSetting($db, $pid, 'analytics_imported_at',     date('Y-m-d H:i:s'));
    saveSetting($db, $pid, 'analytics_imported_count',  (string)$totalCount);

    Audit::log($db, 'analytics_import', $pid, "rows=$imported skipped=$skipped");

    // Roll up + age out (throttled to once per 24h per project).
    $rollupStats = maybeRollupAndPrune($db, $pid, $cfg);
    return ['project_id' => $pid, 'imported' => $imported, 'skipped' => $skipped,
            'rollup' => $rollupStats, 'error' => null];
}

/**
 * Build hourly + daily rollups for any finalized days (date < today), then
 * age out raw events older than 90d and hourly buckets older than 365d.
 *
 *   raw events     0–90 days   — full hour-level + visit-level detail
 *   site_visits_hourly  90–365  — hour buckets, pulled from raw before drop
 *   site_visits_daily   365+    — day buckets, retained forever
 *
 * Throttled to once per 24h per project. Rollups are idempotent via
 * UNIQUE constraint on (project, bucket, path, status, referrer) +
 * INSERT OR REPLACE — re-running rolls produces the same rows.
 *
 * Aging-out is safe: a row is only dropped from raw after the daily/hourly
 * row it contributes to has been written.
 */
function maybeRollupAndPrune(PDO $db, int $pid, array $cfg): array {
    $stats = [
        'skipped'         => false,
        'hourly_buckets'  => 0,
        'daily_buckets'   => 0,
        'raw_dropped'     => 0,
        'hourly_dropped'  => 0,
    ];
    $last = $cfg['analytics_last_rollup'] ?? null;
    if ($last && strtotime($last) > time() - 86400) {
        $stats['skipped'] = true;
        return $stats;
    }

    $today = date('Y-m-d');

    // Find days in raw that aren't today; those are eligible to be rolled up.
    $first = $db->prepare(
        "SELECT MIN(date(visited_at)) FROM site_visits
         WHERE project_id = ? AND date(visited_at) < ?");
    $first->execute([$pid, $today]);
    $firstDay = $first->fetchColumn();

    if ($firstDay) {
        $hourlyIns = $db->prepare(
            "INSERT OR REPLACE INTO site_visits_hourly
               (project_id, bucket_at, path, status, referrer, views, uniques)
             SELECT ?, strftime('%Y-%m-%d %H:00:00', visited_at),
                    path, status, COALESCE(referrer, ''),
                    COUNT(*), COUNT(DISTINCT ip_hash)
             FROM site_visits
             WHERE project_id = ? AND date(visited_at) = ?
             GROUP BY strftime('%Y-%m-%d %H', visited_at), path, status, COALESCE(referrer, '')");
        $dailyIns = $db->prepare(
            "INSERT OR REPLACE INTO site_visits_daily
               (project_id, bucket_at, path, status, referrer, views, uniques)
             SELECT ?, ?, path, status, COALESCE(referrer, ''),
                    COUNT(*), COUNT(DISTINCT ip_hash)
             FROM site_visits
             WHERE project_id = ? AND date(visited_at) = ?
             GROUP BY path, status, COALESCE(referrer, '')");

        $d = $firstDay;
        $endDay = date('Y-m-d', strtotime($today . ' -1 day'));   // yesterday
        while ($d <= $endDay) {
            $hourlyIns->execute([$pid, $pid, $d]);
            $stats['hourly_buckets'] += $hourlyIns->rowCount();
            $dailyIns->execute([$pid, $d, $pid, $d]);
            $stats['daily_buckets'] += $dailyIns->rowCount();
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }

    // Raw older than 90 days — already preserved in daily + hourly rollups.
    $st = $db->prepare(
        "DELETE FROM site_visits
         WHERE project_id = ? AND visited_at < datetime('now', '-90 days')");
    $st->execute([$pid]);
    $stats['raw_dropped'] = $st->rowCount();

    // Hourly older than 365 days — already preserved in daily rollups.
    $st = $db->prepare(
        "DELETE FROM site_visits_hourly
         WHERE project_id = ? AND bucket_at < datetime('now', '-365 days')");
    $st->execute([$pid]);
    $stats['hourly_dropped'] = $st->rowCount();

    saveSetting($db, $pid, 'analytics_last_rollup', date('Y-m-d H:i:s'));
    if ($stats['hourly_buckets'] || $stats['daily_buckets']
            || $stats['raw_dropped'] || $stats['hourly_dropped']) {
        Audit::log($db, 'analytics_rollup', $pid, json_encode($stats));
    }
    return $stats;
}

function readProjectSettings(PDO $db, int $pid): array {
    $st = $db->prepare('SELECT key, value FROM project_settings WHERE project_id = ?');
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['key']] = $r['value'];
    return $out;
}

function saveSetting(PDO $db, int $pid, string $key, ?string $value): void {
    $db->prepare('INSERT OR REPLACE INTO project_settings (project_id, key, value)
                  VALUES (?, ?, ?)')->execute([$pid, $key, $value]);
}

/**
 * Parse a single log line. Combined log format and nginx default share the same
 * field layout — IP - - [ts] "REQ" status size "ref" "ua" — so one regex covers
 * both for our purposes.
 */
function parseLogLine(string $line, string $fmt): ?array {
    $line = rtrim($line);
    if ($line === '') return null;
    // Combined / nginx default.
    if (!preg_match(
        '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) [^"]*" (\d+) \S+ "([^"]*)" "([^"]*)"/',
        $line, $m
    )) return null;
    [, $ip, $tsRaw, $method, $url, $status, $ref, $ua] = $m;
    $ts = parseLogDate($tsRaw);
    if (!$ts) return null;
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    return [
        'ip'     => $ip,
        'ts'     => $ts,
        'method' => strtoupper($method),
        'path'   => $path,
        'status' => (int)$status,
        'ref'    => $ref === '-' ? '' : $ref,
        'ua'     => $ua === '-' ? '' : $ua,
    ];
}

function parseLogDate(string $raw): ?string {
    // 03/May/2026:08:34:56 +0200
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $raw);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function isInteresting(array $row, string $pathPrefix): bool {
    if ($row['method'] !== 'GET') return false;
    // Keep 2xx, 3xx, AND 404 (we want to surface broken-link hits separately).
    // Drop 5xx, 401/403/etc.
    if ($row['status'] < 200) return false;
    if ($row['status'] >= 400 && $row['status'] !== 404) return false;
    if ($pathPrefix !== '' && !str_starts_with($row['path'], $pathPrefix)) return false;

    // Skip asset extensions
    $ext = strtolower((string)pathinfo($row['path'], PATHINFO_EXTENSION));
    static $asset = ['css','js','png','jpg','jpeg','gif','webp','svg','ico',
                     'woff','woff2','ttf','eot','otf','map','mp4','webm',
                     'mp3','wav','ogg','pdf','zip','tar','gz','xml','txt'];
    if (in_array($ext, $asset, true)) return false;

    // Skip obvious bots
    $ua = strtolower((string)$row['ua']);
    foreach (['bot','crawl','spider','curl','wget','headless','scrapy','go-http','python-requests','okhttp'] as $needle) {
        if (str_contains($ua, $needle)) return false;
    }
    return true;
}
