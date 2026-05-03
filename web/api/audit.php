<?php
header('Content-Type: application/json');

$limit  = min(200, (int)($_GET['limit'] ?? 50));
$offset = max(0,   (int)($_GET['offset'] ?? 0));
$pid    = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;

if ($pid) {
    $stmt = $db->prepare(
        'SELECT a.*, u.username FROM audit_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.project_id = ?
         ORDER BY a.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$pid, $limit, $offset]);
    $cnt = $db->prepare('SELECT COUNT(*) FROM audit_log WHERE project_id = ?');
    $cnt->execute([$pid]);
} else {
    $stmt = $db->prepare(
        'SELECT a.*, u.username, p.name AS project_name FROM audit_log a
         LEFT JOIN users u ON u.id = a.user_id
         LEFT JOIN projects p ON p.id = a.project_id
         ORDER BY a.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$limit, $offset]);
    $cnt = $db->query('SELECT COUNT(*) FROM audit_log');
}

echo json_encode([
    'entries' => $stmt->fetchAll(),
    'total'   => (int)$cnt->fetchColumn(),
    'limit'   => $limit,
    'offset'  => $offset,
]);
