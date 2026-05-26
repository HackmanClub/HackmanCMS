<?php
$stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); include ROOT . '/views/error.php'; exit; }

$type      = ProjectTypes::get($project['type']);
$tabs      = $type ? $type::tabs() : ['files'];
$isHexo    = $project['type'] === 'hexo';
$isStorage = $project['type'] === 'storage';
$pid       = (int)$project['id'];

// Remove 'git' tab if no git repo found at project root or one level deep
if (in_array('git', $tabs)) {
    $projectPath = $project['path'];
    $hasGit = is_dir($projectPath . '/.git');
    if (!$hasGit) {
        foreach (@scandir($projectPath) ?: [] as $item) {
            if ($item[0] === '.') continue;
            if (is_dir($projectPath . '/' . $item . '/.git')) { $hasGit = true; break; }
        }
    }
    if (!$hasGit) $tabs = array_values(array_diff($tabs, ['git']));
}

$tab = $_GET['tab'] ?? $tabs[0];
if (!in_array($tab, $tabs)) $tab = $tabs[0];

$page_title = $project['name'];
$nav_active = '';
include ROOT . '/views/_header.php';
?>

<?php
$tabLabels = [
    'dashboard' => 'Dashboard', 'analytics' => 'Analytics',
    'posts'     => 'Posts',     'config'    => 'Config',
    'files'     => 'Files',     'media'     => 'Media',
    'run'       => 'Run',       'themes'    => 'Themes',
    'plugins'   => 'Plugins',   'git'       => 'Git',
    'notes'     => 'Notes',     'settings'  => 'Settings',
    'bot'       => 'Bot',       'botconfig' => 'Config',
    'logs'      => 'Logs',
];
$tabIcons = [
    'dashboard' => 'bi-grid-1x2',         'analytics' => 'bi-graph-up',
    'posts'     => 'bi-file-earmark-text','config'    => 'bi-sliders',
    'files'     => 'bi-folder2',          'media'     => 'bi-images',
    'run'       => 'bi-terminal',         'themes'    => 'bi-palette',
    'plugins'   => 'bi-puzzle',           'git'       => 'bi-git',
    'notes'     => 'bi-sticky',           'settings'  => 'bi-gear',
    'bot'       => 'bi-robot',            'botconfig' => 'bi-sliders2',
    'logs'      => 'bi-journal-text',
];
$tabGroups = [
    ['dashboard', 'analytics'],
    ['bot', 'botconfig', 'logs'],
    ['posts', 'config', 'files', 'media'],
    ['run', 'themes', 'plugins', 'git'],
    ['notes', 'settings'],
];
?>

<!-- Sidebar + content -->
<div class="project-layout d-flex gap-3 align-items-start">
  <aside class="project-sidebar" id="projectSidebar">

    <button type="button" class="btn btn-sm btn-link text-body-secondary p-0 sidebar-toggle"
            id="sidebarToggle" title="Collapse sidebar" aria-label="Collapse sidebar">
      <i class="bi bi-chevron-double-left collapse-icon-expanded"></i>
      <i class="bi bi-chevron-double-right collapse-icon-collapsed"></i>
    </button>

    <!-- Project meta header -->
    <div class="project-sidebar-header mb-2">
      <div class="d-flex align-items-center gap-1">
        <i class="bi <?= htmlspecialchars($type ? $type::typeIcon() : 'bi-folder') ?> text-primary flex-shrink-0"></i>
        <span class="fw-semibold text-truncate sidebar-label" title="<?= htmlspecialchars($project['path']) ?>">
          <?= htmlspecialchars($project['name']) ?>
        </span>
        <div class="dropdown ms-auto flex-shrink-0 sidebar-label">
          <button class="btn btn-sm btn-link text-body-secondary p-0 px-1"
                  data-bs-toggle="dropdown" aria-expanded="false" title="More">
            <i class="bi bi-three-dots-vertical"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted text-truncate d-block" style="max-width:280px"
                     title="<?= htmlspecialchars($project['path']) ?>">
              <code><?= htmlspecialchars($project['path']) ?></code>
            </span></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <button type="button" class="dropdown-item text-danger"
                      data-bs-toggle="modal" data-bs-target="#deleteProjectModal">
                <i class="bi bi-trash me-2"></i>Remove project
              </button>
            </li>
          </ul>
        </div>
      </div>
      <?php if ($project['url']): ?>
      <a href="<?= htmlspecialchars($project['url']) ?>" target="_blank" rel="noopener"
         class="d-inline-flex align-items-center gap-1 small text-decoration-none sidebar-label mt-1"
         title="<?= htmlspecialchars($project['url']) ?>">
        <i class="bi bi-box-arrow-up-right"></i>
        <span class="text-truncate"><?= htmlspecialchars(preg_replace('#^https?://#', '', $project['url'])) ?></span>
      </a>
      <?php endif; ?>
      <div class="mt-1 sidebar-label">
        <span class="badge bg-secondary-subtle text-body-secondary border d-none"
              id="diskBadge" data-project-id="<?= $pid ?>"
              title="Project size (excl. node_modules, public, .git)">
          <i class="bi bi-hdd me-1"></i><span id="diskBadgeValue">…</span>
        </span>
      </div>
    </div>

    <hr>

    <!-- Grouped nav -->
    <?php foreach ($tabGroups as $i => $group):
      $visible = array_values(array_intersect($group, $tabs));
      if (!$visible) continue; ?>
      <?php if ($i > 0): ?><hr><?php endif; ?>
      <ul class="nav flex-column">
        <?php foreach ($visible as $t): ?>
        <li class="nav-item">
          <a class="nav-link <?= $t === $tab ? 'active' : '' ?>"
             href="?tab=<?= urlencode($t) ?>"
             title="<?= htmlspecialchars($tabLabels[$t] ?? ucfirst($t)) ?>">
            <i class="bi <?= $tabIcons[$t] ?? 'bi-circle' ?>"></i>
            <span class="sidebar-label"><?= htmlspecialchars($tabLabels[$t] ?? ucfirst($t)) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>

  </aside>

  <section class="project-main flex-grow-1 min-w-0">

