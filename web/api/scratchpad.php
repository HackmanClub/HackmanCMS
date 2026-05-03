<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT id, scratchpad FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

if ($method === 'GET') {
    echo json_encode(['content' => (string)$row['scratchpad']]);
    exit;
}

if ($method === 'POST') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = (string)($input['content'] ?? '');
    $db->prepare('UPDATE projects SET scratchpad = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$content, $project_id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
