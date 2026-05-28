<?php
header('Content-Type: application/json');

$pid = (int)($_GET['project_id'] ?? 0);
if (!$pid) { http_response_code(400); echo json_encode(['error' => 'project_id required']); exit; }

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Project not found']); exit; }

$client_id = defined('LINKEDIN_CLIENT_ID') ? LINKEDIN_CLIENT_ID : (getenv('LINKEDIN_CLIENT_ID') ?: ($_SERVER['LINKEDIN_CLIENT_ID'] ?? null));
if (!$client_id) { echo json_encode(['error' => 'LINKEDIN_CLIENT_ID not set in server environment']); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['linkedin_oauth'] = ['state' => $state, 'project_id' => $pid];

$redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/linkedin/callback';

$url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'state'         => $state,
    // 'scope'      => 'w_organization_social openid profile', // org page — requires Marketing Developer Platform approval
    'scope'         => 'w_member_social openid profile',
]);

echo json_encode(['url' => $url]);
