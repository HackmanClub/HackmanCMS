<div id="linksPanel" data-project-id="<?= $pid ?>"
     data-project-url="<?= htmlspecialchars($project['url'] ?? '') ?>">
  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <i class="bi bi-link-45deg text-primary"></i>
    <span class="small text-muted">
      Scans posts/pages for HTTP/HTTPS links (and internal links if a project URL is set)
      and reports any that are unreachable.
    </span>
    <div class="form-check form-switch ms-auto">
      <input class="form-check-input" type="checkbox" id="linksOnlyBroken" checked>
      <label class="form-check-label small" for="linksOnlyBroken">Broken only</label>
    </div>
    <button class="btn btn-sm btn-primary" id="linksScanBtn">
      <i class="bi bi-arrow-clockwise me-1"></i>Run scan
    </button>
  </div>

  <?php if (!($project['url'] ?? '')): ?>
  <div class="alert alert-warning small py-2">
    No project URL is set. Internal links (starting with <code>/</code>) and relative
    links will be skipped. Set a URL on Settings → Display name area or via project edit
    to enable them.
  </div>
  <?php endif; ?>

  <div id="linksRunInfo" class="text-muted small mb-2"></div>
  <div id="linksTableWrap" class="table-responsive">
    <div class="text-muted small">Loading…</div>
  </div>
</div>
