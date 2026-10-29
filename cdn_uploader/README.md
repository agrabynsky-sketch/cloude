# cdn.unit.travel — защищённый аплоудер файлов

Модальное окно загрузки изображений, документов, архивов и видео на
`cdn.unit.travel` — по одному файлу или сразу пачкой (сотни файлов, целые
папки) с общим прогрессом. Любое приложение открывает его одним вызовом JS и
получает в ответ ссылки вида

```
https://cdn.unit.travel/f/5a/31/5a3138c4f70853f57070dd57667316094cf558fbbf3e608b185202f49d6f1c35.pdf
```

Стек: Python (Starlette + Pillow), ffmpeg для видео, чистый HTML/CSS/JS без сборки, nginx.

## Как это работает

```
 Ваше приложение (https://app.unit.travel)            cdn.unit.travel
┌──────────────────────────────────────┐          ┌───────────────────────────────────┐
│ 1. backend подписывает токен         │          │                                   │
│    (HMAC-SHA256, секрет клиента)     │          │                                   │
│ 2. UnitUploader.open()  ─────────────┼─ iframe ─▶ /uploader/   модальное окно       │
│    токен уходит в iframe postMessage │          │ 3. POST /api/v1/files (по 1 файлу)│
│                                      │          │    проверка → очистка → ClamAV    │
│                                      │          │    → /f/ab/cd/<hmac-хеш>.<ext>    │
│                                      │          │    видео: 202 + задача, прогресс  │
│                                      │          │    через GET /api/v1/jobs/<id>    │
│ 4. Promise → [{url, name, size, …}] ◀┼postMessage                                  │
└──────────────────────────────────────┘          └───────────────────────────────────┘
```

* Окно — это iframe с `cdn.unit.travel`, поэтому загрузка идёт на тот же origin
  (CORS не нужен), а страница вашего приложения не видит токен и не может
  подменить проверку.
* Встроить окно в iframe могут только origin'ы из конфигурации (`frame-ancestors`).
* Результат iframe отправляет только на origin, который сервер подтвердил для
  этого токена.

## Что защищает аплоудер

