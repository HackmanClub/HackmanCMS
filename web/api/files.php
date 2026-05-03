<?php
header('Content-Type: application/json');

$project_id = (int)($_GET['project_id'] ?? 0);
$method     = $_SERVER['REQUEST_METHOD'];

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base = realpath($project['path']);
if (!$base || !is_dir($base)) { echo json_encode(['error' => 'Project path not accessible']); exit; }

// POST actions read params from JSON body
if ($method === 'POST') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $action   = $input['action'] ?? 'write';
    $rel_path = $input['path'] ?? '';
} else {
    $input    = [];
    $rel_path = $_GET['path'] ?? '';
    $action   = $_GET['action'] ?? 'list';
}

function safeTarget(string $base, string $rel): string|false {
    if ($rel === '' || $rel === '.') return $base;
    $candidate = $base . '/' . ltrim($rel, '/');
    if (file_exists($candidate)) {
        $real = realpath($candidate);
        return ($real && str_starts_with($real . '/', $base . '/')) ? $real : false;
    }
    // File doesn't exist yet (write) — validate parent
    $parentReal = realpath(dirname($candidate));
    return ($parentReal && str_starts_with($parentReal . '/', $base . '/')) ? $candidate : false;
}

$target = safeTarget($base, $rel_path);

function touchRecent(PDO $db, int $pid, string $rel): void {
    if ($rel === '' || $rel === '.') return;
    $db->prepare('INSERT INTO recent_files (project_id, path, opened_at)
                  VALUES (?, ?, CURRENT_TIMESTAMP)
                  ON CONFLICT(project_id, path) DO UPDATE SET opened_at = CURRENT_TIMESTAMP')
       ->execute([$pid, $rel]);
}

// ── WRITE ─────────────────────────────────────────────────────────────────────
if ($action === 'write' && $method === 'POST') {
    if ($target === false) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }
    $dir = dirname($target);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($target, $input['content'] ?? '');
    touchRecent($db, $project_id, $rel_path);
    Audit::log($db, 'file_write', $project_id, $rel_path);
    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE file ───────────────────────────────────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    if ($target === false || !is_file($target)) { echo json_encode(['error' => 'File not found']); exit; }
    unlink($target);
    Audit::log($db, 'file_delete', $project_id, $rel_path);
    echo json_encode(['ok' => true]);
    exit;
}

// ── SERVE (proxy file with correct content-type) ──────────────────────────────
if ($action === 'serve') {
    if ($target === false || !is_file($target)) { http_response_code(404); exit; }
    $ext   = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',  'png' => 'image/png',
        'gif'  => 'image/gif',   'webp' => 'image/webp',  'svg' => 'image/svg+xml',
        'mp4'  => 'video/mp4',   'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',  'wav'  => 'audio/wav',   'ogg' => 'audio/ogg',
        'pdf'  => 'application/pdf',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($target));
    header('Cache-Control: max-age=3600');
    readfile($target);
    exit;
}

// ── READ ──────────────────────────────────────────────────────────────────────
if ($action === 'read') {
    if ($target === false || !is_file($target)) { echo json_encode(['error' => 'Not a file']); exit; }
    if (filesize($target) > 512 * 1024) { echo json_encode(['error' => 'File too large (>512 KB)']); exit; }
    $content = file_get_contents($target);
    if ($content === false) { echo json_encode(['error' => 'Cannot read file — check permissions']); exit; }
    touchRecent($db, $project_id, $rel_path);
    $json = json_encode(['content' => $content]);
    if ($json === false) {
        // Non-UTF-8 bytes — substitute replacement characters
        $json = json_encode(['content' => $content], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json ?? json_encode(['error' => 'Cannot encode file content']);
    exit;
}

// ── LIST (default) ────────────────────────────────────────────────────────────
if ($target === false || !is_dir($target)) { echo json_encode(['error' => 'Not a directory']); exit; }

$entries = [];
foreach (scandir($target) as $item) {
    if ($item === '.' || $item === '..') continue;
    $full = $target . '/' . $item;
    $rel  = $rel_path ? rtrim($rel_path, '/') . '/' . $item : $item;
    $entries[] = [
        'name'     => $item,
        'type'     => is_dir($full) ? 'dir' : 'file',
        'size'     => is_file($full) ? filesize($full) : null,
        'modified' => filemtime($full),
        'path'     => $rel,
    ];
}
usort($entries, fn($a, $b) =>
    $a['type'] !== $b['type'] ? ($a['type'] === 'dir' ? -1 : 1) : strcmp($a['name'], $b['name'])
);
echo json_encode(['entries' => $entries]);
