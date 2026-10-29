/*
 * UnitUploader SDK: opens the cdn.unit.travel uploader in a modal iframe.
 *
 *   const files = await UnitUploader.open({
 *     uploaderUrl: 'https://cdn.unit.travel/uploader/',
 *     getToken: () => fetch('/api/cdn-upload-token', { method: 'POST' })
 *                       .then(r => r.json()).then(j => j.token),
 *     lang: 'ru',                       // ru | uk | en
 *     types: ['image', 'document'],     // optional, narrows what the token allows
 *     maxFiles: 10,                     // optional
 *     onFileUploaded: (file) => {},     // optional, fires per stored file
 *   });
 *   // files: [{ url, name, size, mime, ext, category, hash }]  or  null if cancelled
 *
 * The token is minted by your backend (it holds the shared secret) and is
 * only handed to the iframe via postMessage, never put in a URL.
 */
(function (root) {
  'use strict';

  var HOST = 'unit-uploader-host';
  var GUEST = 'unit-uploader';
  var current = null;

  function isLocalHost(hostname) {
    return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
  }

  function css(el, styles) {
    for (var k in styles) if (Object.prototype.hasOwnProperty.call(styles, k)) el.style[k] = styles[k];
    return el;
  }

  function safeFile(f, cdnBaseUrl) {
    if (!f || typeof f !== 'object' || typeof f.url !== 'string') return null;
    var url;
    try { url = new URL(f.url); } catch (e) { return null; }
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && isLocalHost(url.hostname))) return null;
    if (cdnBaseUrl && url.href.indexOf(cdnBaseUrl.replace(/\/?$/, '/')) !== 0) return null;
    return {
      url: url.href,
      name: String(f.name || ''),
      size: Number(f.size) || 0,
      mime: String(f.mime || ''),
      ext: String(f.ext || ''),
      category: String(f.category || ''),
      hash: /^[0-9a-f]{64}$/.test(f.hash) ? f.hash : ''
    };
  }

  function open(options) {
    options = options || {};
    if (current) return Promise.reject(new Error('UnitUploader is already open'));
    var frameUrl;
    try { frameUrl = new URL(options.uploaderUrl, window.location.href); } catch (e) {
      return Promise.reject(new Error('UnitUploader: invalid uploaderUrl'));
    }
    if (frameUrl.protocol !== 'https:' && !(frameUrl.protocol === 'http:' && isLocalHost(frameUrl.hostname))) {
      return Promise.reject(new Error('UnitUploader: uploaderUrl must use https'));
    }
    var getToken = typeof options.getToken === 'function' ? options.getToken
      : typeof options.token === 'string' ? function () { return options.token; } : null;
    if (!getToken) return Promise.reject(new Error('UnitUploader: pass token or getToken'));

    var origin = frameUrl.origin;
    var cdnBaseUrl = options.cdnBaseUrl || '';

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
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-label', options.title || 'Upload files');

      var frame = css(document.createElement('iframe'), {
        width: '100%', height: '100%', border: '0', display: 'block'
      });
      frame.title = options.title || 'Upload files';
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
                lang: options.lang, title: options.title, types: options.types,
                maxFiles: options.maxFiles, multiple: options.multiple
              }
            });
          }).catch(function (err) { cleanup(); reject(err); });
        } else if (msg.type === 'uploaded') {
          var file = safeFile(msg.file, cdnBaseUrl);
          if (file) call(options.onFileUploaded, file);
        } else if (msg.type === 'complete') {
          var files = (Array.isArray(msg.files) ? msg.files : [])
            .map(function (f) { return safeFile(f, cdnBaseUrl); })
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

      current = { close: function () { cleanup(); resolve(null); } };
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

  var api = { open: open, close: close, version: '1.0.0' };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.UnitUploader = api;
})(typeof window !== 'undefined' ? window : this);
