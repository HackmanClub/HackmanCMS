<?php
$project_id = (int)($_GET['project_id'] ?? 0);
$method     = $_SERVER['REQUEST_METHOD'];

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { jsonErr(404, 'Project not found'); }

$path = realpath($project['path']);
if (!$path) { jsonErr(400, 'Path not accessible'); }

// Parse POST body early so subdir can be sent in either GET param or POST body
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

// Allow using a git repo in a subdirectory
$subdir = trim($_GET['subdir'] ?? ($input['subdir'] ?? ''));
if ($subdir) {
    $sub = realpath($path . '/' . $subdir);
    if ($sub && str_starts_with($sub . '/', $path . '/') && is_dir($sub . '/.git')) {
        $path = $sub;
    }
}

if (!is_dir($path . '/.git')) {
    // Scan one level deep for git sub-repos
    $subdirs = [];
    foreach (@scandir($path) ?: [] as $item) {
        if ($item[0] === '.') continue;
        $sub = $path . '/' . $item;
        if (is_dir($sub) && is_dir($sub . '/.git')) $subdirs[] = $item;
    }
    header('Content-Type: application/json');
    echo json_encode(['no_git' => true, 'subdirs' => $subdirs]);
    exit;
}

function jsonErr(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

function git(string $path, string $args, array &$out = [], int &$exit = 0): string {
    exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $out, $exit);
    return implode("\n", $out);
}

// ── READ actions (GET) ────────────────────────────────────────────────────────
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

if ($action === 'status') {
    $lines  = [];
    $branch = trim(git($path, 'rev-parse --abbrev-ref HEAD'));
    git($path, 'status --porcelain', $lines);
    $files  = [];
    foreach ($lines as $l) {
        if (strlen($l) < 3) continue;
        $files[] = ['xy' => substr($l, 0, 2), 'file' => trim(substr($l, 3))];
    }
    $stashes = [];
    git($path, 'stash list', $stashes);
    echo json_encode(['branch' => $branch, 'files' => $files, 'stash_count' => count($stashes)]);
    exit;
}

if ($action === 'branches') {
    $lines = [];
    git($path, 'branch -a', $lines);
    $branches = [];
    foreach ($lines as $l) {
        $cur        = str_starts_with($l, '* ');
        $branches[] = ['name' => trim(ltrim($l, '* ')), 'current' => $cur];
    }
    echo json_encode(['branches' => $branches]);
    exit;
}

if ($action === 'log') {
    $limit = min(50, (int)($_GET['limit'] ?? 25));
    $lines = [];
    git($path, 'log --pretty=format:"%H|%h|%s|%an|%ar|%ad" --date=short -' . $limit, $lines);
    $commits = array_map(fn($l) => array_combine(
        ['hash','short','subject','author','rel','date'],
        array_pad(explode('|', $l, 6), 6, '')
    ), array_filter($lines));
    echo json_encode(['commits' => array_values($commits)]);
    exit;
}

if ($action === 'diff') {
    $file = $_GET['file'] ?? '';
    $hash = $_GET['hash'] ?? '';
    if ($hash) {
        $diff = git($path, 'show ' . escapeshellarg($hash));
    } elseif ($file) {
        $diff = git($path, 'diff -- ' . escapeshellarg($file));
        if (!trim($diff)) $diff = git($path, 'diff --cached -- ' . escapeshellarg($file));
    } else {
        $diff = git($path, 'diff');
    }
    echo json_encode(['diff' => $diff]);
    exit;
}

// ── WRITE actions (POST) ──────────────────────────────────────────────────────
if ($method !== 'POST') { jsonErr(405, 'Method not allowed'); }

$action = $input['action'] ?? $action;

// Streaming ops (pull, push) return SSE
if (in_array($action, ['pull', 'push'])) {
    Audit::log($db, 'git_' . $action, $project_id);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_flush();

    $cmd  = 'git -C ' . escapeshellarg($path) . ' ' . $action . ' 2>&1';
    $proc = popen($cmd, 'r');
    if (!$proc) {
        echo "data: " . json_encode(['error' => 'Failed to start']) . "\n\n";
        flush();
        exit;
    }
    while (!feof($proc)) {
        $line = fgets($proc, 4096);
        if ($line !== false && $line !== '') {
            echo 'data: ' . json_encode(['line' => $line]) . "\n\n";
            flush();
        }
    }
    $exit = pclose($proc);
    echo 'data: ' . json_encode(['done' => true, 'exit_code' => $exit]) . "\n\n";
    flush();
    exit;
}

// JSON ops
header('Content-Type: application/json');

if ($action === 'commit') {
    $msg = trim($input['message'] ?? '');
    if (!$msg) { echo json_encode(['error' => 'Commit message required']); exit; }
    $out = []; $exit = 0;
    git($path, 'add -A');
    $result = git($path, 'commit -m ' . escapeshellarg($msg), $out, $exit);
    Audit::log($db, 'git_commit', $project_id, $msg);
    echo json_encode(['ok' => $exit === 0, 'output' => $result, 'exit_code' => $exit]);
    exit;
}

if ($action === 'checkout') {
    $branch = $input['branch'] ?? '';
    if (!$branch) { echo json_encode(['error' => 'Branch required']); exit; }
    $out = []; $exit = 0;
    $result = git($path, 'checkout ' . escapeshellarg($branch), $out, $exit);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'create_branch') {
    $branch = $input['branch'] ?? '';
    if (!$branch) { echo json_encode(['error' => 'Branch name required']); exit; }
    $out = []; $exit = 0;
    $result = git($path, 'checkout -b ' . escapeshellarg($branch), $out, $exit);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'merge') {
    $branch = $input['branch'] ?? '';
    if (!$branch) { echo json_encode(['error' => 'Branch required']); exit; }
    $out = []; $exit = 0;
    $result = git($path, 'merge ' . escapeshellarg($branch), $out, $exit);
    Audit::log($db, 'git_merge', $project_id, $branch);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'stash') {
    $out = []; $exit = 0;
    $result = git($path, 'stash', $out, $exit);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'stash_pop') {
    $out = []; $exit = 0;
    $result = git($path, 'stash pop', $out, $exit);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'reset') {
    $out = []; $exit = 0;
    $result = git($path, 'reset --hard HEAD', $out, $exit);
    Audit::log($db, 'git_reset', $project_id);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

if ($action === 'stage') {
    $files = $input['files'] ?? [];
    if (!is_array($files) || empty($files)) {
        echo json_encode(['error' => 'No files specified']); exit;
    }
    $args = 'add --';
    foreach ($files as $f) { $args .= ' ' . escapeshellarg((string)$f); }
    $out = []; $exit = 0;
    $result = git($path, $args, $out, $exit);
    echo json_encode(['ok' => $exit === 0, 'output' => $result]);
    exit;
}

jsonErr(400, 'Unknown action');
