<?php
/**
 * Cron-driven scheduled social post processor.
 *
 * Install once on the host (as the apache user):
 *   * * * * * /usr/bin/php /opt/hackmancms/bin/process-scheduled-posts.php >> /var/log/hackman_scheduled_posts.log 2>&1
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/lib/bootstrap.php';

$now = gmdate('Y-m-d H:i:s');

// Load all due pending targets grouped by post
$stmt = $db->prepare("
    SELECT t.id AS target_id, t.post_id, t.platform, t.target,
           p.content, p.url, p.project_id,
           pr.path AS project_path
    FROM scheduled_post_targets t
    JOIN scheduled_posts p ON p.id = t.post_id
    JOIN projects pr ON pr.id = p.project_id AND pr.is_active = 1
    WHERE t.status = 'pending'
      AND p.status IN ('pending', 'partial')
      AND p.scheduled_at <= ?
    ORDER BY p.scheduled_at ASC, t.id ASC
");
$stmt->execute([$now]);
$targets = $stmt->fetchAll();

if (!$targets) exit(0);

function log_msg(string $msg): void {
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

function read_bot_config(string $base): array {
    $path = $base . '/data/config.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function write_bot_config(string $base, array $data): void {
    $path = $base . '/data/config.json';
    $tmp  = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function read_dot_env(string $base): array {
    $path = $base . '/.env';
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

function curl_post_json(string $url, array $headers, string $body): array {
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

// Refresh LinkedIn token if near expiry; returns fresh token or null on failure
function ensure_linkedin_token(string $base, array &$config): ?string {
    $li = $config['linkedin'] ?? [];
    if (empty($li['access_token'])) return null;

    $expiry_str = $li['token_expiry'] ?? null;
    if ($expiry_str) {
        try {
            $expiry = new DateTimeImmutable($expiry_str, new DateTimeZone('UTC'));
            if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                log_msg('LinkedIn token expired — re-run OAuth');
                return null;
            }
            // Threshold: 90 days before expiry → still valid, no refresh needed yet
            $threshold = new DateTimeImmutable('+90 days', new DateTimeZone('UTC'));
            if ($expiry > $threshold) return $li['access_token'];
        } catch (Exception $e) {
            log_msg('LinkedIn token_expiry parse error: ' . $e->getMessage());
        }
    }

    // Refresh
    $env           = read_dot_env($base);
    $refresh_token = $li['refresh_token'] ?? null;
    $client_id     = $env['LINKEDIN_CLIENT_ID'] ?? null;
    $client_secret = $env['LINKEDIN_CLIENT_SECRET'] ?? null;
    if (!$refresh_token || !$client_id || !$client_secret) {
        log_msg('LinkedIn refresh failed — missing credentials');
        return null;
    }

    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($status !== 200 || empty($data['access_token'])) {
        log_msg('LinkedIn refresh HTTP ' . $status);
        return null;
    }

    $new_token = $data['access_token'];
    $expires_in = $data['expires_in'] ?? 5184000;
    $config['linkedin']['access_token']  = $new_token;
    $config['linkedin']['token_expiry']  = gmdate('c', time() + $expires_in);
    if (!empty($data['refresh_token'])) $config['linkedin']['refresh_token'] = $data['refresh_token'];
    write_bot_config($base, $config);
    return $new_token;
}

// Mark parent post done/partial based on target statuses
function update_post_status(PDO $db, int $post_id): void {
    $row = $db->prepare("SELECT COUNT(*) AS total,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'failed')   AS failed
        FROM scheduled_post_targets WHERE post_id = ?");
    $row->execute([$post_id]);
    $counts = $row->fetch();
    if ((int)$counts['pending'] > 0) return;  // still in flight
    $status = (int)$counts['failed'] > 0 ? 'partial' : 'done';
    $db->prepare("UPDATE scheduled_posts SET status = ? WHERE id = ?")->execute([$status, $post_id]);
}

// Process each target
$project_configs = [];  // cache per project_id

foreach ($targets as $t) {
    $base      = realpath($t['project_path']);
    $pid       = (int)$t['project_id'];
    $target_id = (int)$t['target_id'];
    $post_id   = (int)$t['post_id'];
    $platform  = $t['platform'];
    $target    = $t['target'];
    $content   = $t['content'];
    $url       = $t['url'] ?? '';
    $text      = $url ? "$content\n$url" : $content;

    log_msg("post_id=$post_id target_id=$target_id platform=$platform target=$target");

    // Load config (cached per project)
    if (!isset($project_configs[$pid])) {
        $project_configs[$pid] = read_bot_config($base);
    }
    $config = &$project_configs[$pid];

    $error = null;

    if ($platform === 'discord') {
        $channel = $config['discord_channels'][$target] ?? null;
        if (!$channel) { $error = "Discord channel '$target' not in config"; }
        else {
            $env         = read_dot_env($base);
            $webhook_url = $env['DISCORD_WEBHOOK_' . strtoupper($target)] ?? '';
            if (!$webhook_url) {
                $error = 'DISCORD_WEBHOOK_' . strtoupper($target) . ' not set in .env';
            } else {
                [$status, $resp] = curl_post_json($webhook_url, ['Content-Type: application/json'], json_encode(['content' => $text]));
                if ($status !== 200 && $status !== 204) {
                    $error = 'Discord HTTP ' . $status . ': ' . (json_decode($resp, true)['message'] ?? $resp);
                }
            }
        }

    } elseif ($platform === 'mastodon') {
        $account = $config['mastodon'][$target] ?? null;
        if (!$account) { $error = "Mastodon account '$target' not in config"; }
        else {
            $env      = read_dot_env($base);
            $token    = $env['MASTODON_TOKEN_' . strtoupper($target)] ?? '';
            $api_base = rtrim($account['api_base_url'] ?? '', '/');
            if (!$token)    { $error = 'MASTODON_TOKEN_' . strtoupper($target) . ' not set'; }
            elseif (!$api_base) { $error = "Mastodon account '$target' has no api_base_url"; }
            else {
                [$status, $resp] = curl_post_json(
                    $api_base . '/api/v1/statuses',
                    ['Authorization: Bearer ' . $token, 'Content-Type: application/x-www-form-urlencoded'],
                    http_build_query(['status' => $text])
                );
                if ($status !== 200) {
                    $error = 'Mastodon HTTP ' . $status . ': ' . (json_decode($resp, true)['error'] ?? $resp);
                }
            }
        }

    } elseif ($platform === 'linkedin') {
        $page  = $config['linkedin']['pages'][$target] ?? null;
        if (!$page) { $error = "LinkedIn page '$target' not in config"; }
        else {
            $token = ensure_linkedin_token($base, $config);
            if (!$token) { $error = 'LinkedIn token unavailable — re-run OAuth'; }
            else {
                $page_type = $page['type'] ?? 'organization';
                if ($page_type === 'personal') {
                    $member_id = $config['linkedin']['member_id'] ?? null;
                    if (!$member_id) { $error = 'member_id missing — re-run OAuth'; }
                    else {
                        $author = str_starts_with($member_id, 'urn:') ? $member_id : "urn:li:person:$member_id";
                    }
                } else {
                    $org_id = $page['organization_id'] ?? '';
                    if (!$org_id) { $error = "Organization page '$target' has no organization_id"; }
                    else { $author = "urn:li:organization:$org_id"; }
                }
                if (!$error) {
                    $payload = json_encode([
                        'author'          => $author,
                        'lifecycleState'  => 'PUBLISHED',
                        'specificContent' => [
                            'com.linkedin.ugc.ShareContent' => [
                                'shareCommentary'    => ['text' => $text],
                                'shareMediaCategory' => 'NONE',
                            ],
                        ],
                        'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
                    ]);
                    [$status, $resp] = curl_post_json(
                        'https://api.linkedin.com/v2/ugcPosts',
                        [
                            'Authorization: Bearer ' . $token,
                            'Content-Type: application/json',
                            'X-Restli-Protocol-Version: 2.0.0',
                        ],
                        $payload
                    );
                    if ($status !== 200 && $status !== 201) {
                        $error = 'LinkedIn HTTP ' . $status . ': ' . (json_decode($resp, true)['message'] ?? $resp);
                    }
                }
            }
        }
    } else {
        $error = "Unknown platform '$platform'";
    }

    if ($error) {
        log_msg("  FAILED: $error");
        $db->prepare("UPDATE scheduled_post_targets SET status = 'failed', error = ? WHERE id = ?")
           ->execute([$error, $target_id]);
    } else {
        log_msg("  OK");
        $db->prepare("UPDATE scheduled_post_targets SET status = 'sent', sent_at = ? WHERE id = ?")
           ->execute([gmdate('Y-m-d H:i:s'), $target_id]);
    }

    update_post_status($db, $post_id);
}
