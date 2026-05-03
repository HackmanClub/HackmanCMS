<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base = realpath($project['path']);
$pkgFile = $base ? $base . '/package.json' : null;
if (!$base || !is_dir($base)) { echo json_encode(['error' => 'Project path not accessible']); exit; }

if ($method === 'POST') {
    @set_time_limit(180);
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $name   = trim($input['name'] ?? '');

    if (!preg_match('/^(@[a-z0-9._~-]+\/)?[a-z0-9._~-]+$/i', $name)) {
        echo json_encode(['error' => 'Invalid package name']); exit;
    }

    if ($action === 'install') {
        $cmd = 'cd ' . escapeshellarg($base) . ' && npm install --save ' . escapeshellarg($name) . ' 2>&1';
        exec($cmd, $out, $rc);
        Audit::log($db, 'plugin_install', $project_id, $name);
        echo json_encode(['ok' => $rc === 0, 'log' => implode("\n", $out)]);
        exit;
    }

    if ($action === 'uninstall') {
        $cmd = 'cd ' . escapeshellarg($base) . ' && npm uninstall --save ' . escapeshellarg($name) . ' 2>&1';
        exec($cmd, $out, $rc);
        Audit::log($db, 'plugin_uninstall', $project_id, $name);
        echo json_encode(['ok' => $rc === 0, 'log' => implode("\n", $out)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET — list installed plugins
if (!$pkgFile || !is_file($pkgFile)) {
    echo json_encode(['plugins' => [], 'no_package_json' => true]);
    exit;
}

$pkg = json_decode(file_get_contents($pkgFile), true) ?: [];
$deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);

$plugins = [];
foreach ($deps as $name => $version) {
    if (!str_starts_with($name, 'hexo-')) continue;
    $info = readPackageInfo($base . '/node_modules/' . $name);
    $plugins[] = [
        'name'        => $name,
        'version'     => $version,
        'installed'   => $info['version'] ?? null,
        'description' => $info['description'] ?? null,
        'homepage'    => $info['homepage'] ?? null,
        'repository'  => $info['repo'] ?? null,
        'npm'         => 'https://www.npmjs.com/package/' . $name,
    ];
}
usort($plugins, fn($a, $b) => strcmp($a['name'], $b['name']));
echo json_encode(['plugins' => $plugins]);

function readPackageInfo(string $modDir): array {
    $f = $modDir . '/package.json';
    if (!is_file($f)) return [];
    $j = json_decode(file_get_contents($f), true) ?: [];
    $repo = $j['repository']['url'] ?? ($j['repository'] ?? null);
    if (is_string($repo)) {
        $repo = preg_replace('#^git\+#', '', $repo);
        $repo = preg_replace('#\.git$#', '', $repo);
    }
    return [
        'version'     => $j['version'] ?? null,
        'description' => $j['description'] ?? null,
        'homepage'    => $j['homepage'] ?? null,
        'repo'        => is_string($repo) ? $repo : null,
    ];
}
