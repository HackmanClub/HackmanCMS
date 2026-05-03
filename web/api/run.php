<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET: return command history for a project
if ($method === 'GET') {
    $pid  = (int)($_GET['project_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT * FROM command_history WHERE project_id = ? ORDER BY run_at DESC LIMIT 50'
    );
    $stmt->execute([$pid]);
    echo json_encode(['history' => $stmt->fetchAll()]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$project_id = (int)($input['project_id'] ?? 0);
$cmd_id     = $input['cmd'] ?? '';

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$type = ProjectTypes::get($project['type']);
if (!$type) { echo json_encode(['error' => 'Unknown project type']); exit; }

// Find the matching whitelisted command
$cmd = null;
foreach ($type::commands() as $c) {
    if ($c['id'] === $cmd_id) { $cmd = $c; break; }
}
if (!$cmd) { http_response_code(400); echo json_encode(['error' => 'Unknown command']); exit; }

$path = realpath($project['path']);
if (!$path || !is_dir($path)) {
    echo json_encode(['error' => 'Project path not accessible on this server']);
    exit;
}

$output    = [];
$exit_code = 0;
exec('cd ' . escapeshellarg($path) . ' && ' . $cmd['cmd'] . ' 2>&1', $output, $exit_code);

$outText = implode("\n", $output);
$db->prepare('INSERT INTO command_history (project_id, cmd_id, cmd, output, exit_code) VALUES (?, ?, ?, ?, ?)')
   ->execute([$project_id, $cmd_id, $cmd['cmd'], $outText, $exit_code]);

Audit::log($db, 'command_run', $project_id, $cmd_id . ' exit=' . $exit_code);

echo json_encode([
    'exit_code' => $exit_code,
    'output'    => $outText,
    'cmd'       => $cmd['cmd'],
]);
