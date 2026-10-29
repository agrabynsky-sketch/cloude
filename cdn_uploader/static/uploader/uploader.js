/*
 * Uploader UI. Runs inside an iframe opened by sdk/unit-uploader.js.
 *
 * postMessage protocol (all messages carry source: "unit-uploader" from here,
 * source: "unit-uploader-host" from the parent):
 *   -> ready                     iframe loaded, waiting for a token
 *   <- init {token, options}     token minted by the parent app's backend
 *   -> token-request             token expired mid-upload, need a fresh one
 *   <- token {token}             fresh token (or token-error)
 *   -> uploaded {file}           one file stored
 *   -> complete {files}          user pressed "Done"
 *   -> cancel                    user closed the dialog
 *   -> error {code, message}     token rejected etc.
 *   <- set-lang {lang}           the app switched its language
 *   -> language {lang}           the user switched language in the dialog
 *
 * Results are only ever posted to the origin that the server confirmed is
 * bound to the token. User-controlled strings are rendered with textContent.
 */
(function () {
  'use strict';

  var GUEST = 'unit-uploader';
  var HOST = 'unit-uploader-host';
  var API = new URL('../api/v1/', document.baseURI).href;
  var CONCURRENCY = 3;
  var MAX_ATTEMPTS = 4;        // first try + 3 automatic retries
  var POLL_MS = 1500;
  var MAX_SCAN = 5000;         // files read from dropped folders
  var PREVIEW_IMAGE = { jpg: 1, jpeg: 1, png: 1, gif: 1, webp: 1 };
  var PREVIEW_VIDEO = { mp4: 1, m4v: 1, mov: 1, webm: 1 };
  var RETRYABLE = {
    network: 1, rate_limited: 1, busy: 1, antivirus_unavailable: 1,
    internal_error: 1, client_disconnected: 1, server_error: 1
  };
  var ACTIVE = { queued: 1, uploading: 1, processing: 1, waiting: 1 };
  var JUNK = /^(\.|__macosx$|thumbs\.db$|desktop\.ini$)/i;

  var BASE = window.UNIT_UPLOADER_I18N || {};
  var LOCALES = {};           // lang -> strings, English filled in for anything missing
  var t = BASE.en;
  var $ = function (id) { return document.getElementById(id); };
  var els = {
    title: $('title'), state: $('state'), work: $('work'), drop: $('drop'), scroller: $('scroller'),
    picker: $('picker'), folderPicker: $('folderPicker'), pickFiles: $('pickFiles'),
    pickFolder: $('pickFolder'), folderChoice: $('folderChoice'), hint: $('hint'), notice: $('notice'),
    overview: $('overview'), overall: $('overall'), rate: $('rate'), totalBar: $('totalBar'),
    totalFill: $('totalFill'), retryAll: $('retryAll'), clearErrors: $('clearErrors'),
    files: $('files'), summary: $('summary'), cancel: $('cancel'), done: $('done'), close: $('close'),
    lang: $('lang'), accessNote: $('accessNote'), accessText: $('accessText')
  };

  var st = {
    token: null,
    replyOrigin: null,   // origin of the init message; only gets data-free messages
    origin: null,        // origin confirmed by the server for this token
    session: null,
    lang: 'en',
    title: null,         // custom title: string, or {lang: string}
    notice: [],          // [[key, values], ...] so it can be re-rendered in another language
    fatal: null,
    groups: {},          // category -> ["JPG", ...]
    refreshable: false,  // parent can mint a new token (SDK got getToken)
    refreshing: false,
    allowed: {},         // ext -> category
    maxSizes: {},        // category -> bytes
    limit: 0,
    items: [],
    seen: {},            // duplicate detection
    nextId: 1,
    finished: false,
    sentBytes: 0,        // for the speed estimate
    speed: 0,
    sample: null,
    rafPending: false
  };

  // ---- helpers ----------------------------------------------------------

  function fmt(template, values) {
    return template.replace(/\{(\w+)\}/g, function (_, key) { return values[key]; });
  }

  function formatSize(bytes) {
    var units = t.units;
    var i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    var digits = i > 0 && bytes < 10 ? 1 : 0;
    var n;
    try {
      n = new Intl.NumberFormat(st.lang, { maximumFractionDigits: digits }).format(bytes);
    } catch (e) {
      n = bytes.toFixed(digits);
    }
    return n + ' ' + units[i];
  }

  function formatEta(seconds) {
    seconds = Math.max(1, Math.round(seconds));
    if (seconds < 60) return seconds + ' ' + t.sec;
    var minutes = Math.round(seconds / 60);
    if (minutes < 60) return minutes + ' ' + t.min;
    return Math.floor(minutes / 60) + ' ' + t.hour + ' ' + (minutes % 60) + ' ' + t.min;
  }

  function extOf(name) {
    var lower = String(name).toLowerCase();
    if (/\.tar\.gz$/.test(lower)) return 'tar.gz';
    var m = /\.([a-z0-9]{1,8})$/.exec(lower);
    return m ? m[1] : '';
  }

  function send(type, payload) {
    var target = st.origin || st.replyOrigin;
    if (!target) return;
    var msg = { source: GUEST, type: type };
    for (var k in payload) if (Object.prototype.hasOwnProperty.call(payload, k)) msg[k] = payload[k];
    window.parent.postMessage(msg, target);
  }

  // Strings from `over` that match the shape of `base` (English); nothing else.
  function merge(base, over) {
    var out = {};
    Object.keys(base).forEach(function (k) {
      var b = base[k], o = over && Object.prototype.hasOwnProperty.call(over, k) ? over[k] : undefined;
      if (typeof b === 'string') {
        out[k] = typeof o === 'string' && o.length <= 300 ? o : b;
      } else if (Array.isArray(b)) {
        out[k] = Array.isArray(o) && o.length === b.length &&
          o.every(function (x) { return typeof x === 'string' && x.length <= 10; }) ? o.slice() : b;
      } else {
        out[k] = merge(b, o && typeof o === 'object' ? o : null);
      }
    });
    return out;
  }

  function buildLocales(extra) {
    extra = extra && typeof extra === 'object' ? extra : {};
    var langs = Object.keys(BASE);
    Object.keys(extra).forEach(function (l) {
      if (/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/.test(l) && langs.indexOf(l) === -1) langs.push(l);
    });
    langs.forEach(function (l) { LOCALES[l] = merge(merge(BASE.en, BASE[l]), extra[l]); });
  }

  // "uk-UA" -> "uk"; anything unknown -> English.
  function resolveLang(lang) {
    lang = String(lang || '').toLowerCase();
    if (LOCALES[lang]) return lang;
    var short = lang.split('-')[0];
    return LOCALES[short] ? short : 'en';
  }

  function applyLang(lang) {
    st.lang = resolveLang(lang);
    t = LOCALES[st.lang];
    document.documentElement.lang = st.lang;
    var nodes = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) nodes[i].textContent = t[nodes[i].getAttribute('data-i18n')];
    nodes = document.querySelectorAll('[data-i18n-label]');
    for (i = 0; i < nodes.length; i++) nodes[i].setAttribute('aria-label', t[nodes[i].getAttribute('data-i18n-label')]);
    var title = st.title && typeof st.title === 'object' ? (st.title[st.lang] || st.title.en) : st.title;
    els.title.textContent = typeof title === 'string' && title ? title.slice(0, 80) : t.title;
    document.title = els.title.textContent;
    els.lang.value = st.lang;
    els.lang.setAttribute('aria-label', t.language);
    if (st.session && st.session.access !== 'public') {
      els.accessText.textContent = st.session.access === 'private' ? t.accessPrivate : t.accessSigned;
    }
    if (st.fatal) renderFatal();
    renderNotice();
    st.items.forEach(update);
    refresh();
  }

  function fatal(key, code) {
    st.fatal = { key: key, code: code };
    renderFatal();
  }

  function renderFatal() {
    els.work.hidden = true;
    els.state.hidden = false;
    els.state.textContent = t[st.fatal.key] + (st.fatal.code ? ': ' + errorText(st.fatal.code) : '');
    els.state.classList.add('error');
    els.done.disabled = true;
  }

  function errorText(code) {
    return t.errors[code] || t.errors.unknown;
  }

  function api(path, token) {
    return fetch(API + path, {
      headers: { Authorization: 'Bearer ' + token },
      credentials: 'omit',
      cache: 'no-store'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body, retryAfter: Number(res.headers.get('Retry-After')) || 0 };
      });
    });
  }

  function fetchSession(token, origin) {
    return api('session', token).then(function (r) {
      if (r.status !== 200) throw r.body;
      if (r.body.origin !== origin) throw { error: 'origin_mismatch' };
      return r.body;
    });
  }

  // ---- connection -------------------------------------------------------

  function onMessage(ev) {
    if (ev.source !== window.parent) return;
    var msg = ev.data;
    if (!msg || typeof msg !== 'object' || msg.source !== HOST) return;
    if (msg.type === 'init' && !st.token && typeof msg.token === 'string') {
      start(msg.token, ev.origin, msg.options && typeof msg.options === 'object' ? msg.options : {});
    } else if (msg.type === 'set-lang' && ev.origin === (st.origin || st.replyOrigin)) {
      applyLang(msg.lang);
    } else if (msg.type === 'token' && st.refreshing && ev.origin === st.origin && typeof msg.token === 'string') {
      onFreshToken(msg.token);
    } else if (msg.type === 'token-error' && st.refreshing && ev.origin === st.origin) {
      refreshFailed();
    }
  }

  function start(token, origin, options) {
    st.token = token;
    st.replyOrigin = origin;
    st.refreshable = options.refreshable === true;
    st.title = options.title || null;
    buildLocales(options.translations);
    setupLanguageSwitcher(options.languages);
    applyLang(options.lang);
    fetchSession(token, origin).then(function (session) {
      st.origin = origin;
      st.session = session;
      setup(session, options);
    }).catch(function (err) {
      var code = (err && err.error) || 'network';
      fatal('sessionFailed', code);
      send('error', { code: code, message: errorText(code) });
    });
  }

  // Optional language menu inside the dialog (options.languages: true or ['en', 'uk']).
  function setupLanguageSwitcher(languages) {
    if (!languages) return;
    var list = Array.isArray(languages) ? languages.map(resolveLang) : Object.keys(LOCALES);
    list = list.filter(function (l, i) { return list.indexOf(l) === i; });
    if (list.length < 2) return;
    list.forEach(function (l) {
      var opt = document.createElement('option');
      opt.value = l;
      opt.textContent = LOCALES[l].langName;
      els.lang.appendChild(opt);
    });
    els.lang.hidden = false;
    els.lang.addEventListener('change', function () {
      applyLang(els.lang.value);
      send('language', { lang: st.lang });
    });
  }

  function setup(session, options) {
    // Options from the parent may only narrow what the token allows.
    var types = session.types;
    if (Array.isArray(options.types)) {
      types = types.filter(function (x) { return options.types.indexOf(x) !== -1; });
    }
    Object.keys(session.extensions).forEach(function (ext) {
      if (types.indexOf(session.extensions[ext]) !== -1) st.allowed[ext] = session.extensions[ext];
    });
    types.forEach(function (type) {
      st.maxSizes[type] = (session.max_sizes && session.max_sizes[type]) || session.max_file_size;
    });
    st.limit = session.remaining;
    if (options.multiple === false) st.limit = Math.min(st.limit, 1);
    if (typeof options.maxFiles === 'number' && options.maxFiles > 0) {
      st.limit = Math.min(st.limit, Math.floor(options.maxFiles));
    }
    els.picker.multiple = st.limit > 1;
    els.picker.accept = Object.keys(st.allowed).map(function (e) { return '.' + e; }).join(',');
    els.hintTypes = document.createElement('span');
    els.hintTypes.className = 'hint-types';
    // Folder upload makes no sense for a single file or on touch devices.
    var folders = st.limit > 1 && 'webkitdirectory' in els.folderPicker &&
      !(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    els.folderChoice.hidden = !folders;

    Object.keys(st.allowed).forEach(function (ext) {
      (st.groups[st.allowed[ext]] = st.groups[st.allowed[ext]] || []).push(ext.toUpperCase());
    });
    if (session.access && session.access !== 'public') {
      els.accessText.textContent = session.access === 'private' ? t.accessPrivate : t.accessSigned;
      els.accessNote.hidden = false;
    }

    els.state.hidden = true;
    els.work.hidden = false;
    bindUI();
    setInterval(sampleSpeed, 1000);
    refresh();
    els.pickFiles.focus();
  }

  // Token expired in the middle of a long batch: ask the parent for a new one.
  function refreshToken() {
    if (st.refreshing) return;
    if (!st.refreshable) { refreshFailed(); return; }
    st.refreshing = true;
    showNotice([['refreshing']]);
    send('token-request', {});
    st.refreshTimer = setTimeout(function () { if (st.refreshing) refreshFailed(); }, 30000);
  }

  function onFreshToken(token) {
    fetchSession(token, st.origin).then(function () {
      clearTimeout(st.refreshTimer);
      st.token = token;
      st.refreshing = false;
      st.items.forEach(function (it) { it.needsToken = false; });
      hideNotice();
      pump();
    }).catch(refreshFailed);
  }

  function refreshFailed() {
    clearTimeout(st.refreshTimer);
    st.refreshing = false;
    st.items.forEach(function (it) {
      if (it.needsToken) { it.needsToken = false; setError(it, 'token_expired'); update(it); }
    });
    hideNotice();
    scheduleRefresh();
  }

  // ---- adding files -----------------------------------------------------

  function activeCount() {
    return st.items.filter(function (it) { return it.status !== 'error'; }).length;
  }

  function showNotice(parts) {
    st.notice = parts;
    renderNotice();
  }

  function hideNotice() {
    showNotice([]);
  }

  function renderNotice() {
    els.notice.textContent = st.notice.map(function (p) { return fmt(t[p[0]], p[1] || {}); }).join(' ');
    els.notice.hidden = st.notice.length === 0;
  }

  // entries: [{file, path}]; fromFolder: silently skip junk and unsupported types.
  function addFiles(entries, fromFolder) {
    if (!st.session || st.finished || !entries.length) return;
    var skippedType = 0, skippedDup = 0, overLimit = false;
    for (var i = 0; i < entries.length; i++) {
      var file = entries[i].file;
      var path = entries[i].path || file.name;
      var ext = extOf(file.name);
      if (fromFolder && (isJunkPath(path) || !st.allowed[ext])) { skippedType++; continue; }
      var key = [path, file.size, file.lastModified].join('|');
      if (st.seen[key]) { skippedDup++; continue; }
      if (activeCount() >= st.limit) { overLimit = true; break; }
      var category = st.allowed[ext];
      var item = {
        id: st.nextId++, file: file, name: file.name, path: path, key: key, ext: ext,
        category: category, size: file.size, status: 'queued', loaded: 0, processing: 0,
        attempts: 0, tokenRetries: 0
      };
      st.seen[key] = true;
      if (!category) setError(item, 'type_not_allowed');
      else if (file.size > st.maxSizes[category]) setError(item, 'file_too_large');
      else if (file.size === 0) setError(item, 'empty_file');
      st.items.push(item);
      render(item);
    }
    var notes = [];
    if (skippedType) notes.push(['skippedType', { n: skippedType }]);
    if (skippedDup) notes.push(['skippedDup', { n: skippedDup }]);
    if (overLimit) notes.push(['limitReached', { n: st.limit }]);
    showNotice(notes);
    pump();
    scheduleRefresh();
  }

  // Hidden files/folders and OS metadata anywhere in a folder path.
  function isJunkPath(path) {
    return path.split('/').some(function (part) { return JUNK.test(part); });
  }

  function fromFileList(list) {
    var out = [];
    for (var i = 0; i < list.length; i++) {
      out.push({ file: list[i], path: list[i].webkitRelativePath || list[i].name });
    }
    return out;
  }

  // Walk dropped folders (DataTransferItem.webkitGetAsEntry).
  function collectDropped(dataTransfer) {
    var items = dataTransfer.items;
    var roots = [];
    for (var i = 0; items && i < items.length; i++) {
      var entry = items[i].webkitGetAsEntry && items[i].webkitGetAsEntry();
      if (entry) roots.push(entry);
    }
    if (!roots.some(function (e) { return e.isDirectory; })) {
      return Promise.resolve({ entries: fromFileList(dataTransfer.files), folder: false });
    }
    var out = [];
    function walk(entry) {
      if (out.length >= MAX_SCAN) return Promise.resolve();
      if (entry.isFile) {
        return new Promise(function (resolve) {
          entry.file(function (file) {
            out.push({ file: file, path: entry.fullPath.replace(/^\//, '') });
            resolve();
          }, function () { resolve(); });
        });
      }
      if (!entry.isDirectory || JUNK.test(entry.name)) return Promise.resolve();
      var reader = entry.createReader();
      var children = [];
      return new Promise(function (resolve) {
        (function readBatch() {
          // readEntries returns the directory in chunks (~100 entries per call).
          reader.readEntries(function (batch) {
            if (batch.length && children.length < MAX_SCAN) {
              children = children.concat(Array.prototype.slice.call(batch));
              readBatch();
              return;
            }
            children.reduce(function (p, c) { return p.then(function () { return walk(c); }); },
              Promise.resolve()).then(resolve);
          }, function () { resolve(); });
        })();
      });
    }
    return roots.reduce(function (p, e) { return p.then(function () { return walk(e); }); }, Promise.resolve())
      .then(function () { return { entries: out, folder: true }; });
  }

  // ---- uploading --------------------------------------------------------

  function pump() {
    if (st.refreshing || st.finished) return;
    var running = st.items.filter(function (it) { return it.status === 'uploading'; }).length;
    for (var i = 0; i < st.items.length && running < CONCURRENCY; i++) {
      if (st.items[i].status === 'queued') { upload(st.items[i]); running++; }
    }
  }

  function upload(item) {
    var xhr = new XMLHttpRequest();
    item.xhr = xhr;
    item.status = 'uploading';
    item.loaded = 0;
    item.attempts++;
    xhr.open('POST', API + 'files');
    xhr.setRequestHeader('Authorization', 'Bearer ' + st.token);
    xhr.setRequestHeader('X-File-Name', encodeURIComponent(item.name));
    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
    xhr.upload.onprogress = function (e) {
      if (!e.lengthComputable) return;
      var loaded = Math.min(e.loaded, item.size);
      if (loaded > item.loaded) st.sentBytes += loaded - item.loaded;
      item.loaded = loaded;
      update(item);
      scheduleRefresh();
    };
    xhr.onload = function () {
      item.xhr = null;
      var body = null;
      try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
      var code = body && body.error;
      if (xhr.status === 201 && body && typeof body.url === 'string') {
        finish(item, body);
      } else if (xhr.status === 202 && body && typeof body.job === 'string') {
        item.status = 'processing';
        item.jobQueued = body.status === 'queued';
        item.job = body.job;
        item.loaded = item.size;
        schedulePoll(item, POLL_MS);
      } else if (xhr.status === 401 && (code === 'token_expired' || code === 'invalid_token') &&
                 st.refreshable && item.tokenRetries < 2) {
        item.tokenRetries++;
        item.attempts--;
        item.status = 'queued';
        item.loaded = 0;
        item.needsToken = true;
        refreshToken();
      } else {
        fail(item, code || (xhr.status >= 500 ? 'server_error' : 'unknown'),
             body && body.message, Number(xhr.getResponseHeader('Retry-After')) || 0);
      }
      afterChange(item);
    };
    xhr.onerror = function () { item.xhr = null; fail(item, 'network'); afterChange(item); };
    xhr.onabort = function () { item.xhr = null; };
    xhr.send(item.file);
    update(item);
  }

  function schedulePoll(item, delay) {
    clearTimeout(item.timer);
    item.timer = setTimeout(function () { poll(item); }, delay);
  }

  function poll(item) {
    if (item.status !== 'processing' || st.finished) return;
    if (st.refreshing) { schedulePoll(item, POLL_MS); return; }
    api('jobs/' + encodeURIComponent(item.job), st.token).then(function (r) {
      if (item.status !== 'processing') return;
      item.pollErrors = 0;
      var code = r.body.error;
      if (r.status === 401 && (code === 'token_expired' || code === 'invalid_token') &&
          st.refreshable && item.tokenRetries < 2) {
        item.tokenRetries++;
        refreshToken();
        schedulePoll(item, POLL_MS);
      } else if (r.status === 429) {
        schedulePoll(item, Math.max(r.retryAfter * 1000, POLL_MS));
      } else if (r.status !== 200) {
        fail(item, code || 'server_error', r.body.message, r.retryAfter);
      } else if (r.body.status === 'done') {
        finish(item, r.body.file);
      } else if (r.body.status === 'error') {
        fail(item, r.body.error, r.body.message);
      } else {
        item.processing = Number(r.body.progress) || 0;
        item.jobQueued = r.body.status === 'queued';
        schedulePoll(item, POLL_MS);
      }
      afterChange(item);
    }).catch(function () {
      // A short network blip must not lose a video that is still processing.
      item.pollErrors = (item.pollErrors || 0) + 1;
      if (item.pollErrors > 10) { fail(item, 'network'); afterChange(item); }
      else schedulePoll(item, POLL_MS * 2);
    });
  }

  function finish(item, body) {
    item.status = 'done';
    item.loaded = item.size;
    item.processing = 1;
    item.result = publicFile(body);
    send('uploaded', { file: item.result });
  }

  function setError(item, code, detail) {
    item.status = 'error';
    item.code = code;
    item.detail = detail || '';
    item.retryable = !!RETRYABLE[code];
  }

  // Transient failures are retried automatically with backoff.
  function fail(item, code, detail, retryAfter) {
    item.loaded = 0;       // bytes of a failed attempt do not count as progress
    item.processing = 0;
    if (RETRYABLE[code] && item.attempts < MAX_ATTEMPTS && !st.finished) {
      var delay = Math.max((retryAfter || 0) * 1000, 1000 * Math.pow(2, item.attempts));
      item.status = 'waiting';
      item.retryAt = Date.now() + delay;
      item.code = code;
      clearTimeout(item.timer);
      item.timer = setTimeout(function () {
        if (item.status === 'waiting') { item.status = 'queued'; update(item); pump(); scheduleRefresh(); }
      }, delay);
      return;
    }
    setError(item, code, detail);
  }

  function afterChange(item) {
    update(item);
    pump();
    scheduleRefresh();
  }

  function retry(item) {
    if (item.status !== 'error' || !item.retryable) return;
    item.attempts = 0;
    item.tokenRetries = 0;
    item.status = 'queued';
    update(item);
  }

  function publicFile(body) {
    var file = {
      key: String(body.key || ''), access: String(body.access || 'public'), url: String(body.url),
      receipt: String(body.receipt || ''), name: String(body.name || ''), size: Number(body.size) || 0,
      mime: String(body.mime || ''), ext: String(body.ext || ''),
      category: String(body.category || ''), hash: String(body.hash || '')
    };
    if (typeof body.expires_at === 'number') file.expires_at = body.expires_at;
    if (typeof body.width === 'number') { file.width = body.width; file.height = Number(body.height) || 0; }
    if (typeof body.folder === 'string') file.folder = body.folder;
    if (body.variants && typeof body.variants === 'object') file.variants = body.variants;
    return file;
  }

  function removeItem(item) {
    if (item.xhr) item.xhr.abort();
    clearTimeout(item.timer);
    item.status = 'removed';
    if (item.preview) URL.revokeObjectURL(item.preview);
    if (previews && item.thumbEl) previews.unobserve(item.thumbEl);
    if (item.el) item.el.remove();
    delete st.seen[item.key];
    st.items = st.items.filter(function (it) { return it !== item; });
    hideNotice();
    pump();
    scheduleRefresh();
  }

  // ---- rendering --------------------------------------------------------

  function node(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  }

  function iconButton(cls, label, path) {
    var btn = node('button', 'icon-btn ' + cls);
    btn.type = 'button';
    btn.setAttribute('aria-label', label);
    btn.title = label;
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', path);
    svg.appendChild(p);
    btn.appendChild(svg);
    return btn;
  }

  // Previews are created only when a row scrolls into view: a batch of
  // hundreds of photos must not decode them all at once.
  var previews = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      previews.unobserve(entry.target);
      showPreview(entry.target.uploadItem);
    });
  }, { root: els.scroller, rootMargin: '200px' }) : null;

  function showPreview(item) {
    if (!item || item.status === 'removed' || item.status === 'error') return;
    var thumb = item.thumbEl;
    item.preview = URL.createObjectURL(item.file);
    var media;
    if (PREVIEW_IMAGE[item.ext]) {
      media = node('img');
      media.alt = '';
      media.decoding = 'async';
      media.src = item.preview;
    } else {
      media = node('video');
      media.muted = true;
      media.preload = 'metadata';
      media.playsInline = true;
      media.src = item.preview + '#t=0.5';
    }
    media.onerror = function () { media.remove(); thumb.textContent = item.ext.slice(0, 4); };
    thumb.textContent = '';
    thumb.appendChild(media);
  }

  function render(item) {
    var li = node('li', 'file');
    var thumb = node('div', 'thumb', item.ext.slice(0, 4) || '?');
    item.thumbEl = thumb;
    if ((PREVIEW_IMAGE[item.ext] || PREVIEW_VIDEO[item.ext]) && item.status !== 'error') {
      if (previews) { thumb.uploadItem = item; previews.observe(thumb); } else showPreview(item);
    }
    var meta = node('div', 'meta');
    var name = node('div', 'name', item.name);
    name.title = item.path;
    var sub = node('div', 'sub');
    sub.appendChild(node('span', 'size', formatSize(item.size)));
    sub.appendChild(document.createTextNode(' · '));
    var status = node('span', 'status');
    sub.appendChild(status);
    var bar = node('div', 'bar');
    var fill = node('span');
    bar.appendChild(fill);
    meta.appendChild(name);
    meta.appendChild(sub);
    meta.appendChild(bar);

    var retryBtn = iconButton('retry', t.retry + ': ' + item.name,
      'M20 11a8 8 0 10-2.3 5.7M20 4v7h-7');
    retryBtn.addEventListener('click', function () { retry(item); pump(); scheduleRefresh(); });
    var removeBtn = iconButton('remove', t.remove + ': ' + item.name, 'M6 6l12 12M18 6L6 18');
    removeBtn.addEventListener('click', function () { removeItem(item); });

    li.appendChild(thumb);
    li.appendChild(meta);
    li.appendChild(retryBtn);
    li.appendChild(removeBtn);
    els.files.appendChild(li);
    item.el = li;
    item.statusEl = status;
    item.fillEl = fill;
    item.retryEl = retryBtn;
    item.removeEl = removeBtn;
    update(item);
  }

  function itemProgress(item) {
    // Upload and (for videos) server-side processing each count as one half.
    var sent = item.size ? item.loaded / item.size : 1;
    return item.category === 'video' ? sent / 2 + item.processing / 2 : sent;
  }

  function update(item) {
    if (!item.el) return;
    item.el.setAttribute('data-state', item.status);
    var text = '';
    if (item.status === 'queued') text = t.queued;
    else if (item.status === 'uploading') {
      text = item.loaded >= item.size ? t.checking
        : fmt(t.uploading, { p: Math.floor(item.loaded / Math.max(item.size, 1) * 100) });
    } else if (item.status === 'processing') {
      text = item.jobQueued ? t.jobQueued : fmt(t.processing, { p: Math.floor(item.processing * 100) });
    } else if (item.status === 'waiting') {
      text = errorText(item.code) + ', ' +
        fmt(t.waiting, { s: Math.max(1, Math.ceil((item.retryAt - Date.now()) / 1000)) });
    } else if (item.status === 'done') {
      var sizes = item.result && item.result.variants ? Object.keys(item.result.variants).length : 0;
      text = sizes > 1 ? fmt(t.uploadedSizes, { n: sizes }) : t.uploaded;
    }
    else if (item.status === 'error') text = errorText(item.code);
    item.statusEl.textContent = text;
    item.statusEl.title = item.detail || '';
    item.fillEl.style.width = Math.round(itemProgress(item) * 100) + '%';
    item.retryEl.hidden = !(item.status === 'error' && item.retryable);
    item.retryEl.setAttribute('aria-label', t.retry + ': ' + item.name);
    item.retryEl.title = t.retry;
    item.removeEl.setAttribute('aria-label', t.remove + ': ' + item.name);
    item.removeEl.title = t.remove;
  }

  function sampleSpeed() {
    var now = Date.now();
    if (st.sample) {
      var rate = (st.sentBytes - st.sample.bytes) / ((now - st.sample.time) / 1000);
      st.speed = st.speed ? st.speed * 0.7 + rate * 0.3 : rate;
    }
    st.sample = { time: now, bytes: st.sentBytes };
    st.items.forEach(function (it) { if (it.status === 'waiting') update(it); });
    scheduleRefresh();
  }

  function scheduleRefresh() {
    if (st.rafPending) return;
    st.rafPending = true;
    requestAnimationFrame(function () { st.rafPending = false; refresh(); });
  }

  function refresh() {
    var total = st.items.length;
    var done = 0, errors = 0, retryable = 0, busy = false, uploading = false;
    var waiting = 0, nextRetry = Infinity;
    var bytesTotal = 0, bytesLoaded = 0, weightTotal = 0, weightDone = 0;
    st.items.forEach(function (it) {
      if (it.status === 'done') done++;
      if (it.status === 'error') { errors++; if (it.retryable) retryable++; return; }
      if (ACTIVE[it.status]) busy = true;
      if (it.status === 'uploading') uploading = true;
      if (it.status === 'waiting') { waiting++; nextRetry = Math.min(nextRetry, it.retryAt); }
      var weight = it.category === 'video' ? it.size * 2 : it.size;
      weightTotal += weight;
      weightDone += weight * itemProgress(it);
      bytesTotal += it.size;
      bytesLoaded += it.status === 'done' ? it.size : it.loaded;
    });

    els.overview.hidden = total === 0;
    els.drop.classList.toggle('compact', total > 0);
    var pct = weightTotal ? Math.floor(weightDone / weightTotal * 100) : (done ? 100 : 0);
    els.totalFill.style.width = pct + '%';
    els.totalBar.setAttribute('aria-valuenow', String(pct));
    els.overall.textContent = busy
      ? fmt(t.bytes, { loaded: formatSize(bytesLoaded), total: formatSize(bytesTotal) })
      : (done ? t.allDone : '');
    var rate = [];
    if (uploading && st.speed > 1024) {
      rate.push(fmt(t.speed, { speed: formatSize(st.speed) }));
      if (bytesTotal > bytesLoaded) rate.push(fmt(t.eta, { t: formatEta((bytesTotal - bytesLoaded) / st.speed) }));
    }
    if (waiting) {
      rate.push(fmt(t.waitingAll, { n: waiting, s: Math.max(1, Math.ceil((nextRetry - Date.now()) / 1000)) }));
    }
    els.rate.textContent = rate.join(' · ');
    els.retryAll.hidden = retryable === 0;
    els.retryAll.textContent = fmt(t.retryAll, { n: retryable });
    els.clearErrors.hidden = errors === 0;
    els.clearErrors.textContent = fmt(t.clearErrors, { n: errors });

    var summary = total ? fmt(t.overallDone, { done: done, total: total }) : '';
    if (errors) summary += ' · ' + fmt(t.overallErrors, { n: errors });
    els.summary.textContent = summary;
    els.done.disabled = busy || done === 0;
    els.done.textContent = done ? t.done + ' (' + done + ')' : t.done;

    var left = Math.max(st.limit - activeCount(), 0);
    els.drop.classList.toggle('disabled', left === 0);
    els.pickFiles.disabled = els.pickFolder.disabled = left === 0;
    if (st.session) {
      var sizes = { size: formatSize(st.session.max_file_size), left: left };
      var hint = t.hint;
      if (st.maxSizes.video && st.maxSizes.video !== st.session.max_file_size) {
        sizes.vsize = formatSize(st.maxSizes.video);
        hint = t.hintVideo;
      }
      els.hint.textContent = fmt(hint, sizes) + ' ';
      els.hintTypes.textContent = Object.keys(st.groups).map(function (g) {
        return t.groups[g] + ': ' + st.groups[g].join(', ');
      }).join('. ');
      els.hint.appendChild(els.hintTypes);
    }
  }

  // ---- UI events --------------------------------------------------------

  function cancel() {
    if (st.finished) return;
    st.finished = true;
    st.items.forEach(function (it) { if (it.xhr) it.xhr.abort(); clearTimeout(it.timer); });
    send('cancel', {});
  }

  function complete() {
    if (st.finished || els.done.disabled) return;
    st.finished = true;
    send('complete', {
      files: st.items.filter(function (it) { return it.status === 'done'; })
        .map(function (it) { return it.result; })
    });
  }

  function bindUI() {
    els.pickFiles.addEventListener('click', function (e) { e.stopPropagation(); els.picker.click(); });
    els.pickFolder.addEventListener('click', function (e) { e.stopPropagation(); els.folderPicker.click(); });
    els.drop.addEventListener('click', function (e) {
      if (e.target === els.picker || e.target === els.folderPicker) return;
      if (!els.pickFiles.disabled) els.picker.click();
    });
    els.picker.addEventListener('change', function () {
      addFiles(fromFileList(els.picker.files), false);
      els.picker.value = '';
    });
    els.folderPicker.addEventListener('change', function () {
      addFiles(fromFileList(els.folderPicker.files), true);
      els.folderPicker.value = '';
    });
    els.retryAll.addEventListener('click', function () {
      st.items.forEach(retry);
      pump();
      scheduleRefresh();
    });
    els.clearErrors.addEventListener('click', function () {
      st.items.filter(function (it) { return it.status === 'error'; }).forEach(removeItem);
    });
    els.done.addEventListener('click', complete);

    var depth = 0;
    document.addEventListener('dragenter', function (e) {
      e.preventDefault();
      depth++;
      els.drop.classList.add('over');
    });
    document.addEventListener('dragleave', function () {
      if (--depth <= 0) { depth = 0; els.drop.classList.remove('over'); }
    });
    document.addEventListener('dragover', function (e) { e.preventDefault(); });
    document.addEventListener('drop', function (e) {
      e.preventDefault();
      depth = 0;
      els.drop.classList.remove('over');
      if (!e.dataTransfer) return;
      collectDropped(e.dataTransfer).then(function (res) { addFiles(res.entries, res.folder); });
    });
    document.addEventListener('paste', function (e) {
      if (e.clipboardData && e.clipboardData.files && e.clipboardData.files.length) {
        addFiles(fromFileList(e.clipboardData.files), false);
      }
    });
  }

  // ---- boot -------------------------------------------------------------

  els.cancel.addEventListener('click', cancel);
  els.close.addEventListener('click', cancel);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cancel(); });
  // Dropping a file outside the zone must never navigate the frame to it.
  window.addEventListener('dragover', function (e) { e.preventDefault(); });
  window.addEventListener('drop', function (e) { e.preventDefault(); });

  buildLocales(null);
  applyLang(navigator.language);
  if (window.parent === window) {
    fatal('notEmbedded');
    return;
  }
  window.addEventListener('message', onMessage);
  // Carries nothing but "I'm loaded"; the parent answers with the token.
  window.parent.postMessage({ source: GUEST, type: 'ready' }, '*');
})();
