<?php
$page_title = 'Dashboard';
$nav_active = 'dashboard';
include ROOT . '/views/_header.php';

$projects = $db->query('SELECT * FROM projects WHERE is_active = 1 ORDER BY is_pinned DESC, name')->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="h4 mb-0">Projects</h2>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProjectModal">
    <i class="bi bi-plus-lg me-1"></i>Add project
  </button>
</div>

<?php if (empty($projects)): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-folder-x fs-1 d-block mb-3 opacity-50"></i>
  <p>No projects yet.</p>
  <a href="/settings" class="btn btn-outline-secondary btn-sm">Configure scan paths</a>
  <button class="btn btn-primary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#addProjectModal">Add manually</button>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($projects as $proj):
    $type     = ProjectTypes::get($proj['type']);
    $icon     = $type ? $type::typeIcon() : 'bi-folder';
    $typeName = $type ? $type::typeName() : $proj['type'];
    $hasRun   = $type && in_array('run', $type::tabs());
  ?>
  <div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2 gap-2">
          <i class="bi <?= htmlspecialchars($icon) ?> text-primary fs-5 flex-shrink-0"></i>
          <h6 class="card-title mb-0 text-truncate"><?= htmlspecialchars($proj['name']) ?></h6>
        </div>
        <p class="text-muted small mb-2 text-truncate" title="<?= htmlspecialchars($proj['path']) ?>">
          <code><?= htmlspecialchars($proj['path']) ?></code>
        </p>
        <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
          <span class="badge bg-secondary"><?= htmlspecialchars($typeName) ?></span>
          <?php if ($proj['is_pinned']): ?>
          <span class="badge bg-warning text-dark"><i class="bi bi-pin-fill"></i></span>
          <?php endif; ?>
          <?php if ($proj['url']): ?>
          <a href="<?= htmlspecialchars($proj['url']) ?>" target="_blank" rel="noopener"
             class="badge bg-dark text-decoration-none" title="Open site">
            <i class="bi bi-box-arrow-up-right"></i>
          </a>
          <?php endif; ?>
          <span class="badge bg-secondary-subtle text-body-secondary border project-disk d-none"
                data-project-id="<?= $proj['id'] ?>" title="Project size (excl. node_modules, public, .git)">
            <i class="bi bi-hdd me-1"></i><span class="project-disk-value">…</span>
          </span>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <a href="/project/<?= $proj['id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1">Open</a>
        <?php if ($hasRun): ?>
        <a href="/project/<?= $proj['id'] ?>?tab=run" class="btn btn-sm btn-outline-secondary" title="Run commands">
          <i class="bi bi-terminal"></i>
        </a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary btn-pin-project flex-shrink-0"
                data-id="<?= $proj['id'] ?>"
                title="<?= $proj['is_pinned'] ? 'Unpin' : 'Pin' ?>">
          <i class="bi <?= $proj['is_pinned'] ? 'bi-pin-fill text-warning' : 'bi-pin' ?>"></i>
        </button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Activity feed -->
<div class="mt-5">
  <div class="d-flex align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-journal-text me-1"></i>Recent activity</h6>
    <a href="/audit" class="ms-auto small text-decoration-none">View all →</a>
  </div>
  <div id="activityFeed" class="list-group list-group-flush">
    <div class="list-group-item text-muted small bg-transparent">Loading…</div>
  </div>
</div>

<!-- Add Project Modal -->
<div class="modal fade" id="addProjectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add project</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="addProjectForm">
        <div class="modal-body">
          <div id="addProjectError" class="alert alert-danger d-none"></div>
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Path on server</label>
            <input type="text" name="path" class="form-control" placeholder="/var/www/mysite" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
              <?php foreach (ProjectTypes::all() as $slug => $class): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($class::typeName()) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">URL <span class="text-muted">(optional)</span></label>
            <input type="url" name="url" class="form-control" placeholder="https://...">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include ROOT . '/views/_footer.php'; ?>
