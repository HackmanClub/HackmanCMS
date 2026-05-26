<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$pid    = (int)(($method === 'GET' ? $_GET : $input)['project_id'] ?? 0);
$action = ($method === 'GET' ? ($_GET['action'] ?? 'get') : ($input['action'] ?? ''));

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base        = realpath($project['path']);
$config_path = $base . '/config.json';

if (!$base || !is_dir($base)) {
    echo json_encode(['error' => 'Project path not accessible']); exit;
}

function read_config(string $path): ?array {
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function write_config(string $path, array $data): bool {
    $tmp = $path . '.tmp';
    $ok  = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    if ($ok) $ok = rename($tmp, $path);
    return $ok;
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $config = read_config($config_path);
    if ($config === null) { echo json_encode(['error' => 'config.json not found or invalid']); exit; }
    // Strip secrets from response — tokens are write-only
    $safe = $config;
    if (isset($safe['linkedin']['access_token']))  $safe['linkedin']['access_token']  = $safe['linkedin']['access_token']  ? '***' : null;
    if (isset($safe['linkedin']['refresh_token'])) $safe['linkedin']['refresh_token'] = $safe['linkedin']['refresh_token'] ? '***' : null;
    echo json_encode(['config' => $safe]);
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

$config = read_config($config_path);
if ($config === null) { echo json_encode(['error' => 'config.json not found or invalid']); exit; }

// ── ADD / SAVE / REMOVE STREAMER ──────────────────────────────────────────────
if ($action === 'add_streamer' || $action === 'save_streamer') {
    $name = trim($input['name'] ?? '');
    $data = $input['data'] ?? [];
    if (!$name) { echo json_encode(['error' => 'Streamer name required']); exit; }
    if ($action === 'add_streamer' && isset($config['twitch'][$name])) {
        echo json_encode(['error' => 'Streamer already exists']); exit;
    }
    if (!isset($config['twitch'])) $config['twitch'] = [];
    $existing = $config['twitch'][$name] ?? ['twitch_id' => null, 'is_live' => false];
    $config['twitch'][$name] = array_merge($existing, [
        'color'      => array_map('intval', $data['color'] ?? [255, 255, 255]),
        'channel_id' => isset($data['channel_id']) ? (int)$data['channel_id'] : null,
        'role_id'    => isset($data['role_id']) && $data['role_id'] !== '' ? (int)$data['role_id'] : null,
    ]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_streamer_' . ($action === 'add_streamer' ? 'add' : 'save'), $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'remove_streamer') {
    $name = trim($input['name'] ?? '');
    if (!$name || !isset($config['twitch'][$name])) {
        echo json_encode(['error' => 'Streamer not found']); exit;
    }
    unset($config['twitch'][$name]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_streamer_remove', $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

// ── ADD / SAVE / REMOVE RSS ───────────────────────────────────────────────────
if ($action === 'add_rss' || $action === 'save_rss') {
    $name = trim($input['name'] ?? '');
    $data = $input['data'] ?? [];
    if (!$name) { echo json_encode(['error' => 'Feed name required']); exit; }
    if ($action === 'add_rss' && isset($config['rss'][$name])) {
        echo json_encode(['error' => 'Feed already exists']); exit;
    }
    if (!isset($config['rss'])) $config['rss'] = [];
    $existing = $config['rss'][$name] ?? ['last_id' => ''];
    $config['rss'][$name] = array_merge($existing, [
        'active'           => !empty($data['active']),
        'rss_url'          => trim($data['rss_url'] ?? ''),
        'color'            => array_map('intval', $data['color'] ?? [255, 255, 255]),
        'channel_id'       => isset($data['channel_id']) ? (int)$data['channel_id'] : null,
        'role_id'          => isset($data['role_id']) && $data['role_id'] !== '' ? (int)$data['role_id'] : null,
        'mastodon_account' => $data['mastodon_account'] !== '' ? ($data['mastodon_account'] ?? null) : null,
        'linkedin_page'    => $data['linkedin_page'] !== '' ? ($data['linkedin_page'] ?? null) : null,
    ]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_rss_' . ($action === 'add_rss' ? 'add' : 'save'), $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'remove_rss') {
    $name = trim($input['name'] ?? '');
    if (!$name || !isset($config['rss'][$name])) {
        echo json_encode(['error' => 'Feed not found']); exit;
    }
    unset($config['rss'][$name]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_rss_remove', $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

// ── ADD / SAVE / REMOVE MASTODON ACCOUNT ─────────────────────────────────────
if ($action === 'add_mastodon' || $action === 'save_mastodon') {
    $name = trim($input['name'] ?? '');
    $data = $input['data'] ?? [];
    if (!$name) { echo json_encode(['error' => 'Account name required']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        echo json_encode(['error' => 'Account name must be alphanumeric/underscore (used as env var suffix)']); exit;
    }
    if ($action === 'add_mastodon' && isset($config['mastodon'][$name])) {
        echo json_encode(['error' => 'Account already exists']); exit;
    }
    if (!isset($config['mastodon'])) $config['mastodon'] = [];
    $config['mastodon'][$name] = [
        'label'        => trim($data['label'] ?? $name),
        'api_base_url' => trim($data['api_base_url'] ?? ''),
    ];
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_mastodon_' . ($action === 'add_mastodon' ? 'add' : 'save'), $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'remove_mastodon') {
    $name = trim($input['name'] ?? '');
    if (!$name || !isset($config['mastodon'][$name])) {
        echo json_encode(['error' => 'Account not found']); exit;
    }
    unset($config['mastodon'][$name]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_mastodon_remove', $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

// ── ADD / SAVE / REMOVE LINKEDIN PAGE ────────────────────────────────────────
if ($action === 'add_linkedin_page' || $action === 'save_linkedin_page') {
    $name = trim($input['name'] ?? '');
    $data = $input['data'] ?? [];
    if (!$name) { echo json_encode(['error' => 'Page name required']); exit; }
    if ($action === 'add_linkedin_page' && isset($config['linkedin']['pages'][$name])) {
        echo json_encode(['error' => 'Page already exists']); exit;
    }
    if (!isset($config['linkedin'])) $config['linkedin'] = [];
    if (!isset($config['linkedin']['pages'])) $config['linkedin']['pages'] = [];
    $config['linkedin']['pages'][$name] = [
        'label'           => trim($data['label'] ?? $name),
        'organization_id' => trim($data['organization_id'] ?? ''),
    ];
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_linkedin_page_' . ($action === 'add_linkedin_page' ? 'add' : 'save'), $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'remove_linkedin_page') {
    $name = trim($input['name'] ?? '');
    if (!$name || !isset($config['linkedin']['pages'][$name])) {
        echo json_encode(['error' => 'Page not found']); exit;
    }
    unset($config['linkedin']['pages'][$name]);
    write_config($config_path, $config);
    Audit::log($db, 'botconfig_linkedin_page_remove', $pid, $name);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
