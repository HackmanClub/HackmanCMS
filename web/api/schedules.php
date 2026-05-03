<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT id FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

if ($method === 'GET') {
    $rows = $db->prepare('SELECT * FROM scheduled_builds WHERE project_id = ? ORDER BY id');
    $rows->execute([$project_id]);
    echo json_encode(['items' => $rows->fetchAll()]);
    exit;
}

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        $cmdId = trim($input['cmd_id'] ?? '');
        $cron  = trim($input['cron'] ?? '');
        $en    = !empty($input['is_enabled']) ? 1 : 0;
        if (!$cmdId || !$cron) { echo json_encode(['error' => 'cmd_id and cron required']); exit; }
        $db->prepare('INSERT INTO scheduled_builds (project_id, cmd_id, cron, is_enabled)
                      VALUES (?, ?, ?, ?)')->execute([$project_id, $cmdId, $cron, $en]);
        $sid = (int)$db->lastInsertId();
        Audit::log($db, 'schedule_create', $project_id, $cmdId . ' "' . $cron . '"');
        echo json_encode(['ok' => true, 'id' => $sid]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id required']); exit; }
        $sets = []; $args = [];
        foreach (['cmd_id', 'cron'] as $k) {
            if (array_key_exists($k, $input)) { $sets[] = "$k = ?"; $args[] = trim($input[$k]); }
        }
        if (array_key_exists('is_enabled', $input)) {
            $sets[] = 'is_enabled = ?'; $args[] = $input['is_enabled'] ? 1 : 0;
        }
        if (!$sets) { echo json_encode(['ok' => true]); exit; }
        $args[] = $id; $args[] = $project_id;
        $db->prepare('UPDATE scheduled_builds SET ' . implode(', ', $sets)
                     . ' WHERE id = ? AND project_id = ?')->execute($args);
        Audit::log($db, 'schedule_update', $project_id, 'id=' . $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        $db->prepare('DELETE FROM scheduled_builds WHERE id = ? AND project_id = ?')
           ->execute([$id, $project_id]);
        Audit::log($db, 'schedule_delete', $project_id, 'id=' . $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
