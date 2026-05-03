<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$st = $db->prepare('SELECT id FROM projects WHERE id = ? AND is_active = 1');
$st->execute([$project_id]);
if (!$st->fetch()) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? 'run';

if ($action === 'reset') {
    $db->prepare('DELETE FROM project_settings WHERE project_id = ?
                  AND key IN ("analytics_last_size", "analytics_last_inode")')
       ->execute([$project_id]);
    Audit::log($db, 'analytics_reset', $project_id);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'wipe') {
    // Drop all collected visits for this project and clear cursors.
    $db->prepare('DELETE FROM site_visits WHERE project_id = ?')->execute([$project_id]);
    $db->prepare('DELETE FROM project_settings WHERE project_id = ?
                  AND key IN ("analytics_last_size", "analytics_last_inode",
                              "analytics_imported_at", "analytics_imported_count")')
       ->execute([$project_id]);
    Audit::log($db, 'analytics_wipe', $project_id);
    echo json_encode(['ok' => true]);
    exit;
}

@set_time_limit(300);
require_once ROOT . '/bin/import-site-logs.php';
$res = importOne($db, $project_id);
echo json_encode($res + ['ok' => $res['error'] === null]);
