<div id="botConfigPanel" data-project-id="<?= $pid ?>">

  <!-- Twitch Streamers -->
  <h6 class="mb-3"><i class="bi bi-twitch me-1 text-primary"></i>Twitch Streamers</h6>
  <div id="streamerList" class="mb-2"><div class="text-muted small">Loading…</div></div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="addStreamerBtn">
    <i class="bi bi-plus-lg me-1"></i>Add Streamer
  </button>

  <hr>

  <!-- RSS Feeds -->
  <h6 class="mb-3 mt-3"><i class="bi bi-rss me-1 text-warning"></i>RSS Feeds</h6>
  <div id="rssList" class="mb-2"><div class="text-muted small">Loading…</div></div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="addRssBtn">
    <i class="bi bi-plus-lg me-1"></i>Add Feed
  </button>

  <hr>

  <!-- Mastodon Accounts -->
  <h6 class="mb-1 mt-3"><i class="bi bi-mastodon me-1 text-primary"></i>Mastodon Accounts</h6>
  <p class="text-muted small mb-3">
    Add the token to <code>.env</code> as <code>MASTODON_TOKEN_&lt;NAME&gt;</code> (uppercase).
    The UI manages the label and API base URL only.
  </p>
  <div id="mastodonList" class="mb-2"><div class="text-muted small">Loading…</div></div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="addMastodonBtn">
    <i class="bi bi-plus-lg me-1"></i>Add Account
  </button>

  <hr>

  <!-- LinkedIn Pages -->
  <h6 class="mb-3 mt-3"><i class="bi bi-linkedin me-1 text-primary"></i>LinkedIn Pages</h6>

  <div class="card mb-3">
    <div class="card-body py-2 d-flex align-items-center gap-3">
      <div>
        <div class="small fw-semibold" id="liConnectionLabel">Checking…</div>
        <div class="text-muted small" id="liTokenExpiry"></div>
      </div>
      <button class="btn btn-sm btn-outline-primary ms-auto" id="liConnectBtn">
        <i class="bi bi-box-arrow-in-right me-1"></i>Connect LinkedIn
      </button>
    </div>
  </div>

  <div id="linkedinPagesList" class="mb-2"><div class="text-muted small">Loading…</div></div>
  <button class="btn btn-sm btn-outline-primary mb-4" id="addLinkedinPageBtn">
    <i class="bi bi-plus-lg me-1"></i>Add Page
  </button>

</div>

<!-- Streamer modal -->
<div class="modal fade" id="streamerModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="streamerModalTitle">Add Streamer</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="streamerModalMode">
        <div class="mb-2">
          <label class="form-label small">Twitch login name</label>
          <input type="text" id="streamerName" class="form-control form-control-sm font-monospace"
                 placeholder="streamer_login" autocomplete="off">
        </div>
        <div class="mb-2">
          <label class="form-label small">Discord channel ID</label>
          <input type="text" id="streamerChannelId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="mb-2">
          <label class="form-label small">Role ID <span class="text-muted">(optional)</span></label>
          <input type="text" id="streamerRoleId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="mb-2">
          <label class="form-label small">Embed colour</label>
          <input type="color" id="streamerColor" class="form-control form-control-color form-control-sm" value="#6441a5">
        </div>
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="streamerModalError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="streamerModalSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- RSS modal -->
<div class="modal fade" id="rssModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="rssModalTitle">Add RSS Feed</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rssModalMode">
        <div class="mb-2">
          <label class="form-label small">Feed name (label)</label>
          <input type="text" id="rssName" class="form-control form-control-sm font-monospace"
                 placeholder="myblog" autocomplete="off">
        </div>
        <div class="mb-2">
          <label class="form-label small">RSS URL</label>
          <input type="url" id="rssUrl" class="form-control form-control-sm font-monospace">
        </div>
        <div class="mb-2">
          <label class="form-label small">Discord channel ID</label>
          <input type="text" id="rssChannelId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="mb-2">
          <label class="form-label small">Role ID <span class="text-muted">(optional)</span></label>
          <input type="text" id="rssRoleId" class="form-control form-control-sm font-monospace">
        </div>
        <div class="mb-2">
          <label class="form-label small">Embed colour</label>
          <input type="color" id="rssColor" class="form-control form-control-color form-control-sm" value="#ffd700">
        </div>
        <div class="mb-2">
          <label class="form-label small">Mastodon account <span class="text-muted">(optional)</span></label>
          <select id="rssMastodonAccount" class="form-select form-select-sm"></select>
        </div>
        <div class="mb-2">
          <label class="form-label small">LinkedIn page <span class="text-muted">(optional)</span></label>
          <select id="rssLinkedinPage" class="form-select form-select-sm"></select>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="rssActive" checked>
          <label class="form-check-label small" for="rssActive">Active</label>
        </div>
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="rssModalError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="rssModalSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Mastodon account modal -->
<div class="modal fade" id="mastodonModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="mastodonModalTitle">Add Mastodon Account</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="mastodonModalMode">
        <div class="mb-2">
          <label class="form-label small">Account key</label>
          <input type="text" id="mastodonName" class="form-control form-control-sm font-monospace"
                 placeholder="main" autocomplete="off">
          <div class="form-text small">Used as <code>MASTODON_TOKEN_&lt;KEY&gt;</code> in .env (uppercase).</div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Label</label>
          <input type="text" id="mastodonLabel" class="form-control form-control-sm" placeholder="Main Account">
        </div>
        <div class="mb-2">
          <label class="form-label small">API base URL</label>
          <input type="url" id="mastodonBase" class="form-control form-control-sm font-monospace"
                 placeholder="https://mastodon.social">
        </div>
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="mastodonModalError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="mastodonModalSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- LinkedIn page modal -->
<div class="modal fade" id="linkedinPageModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="linkedinPageModalTitle">Add LinkedIn Page</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="linkedinPageModalMode">
        <div class="mb-2">
          <label class="form-label small">Page key</label>
          <input type="text" id="linkedinPageName" class="form-control form-control-sm font-monospace"
                 placeholder="myorg" autocomplete="off">
        </div>
        <div class="mb-2">
          <label class="form-label small">Label</label>
          <input type="text" id="linkedinPageLabel" class="form-control form-control-sm" placeholder="My Organisation">
        </div>
        <div class="mb-2">
          <label class="form-label small">Organization ID</label>
          <input type="text" id="linkedinPageOrgId" class="form-control form-control-sm font-monospace"
                 placeholder="123456789">
        </div>
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="linkedinPageModalError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="linkedinPageModalSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>
