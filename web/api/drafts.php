<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

$pid  = (int)($_GET['project_id'] ?? $input['project_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $rows = $db->prepare('SELECT * FROM drafts WHERE project_id = ? ORDER BY updated_at DESC');
    $rows->execute([$pid]);
    echo json_encode(['drafts' => $rows->fetchAll()]);
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$action = $input['action'] ?? 'create';

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title  = trim($input['title'] ?? '');
    $slug   = trim($input['slug'] ?? '') ?: slugify($title);
    $folder = trim($input['folder'] ?? '');
    $fm     = $input['frontmatter'] ?? '';
    $body   = $input['body'] ?? '';
    if (!$title) { echo json_encode(['error' => 'Title required']); exit; }
    $stmt = $db->prepare(
        'INSERT INTO drafts (project_id, title, slug, folder, frontmatter, body) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$pid, $title, $slug, $folder, $fm, $body]);
    $newId = (int)$db->lastInsertId();
    Audit::log($db, 'draft_create', $pid, $title);
    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id     = (int)($input['id'] ?? 0);
    $title  = trim($input['title'] ?? '');
    $slug   = trim($input['slug'] ?? '');
    $folder = trim($input['folder'] ?? '');
    $fm     = $input['frontmatter'] ?? '';
    $body   = $input['body'] ?? '';
    if (!$id) { echo json_encode(['error' => 'id required']); exit; }
    $db->prepare(
        'UPDATE drafts SET title=?, slug=?, folder=?, frontmatter=?, body=?,
         updated_at=CURRENT_TIMESTAMP WHERE id=? AND project_id=?'
    )->execute([$title, $slug, $folder, $fm, $body, $id, $pid]);
    Audit::log($db, 'draft_update', $pid, $title);
    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $row = $db->prepare('SELECT title FROM drafts WHERE id = ? AND project_id = ?');
    $row->execute([$id, $pid]);
    $title = (string)$row->fetchColumn();
    $db->prepare('DELETE FROM drafts WHERE id = ? AND project_id = ?')->execute([$id, $pid]);
    Audit::log($db, 'draft_delete', $pid, $title);
    echo json_encode(['ok' => true]);
    exit;
}

// ── PUBLISH (write to _posts, delete from DB) ─────────────────────────────────
if ($action === 'publish') {
    $id    = (int)($input['id'] ?? 0);
    $stmt  = $db->prepare('SELECT * FROM drafts WHERE id = ? AND project_id = ?');
    $stmt->execute([$id, $pid]);
    $draft = $stmt->fetch();
    if (!$draft) { echo json_encode(['error' => 'Draft not found']); exit; }

    $base      = realpath($project['path']);
    $posts_dir = $base . '/source/_posts';
    $target_dir = $draft['folder']
        ? $posts_dir . '/' . ltrim($draft['folder'], '/')
        : $posts_dir;

    $real_t = realpath($target_dir) ?: $target_dir;
    if (realpath($posts_dir) && !str_starts_with($real_t . '/', realpath($posts_dir) . '/')) {
        http_response_code(403); echo json_encode(['error' => 'Invalid folder']); exit;
    }
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

    $filename = ($draft['slug'] ?: slugify($draft['title'])) . '.md';
    $filepath = $target_dir . '/' . $filename;
    $fm = "---\ntitle: \"" . addslashes($draft['title']) . "\"\ndate: " . date('Y-m-d H:i:s') . "\n---";
    file_put_contents($filepath, $fm . "\n\n" . $draft['body']);

    $db->prepare('DELETE FROM drafts WHERE id = ?')->execute([$id]);
    $rel = 'source/_posts/' . ($draft['folder'] ? ltrim($draft['folder'], '/') . '/' : '') . $filename;
    Audit::log($db, 'draft_publish', $pid, $rel);
    echo json_encode(['ok' => true, 'path' => $rel, 'filename' => $filename]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);

function slugify(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'draft-' . time();
}
