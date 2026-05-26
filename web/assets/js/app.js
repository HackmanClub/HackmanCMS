'use strict';

// ── Toast / error helpers ─────────────────────────────────────────────────────
function showToast(msg, type = 'secondary') {
  let box = document.getElementById('toastContainer');
  if (!box) {
    box = document.createElement('div');
    box.id = 'toastContainer';
    box.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:9999;min-width:260px';
    document.body.appendChild(box);
  }
  const t = document.createElement('div');
  t.className = `toast align-items-center text-bg-${type} border-0 show mb-2`;
  t.setAttribute('role', 'alert');
  t.innerHTML = `<div class="d-flex"><div class="toast-body small">${esc(msg)}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  box.appendChild(t);
  bootstrap.Toast.getOrCreateInstance(t, { delay: 4000 }).show();
  t.addEventListener('hidden.bs.toast', () => t.remove());
}
const showError   = msg => showToast(msg, 'danger');
const showSuccess = msg => showToast(msg, 'success');

// ── Confirm modal (no browser dialogs) ───────────────────────────────────────
// The modal is a single shared element. Earlier versions left the previous
// confirm-handler attached when the user cancelled, so a second call would
// add a *second* listener — clicking Confirm then ran the cancelled call's
// callback. This wires both confirm and the modal's hidden event each time
// and tears them down regardless of how the modal closes.
function confirmAction(msg, cb) {
  let modal = document.getElementById('_confirmModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.className = 'modal fade'; modal.id = '_confirmModal';
    modal.innerHTML = `<div class="modal-dialog modal-sm"><div class="modal-content">
      <div class="modal-body small" id="_confirmMsg"></div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-danger" id="_confirmOk">Confirm</button>
      </div></div></div>`;
    document.body.appendChild(modal);
  }
  document.getElementById('_confirmMsg').textContent = msg;
  const bsM = bootstrap.Modal.getOrCreateInstance(modal);
  const ok  = document.getElementById('_confirmOk');

  let confirmed = false;
  const onConfirm = () => { confirmed = true; bsM.hide(); };
  const onHidden = () => {
    ok.removeEventListener('click', onConfirm);
    modal.removeEventListener('hidden.bs.modal', onHidden);
    if (confirmed) cb();
  };
  ok.addEventListener('click', onConfirm);
  modal.addEventListener('hidden.bs.modal', onHidden);
  bsM.show();
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtSize(b) {
  if (b == null) return '';
  if (b < 1024)        return b + ' B';
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
  return (b / 1024 / 1024).toFixed(1) + ' MB';
}
function modeForFile(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  return { js:'javascript', ts:'javascript', json:'javascript', css:'css',
           php:'php', html:'htmlmixed', htm:'htmlmixed', xml:'xml', svg:'xml',
           md:'markdown', markdown:'markdown', yml:'yaml', yaml:'yaml',
           sh:'shell', bash:'shell' }[ext] || null;
}
function isImage(name) { return /\.(jpe?g|png|gif|webp|svg)$/i.test(name); }
function isVideo(name) { return /\.(mp4|webm|mov)$/i.test(name); }
function isAudio(name) { return /\.(mp3|wav|ogg|flac)$/i.test(name); }

// ── Dashboard: add project ────────────────────────────────────────────────────
const addProjectForm = document.getElementById('addProjectForm');
if (addProjectForm) {
  addProjectForm.addEventListener('submit', async e => {
    e.preventDefault();
    const err  = document.getElementById('addProjectError');
    err.classList.add('d-none');
    const data = Object.fromEntries(new FormData(addProjectForm));
    const res  = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data),
    });
    const json = await res.json();
    if (json.ok) { location.reload(); }
    else { err.textContent = json.error || 'Error'; err.classList.remove('d-none'); }
  });
}

// ── Project: delete ───────────────────────────────────────────────────────────
const confirmDelete = document.getElementById('confirmDelete');
if (confirmDelete) {
  confirmDelete.addEventListener('click', async () => {
    const id  = parseInt(confirmDelete.dataset.projectId);
    const res = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'delete', id }),
    });
    const json = await res.json();
    if (json.ok) location.href = '/';
    else showError(json.error || 'Error');
  });
}

// ── File editor — tabbed pane system ─────────────────────────────────────────
// Falls back to modal when no tab bar exists (e.g. posts pane)

let _tabCounter   = 0;
const _tabs       = [];   // [{id, pid, path, name, kind}]  kind: 'editor'|'preview'
let   _activeTabId = null;
const _tabCMs     = {};   // id → CodeMirror instance

function _isMediaFile(name) {
  return /\.(jpe?g|png|gif|webp|svg|mp4|webm|mp3|wav|ogg|flac|pdf)$/i.test(name);
}

function _mediaKind(name) {
  if (/\.(jpe?g|png|gif|webp|svg)$/i.test(name)) return 'image';
  if (/\.(mp4|webm)$/i.test(name))                return 'video';
  if (/\.(mp3|wav|ogg|flac)$/i.test(name))        return 'audio';
  if (/\.pdf$/i.test(name))                        return 'pdf';
  return null;
}

function openFileEditor(pid, path, name) {
  const tabBar     = document.getElementById('editorTabBar');
  const tabContent = document.getElementById('editorTabContent');
  const browser    = document.getElementById('fileBrowser');
  const isStorage  = browser?.dataset.projectType === 'storage';

  // ── Files tab: full tab system ────────────────────────────────────────────
  if (tabBar && tabContent) {
    // Storage images → gallery overlay instead of pane preview
    if (isStorage && _mediaKind(name) === 'image') {
      _showGalleryOverlay(`/api/files?project_id=${pid}&path=${encodeURIComponent(path)}&action=serve`, name);
      return;
    }

    // Already open? Switch to it.
    const existing = _tabs.find(t => t.pid === pid && t.path === path);
    if (existing) { _switchTab(existing.id); return; }

    // Media preview tab
    if (_isMediaFile(name)) {
      const id = 'tab' + (++_tabCounter);
      _tabs.push({ id, pid, path, name, kind: 'preview' });
      _renderTabBar();
      const div = document.createElement('div');
      div.id = 'tc-' + id;
      div.style.display = 'none';
      div.style.height  = '100%';
      div.innerHTML = _buildPreviewHTML(pid, path, name);
      tabContent.appendChild(div);
      _switchTab(id);
      return;
    }

    // Text editor tab
    fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent(path)}&action=read`)
      .then(r => r.json())
      .then(data => {
        if (data.error) { showError(data.error); return; }
        const isMd      = /\.(md|markdown)$/i.test(name);
        const isDraft   = path.startsWith('source/_drafts/');
        if (isMd) { _openMdEditorTab(pid, path, name, data.content ?? '', isDraft); return; }

        const id = 'tab' + (++_tabCounter);
        _tabs.push({ id, pid, path, name, kind: 'editor', isDraft, dirty: false });
        _renderTabBar();
        const div = document.createElement('div');
        div.id = 'tc-' + id;
        div.style.display = 'none';
        div.style.height  = '100%';
        div.style.position = 'relative';
        div.innerHTML = `
          <div id="paneCm-${id}" class="pane-cm-container" style="height:100%"></div>
          <button class="md-pane-save d-none" id="paneSaveBtn-${id}" title="Save (Ctrl+S)">
            <i class="bi bi-floppy me-1"></i>Save
          </button>`;
        tabContent.appendChild(div);
        const cm = CodeMirror(div.querySelector('#paneCm-' + id), {
          value: data.content ?? '', mode: modeForFile(name), theme: 'dracula',
          lineNumbers: true, lineWrapping: true, tabSize: 2,
          extraKeys: { 'Ctrl-S': () => _paneTabSave(id), 'Cmd-S': () => _paneTabSave(id) },
        });
        cm.on('change', () => _markDirty(id));
        _tabCMs[id] = cm;
        div.querySelector('#paneSaveBtn-' + id).addEventListener('click', () => _paneTabSave(id));
        // Ctrl/Cmd+S anywhere in the pane saves.
        div.addEventListener('keydown', (e) => {
          if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            _paneTabSave(id);
          }
        });
        _switchTab(id);
      })
      .catch(err => showError('Error: ' + err.message));
    return;
  }

  // ── Fallback: modal ───────────────────────────────────────────────────────
  fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent(path)}&action=read`)
    .then(r => r.json())
    .then(data => {
      if (data.error) { showError(data.error); return; }
      _renderModalEditor(pid, path, name, data.content ?? '');
    })
    .catch(err => showError('Error: ' + err.message));
}

function _buildPreviewHTML(pid, path, name) {
  const src = `/api/files?project_id=${pid}&path=${encodeURIComponent(path)}&action=serve`;
  const mk  = _mediaKind(name);
  if (mk === 'image') return `<div class="file-preview-pane"><img src="${esc(src)}" alt="${esc(name)}"><p class="file-preview-meta">${esc(name)}</p></div>`;
  if (mk === 'pdf')   return `<div class="file-preview-pane"><embed src="${esc(src)}" type="application/pdf"><p class="file-preview-meta">${esc(name)}</p></div>`;
  if (mk === 'video') return `<div class="file-preview-pane"><video controls style="max-width:100%;max-height:calc(100vh - 380px)"><source src="${esc(src)}"></video><p class="file-preview-meta">${esc(name)}</p></div>`;
  if (mk === 'audio') return `<div class="file-preview-pane"><audio controls class="mt-4"><source src="${esc(src)}"></audio><p class="file-preview-meta">${esc(name)}</p></div>`;
  return `<div class="file-preview-pane"><p class="text-muted">${esc(name)}</p></div>`;
}

function _showGalleryOverlay(src, name) {
  let ov = document.getElementById('_galleryOverlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = '_galleryOverlay';
    ov.className = 'gallery-overlay';
    ov.innerHTML = `<img id="_galleryImg" src=""><p class="gallery-caption" id="_galleryCaption"></p>`;
    ov.addEventListener('click', () => ov.remove());
    document.body.appendChild(ov);
  }
  document.getElementById('_galleryImg').src       = src;
  document.getElementById('_galleryCaption').textContent = name;
  if (!document.body.contains(ov)) document.body.appendChild(ov);
}

function _renderTabBar() {
  const bar = document.getElementById('editorTabBar');
  if (!bar) { _saveTabState(); return; }
  if (!_tabs.length) { bar.innerHTML = ''; _saveTabState(); return; }
  bar.innerHTML = _tabs.map(t => {
    const cmDirty = t.kind === 'editor' && _tabCMs[t.id]?.isClean() === false;
    const dirty   = (t.dirty || cmDirty) ? '<span class="tab-dirty">●</span>' : '';
    const icon    = t.kind === 'preview' ? '<i class="bi bi-image me-1 opacity-50"></i>' : '';
    return `<div class="editor-tab ${t.id === _activeTabId ? 'active' : ''}" data-tab-id="${t.id}">
      ${icon}<span class="tab-name">${esc(t.name)}</span>${dirty}
      <span class="tab-close" data-close-tab="${t.id}">✕</span>
    </div>`;
  }).join('');
  bar.querySelectorAll('.editor-tab').forEach(el => {
    el.addEventListener('click', ev => {
      if (ev.target.closest('[data-close-tab]')) return;
      _switchTab(el.dataset.tabId);
    });
  });
  bar.querySelectorAll('[data-close-tab]').forEach(el => {
    el.addEventListener('click', () => _closeTab(el.dataset.closeTab));
  });
  _saveTabState();
}

function _tabStorageKey() {
  const fb = document.getElementById('fileBrowser');
  const pp = document.getElementById('postsPanel');
  const el = fb || pp;
  if (!el) return null;
  return 'hackmancms_tabs_' + el.dataset.projectId + '_' + (fb ? 'files' : 'posts');
}

function _saveTabState() {
  const key = _tabStorageKey();
  if (!key) return;
  const items = _tabs
    .filter(t => t.path && (t.kind === 'editor' || t.kind === 'preview'))
    .map(t => ({ pid: t.pid, path: t.path, name: t.name, kind: t.kind }));
  if (!items.length) localStorage.removeItem(key);
  else localStorage.setItem(key, JSON.stringify({ tabs: items, active: _activeTabId }));
}

function _restoreTabState() {
  const key = _tabStorageKey();
  if (!key) return;
  let saved;
  try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { saved = null; }
  if (!saved?.tabs?.length) return;
  saved.tabs.forEach(t => openFileEditor(t.pid, t.path, t.name));
}

function _switchTab(id) {
  _tabs.forEach(t => {
    const div = document.getElementById('tc-' + t.id);
    if (div) div.style.display = 'none';
  });
  const div = document.getElementById('tc-' + id);
  if (div) {
    div.style.display = 'flex';
    div.style.flexDirection = 'column';
    div.style.height = '100%';
    requestAnimationFrame(() => _tabCMs[id]?.refresh());
  }
  _activeTabId = id;
  _renderTabBar();

  // Hide placeholder
  const content = document.getElementById('editorTabContent');
  const placeholder = content?.querySelector('.editor-placeholder');
  if (placeholder) placeholder.style.display = 'none';
}

function _closeTab(id) {
  const idx = _tabs.findIndex(t => t.id === id);
  if (idx === -1) return;
  if (_tabCMs[id]) { try { _tabCMs[id].toTextArea(); } catch(e) {} delete _tabCMs[id]; }
  delete _tabMdMounts[id];
  if (_tabMdReflowers[id]) {
    try { _tabMdReflowers[id].disconnect(); } catch (e) {}
    delete _tabMdReflowers[id];
  }
  if (_tabImgObservers[id]) {
    try { _tabImgObservers[id].disconnect(); } catch (e) {}
    delete _tabImgObservers[id];
  }
  document.getElementById('tc-' + id)?.remove();
  _tabs.splice(idx, 1);
  if (_activeTabId === id) {
    const next = _tabs[Math.min(idx, _tabs.length - 1)];
    if (next) { _switchTab(next.id); }
    else {
      _activeTabId = null;
      const content = document.getElementById('editorTabContent');
      if (content) {
        const ph = content.querySelector('.editor-placeholder');
        if (ph) ph.style.display = '';
        else content.innerHTML = `<div class="editor-placeholder"><i class="bi bi-cursor-text fs-1 d-block mb-2 opacity-25"></i>Select a file to edit</div>`;
      }
    }
  }
  _renderTabBar();
}

function _markDirty(id) {
  const tab = _tabs.find(t => t.id === id);
  if (tab) tab.dirty = true;
  _renderTabBar();
  _updateSaveBtnState(id);
}

// ── Markdown editor (Milkdown via Agenda-style mk-mount) ────────────────────
// Each .md tab gets a textarea.mk-mount whose value is the body markdown
// (image URLs pre-rewritten to served URLs); milkdown-mount.js wraps it in
// a WYSIWYG editor with a built-in MD/WYSIWYG toolbar toggle.
const _tabMdMounts    = {};   // id → textarea handle (.mkMount)
const _tabFmCMs       = {};   // id → CodeMirror (front matter YAML)
const _tabMdReflowers = {};   // id → ResizeObserver

// Walk the rendered ProseMirror DOM and rewrite each <img>'s `src` to the
// served URL — without touching the editor's underlying model. The model
// keeps the authored path (e.g. `images/foo.png`), so getMarkdown() returns
// clean markdown on save and Hexo sees exactly what the user wrote.
const _tabImgObservers = {};

function _rewriteImgsInPlace(root, pid) {
  if (!root) return;
  root.querySelectorAll('img').forEach(img => {
    const cur = img.getAttribute('src') || '';
    if (!cur) return;
    if (cur.includes('/api/files')) return;            // already rewritten
    if (/^(https?:|data:|\/\/)/i.test(cur)) return;    // absolute, leave as-is
    const resolved = _resolveImagePathForEditor(cur, pid);
    if (resolved !== cur) {
      img.setAttribute('data-original-src', cur);
      img.setAttribute('src', resolved);
    }
  });
}

function _attachImgSrcRewriter(id, pid) {
  const root = document.getElementById('tc-' + id);
  const pm = root?.querySelector('.ProseMirror');
  if (!pm) return;
  // Initial pass for whatever's already rendered
  _rewriteImgsInPlace(pm, pid);
  // Watch for newly added or changed <img> elements (paste, image insert,
  // ProseMirror re-render). Filtering attributeFilter to `src` avoids loops
  // when our own setAttribute fires a notification.
  const obs = new MutationObserver(() => _rewriteImgsInPlace(pm, pid));
  obs.observe(pm, { childList: true, subtree: true,
                    attributes: true, attributeFilter: ['src'] });
  // Replace any existing observer (e.g. on re-mount)
  if (_tabImgObservers[id]) try { _tabImgObservers[id].disconnect(); } catch (e) {}
  _tabImgObservers[id] = obs;
}

function _reflowMdEditor(id) {
  const mount = document.getElementById('paneEditor-' + id);
  if (!mount) return;
  const stackH = mount.clientHeight;
  if (stackH < 1) return;

  // Editor mount may not be present yet (mk-mount wraps the textarea async).
  const wrap = mount.querySelector('.mk-mount-wrap');
  if (!wrap) return;
  const body = wrap.querySelector('.ie-mk-body');
  if (!body) return;

  const wrapToolbar = wrap.querySelector('.ie-mk-toolbar');
  const innerPhotos = body.querySelector('.md-photos-banner');
  const toolbarH    = wrapToolbar ? wrapToolbar.offsetHeight : 40;
  const photosH     = innerPhotos && !innerPhotos.classList.contains('d-none')
                        ? innerPhotos.offsetHeight : 0;
  const wrapBd      = 2;

  // Lock wrap and body to definite pixel heights — ProseMirror's percentage
  // min-height doesn't cascade reliably through Milkdown's wrappers, so we
  // size them manually to make body's overflow-y: auto fire dependably.
  wrap.style.flex   = '0 0 auto';
  wrap.style.height = stackH + 'px';

  const bodyH = Math.max(80, stackH - toolbarH - wrapBd);
  body.style.flex   = '0 0 auto';
  body.style.height = bodyH + 'px';

  const editorMinH = Math.max(60, bodyH - photosH);
  const milkdownRoot = body.querySelector(':scope > div:not(.md-photos-banner)');
  if (milkdownRoot) milkdownRoot.style.minHeight = editorMinH + 'px';
  body.querySelectorAll('.milkdown, .editor, .ProseMirror').forEach(el => {
    el.style.minHeight = editorMinH + 'px';
  });
  const taSrc = body.querySelector('textarea.ie-mk-ta');
  if (taSrc) {
    taSrc.style.minHeight = editorMinH + 'px';
    taSrc.style.height    = editorMinH + 'px';
  }

}

async function _openMdEditorTab(pid, path, name, content, isDraft) {
  const tabContent = document.getElementById('editorTabContent');
  if (!tabContent) return;

  const id = 'tab' + (++_tabCounter);
  const { fm, body, photos } = _parseMdSource(content);
  const hadFm    = content.startsWith('---');
  const isHexo   = path.startsWith('source/_posts/') || path.startsWith('source/_drafts/');
  const useFmBlock = hadFm || isHexo;

  _tabs.push({ id, pid, path, name, kind: 'md-editor', isDraft, dirty: false });
  _renderTabBar();

  const div = document.createElement('div');
  div.id = 'tc-' + id;
  div.style.display = 'none';
  div.style.height = '100%';
  div.style.position = 'relative';
  div.innerHTML = `
    <div class="md-editor-mount" id="paneEditor-${id}"></div>
    <button class="md-pane-save d-none" id="paneSaveBtn-${id}" title="Save (Ctrl+S)">
      <i class="bi bi-floppy me-1"></i>Save
    </button>`;
  tabContent.appendChild(div);

  const mountEl = div.querySelector('#paneEditor-' + id);
  const ta = document.createElement('textarea');
  ta.className = 'mk-mount';
  // FM lives as the first fenced yaml code block inside Milkdown.
  // On save the block is extracted and serialised back to ---\nfm\n--- form.
  ta.value = useFmBlock ? '```yaml\n' + fm + '\n```\n\n' + body : body;
  ta.dataset.hasFmBlock = useFmBlock ? '1' : '0';
  mountEl.appendChild(ta);
  ta.onMkInput = () => _markDirty(id);
  _tabMdMounts[id] = ta;

  const ro = new ResizeObserver(() => _reflowMdEditor(id));
  ro.observe(mountEl);
  _tabMdReflowers[id] = ro;

  ta.addEventListener('mk-mounted', () => {
    _injectMkToolbarExtras(id, pid, path, isDraft);
    _reflowMdEditor(id);
  });
  ta.addEventListener('mk-ready', () => {
    const editorBody = div.querySelector('.ie-mk-body');
    if (editorBody) {
      let banner = editorBody.querySelector('.md-photos-banner');
      if (!banner) {
        banner = document.createElement('div');
        banner.className = 'md-photos-banner inside-editor';
        banner.id = 'panePhotos-' + id;
        editorBody.insertBefore(banner, editorBody.firstChild);
      }
      _renderPhotosBanner(banner, photos, pid);
    }
    _reflowMdEditor(id);
    _attachImgSrcRewriter(id, pid);
  });

  div.querySelector('#paneSaveBtn-' + id).addEventListener('click', () => _paneTabSave(id));

  div.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      _paneTabSave(id);
    }
  });

  _switchTab(id);
  requestAnimationFrame(() => requestAnimationFrame(() => _reflowMdEditor(id)));
}


function _injectMkToolbarExtras(id, pid, path, isDraft) {
  const root = document.getElementById('tc-' + id);
  if (!root) return;
  const tb = root.querySelector('.ie-mk-toolbar');
  if (!tb || tb.querySelector('.mk-pane-kebab')) return;
  const publishItem = isDraft
    ? `<li><button class="dropdown-item" id="panePublishBtn-${id}">
         <i class="bi bi-send me-2"></i>Publish
       </button></li><li><hr class="dropdown-divider"></li>`
    : '';
  const wrap = document.createElement('div');
  wrap.className = 'dropdown mk-pane-kebab ms-1';
  wrap.innerHTML = `
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown"
            aria-expanded="false" title="More">
      <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      ${publishItem}
      <li><button class="dropdown-item" id="paneRenameBtn-${id}">
        <i class="bi bi-pencil me-2"></i>Rename
      </button></li>
      <li><button class="dropdown-item" id="paneMoveBtn-${id}">
        <i class="bi bi-folder-symlink me-2"></i>Move
      </button></li>
      <li><hr class="dropdown-divider"></li>
      <li><button class="dropdown-item text-danger" id="paneDeleteBtn-${id}">
        <i class="bi bi-trash me-2"></i>Delete
      </button></li>
    </ul>`;
  tb.appendChild(wrap);
  wrap.querySelector('#paneDeleteBtn-' + id).addEventListener('click', () => _paneTabDelete(id));
  wrap.querySelector('#paneRenameBtn-' + id).addEventListener('click', () => {
    promptRename(pid, path, newPath => {
      const tab = _tabs.find(t => t.id === id);
      if (tab) { tab.path = newPath; tab.name = newPath.split('/').pop(); _renderTabBar(); }
      if (document.getElementById('postsPanel')) _postsLoadFn(window._postsCurrentType || 'post');
    });
  });
  wrap.querySelector('#paneMoveBtn-' + id).addEventListener('click', () => {
    promptMove(pid, path, newPath => {
      const tab = _tabs.find(t => t.id === id);
      if (tab) { tab.path = newPath; _renderTabBar(); }
      if (document.getElementById('postsPanel')) _postsLoadFn(window._postsCurrentType || 'post');
    });
  });
  if (isDraft) {
    wrap.querySelector('#panePublishBtn-' + id)?.addEventListener('click', () => _paneTabPublish(id, pid, path));
  }
}

function _updateSaveBtnState(id) {
  const tab = _tabs.find(t => t.id === id);
  const btn = document.getElementById('paneSaveBtn-' + id);
  if (!tab || !btn) return;
  btn.classList.toggle('d-none', !tab.dirty);
}

// Parse a markdown source into { fm: yamlString, body: string, photos: string[] }
function _parseMdSource(text) {
  const out = { fm: '', body: text, photos: [] };
  if (!text.startsWith('---')) return out;
  const end = text.indexOf('\n---', 3);
  if (end === -1) return out;
  out.fm   = text.substring(3, end).replace(/^\n/, '').replace(/\n$/, '');
  out.body = text.substring(end + 4).replace(/^\s*\n/, '');

  // photos: inline array  →  photos: [a, b, c]
  const inline = out.fm.match(/^photos:\s*\[(.+?)\]\s*$/m);
  if (inline) {
    out.photos = inline[1].split(',').map(s => s.trim().replace(/^["']|["']$/g, ''))
                                     .filter(Boolean);
  } else {
    // photos: block list  →  photos:\n  - a\n  - b
    const block = out.fm.match(/^photos:\s*\n((?:[ \t]*-\s*.+\n?)+)/m);
    if (block) {
      out.photos = [...block[1].matchAll(/^[ \t]*-\s*(.+?)\s*$/gm)]
        .map(m => m[1].trim().replace(/^["']|["']$/g, ''))
        .filter(Boolean);
    } else {
      // photos: single value on the same line  →  photos: foo.jpg
      const single = out.fm.match(/^photos:\s*([^\s\[].*?)\s*$/m);
      if (single) out.photos = [single[1].replace(/^["']|["']$/g, '')];
    }
  }
  return out;
}

function _renderPhotosBanner(el, photos, pid) {
  if (!el) return;
  if (!photos.length) { el.classList.add('d-none'); el.innerHTML = ''; return; }
  el.classList.remove('d-none');
  el.innerHTML = photos.map(p => {
    const url = _resolveImagePathForEditor(p, pid);
    return `<img src="${esc(url)}" alt="" loading="lazy">`;
  }).join('');
}

// images/foo.png → /api/files?project_id=X&action=serve&path=source/images/foo.png
// Already-absolute or already-rewritten URLs pass through unchanged.
function _resolveImagePathForEditor(src, pid) {
  if (!src) return src;
  if (/^(https?:|data:|\/\/)/i.test(src)) return src;
  if (src.includes('/api/files')) return src;
  let rel = src.replace(/^\.?\//, '');
  if (!rel.startsWith('source/')) rel = 'source/' + rel;
  return `/api/files?project_id=${pid}&action=serve&path=${encodeURIComponent(rel)}`;
}

// Rewrite ![]() image URLs for in-editor display.
function _rewriteImagePathsForEditor(markdown, pid) {
  return markdown.replace(/(!\[[^\]]*\]\()([^)\s]+)([^)]*\))/g, (m, pre, src, post) =>
    pre + _resolveImagePathForEditor(src, pid) + post);
}

// Reverse: strip the /api/files prefix back to the original relative form.
function _reverseRewriteImagePaths(markdown, pid) {
  const prefix = `/api/files?project_id=${pid}&action=serve&path=`;
  return markdown.replace(/(!\[[^\]]*\]\()([^)\s]+)([^)]*\))/g, (m, pre, src, post) => {
    if (src.startsWith(prefix)) {
      let p = decodeURIComponent(src.substring(prefix.length));
      if (p.startsWith('source/')) p = p.substring(7);
      return pre + p + post;
    }
    return m;
  });
}

async function _paneTabSave(id) {
  const tab = _tabs.find(t => t.id === id);
  if (!tab) return;
  const status = document.getElementById('paneStatus-' + id);
  let content;

  if (tab.kind === 'md-editor') {
    const mount = _tabMdMounts[id];
    if (!mount?.mkMount) { showError('Editor not ready'); return; }
    const md = mount.mkMount.getContent() ?? '';
    // FM lives as the first ```yaml block; extract it and convert to ---\n...\n---
    const fmMatch = md.match(/^```yaml\r?\n([\s\S]*?)\n?```(\r?\n|$)/);
    if (fmMatch) {
      const fm   = fmMatch[1];
      const body = md.substring(fmMatch[0].length).replace(/^\s*\n/, '');
      content = '---\n' + fm + '\n---\n\n' + body;
    } else {
      content = md;
    }
  } else {
    if (!_tabCMs[id]) return;
    content = _tabCMs[id].getValue();
  }

  if (status) status.textContent = 'Saving…';
  const res  = await fetch('/api/files?project_id=' + tab.pid, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'write', path: tab.path, content }),
  });
  const data = await res.json();
  if (data.ok) {
    tab.dirty = false;
    if (tab.kind !== 'md-editor') _tabCMs[id]?.markClean();
    if (status) status.textContent = 'Saved.';
    showSuccess('Saved');
    _renderTabBar();
    _updateSaveBtnState(id);
  } else {
    if (status) status.textContent = '';
    showError(data.error || 'Save failed');
  }
}

