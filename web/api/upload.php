<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'POST required']); exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
$folder     = trim($_POST['folder'] ?? '');

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base = realpath($project['path']);
if (!$base) { echo json_encode(['error' => 'Project path not accessible']); exit; }

// Validate target folder (may not exist yet)
$target_dir = $folder ? $base . '/' . ltrim($folder, '/') : $base;
$real_dir   = realpath($target_dir);
if ($real_dir) {
    if (!str_starts_with($real_dir . '/', $base . '/') && $real_dir !== $base) {
        http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
    }
} else {
    // Directory doesn't exist yet — validate parent
    $parent = realpath(dirname($target_dir));
    if (!$parent || !str_starts_with($parent . '/', $base . '/') && $parent !== $base) {
        http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
    }
    mkdir($target_dir, 0755, true);
    $real_dir = realpath($target_dir);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload failed (error ' . ($_FILES['file']['error'] ?? '?') . ')']);
    exit;
}

$file = $_FILES['file'];

// Validate MIME type
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
$accept  = $_POST['accept'] ?? 'image';
if ($accept === 'image') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        echo json_encode(['error' => 'Only image files allowed']); exit;
    }
}

$safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
$dest      = $real_dir . '/' . $safe_name;

// Avoid overwriting
if (file_exists($dest)) {
    $info      = pathinfo($safe_name);
    $safe_name = $info['filename'] . '_' . time() . '.' . ($info['extension'] ?? '');
    $dest      = $real_dir . '/' . $safe_name;
}

move_uploaded_file($file['tmp_name'], $dest);

// Optimize: resize images wider than 2000px
if (isset($mime) && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    optimizeImage($dest, $mime);
}

$rel_path = ltrim(str_replace($base, '', $dest), '/');
$url      = $project['url'] ? rtrim($project['url'], '/') . '/' . $rel_path : null;

Audit::log($db, 'file_upload', $project_id, $rel_path);

echo json_encode([
    'ok'       => true,
    'filename' => $safe_name,
    'path'     => $rel_path,
    'url'      => $url,
]);

function optimizeImage(string $path, string $mime): void {
    if (!function_exists('imagecreatefromjpeg')) return;
    $img = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
    if (!$img) return;
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 2000) { imagedestroy($img); return; }
    $nw      = 2000;
    $nh      = (int)round($h * 2000 / $w);
    $resized = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    match($mime) {
        'image/jpeg' => imagejpeg($resized, $path, 85),
        'image/png'  => imagepng($resized, $path, 8),
        'image/webp' => imagewebp($resized, $path, 85),
    };
    imagedestroy($img);
    imagedestroy($resized);
}
