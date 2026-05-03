</main>

<?php $b = buildInfo(); ?>
<footer class="text-center text-muted small py-3 border-top mt-4">
  HackmanCMS
  <strong>v<?= htmlspecialchars($b['version']) ?></strong>
  <?php if ($b['sha'] !== ''): ?>
    &middot; <code><?= htmlspecialchars($b['sha']) ?></code>
  <?php endif; ?>
  <?php if ($b['built'] !== ''): ?>
    &middot; build <?= htmlspecialchars($b['built']) ?>
  <?php endif; ?>
</footer>

<!-- Keyboard shortcut help (triggered with `?`) -->
<div class="modal fade" id="shortcutsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-keyboard me-1"></i>Keyboard shortcuts</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><td><kbd>?</kbd></td><td>Show this help</td></tr>
            <tr><td><kbd>g</kbd> <kbd>d</kbd></td><td>Go to dashboard</td></tr>
            <tr><td><kbd>g</kbd> <kbd>s</kbd></td><td>Go to settings</td></tr>
            <tr><td><kbd>g</kbd> <kbd>a</kbd></td><td>Go to audit log</td></tr>
            <tr><td><kbd>n</kbd></td><td>New post / draft (project page)</td></tr>
            <tr><td><kbd>b</kbd></td><td>Trigger generate (Hexo project page)</td></tr>
            <tr><td><kbd>Esc</kbd></td><td>Close any open modal</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($extra_scripts)) echo $extra_scripts; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