async function _paneTabDelete(id) {
  const tab = _tabs.find(t => t.id === id);
  if (!tab) return;
  confirmAction(`Delete "${tab.name}"? This cannot be undone.`, async () => {
    const res  = await fetch('/api/files?project_id=' + tab.pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'delete', path: tab.path }),
    });
    const data = await res.json();
    if (data.ok) {
      showSuccess('Deleted ' + tab.name);
      _closeTab(id);
      const browser = document.getElementById('fileBrowser');
      if (browser) {
        const currentPath = browser.dataset.currentPath || '';
        if (typeof loadDir === 'function') loadDir(currentPath);
      }
      if (document.getElementById('postsPanel')) _postsLoadFn(window._postsCurrentType || 'post');
    } else {
      showError(data.error || 'Delete failed');
    }
  });
}

async function _paneTabPublish(id, pid, path) {
  const tab = _tabs.find(t => t.id === id);
  if (!tab) return;
  confirmAction('Publish draft to _posts? This moves the file.', async () => {
    const relpath = tab.path.replace('source/_drafts/', '');
    const res  = await fetch('/api/posts?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'publish', relpath }),
    });
    const data = await res.json();
    if (data.ok) {
      showSuccess('Published!');
      _closeTab(id);
      _postsLoadFn('post');
    } else {
      showError(data.error || 'Publish failed');
    }
  });
}

function promptRename(pid, path, onSuccess) {
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('renameModal'));
  const input = document.getElementById('renameInput');
  const err   = document.getElementById('renameError');
  input.value = path.split('/').pop();
  err.classList.add('d-none');

  const confirm = async () => {
    const newName = input.value.trim();
    if (!newName) { err.textContent = 'Name required'; err.classList.remove('d-none'); return; }
    const res  = await fetch('/api/files?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'rename', path, newName }),
    });
    const data = await res.json();
    if (!data.ok) { err.textContent = data.error || 'Error'; err.classList.remove('d-none'); return; }
    modal.hide();
    showSuccess('Renamed');
    onSuccess(data.newPath);
  };
  document.getElementById('renameConfirmBtn').onclick = confirm;
  input.onkeydown = e => { if (e.key === 'Enter') confirm(); };
  modal.show();
  setTimeout(() => { input.select(); }, 300);
}

function promptMove(pid, path, onSuccess) {
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('moveModal'));
  const input = document.getElementById('moveInput');
  const err   = document.getElementById('moveError');
  const parts = path.split('/');
  parts.pop();
  input.value = parts.join('/') + '/';
  err.classList.add('d-none');

  const confirm = async () => {
    const dir  = input.value.trim().replace(/\/$/, '');
    const file = path.split('/').pop();
    const newPath = dir + '/' + file;
    const res  = await fetch('/api/files?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'move', path, newPath }),
    });
    const data = await res.json();
    if (!data.ok) { err.textContent = data.error || 'Error'; err.classList.remove('d-none'); return; }
    modal.hide();
    showSuccess('Moved');
    onSuccess(data.newPath);
  };
  document.getElementById('moveConfirmBtn').onclick = confirm;
  input.onkeydown = e => { if (e.key === 'Enter') confirm(); };
  modal.show();
  setTimeout(() => { const l = input.value.length; input.setSelectionRange(l, l); input.focus(); }, 300);
}

// Modal editor (used from posts pane where there's no tab bar)
let _modalCM = null;

function _renderModalEditor(pid, path, name, content) {
  document.getElementById('fileEditName').textContent   = name;
  document.getElementById('fileEditPath').value         = path;
  document.getElementById('fileEditPid').value          = pid;
  document.getElementById('fileEditStatus').textContent = '';

  const container = document.getElementById('fileEditCm');
  container.innerHTML = '';
  if (_modalCM) { try { _modalCM.toTextArea(); } catch(e) {} _modalCM = null; }
  _modalCM = CodeMirror(container, {
    value: content, mode: modeForFile(name), theme: 'dracula',
    lineNumbers: true, lineWrapping: true, tabSize: 2,
    extraKeys: { 'Ctrl-S': saveFile, 'Cmd-S': saveFile },
  });

  const modal = document.getElementById('fileEditModal');
  bootstrap.Modal.getOrCreateInstance(modal).show();
  modal.addEventListener('shown.bs.modal', () => _modalCM?.refresh(), { once: true });
}

async function saveFile() {
  const pid     = document.getElementById('fileEditPid').value;
  const path    = document.getElementById('fileEditPath').value;
  const content = _modalCM ? _modalCM.getValue() : '';
  const status  = document.getElementById('fileEditStatus');
  status.textContent = 'Saving…';
  const res  = await fetch('/api/files?project_id=' + pid, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action: 'write', path, content }),
  });
  const data = await res.json();
  if (data.ok) { status.textContent = 'Saved.'; showSuccess('Saved'); }
  else { status.textContent = ''; showError(data.error || 'Save failed'); }
}

document.getElementById('fileEditSave')?.addEventListener('click', saveFile);

