<div id="themesPanel" data-project-id="<?= $pid ?>">
  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <i class="bi bi-palette text-primary"></i>
    <span class="small text-muted">
      Manage Hexo themes installed under <code>themes/</code>. Switching writes to <code>_config.yml</code>.
    </span>
    <button class="btn btn-sm btn-outline-primary ms-auto" id="cloneThemeBtn" data-bs-toggle="modal"
            data-bs-target="#cloneThemeModal">
      <i class="bi bi-cloud-download me-1"></i>Clone from git URL
    </button>
  </div>

  <div id="themesList" class="row g-3">
    <div class="col-12 text-muted small">Loading…</div>
  </div>
</div>

<!-- Clone modal -->
<div class="modal fade" id="cloneThemeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Clone theme</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small">Git URL</label>
          <input type="text" id="cloneThemeUrl" class="form-control form-control-sm font-monospace"
                 placeholder="https://github.com/user/hexo-theme-foo.git">
        </div>
        <div class="mb-2">
          <label class="form-label small">Folder name <span class="text-muted">(optional)</span></label>
          <input type="text" id="cloneThemeName" class="form-control form-control-sm"
                 placeholder="auto from URL">
        </div>
        <div id="cloneThemeLog" class="small font-monospace text-muted"
             style="white-space:pre-wrap;max-height:200px;overflow:auto"></div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="cloneThemeSubmit">Clone</button>
      </div>
    </div>
  </div>
</div>
