<?php
$page_title = 'Settings';
$nav_active = 'settings';
include ROOT . '/views/_header.php';

$scan_paths = $db->query('SELECT * FROM scan_paths ORDER BY path')->fetchAll();
?>
<h2 class="h4 mb-4">Settings</h2>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Project discovery</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Directories to scan. HackmanCMS detects project types automatically by looking for marker files
          (<code>_config.yml</code> → Hexo, <code>index.php</code> → Website, etc.).
        </p>

        <ul class="list-group list-group-flush mb-3" id="scanPathsList">
          <?php if (empty($scan_paths)): ?>
          <li class="list-group-item text-muted px-0 small">No scan paths configured yet.</li>
          <?php endif; ?>
          <?php foreach ($scan_paths as $sp): ?>
          <li class="list-group-item d-flex align-items-center gap-2 px-0">
            <code class="flex-grow-1"><?= htmlspecialchars($sp['path']) ?></code>
            <span class="badge bg-secondary">depth <?= (int)$sp['depth'] ?></span>
            <button class="btn btn-sm btn-outline-primary btn-scan" data-path="<?= htmlspecialchars($sp['path']) ?>"
                    data-depth="<?= (int)$sp['depth'] ?>">
              <i class="bi bi-search"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger btn-remove-scan" data-id="<?= (int)$sp['id'] ?>">
              <i class="bi bi-x-lg"></i>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>

        <form id="addScanPathForm" class="d-flex gap-2">
          <input type="text" name="path" class="form-control form-control-sm" placeholder="/var/www" required>
          <input type="number" name="depth" class="form-control form-control-sm" value="2" min="1" max="5"
                 style="width:72px" title="Scan depth">
          <button type="submit" class="btn btn-sm btn-primary">Add</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Project types</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Drop a PHP file extending <code>ProjectTypeBase</code> into
          <code>lib/project-types/</code> to register a new type — no config needed.
        </p>
        <ul class="list-group list-group-flush">
          <?php foreach (ProjectTypes::all() as $slug => $class): ?>
          <li class="list-group-item px-0 d-flex align-items-center gap-2">
            <i class="bi <?= htmlspecialchars($class::typeIcon()) ?> text-primary"></i>
            <span><?= htmlspecialchars($class::typeName()) ?></span>
            <?php if ($class::description()): ?>
            <small class="text-muted"><?= htmlspecialchars($class::description()) ?></small>
            <?php endif; ?>
            <code class="ms-auto text-muted small"><?= htmlspecialchars($slug) ?></code>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<div id="scanResults" class="mt-4 d-none">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Scan results</h5>
    <button class="btn btn-sm btn-outline-secondary" id="closeScanResults">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <div id="scanResultsList"></div>
</div>

<?php include ROOT . '/views/_footer.php'; ?>