<!-- ── POSTS tab (Hexo) ─────────────────────────────────────────────────────── -->
<?php if ($tab === 'posts'): ?>
<div id="postsPanel" class="row g-0 h-split" data-project-id="<?= $pid ?>">
  <div class="col-md-5 col-xl-4 split-list pe-2">
    <div class="d-flex align-items-center gap-1 mb-2 flex-wrap">
      <button class="btn btn-sm btn-outline-primary active" id="showPosts">Posts</button>
      <button class="btn btn-sm btn-outline-secondary" id="showPages">Pages</button>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-primary" id="newItemBtn"><i class="bi bi-plus-lg"></i> New</button>
        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="visually-hidden">Toggle</span>
        </button>
        <ul class="dropdown-menu">
          <li><button class="dropdown-item small new-type-btn" data-type="post"><i class="bi bi-file-text me-2"></i>Post</button></li>
          <li><button class="dropdown-item small new-type-btn" data-type="draft"><i class="bi bi-pencil-square me-2"></i>Draft</button></li>
          <li><button class="dropdown-item small new-type-btn" data-type="page"><i class="bi bi-file-earmark me-2"></i>Page</button></li>
        </ul>
      </div>
      <div class="input-group input-group-sm ms-auto" style="max-width:160px">
        <input type="text" id="searchQuery" class="form-control" placeholder="Search…" autocomplete="off">
        <button class="btn btn-outline-secondary" id="searchClearBtn" title="Clear"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div id="postsList"></div>
    <div id="searchResults" class="d-none"></div>
  </div>

  <div class="col-md-7 col-xl-8 border-start ps-3 d-flex flex-column">
    <div class="editor-tab-bar" id="editorTabBar"></div>
    <div class="editor-tab-content" id="editorTabContent">
      <div class="editor-placeholder">
        <i class="bi bi-cursor-text fs-1 d-block mb-2 opacity-25"></i>
        Select a post to edit
      </div>
    </div>
  </div>
</div>

<!-- ── FILES tab ─────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'files'): ?>
<div id="fileBrowser" class="row g-0 h-split"
     data-project-id="<?= $pid ?>"
     data-project-type="<?= htmlspecialchars($project['type']) ?>">
  <div class="col-md-5 col-xl-4 split-list pe-2">
    <div class="d-flex align-items-center mb-2 gap-2">
      <nav aria-label="breadcrumb" id="fileCrumb" class="flex-grow-1">
        <ol class="breadcrumb mb-0 small">
          <li class="breadcrumb-item"><a href="#" data-path="">Root</a></li>
        </ol>
      </nav>
      <button class="btn btn-xs btn-outline-secondary flex-shrink-0"
              data-bs-toggle="modal" data-bs-target="#uploadModal"
              title="Upload file">
        <i class="bi bi-cloud-upload"></i>
      </button>
    </div>
    <div id="fileList" class="list-group">
      <div class="list-group-item text-muted small">Loading…</div>
    </div>
  </div>
  <div class="col-md-7 col-xl-8 border-start ps-3 d-flex flex-column" id="editorPane">
    <div class="editor-tab-bar" id="editorTabBar"></div>
    <div class="editor-tab-content" id="editorTabContent">
      <div class="editor-placeholder">
        <i class="bi bi-cursor-text fs-1 d-block mb-2 opacity-25"></i>
        Select a file to edit
      </div>
    </div>
  </div>