// ── File browser ──────────────────────────────────────────────────────────────
const fileBrowser = document.getElementById('fileBrowser');
if (fileBrowser) {
  const pid = fileBrowser.dataset.projectId;
  let fileBrowserCurrentPath = '';

  async function loadDir(relPath) {
    fileBrowserCurrentPath = relPath;
    fileBrowser.dataset.currentPath = relPath;
    const res  = await fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent(relPath)}`);
    const data = await res.json();
    if (data.error) { renderBrowserError(data.error); return; }
    renderCrumb(relPath, '#fileCrumb', loadDir);
    renderEntries(data.entries);
  }

  function renderEntries(entries) {
    const list = document.getElementById('fileList');
    if (!entries.length) { list.innerHTML = '<div class="list-group-item text-muted small">Empty directory</div>'; return; }
    list.innerHTML = entries.map(e => {
      const icon = e.type === 'dir' ? 'bi-folder-fill text-warning' : 'bi-file-text text-secondary';
      const kebab = e.type === 'file' ? `
        <div class="dropdown flex-shrink-0">
          <button class="btn btn-xs btn-outline-secondary border-0 px-1" data-bs-toggle="dropdown" aria-expanded="false" title="More">
            <i class="bi bi-three-dots-vertical"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item small btn-open-file" data-path="${esc(e.path)}" data-name="${esc(e.name)}">
              <i class="bi bi-pencil me-2"></i>Edit
            </button></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item small text-danger btn-del-file" data-path="${esc(e.path)}" data-name="${esc(e.name)}">
              <i class="bi bi-trash me-2"></i>Delete
            </button></li>
          </ul>
        </div>` : '';
      return `<div class="list-group-item list-group-item-action d-flex align-items-center gap-2"
        data-type="${e.type}" data-path="${esc(e.path)}" data-name="${esc(e.name)}" style="cursor:pointer">
        <i class="bi ${icon} flex-shrink-0"></i>
        <span class="flex-grow-1 text-truncate">${esc(e.name)}</span>
        ${e.size != null ? `<small class="text-muted">${fmtSize(e.size)}</small>` : ''}
        ${kebab}
      </div>`;
    }).join('');
    list.querySelectorAll('[data-type]').forEach(row => {
      row.addEventListener('click', ev => {
        if (ev.target.closest('button')) return;
        row.dataset.type === 'dir' ? loadDir(row.dataset.path) : openFileEditor(pid, row.dataset.path, row.dataset.name);
      });
    });
    list.querySelectorAll('.btn-open-file').forEach(btn => {
      btn.addEventListener('click', () => openFileEditor(pid, btn.dataset.path, btn.dataset.name));
    });
    list.querySelectorAll('.btn-del-file').forEach(btn => {
      btn.addEventListener('click', () => {
        confirmAction(`Delete "${btn.dataset.name}"?`, async () => {
          const r = await fetch('/api/files?project_id=' + pid, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'delete', path: btn.dataset.path }),
          });
          const d = await r.json();
          if (d.ok) {
            showSuccess('Deleted ' + btn.dataset.name);
            // Close the editor tab if this file was open
            const openTab = _tabs.find(t => t.path === btn.dataset.path);
            if (openTab) _closeTab(openTab.id);
            loadDir(fileBrowserCurrentPath);
          } else showError(d.error || 'Delete failed');
        });
      });
    });
  }

  function renderBrowserError(msg) {
    document.getElementById('fileList').innerHTML = `<div class="list-group-item text-danger small">${esc(msg)}</div>`;
  }

  // Drag-and-drop upload — whole left pane as drop zone
  const dropZone = fileBrowser.querySelector('.split-list') || fileBrowser;
  let dragDepth = 0;
  dropZone.addEventListener('dragenter', e => {
    e.preventDefault(); dragDepth++;
    dropZone.classList.add('drag-over');
  });
  dropZone.addEventListener('dragleave', () => {
    if (--dragDepth <= 0) { dragDepth = 0; dropZone.classList.remove('drag-over'); }
  });
  dropZone.addEventListener('dragover', e => {
    e.preventDefault(); e.dataTransfer.dropEffect = 'copy';
  });
  dropZone.addEventListener('drop', async e => {
    e.preventDefault(); dragDepth = 0; dropZone.classList.remove('drag-over');
    const files = [...(e.dataTransfer.files || [])];
    if (!files.length) return;
    let ok = 0;
    for (const file of files) {
      const fd = new FormData();
      fd.append('project_id', pid);
      fd.append('folder', fileBrowserCurrentPath);
      fd.append('accept', 'any');
      fd.append('file', file);
      try {
        const res  = await fetch('/api/upload', { method: 'POST', body: fd });
        const data = await res.json();
        data.ok ? ok++ : showError(`${file.name}: ${data.error || 'Upload failed'}`);
      } catch(err) { showError(`${file.name}: ${err.message}`); }
    }
    if (ok > 0) { showSuccess(`Uploaded ${ok} file${ok > 1 ? 's' : ''}`); loadDir(fileBrowserCurrentPath); }
  });

  const initialPath = new URLSearchParams(location.search).get('path') || '';
  loadDir(initialPath);
}

// ── Command runner ────────────────────────────────────────────────────────────
const commandRunner = document.getElementById('commandRunner');
if (commandRunner) {
  const pid    = commandRunner.dataset.projectId;
  const output = document.getElementById('cmdOutput');
  document.getElementById('clearOutput')?.addEventListener('click', () => { output.textContent = ''; });
  commandRunner.querySelectorAll('.btn-run-cmd').forEach(btn => {
    btn.addEventListener('click', async () => {
      const cmd = btn.dataset.cmd;
      output.textContent += `\n$ ${cmd}\n`;
      btn.disabled = true;
      const res  = await fetch('/api/run', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ project_id: parseInt(pid), cmd }),
      });
      const data = await res.json();
      output.textContent += data.error ? `ERROR: ${data.error}\n` : (data.output || '(no output)') + `\nExit: ${data.exit_code}\n`;
      output.scrollTop = output.scrollHeight;
      btn.disabled = false;
    });
  });
}

// ── Post tree helpers ─────────────────────────────────────────────────────────
let _postFolderCounter = 0;

function buildPostTree(items) {
  const root = { posts: [], children: {} };
  for (const item of items) {
    const segs = item.folder ? item.folder.split('/').filter(Boolean) : [];
    let node = root;
    for (const seg of segs) {
      if (!node.children[seg]) node.children[seg] = { posts: [], children: {} };
      node = node.children[seg];
    }
    node.posts.push(item);
  }
  return root;
}

function countTreePosts(node) {
  let c = node.posts.length;
  for (const child of Object.values(node.children)) c += countTreePosts(child);
  return c;
}

function renderPostItem(p, pid, type) {
  const rel  = esc(p.relpath);
  const path = esc(p.path);
  const publishItem = type === 'draft'
    ? `<li><button class="dropdown-item small btn-pub-post" data-relpath="${rel}" data-path="${path}">
         <i class="bi bi-send me-2"></i>Publish
       </button></li><li><hr class="dropdown-divider"></li>`
    : '';
  return `<div class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-1 px-2 post-item-row"
    data-path="${path}" data-name="${esc(p.filename)}"
    data-relpath="${rel}" data-type="${esc(type)}" style="cursor:pointer">
    <div class="flex-grow-1 min-w-0">
      <div class="text-truncate small">${esc(p.title)}</div>
      <small class="text-muted">${p.date ? esc(p.date.substring(0,10)) : '—'} &bull; <code class="small">${esc(p.filename)}</code></small>
    </div>
    <div class="dropdown flex-shrink-0">
      <button class="btn btn-xs btn-outline-secondary border-0 px-1 post-row-menu" data-bs-toggle="dropdown" aria-expanded="false" title="More">
        <i class="bi bi-three-dots-vertical"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        ${publishItem}
        <li><button class="dropdown-item small btn-dup-post" data-relpath="${rel}" data-type="${esc(type)}">
          <i class="bi bi-copy me-2"></i>Duplicate
        </button></li>
        <li><button class="dropdown-item small btn-rename-post" data-path="${path}">
          <i class="bi bi-pencil me-2"></i>Rename
        </button></li>
        <li><button class="dropdown-item small btn-move-post" data-path="${path}">
          <i class="bi bi-folder-symlink me-2"></i>Move
        </button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item small text-danger btn-del-post" data-relpath="${rel}" data-type="${esc(type)}">
          <i class="bi bi-trash me-2"></i>Delete
        </button></li>
      </ul>
    </div>
  </div>`;
}

function renderPostTree(node, pid, type, expandPath = []) {
  let html = '';
  if (node.posts.length) {
    html += '<div class="list-group mb-1">';
    for (const p of node.posts) html += renderPostItem(p, pid, type);
    html += '</div>';
  }
  for (const [name, child] of Object.entries(node.children)) {
    const id     = 'pfg' + (++_postFolderCounter);
    const count  = countTreePosts(child);
    const isOpen = expandPath.length > 0 && expandPath[0] === name;
    const sub    = isOpen ? expandPath.slice(1) : [];
    html += `<div class="mb-1">
      <div class="post-section-header" data-bs-toggle="collapse" data-bs-target="#${id}" aria-expanded="${isOpen}">
        <i class="bi bi-chevron-right post-section-chevron"></i>
        <span class="flex-grow-1">${esc(name)}</span>
        <span class="badge bg-secondary" style="font-size:.7rem;font-weight:400">${count}</span>
      </div>
      <div class="collapse ${isOpen ? 'show' : ''} ps-2" id="${id}">
        ${renderPostTree(child, pid, type, sub)}
      </div>
    </div>`;
  }
  return html;
}

function _wirePostList(list, pid, currentTypeRef) {
  list.querySelectorAll('.post-item-row').forEach(row => {
    row.addEventListener('click', ev => {
      if (ev.target.closest('button')) return;
      openFileEditor(pid, row.dataset.path, row.dataset.name);
    });
  });
  list.querySelectorAll('.btn-del-post').forEach(btn => {
    btn.addEventListener('click', () => confirmAction(`Delete "${btn.dataset.relpath}"?`, async () => {
      const res = await fetch('/api/posts?project_id=' + pid, {
        method: 'DELETE', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ relpath: btn.dataset.relpath, type: btn.dataset.type }),
      });
      const d = await res.json();
      d.ok ? _postsLoadFn(currentTypeRef.type) : showError(d.error || 'Error');
    }));
  });
  list.querySelectorAll('.btn-dup-post').forEach(btn => {
    btn.addEventListener('click', async () => {
      const res = await fetch('/api/posts?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'duplicate', relpath: btn.dataset.relpath, type: btn.dataset.type }),
      });
      const d = await res.json();
      d.ok ? (showSuccess('Duplicated as ' + d.relpath), _postsLoadFn(currentTypeRef.type)) : showError(d.error || 'Error');
    });
  });
  list.querySelectorAll('.btn-pub-post').forEach(btn => {
    btn.addEventListener('click', () => confirmAction('Publish draft to _posts?', async () => {
      const res = await fetch('/api/posts?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'publish', relpath: btn.dataset.relpath }),
      });
      const d = await res.json();
      if (d.ok) {
        showSuccess('Published!');
        const tab = _tabs.find(t => t.path === btn.dataset.path);
        if (tab) _closeTab(tab.id);
        _postsLoadFn('post');
      } else { showError(d.error || 'Error'); }
    }));
  });
  list.querySelectorAll('.btn-rename-post').forEach(btn => {
    btn.addEventListener('click', () => {
      promptRename(pid, btn.dataset.path, newPath => {
        const tab = _tabs.find(t => t.path === btn.dataset.path);
        if (tab) { tab.path = newPath; tab.name = newPath.split('/').pop(); _renderTabBar(); }
        _postsLoadFn(currentTypeRef.type);
      });
    });
  });
  list.querySelectorAll('.btn-move-post').forEach(btn => {
    btn.addEventListener('click', () => {
      promptMove(pid, btn.dataset.path, newPath => {
        const tab = _tabs.find(t => t.path === btn.dataset.path);
        if (tab) { tab.path = newPath; _renderTabBar(); }
        _postsLoadFn(currentTypeRef.type);
      });
    });
  });
}

// Shared reference so modal "Create" button can reload the list
let _postsLoadFn = () => {};

// ── Posts panel (Hexo) ───────────────────────────────────────────────────────
const postsPanel = document.getElementById('postsPanel');
if (postsPanel) {
  const pid          = postsPanel.dataset.projectId;
  const postsList    = document.getElementById('postsList');
  const searchResults= document.getElementById('searchResults');
  let   currentType  = 'post';
  _postsLoadFn = type => loadPostsByType(type);

  function setActiveBtn(btnId) {
    ['showPosts','showPages'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.toggle('btn-outline-primary',   id === btnId);
      el.classList.toggle('active',                id === btnId);
      el.classList.toggle('btn-outline-secondary', id !== btnId);
    });
  }

  // ── Filter pill (tag/category from Dashboard) ───────────────────────────────
  let _activeFilter = null;  // {kind, value}
  function _renderFilterBar() {
    let bar = document.getElementById('postsFilterBar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'postsFilterBar';
      bar.className = 'mb-2';
      postsList.parentElement.insertBefore(bar, postsList);
    }
    if (!_activeFilter) { bar.innerHTML = ''; return; }
    bar.innerHTML = `<span class="badge bg-info-subtle text-info border d-inline-flex align-items-center gap-1">
      <i class="bi bi-funnel-fill"></i>
      <span>${esc(_activeFilter.kind)}: <strong>${esc(_activeFilter.value)}</strong></span>
      <button type="button" class="btn-close btn-close-white btn-close-sm ms-1" id="clearFilterBtn"
              style="font-size:.6rem" aria-label="Clear filter"></button>
    </span>`;
    document.getElementById('clearFilterBtn').addEventListener('click', () => {
      _activeFilter = null;
      const url = new URL(location);
      url.searchParams.delete('filter');
      history.replaceState({}, '', url);
      loadPostsByType(currentType);
    });
  }

  function _applyFilter(items) {
    if (!_activeFilter) return items;
    const { kind, value } = _activeFilter;
    return items.filter(it => {
      const list = kind === 'tag' ? (it.tags || []) : (it.categories || []);
      return list.some(v => String(v).toLowerCase() === value.toLowerCase());
    });
  }

  async function loadPostsByType(type) {
    // Drafts are merged into the posts view — always normalise to 'post'
    if (type === 'draft') type = 'post';
    currentType = type;
    window._postsCurrentType = type;
    setActiveBtn(type === 'page' ? 'showPages' : 'showPosts');
    const sq = document.getElementById('searchQuery');
    if (sq) sq.value = '';
    searchResults.classList.add('d-none');
    postsList.classList.remove('d-none');
    postsList.innerHTML = '<div class="text-muted small">Loading…</div>';

    if (type === 'post') {
      // Fetch posts + drafts together
      const [pr, dr] = await Promise.all([
        fetch(`/api/posts?project_id=${pid}&type=post`),
        fetch(`/api/posts?project_id=${pid}&type=draft`),
      ]);
      const [postsData, draftsData] = await Promise.all([pr.json(), dr.json()]);

      const posts  = _applyFilter(postsData.items  || []);
      const drafts = _applyFilter(draftsData.items || []);

      if (!posts.length && !drafts.length) {
        postsList.innerHTML = `<div class="text-muted small">${_activeFilter ? 'No posts match this filter.' : 'No posts yet.'}</div>`;
        _renderFilterBar();
        return;
      }

      let html = '';
      if (drafts.length) {
        _postFolderCounter = 0;
        const draftTree = renderPostTree(buildPostTree(drafts), pid, 'draft');
        html += `<div class="mb-2">
          <div class="post-section-header" data-bs-toggle="collapse" data-bs-target="#vfDrafts" aria-expanded="false">
            <i class="bi bi-chevron-right post-section-chevron"></i>
            <i class="bi bi-pencil-square me-1" style="font-size:.75rem;opacity:.7"></i>
            <span class="flex-grow-1">Drafts</span>
            <span class="badge bg-secondary" style="font-size:.7rem;font-weight:400">${drafts.length}</span>
          </div>
          <div class="collapse ps-2" id="vfDrafts">
            <div class="posts-tree">${draftTree}</div>
          </div>
        </div>`;
      }
      if (posts.length) {
        const expandPath = posts[0]?.folder ? posts[0].folder.split('/').filter(Boolean) : [];
        _postFolderCounter = 0;
        html += `<div class="posts-tree">${renderPostTree(buildPostTree(posts), pid, 'post', expandPath)}</div>`;
      }
      postsList.innerHTML = html;
      _wirePostList(postsList, pid, { type: 'post' });
      _renderFilterBar();
    } else {
      // Pages
      const res  = await fetch(`/api/posts?project_id=${pid}&type=${type}`);
      const data = await res.json();
      if (data.missing_dir) {
        postsList.innerHTML = `<div class="text-muted small"><code>source/</code> not found.</div>`;
        _renderFilterBar();
        return;
      }
      const items = _applyFilter(data.items || []);
      if (!items.length) {
        postsList.innerHTML = `<div class="text-muted small">${_activeFilter ? 'No pages match this filter.' : 'No pages yet.'}</div>`;
        _renderFilterBar();
        return;
      }
      const expandPath = items[0]?.folder ? items[0].folder.split('/').filter(Boolean) : [];
      _postFolderCounter = 0;
      postsList.innerHTML = `<div class="posts-tree">${renderPostTree(buildPostTree(items), pid, type, expandPath)}</div>`;
      _wirePostList(postsList, pid, { type });
      _renderFilterBar();
    }
  }

  // Read ?filter=tag:foo or ?filter=category:bar from URL
  const urlFilter = new URLSearchParams(location.search).get('filter');
  if (urlFilter && urlFilter.includes(':')) {
    const [k, ...rest] = urlFilter.split(':');
    if (k === 'tag' || k === 'category') {
      _activeFilter = { kind: k, value: rest.join(':') };
    }
  }

  document.getElementById('showPosts')?.addEventListener('click',  () => loadPostsByType('post'));
  document.getElementById('showPages')?.addEventListener('click',  () => loadPostsByType('page'));

  let _newTypeTarget = 'post';

  function openNewPostModal(type) {
    _newTypeTarget = type;
    const labels = { post: 'New Post', draft: 'New Draft', page: 'New Page' };
    document.getElementById('newPostModalTitle').textContent = labels[type] ?? 'New';
    document.getElementById('newPostTitle').value  = '';
    document.getElementById('newPostFolder').value = '';
    document.getElementById('newPostError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('newPostModal')).show();
    setTimeout(() => document.getElementById('newPostTitle').focus(), 300);
  }

  document.getElementById('newItemBtn')?.addEventListener('click', () => {
    openNewPostModal(currentType === 'page' ? 'page' : 'post');
  });

  postsPanel.querySelectorAll('.new-type-btn').forEach(btn => {
    btn.addEventListener('click', () => openNewPostModal(btn.dataset.type));
  });

  document.getElementById('newPostCreateBtn')?.addEventListener('click', async () => {
    const title   = document.getElementById('newPostTitle').value.trim();
    const folder  = document.getElementById('newPostFolder').value.trim();
    const errEl   = document.getElementById('newPostError');
    if (!title) { errEl.textContent = 'Title required'; errEl.classList.remove('d-none'); return; }
    const date = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const fm   = `---\ntitle: "${title.replace(/"/g, '\\"')}"\ndate: ${date}\ntags: []\n---`;
    const res  = await fetch('/api/posts?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ type: _newTypeTarget, title, folder, frontmatter: fm }),
    });
    const data = await res.json();
    if (!data.ok) { errEl.textContent = data.error || 'Error'; errEl.classList.remove('d-none'); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('newPostModal')).hide();
    await loadPostsByType('post');
    openFileEditor(pid, data.path, data.filename);
  });

  document.getElementById('newPostTitle')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('newPostCreateBtn')?.click();
  });

  let _searchTimer = null;
  document.getElementById('searchQuery')?.addEventListener('input', e => {
    clearTimeout(_searchTimer);
    const q = e.target.value.trim();
    if (!q) {
      searchResults.classList.add('d-none');
      postsList.classList.remove('d-none');
      return;
    }
    postsList.classList.add('d-none');
    searchResults.classList.remove('d-none');
    searchResults.innerHTML = '<div class="text-muted small">Searching…</div>';
    _searchTimer = setTimeout(async () => {
      const res  = await fetch(`/api/search?project_id=${pid}&q=${encodeURIComponent(q)}`);
      const data = await res.json();
      if (data.error) { searchResults.innerHTML = `<div class="text-danger small">${esc(data.error)}</div>`; return; }
      if (!data.results.length) { searchResults.innerHTML = '<div class="text-muted small">No results.</div>'; return; }
      searchResults.innerHTML = data.results.map(r => `
        <div class="list-group-item list-group-item-action py-2 search-result"
             data-path="${esc(r.file)}" data-name="${esc(r.file.split('/').pop())}" style="cursor:pointer">
          <div class="d-flex gap-2 justify-content-between">
            <code class="text-primary small">${esc(r.file)}</code>
            <small class="text-muted flex-shrink-0">L${r.line}</small>
          </div>
          <small class="text-muted text-truncate d-block">${esc(r.content)}</small>
        </div>`).join('');
      searchResults.querySelectorAll('.search-result').forEach(row => {
        row.addEventListener('click', () => openFileEditor(pid, row.dataset.path, row.dataset.name));
      });
    }, 350);
  });

  document.getElementById('searchClearBtn')?.addEventListener('click', () => {
    const sq = document.getElementById('searchQuery');
    if (sq) sq.value = '';
    searchResults.classList.add('d-none');
    postsList.classList.remove('d-none');
  });

  loadPostsByType('post');
}


// ── Upload modal ──────────────────────────────────────────────────────────────
// Pre-fill folder from file browser when modal opens
document.getElementById('uploadModal')?.addEventListener('show.bs.modal', () => {
  const browser = document.getElementById('fileBrowser');
  const folder  = document.getElementById('uploadFolder');
  if (browser && folder) folder.value = browser.dataset.currentPath || '';
  document.getElementById('uploadResult')?.classList.add('d-none');
  const uf = document.getElementById('uploadFile');
  if (uf) uf.value = '';
});

const uploadSubmit = document.getElementById('uploadSubmit');
if (uploadSubmit) {
  uploadSubmit.addEventListener('click', async () => {
    const pid    = document.getElementById('uploadPid').value;
    const accept = document.getElementById('uploadAccept').value;
    const folder = document.getElementById('uploadFolder').value.trim();
    const file   = document.getElementById('uploadFile').files[0];

    if (!file) { showError('No file selected'); return; }

    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('folder', folder);
    fd.append('accept', accept);
    fd.append('file', file);

    uploadSubmit.disabled = true;
    const res  = await fetch('/api/upload', { method: 'POST', body: fd });
    const data = await res.json();
    uploadSubmit.disabled = false;

    if (!data.ok) { showError(data.error || 'Upload failed'); return; }

    const resultDiv = document.getElementById('uploadResult');
    const urlInput  = document.getElementById('uploadResultUrl');
    resultDiv.classList.remove('d-none');
    document.getElementById('uploadResultMsg').textContent = `Uploaded: ${data.filename}`;
    urlInput.value = data.url || data.path;

    document.getElementById('uploadCopy')?.addEventListener('click', () => {
      navigator.clipboard.writeText(urlInput.value).then(() => showSuccess('Copied!'));
    }, { once: true });
  });
}

