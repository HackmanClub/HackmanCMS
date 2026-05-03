<?php
header('Content-Type: application/json');

$project_id = (int)($_GET['project_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

$base = realpath($project['path'] . '/source');
if (!$base || !is_dir($base)) { echo json_encode(['tags' => [], 'categories' => []]); exit; }

$tags       = [];
$categories = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
);
foreach ($it as $file) {
    if ($file->getExtension() !== 'md') continue;
    $content = file_get_contents($file->getPathname());
    $fm      = parseFm($content);
    foreach ($fm['tags'] as $t)   $tags[$t]       = ($tags[$t] ?? 0) + 1;
    foreach ($fm['cats'] as $c)   $categories[$c] = ($categories[$c] ?? 0) + 1;
}

arsort($tags); arsort($categories);
echo json_encode(['tags' => $tags, 'categories' => $categories]);

function parseFm(string $content): array {
    $tags = []; $cats = [];
    if (!str_starts_with($content, '---')) return compact('tags', 'cats');
    $end  = strpos($content, '---', 3);
    if (!$end) return compact('tags', 'cats');
    $yaml = substr($content, 3, $end - 3);

    // tags: [a, b, c] or tags:\n  - a\n  - b
    if (preg_match('/^tags:\s*\[(.+)\]/m', $yaml, $m)) {
        $tags = array_map('trim', explode(',', $m[1]));
    } elseif (preg_match('/^tags:\s*\n((?:\s+-\s*.+\n?)+)/m', $yaml, $m)) {
        preg_match_all('/^\s+-\s*(.+)$/m', $m[1], $items);
        $tags = array_map('trim', $items[1]);
    }

    if (preg_match('/^categories:\s*\[(.+)\]/m', $yaml, $m)) {
        $cats = array_map('trim', explode(',', $m[1]));
    } elseif (preg_match('/^categories:\s*\n((?:\s+-\s*.+\n?)+)/m', $yaml, $m)) {
        preg_match_all('/^\s+-\s*(.+)$/m', $m[1], $items);
        $cats = array_map('trim', $items[1]);
    } elseif (preg_match('/^category:\s*(.+)$/m', $yaml, $m)) {
        $cats = [trim($m[1])];
    }

    $tags = array_filter(array_map('trim', $tags));
    $cats = array_filter(array_map('trim', $cats));
    return compact('tags', 'cats');
}
