<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/lib/bootstrap.php';

$uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

// Public routes — no auth required
if ($uri === '/login') {
    if (Auth::check()) { header('Location: /'); exit; }
    include ROOT . '/views/login.php';
    exit;
}
if ($uri === '/api/auth') {
    include ROOT . '/web/api/auth.php';
    exit;
}

// All other routes require login
Auth::requireLogin();
ProjectTypes::load();

if ($uri === '/' || $uri === '/dashboard') {
    include ROOT . '/views/dashboard.php';

} elseif ($uri === '/settings') {
    include ROOT . '/views/settings.php';

} elseif ($uri === '/audit') {
    include ROOT . '/views/audit.php';

} elseif (preg_match('#^/project/(\d+)$#', $uri, $m)) {
    $project_id = (int)$m[1];
    include ROOT . '/views/project/view.php';

} elseif (preg_match('#^/api/([a-z_]+)#', $uri, $m)) {
    $api_file = ROOT . '/web/api/' . $m[1] . '.php';
    if (file_exists($api_file)) {
        include $api_file;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API endpoint not found']);
    }

} else {
    http_response_code(404);
    include ROOT . '/views/error.php';
}