// ── Media grid (Storage) ──────────────────────────────────────────────────────
const mediaPanel = document.getElementById('mediaPanel');
if (mediaPanel) {
  const pid        = mediaPanel.dataset.projectId;
  const projectUrl = mediaPanel.dataset.projectUrl;

  async function loadMedia(relPath) {
    const res  = await fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent(relPath)}`);
    const data = await res.json();
    if (data.error) { document.getElementById('mediaGrid').innerHTML = `<div class="col text-danger small">${esc(data.error)}</div>`; return; }
    renderCrumb(relPath, '#mediaCrumb', loadMedia);
    renderMediaGrid(data.entries, relPath);
  }

  function renderMediaGrid(entries, relPath) {
    const grid = document.getElementById('mediaGrid');
    if (!entries.length) { grid.innerHTML = '<div class="col text-muted small">Empty directory</div>'; return; }

    grid.innerHTML = entries.map(e => {
      if (e.type === 'dir') {
        return `<div class="col-6 col-sm-4 col-md-3 col-lg-2">
          <div class="card h-100 text-center" style="cursor:pointer" data-nav-path="${esc(e.path)}">
            <div class="card-body p-2 d-flex flex-column align-items-center justify-content-center" style="min-height:100px">
              <i class="bi bi-folder-fill text-warning fs-1"></i>
            </div>
            <div class="card-footer py-1 px-2"><small class="text-truncate d-block">${esc(e.name)}</small></div>
          </div>
        </div>`;
      }
      const thumb = isImage(e.name)
        ? `<img src="/api/files?project_id=${pid}&path=${encodeURIComponent(e.path)}&action=serve"
               class="img-fluid" style="max-height:80px;object-fit:cover" loading="lazy">`
        : `<i class="bi ${isVideo(e.name) ? 'bi-film' : isAudio(e.name) ? 'bi-music-note' : 'bi-file-earmark'} fs-1 text-secondary"></i>`;

      const fileUrl = projectUrl ? rtrim(projectUrl, '/') + '/' + e.path : e.path;
      return `<div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <div class="card h-100">
          <div class="card-body p-2 d-flex align-items-center justify-content-center bg-dark" style="min-height:100px">
            ${thumb}
          </div>
          <div class="card-footer py-1 px-2">
            <small class="text-truncate d-block" title="${esc(e.name)}">${esc(e.name)}</small>
            <small class="text-muted">${fmtSize(e.size)}</small>
            <button class="btn btn-xs btn-outline-secondary py-0 float-end btn-copy-url"
                    data-url="${esc(fileUrl)}" title="Copy URL">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
      </div>`;
    }).join('');

    grid.querySelectorAll('[data-nav-path]').forEach(el => {
      el.addEventListener('click', () => loadMedia(el.dataset.navPath));
    });
    grid.querySelectorAll('.btn-copy-url').forEach(btn => {
      btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.dataset.url).then(() => showSuccess('Copied!'));
      });
    });
  }

  loadMedia('');
}

function rtrim(s, c) { return s.endsWith(c) ? s.slice(0, -c.length) : s; }

// ── Shared: breadcrumb renderer ───────────────────────────────────────────────
function renderCrumb(path, selector, onNavigate) {
  const ol    = document.querySelector(selector + ' ol');
  if (!ol) return;
  const parts = path ? path.split('/').filter(Boolean) : [];
  let html = '<li class="breadcrumb-item"><a href="#" data-path="">Root</a></li>';
  let acc  = '';
  for (const p of parts) {
    acc   = acc ? acc + '/' + p : p;
    html += `<li class="breadcrumb-item"><a href="#" data-path="${esc(acc)}">${esc(p)}</a></li>`;
  }
  ol.innerHTML = html;
  ol.querySelectorAll('a').forEach(a =>
    a.addEventListener('click', ev => { ev.preventDefault(); onNavigate(a.dataset.path); })
  );
}

// (project type selector moved to Settings tab — handled in settingsPanel block)

// ── Dashboard: pin toggle ─────────────────────────────────────────────────────
document.querySelectorAll('.btn-pin-project').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id  = parseInt(btn.dataset.id);
    const res = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'pin', id }),
    });
    const data = await res.json();
    if (data.ok) location.reload();
    else showError(data.error || 'Error');
  });
});


// ── Git tab ───────────────────────────────────────────────────────────────────
const gitPanel = document.getElementById('gitPanel');
if (gitPanel) {
  const pid = gitPanel.dataset.projectId;
  let   gitSubdir = '';

  function gitParams(extra = '') {
    const base = `/api/git?project_id=${pid}${gitSubdir ? '&subdir=' + encodeURIComponent(gitSubdir) : ''}`;
    return base + (extra ? '&' + extra : '');
  }

  async function loadGitStatus() {
    const res  = await fetch(gitParams('action=status'));
    const data = await res.json();
    if (data.no_git) {
      if (data.subdirs?.length) {
        const sel  = document.getElementById('gitSubdirSelector');
        const opts = document.getElementById('gitSubdirSelect');
        opts.innerHTML = data.subdirs.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
        sel.classList.remove('d-none');
        document.getElementById('gitSubdirUseBtn').onclick = () => {
          gitSubdir = opts.value;
          sel.classList.add('d-none');
          loadGitStatus();
          loadGitLog();
        };
      } else {
        document.getElementById('gitNoRepo').classList.remove('d-none');
      }
      return;
    }
    if (data.error) { showError(data.error); return; }

    document.getElementById('gitStatus').classList.remove('d-none');
    document.getElementById('gitBranch').innerHTML = `<i class="bi bi-git me-1"></i>${esc(data.branch)}`;
    const files = data.files || [];
    const stash = data.stash_count || 0;
    let summary = files.length
      ? `${files.length} changed file${files.length > 1 ? 's' : ''}`
      : 'Working tree clean';
    if (stash) summary += ` · ${stash} stash${stash > 1 ? 'es' : ''}`;
    document.getElementById('gitStatusSummary').textContent = summary;

    const div = document.getElementById('gitFiles');
    if (files.length) {
      div.innerHTML = `<div class="list-group list-group-flush small mb-1">` +
        files.map(f => `<div class="list-group-item py-1 px-2 d-flex align-items-center gap-2">
          <input type="checkbox" class="form-check-input git-file-check flex-shrink-0 mt-0"
                 data-file="${esc(f.file)}">
          <code class="git-xy flex-shrink-0">${esc(f.xy)}</code>
          <span class="text-truncate flex-grow-1">${esc(f.file)}</span>
          <button class="btn btn-xs btn-outline-secondary btn-git-diff flex-shrink-0"
                  data-file="${esc(f.file)}">Diff</button>
        </div>`).join('') + `</div>
        <button class="btn btn-sm btn-outline-success mb-2 mt-1" id="gitAddSelectedBtn">
          <i class="bi bi-plus-lg me-1"></i>Add selected
        </button>`;
      div.querySelectorAll('.btn-git-diff').forEach(btn => {
        btn.addEventListener('click', () => showGitDiff('', btn.dataset.file));
      });
      document.getElementById('gitAddSelectedBtn')?.addEventListener('click', async () => {
        const checked = [...div.querySelectorAll('.git-file-check:checked')].map(cb => cb.dataset.file);
        if (!checked.length) { showError('Select files to add first'); return; }
        const d = await (await gitPost({ action: 'stage', files: checked })).json();
        d.ok ? (showSuccess(`Staged ${checked.length} file${checked.length > 1 ? 's' : ''}`), loadGitStatus())
             : showError(d.output || 'Stage failed');
      });
    } else {
      div.innerHTML = '';
    }
  }

  async function loadGitLog() {
    const res  = await fetch(gitParams('action=log'));
    const data = await res.json();
    const tbl  = document.getElementById('gitLogTable');
    if (!data.commits?.length) { tbl.innerHTML = '<div class="text-muted small">No commits yet.</div>'; return; }
    tbl.innerHTML = `<div class="list-group">` + data.commits.map(c => `
      <div class="list-group-item list-group-item-action py-2 btn-git-show-commit"
           data-hash="${esc(c.hash)}" style="cursor:pointer">
        <div class="d-flex justify-content-between gap-2">
          <span class="text-truncate">${esc(c.subject)}</span>
          <small class="text-muted flex-shrink-0">${esc(c.rel)}</small>
        </div>
        <small class="text-muted"><code>${esc(c.short)}</code> &bull; ${esc(c.author)}</small>
      </div>`).join('') + `</div>`;
    tbl.querySelectorAll('.btn-git-show-commit').forEach(row => {
      row.addEventListener('click', () => showGitDiff(row.dataset.hash, ''));
    });
  }

  async function loadGitBranches() {
    const res  = await fetch(gitParams('action=branches'));
    const data = await res.json();
    if (!data.branches) return;
    const list = document.getElementById('gitBranchList');
    list.innerHTML = data.branches.map(b => `
      <div class="d-flex align-items-center gap-2 mb-1 small">
        <span class="flex-grow-1 font-monospace">${esc(b.name)}</span>
        ${b.current ? '<span class="badge bg-primary">current</span>' : ''}
        ${!b.current ? `<button class="btn btn-xs btn-outline-secondary btn-checkout"
                         data-branch="${esc(b.name)}">Switch</button>` : ''}
      </div>`).join('');
    list.querySelectorAll('.btn-checkout').forEach(btn => {
      btn.addEventListener('click', async () => {
        const d = await (await gitPost({ action: 'checkout', branch: btn.dataset.branch })).json();
        if (d.ok) { showSuccess('Switched to ' + btn.dataset.branch); loadGitStatus(); loadGitBranches(); }
        else showError(d.output || 'Checkout failed');
      });
    });
  }

  async function showGitDiff(hash, file) {
    const params = new URLSearchParams({ action: 'diff' });
    if (gitSubdir) params.set('subdir', gitSubdir);
    if (hash) params.append('hash', hash);
    if (file) params.append('file', file);
    const res  = await fetch(`/api/git?project_id=${pid}&` + params);
    const data = await res.json();
    document.getElementById('gitDiffContent').innerHTML = colorDiff(data.diff || '(empty)');
    document.getElementById('gitDiffTitle').textContent = hash
      ? `Commit ${hash.substring(0, 7)}` : `Diff: ${file}`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('gitDiffModal')).show();
  }

  function colorDiff(raw) {
    return raw.split('\n').map(line => {
      if (line.startsWith('+') && !line.startsWith('+++')) return `<span class="diff-add">${esc(line)}</span>`;
      if (line.startsWith('-') && !line.startsWith('---')) return `<span class="diff-remove">${esc(line)}</span>`;
      if (line.startsWith('@@'))                            return `<span class="diff-hunk">${esc(line)}</span>`;
      if (/^(diff |index |---|[+]{3})/.test(line))         return `<span class="diff-meta">${esc(line)}</span>`;
      return esc(line);
    }).join('\n');
  }

  function gitPost(body) {
    if (gitSubdir) body.subdir = gitSubdir;
    return fetch('/api/git?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
  }

  async function runGitStream(action) {
    const out = document.getElementById('gitStreamOutput');
    out.classList.remove('d-none');
    out.textContent = `git ${action}…\n`;
    const res = await gitPost({ action });
    const reader  = res.body.getReader();
    const decoder = new TextDecoder();
    let   buf     = '';
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += decoder.decode(value, { stream: true });
      const lines = buf.split('\n');
      buf = lines.pop();
      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;
        try {
          const d = JSON.parse(line.slice(6));
          if (d.line) out.textContent += d.line;
          if (d.done) {
            out.textContent += `\nExit: ${d.exit_code}`;
            d.exit_code === 0
              ? showSuccess(`git ${action} done`)
              : showError(`git ${action} failed (exit ${d.exit_code})`);
            loadGitStatus();
            if (action === 'pull') loadGitLog();
          }
          if (d.error) showError(d.error);
        } catch(e) {}
      }
      out.scrollTop = out.scrollHeight;
    }
  }

  document.getElementById('gitPullBtn')?.addEventListener('click', () => runGitStream('pull'));
  document.getElementById('gitPushBtn')?.addEventListener('click', () => runGitStream('push'));

  document.getElementById('gitCommitBtn')?.addEventListener('click', async () => {
    const msg = document.getElementById('gitCommitMsg').value.trim();
    if (!msg) { showError('Commit message required'); return; }
    const res  = await gitPost({ action: 'commit', message: msg });
    const data = await res.json();
    if (data.ok) {
      showSuccess('Committed');
      document.getElementById('gitCommitMsg').value = '';
      loadGitStatus(); loadGitLog();
    } else {
      showError(data.output || 'Commit failed');
    }
  });

  document.getElementById('gitStashBtn')?.addEventListener('click', async () => {
    const d = await (await gitPost({ action: 'stash' })).json();
    d.ok ? (showSuccess('Stashed'), loadGitStatus()) : showError(d.output || 'Error');
  });

  document.getElementById('gitStashPopBtn')?.addEventListener('click', async () => {
    const d = await (await gitPost({ action: 'stash_pop' })).json();
    d.ok ? (showSuccess('Applied stash'), loadGitStatus()) : showError(d.output || 'Error');
  });

  document.getElementById('gitResetBtn')?.addEventListener('click', () => {
    confirmAction('Reset to HEAD? All uncommitted changes will be lost.', async () => {
      const d = await (await gitPost({ action: 'reset' })).json();
      d.ok ? (showSuccess('Reset to HEAD'), loadGitStatus()) : showError(d.output || 'Error');
    });
  });

  document.getElementById('gitCreateBranchBtn')?.addEventListener('click', async () => {
    const branch = document.getElementById('gitNewBranch').value.trim();
    if (!branch) return;
    const d = await (await gitPost({ action: 'create_branch', branch })).json();
    if (d.ok) {
      showSuccess('Created ' + branch);
      document.getElementById('gitNewBranch').value = '';
      loadGitBranches(); loadGitStatus();
    } else {
      showError(d.output || 'Error');
    }
  });

  // Sub-tab switching
  document.getElementById('gitTabLogBtn')?.addEventListener('click', () => {
    document.getElementById('gitTabLogBtn').classList.replace('btn-outline-secondary', 'btn-outline-primary');
    document.getElementById('gitTabLogBtn').classList.add('active');
    document.getElementById('gitTabBranchesBtn').classList.replace('btn-outline-primary', 'btn-outline-secondary');
    document.getElementById('gitTabBranchesBtn').classList.remove('active');
    document.getElementById('gitLogPanel').classList.remove('d-none');
    document.getElementById('gitBranchesPanel').classList.add('d-none');
  });
  document.getElementById('gitTabBranchesBtn')?.addEventListener('click', () => {
    document.getElementById('gitTabBranchesBtn').classList.replace('btn-outline-secondary', 'btn-outline-primary');
    document.getElementById('gitTabBranchesBtn').classList.add('active');
    document.getElementById('gitTabLogBtn').classList.replace('btn-outline-primary', 'btn-outline-secondary');
    document.getElementById('gitTabLogBtn').classList.remove('active');
    document.getElementById('gitBranchesPanel').classList.remove('d-none');
    document.getElementById('gitLogPanel').classList.add('d-none');
    loadGitBranches();
  });

  loadGitStatus();
  loadGitLog();
}

// ── Config editor (multi-file) ────────────────────────────────────────────────
const configPanel = document.getElementById('configPanel');
if (configPanel) {
  const pid = configPanel.dataset.projectId;
  let   configCM = null;
  let   configCurrentPath = '_config.yml';

  // Discover available config files (root _config*.yml + themes/*/  _config.yml)
  async function discoverConfigs() {
    const sel = document.getElementById('configFileSelect');
    if (!sel) return;
    try {
      const res  = await fetch(`/api/files?project_id=${pid}&path=`);
      const data = await res.json();
      const yamls = (data.entries || [])
        .filter(e => e.type === 'file' && /^_config.*\.ya?ml$/i.test(e.name))
        .map(e => ({ path: e.path, label: e.name + (e.name === '_config.yml' ? ' (blog)' : '') }));
      // Also check themes/ for theme configs
      try {
        const tr  = await fetch(`/api/files?project_id=${pid}&path=themes`);
        const td  = await tr.json();
        for (const d of (td.entries || []).filter(e => e.type === 'dir')) {
          const cr = await fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent('themes/' + d.name)}`);
          const cd = await cr.json();
          if ((cd.entries || []).some(e => e.name === '_config.yml')) {
            yamls.push({ path: 'themes/' + d.name + '/_config.yml', label: d.name + ' theme config' });
          }
        }
      } catch(e) {}
      if (yamls.length > 1) {
        sel.innerHTML = yamls.map(y =>
          `<option value="${esc(y.path)}">${esc(y.label)}</option>`
        ).join('');
      }
    } catch(e) {}
  }

  function loadConfig(path) {
    configCurrentPath = path;
    fetch(`/api/files?project_id=${pid}&path=${encodeURIComponent(path)}&action=read`)
      .then(r => r.json())
      .then(data => {
        const container = document.getElementById('configEditor');
        if (data.error) {
          container.innerHTML = `<div class="text-danger small p-3">${esc(data.error)}</div>`;
          return;
        }
        container.innerHTML = '';
        if (configCM) { try { configCM.toTextArea(); } catch(e) {} configCM = null; }
        configCM = CodeMirror(container, {
          value: data.content, mode: 'yaml', theme: 'dracula',
          lineNumbers: true, lineWrapping: true, tabSize: 2,
          extraKeys: { 'Ctrl-S': saveConfig, 'Cmd-S': saveConfig },
        });
        configCM.setSize('100%', 'var(--hm-tab-height)');
        requestAnimationFrame(() => requestAnimationFrame(() => configCM?.refresh()));
      });
  }

  async function saveConfig() {
    if (!configCM) return;
    const status = document.getElementById('configSaveStatus');
    status.textContent = 'Saving…';
    const res  = await fetch('/api/files?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'write', path: configCurrentPath, content: configCM.getValue() }),
    });
    const data = await res.json();
    if (data.ok) { status.textContent = 'Saved.'; showSuccess('Config saved'); }
    else { status.textContent = ''; showError(data.error || 'Save failed'); }
  }

  document.getElementById('configSaveBtn')?.addEventListener('click', saveConfig);
  document.getElementById('configFileSelect')?.addEventListener('change', function() {
    loadConfig(this.value);
  });

  discoverConfigs().then(() => loadConfig('_config.yml'));
}

// ── Full-text search ──────────────────────────────────────────────────────────

