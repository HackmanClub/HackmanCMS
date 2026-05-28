<?php
// LinkedIn OAuth callback — validates state, exchanges code for tokens,
// writes them to the discord-bot project's data/config.json.

if ($_GET['state'] !== ($_SESSION['linkedin_oauth']['state'] ?? '')) {
    http_response_code(400);
    echo 'Invalid OAuth state. <a href="/">Go back</a>';
    exit;
}

$pid = (int)($_SESSION['linkedin_oauth']['project_id'] ?? 0);
unset($_SESSION['linkedin_oauth']);

if (isset($_GET['error'])) {
    $msg = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    echo "LinkedIn denied access: $msg. <a href=\"/project/$pid?tab=botconfig\">Go back</a>";
    exit;
}

$code = $_GET['code'] ?? '';
if (!$code) { echo 'Missing code. <a href="/">Go back</a>'; exit; }

$client_id     = getenv('LINKEDIN_CLIENT_ID') ?: ($_SERVER['LINKEDIN_CLIENT_ID'] ?? null);
$client_secret = getenv('LINKEDIN_CLIENT_SECRET') ?: ($_SERVER['LINKEDIN_CLIENT_SECRET'] ?? null);
$redirect_uri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/linkedin/callback';

// Exchange code for tokens
$ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect_uri,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true);
if ($status !== 200 || empty($data['access_token'])) {
    $err = htmlspecialchars($data['error_description'] ?? $data['error'] ?? 'Unknown error');
    echo "LinkedIn token exchange failed: $err. <a href=\"/project/$pid?tab=botconfig\">Go back</a>";
    exit;
}

// Get the project's config.json path
$stmt = $db->prepare('SELECT path FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { echo 'Project not found. <a href="/">Go back</a>'; exit; }

$config_path = realpath($project['path']) . '/data/config.json';

if (!file_exists($config_path)) {
    echo "config.json not found at $config_path. <a href=\"/project/$pid?tab=botconfig\">Go back</a>";
    exit;
}

$config = json_decode(file_get_contents($config_path), true);
if (!is_array($config)) { echo 'Could not parse config.json. <a href="/">Go back</a>'; exit; }

if (!isset($config['linkedin'])) $config['linkedin'] = [];
$config['linkedin']['access_token']  = $data['access_token'];
$config['linkedin']['refresh_token'] = $data['refresh_token'] ?? null;
$expires_in = $data['expires_in'] ?? 5184000;
$config['linkedin']['token_expiry']  = date('c', time() + $expires_in);

$tmp = $config_path . '.tmp';
file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmp, $config_path);

Audit::log($db, 'linkedin_oauth_connect', $pid);

header('Location: /project/' . $pid . '?tab=botconfig');
exit;
