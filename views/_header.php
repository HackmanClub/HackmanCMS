<?php
$_nav_active  = $nav_active ?? '';
$_page_title  = isset($page_title) ? htmlspecialchars($page_title) . ' — ' : '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $_page_title ?>HackmanCMS</title>
  <link rel="icon" type="image/png" href="/assets/img/logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<nav class="navbar navbar-expand-lg border-bottom px-3">
  <a class="navbar-brand fw-bold" href="/">
    <img src="/assets/img/logo.png" alt="" width="24" height="24" class="me-2">HackmanCMS
  </a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navMain">
    <ul class="navbar-nav me-auto">
      <li class="nav-item">
        <a class="nav-link <?= $_nav_active === 'dashboard' ? 'active' : '' ?>" href="/">
          <i class="bi bi-grid-3x3-gap me-1"></i>Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $_nav_active === 'audit' ? 'active' : '' ?>" href="/audit">
          <i class="bi bi-journal-text me-1"></i>Audit
        </a>
      </li>
    </ul>
    <?php $user = Auth::currentUser(); ?>
    <ul class="navbar-nav">
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user['username'] ?? '') ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="/settings">
              <i class="bi bi-gear me-2"></i>Settings
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="/audit">
              <i class="bi bi-journal-text me-2"></i>Audit
            </a>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item text-danger" href="/api/auth?action=logout">
              <i class="bi bi-box-arrow-right me-2"></i>Log out
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
<main class="container-fluid py-4">
