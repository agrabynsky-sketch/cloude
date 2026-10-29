/*
 * UnitUploader SDK: opens the cdn.unit.travel uploader in a modal iframe,
 * and opens protected ("private") files for the app.
 *
 *   const files = await UnitUploader.open({
 *     uploaderUrl: 'https://cdn.unit.travel/uploader/',
 *     getToken: () => fetch('/api/cdn-upload-token', { method: 'POST' })
 *                       .then(r => r.json()).then(j => j.token),
 *     lang: 'uk',                       // default: <html lang> of the page, then the browser, then 'en'
 *     languages: ['en', 'uk', 'ru'],    // optional: language menu inside the dialog
 *     onLanguageChange: (lang) => {},   // optional: the user picked a language there
 *     translations: { pl: {...} },      // optional: extra languages / overridden strings
 *     title: { en: 'Passport', uk: 'Паспорт' },   // optional, string or per language
 *     types: ['image', 'document'],     // optional, narrows what the token allows
 *     maxFiles: 10,                     // optional
 *     onFileUploaded: (file) => {},     // optional, fires per stored file
 *   });
 *   // files: [{ key, access, url, expires_at?, receipt, name, size, mime, ext, category, hash,
 *   //           folder?, width?, height?, variants?: { orig: {key, url, receipt, width, height}, small: {...} } }]
 *   //        or null if cancelled
 *
 *   UnitUploader.setLang('ru');          // follow the app's language switch while open
 *
 *   // "private" files open only with a read token minted by the app backend:
 *   const blobUrl = await UnitUploader.fileUrl(file.url, { getToken });  // for <img>, <iframe>
 *   await UnitUploader.download(file.url, { getToken, filename: 'passport.pdf' });
 *
 * Tokens are minted by your backend (it holds the shared secret) and are
 * only handed over via postMessage or a request header, never put in a URL.
 * With getToken the uploader can also ask for a fresh token when a long
 * batch (many files, big videos) outlives the first one.
 */
