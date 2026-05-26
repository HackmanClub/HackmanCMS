<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)(($method === 'GET' ? $_GET : (json_decode(file_get_contents('php://input'), true) ?? []))['project_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

// Service name from project settings (default: bashybot)
$psStmt = $db->prepare('SELECT value FROM project_settings WHERE project_id = ? AND key = ?');
$psStmt->execute([$project_id, 'bot_service_name']);
$row = $psStmt->fetch();
$service = $row ? $row['value'] : 'bashybot';
$service = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $service); // sanitize

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
    if ($action !== 'status') { http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit; }

    exec('systemctl is-active ' . escapeshellarg($service) . ' 2>&1', $activeOut, $activeCode);
    $isActive = trim(implode('', $activeOut)) === 'active';

    exec('systemctl show ' . escapeshellarg($service) . ' --property=ActiveEnterTimestamp --value 2>&1', $tsOut);
    $since = trim(implode('', $tsOut));

    echo json_encode([
        'active'   => $isActive,
        'status'   => trim(implode('', $activeOut)),
        'since'    => $since,
        'service'  => $service,
    ]);
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

$allowed = ['start', 'stop', 'restart'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$output    = [];
$exit_code = 0;
exec('sudo systemctl ' . $action . ' ' . escapeshellarg($service) . ' 2>&1', $output, $exit_code);

Audit::log($db, 'bot_' . $action, $project_id, $service);

// Return updated status after action
exec('systemctl is-active ' . escapeshellarg($service) . ' 2>&1', $activeOut);
$isActive = trim(implode('', $activeOut)) === 'active';

echo json_encode([
    'ok'       => $exit_code === 0,
    'action'   => $action,
    'output'   => implode("\n", $output),
    'active'   => $isActive,
    'status'   => trim(implode('', $activeOut)),
]);
