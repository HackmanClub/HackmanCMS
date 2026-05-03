<div id="recentPanel" data-project-id="<?= $pid ?>">
  <div class="d-flex align-items-center mb-3 gap-2">
    <i class="bi bi-clock-history text-info"></i>
    <span class="small text-muted">Recently opened or saved files in this project.</span>
    <button class="btn btn-sm btn-outline-secondary ms-auto" id="recentClearBtn">
      <i class="bi bi-trash"></i> Clear
    </button>
  </div>
  <div id="recentList" class="list-group">
    <div class="list-group-item text-muted small">Loading…</div>
  </div>
</div>
