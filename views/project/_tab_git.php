<div id="gitPanel" data-project-id="<?= $pid ?>">
  <!-- Subdir picker (hidden until needed) -->
  <div id="gitSubdirSelector" class="d-none alert alert-info py-2 d-flex align-items-center gap-3 mb-3">
    <i class="bi bi-git flex-shrink-0"></i>
    <span class="flex-grow-1 small">No git repo at project root. Found in subdirectory:</span>
    <select id="gitSubdirSelect" class="form-select form-select-sm flex-shrink-0" style="width:auto"></select>
    <button class="btn btn-sm btn-primary flex-shrink-0" id="gitSubdirUseBtn">Use</button>
  </div>

  <div id="gitNoRepo" class="alert alert-warning d-none">
    <i class="bi bi-exclamation-triangle me-2"></i>Not a git repository and no git repos found in subdirectories.
  </div>

  <div id="gitStatus" class="d-none">
    <!-- Branch + summary -->
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <span class="badge bg-secondary fs-6" id="gitBranch"><i class="bi bi-git me-1"></i>—</span>
      <span class="text-muted small" id="gitStatusSummary"></span>
      <div class="ms-auto d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-outline-secondary" id="gitStashBtn">Stash</button>
        <button class="btn btn-sm btn-outline-secondary" id="gitStashPopBtn">Stash pop</button>
        <button class="btn btn-sm btn-outline-danger" id="gitResetBtn">Reset HEAD</button>
      </div>
    </div>

    <!-- Changed files -->
    <div id="gitFiles" class="mb-2"></div>

    <!-- Commit form -->
    <div class="input-group mb-3" style="max-width:700px">
      <input type="text" id="gitCommitMsg" class="form-control form-control-sm"
             placeholder="Commit message — stages all changed files">
      <button class="btn btn-sm btn-primary" id="gitCommitBtn">
        <i class="bi bi-check2 me-1"></i>Commit
      </button>
    </div>

    <!-- Pull / Push -->
    <div class="d-flex gap-2 mb-3">
      <button class="btn btn-sm btn-outline-secondary" id="gitPullBtn">
        <i class="bi bi-cloud-download me-1"></i>Pull
      </button>
      <button class="btn btn-sm btn-outline-secondary" id="gitPushBtn">
        <i class="bi bi-cloud-upload me-1"></i>Push
      </button>
    </div>
    <pre id="gitStreamOutput" class="small p-2 rounded d-none"
         style="max-height:200px;overflow-y:auto;background:var(--hm-bg)"></pre>
  </div>

  <!-- Sub-tabs -->
  <div class="d-flex gap-2 mb-3 mt-2">
    <button class="btn btn-sm btn-outline-primary active" id="gitTabLogBtn">Log</button>
    <button class="btn btn-sm btn-outline-secondary" id="gitTabBranchesBtn">Branches</button>
  </div>

  <div id="gitLogPanel">
    <div id="gitLogTable" class="text-muted small">Loading…</div>
  </div>
  <div id="gitBranchesPanel" class="d-none">
    <div id="gitBranchList" class="mb-3"></div>
    <div class="input-group input-group-sm" style="max-width:400px">
      <input type="text" id="gitNewBranch" class="form-control" placeholder="new-branch-name">
      <button class="btn btn-outline-secondary" id="gitCreateBranchBtn">
        <i class="bi bi-plus-lg me-1"></i>Create &amp; switch
      </button>
    </div>
  </div>
</div>

<!-- Diff viewer modal -->
<div class="modal fade" id="gitDiffModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="gitDiffTitle">Diff</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <pre id="gitDiffContent" class="diff-view m-0 p-3 small" style="overflow-x:auto"></pre>
      </div>
    </div>
  </div>
</div>
