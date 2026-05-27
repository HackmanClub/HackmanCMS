<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$pid    = (int)($input['project_id'] ?? 0);
$action = $input['action'] ?? '';

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$base        = realpath($project['path']);
$config_path = $base . '/data/config.json';

function read_config(string $path): ?array {
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function write_config(string $path, array $data): bool {
    $tmp = $path . '.tmp';
    $ok  = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    if ($ok) $ok = rename($tmp, $path);
    return (bool)$ok;
}

function read_dot_env(string $path): array {
    if (!file_exists($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val, " \t\"'");
    }
    return $env;
}

function curl_post(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $resp];
}

// ── RSS: clear last_id to trigger repost on next poll ─────────────────────────
if ($action === 'rss_repost') {
    $feed_name = $input['feed_name'] ?? '';
    if (!$feed_name) { echo json_encode(['error' => 'feed_name required']); exit; }

    $config = read_config($config_path);
    if (!$config) { echo json_encode(['error' => 'config.json not found']); exit; }
    if (!isset($config['rss'][$feed_name])) { echo json_encode(['error' => 'Feed not found']); exit; }

    $config['rss'][$feed_name]['last_id'] = '';
    if (!write_config($config_path, $config)) {
        echo json_encode(['error' => 'Failed to write config.json — check file permissions']); exit;
    }

    Audit::log($db, 'botcompose_rss_repost', $pid, $feed_name);
    echo json_encode(['ok' => true, 'message' => 'Last ID cleared — bot will repost on next poll (within 5 min)']);
    exit;
}

// ── Mastodon: post directly via API ───────────────────────────────────────────
if ($action === 'mastodon_post') {
    $account_name = $input['account_name'] ?? '';
    $text         = trim($input['text'] ?? '');
    if (!$account_name || !$text) { echo json_encode(['error' => 'account_name and text required']); exit; }

    $config = read_config($config_path);
    if (!$config) { echo json_encode(['error' => 'config.json not found']); exit; }

    $account = $config['mastodon'][$account_name] ?? null;
    if (!$account) { echo json_encode(['error' => "Mastodon account '$account_name' not in config"]); exit; }

    $env   = read_dot_env($base . '/.env');
    $token = $env['MASTODON_TOKEN_' . strtoupper($account_name)] ?? '';
    if (!$token) { echo json_encode(['error' => 'MASTODON_TOKEN_' . strtoupper($account_name) . ' not found in .env']); exit; }

    $api_base = rtrim($account['api_base_url'] ?? '', '/');
    [$status, $resp] = curl_post(
        $api_base . '/api/v1/statuses',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['status' => $text])
    );

    if ($status !== 200) {
        $err = json_decode($resp, true)['error'] ?? "HTTP $status";
        echo json_encode(['error' => "Mastodon error: $err"]); exit;
    }

    Audit::log($db, 'botcompose_mastodon_post', $pid, $account_name);
    echo json_encode(['ok' => true]);
    exit;
}

// ── LinkedIn: post directly via API ──────────────────────────────────────────
if ($action === 'linkedin_post') {
    $page_name = $input['page_name'] ?? '';
    $text      = trim($input['text'] ?? '');
    if (!$page_name || !$text) { echo json_encode(['error' => 'page_name and text required']); exit; }

    $config = read_config($config_path);
    if (!$config) { echo json_encode(['error' => 'config.json not found']); exit; }

    $page  = $config['linkedin']['pages'][$page_name] ?? null;
    $token = $config['linkedin']['access_token'] ?? null;
    if (!$page)  { echo json_encode(['error' => "LinkedIn page '$page_name' not in config"]); exit; }
    if (!$token) { echo json_encode(['error' => 'LinkedIn not connected — use Connect LinkedIn first']); exit; }

    $org_id  = $page['organization_id'] ?? '';
    $payload = json_encode([
        'author'          => "urn:li:organization:$org_id",
        'lifecycleState'  => 'PUBLISHED',
        'specificContent' => [
            'com.linkedin.ugc.ShareContent' => [
                'shareCommentary'    => ['text' => $text],
                'shareMediaCategory' => 'NONE',
            ],
        ],
        'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
    ]);

    [$status, $resp] = curl_post(
        'https://api.linkedin.com/v2/ugcPosts',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-Restli-Protocol-Version: 2.0.0',
        ],
        $payload
    );

    if ($status !== 200 && $status !== 201) {
        $err = json_decode($resp, true)['message'] ?? "HTTP $status";
        echo json_encode(['error' => "LinkedIn error: $err"]); exit;
    }

    Audit::log($db, 'botcompose_linkedin_post', $pid, $page_name);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