| Угроза | Защита |
|---|---|
| Загрузка скриптов/исполняемых файлов (`.php`, `.js`, `.html`, `.svg`, `.exe`, …) | Белый список расширений; содержимое обязано совпадать с расширением (magic bytes + полноценный парсер); исполняемые сигнатуры (MZ, ELF, Mach-O, `#!`, wasm) отклоняются при любом расширении |
| Двойные расширения, RTL-подмена имени (`gpj.exe` → `exe.jpg`) | Учитывается только последнее расширение, невидимые/bidi-символы вырезаются; имя пользователя никогда не попадает на диск |
| Полиглоты (JPEG+PHP, GIFAR, HTML в начале ZIP/PDF) | Изображения полностью декодируются и **перекодируются** из пикселей: EXIF/XMP/ICC, комментарии, хвосты отбрасываются. ZIP/PDF с данными перед заголовком отклоняются |
| Image-бомбы, decompression bombs | Проверка размеров по заголовку *до* декодирования, лимит пикселей на кадр и суммарно на анимацию |
| ReDoS / медленные пути | Все регулярные выражения для документов линейные: 1 МБ враждебного XML проверяется за сотые доли секунды |
| PDF с JavaScript, `/Launch`, вложениями, XFA, `/SubmitForm`, `/GoToR` (утечка NTLM) | Сканирование всех имён с декодированием `#xx`-обфускации, распаковка сжатых object streams (там прячут `/JS`), отказ при неоднозначной структуре; зашифрованные PDF отклоняются |
| Макросы Office, ActiveX, OLE (CVE-2017-11882), `.docm` переименованный в `.docx`, вложенный `.docm` | Проверка `[Content_Types].xml` и **каждой** части по содержимому (а не по имени файла — Office находит части по связям), OLE-сигнатур, `vbaProject.bin`, вложенных пакетов; принимаются только docx/xlsx/pptx/odt/ods/odp |
| Follina (CVE-2022-30190), remote template injection | Любая внешняя связь (`TargetMode="External"`) кроме обычной гиперссылки отклоняется; XML-сущности декодируются до проверки |
| DDE (`DDEAUTO`, `=cmd\|…`), «Run program» в PowerPoint, макросы LibreOffice | Проверка полей Word, формул и именованных диапазонов Excel, `ppaction://`, `event-listener`; CDATA, комментарии и XML-сущности нормализуются до проверки, поэтому ими нельзя разрезать или спрятать формулу |
| XXE / billion laughs | Любые `<!DOCTYPE`/`<!ENTITY` в документах отклоняются |
| CSV/formula injection | Ячейки, начинающиеся с `= @ + -` (кроме чисел и телефонов), отклоняются для всех распространённых разделителей |
| Zip slip, симлинки, zip/gzip-бомбы | Проверка путей, типов записей, коэффициента сжатия и общего бюджета распаковки; архив **пересобирается** только из проверенных файлов, каждый файл внутри проходит те же проверки |
| Видео с эксплойтами для плееров, метаданные (GPS), вложения MKV, лишние дорожки | По умолчанию видео **перекодируется** в H.264/AAC MP4 из декодированных кадров: в хранилище попадает только то, что сгенерировал наш энкодер. Берутся одна видео- и одна аудиодорожка, метаданные, главы, субтитры и вложения отбрасываются; разрешение и длительность ограничены, кодеки по белому списку |
| Атаки на сам ffmpeg (HLS/concat-плейлисты → чтение локальных файлов, SSRF) | Демуксер задаётся жёстко по расширению (плейлист не может притвориться видео), разрешён только протокол `file`, внешние ссылки MOV (`dref`) выключены; ffmpeg запускается с пустым окружением, лимитами памяти/CPU/размера файла и тайм-аутом |
| Вирусы | Опционально ClamAV (clamd INSTREAM), по умолчанию fail-closed; проверяются и загруженные, и сохраняемые байты |
| XSS при отдаче файлов | `X-Content-Type-Options: nosniff`, `CSP: default-src 'none'; sandbox`, всё кроме картинок и видео — `Content-Disposition: attachment`; строгий `Content-Type` только по белому списку |
| XSS/clickjacking в окне загрузки | CSP без `unsafe-inline`, `frame-ancestors` по списку, весь пользовательский текст через `textContent` |
| Злоупотребление API, подделка запросов | Короткоживущий HMAC-токен (origin, срок, лимит файлов, разрешённые типы), лимит запросов по IP, лимит размера тела, без cookies (CSRF невозможен) |
| Угадывание ссылок | Имя = HMAC-SHA256 от содержимого с секретным ключом: нельзя ни угадать, ни проверить наличие известного файла |
| Выполнение файлов на сервере | Файлы отдаёт nginx как статику, только пути вида `/f/xx/yy/<64 hex>.<ext>`; хранилище монтируется `noexec`; сервис в systemd-песочнице с доступом на запись только к хранилищу |

### Поддерживаемые типы

| Категория | Расширения | Что делается |
|---|---|---|
| image | jpg/jpeg, png, gif, webp | декодирование и перекодирование (анимация сохраняется), метаданные удаляются |
| document | pdf | глубокая проверка на активное содержимое |
| document | docx, xlsx, pptx, odt, ods, odp | проверка частей и пересборка zip |
| document | txt, csv | только UTF-8, без бинарных данных и HTML/скриптов; CSV без формул |
| archive | zip, tar, tar.gz/tgz | каждый файл проверяется как отдельная загрузка, архив пересобирается; мусор macOS/Windows удаляется |
| video | mp4/m4v, mov, webm, mkv | перекодирование в MP4 (H.264/AAC, до 1920 px по большей стороне) в фоне; режим `remux` только пересобирает контейнер. Видео внутри архивов не принимается |

Проверка подтверждена на реальных файлах из LibreOffice, python-docx,
openpyxl, python-pptx, reportlab, qpdf (PDF с object streams) и ffmpeg, а
также независимым adversarial-ревью с рабочими PoC (все найденные обходы
закрыты и покрыты регрессионными тестами).

### Оптовая загрузка в окне

* выбор многих файлов, **папок** (кнопка или drag & drop, с подпапками) и вставка из буфера;
  из папок молча пропускаются скрытые/служебные (`.DS_Store`, `Thumbs.db`, …) и неподдерживаемые файлы;
* общий прогресс: «35 МБ из 120 МБ · 29%», скорость, оставшееся время; у каждого файла —
  своя полоса загрузки, для видео — ещё и прогресс обработки на сервере;