</div>

<!-- ── RUN tab ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'run' && $type && in_array('run', $tabs)): ?>
<div id="commandRunner" data-project-id="<?= $pid ?>">
  <div class="row g-3">
    <div class="col-md-4 col-lg-3">
      <div class="card">
        <div class="card-header small">Commands</div>
        <div class="list-group list-group-flush">
          <?php foreach ($type::commands() as $cmd): ?>
          <button class="list-group-item list-group-item-action btn-run-cmd"
                  data-cmd="<?= htmlspecialchars($cmd['id']) ?>">
            <span class="d-block"><?= htmlspecialchars($cmd['label']) ?></span>
            <code class="small text-muted"><?= htmlspecialchars($cmd['cmd']) ?></code>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-md-8 col-lg-9">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center small">
          Output
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary py-0" id="showHistoryBtn">History</button>
            <button class="btn btn-sm btn-outline-secondary py-0" id="clearOutput">Clear</button>
          </div>
        </div>
        <div class="card-body p-0">
          <pre id="cmdOutput" class="m-0 p-3 text-success"
               style="min-height:300px;max-height:65vh;overflow-y:auto;font-size:.8rem;background:transparent"></pre>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── MEDIA tab (Storage) ───────────────────────────────────────────────────── -->
<?php elseif ($tab === 'media'): ?>
<div id="mediaPanel" data-project-id="<?= $pid ?>"
     data-project-url="<?= htmlspecialchars($project['url'] ?? '') ?>">
  <nav aria-label="breadcrumb" id="mediaCrumb" class="mb-3">
    <ol class="breadcrumb mb-0 small">
      <li class="breadcrumb-item"><a href="#" data-path="">Root</a></li>
    </ol>
  </nav>
  <div id="mediaGrid" class="row g-3">
    <div class="col-12 text-muted small">Loading…</div>
  </div>
</div>

<?php elseif ($tab === 'bot'): ?>
<?php include ROOT . '/views/project/_tab_bot.php'; ?>

<?php elseif ($tab === 'botconfig'): ?>
<?php include ROOT . '/views/project/_tab_botconfig.php'; ?>

<?php elseif ($tab === 'logs'): ?>
<?php include ROOT . '/views/project/_tab_logs.php'; ?>

<?php elseif ($tab === 'git'): ?>
<?php include ROOT . '/views/project/_tab_git.php'; ?>

<?php elseif ($tab === 'config'): ?>
<?php include ROOT . '/views/project/_tab_config.php'; ?>

<?php elseif ($tab === 'dashboard'): ?>
<?php include ROOT . '/views/project/_tab_dashboard.php'; ?>

<?php elseif ($tab === 'analytics'): ?>
<?php include ROOT . '/views/project/_tab_analytics.php'; ?>

<?php elseif ($tab === 'settings'): ?>
<?php include ROOT . '/views/project/_tab_settings.php'; ?>

<?php elseif ($tab === 'notes'): ?>
<?php include ROOT . '/views/project/_tab_notes.php'; ?>

<?php elseif ($tab === 'themes'): ?>
<?php include ROOT . '/views/project/_tab_themes.php'; ?>

<?php elseif ($tab === 'plugins'): ?>
<?php include ROOT . '/views/project/_tab_plugins.php'; ?>

<?php endif; ?>
  </section>
</div>


<!-- ═══ MODALS ════════════════════════════════════════════════════════════════ -->

<!-- File editor -->
<div class="modal fade" id="fileEditModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="fileEditName">Edit file</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <input type="hidden" id="fileEditPid" value="<?= $pid ?>">
        <input type="hidden" id="fileEditPath">
        <textarea id="fileEditContent" style="display:none"></textarea>
        <div id="fileEditCm" style="height:70vh"></div>
      </div>
      <div class="modal-footer py-2">
        <span id="fileEditStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="fileEditSave">Save</button>
      </div>
    </div>
  </div>
</div>


