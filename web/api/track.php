<?php
// Public visit-tracking endpoint. Whitelisted in index.php; no session required.

$pid  = (int)($_GET['p'] ?? 0);
$path = substr(trim((string)($_GET['path'] ?? '/')), 0, 512);
$ref  = substr(trim((string)($_GET['ref'] ?? '')), 0, 512);
$ua   = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// Truncated salted hashes to count uniques without storing raw values
$salt = 'hackmancms-site-visits';
$uaHash = $ua ? substr(hash('sha256', $salt . $ua), 0, 16) : null;
$ipHash = $ip ? substr(hash('sha256', $salt . $ip), 0, 16) : null;

// Verify the project exists and is active
$st = $db->prepare('SELECT id FROM projects WHERE id = ? AND is_active = 1');
$st->execute([$pid]);
if ($st->fetch()) {
    try {
        $db->prepare('INSERT INTO site_visits (project_id, path, referrer, ua_hash, ip_hash)
                      VALUES (?, ?, ?, ?, ?)')
           ->execute([$pid, $path, $ref ?: null, $uaHash, $ipHash]);
    } catch (Exception $e) { /* don't fail the pixel on logging error */ }
}

// 1x1 transparent PNG, cache-busted by request
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
echo base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAYAAjCB0C8AAAAASUVORK5CYII='
);