* 3 загрузки параллельно, превью картинок и видео создаются лениво (только видимые строки);
* временные ошибки (сеть, лимит запросов, сервер занят) повторяются автоматически
  с паузой из `Retry-After`; кнопки «Повторить неудачные» и «Убрать ошибки»;
* повторно добавленный тот же файл не дублируется;
* если пачка загружается дольше, чем живёт токен, окно само попросит новый у
  `getToken` — уже идущие и обработанные файлы не теряются.

Намеренно **не поддерживаются**: SVG, HTML, XML, RTF, старые `.doc/.xls/.ppt`,
macro-enabled форматы, rar/7z (стандартная библиотека не умеет их безопасно
проверять), файлы с паролем.

## Быстрый старт (локально)

```bash
sudo apt install ffmpeg          # для видео; без него задайте UPLOADER_VIDEO_MODE=off
cd cdn_uploader
python3 -m venv .venv
.venv/bin/pip install -r requirements-dev.txt
```

```bash
export UPLOADER_HASH_KEY=$(python3 -c 'import secrets; print(secrets.token_hex(32))')
export UPLOADER_CLIENTS='{"demo":{"secret":"demo-secret-change-me-0123456789abcdef","origins":["http://localhost:8001"]}}'
export UPLOADER_STORAGE_DIR=./data/f
export UPLOADER_CDN_BASE_URL=http://localhost:8000/f
export UPLOADER_SERVE_FILES=1
.venv/bin/uvicorn --factory uploader.app:create_app --port 8000
```

Во втором терминале — демо «родительского» приложения:

```bash
DEMO_CLIENT_SECRET=demo-secret-change-me-0123456789abcdef .venv/bin/uvicorn examples.parent_app:app --port 8001
```

Откройте http://localhost:8001 и нажмите «Загрузить файлы».

Тесты:

```bash
.venv/bin/python -m pytest
```

## Интеграция в приложение

### 1. Бэкенд выпускает токен

Секрет клиента хранится только на сервере вашего приложения. Перед выдачей
токена проверьте сессию пользователя и решите, что ему можно загружать.

Формат: `base64url(JSON) + "." + base64url(HMAC-SHA256(secret, первая часть))`.

| Поле | Обязательно | Значение |
|---|---|---|
| `cid` | да | id клиента из `UPLOADER_CLIENTS` |
| `origin` | да | точный origin страницы, открывающей окно (`https://app.unit.travel`) |
| `exp` | да | unix-время истечения (не дальше `UPLOADER_TOKEN_MAX_TTL`) |
| `jti` | да | случайный id; по нему считается лимит файлов |
| `max_files` | нет | сколько файлов можно загрузить по токену (не больше `UPLOADER_MAX_FILES_PER_TOKEN`, по умолчанию 200) |
| `types` | нет | подмножество `["image", "document", "archive", "video"]` (по умолчанию все) |
| `sub` | нет | id пользователя, пишется в лог |

**Python** (можно просто импортировать `uploader/tokens.py`):

```python
from uploader.tokens import create_token

token = create_token("booking", SECRET, "https://app.unit.travel",
                     subject=str(user.id), max_files=10, types=["image", "document"])
```

**Node.js:**

```js
const crypto = require('crypto');

function createUploadToken({ clientId, secret, origin, sub, ttl = 900, maxFiles = 20, types }) {
  const payload = {
    cid: clientId,
    origin,
    exp: Math.floor(Date.now() / 1000) + ttl,
    jti: crypto.randomBytes(16).toString('base64url'),
    max_files: maxFiles,
  };
  if (sub) payload.sub = String(sub);
  if (types) payload.types = types;
  const body = Buffer.from(JSON.stringify(payload)).toString('base64url');
  const sig = crypto.createHmac('sha256', secret).update(body).digest('base64url');
  return `${body}.${sig}`;
}
```

**PHP:**

```php
function b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function create_upload_token(string $clientId, string $secret, string $origin,
                             string $sub = '', int $ttl = 900, int $maxFiles = 20): string {
    $payload = [
        'cid' => $clientId,
        'origin' => $origin,
        'exp' => time() + $ttl,
        'jti' => b64url(random_bytes(16)),
        'max_files' => $maxFiles,
    ];
    if ($sub !== '') $payload['sub'] = $sub;
    $body = b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $body . '.' . b64url(hash_hmac('sha256', $body, $secret, true));
}
```

Для ручной проверки:

```bash
python -m uploader.tokens --client demo --secret demo-secret-change-me-0123456789abcdef --origin http://localhost:8001
```

