<?php
$status   = http_response_code() ?: 404;
$messages = [404 => 'Page not found', 403 => 'Forbidden', 500 => 'Server error'];
$msg      = $messages[$status] ?? 'Something went wrong';
if (Auth::check()):
    $page_title = $status;
    $nav_active = '';
    include ROOT . '/views/_header.php';
?>
<div class="text-center py-5">
  <p class="display-3 fw-bold text-muted"><?= $status ?></p>
  <p class="lead mb-4"><?= htmlspecialchars($msg) ?></p>
  <a href="/" class="btn btn-primary">Go home</a>
</div>
<?php
    include ROOT . '/views/_footer.php';
else:
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head><meta charset="UTF-8"><title><?= $status ?></title></head>
<body class="text-center pt-5"><?= $status ?> <?= htmlspecialchars($msg) ?></body>
</html>
<?php endif; ?>
