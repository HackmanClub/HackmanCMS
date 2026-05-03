<div id="draftsPanel" class="row g-0 h-split" data-project-id="<?= $pid ?>">
  <!-- Left: draft list -->
  <div class="col-md-5 col-xl-4 split-list pe-2">
    <div class="mb-3">
      <button class="btn btn-sm btn-primary" id="newDraftBtn">
        <i class="bi bi-plus-lg me-1"></i>New draft
      </button>
    </div>
    <div id="draftsList">
      <div class="text-muted small">Loading…</div>
    </div>
  </div>

  <!-- Right: draft editor -->
  <div class="col-md-7 col-xl-8 border-start ps-3" id="draftEditorPane">
    <div class="editor-placeholder">
      <i class="bi bi-pencil-square fs-1 d-block mb-2 opacity-25"></i>
      <span>Select a draft or create a new one</span>
    </div>
  </div>
</div>
