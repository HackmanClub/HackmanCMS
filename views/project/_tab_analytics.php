<?php
$pStmt = $db->prepare('SELECT key, value FROM project_settings WHERE project_id = ?');
$pStmt->execute([$pid]);
$pSettings = [];
foreach ($pStmt->fetchAll() as $row) $pSettings[$row['key']] = $row['value'];
?>
<div id="analyticsPanel" data-project-id="<?= $pid ?>">

  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analytics</h5>
    <select id="analyticsRange" class="form-select form-select-sm py-0 ms-auto" style="width:auto">
      <option value="7"   selected>Last 7 days</option>
      <option value="30">Last 30 days</option>
      <option value="90">Last 90 days</option>
      <option value="365">Last year</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary" id="analyticsRefreshBtn" title="Refresh">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  </div>

  <div id="analyticsBody" class="text-muted small">Loading…</div>

  <!-- Setup / log import — collapsed by default. -->
  <details class="mt-4" id="analyticsSetupDetails">
    <summary class="small text-muted">
      <i class="bi bi-gear me-1"></i>Server-log import setup
    </summary>
    <div class="mt-2">
      <p class="text-muted small mb-2">
        Imports page-view data from the web server's access log. No JS, no pixel,
        no client-side change to the managed site. Set the path of the access log
        below; HackmanCMS tails new lines on each import. Asset hits, non-GETs and
        obvious bots are dropped.
      </p>
      <div id="analyticsSettings" data-project-id="<?= $pid ?>" class="mb-2">
        <div class="row g-2 mb-2">
          <div class="col-md-7">
            <label class="form-label small">Access log file</label>
            <input type="text" id="analyticsLogPath" class="form-control form-control-sm font-monospace"
                   value="<?= htmlspecialchars($pSettings['analytics_log_path'] ?? '') ?>"
                   placeholder="/var/log/apache2/blog.example.com_access.log">
          </div>
          <div class="col-md-3">
            <label class="form-label small">Log format</label>
            <select id="analyticsLogFormat" class="form-select form-select-sm">
              <?php $fmt = $pSettings['analytics_log_format'] ?? 'combined'; ?>
              <option value="combined" <?= $fmt === 'combined' ? 'selected' : '' ?>>Apache combined</option>
              <option value="nginx" <?= $fmt === 'nginx' ? 'selected' : '' ?>>nginx default</option>
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-sm btn-outline-secondary w-100" id="analyticsSaveBtn">Save</button>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-7">
            <label class="form-label small">Path prefix filter <span class="text-muted">(optional)</span></label>
            <input type="text" id="analyticsLogFilter" class="form-control form-control-sm font-monospace"
                   value="<?= htmlspecialchars($pSettings['analytics_log_filter'] ?? '') ?>"
                   placeholder="/blog">
          </div>
          <div class="col-md-3">
            <label class="form-label small d-block">&nbsp;</label>
            <button class="btn btn-sm btn-primary w-100" id="analyticsImportBtn">
              <i class="bi bi-arrow-down-circle me-1"></i>Import now
            </button>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-sm btn-outline-warning w-100" id="analyticsResetBtn"
                    title="Discard last-position state and reimport from start">
              <i class="bi bi-arrow-counterclockwise"></i>
            </button>
          </div>
        </div>
        <div id="analyticsStatus" class="small text-muted">
          <?php if (isset($pSettings['analytics_imported_at'])): ?>
            Last import: <?= htmlspecialchars($pSettings['analytics_imported_at']) ?>
            (<?= htmlspecialchars($pSettings['analytics_imported_count'] ?? '0') ?> rows total).
          <?php else: ?>
            No imports yet.
          <?php endif; ?>
        </div>
        <p class="text-muted small mb-0 mt-2">
          Continuous tracking — install once as a user that can read the log:
          <br>
          <code class="small">*/5 * * * * /usr/bin/php /opt/hackmancms/bin/import-site-logs.php</code>
        </p>
        <p class="text-muted small mb-0 mt-2">
          <i class="bi bi-archive"></i>
          <strong>Retention:</strong> raw events for 90 days, hourly rollups
          for 365 days, daily rollups forever. Visitor IPs are hashed with a
          <strong>daily-rotating salt</strong> so visitors look like new
          visitors across days — "uniques over a multi-day window" is
          therefore the sum of per-day unique counts.
          <?php if (isset($pSettings['analytics_last_rollup'])): ?>
            Last rollup:
            <?= htmlspecialchars($pSettings['analytics_last_rollup']) ?>.
          <?php endif; ?>
        </p>
      </div>
    </div>
  </details>

</div>