// ── Tag/category browser ──────────────────────────────────────────────────────
const tagsPanel = document.getElementById('tagsPanel');
if (tagsPanel) {
  const pid = tagsPanel.dataset.projectId;

  fetch(`/api/tags?project_id=${pid}`)
    .then(r => r.json())
    .then(data => {
      document.getElementById('tagsLoading').classList.add('d-none');
      document.getElementById('tagsContent').classList.remove('d-none');
      const tags = data.tags || {};
      const cats = data.categories || {};
      document.getElementById('tagsCount').textContent = Object.keys(tags).length;
      document.getElementById('catsCount').textContent = Object.keys(cats).length;
      document.getElementById('tagCloud').innerHTML = renderTagCloud(tags, 'tag');
      document.getElementById('catCloud').innerHTML = renderTagCloud(cats, 'category');
    })
    .catch(() => {
      document.getElementById('tagsLoading').textContent = 'Failed to load.';
    });

  function renderTagCloud(map, kind) {
    const entries = Object.entries(map);
    if (!entries.length) return '<div class="text-muted small">None found.</div>';
    const max = entries[0][1];
    return entries.map(([name, count]) => {
      const size = Math.max(75, Math.min(160, Math.round(count / max * 100 + 60)));
      const href = `/project/${pid}?tab=posts&filter=${encodeURIComponent(kind + ':' + name)}`;
      return `<a class="tag-item" href="${href}" style="font-size:${size}%"
        title="Show posts with this ${kind}">${esc(name)}<sup class="text-muted ms-1">${count}</sup></a>`;
    }).join(' ');
  }
}

// ── Command history ───────────────────────────────────────────────────────────
document.getElementById('showHistoryBtn')?.addEventListener('click', async () => {
  const runner = document.getElementById('commandRunner');
  if (!runner) return;
  const pid  = runner.dataset.projectId;
  const res  = await fetch(`/api/run?project_id=${pid}`);
  const data = await res.json();
  const list = document.getElementById('cmdHistoryList');
  if (!list) return;
  if (!data.history?.length) {
    list.innerHTML = '<div class="list-group-item text-muted small">No history yet.</div>';
  } else {
    list.innerHTML = data.history.map(h => `
      <div class="list-group-item py-2">
        <div class="d-flex justify-content-between gap-2 mb-1">
          <code class="small">${esc(h.cmd)}</code>
          <span class="badge ${h.exit_code === 0 ? 'bg-success' : 'bg-danger'} flex-shrink-0">
            Exit ${h.exit_code}
          </span>
        </div>
        <small class="text-muted">${esc(h.run_at)}</small>
        ${h.output ? `<pre class="mt-1 mb-0 small" style="max-height:80px;overflow-y:auto;background:transparent">${esc(h.output.substring(0,500))}</pre>` : ''}
      </div>`).join('');
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('cmdHistoryModal')).show();
});

// ── Post duplicate ────────────────────────────────────────────────────────────
// Handled via event delegation inside loadPosts(); trigger is .btn-dup-post

// ── Settings tab ─────────────────────────────────────────────────────────────
const settingsPanel = document.getElementById('settingsPanel');
if (settingsPanel) {
  const pid = settingsPanel.dataset.projectId;
  let   tplCM = null, snipCM = null;

  // ── Project rename ──
  document.getElementById('projectNameSaveBtn')?.addEventListener('click', async () => {
    const name = document.getElementById('projectNameInput')?.value.trim();
    if (!name) { showError('Name required'); return; }
    const res  = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'update_name', id: parseInt(pid), name }),
    });
    const data = await res.json();
    if (data.ok) showSuccess('Name updated');
    else showError(data.error || 'Error');
  });

  // ── Project type ──
  document.getElementById('projectTypeSaveBtn')?.addEventListener('click', async () => {
    const type = document.getElementById('projectTypeSelectSettings')?.value;
    if (!type) return;
    const res  = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'update_type', id: parseInt(pid), type }),
    });
    const data = await res.json();
    if (data.ok) location.reload();
    else showError(data.error || 'Error');
  });

  // ── Page directories ──
  document.getElementById('pageDirsSaveBtn')?.addEventListener('click', async () => {
    const dirs = document.getElementById('pageDirsInput')?.value ?? '';
    const res  = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'update_setting', id: parseInt(pid), key: 'page_dirs', value: dirs }),
    });
    const data = await res.json();
    if (data.ok) showSuccess('Page dirs saved');
    else showError(data.error || 'Error');
  });

  // ── Templates ──
  async function loadTemplates() {
    const res  = await fetch(`/api/templates?project_id=${pid}`);
    const data = await res.json();
    const list = document.getElementById('templatesList');
    if (!data.templates?.length) {
      list.innerHTML = '<div class="text-muted small">No templates yet.</div>';
      return;
    }
    list.innerHTML = data.templates.map(t => `
      <div class="card mb-2">
        <div class="card-body py-2 d-flex align-items-center gap-3">
          <div class="flex-grow-1">
            <strong class="small">${esc(t.name)}</strong>
            <span class="badge bg-secondary ms-2">${esc(t.type)}</span>
          </div>
          <button class="btn btn-xs btn-outline-secondary btn-edit-template" data-id="${t.id}"
                  data-name="${esc(t.name)}" data-content="${esc(t.content ?? '')}">Edit</button>
          <button class="btn btn-xs btn-outline-danger btn-del-template" data-id="${t.id}">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>`).join('');
    list.querySelectorAll('.btn-edit-template').forEach(btn => {
      btn.addEventListener('click', () => openTemplateModal(btn.dataset.id, btn.dataset.name, btn.dataset.content));
    });
    list.querySelectorAll('.btn-del-template').forEach(btn => {
      btn.addEventListener('click', () => confirmAction('Delete this template?', async () => {
        const r = await fetch('/api/templates', {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'delete', id: parseInt(btn.dataset.id) }),
        });
        const d = await r.json();
        d.ok ? loadTemplates() : showError(d.error || 'Error');
      }));
    });
  }

  function openTemplateModal(id, name, content) {
    document.getElementById('templateId').value      = id || '';
    document.getElementById('templateName').value    = name || '';
    document.getElementById('templateModalTitle').textContent = id ? 'Edit template' : 'New template';

    const container = document.getElementById('templateContentEditor');
    container.innerHTML = '';
    if (tplCM) { try { tplCM.toTextArea(); } catch(e) {} tplCM = null; }
    tplCM = CodeMirror(container, {
      value: content || '', mode: 'markdown', theme: 'dracula',
      lineNumbers: true, lineWrapping: true, tabSize: 2,
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('templateModal')).show();
    setTimeout(() => tplCM && tplCM.refresh(), 200);
  }

  document.getElementById('newTemplateBtn')?.addEventListener('click', () => openTemplateModal('', '', ''));

  document.getElementById('templateSaveBtn')?.addEventListener('click', async () => {
    const id      = document.getElementById('templateId').value;
    const name    = document.getElementById('templateName').value.trim();
    const content = tplCM ? tplCM.getValue() : '';
    if (!name) { showError('Name required'); return; }
    const body = id
      ? { action: 'update', id: parseInt(id), name, content }
      : { action: 'create', project_id: parseInt(pid), name, type: 'post', content };
    const res  = await fetch('/api/templates', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
      showSuccess(id ? 'Template updated' : 'Template created');
      loadTemplates();
    } else {
      showError(data.error || 'Error');
    }
  });

  // ── Snippets ──
  async function loadSnippets() {
    const res  = await fetch(`/api/snippets?project_id=${pid}`);
    const data = await res.json();
    const list = document.getElementById('snippetsList');
    if (!data.snippets?.length) {
      list.innerHTML = '<div class="text-muted small">No snippets yet.</div>';
      return;
    }
    list.innerHTML = data.snippets.map(s => `
      <div class="card mb-2">
        <div class="card-body py-2 d-flex align-items-center gap-3">
          <span class="flex-grow-1 small fw-semibold">${esc(s.name)}</span>
          <button class="btn btn-xs btn-outline-secondary btn-edit-snippet" data-id="${s.id}"
                  data-name="${esc(s.name)}" data-content="${esc(s.content ?? '')}">Edit</button>
          <button class="btn btn-xs btn-outline-danger btn-del-snippet" data-id="${s.id}">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>`).join('');
    list.querySelectorAll('.btn-edit-snippet').forEach(btn => {
      btn.addEventListener('click', () => openSnippetModal(btn.dataset.id, btn.dataset.name, btn.dataset.content));
    });
    list.querySelectorAll('.btn-del-snippet').forEach(btn => {
      btn.addEventListener('click', () => confirmAction('Delete this snippet?', async () => {
        const r = await fetch('/api/snippets', {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'delete', id: parseInt(btn.dataset.id) }),
        });
        const d = await r.json();
        d.ok ? loadSnippets() : showError(d.error || 'Error');
      }));
    });
  }

  function openSnippetModal(id, name, content) {
    document.getElementById('snippetId').value       = id || '';
    document.getElementById('snippetName').value     = name || '';
    document.getElementById('snippetModalTitle').textContent = id ? 'Edit snippet' : 'New snippet';

    const container = document.getElementById('snippetContentEditor');
    container.innerHTML = '';
    if (snipCM) { try { snipCM.toTextArea(); } catch(e) {} snipCM = null; }
    snipCM = CodeMirror(container, {
      value: content || '', mode: 'markdown', theme: 'dracula',
      lineNumbers: true, lineWrapping: true, tabSize: 2,
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('snippetModal')).show();
    setTimeout(() => snipCM && snipCM.refresh(), 200);
  }

  document.getElementById('newSnippetBtn')?.addEventListener('click', () => openSnippetModal('', '', ''));

  document.getElementById('snippetSaveBtn')?.addEventListener('click', async () => {
    const id      = document.getElementById('snippetId').value;
    const name    = document.getElementById('snippetName').value.trim();
    const content = snipCM ? snipCM.getValue() : '';
    if (!name) { showError('Name required'); return; }
    const body = id
      ? { action: 'update', id: parseInt(id), name, content }
      : { action: 'create', project_id: parseInt(pid), name, content };
    const res  = await fetch('/api/snippets', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('snippetModal')).hide();
      showSuccess(id ? 'Snippet updated' : 'Snippet created');
      loadSnippets();
    } else {
      showError(data.error || 'Error');
    }
  });

  if (document.getElementById('templatesList')) loadTemplates();
  if (document.getElementById('snippetsList'))  loadSnippets();
}

// ── Audit log page ────────────────────────────────────────────────────────────
const auditTable = document.getElementById('auditTable');
if (auditTable) {
  let auditOffset = 0;
  const auditLimit = 50;

  async function loadAudit(offset = 0) {
    auditOffset = offset;
    const pid = document.getElementById('auditProjectFilter')?.value || '';
    const params = new URLSearchParams({ limit: auditLimit, offset });
    if (pid) params.append('project_id', pid);

    const res  = await fetch('/api/audit?' + params);
    const data = await res.json();

    if (!data.entries?.length) {
      auditTable.innerHTML = '<div class="text-muted small">No entries.</div>';
    } else {
      auditTable.innerHTML = `<table class="table table-sm table-hover small">
        <thead><tr>
          <th>Time</th><th>User</th><th>Project</th><th>Action</th><th>Detail</th><th>IP</th>
        </tr></thead>
        <tbody>` + data.entries.map(e => `<tr>
          <td class="text-muted text-nowrap">${esc(e.created_at)}</td>
          <td>${esc(e.username ?? e.user_id ?? '—')}</td>
          <td>${esc(e.project_name ?? (e.project_id ? '#' + e.project_id : '—'))}</td>
          <td><code>${esc(e.action)}</code></td>
          <td class="text-truncate" style="max-width:200px">${esc(e.detail ?? '')}</td>
          <td class="text-muted">${esc(e.ip ?? '')}</td>
        </tr>`).join('') + `</tbody></table>`;
    }

    const total = data.total ?? 0;
    document.getElementById('auditPrev').classList.toggle('d-none', offset === 0);
    document.getElementById('auditNext').classList.toggle('d-none', offset + auditLimit >= total);
  }

  document.getElementById('auditProjectFilter')?.addEventListener('change', () => loadAudit(0));
  document.getElementById('auditPrev')?.addEventListener('click', () => loadAudit(Math.max(0, auditOffset - auditLimit)));
  document.getElementById('auditNext')?.addEventListener('click', () => loadAudit(auditOffset + auditLimit));

  loadAudit();
}

// ── Settings: scan paths ──────────────────────────────────────────────────────
const addScanPathForm = document.getElementById('addScanPathForm');
if (addScanPathForm) {
  addScanPathForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd  = new FormData(addScanPathForm);
    const res = await fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'add_scan_path', path: fd.get('path'), depth: parseInt(fd.get('depth')) }),
    });
    const data = await res.json();
    data.ok ? location.reload() : showError(data.error || 'Error');
  });

  document.querySelectorAll('.btn-remove-scan').forEach(btn => {
    btn.addEventListener('click', () => confirmAction('Remove this scan path?', async () => {
      const res  = await fetch('/api/projects', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'remove_scan_path', id: parseInt(btn.dataset.id) }),
      });
      const data = await res.json();
      if (data.ok) location.reload();
    }));
  });

  document.querySelectorAll('.btn-scan').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const res  = await fetch('/api/projects', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'scan', path: btn.dataset.path, depth: parseInt(btn.dataset.depth) }),
      });
      const data = await res.json();
      btn.disabled = false;
      if (data.error) { showError(data.error); return; }

      const resultsDiv = document.getElementById('scanResults');
      const listDiv    = document.getElementById('scanResultsList');
      resultsDiv.classList.remove('d-none');

      if (!data.found.length) { listDiv.innerHTML = '<p class="text-muted small">No projects found.</p>'; return; }

      listDiv.innerHTML = data.found.map(p => `
        <div class="card mb-2"><div class="card-body d-flex align-items-center gap-3 py-2">
          <div class="flex-grow-1 min-w-0">
            <strong>${esc(p.name)}</strong>
            <small class="text-muted d-block text-truncate">${esc(p.path)}</small>
          </div>
          <span class="badge bg-secondary">${esc(p.type_name)}</span>
          <button class="btn btn-sm btn-primary btn-add-found flex-shrink-0"
            data-name="${esc(p.name)}" data-path="${esc(p.path)}" data-type="${esc(p.type)}">Add</button>
        </div></div>`).join('');

      listDiv.querySelectorAll('.btn-add-found').forEach(b => {
        b.addEventListener('click', async () => {
          const r = await fetch('/api/projects', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ name: b.dataset.name, path: b.dataset.path, type: b.dataset.type }),
          });
          const d = await r.json();
          if (d.ok) { b.innerHTML = '<i class="bi bi-check-lg"></i>'; b.disabled = true; b.classList.replace('btn-primary','btn-success'); }
          else showError(d.error || 'Error');
        });
      });
    });
  });

  document.getElementById('closeScanResults')?.addEventListener('click', () => {
    document.getElementById('scanResults').classList.add('d-none');
  });
}

// ── Scratchpad: per-project notes ─────────────────────────────────────────────
(() => {
  const card  = document.getElementById('scratchpadCard');
  if (!card) return;
  const pid   = parseInt(card.dataset.projectId);
  const input = document.getElementById('scratchpadInput');
  const status = document.getElementById('scratchpadStatus');
  let timer = null;
  let lastSaved = input.value;

  const setStatus = (msg, cls = 'text-muted') => {
    status.className = 'small ms-auto ' + cls;
    status.textContent = msg;
  };

  const save = async () => {
    if (input.value === lastSaved) return;
    setStatus('Saving…');
    try {
      const r = await fetch('/api/scratchpad?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ content: input.value }),
      });
      const j = await r.json();
      if (j.ok) {
        lastSaved = input.value;
        setStatus('Saved', 'text-success');
        setTimeout(() => setStatus(''), 1500);
      } else {
        setStatus(j.error || 'Save failed', 'text-danger');
      }
    } catch (e) {
      setStatus('Save failed', 'text-danger');
    }
  };

  input.addEventListener('input', () => {
    setStatus('Editing…');
    clearTimeout(timer);
    timer = setTimeout(save, 800);
  });
  input.addEventListener('blur', () => { clearTimeout(timer); save(); });
})();

// ── Recent files tab ──────────────────────────────────────────────────────────
(() => {
  const panel = document.getElementById('recentPanel');
  if (!panel) return;
  const pid  = parseInt(panel.dataset.projectId);
  const list = document.getElementById('recentList');

  const fmtAgo = ts => {
    const t = Date.parse(ts.replace(' ', 'T') + 'Z');
    const s = Math.max(1, Math.floor((Date.now() - t) / 1000));
    if (s < 60)         return s + 's ago';
    if (s < 3600)       return Math.floor(s / 60) + 'm ago';
    if (s < 86400)      return Math.floor(s / 3600) + 'h ago';
    if (s < 86400 * 7)  return Math.floor(s / 86400) + 'd ago';
    return new Date(t).toLocaleDateString();
  };

  async function load() {
    const r = await fetch('/api/recent?project_id=' + pid);
    const d = await r.json();
    if (!d.items?.length) {
      list.innerHTML = '<div class="list-group-item text-muted small">No recent files yet — open or save a file to start tracking.</div>';
      return;
    }
    list.innerHTML = d.items.map(it => `
      <div class="list-group-item d-flex align-items-center gap-2">
        <i class="bi bi-file-text text-secondary"></i>
        <div class="flex-grow-1 min-w-0">
          <div class="text-truncate"><strong>${esc(it.name)}</strong></div>
          <small class="text-muted text-truncate d-block">${esc(it.dir || '/')}</small>
        </div>
        <small class="text-muted flex-shrink-0">${fmtAgo(it.opened_at)}</small>
        <button class="btn btn-xs btn-outline-secondary py-0 px-1 btn-edit-recent flex-shrink-0"
                data-path="${esc(it.path)}" data-name="${esc(it.name)}" title="Edit">
          <i class="bi bi-pencil"></i>
        </button>
        <button class="btn btn-xs btn-outline-danger py-0 px-1 btn-remove-recent flex-shrink-0"
                data-path="${esc(it.path)}" title="Remove from recent">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>`).join('');
    list.querySelectorAll('.btn-edit-recent').forEach(b => {
      b.addEventListener('click', () => openFileEditor(pid, b.dataset.path, b.dataset.name));
    });
    list.querySelectorAll('.btn-remove-recent').forEach(b => {
      b.addEventListener('click', async () => {
        await fetch('/api/recent?project_id=' + pid, {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'remove', path: b.dataset.path }),
        });
        load();
      });
    });
  }

  document.getElementById('recentClearBtn').addEventListener('click', () => {
    confirmAction('Clear all recent files for this project?', async () => {
      await fetch('/api/recent?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'clear' }),
      });
      load();
    });
  });

  load();
})();

