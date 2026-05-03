<?php
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$project_id = (int)($_GET['project_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base = realpath($project['path']);
$themesDir = $base ? $base . '/themes' : null;
$configFile = $base ? $base . '/_config.yml' : null;

if (!$base || !is_dir($base)) { echo json_encode(['error' => 'Project path not accessible']); exit; }

function readActiveTheme(?string $configFile): ?string {
    if (!$configFile || !is_file($configFile)) return null;
    foreach (file($configFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^theme:\s*(.+?)\s*$/', $line, $m)) return trim($m[1], "\"' ");
    }
    return null;
}

function writeActiveTheme(string $configFile, string $name): bool {
    if (!is_file($configFile)) return false;
    $lines = file($configFile, FILE_IGNORE_NEW_LINES);
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^theme:/', $line)) { $lines[$i] = 'theme: ' . $name; $found = true; break; }
    }
    if (!$found) $lines[] = 'theme: ' . $name;
    return file_put_contents($configFile, implode("\n", $lines) . "\n") !== false;
}

function gitInfo(string $dir): array {
    if (!is_dir($dir . '/.git')) return ['has_git' => false];
    $run = function (string $cmd) use ($dir) {
        $out = [];
        exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>/dev/null', $out);
        return implode("\n", $out);
    };
    $branch  = trim($run('git rev-parse --abbrev-ref HEAD'));
    $remote  = trim($run('git remote get-url origin'));
    $commit  = trim($run('git log -1 --pretty=format:"%h %s"'));
    $status  = trim($run('git status --porcelain'));
    @exec('cd ' . escapeshellarg($dir) . ' && git rev-list --left-right --count @{u}...HEAD 2>/dev/null',
          $countOut);
    $ahead = $behind = null;
    if (!empty($countOut[0]) && preg_match('/^(\d+)\s+(\d+)$/', $countOut[0], $m)) {
        $behind = (int)$m[1]; $ahead = (int)$m[2];
    }
    return [
        'has_git' => true,
        'branch'  => $branch,
        'remote'  => $remote ?: null,
        'commit'  => $commit ?: null,
        'dirty'   => $status !== '',
        'ahead'   => $ahead,
        'behind'  => $behind,
    ];
}

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $name   = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($input['name'] ?? ''));

    if ($action === 'switch' && $name) {
        if (!is_dir($themesDir . '/' . $name)) { echo json_encode(['error' => 'Theme not found']); exit; }
        $ok = writeActiveTheme($configFile, $name);
        Audit::log($db, 'theme_switch', $project_id, $name);
        echo json_encode(['ok' => $ok, 'active' => $name]);
        exit;
    }

    if ($action === 'clone') {
        $url  = trim($input['url'] ?? '');
        if (!$url) { echo json_encode(['error' => 'url required']); exit; }
        if (!$name) {
            // derive from url
            $name = preg_replace('/\.git$/', '', basename(parse_url($url, PHP_URL_PATH) ?: ''));
            $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
        }
        if (!$name) { echo json_encode(['error' => 'cannot derive name']); exit; }
        $dest = $themesDir . '/' . $name;
        if (is_dir($dest)) { echo json_encode(['error' => 'Theme directory already exists']); exit; }
        if (!is_dir($themesDir)) mkdir($themesDir, 0755, true);
        $cmd = 'git clone --depth 50 ' . escapeshellarg($url) . ' ' . escapeshellarg($dest) . ' 2>&1';
        exec($cmd, $out, $rc);
        $log = implode("\n", $out);
        if ($rc !== 0) { echo json_encode(['error' => 'git clone failed', 'log' => $log]); exit; }
        Audit::log($db, 'theme_clone', $project_id, $name);
        echo json_encode(['ok' => true, 'name' => $name, 'log' => $log]);
        exit;
    }

    if ($action === 'git' && $name) {
        $op = $input['op'] ?? '';
        $dir = $themesDir . '/' . $name;
        if (!is_dir($dir . '/.git')) { echo json_encode(['error' => 'No git repo in theme']); exit; }
        $cmd = match ($op) {
            'pull'   => 'git pull',
            'push'   => 'git push',
            'fetch'  => 'git fetch',
            default  => null,
        };
        if (!$cmd) { echo json_encode(['error' => 'Unknown op']); exit; }
        exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>&1', $out, $rc);
        Audit::log($db, 'theme_git_' . $op, $project_id, $name);
        echo json_encode(['ok' => $rc === 0, 'log' => implode("\n", $out)]);
        exit;
    }

    if ($action === 'delete' && $name) {
        if ($name === readActiveTheme($configFile)) {
            echo json_encode(['error' => 'Cannot delete the active theme']); exit;
        }
        $dir = $themesDir . '/' . $name;
        if (!is_dir($dir) || !str_starts_with(realpath($dir) . '/', realpath($themesDir) . '/')) {
            echo json_encode(['error' => 'Theme not found']); exit;
        }
        exec('rm -rf ' . escapeshellarg($dir), $_o, $rc);
        Audit::log($db, 'theme_delete', $project_id, $name);
        echo json_encode(['ok' => $rc === 0]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET list
if (!$themesDir || !is_dir($themesDir)) {
    echo json_encode(['active' => null, 'themes' => [], 'no_themes_dir' => true]);
    exit;
}

$active = readActiveTheme($configFile);
$themes = [];
foreach (scandir($themesDir) as $name) {
    if ($name[0] === '.') continue;
    $dir = $themesDir . '/' . $name;
    if (!is_dir($dir)) continue;
    $themes[] = [
        'name'   => $name,
        'active' => $name === $active,
        'git'    => gitInfo($dir),
    ];
}
usort($themes, fn($a, $b) => ((int)$b['active']) - ((int)$a['active']) ?: strcmp($a['name'], $b['name']));

echo json_encode(['active' => $active, 'themes' => $themes]);
