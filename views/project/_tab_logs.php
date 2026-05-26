<div id="botLogsPanel" data-project-id="<?= $pid ?>">

  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h6 class="mb-0 text-muted">
      <i class="bi bi-journal-text me-1"></i>Bot logs
    </h6>
    <div class="d-flex align-items-center gap-2 ms-auto">
      <label class="form-label small mb-0">Lines:</label>
      <select id="logsLineCount" class="form-select form-select-sm" style="width:auto">
        <option value="50">50</option>
        <option value="100" selected>100</option>
        <option value="200">200</option>
        <option value="500">500</option>
      </select>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="logsAutoRefresh">
        <label class="form-check-label small" for="logsAutoRefresh">Auto-refresh</label>
      </div>
      <button class="btn btn-sm btn-outline-secondary" id="logsRefreshBtn">
        <i class="bi bi-arrow-repeat"></i>
      </button>
    </div>
  </div>

  <pre id="logsOutput" class="p-3 border rounded bg-body-tertiary text-body-secondary"
       style="font-size:.75rem;min-height:200px;max-height:70vh;overflow-y:auto;white-space:pre-wrap;word-break:break-all">Loading…</pre>

</div>