// ── Disk usage: dashboard cards + project page badge ─────────────────────────
(() => {
  // Project header pill
  const badge = document.getElementById('diskBadge');
  if (badge) {
    const pid = badge.dataset.projectId;
    fetch('/api/disk?project_id=' + pid).then(r => r.json()).then(d => {
      if (d.size?.human) {
        document.getElementById('diskBadgeValue').textContent = d.size.human;
        badge.classList.remove('d-none');
      }
    });
  }

  // Dashboard card pills (bulk)
  const cards = [...document.querySelectorAll('.project-disk')];
  if (cards.length) {
    const ids = cards.map(c => c.dataset.projectId).join(',');
    fetch('/api/disk?ids=' + ids).then(r => r.json()).then(d => {
      cards.forEach(c => {
        const item = d.items?.[c.dataset.projectId];
        if (item?.human) {
          c.querySelector('.project-disk-value').textContent = item.human;
          c.classList.remove('d-none');
        }
      });
    });
  }
})();

// ── Activity feed (dashboard) ────────────────────────────────────────────────
(() => {
  const feed = document.getElementById('activityFeed');
  if (!feed) return;
  const ACTION_LABELS = {
    git_commit: 'commit', git_push: 'push', git_pull: 'pull', git_merge: 'merge',
    git_reset: 'reset', git_stage: 'stage', git_unstage: 'unstage',
    git_discard: 'discard', git_fetch: 'fetch',
    backup_download: 'backup', link_scan: 'link scan',
    theme_switch: 'theme switch', theme_clone: 'theme clone', theme_delete: 'theme delete',
    theme_git_pull: 'theme pull', theme_git_push: 'theme push', theme_git_fetch: 'theme fetch',
    plugin_install: 'plugin install', plugin_uninstall: 'plugin uninstall',
    scheduled_build: 'scheduled build',
    bot_start: 'bot started', bot_stop: 'bot stopped', bot_restart: 'bot restarted',
    botconfig_streamer_add: 'streamer added', botconfig_streamer_save: 'streamer updated',
    botconfig_streamer_remove: 'streamer removed',
    botconfig_rss_add: 'feed added', botconfig_rss_save: 'feed updated',
    botconfig_rss_remove: 'feed removed',
    botconfig_mastodon_add: 'mastodon added', botconfig_mastodon_save: 'mastodon updated',
    botconfig_mastodon_remove: 'mastodon removed',
    botconfig_linkedin_page_add: 'linkedin page added', botconfig_linkedin_page_save: 'linkedin page updated',
    botconfig_linkedin_page_remove: 'linkedin page removed',
    linkedin_oauth_connect: 'linkedin connected',
    botcompose_rss_repost: 'rss repost queued',
    botcompose_mastodon_post: 'mastodon posted',
    botcompose_linkedin_post: 'linkedin posted',
    file_write: 'file edit', file_delete: 'file delete', file_upload: 'upload',
    post_create: 'post created', post_delete: 'post deleted',
    post_publish: 'post published', post_duplicate: 'post duplicated',
    draft_create: 'draft created', draft_update: 'draft updated',
    draft_delete: 'draft deleted', draft_publish: 'draft published',
    command_run: 'command run',
    project_add: 'project added', project_delete: 'project removed',
    project_rename: 'project renamed', project_type_change: 'type changed',
    project_pin: 'pinned', project_unpin: 'unpinned',
    project_setting: 'setting changed',
    scan_path_add: 'scan path added', scan_path_delete: 'scan path removed',
    schedule_create: 'schedule added', schedule_update: 'schedule updated',
    schedule_delete: 'schedule removed',
    template_create: 'template added', template_update: 'template updated',
    template_delete: 'template deleted',
    snippet_create: 'snippet added', snippet_update: 'snippet updated',
    snippet_delete: 'snippet deleted',
  };
  const ACTION_ICONS = {
    git_commit: 'bi-git', git_push: 'bi-arrow-up-circle', git_pull: 'bi-arrow-down-circle',
    git_fetch: 'bi-arrow-repeat', git_merge: 'bi-sign-merge-right',
    git_reset: 'bi-arrow-counterclockwise',
    git_stage: 'bi-plus-circle', git_unstage: 'bi-dash-circle', git_discard: 'bi-x-circle',
    backup_download: 'bi-download', link_scan: 'bi-link-45deg',
    theme_switch: 'bi-palette', theme_clone: 'bi-cloud-download', theme_delete: 'bi-trash',
    theme_git_pull: 'bi-arrow-down-circle', theme_git_push: 'bi-arrow-up-circle',
    theme_git_fetch: 'bi-arrow-repeat',
    plugin_install: 'bi-puzzle', plugin_uninstall: 'bi-puzzle',
    scheduled_build: 'bi-clock',
    bot_start: 'bi-play-circle', bot_stop: 'bi-stop-circle', bot_restart: 'bi-arrow-clockwise',
    botconfig_streamer_add: 'bi-twitch', botconfig_streamer_save: 'bi-twitch',
    botconfig_streamer_remove: 'bi-twitch',
    botconfig_rss_add: 'bi-rss', botconfig_rss_save: 'bi-rss', botconfig_rss_remove: 'bi-rss',
    botconfig_mastodon_add: 'bi-mastodon', botconfig_mastodon_save: 'bi-mastodon', botconfig_mastodon_remove: 'bi-mastodon',
    botconfig_linkedin_page_add: 'bi-linkedin', botconfig_linkedin_page_save: 'bi-linkedin', botconfig_linkedin_page_remove: 'bi-linkedin',
    linkedin_oauth_connect: 'bi-linkedin',
    botcompose_rss_repost: 'bi-arrow-repeat',
    botcompose_mastodon_post: 'bi-mastodon',
    botcompose_linkedin_post: 'bi-linkedin',
    file_write: 'bi-pencil', file_delete: 'bi-trash', file_upload: 'bi-cloud-upload',
    post_create: 'bi-file-earmark-plus', post_delete: 'bi-file-earmark-x',
    post_publish: 'bi-send', post_duplicate: 'bi-files',
    draft_create: 'bi-pencil-square', draft_update: 'bi-pencil-square',
    draft_delete: 'bi-trash', draft_publish: 'bi-send',
    command_run: 'bi-terminal',
    project_add: 'bi-plus-square', project_delete: 'bi-trash',
    project_rename: 'bi-pencil', project_type_change: 'bi-arrow-left-right',
    project_pin: 'bi-pin-fill', project_unpin: 'bi-pin',
    project_setting: 'bi-sliders',
    scan_path_add: 'bi-folder-plus', scan_path_delete: 'bi-folder-minus',
    schedule_create: 'bi-clock', schedule_update: 'bi-clock', schedule_delete: 'bi-clock',
    template_create: 'bi-file-earmark-text', template_update: 'bi-file-earmark-text',
    template_delete: 'bi-file-earmark-text',
    snippet_create: 'bi-code-square', snippet_update: 'bi-code-square',
    snippet_delete: 'bi-code-square',
  };
  fetch('/api/audit?limit=20').then(r => r.json()).then(d => {
    if (!d.entries?.length) {
      feed.innerHTML = '<div class="list-group-item text-muted small bg-transparent">No activity yet.</div>';
      return;
    }
    feed.innerHTML = d.entries.map(e => {
      const label = ACTION_LABELS[e.action] || e.action;
      const icon  = ACTION_ICONS[e.action] || 'bi-circle';
      const proj  = e.project_name
        ? `<a href="/project/${e.project_id}" class="text-decoration-none">${esc(e.project_name)}</a>`
        : '<span class="text-muted">—</span>';
      const detail = e.detail
        ? `<small class="text-muted ms-1">${esc(e.detail)}</small>` : '';
      return `<div class="list-group-item bg-transparent d-flex align-items-center gap-2 small">
        <i class="bi ${icon} text-secondary"></i>
        <span class="badge bg-secondary-subtle text-body-secondary border">${esc(label)}</span>
        ${proj}
        ${detail}
        <small class="text-muted ms-auto">${esc(e.created_at)}</small>
      </div>`;
    }).join('');
  });
})();

// ── Broken link checker (links tab) ──────────────────────────────────────────
(() => {
  const panel = document.getElementById('linksPanel');
  if (!panel) return;
  const pid    = parseInt(panel.dataset.projectId);
  const wrap   = document.getElementById('linksTableWrap');
  const info   = document.getElementById('linksRunInfo');
  const onlyB  = document.getElementById('linksOnlyBroken');
  const btn    = document.getElementById('linksScanBtn');

  function statusBadge(code, error) {
    if (error)       return `<span class="badge bg-danger">err</span> <small class="text-muted">${esc(error)}</small>`;
    if (code == null) return `<span class="badge bg-warning text-dark">?</span>`;
    if (code >= 400)  return `<span class="badge bg-danger">${code}</span>`;
    if (code >= 300)  return `<span class="badge bg-warning text-dark">${code}</span>`;
    return `<span class="badge bg-success">${code}</span>`;
  }

  async function load() {
    const url = '/api/links?project_id=' + pid + (onlyB.checked ? '&broken_only=1' : '');
    const d = await (await fetch(url)).json();
    if (!d.run) {
      info.textContent = 'No scan run yet.';
      wrap.innerHTML = '';
      return;
    }
    const r = d.run;
    info.innerHTML = `Last scan ${esc(r.finished_at || r.started_at)} —
      ${r.total_links} links, <strong class="${r.broken > 0 ? 'text-danger' : 'text-success'}">${r.broken} broken</strong>`;
    if (!d.results.length) {
      wrap.innerHTML = `<div class="alert alert-success py-2 small">${onlyB.checked ? 'No broken links 🎉' : 'No links recorded.'}</div>`;
      return;
    }
    wrap.innerHTML = `<table class="table table-sm align-middle">
      <thead><tr><th style="width:80px">Status</th><th>URL</th><th>Source</th></tr></thead>
      <tbody>${d.results.map(x => `
        <tr>
          <td>${statusBadge(x.status_code, x.error)}</td>
          <td><a href="${esc(x.url)}" target="_blank" rel="noopener" class="text-truncate d-inline-block" style="max-width:480px">${esc(x.url)}</a></td>
          <td><code class="small">${esc(x.source)}</code></td>
        </tr>`).join('')}
      </tbody></table>`;
  }

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Scanning…';
    info.textContent = 'Scanning… this can take a while for large projects.';
    wrap.innerHTML = '';
    try {
      const r = await fetch('/api/links?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'scan' }),
      });
      const d = await r.json();
      if (d.error) showError(d.error);
      else showSuccess(`Scan done — ${d.broken} broken of ${d.total}`);
      await load();
    } catch (e) {
      showError(e.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = orig;
    }
  });

  onlyB.addEventListener('change', load);
  load();
})();

