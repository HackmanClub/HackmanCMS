<?php
header('Content-Type: application/json');

$project_id = (int)($_GET['project_id'] ?? 0);
$q          = trim($_GET['q'] ?? '');

if (!$q) { echo json_encode(['results' => []]); exit; }

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

$base = realpath($project['path']);
if (!$base) { echo json_encode(['error' => 'Path not accessible']); exit; }

$output  = [];
exec('grep -rn --include="*.md" -i ' . escapeshellarg($q) . ' ' . escapeshellarg($base) . '/source 2>/dev/null', $output);

$results = [];
foreach ($output as $line) {
    if (!preg_match('#^(.+\.md):(\d+):(.+)$#', $line, $m)) continue;
    $file      = ltrim(str_replace($base, '', $m[1]), '/');
    $results[] = [
        'file'    => $file,
        'line'    => (int)$m[2],
        'content' => trim($m[3]),
    ];
    if (count($results) >= 100) break;
}

echo json_encode(['results' => $results, 'query' => $q]);
