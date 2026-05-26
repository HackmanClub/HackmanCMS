<div id="botControlPanel" data-project-id="<?= $pid ?>">

  <!-- Status card -->
  <div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <span id="botStatusDot" class="rounded-circle d-inline-block"
              style="width:12px;height:12px;background:#6c757d"></span>
        <span id="botStatusText" class="fw-semibold">Checking…</span>
      </div>
      <small id="botSince" class="text-muted"></small>
      <small id="botService" class="text-muted font-monospace ms-auto"></small>
    </div>
  </div>

  <!-- Controls -->
  <div class="d-flex gap-2 mb-4">
    <button class="btn btn-success btn-sm" id="botStartBtn">
      <i class="bi bi-play-fill me-1"></i>Start
    </button>
    <button class="btn btn-danger btn-sm" id="botStopBtn">
      <i class="bi bi-stop-fill me-1"></i>Stop
    </button>
    <button class="btn btn-warning btn-sm" id="botRestartBtn">
      <i class="bi bi-arrow-clockwise me-1"></i>Restart
    </button>
    <button class="btn btn-outline-secondary btn-sm ms-auto" id="botRefreshStatusBtn">
      <i class="bi bi-arrow-repeat me-1"></i>Refresh
    </button>
  </div>

  <!-- Output -->
  <div id="botCmdOutputWrap" class="d-none">
    <div class="card">
      <div class="card-header small d-flex justify-content-between align-items-center">
        Command output
        <button class="btn btn-sm btn-outline-secondary py-0" id="botClearOutputBtn">Clear</button>
      </div>
      <pre id="botCmdOutput" class="m-0 p-3 text-success"
           style="min-height:80px;max-height:300px;overflow-y:auto;font-size:.8rem;background:transparent"></pre>
    </div>
  </div>

  <div class="mt-4">
    <p class="text-muted small mb-1">
      <i class="bi bi-info-circle me-1"></i>
      Bot process managed via <code>systemctl</code>.
      The <code>www-data</code> user requires passwordless sudo for these commands:
    </p>
    <pre class="small text-muted p-2 border rounded bg-body-tertiary" style="font-size:.75rem">www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start bashybot, /usr/bin/systemctl stop bashybot, /usr/bin/systemctl restart bashybot, /usr/bin/systemctl is-active bashybot, /usr/bin/systemctl show bashybot</pre>
  </div>

</div>
