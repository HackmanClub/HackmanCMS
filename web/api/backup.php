<?php
$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    exit;
}

$base = realpath($project['path']);
if (!$base || !is_dir($base)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Project path not accessible']);
    exit;
}

$excludes = ['node_modules', 'public', '.git'];

// Build ZIP into a temp file then stream
$tmp = tempnam(sys_get_temp_dir(), 'hexbk');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Cannot open zip for writing']);
    exit;
}

$baseLen = strlen($base) + 1;
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($base,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        function ($current) use ($excludes, $base) {
            $name = $current->getFilename();
            if ($current->isDir() && in_array($name, $excludes, true)) return false;
            return true;
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $file) {
    $abs   = $file->getPathname();
    $local = substr($abs, $baseLen);
    if ($local === '') continue;
    if ($file->isDir()) {
        $zip->addEmptyDir($local);
    } else {
        $zip->addFile($abs, $local);
    }
}
$zip->close();

Audit::log($db, 'backup_download', $project_id);

$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $project['name']) ?: 'project';
$filename = $slug . '_' . date('Y-m-d_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
