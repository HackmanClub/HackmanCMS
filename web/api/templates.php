<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    $pid  = (int)($_GET['project_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM post_templates WHERE project_id = ? ORDER BY name');
    $stmt->execute([$pid]);
    echo json_encode(['templates' => $stmt->fetchAll()]);
    exit;
}

if ($method === 'POST') {
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        $pid     = (int)($input['project_id'] ?? 0);
        $name    = trim($input['name'] ?? '');
        $type    = $input['type'] ?? 'post';
        $content = $input['content'] ?? '';
        if (!$pid || !$name) { echo json_encode(['error' => 'project_id and name required']); exit; }
        $stmt = $db->prepare('INSERT INTO post_templates (project_id, name, type, content) VALUES (?, ?, ?, ?)');
        $stmt->execute([$pid, $name, $type, $content]);
        Audit::log($db, 'template_create', $pid, $name);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id      = (int)($input['id'] ?? 0);
        $name    = trim($input['name'] ?? '');
        $content = $input['content'] ?? '';
        if (!$id || !$name) { echo json_encode(['error' => 'id and name required']); exit; }
        $row = $db->prepare('SELECT project_id FROM post_templates WHERE id = ?');
        $row->execute([$id]);
        $pid = (int)$row->fetchColumn();
        $db->prepare('UPDATE post_templates SET name = ?, content = ? WHERE id = ?')
           ->execute([$name, $content, $id]);
        Audit::log($db, 'template_update', $pid ?: null, $name);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id required']); exit; }
        $row = $db->prepare('SELECT project_id, name FROM post_templates WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch();
        $db->prepare('DELETE FROM post_templates WHERE id = ?')->execute([$id]);
        Audit::log($db, 'template_delete', $r ? (int)$r['project_id'] : null, $r['name'] ?? null);
        echo json_encode(['ok' => true]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
