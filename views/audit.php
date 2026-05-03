<?php
$page_title = 'Audit Log';
$nav_active = 'audit';
include ROOT . '/views/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="h4 mb-0">Audit Log</h2>
  <div class="d-flex gap-2 align-items-center">
    <select id="auditProjectFilter" class="form-select form-select-sm" style="width:auto">
      <option value="">All projects</option>
      <?php foreach ($db->query('SELECT id, name FROM projects WHERE is_active = 1 ORDER BY name') as $p): ?>
      <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<div id="auditTable">
  <div class="text-muted small">Loading…</div>
</div>
<div class="d-flex gap-2 mt-3">
  <button class="btn btn-sm btn-outline-secondary d-none" id="auditPrev">
    <i class="bi bi-chevron-left me-1"></i>Prev
  </button>
  <button class="btn btn-sm btn-outline-secondary d-none" id="auditNext">
    Next<i class="bi bi-chevron-right ms-1"></i>
  </button>
</div>

<?php include ROOT . '/views/_footer.php'; ?>
