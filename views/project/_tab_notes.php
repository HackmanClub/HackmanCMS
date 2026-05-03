<div id="scratchpadCard" data-project-id="<?= $pid ?>">
  <div class="d-flex align-items-center mb-2 gap-2">
    <i class="bi bi-sticky text-warning"></i>
    <span class="small text-muted">Quick notes — pre-deploy TODOs, reminders. Auto-saves.</span>
    <span id="scratchpadStatus" class="text-muted small ms-auto"></span>
  </div>
  <textarea id="scratchpadInput"
            class="form-control font-monospace"
            style="height: calc(var(--hm-tab-height) - 30px)"
            placeholder="Start typing — saves automatically…"><?= htmlspecialchars((string)($project['scratchpad'] ?? '')) ?></textarea>
</div>
