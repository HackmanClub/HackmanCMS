<?php
$needs_setup = !Auth::hasUsers($db);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — HackmanCMS</title>
  <link rel="icon" type="image/png" href="/assets/img/logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
<div class="card" style="width:360px">
  <div class="card-body p-4">
    <h4 class="card-title mb-4 text-center">
      <img src="/assets/img/logo.png" alt="" width="40" height="40" class="me-2">HackmanCMS
    </h4>
    <?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($needs_setup): ?>
    <p class="text-muted small">First run — create your admin account.</p>
    <?php endif; ?>
    <form method="POST" action="/api/auth">
      <input type="hidden" name="action" value="<?= $needs_setup ? 'setup' : 'login' ?>">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <?php if ($needs_setup): ?>
      <div class="mb-3">
        <label class="form-label">Confirm password</label>
        <input type="password" name="password_confirm" class="form-control" required>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary w-100">
        <?= $needs_setup ? 'Create account' : 'Log in' ?>
      </button>
    </form>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
