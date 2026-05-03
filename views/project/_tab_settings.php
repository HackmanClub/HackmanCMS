<?php
// Load current project settings from DB
$psStmt = $db->prepare('SELECT key, value FROM project_settings WHERE project_id = ?');
$psStmt->execute([$pid]);
$pSettings = [];
foreach ($psStmt->fetchAll() as $row) {
    $pSettings[$row['key']] = $row['value'];
}
?>
<?php
$schedules = [];
if ($isHexo) {
    $sStmt = $db->prepare('SELECT * FROM scheduled_builds WHERE project_id = ? ORDER BY id');
    $sStmt->execute([$pid]);
    $schedules = $sStmt->fetchAll();
}
?>
<div id="settingsPanel" data-project-id="<?= $pid ?>">

  <!-- Project -->
  <h6 class="mb-3">Project</h6>
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <label class="form-label small">Display name</label>
      <div class="input-group input-group-sm">
        <input type="text" id="projectNameInput" class="form-control"
               value="<?= htmlspecialchars($project['name']) ?>">
        <button class="btn btn-outline-secondary" id="projectNameSaveBtn">Save</button>
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label small">Project type</label>
      <div class="input-group input-group-sm">
        <select class="form-select" id="projectTypeSelectSettings">
          <?php foreach (ProjectTypes::all() as $slug => $class): ?>
          <option value="<?= htmlspecialchars($slug) ?>" <?= $project['type'] === $slug ? 'selected' : '' ?>>
            <?= htmlspecialchars($class::typeName()) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" id="projectTypeSaveBtn">Save</button>
      </div>
    </div>
  </div>

  <?php if ($isHexo): ?>
  <hr>

  <!-- Broken link checker -->
  <h6 class="mb-2 mt-3">Broken link checker</h6>
  <p class="text-muted small mb-2">
    Scans posts/pages for HTTP/HTTPS links (and internal links if a project URL is set)
    and reports any that are unreachable.
  </p>
  <div id="linksPanel" class="mb-4"
       data-project-id="<?= $pid ?>"
       data-project-url="<?= htmlspecialchars($project['url'] ?? '') ?>">
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-primary" id="linksScanBtn">
        <i class="bi bi-arrow-clockwise me-1"></i>Run scan
      </button>
      <div class="form-check form-switch m-0">
        <input class="form-check-input" type="checkbox" id="linksOnlyBroken" checked>
        <label class="form-check-label small" for="linksOnlyBroken">Broken only</label>
      </div>
      <span id="linksRunInfo" class="text-muted small ms-auto"></span>
    </div>
    <div id="linksTableWrap" class="table-responsive"></div>
  </div>
  <?php endif; ?>

  <hr>

  <!-- Backup & Export -->
  <h6 class="mb-2 mt-3">Backup &amp; export</h6>
  <p class="text-muted small mb-2">
    Download a zip of the project source.
    Excludes <code>node_modules</code>, <code>public</code>, and <code>.git</code>.
  </p>
  <a class="btn btn-sm btn-outline-primary mb-4"
     href="/api/backup?project_id=<?= $pid ?>">
    <i class="bi bi-download me-1"></i>Download backup (.zip)
  </a>

  <?php if ($isHexo): ?>
  <hr>

  <!-- Scheduled builds (Hexo) -->
  <h6 class="mb-2 mt-3">Scheduled builds</h6>
  <p class="text-muted small mb-2">
    Run a project command on a cron schedule. Output is saved to command history.
    Requires the system cron entry to be installed (see <code>docs/scheduled-builds.md</code>).
  </p>
  <div id="schedulesList" class="mb-2">
    <?php if (!$schedules): ?>
      <div class="text-muted small">No schedules yet.</div>
    <?php else: foreach ($schedules as $s): ?>
      <div class="d-flex align-items-center gap-2 border rounded p-2 mb-1 schedule-row"
           data-id="<?= (int)$s['id'] ?>">
        <div class="form-check form-switch m-0">
          <input class="form-check-input schedule-enabled" type="checkbox"
                 <?= $s['is_enabled'] ? 'checked' : '' ?>>
        </div>
        <select class="form-select form-select-sm schedule-cmd" style="max-width:150px">
          <?php foreach ($type::commands() as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>"
                  <?= $s['cmd_id'] === $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-control-sm font-monospace schedule-cron"
               placeholder="0 3 * * *" value="<?= htmlspecialchars($s['cron']) ?>"
               style="max-width:160px">
        <small class="text-muted small flex-grow-1">
          <?php if ($s['last_run_at']): ?>
            last: <?= htmlspecialchars($s['last_run_at']) ?>
            (<?= htmlspecialchars($s['last_status'] ?? '–') ?>)
          <?php else: ?>
            never run
          <?php endif; ?>
        </small>
        <button class="btn btn-sm btn-outline-secondary schedule-save" title="Save">
          <i class="bi bi-check-lg"></i>
        </button>
        <button class="btn btn-sm btn-outline-danger schedule-delete" title="Delete">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="newScheduleBtn">
    <i class="bi bi-plus-lg me-1"></i>Add schedule
  </button>

  <hr>

  <!-- Page directories (Hexo) -->
  <h6 class="mb-2 mt-3">Page directories</h6>
  <p class="text-muted small mb-2">
    Subdirectories of <code>source/</code> scanned for pages, one per line.
    Leave empty to scan only <code>source/*.md</code>.
  </p>
  <textarea id="pageDirsInput" class="form-control form-control-sm font-monospace mb-2" rows="3"
            placeholder="p&#10;pages"><?= htmlspecialchars($pSettings['page_dirs'] ?? "p\npages") ?></textarea>
  <button class="btn btn-sm btn-outline-primary mb-4" id="pageDirsSaveBtn">Save</button>

  <hr>

  <!-- Templates -->
  <h6 class="mb-3 mt-3">Post Templates</h6>
  <p class="text-muted small">
    Full post file content (front matter + body). Applied when creating a new post.
  </p>
  <div id="templatesList" class="mb-3"></div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="newTemplateBtn">
    <i class="bi bi-plus-lg me-1"></i>New template
  </button>

  <hr>

  <!-- Snippets -->
  <h6 class="mb-3 mt-3">Snippets</h6>
  <p class="text-muted small">
    Reusable markdown fragments. Insert into post body via the snippet picker.
  </p>
  <div id="snippetsList" class="mb-3"></div>
  <button class="btn btn-sm btn-outline-primary" id="newSnippetBtn">
    <i class="bi bi-plus-lg me-1"></i>New snippet
  </button>
  <?php endif; ?>

</div>

<?php if ($isHexo): ?>
<!-- Template editor modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="templateModalTitle">New template</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="templateId">
        <div class="mb-3">
          <label class="form-label small">Name</label>
          <input type="text" id="templateName" class="form-control form-control-sm">
        </div>
        <div>
          <label class="form-label small">Content <span class="text-muted">(full post: front matter + body)</span></label>
          <div id="templateContentEditor" class="border rounded" style="height:400px"></div>
          <textarea id="templateContent" style="display:none"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="templateSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Snippet editor modal -->
<div class="modal fade" id="snippetModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="snippetModalTitle">New snippet</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="snippetId">
        <div class="mb-3">
          <label class="form-label small">Name</label>
          <input type="text" id="snippetName" class="form-control form-control-sm">
        </div>
        <div>
          <label class="form-label small">Content</label>
          <div id="snippetContentEditor" class="border rounded" style="height:250px"></div>
          <textarea id="snippetContent" style="display:none"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="snippetSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
