<div id="projectDashboard" data-project-id="<?= $pid ?>"
     data-project-type="<?= htmlspecialchars($project['type']) ?>">

  <?php if ($isHexo): ?>
  <!-- Tags + categories -->
  <div id="tagsPanel" data-project-id="<?= $pid ?>" class="mb-4">
    <div id="tagsLoading" class="text-muted small">Loading tags…</div>
    <div id="tagsContent" class="d-none">
      <div class="row g-4">
        <div class="col-md-6">
          <h6 class="mb-2 text-muted">
            <i class="bi bi-tags me-1"></i>Tags
            <span id="tagsCount" class="badge bg-secondary ms-1">0</span>
          </h6>
          <div id="tagCloud" class="tag-cloud"></div>
        </div>
        <div class="col-md-6">
          <h6 class="mb-2 text-muted">
            <i class="bi bi-folder me-1"></i>Categories
            <span id="catsCount" class="badge bg-secondary ms-1">0</span>
          </h6>
          <div id="catCloud" class="tag-cloud"></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent files -->
  <div id="recentPanel" data-project-id="<?= $pid ?>">
    <div class="d-flex align-items-center mb-2 gap-2">
      <h6 class="mb-0 text-muted">
        <i class="bi bi-clock-history me-1"></i>Recent files
      </h6>
      <button class="btn btn-sm btn-outline-secondary ms-auto py-0" id="recentClearBtn">
        <i class="bi bi-trash"></i> Clear
      </button>
    </div>
    <div id="recentList" class="list-group">
      <div class="list-group-item text-muted small">Loading…</div>
    </div>
  </div>

</div>
