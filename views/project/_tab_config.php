<div id="configPanel" data-project-id="<?= $pid ?>">
  <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
    <select id="configFileSelect" class="form-select form-select-sm" style="width:auto;min-width:220px">
      <option value="_config.yml">_config.yml (blog)</option>
    </select>
    <div class="d-flex align-items-center gap-2">
      <span id="configSaveStatus" class="text-muted small"></span>
      <button class="btn btn-sm btn-primary" id="configSaveBtn">
        <i class="bi bi-floppy me-1"></i>Save
      </button>
    </div>
  </div>
  <div id="configEditor"
       style="border:1px solid var(--hm-border);border-radius:4px;overflow:hidden"></div>
</div>