### 2. Фронтенд открывает окно

Подключите SDK (лучше скопировать файл `static/sdk/unit-uploader.js` в своё
приложение или подключить с `integrity`):

```html
<script src="https://cdn.unit.travel/sdk/unit-uploader.js"></script>
```

```js
const files = await UnitUploader.open({
  uploaderUrl: 'https://cdn.unit.travel/uploader/',
  // Функция, а не готовая строка: так окно сможет получить новый токен,
  // если большая пачка грузится дольше, чем живёт первый.
  getToken: () => fetch('/api/cdn-upload-token', { method: 'POST' })
                    .then(r => r.json()).then(j => j.token),
  lang: 'ru',                     // ru | uk | en
  title: 'Документы для визы',    // необязательно
  types: ['image', 'video'],      // необязательно, только сужает то, что разрешил токен
  maxFiles: 5,                    // необязательно
  multiple: true,                 // false = один файл
  cdnBaseUrl: 'https://cdn.unit.travel/f/',   // необязательно: SDK отбросит любые другие ссылки
  onFileUploaded: (file) => {},   // необязательно, после каждого файла
  onError: (err) => {},           // необязательно, например истёкший токен
});

if (files === null) {
  // пользователь закрыл окно
} else {
  // [{ url, name, size, mime, ext, category, hash }, ...]
}
```

`name` — исходное имя файла от пользователя: выводите его только через
`textContent`/экранирование, никогда через `innerHTML`.

## HTTP API

Все запросы — с заголовком `Authorization: Bearer <token>`.

`GET /api/v1/session` — проверка токена и лимиты для окна:

```json
{"origin": "https://app.unit.travel", "expires_at": 1793257543, "max_files": 10, "remaining": 10,
 "max_file_size": 52428800, "max_sizes": {"image": 52428800, "video": 524288000},
 "types": ["image", "video"], "extensions": {"jpg": "image", "mp4": "video"}}
```

`POST /api/v1/files` — один файл в теле запроса как есть (не multipart),
имя в заголовке `X-File-Name` (percent-encoded UTF-8):

```bash
curl -X POST https://cdn.unit.travel/api/v1/files \
  -H "Authorization: Bearer $TOKEN" -H "X-File-Name: $(python3 -c 'import urllib.parse;print(urllib.parse.quote("Паспорт.jpg"))')" \
  --data-binary @passport.jpg
```

Ответ `201`:

```json
{"url": "https://cdn.unit.travel/f/5a/31/5a31…1c35.jpg", "hash": "5a31…1c35", "name": "Паспорт.jpg",
 "ext": "jpg", "mime": "image/jpeg", "category": "image", "size": 183211, "original_size": 201733}
```

Для видео ответ `202` — файл принят и обрабатывается в фоне:

```json
{"job": "L9PoiacKlTtmtZh9oaQoAjes", "status": "queued", "progress": 0.0, "name": "Поездка.mov"}
```

`GET /api/v1/jobs/<job>` (тот же клиент и `sub`, токен может быть уже новым):

```json
{"job": "L9Po…", "status": "processing", "progress": 0.42}
{"job": "L9Po…", "status": "done", "progress": 1.0, "file": {"url": "https://cdn.unit.travel/f/…/….mp4", "ext": "mp4", "mime": "video/mp4", …}}
{"job": "L9Po…", "status": "error", "error": "unsupported_codec", "message": "…"}
```

Ошибки: `{"error": "<код>", "message": "<подробности>"}`. На `429` и `503`
сервер присылает `Retry-After` с точным временем ожидания.

| HTTP | Коды |
|---|---|
| 401 / 403 | `invalid_token`, `token_expired`, `origin_not_allowed` |
| 413 | `file_too_large` |
| 415 | `type_not_allowed` |
| 422 | `content_mismatch`, `active_content`, `invalid_image`, `image_too_large`, `invalid_pdf`, `invalid_document`, `invalid_archive`, `invalid_text`, `unsafe_path`, `encrypted`, `archive_too_many_entries`, `archive_nesting`, `too_large_uncompressed`, `empty_file`, `malware_detected` |
| 422 (в задаче видео) | `invalid_video`, `unsupported_codec`, `video_too_long`, `video_too_large`, `video_timeout` |
| 429 | `rate_limited`, `file_limit_reached` |
| 503 | `antivirus_unavailable`, `busy` (очередь видео заполнена) |

