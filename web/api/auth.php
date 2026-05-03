<?php
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
    Auth::logout();
    header('Location: /login');
    exit;
}

if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::login($db, $username, $password)) {
        header('Location: /');
        exit;
    }
    $error = 'Invalid username or password.';
    include ROOT . '/views/login.php';
    exit;
}

if ($action === 'setup') {
    if (Auth::hasUsers($db)) {
        header('Location: /login');
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($username) < 2) {
        $error = 'Username must be at least 2 characters.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        Auth::createUser($db, $username, $password);
        Auth::login($db, $username, $password);
        header('Location: /');
        exit;
    }
    include ROOT . '/views/login.php';
    exit;
}

http_response_code(400);
echo 'Bad request';
