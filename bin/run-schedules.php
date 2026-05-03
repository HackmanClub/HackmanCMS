<?php
/**
 * Cron-driven scheduled build runner.
 *
 * Install once on the host (as the apache user so it can write data/):
 *   * * * * * /usr/bin/php /opt/hackmancms/bin/run-schedules.php
 *
 * Each run: enumerate enabled schedules, run any whose cron expression matches
 * the current minute (and that haven't already run within the last minute),
 * record output to command_history, update last_run_at/last_status.
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/lib/bootstrap.php';
ProjectTypes::load();

$now    = time();
$now    = $now - ($now % 60);  // truncate to minute boundary
$nowSql = date('Y-m-d H:i:s', $now);

$schedules = $db->query(
    'SELECT s.*, p.path AS project_path, p.type AS project_type
     FROM scheduled_builds s
     JOIN projects p ON p.id = s.project_id
     WHERE s.is_enabled = 1 AND p.is_active = 1'
)->fetchAll();

foreach ($schedules as $s) {
    if (!cronMatches($s['cron'], $now)) continue;
    if ($s['last_run_at'] && (strtotime($s['last_run_at']) >= $now)) continue;

    $type = ProjectTypes::get($s['project_type']);
    if (!$type) continue;
    $cmd = null;
    foreach ($type::commands() as $c) if ($c['id'] === $s['cmd_id']) { $cmd = $c; break; }
    if (!$cmd) continue;

    $base = realpath($s['project_path']);
    if (!$base || !is_dir($base)) continue;

    fwrite(STDOUT, "[$nowSql] running schedule #{$s['id']} cmd={$cmd['id']} project={$s['project_id']}\n");
    exec('cd ' . escapeshellarg($base) . ' && ' . $cmd['cmd'] . ' 2>&1', $out, $rc);
    $outText = implode("\n", $out);
    $status  = $rc === 0 ? 'ok' : ('exit=' . $rc);

    $db->prepare('INSERT INTO command_history (project_id, cmd_id, cmd, output, exit_code)
                  VALUES (?, ?, ?, ?, ?)')
       ->execute([$s['project_id'], $s['cmd_id'], $cmd['cmd'], $outText, $rc]);
    $db->prepare('UPDATE scheduled_builds SET last_run_at = ?, last_status = ? WHERE id = ?')
       ->execute([$nowSql, $status, $s['id']]);
    Audit::log($db, 'scheduled_build', $s['project_id'], "{$cmd['id']} status=$status");
    unset($out);
}

/** Match a 5-field cron expression against a unix timestamp. */
function cronMatches(string $expr, int $ts): bool {
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return false;
    [$min, $hour, $dom, $mon, $dow] = $fields;
    $t = getdate($ts);
    return cronField($min,  $t['minutes'], 0, 59)
        && cronField($hour, $t['hours'],   0, 23)
        && cronField($dom,  $t['mday'],    1, 31)
        && cronField($mon,  $t['mon'],     1, 12)
        && cronField($dow,  $t['wday'],    0, 6);  // 0=Sun
}

function cronField(string $field, int $value, int $min, int $max): bool {
    foreach (explode(',', $field) as $part) {
        $step = 1;
        if (str_contains($part, '/')) { [$part, $step] = explode('/', $part, 2); $step = max(1, (int)$step); }
        if ($part === '*' || $part === '') {
            if (($value - $min) % $step === 0) return true;
            continue;
        }
        if (str_contains($part, '-')) {
            [$a, $b] = array_map('intval', explode('-', $part, 2));
        } else {
            $a = $b = (int)$part;
        }
        for ($v = $a; $v <= $b; $v++) {
            if ((($v - $a) % $step === 0) && $v === $value) return true;
        }
    }
    return false;
}
