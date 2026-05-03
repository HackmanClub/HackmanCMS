<div id="pluginsPanel" data-project-id="<?= $pid ?>">
  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <i class="bi bi-puzzle text-primary"></i>
    <span class="small text-muted">
      Hexo plugins listed from <code>package.json</code>. Install/uninstall runs npm.
    </span>
  </div>

  <div class="card mb-3">
    <div class="card-body py-2">
      <div class="d-flex gap-2 align-items-center">
        <input type="text" id="pluginInstallName" class="form-control form-control-sm font-monospace"
               placeholder="hexo-…" style="max-width:300px">
        <button class="btn btn-sm btn-primary" id="pluginInstallBtn">
          <i class="bi bi-cloud-download me-1"></i>Install
        </button>
        <small class="text-muted ms-2">npm install --save</small>
      </div>
      <div id="pluginInstallLog" class="small font-monospace text-muted mt-2 d-none"
           style="white-space:pre-wrap;max-height:200px;overflow:auto"></div>
    </div>
  </div>

  <div id="pluginsList" class="row g-2">
    <div class="col-12 text-muted small">Loading…</div>
  </div>
</div>
