/**
 * milkdown-mount.js — auto-mount a Milkdown WYSIWYG editor on any
 * <textarea class="mk-mount"> and keep it synced with the underlying
 * textarea. Adapted from Agenda (/opt/agenda/web/lib/milkdown-mount.js)
 * with English UI and HackmanCMS's dark theme.
 *
 * Relies on window.MilkdownKit being populated by the loader in view.php.
 *
 * data- attributes on the textarea:
 *   data-mk-min-height="20rem"   override the editor's min-height
 *   data-mk-required             empty content blocks form submit
 */
(function () {
  'use strict';

  // ── Link dialog ─────────────────────────────────────────────────────
  var _linkModalEl = null;
  function ensureLinkModal() {
    if (_linkModalEl) return _linkModalEl;
    var el = document.createElement('div');
    el.className = 'modal fade';
    el.tabIndex = -1;
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-sm">' +
        '<div class="modal-content">' +
          '<form class="ld-form">' +
            '<div class="modal-header py-2">' +
              '<h5 class="modal-title h6 mb-0"><i class="bi bi-link-45deg"></i> Link</h5>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body">' +
              '<div class="mb-2">' +
                '<label class="form-label small mb-1">URL</label>' +
                '<input type="text" class="form-control form-control-sm ld-url" required ' +
                       'placeholder="https://… / /path / mailto:…">' +
                '<div class="invalid-feedback small ld-url-warning"></div>' +
              '</div>' +
              '<div class="mb-1 ld-text-wrap">' +
                '<label class="form-label small mb-1">Text <span class="text-muted">(optional)</span></label>' +
                '<input type="text" class="form-control form-control-sm ld-text">' +
              '</div>' +
            '</div>' +
            '<div class="modal-footer justify-content-between py-2">' +
              '<button type="button" class="btn btn-sm btn-outline-danger ld-remove d-none">' +
                '<i class="bi bi-trash"></i> Remove' +
              '</button>' +
              '<div class="ms-auto d-flex gap-2">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
                '<button type="submit" class="btn btn-sm btn-primary ld-confirm">Insert</button>' +
              '</div>' +
            '</div>' +
          '</form>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);
    _linkModalEl = el;
    return el;
  }

  function isPlausibleUrl(url) {
    if (!url) return false;
    return /^(https?:|mailto:|tel:|ftp:|#|\/|[\w.-]+\.[a-z]{2,})/i.test(url.trim());
  }

  window.LinkDialog = {
    open: function (opts) {
      opts = opts || {};
      var el = ensureLinkModal();
      var inst = bootstrap.Modal.getOrCreateInstance(el);
      var form = el.querySelector('.ld-form');
      var urlEl = el.querySelector('.ld-url');
      var textEl = el.querySelector('.ld-text');
      var textWrap = el.querySelector('.ld-text-wrap');
      var removeBtn = el.querySelector('.ld-remove');
      var warnEl = el.querySelector('.ld-url-warning');
      var confirmBtn = el.querySelector('.ld-confirm');

      urlEl.value = opts.url || '';
      textEl.value = opts.text || '';
      urlEl.classList.remove('is-invalid');
      warnEl.textContent = '';
      textWrap.classList.toggle('d-none', !!opts.urlOnly);
      removeBtn.classList.toggle('d-none', !opts.canRemove);
      confirmBtn.textContent = opts.canRemove ? 'Save' : 'Insert';

      return new Promise(function (resolve) {
        var done = false;
        function cleanup(result) {
          if (done) return; done = true;
          form.removeEventListener('submit', onSubmit);
          removeBtn.removeEventListener('click', onRemove);
          el.removeEventListener('hidden.bs.modal', onHidden);
          el.removeEventListener('shown.bs.modal', onShown);
          inst.hide();
          resolve(result);
        }
        function onSubmit(e) {
          e.preventDefault();
          var url = urlEl.value.trim();
          if (!url) { urlEl.classList.add('is-invalid'); warnEl.textContent = 'URL required.'; return; }
          if (!/^[a-z][a-z0-9+.-]*:/i.test(url) && !url.startsWith('/') && !url.startsWith('#')) {
            if (/^[\w.-]+\.[a-z]{2,}/i.test(url)) url = 'https://' + url;
          }
          if (!isPlausibleUrl(url)) {
            urlEl.classList.add('is-invalid'); warnEl.textContent = "Doesn't look like a valid URL.";
            urlEl.addEventListener('input', function once() {
              urlEl.classList.remove('is-invalid'); warnEl.textContent = '';
              urlEl.removeEventListener('input', once);
            });
            return;
          }
          cleanup({ action: 'confirm', url: url, text: textEl.value.trim() });
        }
        function onRemove() { cleanup({ action: 'remove' }); }
        function onHidden() { cleanup(null); }
        function onShown()  { urlEl.focus(); urlEl.select(); }

        form.addEventListener('submit', onSubmit);
        removeBtn.addEventListener('click', onRemove);
        el.addEventListener('hidden.bs.modal', onHidden);
        el.addEventListener('shown.bs.modal', onShown);
        inst.show();
      });
    }
  };

  function mountAll() {
    var kit = window.MilkdownKit;
    if (!kit) return;
    document.querySelectorAll('textarea.mk-mount:not([data-mk-mounted])').forEach(function (ta) {
      ta.dataset.mkMounted = '1';
      mountOne(ta, kit);
    });
  }

  function mountOne(ta, kit) {
    var form = ta.closest('form');

    var wrap = document.createElement('div');
    wrap.className = 'mk-mount-wrap';

    var toolbar = document.createElement('div');
    toolbar.className = 'ie-mk-toolbar';
    toolbar.innerHTML =
      '<div class="ie-mk-tools">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bold"          title="Bold"><i class="bi bi-type-bold"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="italic"        title="Italic"><i class="bi bi-type-italic"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="strikethrough" title="Strikethrough"><i class="bi bi-type-strikethrough"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="inlineCode"    title="Inline code"><i class="bi bi-code"></i></button>' +
        '<span class="ie-mk-sep"></span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bullet"        title="Bullet list"><i class="bi bi-list-ul"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="ordered"       title="Ordered list"><i class="bi bi-list-ol"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="blockquote"    title="Quote"><i class="bi bi-blockquote-left"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="codeBlock"     title="Code block"><i class="bi bi-code-square"></i></button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="link"          title="Link"><i class="bi bi-link-45deg"></i></button>' +
      '</div>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary mk-mode" title="Switch to Markdown source">' +
        '<i class="bi bi-markdown"></i> MD' +
      '</button>';

    var body = document.createElement('div');
    body.className = 'ie-mk-body';
    // Only honour an explicit data-mk-min-height; otherwise let CSS size the
    // body via the surrounding flex layout (so it actually fills the tab pane
    // and lets the inner ProseMirror trigger overflow scroll).
    var minH = ta.dataset.mkMinHeight;
    if (minH) body.style.minHeight = minH;

    var editorEl = document.createElement('div');
    body.appendChild(editorEl);

    var mdTa = document.createElement('textarea');
    mdTa.className = 'form-control ie-mk-ta';
    mdTa.rows = 4;
    mdTa.style.display = 'none';
    if (minH) mdTa.style.minHeight = minH;
    body.appendChild(mdTa);

    wrap.appendChild(toolbar);
    wrap.appendChild(body);

    var initialMd = ta.value;
    ta.style.display = 'none';
    var wasRequired = ta.hasAttribute('required');
    if (wasRequired) { ta.removeAttribute('required'); ta.dataset.mkRequired = '1'; }
    ta.parentNode.insertBefore(wrap, ta);

    var mkEditor = null;
    var mode = 'wysiwyg';

    kit.Editor.make()
      .config(function (ctx) {
        ctx.set(kit.rootCtx, editorEl);
        ctx.set(kit.defaultValueCtx, initialMd);
      })
      .use(kit.commonmark).use(kit.gfm).use(kit.history)
      .create()
      .then(function (ed) {
        mkEditor = ed;
        ta.dispatchEvent(new CustomEvent('mk-ready'));
      })
      .catch(function (err) { console.error('[mk-mount] init failed:', err); });

    function getContent() {
      if (mode === 'markdown') return mdTa.value;
      if (mkEditor) return mkEditor.action(kit.getMarkdown());
      return initialMd;
    }
    function setContent(md) {
      if (mode === 'markdown') { mdTa.value = md; return; }
      if (mkEditor) mkEditor.action(kit.replaceAll(md));
    }
    function insertMd(before, after) {
      var s = mdTa.selectionStart, e = mdTa.selectionEnd;
      var sel = mdTa.value.substring(s, e);
      mdTa.value = mdTa.value.substring(0, s) + before + sel + after + mdTa.value.substring(e);
      mdTa.selectionStart = s + before.length;
      mdTa.selectionEnd   = s + before.length + sel.length;
      mdTa.focus();
    }

    toolbar.querySelector('.ie-mk-tools').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cmd]'); if (!btn) return;
      e.preventDefault();
      var cmd = btn.dataset.cmd;
      if (mode === 'markdown') {
        var md = {
          bold:          function () { insertMd('**', '**'); },
          italic:        function () { insertMd('*', '*'); },
          strikethrough: function () { insertMd('~~', '~~'); },
          inlineCode:    function () { insertMd('`', '`'); },
          bullet:        function () { insertMd('- ', ''); },
          ordered:       function () { insertMd('1. ', ''); },
          blockquote:    function () { insertMd('> ', ''); },
          codeBlock:     function () { insertMd('```\n', '\n```'); },
          link:          function () {
            var s = mdTa.selectionStart, e = mdTa.selectionEnd;
            var sel = mdTa.value.substring(s, e);
            window.LinkDialog.open({ text: sel }).then(function (r) {
              if (!r || r.action !== 'confirm') { mdTa.focus(); return; }
              var txt = r.text || r.url;
              var out = '[' + txt + '](' + r.url + ')';
              mdTa.value = mdTa.value.substring(0, s) + out + mdTa.value.substring(e);
              mdTa.selectionStart = mdTa.selectionEnd = s + out.length;
              mdTa.focus();
            });
          },
        };
        if (md[cmd]) md[cmd](); return;
      }
      if (!mkEditor) return;
      var c = kit.commands;
      function exec(key, payload) { mkEditor.action(function (ctx) { ctx.get(kit.commandsCtx).call(key, payload); }); }
      var wy = {
        bold:          function () { exec(c.bold.key); },
        italic:        function () { exec(c.italic.key); },
        strikethrough: function () { exec(c.strikethrough.key); },
        inlineCode:    function () { exec(c.inlineCode.key); },
        bullet:        function () { exec(c.bulletList.key); },
        ordered:       function () { exec(c.orderedList.key); },
        blockquote:    function () { exec(c.blockquote.key); },
        codeBlock:     function () { exec(c.codeBlock.key); },
        link:          function () {
          window.LinkDialog.open({ urlOnly: true }).then(function (r) {
            if (!r || r.action !== 'confirm') return;
            exec(c.link.key, { href: r.url });
            var pm = editorEl.querySelector('.ProseMirror'); if (pm) pm.focus();
          });
        },
      };
      if (wy[cmd]) wy[cmd]();
      var pm = editorEl.querySelector('.ProseMirror'); if (pm) pm.focus();
    });

    editorEl.addEventListener('click', function (evt) {
      if (mode !== 'wysiwyg') return;
      var a = evt.target.closest('a'); if (!a || !editorEl.contains(a)) return;
      evt.preventDefault(); evt.stopPropagation();
      var pm = editorEl.querySelector('.ProseMirror');
      var href = a.getAttribute('href') || '', text = a.textContent || '';
      function selectAnchor() {
        if (!pm || !document.body.contains(a)) return false;
        pm.focus();
        var range = document.createRange(); range.selectNodeContents(a);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        return true;
      }
      selectAnchor();
      window.LinkDialog.open({ url: href, text: text, canRemove: true, urlOnly: true }).then(function (r) {
        if (!r) return;
        if (!selectAnchor() || !mkEditor) return;
        var c = kit.commands;
        function exec(key, payload) { mkEditor.action(function (ctx) { ctx.get(kit.commandsCtx).call(key, payload); }); }
        if (r.action === 'remove') { exec(c.link.key); }
        else if (r.action === 'confirm') { exec(c.link.key); exec(c.link.key, { href: r.url }); }
        if (pm) pm.focus();
      });
    });

    function switchMkMode() {
      if (mode === 'wysiwyg') {
        mdTa.value = getContent();
        editorEl.style.display = 'none';
        mdTa.style.display = '';
        mdTa.focus();
        mode = 'markdown';
      } else {
        var md = mdTa.value;
        mdTa.style.display = 'none';
        editorEl.style.display = '';
        if (mkEditor) mkEditor.action(kit.replaceAll(md));
        mode = 'wysiwyg';
      }
      var modeBtn = toolbar.querySelector('.mk-mode');
      if (modeBtn) modeBtn.innerHTML = mode === 'wysiwyg'
        ? '<i class="bi bi-markdown"></i> MD'
        : '<i class="bi bi-eye"></i> WYSIWYG';
    }
    toolbar.querySelector('.mk-mode').addEventListener('click', switchMkMode);

    // Track dirty changes
    editorEl.addEventListener('input', function () {
      if (typeof ta.onMkInput === 'function') ta.onMkInput();
    }, true);
    mdTa.addEventListener('input', function () {
      if (typeof ta.onMkInput === 'function') ta.onMkInput();
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        var md = getContent().trim();
        if (ta.dataset.mkRequired && md === '') {
          e.preventDefault();
          var pm = editorEl.querySelector('.ProseMirror');
          if (pm) pm.focus(); else mdTa.focus();
          return;
        }
        ta.value = md;
      });
    }

    ta.mkMount = {
      getContent: getContent,
      setContent: setContent,
      getMode: function () { return mode; },
      setMode: function (target) {
        // target: 'wysiwyg' | 'markdown'
        if (target !== 'wysiwyg' && target !== 'markdown') return;
        if (mode !== target) switchMkMode();
      },
    };
    ta.dispatchEvent(new CustomEvent('mk-mounted'));
  }

  if (window.MilkdownKit) mountAll();
  else window.addEventListener('milkdown-ready', mountAll);

  // Re-scan whenever new mk-mount textareas get inserted (e.g. opening a new tab)
  var moObserver = new MutationObserver(mountAll);
  moObserver.observe(document.body, { childList: true, subtree: true });
})();
