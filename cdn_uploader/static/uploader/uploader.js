/*
 * Uploader UI. Runs inside an iframe opened by sdk/unit-uploader.js.
 *
 * postMessage protocol (all messages carry source: "unit-uploader" from here,
 * source: "unit-uploader-host" from the parent):
 *   -> ready                     iframe loaded, waiting for a token
 *   <- init {token, options}     token minted by the parent app's backend
 *   -> uploaded {file}           one file stored
 *   -> complete {files}          user pressed "Done"
 *   -> cancel                    user closed the dialog
 *   -> error {code, message}     token rejected etc.
 *
 * Results are only ever posted to the origin that the server confirmed is
 * bound to the token. User-controlled strings are rendered with textContent.
 */
(function () {
  'use strict';

  var GUEST = 'unit-uploader';
  var HOST = 'unit-uploader-host';
  var API = new URL('../api/v1/', document.baseURI).href;
  var CONCURRENCY = 2;
  var PREVIEWABLE = { jpg: 1, jpeg: 1, png: 1, gif: 1, webp: 1 };

  var I18N = {
    ru: {
      title: 'Загрузка файлов', close: 'Закрыть', connecting: 'Подключение…',
      dropTitle: 'Перетащите файлы сюда', or: 'или', browse: 'выберите на устройстве',
      cancel: 'Отмена', done: 'Готово', remove: 'Удалить',
      hint: 'До {size} на файл. Можно добавить ещё: {left}.',
      groups: { image: 'Изображения', document: 'Документы', archive: 'Архивы' },
      queued: 'В очереди', uploading: 'Загрузка {p}%', checking: 'Проверка…', uploaded: 'Загружено',
      summary: 'Загружено {done} из {total}', tooMany: 'Достигнут лимит файлов для этой загрузки',
      notEmbedded: 'Эта страница открывается только из приложения.', sessionFailed: 'Не удалось начать загрузку',
      errors: {
        type_not_allowed: 'Этот тип файлов не разрешён',
        file_too_large: 'Файл слишком большой',
        empty_file: 'Файл пустой',
        content_mismatch: 'Содержимое не соответствует расширению файла',
        active_content: 'Файл содержит скрипты, макросы или другое активное содержимое',
        invalid_image: 'Изображение повреждено',
        image_too_large: 'Слишком большое разрешение изображения',
        invalid_pdf: 'PDF повреждён или не может быть проверен',
        invalid_archive: 'Архив повреждён',
        invalid_text: 'Текст должен быть в кодировке UTF-8',
        unsafe_path: 'Архив содержит небезопасные пути или ссылки',
        encrypted: 'Файлы с паролем не принимаются',
        archive_too_many_entries: 'В архиве слишком много файлов',
        archive_nesting: 'Слишком много вложенных архивов',
        too_large_uncompressed: 'Архив распаковывается в слишком большой объём',
        malware_detected: 'Обнаружено вредоносное ПО',
        file_limit_reached: 'Достигнут лимит файлов',
        rate_limited: 'Слишком много запросов, попробуйте позже',
        invalid_token: 'Сессия загрузки недействительна',
        token_expired: 'Сессия загрузки истекла, откройте окно заново',
        origin_not_allowed: 'Загрузка с этого сайта не разрешена',
        origin_mismatch: 'Загрузка с этого сайта не разрешена',
        antivirus_unavailable: 'Проверка временно недоступна, попробуйте позже',
        network: 'Ошибка сети',
        unknown: 'Не удалось загрузить файл'
      }
    },
    uk: {
      title: 'Завантаження файлів', close: 'Закрити', connecting: 'Підключення…',
      dropTitle: 'Перетягніть файли сюди', or: 'або', browse: 'оберіть на пристрої',
      cancel: 'Скасувати', done: 'Готово', remove: 'Видалити',
      hint: 'До {size} на файл. Можна додати ще: {left}.',
      groups: { image: 'Зображення', document: 'Документи', archive: 'Архіви' },
      queued: 'У черзі', uploading: 'Завантаження {p}%', checking: 'Перевірка…', uploaded: 'Завантажено',
      summary: 'Завантажено {done} з {total}', tooMany: 'Досягнуто ліміт файлів для цього завантаження',
      notEmbedded: 'Ця сторінка відкривається лише із застосунку.', sessionFailed: 'Не вдалося почати завантаження',
      errors: {
        type_not_allowed: 'Цей тип файлів не дозволено',
        file_too_large: 'Файл завеликий',
        empty_file: 'Файл порожній',
        content_mismatch: 'Вміст не відповідає розширенню файлу',
        active_content: 'Файл містить скрипти, макроси або інший активний вміст',
        invalid_image: 'Зображення пошкоджене',
        image_too_large: 'Завелика роздільна здатність зображення',
        invalid_pdf: 'PDF пошкоджений або не може бути перевірений',
        invalid_archive: 'Архів пошкоджений',
        invalid_text: 'Текст має бути в кодуванні UTF-8',
        unsafe_path: 'Архів містить небезпечні шляхи або посилання',
        encrypted: 'Файли з паролем не приймаються',
        archive_too_many_entries: 'В архіві забагато файлів',
        archive_nesting: 'Забагато вкладених архівів',
        too_large_uncompressed: 'Архів розпаковується у завеликий обсяг',
        malware_detected: 'Виявлено шкідливе ПЗ',
        file_limit_reached: 'Досягнуто ліміт файлів',
        rate_limited: 'Забагато запитів, спробуйте пізніше',
        invalid_token: 'Сесія завантаження недійсна',
        token_expired: 'Сесія завантаження закінчилась, відкрийте вікно знову',
        origin_not_allowed: 'Завантаження з цього сайту не дозволено',
        origin_mismatch: 'Завантаження з цього сайту не дозволено',
        antivirus_unavailable: 'Перевірка тимчасово недоступна, спробуйте пізніше',
        network: 'Помилка мережі',
        unknown: 'Не вдалося завантажити файл'
      }
    },
    en: {
      title: 'Upload files', close: 'Close', connecting: 'Connecting…',
      dropTitle: 'Drag files here', or: 'or', browse: 'choose from your device',
      cancel: 'Cancel', done: 'Done', remove: 'Remove',
      hint: 'Up to {size} per file. Files left: {left}.',
      groups: { image: 'Images', document: 'Documents', archive: 'Archives' },
      queued: 'Queued', uploading: 'Uploading {p}%', checking: 'Checking…', uploaded: 'Uploaded',
      summary: '{done} of {total} uploaded', tooMany: 'File limit reached for this upload',
      notEmbedded: 'This page can only be opened from the application.', sessionFailed: 'Could not start the upload',
      errors: {
        type_not_allowed: 'This file type is not allowed',
        file_too_large: 'File is too large',
        empty_file: 'File is empty',
        content_mismatch: 'File content does not match its extension',
        active_content: 'File contains scripts, macros or other active content',
        invalid_image: 'Image is corrupt',
        image_too_large: 'Image resolution is too large',
        invalid_pdf: 'PDF is corrupt or cannot be checked',
        invalid_archive: 'Archive is corrupt',
        invalid_text: 'Text must be UTF-8 encoded',
        unsafe_path: 'Archive contains unsafe paths or links',
        encrypted: 'Password-protected files are not accepted',
        archive_too_many_entries: 'Archive has too many files',
        archive_nesting: 'Too many nested archives',
        too_large_uncompressed: 'Archive expands to too much data',
        malware_detected: 'Malware detected',
        file_limit_reached: 'File limit reached',
        rate_limited: 'Too many requests, try again later',
        invalid_token: 'Upload session is invalid',
        token_expired: 'Upload session expired, please reopen the dialog',
        origin_not_allowed: 'Uploads from this site are not allowed',
        origin_mismatch: 'Uploads from this site are not allowed',
        antivirus_unavailable: 'Scanning is temporarily unavailable, try again later',
        network: 'Network error',
        unknown: 'Upload failed'
      }
    }
  };

  var t = I18N.ru;
  var $ = function (id) { return document.getElementById(id); };
  var els = {
    title: $('title'), state: $('state'), work: $('work'), drop: $('drop'), picker: $('picker'),
    hint: $('hint'), notice: $('notice'), files: $('files'), summary: $('summary'),
    cancel: $('cancel'), done: $('done'), close: $('close')
  };

  var st = {
    token: null,
    replyOrigin: null,   // origin of the init message; only gets data-free messages
    origin: null,        // origin confirmed by the server for this token
    session: null,
    allowed: {},         // ext -> category
    limit: 0,
    typesText: '',
    items: [],
    nextId: 1,
    finished: false
  };

  function fmt(template, values) {
    return template.replace(/\{(\w+)\}/g, function (_, key) { return values[key]; });
  }

  function formatSize(bytes) {
    var units = st.lang === 'en' ? ['B', 'KB', 'MB', 'GB'] : ['Б', 'КБ', 'МБ', 'ГБ'];
    var i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return (i === 0 ? bytes : bytes.toFixed(bytes < 10 ? 1 : 0)) + ' ' + units[i];
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

  function applyLang(lang) {
    st.lang = I18N[lang] ? lang : 'ru';
    t = I18N[st.lang];
    document.documentElement.lang = st.lang;
    var nodes = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) nodes[i].textContent = t[nodes[i].getAttribute('data-i18n')];
    nodes = document.querySelectorAll('[data-i18n-label]');
    for (i = 0; i < nodes.length; i++) nodes[i].setAttribute('aria-label', t[nodes[i].getAttribute('data-i18n-label')]);
    document.title = t.title;
  }

  function fatal(text) {
    els.work.hidden = true;
    els.state.hidden = false;
    els.state.textContent = text;
    els.state.classList.add('error');
    els.done.disabled = true;
  }

  function errorText(code) {
    return t.errors[code] || t.errors.unknown;
  }

  // ---- connection -------------------------------------------------------

  function onMessage(ev) {
    if (ev.source !== window.parent) return;
    var msg = ev.data;
    if (!msg || typeof msg !== 'object' || msg.source !== HOST) return;
    if (msg.type === 'init' && !st.token && typeof msg.token === 'string') {
      start(msg.token, ev.origin, msg.options && typeof msg.options === 'object' ? msg.options : {});
    }
  }

  function start(token, origin, options) {
    st.token = token;
    st.replyOrigin = origin;
    applyLang(options.lang);
    if (typeof options.title === 'string' && options.title) {
      els.title.textContent = options.title.slice(0, 80);
    }

    fetch(API + 'session', {
      headers: { Authorization: 'Bearer ' + token },
      credentials: 'omit',
      cache: 'no-store'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        if (!res.ok) throw body;
        return body;
      });
    }).then(function (session) {
      if (session.origin !== origin) throw { error: 'origin_mismatch' };
      st.origin = origin;
      st.session = session;
      setup(session, options);
    }).catch(function (err) {
      var code = (err && err.error) || 'network';
      fatal(t.sessionFailed + ': ' + errorText(code));
      send('error', { code: code, message: errorText(code) });
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
    st.limit = session.remaining;
    if (options.multiple === false) st.limit = Math.min(st.limit, 1);
    if (typeof options.maxFiles === 'number' && options.maxFiles > 0) {
      st.limit = Math.min(st.limit, Math.floor(options.maxFiles));
    }
    els.picker.multiple = st.limit > 1;
    els.picker.accept = Object.keys(st.allowed).map(function (e) { return '.' + e; }).join(',');

    var groups = {};
    Object.keys(st.allowed).forEach(function (ext) {
      (groups[st.allowed[ext]] = groups[st.allowed[ext]] || []).push(ext.toUpperCase());
    });
    st.typesText = Object.keys(groups).map(function (g) {
      return t.groups[g] + ': ' + groups[g].join(', ');
    }).join('. ');

    els.state.hidden = true;
    els.work.hidden = false;
    bindUI();
    refresh();
    els.drop.focus();
  }

  // ---- files ------------------------------------------------------------

  function activeCount() {
    return st.items.filter(function (it) { return it.status !== 'error'; }).length;
  }

  function addFiles(list) {
    if (!st.session || st.finished) return;
    els.notice.hidden = true;
    for (var i = 0; i < list.length; i++) {
      var file = list[i];
      if (activeCount() >= st.limit) {
        els.notice.textContent = t.tooMany;
        els.notice.hidden = false;
        break;
      }
      var ext = extOf(file.name);
      var item = { id: st.nextId++, file: file, name: file.name, ext: ext, status: 'queued', progress: 0 };
      if (!st.allowed[ext]) fail(item, 'type_not_allowed');
      else if (file.size > st.session.max_file_size) fail(item, 'file_too_large');
      else if (file.size === 0) fail(item, 'empty_file');
      st.items.push(item);
      render(item);
    }
    pump();
    refresh();
  }

  function fail(item, code, detail) {
    item.status = 'error';
    item.error = errorText(code);
    item.detail = detail || '';
  }

  function pump() {
    var running = st.items.filter(function (it) { return it.status === 'uploading'; }).length;
    for (var i = 0; i < st.items.length && running < CONCURRENCY; i++) {
      if (st.items[i].status === 'queued') { upload(st.items[i]); running++; }
    }
  }

  function upload(item) {
    var xhr = new XMLHttpRequest();
    item.xhr = xhr;
    item.status = 'uploading';
    xhr.open('POST', API + 'files');
    xhr.setRequestHeader('Authorization', 'Bearer ' + st.token);
    xhr.setRequestHeader('X-File-Name', encodeURIComponent(item.name));
    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) { item.progress = e.loaded / e.total; update(item); }
    };
    xhr.onload = function () {
      var body = null;
      try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
      if (xhr.status === 201 && body && typeof body.url === 'string') {
        item.status = 'done';
        item.result = publicFile(body);
        send('uploaded', { file: item.result });
      } else {
        fail(item, body && body.error ? body.error : 'unknown', body && body.message);
      }
      finishRequest(item);
    };
    xhr.onerror = function () { fail(item, 'network'); finishRequest(item); };
    xhr.onabort = function () { item.status = 'removed'; finishRequest(item); };
    xhr.send(item.file);
    update(item);
  }

  function finishRequest(item) {
    item.xhr = null;
    update(item);
    pump();
    refresh();
  }

  function publicFile(body) {
    return {
      url: String(body.url), name: String(body.name || ''), size: Number(body.size) || 0,
      mime: String(body.mime || ''), ext: String(body.ext || ''),
      category: String(body.category || ''), hash: String(body.hash || '')
    };
  }

  function removeItem(item) {
    if (item.xhr) item.xhr.abort();
    item.status = 'removed';
    if (item.preview) URL.revokeObjectURL(item.preview);
    if (item.el) item.el.remove();
    st.items = st.items.filter(function (it) { return it !== item; });
    els.notice.hidden = true;
    pump();
    refresh();
  }

  // ---- rendering --------------------------------------------------------

  function node(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  }

  function render(item) {
    var li = node('li', 'file');
    var thumb = node('div', 'thumb');
    if (PREVIEWABLE[item.ext] && item.status !== 'error') {
      var img = node('img');
      img.alt = '';
      item.preview = URL.createObjectURL(item.file);
      img.src = item.preview;
      img.onerror = function () { img.remove(); thumb.textContent = item.ext; };
      thumb.appendChild(img);
    } else {
      thumb.textContent = item.ext.slice(0, 4) || '?';
    }
    var meta = node('div', 'meta');
    var name = node('div', 'name', item.name);
    name.title = item.name;
    var sub = node('div', 'sub');
    sub.appendChild(node('span', 'size', formatSize(item.file.size)));
    sub.appendChild(document.createTextNode(' · '));
    var status = node('span', 'status');
    sub.appendChild(status);
    var bar = node('div', 'bar');
    var fill = node('span');
    bar.appendChild(fill);
    meta.appendChild(name);
    meta.appendChild(sub);
    meta.appendChild(bar);

    var remove = node('button', 'icon-btn remove');
    remove.type = 'button';
    remove.setAttribute('aria-label', t.remove + ': ' + item.name);
    remove.appendChild(els.close.querySelector('svg').cloneNode(true));
    remove.addEventListener('click', function () { removeItem(item); });

    li.appendChild(thumb);
    li.appendChild(meta);
    li.appendChild(remove);
    els.files.appendChild(li);
    item.el = li;
    item.statusEl = status;
    item.fillEl = fill;
    update(item);
  }

  function update(item) {
    if (!item.el) return;
    item.el.setAttribute('data-state', item.status);
    var text;
    if (item.status === 'queued') text = t.queued;
    else if (item.status === 'uploading') {
      text = item.progress >= 1 ? t.checking : fmt(t.uploading, { p: Math.floor(item.progress * 100) });
    } else if (item.status === 'done') text = t.uploaded;
    else if (item.status === 'error') text = item.error;
    else text = '';
    item.statusEl.textContent = text;
    item.statusEl.title = item.detail || '';
    item.fillEl.style.width = Math.round(item.progress * 100) + '%';
  }

  function refresh() {
    var done = st.items.filter(function (it) { return it.status === 'done'; }).length;
    var busy = st.items.some(function (it) { return it.status === 'queued' || it.status === 'uploading'; });
    var total = activeCount();
    els.summary.textContent = total ? fmt(t.summary, { done: done, total: total }) : '';
    els.done.disabled = busy || done === 0;
    var left = Math.max(st.limit - activeCount(), 0);
    var full = left === 0;
    if (st.session) {
      els.hint.textContent = fmt(t.hint, { size: formatSize(st.session.max_file_size), left: left }) +
        ' ' + st.typesText;
    }
    els.drop.classList.toggle('disabled', full);
    els.drop.setAttribute('aria-disabled', full ? 'true' : 'false');
  }

  // ---- UI events --------------------------------------------------------

  function cancel() {
    if (st.finished) return;
    st.finished = true;
    st.items.forEach(function (it) { if (it.xhr) it.xhr.abort(); });
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

  function openPicker() {
    if (activeCount() < st.limit) els.picker.click();
  }

  function bindUI() {
    els.drop.addEventListener('click', openPicker);
    els.drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); }
    });
    els.picker.addEventListener('change', function () {
      addFiles(els.picker.files);
      els.picker.value = '';
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
      if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });
    document.addEventListener('paste', function (e) {
      if (e.clipboardData && e.clipboardData.files && e.clipboardData.files.length) {
        addFiles(e.clipboardData.files);
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

  if (window.parent === window) {
    fatal(t.notEmbedded);
    return;
  }
  window.addEventListener('message', onMessage);
  // Carries nothing but "I'm loaded"; the parent answers with the token.
  window.parent.postMessage({ source: GUEST, type: 'ready' }, '*');
})();