<!-- Upload -->
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Upload file</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="uploadPid" value="<?= $pid ?>">
        <input type="hidden" id="uploadAccept" value="any">
        <div class="mb-3">
          <label class="form-label small">Target folder</label>
          <input type="text" id="uploadFolder" class="form-control form-control-sm font-monospace"
                 placeholder="<?= $isHexo ? 'source/images' : '' ?>">
        </div>
        <div class="mb-3">
          <label class="form-label small">File</label>
          <input type="file" id="uploadFile" class="form-control form-control-sm">
        </div>
        <div id="uploadResult" class="d-none">
          <div class="alert alert-success py-2 small mb-2" id="uploadResultMsg"></div>
          <div class="input-group input-group-sm">
            <input type="text" id="uploadResultUrl" class="form-control font-monospace" readonly>
            <button class="btn btn-outline-secondary" id="uploadCopy" type="button">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="uploadSubmit">Upload</button>
      </div>
    </div>
  </div>
</div>

<!-- Command history -->
<div class="modal fade" id="cmdHistoryModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Command history</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="cmdHistoryList" class="list-group list-group-flush">
          <div class="list-group-item text-muted small">Loading…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Delete project -->
<div class="modal fade" id="deleteProjectModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Remove project?</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small text-muted">Files on disk are untouched.</div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirmDelete"
                data-project-id="<?= $pid ?>">Remove</button>
      </div>
    </div>
  </div>
</div>

<!-- New post/draft/page -->
<div class="modal fade" id="newPostModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="newPostModalTitle">New Post</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small mb-1">Title</label>
          <input type="text" id="newPostTitle" class="form-control form-control-sm" placeholder="Post title" autocomplete="off">
        </div>
        <div>
          <label class="form-label small mb-1">Folder <span class="text-muted">(optional)</span></label>
          <input type="text" id="newPostFolder" class="form-control form-control-sm font-monospace" placeholder="2026">
        </div>
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="newPostError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="newPostCreateBtn">Create</button>
      </div>
    </div>
  </div>
</div>

<!-- Rename file -->
<div class="modal fade" id="renameModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Rename</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="renameInput" class="form-control form-control-sm font-monospace" autocomplete="off">
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="renameError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="renameConfirmBtn">Rename</button>
      </div>
    </div>
  </div>
</div>

<!-- Move file -->
<div class="modal fade" id="moveModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Move to folder</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small text-muted mb-1">Destination path</label>
        <input type="text" id="moveInput" class="form-control form-control-sm font-monospace" autocomplete="off">
      </div>
      <div class="modal-footer py-2">
        <div class="text-danger small d-none flex-grow-1" id="moveError"></div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="moveConfirmBtn">Move</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toastContainer" style="position:fixed;bottom:1rem;right:1rem;z-index:9999;min-width:260px"></div>

<?php
$extra_scripts = '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
<script type="module">
  // Mirrors Agenda\'s Milkdown loader (lib/layout.php). Front matter is NOT fed
  // through Milkdown — it\'s extracted in JS and edited in a separate CodeMirror
  // so the YAML round-trips byte-for-byte.
  const H = "https://esm.sh/@milkdown/";
  const V = "@7.20.0/es2022/";
  Promise.all([
    import(H + "core"              + V + "core.mjs"),
    import(H + "preset-commonmark" + V + "preset-commonmark.mjs"),
    import(H + "preset-gfm"        + V + "preset-gfm.mjs"),
    import(H + "plugin-history"    + V + "plugin-history.mjs"),
    import(H + "utils"             + V + "utils.mjs"),
  ]).then(function ([core, cm, gfmPkg, hist, utils]) {
    window.MilkdownKit = {
      Editor: core.Editor, rootCtx: core.rootCtx,
      defaultValueCtx: core.defaultValueCtx, commandsCtx: core.commandsCtx,
      commonmark: cm.commonmark, gfm: gfmPkg.gfm, history: hist.history,
      getMarkdown: utils.getMarkdown, replaceAll: utils.replaceAll,
      callCommand: utils.callCommand,
      commands: {
        bold: cm.toggleStrongCommand, italic: cm.toggleEmphasisCommand,
        strikethrough: gfmPkg.toggleStrikethroughCommand,
        inlineCode: cm.toggleInlineCodeCommand, link: cm.toggleLinkCommand,
        bulletList: cm.wrapInBulletListCommand, orderedList: cm.wrapInOrderedListCommand,
        blockquote: cm.wrapInBlockquoteCommand, codeBlock: cm.createCodeBlockCommand,
      },
    };
    window.dispatchEvent(new CustomEvent("milkdown-ready"));
  }).catch(function (err) { console.error("[Milkdown] core load failed:", err); });
</script>
<script src="/assets/js/milkdown-mount.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
';
include ROOT . '/views/_footer.php';
?>
