<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT id, path FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? 'touch';

    if ($action === 'touch') {
        $path = trim($input['path'] ?? '');
        if ($path === '') { echo json_encode(['error' => 'path required']); exit; }
        $db->prepare('INSERT INTO recent_files (project_id, path, opened_at)
                      VALUES (?, ?, CURRENT_TIMESTAMP)
                      ON CONFLICT(project_id, path) DO UPDATE SET opened_at = CURRENT_TIMESTAMP')
           ->execute([$project_id, $path]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'clear') {
        $db->prepare('DELETE FROM recent_files WHERE project_id = ?')->execute([$project_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'remove') {
        $path = trim($input['path'] ?? '');
        $db->prepare('DELETE FROM recent_files WHERE project_id = ? AND path = ?')
           ->execute([$project_id, $path]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET — list recent files, prune missing ones lazily
$limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
$rows  = $db->prepare('SELECT path, opened_at FROM recent_files
                       WHERE project_id = ? ORDER BY opened_at DESC LIMIT ?');
$rows->execute([$project_id, $limit]);
$base = realpath($project['path']);
$out  = [];
$pruned = [];
foreach ($rows->fetchAll() as $r) {
    $abs = $base ? $base . '/' . ltrim($r['path'], '/') : null;
    if (!$abs || !is_file($abs)) {
        $pruned[] = $r['path'];
        continue;
    }
    $out[] = [
        'path'      => $r['path'],
        'name'      => basename($r['path']),
        'dir'       => dirname($r['path']) === '.' ? '' : dirname($r['path']),
        'opened_at' => $r['opened_at'],
        'size'      => @filesize($abs),
        'modified'  => @filemtime($abs),
    ];
}
if ($pruned) {
    $del = $db->prepare('DELETE FROM recent_files WHERE project_id = ? AND path = ?');
    foreach ($pruned as $p) $del->execute([$project_id, $p]);
}
echo json_encode(['items' => $out]);
