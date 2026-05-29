<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$pid    = (int)(($method === 'GET' ? $_GET : $input)['project_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base        = realpath($project['path']);
$config_path = $base . '/data/config.json';

function ps_read_config(string $path): array {
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // Return available targets for the schedule form
    if ($action === 'targets') {
        $config = ps_read_config($config_path);
        $discord  = [];
        $mastodon = [];
        $linkedin = [];
        foreach ($config['discord_channels'] ?? [] as $key => $ch) {
            $discord[] = ['key' => $key, 'label' => $ch['label'] ?? $key];
        }
        foreach ($config['mastodon'] ?? [] as $key => $acc) {
            $mastodon[] = ['key' => $key, 'label' => $acc['label'] ?? $key];
        }
        foreach ($config['linkedin']['pages'] ?? [] as $key => $page) {
            // Only personal profiles can post — org pages not supported by LinkedIn API
            if (($page['type'] ?? 'organization') === 'personal') {
                $linkedin[] = ['key' => $key, 'label' => $page['label'] ?? $key];
            }
        }
        echo json_encode(['discord' => $discord, 'mastodon' => $mastodon, 'linkedin' => $linkedin]);
        exit;
    }

    // List posts with their targets
    $rows = $db->prepare(
        'SELECT * FROM scheduled_posts WHERE project_id = ? ORDER BY scheduled_at DESC LIMIT 200'
    );
    $rows->execute([$pid]);
    $posts = $rows->fetchAll();
    foreach ($posts as &$post) {
        $tgt = $db->prepare('SELECT * FROM scheduled_post_targets WHERE post_id = ? ORDER BY id');
        $tgt->execute([$post['id']]);
        $post['targets'] = $tgt->fetchAll();
    }
    echo json_encode(['posts' => $posts]);
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$action = $input['action'] ?? '';

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $content      = trim($input['content'] ?? '');
    $url          = trim($input['url'] ?? '');
    $scheduled_at = trim($input['scheduled_at'] ?? '');
    $targets      = $input['targets'] ?? [];

    if (!$content)      { echo json_encode(['error' => 'Content required']); exit; }
    if (!$scheduled_at) { echo json_encode(['error' => 'Scheduled time required']); exit; }
    if (!$targets)      { echo json_encode(['error' => 'At least one target required']); exit; }

    $db->prepare(
        'INSERT INTO scheduled_posts (project_id, content, url, scheduled_at) VALUES (?, ?, ?, ?)'
    )->execute([$pid, $content, $url ?: null, $scheduled_at]);
    $post_id = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO scheduled_post_targets (post_id, platform, target) VALUES (?, ?, ?)');
    foreach ($targets as $t) {
        $platform = $t['platform'] ?? '';
        $target   = $t['target']   ?? '';
        if (!in_array($platform, ['discord', 'mastodon', 'linkedin'], true) || !$target) continue;
        $stmt->execute([$post_id, $platform, $target]);
    }

    Audit::log($db, 'scheduled_post_create', $pid, "post_id=$post_id");
    echo json_encode(['ok' => true, 'id' => $post_id]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'id required']); exit; }
    $db->prepare('DELETE FROM scheduled_posts WHERE id = ? AND project_id = ?')->execute([$id, $pid]);
    Audit::log($db, 'scheduled_post_delete', $pid, "post_id=$id");
    echo json_encode(['ok' => true]);
    exit;
}

// ── RETRY FAILED TARGET ───────────────────────────────────────────────────────
if ($action === 'retry_target') {
    $target_id = (int)($input['target_id'] ?? 0);
    if (!$target_id) { echo json_encode(['error' => 'target_id required']); exit; }

    // Verify target belongs to this project
    $row = $db->prepare(
        'SELECT t.post_id FROM scheduled_post_targets t
         JOIN scheduled_posts p ON p.id = t.post_id
         WHERE t.id = ? AND p.project_id = ?'
    );
    $row->execute([$target_id, $pid]);
    $r = $row->fetch();
    if (!$r) { echo json_encode(['error' => 'Target not found']); exit; }

    $db->prepare("UPDATE scheduled_post_targets SET status = 'pending', error = NULL WHERE id = ?")
       ->execute([$target_id]);
    $db->prepare("UPDATE scheduled_posts SET status = 'pending' WHERE id = ? AND status IN ('done', 'partial')")
       ->execute([$r['post_id']]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
