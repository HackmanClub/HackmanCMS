<div id="botSchedulePanel" data-project-id="<?= $pid ?>">

  <!-- Create form -->
  <div class="card mb-4">
    <div class="card-header py-2 small fw-semibold"><i class="bi bi-clock me-1"></i>Schedule a Post</div>
    <div class="card-body">
      <div class="mb-2">
        <label class="form-label small">Content</label>
        <textarea id="schedContent" rows="4" class="form-control form-control-sm font-monospace"
                  placeholder="What's on your mind?"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label small">URL <span class="text-muted">(optional — appended to content)</span></label>
        <input type="url" id="schedUrl" class="form-control form-control-sm font-monospace"
               placeholder="https://example.com/post">
      </div>
      <div class="mb-3">
        <label class="form-label small">Send at</label>
        <input type="datetime-local" id="schedAt" class="form-control form-control-sm" style="max-width:240px">
      </div>

      <label class="form-label small">Platforms</label>

      <div class="mb-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="schedUseDiscord">
          <label class="form-check-label small fw-semibold" for="schedUseDiscord">
            <i class="bi bi-discord me-1 text-primary"></i>Discord
          </label>
        </div>
        <div id="schedDiscordPicker" class="d-none ms-3 mt-1">
          <div id="schedDiscordChannels" class="d-flex flex-wrap gap-2"></div>
          <div class="text-muted small d-none" id="schedDiscordEmpty">
            No Discord channels configured — add them in the Config tab.
          </div>
        </div>
      </div>

      <div class="mb-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="schedUseMastodon">
          <label class="form-check-label small fw-semibold" for="schedUseMastodon">
            <i class="bi bi-mastodon me-1 text-primary"></i>Mastodon
          </label>
        </div>
        <div id="schedMastodonPicker" class="d-none ms-3 mt-1">
          <div id="schedMastodonAccounts" class="d-flex flex-wrap gap-2"></div>
          <div class="text-muted small d-none" id="schedMastodonEmpty">
            No Mastodon accounts configured — add them in the Config tab.
          </div>
        </div>
      </div>

      <div class="mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="schedUseLinkedin">
          <label class="form-check-label small fw-semibold" for="schedUseLinkedin">
            <i class="bi bi-linkedin me-1 text-primary"></i>LinkedIn
          </label>
        </div>
        <div id="schedLinkedinPicker" class="d-none ms-3 mt-1">
          <div id="schedLinkedinProfiles" class="d-flex flex-wrap gap-2"></div>
          <div class="text-muted small d-none" id="schedLinkedinEmpty">
            No LinkedIn profiles connected — connect your account in the Config tab.
          </div>
        </div>
      </div>

      <div class="text-danger small d-none mb-2" id="schedError"></div>
      <button class="btn btn-primary btn-sm" id="schedSubmitBtn">
        <i class="bi bi-calendar-check me-1"></i>Schedule Post
      </button>
    </div>
  </div>

  <!-- Post list -->
  <h6 class="mb-3">Scheduled &amp; Recent Posts</h6>
  <div id="schedPostsList"><div class="text-muted small">Loading…</div></div>

</div>