// ── Themes tab ───────────────────────────────────────────────────────────────
(() => {
  const panel = document.getElementById('themesPanel');
  if (!panel) return;
  const pid  = parseInt(panel.dataset.projectId);
  const list = document.getElementById('themesList');

  function gitChip(g) {
    if (!g.has_git) return '<span class="badge bg-secondary-subtle text-body-secondary border small">no git</span>';
    const parts = [`<i class="bi bi-git"></i> ${esc(g.branch || '?')}`];
    if (g.dirty) parts.push('<span class="text-warning">● dirty</span>');
    if (g.ahead)  parts.push(`<span class="text-info">↑${g.ahead}</span>`);
    if (g.behind) parts.push(`<span class="text-info">↓${g.behind}</span>`);
    return `<span class="small">${parts.join(' ')}</span>`;
  }

  function renderTheme(t) {
    const g = t.git;
    return `<div class="col-md-6 col-xl-4">
      <div class="card h-100 ${t.active ? 'border-primary' : ''}">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-palette text-primary"></i>
            <h6 class="card-title mb-0 text-truncate">${esc(t.name)}</h6>
            ${t.active ? '<span class="badge bg-primary ms-auto">active</span>' : ''}
          </div>
          <div class="small text-muted text-truncate mb-2">${gitChip(g)}</div>
          ${g.remote ? `<div class="small text-truncate mb-1"><code>${esc(g.remote)}</code></div>` : ''}
          ${g.commit ? `<div class="small text-muted text-truncate mb-2">${esc(g.commit)}</div>` : ''}
        </div>
        <div class="card-footer d-flex gap-1 flex-wrap">
          ${!t.active ? `<button class="btn btn-sm btn-outline-primary btn-theme-switch" data-name="${esc(t.name)}">Activate</button>` : ''}
          <a class="btn btn-sm btn-outline-secondary" href="/project/${pid}?tab=files&path=themes/${encodeURIComponent(t.name)}/" title="Edit theme files">
            <i class="bi bi-pencil"></i>
          </a>
          ${g.has_git ? `
            <button class="btn btn-sm btn-outline-secondary btn-theme-git" data-name="${esc(t.name)}" data-op="pull" title="Pull"><i class="bi bi-arrow-down-circle"></i></button>
            <button class="btn btn-sm btn-outline-secondary btn-theme-git" data-name="${esc(t.name)}" data-op="push" title="Push"><i class="bi bi-arrow-up-circle"></i></button>
          ` : ''}
          ${!t.active ? `<button class="btn btn-sm btn-outline-danger ms-auto btn-theme-delete" data-name="${esc(t.name)}" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
        </div>
      </div></div>`;
  }

  async function load() {
    list.innerHTML = '<div class="col-12 text-muted small">Loading…</div>';
    const d = await (await fetch('/api/themes?project_id=' + pid)).json();
    if (d.no_themes_dir) {
      list.innerHTML = '<div class="col-12 alert alert-warning small">No <code>themes/</code> directory found in this project.</div>';
      return;
    }
    if (!d.themes.length) {
      list.innerHTML = '<div class="col-12 text-muted small">No themes installed yet. Clone one from a git URL.</div>';
      return;
    }
    list.innerHTML = d.themes.map(renderTheme).join('');

    list.querySelectorAll('.btn-theme-switch').forEach(b => {
      b.addEventListener('click', async () => {
        const r = await fetch('/api/themes?project_id=' + pid, {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'switch', name: b.dataset.name }),
        });
        const j = await r.json();
        if (j.ok) { showSuccess('Theme switched to ' + b.dataset.name); load(); }
        else showError(j.error || 'Switch failed');
      });
    });
    list.querySelectorAll('.btn-theme-git').forEach(b => {
      b.addEventListener('click', async () => {
        b.disabled = true;
        const r = await fetch('/api/themes?project_id=' + pid, {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'git', name: b.dataset.name, op: b.dataset.op }),
        });
        const j = await r.json();
        b.disabled = false;
        if (j.ok) { showSuccess(b.dataset.op + ' ok'); load(); }
        else showError(j.error || j.log || 'Git op failed');
      });
    });
    list.querySelectorAll('.btn-theme-delete').forEach(b => {
      b.addEventListener('click', () => {
        confirmAction('Delete theme "' + b.dataset.name + '"? Files will be removed from disk.', async () => {
          const r = await fetch('/api/themes?project_id=' + pid, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'delete', name: b.dataset.name }),
          });
          const j = await r.json();
          if (j.ok) { showSuccess('Deleted'); load(); }
          else showError(j.error || 'Delete failed');
        });
      });
    });
  }

  document.getElementById('cloneThemeSubmit').addEventListener('click', async () => {
    const url  = document.getElementById('cloneThemeUrl').value.trim();
    const name = document.getElementById('cloneThemeName').value.trim();
    const log  = document.getElementById('cloneThemeLog');
    if (!url) { log.textContent = 'URL required'; return; }
    log.textContent = 'Cloning…';
    const r = await fetch('/api/themes?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'clone', url, name }),
    });
    const j = await r.json();
    log.textContent = j.log || (j.error || 'Done');
    if (j.ok) {
      showSuccess('Cloned theme ' + j.name);
      bootstrap.Modal.getInstance(document.getElementById('cloneThemeModal')).hide();
      document.getElementById('cloneThemeUrl').value = '';
      document.getElementById('cloneThemeName').value = '';
      load();
    } else {
      showError(j.error || 'Clone failed');
    }
  });

  load();
})();

// ── Plugins tab ──────────────────────────────────────────────────────────────
(() => {
  const panel = document.getElementById('pluginsPanel');
  if (!panel) return;
  const pid  = parseInt(panel.dataset.projectId);
  const list = document.getElementById('pluginsList');
  const log  = document.getElementById('pluginInstallLog');

  function renderPlugin(p) {
    const installed = p.installed
      ? `<span class="badge bg-success-subtle text-success border">v${esc(p.installed)}</span>`
      : `<span class="badge bg-warning-subtle text-warning border">not installed (run npm install)</span>`;
    return `<div class="col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-puzzle text-primary"></i>
            <h6 class="card-title mb-0 text-truncate">${esc(p.name)}</h6>
          </div>
          <div class="small mb-1">
            ${installed}
            <small class="text-muted ms-1">range ${esc(p.version)}</small>
          </div>
          ${p.description ? `<p class="small text-muted mb-2">${esc(p.description)}</p>` : ''}
          <div class="small">
            <a href="${esc(p.npm)}" target="_blank" rel="noopener">npm</a>
            ${p.repository ? ` · <a href="${esc(p.repository)}" target="_blank" rel="noopener">repo</a>` : ''}
            ${p.homepage && p.homepage !== p.repository ? ` · <a href="${esc(p.homepage)}" target="_blank" rel="noopener">docs</a>` : ''}
          </div>
        </div>
        <div class="card-footer">
          <button class="btn btn-sm btn-outline-danger btn-plugin-uninstall" data-name="${esc(p.name)}">
            <i class="bi bi-trash me-1"></i>Uninstall
          </button>
        </div>
      </div></div>`;
  }

  async function load() {
    list.innerHTML = '<div class="col-12 text-muted small">Loading…</div>';
    const d = await (await fetch('/api/plugins?project_id=' + pid)).json();
    if (d.no_package_json) {
      list.innerHTML = '<div class="col-12 alert alert-warning small">No <code>package.json</code> found in this project.</div>';
      return;
    }
    if (!d.plugins.length) {
      list.innerHTML = '<div class="col-12 text-muted small">No <code>hexo-*</code> plugins installed.</div>';
      return;
    }
    list.innerHTML = d.plugins.map(renderPlugin).join('');
    list.querySelectorAll('.btn-plugin-uninstall').forEach(b => {
      b.addEventListener('click', () => {
        confirmAction('Uninstall ' + b.dataset.name + '?', async () => {
          b.disabled = true;
          log.textContent = 'Uninstalling…'; log.classList.remove('d-none');
          const r = await fetch('/api/plugins?project_id=' + pid, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'uninstall', name: b.dataset.name }),
          });
          const j = await r.json();
          log.textContent = j.log || j.error || 'Done';
          if (j.ok) { showSuccess('Uninstalled'); load(); }
          else showError(j.error || 'Uninstall failed');
        });
      });
    });
  }

  document.getElementById('pluginInstallBtn').addEventListener('click', async () => {
    const name = document.getElementById('pluginInstallName').value.trim();
    if (!name) return;
    log.textContent = 'Installing ' + name + '…'; log.classList.remove('d-none');
    const r = await fetch('/api/plugins?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'install', name }),
    });
    const j = await r.json();
    log.textContent = j.log || j.error || 'Done';
    if (j.ok) {
      showSuccess('Installed ' + name);
      document.getElementById('pluginInstallName').value = '';
      load();
    } else {
      showError(j.error || 'Install failed');
    }
  });

  load();
})();

// ── Scheduled builds (settings tab) ──────────────────────────────────────────
(() => {
  const panel = document.getElementById('settingsPanel');
  if (!panel) return;
  const pid    = parseInt(panel.dataset.projectId);
  const list   = document.getElementById('schedulesList');
  const newBtn = document.getElementById('newScheduleBtn');
  if (!list || !newBtn) return;

  async function api(body) {
    const r = await fetch('/api/schedules?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    return r.json();
  }

  function bindRow(row) {
    const id = parseInt(row.dataset.id);
    row.querySelector('.schedule-save').addEventListener('click', async () => {
      const j = await api({
        action: 'update', id,
        cmd_id:     row.querySelector('.schedule-cmd').value,
        cron:       row.querySelector('.schedule-cron').value.trim(),
        is_enabled: row.querySelector('.schedule-enabled').checked,
      });
      j.ok ? showSuccess('Saved') : showError(j.error || 'Save failed');
    });
    row.querySelector('.schedule-enabled').addEventListener('change', async (e) => {
      await api({ action: 'update', id, is_enabled: e.target.checked });
    });
    row.querySelector('.schedule-delete').addEventListener('click', () => {
      confirmAction('Delete this schedule?', async () => {
        const j = await api({ action: 'delete', id });
        if (j.ok) row.remove();
      });
    });
  }

  list.querySelectorAll('.schedule-row').forEach(bindRow);

  newBtn.addEventListener('click', async () => {
    const firstCmd = panel.querySelector('.schedule-cmd')?.options[0]?.value
      || document.querySelector('.btn-run-cmd')?.dataset.cmd
      || 'generate';
    const j = await api({ action: 'create', cmd_id: firstCmd, cron: '0 3 * * *', is_enabled: 1 });
    if (j.id) location.reload();
    else showError(j.error || 'Create failed');
  });
})();

// ── Server-log analytics — Settings tab controls ────────────────────────────
(() => {
  const root = document.getElementById('analyticsSettings');
  if (!root) return;
  const pid    = parseInt(root.dataset.projectId);
  const status = document.getElementById('analyticsStatus');

  async function saveSetting(key, value) {
    return fetch('/api/projects', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'update_setting', id: pid, key, value }),
    }).then(r => r.json());
  }

  document.getElementById('analyticsSaveBtn').addEventListener('click', async () => {
    await saveSetting('analytics_log_path',   document.getElementById('analyticsLogPath').value.trim());
    await saveSetting('analytics_log_format', document.getElementById('analyticsLogFormat').value);
    await saveSetting('analytics_log_filter', document.getElementById('analyticsLogFilter').value.trim());
    showSuccess('Saved');
  });

  document.getElementById('analyticsImportBtn').addEventListener('click', async () => {
    const btn = document.getElementById('analyticsImportBtn');
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…';
    status.textContent = 'Importing…';
    const r = await fetch('/api/analytics_import?project_id=' + pid, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'run' }),
    });
    const j = await r.json();
    btn.disabled = false; btn.innerHTML = orig;
    if (j.ok) {
      showSuccess(`Imported ${j.imported} new rows (skipped ${j.skipped})`);
      status.textContent = `Last import just now — added ${j.imported} rows, skipped ${j.skipped}.`;
    } else {
      showError(j.error || 'Import failed');
      status.textContent = 'Last import failed: ' + (j.error || 'unknown error');
    }
  });

  document.getElementById('analyticsResetBtn').addEventListener('click', () => {
    confirmAction('Reset import position? Next "Import now" will re-scan the log from the start.', async () => {
      const r = await fetch('/api/analytics_import?project_id=' + pid, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'reset' }),
      });
      const j = await r.json();
      if (j.ok) showSuccess('Position reset');
      else showError(j.error || 'Reset failed');
    });
  });
})();

// ── Analytics (project Analytics tab) ────────────────────────────────────────
(() => {
  const panel = document.getElementById('analyticsPanel');
  if (!panel) return;
  const pid    = parseInt(panel.dataset.projectId);
  const body   = document.getElementById('analyticsBody');
  const range  = document.getElementById('analyticsRange');
  const refresh = document.getElementById('analyticsRefreshBtn');

  function renderEmpty() {
    body.innerHTML = `<div class="alert alert-info py-2 small mb-0">
      No visits recorded yet. Configure the access-log path below and click
      <strong>Import now</strong> to load history.
    </div>`;
  }

  function delta(cur, prev) {
    if (prev === 0) return cur === 0 ? { txt: '', cls: 'text-muted' } : { txt: 'new', cls: 'text-success' };
    const pct = Math.round((cur - prev) / prev * 100);
    if (pct === 0)  return { txt: '0%',           cls: 'text-muted'   };
    if (pct  >  0)  return { txt: '+' + pct + '%', cls: 'text-success' };
    return                  { txt: pct + '%',     cls: 'text-danger'  };
  }

  function dayKey(date) { return date.toISOString().slice(0, 10); }

  // SVG line chart with current + previous-period overlay.
  function renderLineChart(series, prevSeries, days) {
    const w = 720, h = 160, padL = 30, padR = 8, padT = 8, padB = 22;
    const innerW = w - padL - padR;
    const innerH = h - padT - padB;
    const today  = new Date(); today.setHours(0,0,0,0);

    const cur = {}; series.forEach(s => cur[s.day] = s.views);
    const prv = {}; prevSeries.forEach(s => prv[s.day] = s.views);

    // Build full-day arrays so missing days appear as 0.
    const curArr = [], prvArr = [];
    for (let i = days - 1; i >= 0; i--) {
      const d = new Date(today); d.setDate(today.getDate() - i);
      const dPrev = new Date(today); dPrev.setDate(today.getDate() - i - days);
      curArr.push(cur[dayKey(d)]    || 0);
      prvArr.push(prv[dayKey(dPrev)] || 0);
    }
    const max = Math.max(1, ...curArr, ...prvArr);
    const stepX = days > 1 ? innerW / (days - 1) : innerW;
    const toXY = (i, v) => [padL + i * stepX, padT + innerH - (v / max) * innerH];

    const path = (arr) => arr.map((v, i) => {
      const [x, y] = toXY(i, v);
      return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');

    const fill = curArr.map((v, i) => {
      const [x, y] = toXY(i, v);
      return (i === 0 ? `M${x.toFixed(1)},${(padT+innerH).toFixed(1)} L${x.toFixed(1)},${y.toFixed(1)}`
                      : ` L${x.toFixed(1)},${y.toFixed(1)}`);
    }).join('') + ` L${(padL + innerW).toFixed(1)},${(padT+innerH).toFixed(1)} Z`;

    // Y-axis ticks (0 / max/2 / max)
    const ticks = [0, Math.round(max / 2), max].map(v => {
      const y = padT + innerH - (v / max) * innerH;
      return `<line x1="${padL}" y1="${y}" x2="${padL+innerW}" y2="${y}" stroke="#30363d" stroke-dasharray="2,3"/>
              <text x="${padL-4}" y="${y+3}" text-anchor="end" font-size="10" fill="#6e7681">${v}</text>`;
    }).join('');

    // X-axis labels — first/middle/last
    const labels = [0, Math.floor((days - 1) / 2), days - 1].map(i => {
      const d = new Date(today); d.setDate(today.getDate() - (days - 1 - i));
      const x = padL + i * stepX;
      return `<text x="${x}" y="${h-4}" text-anchor="middle" font-size="10" fill="#6e7681">${
        d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
      }</text>`;
    }).join('');

    return `<svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="xMidYMid meet">
      ${ticks}
      <path d="${fill}" fill="rgba(88,166,255,.12)" stroke="none"/>
      <path d="${path(prvArr)}" fill="none" stroke="#6e7681" stroke-width="1" stroke-dasharray="3,3"/>
      <path d="${path(curArr)}" fill="none" stroke="#58a6ff" stroke-width="1.7"/>
      ${labels}
    </svg>`;
  }

  // 24-hour bar chart
  function renderHours(hours) {
    const byHour = {}; hours.forEach(h => byHour[h.hour] = h.views);
    const max = Math.max(1, ...Object.values(byHour));
    const w = 360, h = 64, gap = 2, barW = (w - 23 * gap) / 24;
    const bars = [];
    for (let i = 0; i < 24; i++) {
      const v   = byHour[i] || 0;
      const bh  = (v / max) * (h - 14);
      const x   = i * (barW + gap);
      const y   = h - 12 - bh;
      bars.push(`<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}"
                      height="${bh.toFixed(1)}" fill="#388bfd" opacity=".75"/>`);
    }
    const labels = [0, 6, 12, 18].map(i => {
      const x = i * (barW + gap) + barW / 2;
      return `<text x="${x.toFixed(1)}" y="${h-1}" text-anchor="middle" font-size="9" fill="#6e7681">${i}h</text>`;
    }).join('');
    return `<svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="xMidYMid meet">
      ${bars.join('')}${labels}
    </svg>`;
  }

  function renderTable(rows, html) {
    if (!rows.length) return '<div class="text-muted small">—</div>';
    return html;
  }

  async function load() {
    body.innerHTML = '<div class="text-muted small">Loading…</div>';
    const days = parseInt(range.value);
    const r = await fetch(`/api/analytics?project_id=${pid}&days=${days}`);
    const d = await r.json();
    if (d.error) { body.innerHTML = `<div class="text-danger small">${esc(d.error)}</div>`; return; }
    if (d.all_time.views === 0) { renderEmpty(); return; }

    const dV = delta(d.window.views,   d.previous.views);
    const dU = delta(d.window.uniques, d.previous.uniques);

    const top = d.top_pages.map(p => `
      <tr>
        <td><code class="small text-truncate d-inline-block" style="max-width:340px">${esc(p.path)}</code></td>
        <td class="text-end">${p.views}</td>
        <td class="text-end text-muted">${p.uniques}</td>
      </tr>`).join('');

    const refs = d.top_refs.length
      ? d.top_refs.map(r => `<tr>
          <td><span class="text-truncate d-inline-block" style="max-width:340px">${esc(r.referrer)}</span></td>
          <td class="text-end">${r.views}</td>
        </tr>`).join('')
      : '';

    const rows404 = (d.top_404s || []).map(r => `<tr>
        <td><code class="small text-truncate d-inline-block" style="max-width:340px">${esc(r.path)}</code></td>
        <td class="text-end">${r.hits}</td>
        <td class="text-end text-muted small">${esc((r.last_hit || '').replace('T', ' ').slice(0, 16))}</td>
      </tr>`).join('');

    body.innerHTML = `
      <div class="row g-3 mb-3">
        <div class="col-md-3 col-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Views (last ${d.days}d)</div>
            <div class="h3 mb-0">${d.window.views}</div>
            <small class="${dV.cls}">${dV.txt}</small>
            <small class="text-muted ms-1">vs prev ${d.days}d (${d.previous.views})</small>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small" title="Sum of per-day unique visitors. Daily-rotating salt means visitors are not linked across days.">
              Unique visitors <i class="bi bi-info-circle small"></i>
            </div>
            <div class="h3 mb-0">${d.window.uniques}</div>
            <small class="${dU.cls}">${dU.txt}</small>
            <small class="text-muted ms-1">vs prev (${d.previous.uniques})</small>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">All-time views</div>
            <div class="h3 mb-0">${d.all_time.views}</div>
            <small class="text-muted">${d.all_time.uniques} uniques</small>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Tracking since</div>
            <div class="h6 mb-0">${esc((d.all_time.first_seen || '—').slice(0, 10))}</div>
            <small class="text-muted">last hit ${esc((d.all_time.last_seen || '—').slice(0, 16).replace('T', ' '))}</small>
          </div>
        </div>
      </div>

      <div class="border rounded p-2 mb-3">
        <div class="d-flex align-items-center mb-1 small text-muted">
          <span><span style="color:#58a6ff">●</span> last ${d.days}d</span>
          <span class="ms-3"><span style="color:#6e7681">— —</span> previous ${d.days}d</span>
        </div>
        ${renderLineChart(d.series, d.prev_series || [], d.days)}
      </div>

      <div class="row g-3">
        <div class="col-md-7">
          <h6 class="small text-muted mb-2">Top pages</h6>
          <table class="table table-sm table-borderless mb-3">
            <thead><tr><th>Path</th><th class="text-end">Views</th><th class="text-end">Uniques</th></tr></thead>
            <tbody>${top}</tbody>
          </table>
        </div>
        <div class="col-md-5">
          <h6 class="small text-muted mb-2">Referrers</h6>
          ${renderTable(d.top_refs, `<table class="table table-sm table-borderless mb-3">
            <thead><tr><th>From</th><th class="text-end">Views</th></tr></thead>
            <tbody>${refs}</tbody>
          </table>`)}

          <h6 class="small text-muted mb-2 mt-1">Hour of day</h6>
          <div class="border rounded p-2 mb-3">${renderHours(d.hours || [])}</div>
        </div>
      </div>

      ${rows404 ? `
      <h6 class="small text-muted mb-2 mt-2">
        <i class="bi bi-exclamation-triangle text-warning me-1"></i>
        Top 404s in this window
      </h6>
      <table class="table table-sm table-borderless mb-0">
        <thead><tr><th>Path</th><th class="text-end">Hits</th><th class="text-end">Last seen</th></tr></thead>
        <tbody>${rows404}</tbody>
      </table>` : ''}`;
  }

  range.addEventListener('change', load);
  refresh?.addEventListener('click', load);
  load();
})();

// ── Project sidebar collapse toggle ──────────────────────────────────────────
(() => {
  const sb = document.getElementById('projectSidebar');
  if (!sb) return;
  const KEY = 'hackmancms_sidebar_collapsed';
  if (localStorage.getItem(KEY) === '1') sb.classList.add('collapsed');
  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    localStorage.setItem(KEY, sb.classList.contains('collapsed') ? '1' : '0');
  });
})();

// ── Keyboard shortcuts ───────────────────────────────────────────────────────
(() => {
  let chord = null;
  let chordTimer = null;
  const isTyping = () => {
    const el = document.activeElement;
    if (!el) return false;
    if (el.isContentEditable) return true;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
  };

  function handle(key) {
    if (chord === 'g') {
      chord = null;
      if (key === 'd') location.href = '/';
      else if (key === 's') location.href = '/settings';
      else if (key === 'a') location.href = '/audit';
      return;
    }
    if (key === '?') {
      const m = document.getElementById('shortcutsModal');
      if (m) bootstrap.Modal.getOrCreateInstance(m).show();
      return;
    }
    if (key === 'g') {
      chord = 'g';
      clearTimeout(chordTimer);
      chordTimer = setTimeout(() => { chord = null; }, 1200);
      return;
    }
    // Project page only
    if (key === 'n') {
      const btn = document.getElementById('newItemBtn');
      if (btn) { btn.click(); return; }
    }
    if (key === 'b') {
      const btn = document.querySelector('.btn-run-cmd[data-cmd="generate"]');
      if (btn) { btn.click(); showSuccess('Triggered generate'); return; }
    }
  }

  document.addEventListener('keydown', e => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (isTyping()) return;
    if (e.key === '?') { e.preventDefault(); handle('?'); return; }
    if (/^[a-z]$/.test(e.key)) handle(e.key);
  });
})();

// ── Restore persisted editor tabs on page load ───────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => { try { _restoreTabState(); } catch(e) {} }, 50);
});

// ── Bot: control panel ────────────────────────────────────────────────────────
(function () {
  const panel = document.getElementById('botControlPanel');
  if (!panel) return;
  const pid = panel.dataset.projectId;

  const dot      = document.getElementById('botStatusDot');
  const txt      = document.getElementById('botStatusText');
  const since    = document.getElementById('botSince');
  const svc      = document.getElementById('botService');
  const outputWrap = document.getElementById('botCmdOutputWrap');
  const output   = document.getElementById('botCmdOutput');

  function setStatus(data) {
    const active = data.active;
    dot.style.background = active ? '#198754' : '#dc3545';
    txt.textContent = active ? 'Running' : (data.status || 'Stopped');
    since.textContent = data.since ? 'since ' + data.since : '';
    svc.textContent = data.service ? data.service + '.service' : '';
  }

  async function loadStatus() {
    try {
      const r = await fetch('/api/botcontrol?action=status&project_id=' + pid);
      const d = await r.json();
      if (d.error) { txt.textContent = d.error; return; }
      setStatus(d);
    } catch (e) { txt.textContent = 'Error loading status'; }
  }

  async function runAction(action) {
    outputWrap.classList.remove('d-none');
    output.textContent = action + '…';
    try {
      const r = await fetch('/api/botcontrol', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: +pid, action }),
      });
      const d = await r.json();
      output.textContent = d.output || (d.ok ? 'Done.' : 'Failed.');
      setStatus(d);
    } catch (e) { output.textContent = 'Request failed: ' + e.message; }
  }

  document.getElementById('botStartBtn').addEventListener('click', () => runAction('start'));
  document.getElementById('botStopBtn').addEventListener('click', () => runAction('stop'));
  document.getElementById('botRestartBtn').addEventListener('click', () => runAction('restart'));
  document.getElementById('botRefreshStatusBtn').addEventListener('click', loadStatus);
  document.getElementById('botClearOutputBtn').addEventListener('click', () => {
    output.textContent = '';
    outputWrap.classList.add('d-none');
  });

  loadStatus();
})();

// ── Bot: config editor ────────────────────────────────────────────────────────
(function () {
  const panel = document.getElementById('botConfigPanel');
  if (!panel) return;
  const pid = panel.dataset.projectId;

  function rgbToHex([r, g, b]) {
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
  }
  function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }

  let _config = null;

  async function loadConfig() {
    const r = await fetch('/api/botconfig?action=get&project_id=' + pid);
    const d = await r.json();
    if (d.error) { showError(d.error); return; }
    _config = d.config;
    renderStreamers(_config.twitch || {});
    renderFeeds(_config.rss || {});
    renderMastodon(_config.mastodon || {});
    renderLinkedinPages(_config.linkedin?.pages || {});
    renderLinkedinConnection(_config.linkedin || {});
  }

  // ── Streamers ────────────────────────────────────────────────────────────────
  function renderStreamers(twitch) {
    const list = document.getElementById('streamerList');
    const names = Object.keys(twitch);
    if (!names.length) { list.innerHTML = '<div class="text-muted small">No streamers configured.</div>'; return; }
    list.innerHTML = names.map(name => {
      const s = twitch[name];
      const hex = rgbToHex(s.color || [255, 255, 255]);
      return `<div class="d-flex align-items-center gap-2 border rounded p-2 mb-1">
        <span class="rounded-circle border flex-shrink-0" style="width:16px;height:16px;background:${hex}"></span>
        <span class="fw-semibold small">${esc(name)}</span>
        <small class="text-muted ms-1">ch: <code>${esc(String(s.channel_id ?? '–'))}</code></small>
        ${s.role_id ? `<small class="text-muted">role: <code>${esc(String(s.role_id))}</code></small>` : ''}
        <div class="ms-auto d-flex gap-1">
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 edit-streamer-btn" data-name="${esc(name)}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-xs btn-outline-danger py-0 px-1 del-streamer-btn" data-name="${esc(name)}"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('.edit-streamer-btn').forEach(b => b.addEventListener('click', () => openStreamerModal('edit', b.dataset.name)));
    list.querySelectorAll('.del-streamer-btn').forEach(b => b.addEventListener('click', () => deleteItem('remove_streamer', b.dataset.name, `Remove streamer "${b.dataset.name}"?`, 'Streamer removed')));
  }

  function openStreamerModal(mode, name = '') {
    const s = (mode === 'edit' && _config?.twitch?.[name]) || {};
    document.getElementById('streamerModalTitle').textContent = mode === 'add' ? 'Add Streamer' : 'Edit Streamer';
    document.getElementById('streamerModalMode').value = mode;
    document.getElementById('streamerName').value = name;
    document.getElementById('streamerName').disabled = mode === 'edit';
    document.getElementById('streamerChannelId').value = s.channel_id ?? '';
    document.getElementById('streamerRoleId').value = s.role_id ?? '';
    document.getElementById('streamerColor').value = rgbToHex(s.color || [100, 65, 165]);
    document.getElementById('streamerModalError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('streamerModal')).show();
  }

  document.getElementById('addStreamerBtn').addEventListener('click', () => openStreamerModal('add'));
  document.getElementById('streamerModalSaveBtn').addEventListener('click', async () => {
    const mode = document.getElementById('streamerModalMode').value;
    const name = document.getElementById('streamerName').value.trim();
    const errEl = document.getElementById('streamerModalError');
    errEl.classList.add('d-none');
    if (!name) { errEl.textContent = 'Name required'; errEl.classList.remove('d-none'); return; }
    const ok = await apiPost({ action: mode === 'add' ? 'add_streamer' : 'save_streamer', name, data: {
      channel_id: document.getElementById('streamerChannelId').value.trim(),
      role_id:    document.getElementById('streamerRoleId').value.trim(),
      color:      hexToRgb(document.getElementById('streamerColor').value),
    }}, errEl);
    if (ok) { bootstrap.Modal.getOrCreateInstance(document.getElementById('streamerModal')).hide(); showSuccess('Streamer saved'); loadConfig(); }
  });

  // ── RSS feeds ────────────────────────────────────────────────────────────────
  function renderFeeds(rss) {
    const list = document.getElementById('rssList');
    const names = Object.keys(rss);
    if (!names.length) { list.innerHTML = '<div class="text-muted small">No RSS feeds configured.</div>'; return; }
    list.innerHTML = names.map(name => {
      const f = rss[name];
      const hex = rgbToHex(Array.isArray(f.color) ? f.color : [255, 215, 0]);
      const activeBadge = f.active
        ? '<span class="badge bg-success-subtle text-success border ms-1">active</span>'
        : '<span class="badge bg-secondary-subtle text-muted border ms-1">paused</span>';
      const acct = f.mastodon_account ? `<small class="text-muted"><i class="bi bi-mastodon"></i> ${esc(f.mastodon_account)}</small>` : '';
      const liPage = f.linkedin_page ? `<small class="text-muted"><i class="bi bi-linkedin"></i> ${esc(f.linkedin_page)}</small>` : '';
      return `<div class="d-flex align-items-center gap-2 border rounded p-2 mb-1 flex-wrap">
        <span class="rounded-circle border flex-shrink-0" style="width:16px;height:16px;background:${hex}"></span>
        <span class="fw-semibold small">${esc(name)}</span>${activeBadge}
        ${acct}${liPage}
        <small class="text-muted text-truncate" style="max-width:180px" title="${esc(f.rss_url || '')}">${esc(f.rss_url || '–')}</small>
        <div class="ms-auto d-flex gap-1">
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 repost-rss-btn" data-name="${esc(name)}" title="Repeat last post"><i class="bi bi-arrow-repeat"></i></button>
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 edit-rss-btn" data-name="${esc(name)}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-xs btn-outline-danger py-0 px-1 del-rss-btn" data-name="${esc(name)}"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('.repost-rss-btn').forEach(b => b.addEventListener('click', () => {
      const name = b.dataset.name;
      confirmAction(`Repeat last post for feed "${name}"?\nThe bot will repost it on the next poll (within 5 min).`, async () => {
        const r = await fetch('/api/botcompose', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: +pid, action: 'rss_repost', feed_name: name }),
        });
        const d = await r.json();
        if (d.ok) showSuccess(d.message || 'Repost queued — bot will post on next poll');
        else showError(d.error || 'Failed');
      });
    }));
    list.querySelectorAll('.edit-rss-btn').forEach(b => b.addEventListener('click', () => openRssModal('edit', b.dataset.name)));
    list.querySelectorAll('.del-rss-btn').forEach(b => b.addEventListener('click', () => deleteItem('remove_rss', b.dataset.name, `Remove RSS feed "${b.dataset.name}"?`, 'Feed removed')));
  }

  function populateSelect(id, options, selected) {
    const sel = document.getElementById(id);
    sel.innerHTML = '<option value="">— none —</option>' +
      options.map(([v, l]) => `<option value="${esc(v)}" ${v === selected ? 'selected' : ''}>${esc(l)}</option>`).join('');
  }

  function openRssModal(mode, name = '') {
    const f = (mode === 'edit' && _config?.rss?.[name]) || {};
    document.getElementById('rssModalTitle').textContent = mode === 'add' ? 'Add RSS Feed' : 'Edit RSS Feed';
    document.getElementById('rssModalMode').value = mode;
    document.getElementById('rssName').value = name;
    document.getElementById('rssName').disabled = mode === 'edit';
    document.getElementById('rssUrl').value = f.rss_url ?? '';
    document.getElementById('rssChannelId').value = f.channel_id ?? '';
    document.getElementById('rssRoleId').value = f.role_id ?? '';
    document.getElementById('rssColor').value = rgbToHex(Array.isArray(f.color) ? f.color : [255, 215, 0]);
    document.getElementById('rssActive').checked = f.active !== false;
    populateSelect('rssMastodonAccount',
      Object.entries(_config?.mastodon || {}).map(([k, v]) => [k, v.label || k]),
      f.mastodon_account || '');
    populateSelect('rssLinkedinPage',
      Object.entries(_config?.linkedin?.pages || {}).map(([k, v]) => [k, v.label || k]),
      f.linkedin_page || '');
    document.getElementById('rssModalError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rssModal')).show();
  }

  document.getElementById('addRssBtn').addEventListener('click', () => openRssModal('add'));
  document.getElementById('rssModalSaveBtn').addEventListener('click', async () => {
    const mode = document.getElementById('rssModalMode').value;
    const name = document.getElementById('rssName').value.trim();
    const errEl = document.getElementById('rssModalError');
    errEl.classList.add('d-none');
    if (!name) { errEl.textContent = 'Name required'; errEl.classList.remove('d-none'); return; }
    const ok = await apiPost({ action: mode === 'add' ? 'add_rss' : 'save_rss', name, data: {
      rss_url:          document.getElementById('rssUrl').value.trim(),
      channel_id:       document.getElementById('rssChannelId').value.trim(),
      role_id:          document.getElementById('rssRoleId').value.trim(),
      color:            hexToRgb(document.getElementById('rssColor').value),
      active:           document.getElementById('rssActive').checked,
      mastodon_account: document.getElementById('rssMastodonAccount').value,
      linkedin_page:    document.getElementById('rssLinkedinPage').value,
    }}, errEl);
    if (ok) { bootstrap.Modal.getOrCreateInstance(document.getElementById('rssModal')).hide(); showSuccess('Feed saved'); loadConfig(); }
  });

  // ── Mastodon accounts ─────────────────────────────────────────────────────────
  function renderMastodon(mastodon) {
    const list = document.getElementById('mastodonList');
    const names = Object.keys(mastodon);
    if (!names.length) { list.innerHTML = '<div class="text-muted small">No accounts configured.</div>'; return; }
    list.innerHTML = names.map(name => {
      const a = mastodon[name];
      return `<div class="d-flex align-items-center gap-2 border rounded p-2 mb-1">
        <span class="fw-semibold small">${esc(a.label || name)}</span>
        <code class="small text-muted">MASTODON_TOKEN_${esc(name.toUpperCase())}</code>
        <small class="text-muted text-truncate" style="max-width:160px">${esc(a.api_base_url || '')}</small>
        <div class="ms-auto d-flex gap-1">
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 compose-mastodon-btn"
                  data-name="${esc(name)}" data-label="${esc(a.label || name)}" title="Post to Mastodon">
            <i class="bi bi-pencil-square"></i></button>
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 edit-mastodon-btn" data-name="${esc(name)}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-xs btn-outline-danger py-0 px-1 del-mastodon-btn" data-name="${esc(name)}"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('.compose-mastodon-btn').forEach(b => b.addEventListener('click', () => openComposeModal('mastodon', b.dataset.name, b.dataset.label)));
    list.querySelectorAll('.edit-mastodon-btn').forEach(b => b.addEventListener('click', () => openMastodonModal('edit', b.dataset.name)));
    list.querySelectorAll('.del-mastodon-btn').forEach(b => b.addEventListener('click', () => deleteItem('remove_mastodon', b.dataset.name, `Remove account "${b.dataset.name}"?`, 'Account removed')));
  }

  function openMastodonModal(mode, name = '') {
    const a = (mode === 'edit' && _config?.mastodon?.[name]) || {};
    document.getElementById('mastodonModalTitle').textContent = mode === 'add' ? 'Add Mastodon Account' : 'Edit Mastodon Account';
    document.getElementById('mastodonModalMode').value = mode;
    document.getElementById('mastodonName').value = name;
    document.getElementById('mastodonName').disabled = mode === 'edit';
    document.getElementById('mastodonLabel').value = a.label ?? '';
    document.getElementById('mastodonBase').value = a.api_base_url ?? '';
    document.getElementById('mastodonModalError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mastodonModal')).show();
  }

  document.getElementById('addMastodonBtn').addEventListener('click', () => openMastodonModal('add'));
  document.getElementById('mastodonModalSaveBtn').addEventListener('click', async () => {
    const mode = document.getElementById('mastodonModalMode').value;
    const name = document.getElementById('mastodonName').value.trim();
    const errEl = document.getElementById('mastodonModalError');
    errEl.classList.add('d-none');
    if (!name) { errEl.textContent = 'Key required'; errEl.classList.remove('d-none'); return; }
    const ok = await apiPost({ action: mode === 'add' ? 'add_mastodon' : 'save_mastodon', name, data: {
      label:        document.getElementById('mastodonLabel').value.trim(),
      api_base_url: document.getElementById('mastodonBase').value.trim(),
    }}, errEl);
    if (ok) { bootstrap.Modal.getOrCreateInstance(document.getElementById('mastodonModal')).hide(); showSuccess('Account saved'); loadConfig(); }
  });

  // ── LinkedIn pages ────────────────────────────────────────────────────────────
  function renderLinkedinConnection(li) {
    const label = document.getElementById('liConnectionLabel');
    const expiry = document.getElementById('liTokenExpiry');
    const connected = li.access_token === '***';
    label.textContent = connected ? 'Connected to LinkedIn' : 'Not connected';
    label.className = 'small fw-semibold ' + (connected ? 'text-success' : 'text-muted');
    expiry.textContent = li.token_expiry ? 'Expires: ' + li.token_expiry : '';
  }

  document.getElementById('liConnectBtn').addEventListener('click', async () => {
    const r = await fetch('/api/linkedin_auth?project_id=' + pid);
    const d = await r.json();
    if (d.error) { showError(d.error); return; }
    window.location.href = d.url;
  });

  function renderLinkedinPages(pages) {
    const list = document.getElementById('linkedinPagesList');
    const names = Object.keys(pages);
    if (!names.length) { list.innerHTML = '<div class="text-muted small">No pages configured.</div>'; return; }
    list.innerHTML = names.map(name => {
      const p = pages[name];
      return `<div class="d-flex align-items-center gap-2 border rounded p-2 mb-1">
        <span class="fw-semibold small">${esc(p.label || name)}</span>
        <code class="small text-muted">${esc(p.organization_id || '–')}</code>
        <div class="ms-auto d-flex gap-1">
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 compose-linkedin-btn"
                  data-name="${esc(name)}" data-label="${esc(p.label || name)}" title="Post to LinkedIn">
            <i class="bi bi-pencil-square"></i></button>
          <button class="btn btn-xs btn-outline-secondary py-0 px-1 edit-lipage-btn" data-name="${esc(name)}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-xs btn-outline-danger py-0 px-1 del-lipage-btn" data-name="${esc(name)}"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('.compose-linkedin-btn').forEach(b => b.addEventListener('click', () => openComposeModal('linkedin', b.dataset.name, b.dataset.label)));
    list.querySelectorAll('.edit-lipage-btn').forEach(b => b.addEventListener('click', () => openLinkedinPageModal('edit', b.dataset.name)));
    list.querySelectorAll('.del-lipage-btn').forEach(b => b.addEventListener('click', () => deleteItem('remove_linkedin_page', b.dataset.name, `Remove page "${b.dataset.name}"?`, 'Page removed')));
  }

  function openLinkedinPageModal(mode, name = '') {
    const p = (mode === 'edit' && _config?.linkedin?.pages?.[name]) || {};
    document.getElementById('linkedinPageModalTitle').textContent = mode === 'add' ? 'Add LinkedIn Page' : 'Edit LinkedIn Page';
    document.getElementById('linkedinPageModalMode').value = mode;
    document.getElementById('linkedinPageName').value = name;
    document.getElementById('linkedinPageName').disabled = mode === 'edit';
    document.getElementById('linkedinPageLabel').value = p.label ?? '';
    document.getElementById('linkedinPageOrgId').value = p.organization_id ?? '';
    document.getElementById('linkedinPageModalError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('linkedinPageModal')).show();
  }

  document.getElementById('addLinkedinPageBtn').addEventListener('click', () => openLinkedinPageModal('add'));
  document.getElementById('linkedinPageModalSaveBtn').addEventListener('click', async () => {
    const mode = document.getElementById('linkedinPageModalMode').value;
    const name = document.getElementById('linkedinPageName').value.trim();
    const errEl = document.getElementById('linkedinPageModalError');
    errEl.classList.add('d-none');
    if (!name) { errEl.textContent = 'Key required'; errEl.classList.remove('d-none'); return; }
    const ok = await apiPost({ action: mode === 'add' ? 'add_linkedin_page' : 'save_linkedin_page', name, data: {
      label:           document.getElementById('linkedinPageLabel').value.trim(),
      organization_id: document.getElementById('linkedinPageOrgId').value.trim(),
    }}, errEl);
    if (ok) { bootstrap.Modal.getOrCreateInstance(document.getElementById('linkedinPageModal')).hide(); showSuccess('Page saved'); loadConfig(); }
  });

  // ── Compose (Mastodon / LinkedIn direct post) ─────────────────────────────────
  function openComposeModal(type, name, label) {
    document.getElementById('composeModalTitle').textContent = 'Post to ' + label;
    document.getElementById('composeModalType').value = type;
    document.getElementById('composeModalName').value = name;
    document.getElementById('composeText').value = '';
    document.getElementById('composeModalError').classList.add('d-none');
    document.getElementById('composeHint').textContent = type === 'mastodon'
      ? 'Plain text post — no image upload from this composer.'
      : 'Text post to LinkedIn organization page.';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('composeModal')).show();
    setTimeout(() => document.getElementById('composeText').focus(), 300);
  }

  document.getElementById('composeModalPostBtn').addEventListener('click', async () => {
    const type  = document.getElementById('composeModalType').value;
    const name  = document.getElementById('composeModalName').value;
    const text  = document.getElementById('composeText').value.trim();
    const errEl = document.getElementById('composeModalError');
    errEl.classList.add('d-none');
    if (!text) { errEl.textContent = 'Text is required'; errEl.classList.remove('d-none'); return; }

    const body = { project_id: +pid, action: type === 'mastodon' ? 'mastodon_post' : 'linkedin_post', text };
    if (type === 'mastodon') body.account_name = name;
    else body.page_name = name;

    const btn = document.getElementById('composeModalPostBtn');
    btn.disabled = true;
    const r = await fetch('/api/botcompose', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const d = await r.json();
    btn.disabled = false;
    if (d.ok) {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('composeModal')).hide();
      showSuccess('Posted!');
    } else {
      errEl.textContent = d.error || 'Post failed';
      errEl.classList.remove('d-none');
    }
  });

  // ── Shared helpers ────────────────────────────────────────────────────────────
  async function apiPost(body, errEl) {
    const r = await fetch('/api/botconfig', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: +pid, ...body }),
    });
    const d = await r.json();
    if (d.error && errEl) { errEl.textContent = d.error; errEl.classList.remove('d-none'); return false; }
    if (d.error) { showError(d.error); return false; }
    return true;
  }

  function deleteItem(action, name, confirmMsg, successMsg) {
    confirmAction(confirmMsg, async () => {
      const ok = await apiPost({ action, name }, null);
      if (ok) { showSuccess(successMsg); loadConfig(); }
    });
  }

  loadConfig();
})();

// ── Bot: log viewer ───────────────────────────────────────────────────────────
(function () {
  const panel = document.getElementById('botLogsPanel');
  if (!panel) return;
  const pid  = panel.dataset.projectId;
  const pre  = document.getElementById('logsOutput');
  let _timer = null;

  async function loadLogs() {
    const lines = document.getElementById('logsLineCount').value;
    try {
      const r = await fetch(`/api/botlogs?project_id=${pid}&lines=${lines}`);
      const d = await r.json();
      if (d.error) { pre.textContent = d.error; return; }
      pre.textContent = d.lines.join('\n') || '(no log entries)';
      pre.scrollTop = pre.scrollHeight;
    } catch (e) { pre.textContent = 'Error: ' + e.message; }
  }

  function startAutoRefresh() {
    stopAutoRefresh();
    _timer = setInterval(loadLogs, 5000);
  }
  function stopAutoRefresh() {
    if (_timer) { clearInterval(_timer); _timer = null; }
  }

  document.getElementById('logsRefreshBtn').addEventListener('click', loadLogs);
  document.getElementById('logsLineCount').addEventListener('change', loadLogs);
  document.getElementById('logsAutoRefresh').addEventListener('change', e => {
    e.target.checked ? startAutoRefresh() : stopAutoRefresh();
  });

  loadLogs();
})();

