<?php
header('Content-Type: application/json');

$project_id = (int)($_GET['project_id'] ?? 0);
$st = $db->prepare('SELECT id FROM projects WHERE id = ? AND is_active = 1');
$st->execute([$project_id]);
if (!$st->fetch()) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$days = max(1, min(3650, (int)($_GET['days'] ?? 7)));

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
$sinceD   = date('Y-m-d', strtotime($today . " -{$days} days"));    // window start (date)
$prevFrom = date('Y-m-d', strtotime($today . " -" . ($days * 2) . " days"));
$prevTo   = $sinceD;
$startTs  = $sinceD . ' 00:00:00';                                  // for raw bound

// ---- helpers --------------------------------------------------------------
// "Today's" raw stats — raw is authoritative for the current day; everything
// else comes from the daily rollup (which is built once per day in import).
function rawTotalsToday(PDO $db, int $pid, string $today): array {
    $q = $db->prepare(
        "SELECT COUNT(*) AS views, COUNT(DISTINCT ip_hash) AS uniques
         FROM site_visits WHERE project_id = ? AND date(visited_at) = ? AND status < 400");
    $q->execute([$pid, $today]);
    $r = $q->fetch();
    return ['views' => (int)($r['views'] ?? 0), 'uniques' => (int)($r['uniques'] ?? 0)];
}

function dailyTotalsRange(PDO $db, int $pid, string $fromDate, string $toDateExcl): array {
    // SUM views + uniques across rollup rows (per-day uniques summed; cross-
    // day overlap not deduped — by design, since the daily-rotating salt
    // makes cross-day visitors look like new visitors).
    $q = $db->prepare(
        "SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(uniques),0) AS uniques
         FROM site_visits_daily
         WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status < 400");
    $q->execute([$pid, $fromDate, $toDateExcl]);
    $r = $q->fetch();
    return ['views' => (int)($r['views'] ?? 0), 'uniques' => (int)($r['uniques'] ?? 0)];
}

// Window totals = today (raw) + (since..today) (daily rollup). When the window
// includes today, raw covers it. When the window is fully past, raw isn't used.
$tot = rawTotalsToday($db, $project_id, $today);
$totDaily = dailyTotalsRange($db, $project_id, $sinceD, $today);
$totals = [
    'views'   => $tot['views']   + $totDaily['views'],
    'uniques' => $tot['uniques'] + $totDaily['uniques'],
];

// Previous-period totals — fully past, daily rollup only.
$prevTotals = dailyTotalsRange($db, $project_id, $prevFrom, $prevTo);

// All-time
$all = $db->prepare(
    "SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(uniques),0) AS uniques,
            MIN(bucket_at) AS first_seen, MAX(bucket_at) AS last_seen
     FROM site_visits_daily WHERE project_id = ? AND status < 400");
$all->execute([$project_id]);
$allRow = $all->fetch();
$rawAll = $db->prepare(
    "SELECT COUNT(*) AS views, COUNT(DISTINCT ip_hash) AS uniques,
            MIN(date(visited_at)) AS first_seen, MAX(date(visited_at)) AS last_seen
     FROM site_visits WHERE project_id = ? AND status < 400");
$rawAll->execute([$project_id]);
$rawAllRow = $rawAll->fetch();
$allTime = [
    'views'      => (int)$allRow['views'] + (int)($rawAllRow['views'] ?? 0),
    'uniques'    => (int)$allRow['uniques'] + (int)($rawAllRow['uniques'] ?? 0),
    'first_seen' => $allRow['first_seen'] ?: $rawAllRow['first_seen'],
    'last_seen'  => max($allRow['last_seen'] ?? '', $rawAllRow['last_seen'] ?? '') ?: null,
];

// Top pages — UNION raw(today) + daily(since..today), aggregate by path.
$topPages = $db->prepare(
    "SELECT path, SUM(views) AS views, SUM(uniques) AS uniques
     FROM (
       SELECT path, COUNT(*) AS views, COUNT(DISTINCT ip_hash) AS uniques
       FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? AND status < 400
       GROUP BY path
       UNION ALL
       SELECT path, views, uniques
       FROM site_visits_daily
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status < 400
     )
     GROUP BY path ORDER BY views DESC LIMIT 20");
$topPages->execute([$project_id, $today, $project_id, $sinceD, $today]);

// Top referrers
$topRefs = $db->prepare(
    "SELECT referrer, SUM(views) AS views FROM (
       SELECT COALESCE(referrer, '') AS referrer, COUNT(*) AS views
       FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? AND status < 400
         AND referrer IS NOT NULL AND referrer != ''
       GROUP BY referrer
       UNION ALL
       SELECT referrer, views FROM site_visits_daily
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ?
         AND status < 400 AND referrer != ''
     )
     GROUP BY referrer ORDER BY views DESC LIMIT 20");
$topRefs->execute([$project_id, $today, $project_id, $sinceD, $today]);

// Daily series (current period) — today from raw, prior days from daily rollup.
$series = $db->prepare(
    "SELECT day, SUM(views) AS views, SUM(uniques) AS uniques FROM (
       SELECT date(visited_at) AS day, COUNT(*) AS views, COUNT(DISTINCT ip_hash) AS uniques
       FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? AND status < 400
       GROUP BY day
       UNION ALL
       SELECT bucket_at AS day, views, uniques FROM site_visits_daily
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status < 400
     )
     GROUP BY day ORDER BY day");
$series->execute([$project_id, $today, $project_id, $sinceD, $today]);

// Previous-period daily series (for chart overlay) — daily rollup only.
$prevSeries = $db->prepare(
    "SELECT bucket_at AS day, SUM(views) AS views FROM site_visits_daily
     WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status < 400
     GROUP BY bucket_at ORDER BY bucket_at");
$prevSeries->execute([$project_id, $prevFrom, $prevTo]);

// Hour-of-day — raw (today) + hourly rollup (other days). Days only in daily
// rollup don't contribute to this distribution (we lose sub-day timestamps
// after 365d). For most query windows that's fine.
$hours = $db->prepare(
    "SELECT hour, SUM(views) AS views FROM (
       SELECT CAST(strftime('%H', visited_at) AS INTEGER) AS hour, COUNT(*) AS views
       FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? AND status < 400
       GROUP BY hour
       UNION ALL
       SELECT CAST(strftime('%H', bucket_at) AS INTEGER) AS hour, SUM(views) AS views
       FROM site_visits_hourly
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status < 400
       GROUP BY hour
     )
     GROUP BY hour ORDER BY hour");
$hours->execute([$project_id, $today, $project_id, $startTs, $tomorrow]);

// Top 404s
$top404s = $db->prepare(
    "SELECT path, SUM(hits) AS hits, MAX(last_hit) AS last_hit FROM (
       SELECT path, COUNT(*) AS hits, MAX(visited_at) AS last_hit
       FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? AND status = 404
       GROUP BY path
       UNION ALL
       SELECT path, views AS hits, bucket_at AS last_hit FROM site_visits_daily
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? AND status = 404
     )
     GROUP BY path ORDER BY hits DESC LIMIT 20");
$top404s->execute([$project_id, $today, $project_id, $sinceD, $today]);

// Status mix
$statusMix = $db->prepare(
    "SELECT status, SUM(views) AS views FROM (
       SELECT status, COUNT(*) AS views FROM site_visits
       WHERE project_id = ? AND date(visited_at) = ? GROUP BY status
       UNION ALL
       SELECT status, SUM(views) AS views FROM site_visits_daily
       WHERE project_id = ? AND bucket_at >= ? AND bucket_at < ? GROUP BY status
     )
     GROUP BY status ORDER BY views DESC");
$statusMix->execute([$project_id, $today, $project_id, $sinceD, $today]);

echo json_encode([
    'days'        => $days,
    'window'      => $totals,
    'previous'    => $prevTotals,
    'all_time'    => $allTime,
    'top_pages'   => $topPages->fetchAll(),
    'top_refs'    => $topRefs->fetchAll(),
    'series'      => $series->fetchAll(),
    'prev_series' => $prevSeries->fetchAll(),
    'hours'       => $hours->fetchAll(),
    'top_404s'    => $top404s->fetchAll(),
    'status_mix'  => $statusMix->fetchAll(),
    'notes'       => [
        'unique_semantic' => 'sum of per-day unique visitors (daily-rotating salt) — same person across days counts once per day',
    ],
]);
