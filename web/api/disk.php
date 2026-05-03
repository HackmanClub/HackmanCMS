<?php
header('Content-Type: application/json');

$ids = $_GET['ids'] ?? '';
if ($ids !== '') {
    // Bulk: ?ids=1,2,3
    $idList = array_filter(array_map('intval', explode(',', $ids)));
    if (!$idList) { echo json_encode(['items' => []]); exit; }
    $place = implode(',', array_fill(0, count($idList), '?'));
    $stmt = $db->prepare("SELECT id, path FROM projects WHERE is_active = 1 AND id IN ($place)");
    $stmt->execute($idList);
    $out = [];
    foreach ($stmt->fetchAll() as $p) {
        $out[(int)$p['id']] = diskUsage($p['path']);
    }
    echo json_encode(['items' => $out]);
    exit;
}

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT id, path FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

echo json_encode(['size' => diskUsage($project['path'])]);

function diskUsage(?string $path): ?array {
    if (!$path) return null;
    $real = realpath($path);
    if (!$real || !is_dir($real)) return null;
    // GNU du -sb with excludes; bytes for accuracy
    $cmd = 'du -sb --exclude=node_modules --exclude=public --exclude=.git '
         . escapeshellarg($real) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out === null) return null;
    $bytes = (int)strtok(trim($out), "\t");
    return ['bytes' => $bytes, 'human' => humanBytes($bytes)];
}

function humanBytes(int $b): string {
    if ($b < 1024)               return $b . ' B';
    if ($b < 1024 * 1024)        return number_format($b / 1024, 1) . ' KB';
    if ($b < 1024 * 1024 * 1024) return number_format($b / 1024 / 1024, 1) . ' MB';
    return number_format($b / 1024 / 1024 / 1024, 2) . ' GB';
}