(function (root) {
  'use strict';

  var HOST = 'unit-uploader-host';
  var GUEST = 'unit-uploader';
  var DIALOG_LABEL = { en: 'Upload files', uk: 'Завантаження файлів', ru: 'Загрузка файлов' };
  var ACCESS = { 'public': 1, signed: 1, 'private': 1 };
  var KEY_RE = /^(f|[sp]\/[a-z0-9][a-z0-9_-]{0,31})\/([a-z0-9][a-z0-9_-]{0,63}\/){1,10}[0-9a-f]{64}\.[a-z0-9.]{2,6}$/;
  var SIZE_RE = /^[a-z0-9][a-z0-9_]{0,31}$/;
  var current = null;

  function isLocalHost(hostname) {
    return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
  }

  function isSafeUrl(url) {
    return url.protocol === 'https:' || (url.protocol === 'http:' && isLocalHost(url.hostname));
  }

  function css(el, styles) {
    for (var k in styles) if (Object.prototype.hasOwnProperty.call(styles, k)) el.style[k] = styles[k];
    return el;
  }

  function detectLang() {
    return document.documentElement.lang || (navigator.languages && navigator.languages[0]) ||
      navigator.language || 'en';
  }

  function tokenSource(options) {
    if (typeof options.getToken === 'function') return options.getToken;
    if (typeof options.token === 'string') return function () { return options.token; };
    return null;
  }

  function safeRef(r, cdnOrigin) {
    if (!r || typeof r !== 'object' || typeof r.url !== 'string') return null;
    var url;
    try { url = new URL(r.url); } catch (e) { return null; }
    if (!isSafeUrl(url) || (cdnOrigin && url.origin !== cdnOrigin)) return null;
    var out = {
      key: KEY_RE.test(r.key) ? r.key : '',
      url: url.href,
      receipt: /^[A-Za-z0-9_-]{20,100}$/.test(r.receipt) ? r.receipt : ''
    };
    if (typeof r.expires_at === 'number') out.expires_at = r.expires_at;
    if (typeof r.width === 'number' && typeof r.height === 'number') { out.width = r.width; out.height = r.height; }
    return out;
  }

  // Only well-formed results from our own iframe reach the app.
  function safeFile(f, cdnOrigin) {
    var ref = safeRef(f, cdnOrigin);
    if (!ref) return null;
    var out = {
      key: ref.key,
      access: ACCESS[f.access] ? f.access : 'public',
      url: ref.url,
      receipt: ref.receipt,
      name: String(f.name || ''),
      size: Number(f.size) || 0,
      mime: String(f.mime || ''),
      ext: String(f.ext || ''),
      category: String(f.category || ''),
      hash: /^[0-9a-f]{64}$/.test(f.hash) ? f.hash : ''
    };
    if (ref.expires_at) out.expires_at = ref.expires_at;
    if (ref.width) { out.width = ref.width; out.height = ref.height; }
    if (typeof f.folder === 'string') out.folder = f.folder;
    if (f.variants && typeof f.variants === 'object') {
      out.variants = {};
      Object.keys(f.variants).forEach(function (name) {
        var v = SIZE_RE.test(name) && safeRef(f.variants[name], cdnOrigin);
        if (v) out.variants[name] = v;
      });
    }
    return out;
  }

  function open(options) {
    options = options || {};
    if (current) return Promise.reject(new Error('UnitUploader is already open'));
    var frameUrl;
    try { frameUrl = new URL(options.uploaderUrl, window.location.href); } catch (e) {
      return Promise.reject(new Error('UnitUploader: invalid uploaderUrl'));
    }
    if (!isSafeUrl(frameUrl)) return Promise.reject(new Error('UnitUploader: uploaderUrl must use https'));
    var getToken = tokenSource(options);
    if (!getToken) return Promise.reject(new Error('UnitUploader: pass token or getToken'));

    var origin = frameUrl.origin;
    var cdnOrigin = options.cdnBaseUrl ? new URL(options.cdnBaseUrl, window.location.href).origin : '';
    var lang = String(options.lang || detectLang());

    return new Promise(function (resolve, reject) {
      var small = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
      var overlay = css(document.createElement('div'), {
        position: 'fixed', inset: '0', zIndex: '2147483000', display: 'flex',
        alignItems: 'center', justifyContent: 'center', background: 'rgba(15, 18, 25, 0.55)'
      });
      var dialog = css(document.createElement('div'), {
        width: small ? '100%' : 'min(640px, calc(100vw - 32px))',
        height: small ? '100%' : 'min(600px, calc(100vh - 32px))',
        borderRadius: small ? '0' : '14px', overflow: 'hidden',
        boxShadow: '0 20px 60px rgba(0, 0, 0, 0.3)', background: '#fff'
      });
      var label = DIALOG_LABEL[lang.toLowerCase().split('-')[0]] || DIALOG_LABEL.en;
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-label', label);

      var frame = css(document.createElement('iframe'), {
        width: '100%', height: '100%', border: '0', display: 'block'
      });
      frame.title = label;
      frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
      frame.setAttribute('referrerpolicy', 'no-referrer');
      frame.src = frameUrl.href;

      dialog.appendChild(frame);
      overlay.appendChild(dialog);

      var previousFocus = document.activeElement;
      var previousOverflow = document.documentElement.style.overflow;
      var initSent = false;

      function post(msg) {
        msg.source = HOST;
        frame.contentWindow.postMessage(msg, origin);
      }

      function cleanup() {
        window.removeEventListener('message', onMessage);
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        document.documentElement.style.overflow = previousOverflow;
        current = null;
        if (previousFocus && previousFocus.focus) previousFocus.focus();
      }

      function call(fn, arg) {
        if (typeof fn !== 'function') return;
        try { fn(arg); } catch (e) { setTimeout(function () { throw e; }); }
      }

      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); cleanup(); resolve(null); }
      }

      function onMessage(ev) {
        // Only the iframe we created, served from the uploader origin.
        if (ev.source !== frame.contentWindow || ev.origin !== origin) return;
        var msg = ev.data;
        if (!msg || typeof msg !== 'object' || msg.source !== GUEST) return;

        if (msg.type === 'ready' && !initSent) {
          initSent = true;
          Promise.resolve().then(getToken).then(function (token) {
            if (typeof token !== 'string' || !token) throw new Error('UnitUploader: empty token');
            post({
              type: 'init',
              token: token,
              options: {
                lang: lang, languages: options.languages, translations: options.translations,
                title: options.title, types: options.types, maxFiles: options.maxFiles,
                multiple: options.multiple, refreshable: typeof options.getToken === 'function'
              }
            });
          }).catch(function (err) { cleanup(); reject(err); });
        } else if (msg.type === 'token-request' && initSent && typeof options.getToken === 'function') {
          Promise.resolve().then(options.getToken).then(function (token) {
            if (typeof token !== 'string' || !token) throw new Error('empty token');
            post({ type: 'token', token: token });
          }).catch(function () { post({ type: 'token-error' }); });
        } else if (msg.type === 'language' && typeof msg.lang === 'string') {
          call(options.onLanguageChange, msg.lang);
        } else if (msg.type === 'uploaded') {
          var file = safeFile(msg.file, cdnOrigin);
          if (file) call(options.onFileUploaded, file);
        } else if (msg.type === 'complete') {
          var files = (Array.isArray(msg.files) ? msg.files : [])
            .map(function (f) { return safeFile(f, cdnOrigin); })
            .filter(Boolean);
          cleanup();
          resolve(files);
        } else if (msg.type === 'cancel') {
          cleanup();
          resolve(null);
        } else if (msg.type === 'error') {
          call(options.onError, { code: String(msg.code || ''), message: String(msg.message || '') });
        }
      }

      current = {
        close: function () { cleanup(); resolve(null); },
        setLang: function (l) { if (initSent) post({ type: 'set-lang', lang: String(l) }); }
      };
      window.addEventListener('message', onMessage);
      document.addEventListener('keydown', onKey, true);
      document.documentElement.style.overflow = 'hidden';
      document.body.appendChild(overlay);
      frame.focus();
    });
  }

  function close() {
    if (current) current.close();
  }

  function setLang(lang) {
    if (current) current.setLang(lang);
  }

  // ---- protected files --------------------------------------------------

  // Fetch a "private" (or "signed") file with a read token in the
  // Authorization header. Resolves to a Blob; rejects with err.code set.
  function fetchFile(url, options) {
    options = options || {};
    var getToken = tokenSource(options);
    var target;
    try { target = new URL(url, window.location.href); } catch (e) {
      return Promise.reject(new Error('UnitUploader: invalid file url'));
    }
    if (!isSafeUrl(target)) return Promise.reject(new Error('UnitUploader: file url must use https'));
    return Promise.resolve().then(getToken || function () { return ''; }).then(function (token) {
      var headers = {};
      if (token) headers.Authorization = 'Bearer ' + token;
      return fetch(target.href, { headers: headers, credentials: 'omit', mode: 'cors', cache: 'no-store' });
    }).then(function (res) {
      if (res.ok) return res.blob();
      return res.json().catch(function () { return {}; }).then(function (body) {
        var err = new Error(body.message || 'Could not open the file (' + res.status + ')');
        err.code = body.error || 'http_' + res.status;
        err.status = res.status;
        throw err;
      });
    });
  }

  // Object URL for <img>, <video>, <iframe>; call URL.revokeObjectURL when done.
  function fileUrl(url, options) {
    return fetchFile(url, options).then(function (blob) { return URL.createObjectURL(blob); });
  }

  function download(url, options) {
    options = options || {};
    return fetchFile(url, options).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = String(options.filename || url.split('/').pop().split('?')[0]);
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 30000);
    });
  }

  var api = {
    open: open, close: close, setLang: setLang,
    fetchFile: fetchFile, fileUrl: fileUrl, download: download,
    version: '1.2.0'
  };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.UnitUploader = api;
})(typeof window !== 'undefined' ? window : this);
