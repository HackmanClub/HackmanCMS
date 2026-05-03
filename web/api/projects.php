<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    echo json_encode($db->query('SELECT * FROM projects WHERE is_active = 1 ORDER BY is_pinned DESC, name')->fetchAll());
    exit;
}

if ($method === 'POST') {
    $action = $input['action'] ?? 'create';

    if ($action === 'add_scan_path') {
        $path  = trim($input['path'] ?? '');
        $depth = max(1, min(5, (int)($input['depth'] ?? 2)));
        if (!$path) { echo json_encode(['error' => 'Path required']); exit; }
        $stmt = $db->prepare('INSERT OR REPLACE INTO scan_paths (path, depth) VALUES (?, ?)');
        $stmt->execute([$path, $depth]);
        Audit::log($db, 'scan_path_add', null, $path . ' depth=' . $depth);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    if ($action === 'update_type') {
        $id   = (int)($input['id'] ?? 0);
        $type = trim($input['type'] ?? '');
        if (!$id || !$type) { echo json_encode(['error' => 'id and type required']); exit; }
        $db->prepare('UPDATE projects SET type = ? WHERE id = ?')->execute([$type, $id]);
        Audit::log($db, 'project_type_change', $id, $type);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_name') {
        $id   = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$id || !$name) { echo json_encode(['error' => 'id and name required']); exit; }
        $db->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$name, $id]);
        Audit::log($db, 'project_rename', $id, $name);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_setting') {
        $id  = (int)($input['id'] ?? 0);
        $key = trim($input['key'] ?? '');
        $val = $input['value'] ?? null;
        if (!$id || !$key) { echo json_encode(['error' => 'id and key required']); exit; }
        $db->prepare('INSERT OR REPLACE INTO project_settings (project_id, key, value) VALUES (?, ?, ?)')
           ->execute([$id, $key, $val]);
        Audit::log($db, 'project_setting', $id, $key);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'pin') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id required']); exit; }
        $db->prepare('UPDATE projects SET is_pinned = CASE WHEN is_pinned = 1 THEN 0 ELSE 1 END WHERE id = ?')
           ->execute([$id]);
        $stmt = $db->prepare('SELECT is_pinned FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $pinned = (bool)$stmt->fetchColumn();
        Audit::log($db, $pinned ? 'project_pin' : 'project_unpin', $id);
        echo json_encode(['ok' => true, 'is_pinned' => $pinned]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        $db->prepare('UPDATE projects SET is_active = 0 WHERE id = ?')->execute([$id]);
        Audit::log($db, 'project_delete', $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'remove_scan_path') {
        $sid = (int)($input['id'] ?? 0);
        $db->prepare('DELETE FROM scan_paths WHERE id = ?')->execute([$sid]);
        Audit::log($db, 'scan_path_delete', null, 'id=' . $sid);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'scan') {
        $path  = trim($input['path'] ?? '');
        $depth = max(1, min(5, (int)($input['depth'] ?? 2)));
        $real  = realpath($path);
        if (!$real || !is_dir($real)) {
            echo json_encode(['error' => 'Path not found or not a directory']);
            exit;
        }
        $found = [];
        scanForProjects($real, $depth, $found);
        echo json_encode(['found' => $found]);
        exit;
    }

    // Default: create project
    $name = trim($input['name'] ?? '');
    $path = trim($input['path'] ?? '');
    $type = trim($input['type'] ?? 'generic');
    $url  = trim($input['url'] ?? '') ?: null;
    if (!$name || !$path) {
        echo json_encode(['error' => 'Name and path are required']);
        exit;
    }
    $stmt = $db->prepare('INSERT INTO projects (name, path, type, url) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $path, $type, $url]);
    $newId = (int)$db->lastInsertId();
    Audit::log($db, 'project_add', $newId, $name . ' (' . $type . ')');
    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($input['id'] ?? 0);
    $db->prepare('UPDATE projects SET is_active = 0 WHERE id = ?')->execute([$id]);
    Audit::log($db, 'project_delete', $id);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

function scanForProjects(string $base, int $maxDepth, array &$found, int $depth = 0): void {
    if ($depth >= $maxDepth) return;
    $items = @scandir($base);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $path = $base . '/' . $item;
        if (!is_dir($path)) continue;
        $type     = ProjectTypes::detect($path);
        $typeClass = ProjectTypes::get($type);
        $found[] = [
            'name'      => $item,
            'path'      => $path,
            'type'      => $type,
            'type_name' => $typeClass ? $typeClass::typeName() : $type,
        ];
        scanForProjects($path, $maxDepth, $found, $depth + 1);
    }
}