## Развёртывание

1. Пользователь и каталоги:

   ```bash
   sudo apt install ffmpeg
   sudo useradd --system --no-create-home --shell /usr/sbin/nologin unitcdn
   sudo mkdir -p /opt/unit-uploader /srv/unit-cdn/f /srv/unit-cdn/tmp /srv/unit-cdn/nginx-body
   sudo chown unitcdn:unitcdn /srv/unit-cdn/f /srv/unit-cdn/tmp
   sudo chown www-data:www-data /srv/unit-cdn/nginx-body
   ```

   `tmp` — недогруженные файлы и рабочие файлы ffmpeg; он на том же диске,
   что и хранилище, чтобы готовое видео перемещалось, а не копировалось.

   Хранилище лучше вынести на отдельный раздел и смонтировать с
   `noexec,nosuid,nodev`.

2. Код и зависимости:

   ```bash
   sudo cp -r uploader static /opt/unit-uploader/
   sudo python3 -m venv /opt/unit-uploader/venv
   sudo /opt/unit-uploader/venv/bin/pip install -r requirements.txt
   ```

3. Конфигурация: скопируйте `.env.example` в `/etc/unit-uploader.env`
   (`chmod 600`), задайте `UPLOADER_HASH_KEY` (не меняйте его потом) и
   клиентов с их секретами и origin'ами.

4. `deploy/unit-uploader.service` → `/etc/systemd/system/`, затем
   `systemctl enable --now unit-uploader`.

5. `deploy/nginx.conf` → `/etc/nginx/sites-available/`, поправьте пути к
   сертификатам, `nginx -t && systemctl reload nginx`.

6. Рекомендуется ClamAV: `apt install clamav-daemon`, затем
   `UPLOADER_CLAMD=unix:/run/clamav/clamd.ctl`. Проверьте, что лимит
   `StreamMaxLength` в `clamd.conf` не меньше `UPLOADER_MAX_FILE_MB`.
   Перекодированное видео ClamAV не проверяется (его целиком создаёт наш
   энкодер); в режиме `remux` — проверяется, тогда `StreamMaxLength` нужен
   не меньше `UPLOADER_MAX_VIDEO_MB`.

### Важно

* Сервис работает в **одном процессе**: счётчики файлов по токену и лимит
  запросов хранятся в памяти. Для нескольких воркеров/серверов перенесите
  `ratelimit.py` на Redis. nginx `limit_req` действует независимо от этого.
* Перекодирование видео нагружает CPU. Замер на 4 ядрах: 20 с 1080p,
  похожего на съёмку телефоном (15 Мбит/с), обрабатываются за ~13 с; очень
  шумное видео — до ~2× дольше его длительности; `remux` — доли секунды.
  По умолчанию видео обрабатываются
  по одному (`UPLOADER_VIDEO_WORKERS=1`), ещё до 20 ждут в очереди; лимиты
  длительности/размера — в `.env.example`. Для большого потока видео
  вынесите обработку на отдельную машину/очередь.
* Если файлы отдаются через внешний CDN/кеш, он должен сохранять заголовки
  `Content-Type`, `X-Content-Type-Options`, `Content-Security-Policy` и
  `Content-Disposition`.
* PDF проверяется, но не перерисовывается. Если нужна максимальная гарантия
  (CDR), можно дополнительно растеризовать PDF отдельным изолированным
  процессом — это дорого по CPU и убирает текстовый слой.
* Ссылки публичные для всех, кто их знает (неугадываемые, но не приватные).
  Для паспортов и подобных документов стоит подумать о подписанных ссылках
  с ограниченным сроком действия (nginx `secure_link`).

## Структура

```
uploader/
  app.py            HTTP API, заголовки безопасности, раздача UI
  tokens.py         выпуск и проверка токенов (+ CLI)
  config.py         настройки из переменных окружения
  storage.py        хранение по HMAC-хешу содержимого
  antivirus.py      клиент clamd
  ratelimit.py      лимиты запросов и файлов по токену
  jobs.py           фоновая обработка видео
  validators/       проверка и очистка каждого типа файлов (video.py — ffmpeg)
static/
  uploader/         модальное окно (HTML/CSS/JS, работает в iframe)
  sdk/              unit-uploader.js для родительского приложения
deploy/             nginx и systemd
examples/           демо родительского приложения
tests/              pytest: валидаторы, видео, токены, API
```
