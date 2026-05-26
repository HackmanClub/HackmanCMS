<?php
header('Content-Type: application/json');

$pid = (int)($_GET['project_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

// Log file path from project settings (default: logs/bashybot.log)
$psStmt = $db->prepare('SELECT value FROM project_settings WHERE project_id = ? AND key = ?');
$psStmt->execute([$pid, 'bot_log_file']);
$row     = $psStmt->fetch();
$rel     = $row ? $row['value'] : 'logs/bashybot.log';

$base    = realpath($project['path']);
$logPath = realpath($base . '/' . ltrim($rel, '/'));

if (!$logPath || strpos($logPath, $base) !== 0 || !is_file($logPath)) {
    echo json_encode(['lines' => [], 'error' => 'Log file not found: ' . htmlspecialchars($rel)]);
    exit;
}

$lines = (int)($_GET['lines'] ?? 100);
$lines = max(10, min(500, $lines));

$output = [];
exec('tail -n ' . $lines . ' ' . escapeshellarg($logPath) . ' 2>&1', $output);

echo json_encode(['lines' => $output]);
