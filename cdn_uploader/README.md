# cdn.unit.travel — защищённый аплоудер файлов

Модальное окно загрузки изображений, документов, архивов и видео на
`cdn.unit.travel` — по одному файлу или сразу пачкой (сотни файлов, целые
папки) с общим прогрессом. Любое приложение открывает его одним вызовом JS и
получает в ответ ключи и ссылки на файлы. Каждой загрузке приложение задаёт
уровень доступа: **публичные** файлы, файлы только по **временным ссылкам** и
файлы, которые открываются **только внутри приложения** (паспорта, справки).
Для фотографий можно сразу получить несколько размеров (`orig`, `small`,
`medium`, …), разложенных по папкам. Интерфейс окна — на английском,
украинском и русском, с переключением на лету.

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
  (CORS не нужен). Страница приложения получает от своего бэкенда токен и
  передаёт его окну; токен короткоживущий и подписан, а в нём зафиксированы
  пользователь, сайт, лимиты, типы, папка и уровень доступа — изменить их
  страница не может, а проверка файлов идёт только на сервере.
* Работает на любых доменах, включая white-label сайты (см. «White label»).
* Встроить окно в iframe может только тот зарегистрированный сайт, который
  его открывает (`frame-ancestors`).
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
| XSS/clickjacking в окне загрузки | CSP без `unsafe-inline`; `frame-ancestors` — только сайт, открывший окно, если он зарегистрирован (список партнёров не раскрывается); весь пользовательский текст через `textContent`; тема бренда проверяется строго (только цвета, скругление, имя шрифта) |
| Злоупотребление API, подделка запросов | Короткоживущий HMAC-токен (origin, срок, лимит файлов, разрешённые типы), лимит запросов по IP, лимит размера тела, без cookies (CSRF невозможен) |
| Угадывание ссылок | Имя = HMAC-SHA256 от содержимого с секретным ключом: нельзя ни угадать, ни проверить наличие известного файла |
| Публичный доступ к паспортам и документам | Уровни доступа `signed` (только по временной подписанной ссылке) и `private` (только с токеном чтения из приложения). Такие файлы лежат в отдельном каталоге, который nginx не раздаёт напрямую; права проверяются до обращения к диску; `Cache-Control: private, no-store`, `X-Robots-Tag: noindex` |
| Подмена ключа чужого файла | Ключ содержит id клиента и проверяется его секретом; в ответе загрузки — квитанция (HMAC от ключа и пользователя), по которой бэкенд проверяет, что файл загрузил именно этот пользователь |
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
| `sub` | нет | id пользователя: пишется в лог и входит в квитанцию файла |
| `access` | нет | `public`, `signed` или `private` — см. «Доступ к файлам» (по умолчанию `default_access` клиента, обычно `public`) |
| `folder` | нет | папка, например `rooms` или `photos/rooms` — см. «Размеры фото и папки» |
| `sizes` | нет | размеры фото, например `["small-70-0", "big-1200-800"]` |
| `path` | нет | подпапки вместо шардинга по хешу, например `10/10991` |

**Python** (можно просто импортировать `uploader/tokens.py`):

```python
from uploader.tokens import create_token

token = create_token("booking", SECRET, "https://app.unit.travel",
                     subject=str(user.id), max_files=10, types=["image", "document"],
                     access="private")

# фото номеров: оригинал + 4 размера в photos/rooms/<размер>/10/10991/
token = create_token("booking", SECRET, "https://app.unit.travel", subject=str(user.id),
                     types=["image"], folder="photos/rooms", path=f"{hotel_id // 1000}/{hotel_id}",
                     sizes=["preview_panel-100-100", "small-70-0", "medium-350-0", "big-1200-800"])
```

**Node.js:**

```js
const crypto = require('crypto');

function createUploadToken({ clientId, secret, origin, sub, ttl = 900, maxFiles = 20, types, access,
                            folder, sizes, path }) {
  const payload = {
    cid: clientId,
    origin,
    exp: Math.floor(Date.now() / 1000) + ttl,
    jti: crypto.randomBytes(16).toString('base64url'),
    max_files: maxFiles,
  };
  if (sub) payload.sub = String(sub);
  if (types) payload.types = types;
  if (access) payload.access = access;      // 'public' | 'signed' | 'private'
  if (folder) payload.folder = folder;      // e.g. photos/rooms
  if (sizes) payload.sizes = sizes;         // e.g. ['small-70-0', 'big-1200-800']
  if (path) payload.path = path;            // e.g. 10/10991
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
                             string $sub = '', int $ttl = 900, int $maxFiles = 20,
                             string $access = 'public', string $folder = '',
                             array $sizes = [], string $path = '', array $types = []): string {
    $payload = [
        'cid' => $clientId,
        'origin' => $origin,
        'exp' => time() + $ttl,
        'jti' => b64url(random_bytes(16)),
        'max_files' => $maxFiles,
        'access' => $access,
    ];
    if ($sub !== '') $payload['sub'] = $sub;
    if ($folder !== '') $payload['folder'] = $folder;     // e.g. photos/rooms
    if ($sizes) $payload['sizes'] = array_values($sizes); // e.g. ['small-70-0', 'big-1200-800']
    if ($path !== '') $payload['path'] = $path;           // e.g. 10/10991
    if ($types) $payload['types'] = array_values($types); // e.g. ['image']
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
  lang: 'uk',                     // необязательно, см. «Языки»
  languages: ['en', 'uk', 'ru'],  // необязательно: меню языка в окне
  onLanguageChange: (lang) => {}, // необязательно: язык выбрали в окне
  title: { en: 'Visa documents', uk: 'Документи для візи', ru: 'Документы для визы' },
  types: ['image', 'video'],      // необязательно, только сужает то, что разрешил токен
  maxFiles: 5,                    // необязательно
  multiple: true,                 // false = один файл
  cdnBaseUrl: 'https://cdn.unit.travel',      // необязательно: SDK отбросит ссылки с другого хоста
  onFileUploaded: (file) => {},   // необязательно, после каждого файла
  onError: (err) => {},           // необязательно, например истёкший токен
});

if (files === null) {
  // пользователь закрыл окно
} else {
  // [{ key, access, url, expires_at?, receipt, name, size, mime, ext, category, hash }, ...]
}
```

`name` — исходное имя файла от пользователя: выводите его только через
`textContent`/экранирование, никогда через `innerHTML`.

## Доступ к файлам и ссылки

Уровень доступа задаёт **бэкенд приложения** в токене загрузки (`access`) — его
нельзя поменять из браузера. Для клиента можно задать уровень по умолчанию:
`"default_access": "private"` в `UPLOADER_CLIENTS`.

| `access` | Где лежит / ключ | Как открыть | Для чего |
|---|---|---|---|
| `public` | `f/ab/cd/<hash>.ext` | постоянная ссылка `https://cdn.unit.travel/f/…`, кто угодно, у кого она есть | аватары, фото отелей, публичные материалы |
| `signed` | `s/<клиент>/ab/cd/<hash>.ext`¹ | только по **временной ссылке** `…?exp=…&sig=…`, которую подписывает бэкенд приложения (срок задаёте вы, максимум `UPLOADER_LINK_MAX_TTL`); по желанию ссылка привязывается к IP пользователя и задаёт имя файла при скачивании | билеты, ваучеры, документы, которые надо показать в `<img>`/`<video>` или дать скачать по кнопке |
| `private` | `p/<клиент>/ab/cd/<hash>.ext`¹ | **только с токеном чтения** приложения в заголовке `Authorization`; голая ссылка отвечает `401`, ссылки-подписи не работают, читать можно только со страниц ваших origin'ов (CORS) | паспорта, справки, персональные документы |

¹ У файлов white-label сайта общего клиента — `s/<клиент>.<сайт>/…` и
`p/<клиент>.<сайт>/…`: они открываются только на своём сайте (см. «White label»).

Как это работает для приложения:

```
загрузка ─▶ окно отдаёт { key, access, url, receipt, … }
           ─▶ фронтенд передаёт key + receipt своему бэкенду
           ─▶ бэкенд проверяет receipt (файл загрузил этот пользователь) и сохраняет key в БД

показ    ─▶ бэкенд проверяет права пользователя на key и выдаёт:
              public:  постоянный url (можно хранить сразу его)
              signed:  временную ссылку sign_link(key, ttl=…)   → <img src>, <a href>, <video>
              private: токен чтения create_read_token([key], …) → UnitUploader.fileUrl()/download()
```

* **Храните `key`, а не `url`**: для `signed` ссылка из ответа загрузки живёт
  `UPLOADER_PREVIEW_LINK_TTL` (10 минут) — её хватает на превью сразу после загрузки.
* **Проверяйте `receipt`** перед сохранением ключа: иначе пользователь мог бы
  прислать ключ чужого файла и получить на него ссылку.
* Временная ссылка открывается у любого, кто её получил, до истечения срока.
  Для документов делайте её короткой (1–5 минут), при необходимости с `ip`.
  Если файл нельзя открывать вне вашего сайта вообще — используйте `private`.
* Подписи и токены выпускаются локально общим секретом клиента, без запроса к CDN.

**Показ приватного файла на странице приложения:**

```js
const getToken = () => fetch('/api/cdn-read-token', { method: 'POST', body: JSON.stringify({ keys: [file.key] }) })
                         .then(r => r.json()).then(j => j.token);
img.src = await UnitUploader.fileUrl(file.url, { getToken });           // blob: URL, без токена в адресе
await UnitUploader.download(file.url, { getToken, filename: 'passport.pdf' });
```

CSP страницы приложения должен разрешать `connect-src https://cdn.unit.travel` и
`img-src blob:` (для `fileUrl`). Для больших видео используйте `signed`:
`private` загружает файл в память браузера целиком.

**Python** (бэкенд приложения):

```python
from uploader.tokens import create_read_token, sign_link, verify_receipt

if verify_receipt(file["key"], str(user.id), file["receipt"], SECRET):
    save(user, file["key"])                                   # при загрузке

link = sign_link(key, "booking", SECRET, "https://cdn.unit.travel", ttl=120,
                 ip=request_ip, name="Ticket.pdf")            # signed
token = create_read_token("booking", SECRET, "https://app.unit.travel", [key], ttl=120)  # private
```

**Node.js:**

```js
const crypto = require('crypto');

const b64url = buf => Buffer.from(buf).toString('base64url');
const derive = (secret, label) => crypto.createHmac('sha256', secret).update(label).digest();

// Temporary link to a "signed" file (key from the upload result).
function signLink({ key, secret, cdn = 'https://cdn.unit.travel', ttl = 300, ip = '', name = '' }) {
  const exp = Math.floor(Date.now() / 1000) + ttl;
  const sig = crypto.createHmac('sha256', derive(secret, 'unit-uploader:link:v1'))
    .update(`${key}\n${exp}\n${ip}\n${name}`).digest();
  const params = new URLSearchParams({ exp: String(exp), sig: b64url(sig) });
  if (ip) params.set('ip', '1');
  if (name) params.set('name', name);
  return `${cdn}/${key}?${params.toString().replace(/\+/g, '%20')}`;
}

// Read token for "private" files: the page sends it as "Authorization: Bearer ...".
function createReadToken({ clientId, secret, origin, files, ttl = 300, sub }) {
  const payload = { cid: clientId, origin, exp: Math.floor(Date.now() / 1000) + ttl,
                    jti: crypto.randomBytes(16).toString('base64url'), scope: 'read', files };
  if (sub) payload.sub = String(sub);
  const body = b64url(JSON.stringify(payload));
  return `${body}.${b64url(crypto.createHmac('sha256', secret).update(body).digest())}`;
}

// Was this key really uploaded by this user? (receipt from the upload result)
function verifyReceipt({ key, sub, receipt, secret }) {
  const expected = b64url(crypto.createHmac('sha256', derive(secret, 'unit-uploader:receipt:v1'))
    .update(`${key}\n${sub}`).digest());
  return expected.length === String(receipt).length &&
    crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(String(receipt)));
}
```

**PHP:**

```php
// Temporary link to a "signed" file (key from the upload result).
function sign_link(string $key, string $secret, string $cdn = 'https://cdn.unit.travel',
                   int $ttl = 300, string $ip = '', string $name = ''): string {
    $exp = time() + $ttl;
    $linkKey = hash_hmac('sha256', 'unit-uploader:link:v1', $secret, true);
    $sig = b64url(hash_hmac('sha256', "$key\n$exp\n$ip\n$name", $linkKey, true));
    $params = ['exp' => $exp, 'sig' => $sig];
    if ($ip !== '') $params['ip'] = '1';
    if ($name !== '') $params['name'] = $name;
    return "$cdn/$key?" . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// Read token for "private" files: the page sends it as "Authorization: Bearer ...".
function create_read_token(string $clientId, string $secret, string $origin, array $files,
                           int $ttl = 300, string $sub = ''): string {
    $payload = ['cid' => $clientId, 'origin' => $origin, 'exp' => time() + $ttl,
                'jti' => b64url(random_bytes(16)), 'scope' => 'read', 'files' => array_values($files)];
    if ($sub !== '') $payload['sub'] = $sub;
    $body = b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $body . '.' . b64url(hash_hmac('sha256', $body, $secret, true));
}

// Was this key really uploaded by this user? (receipt from the upload result)
function verify_receipt(string $key, string $sub, string $receipt, string $secret): bool {
    $receiptKey = hash_hmac('sha256', 'unit-uploader:receipt:v1', $secret, true);
    return hash_equals(b64url(hash_hmac('sha256', "$key\n$sub", $receiptKey, true)), $receipt);
}
```

Токен чтения: до 100 ключей одного клиента, срок — не дольше
`UPLOADER_TOKEN_MAX_TTL`; токен загрузки не может читать, токен чтения — загружать.

## Размеры фото и папки

Папку и размеры задаёт **бэкенд приложения** в токене загрузки (`folder`,
`sizes`, `path`), поэтому пользователь не может ни подменить папку, ни
заказать размер 50000 px. Удобно держать на бэкенде пресеты («номера»,
«отели», «аватары») и выбирать нужный при выдаче токена — так сделано в демо.

### Как раздел и сущность передают папку и размеры

Так же, как сейчас (`folder=rooms&size[]=…`), только параметры идут не прямо в
CDN, а через ваш бэкенд, который подписывает их в токен:

```
страница «Номер 10991», кнопка «Фото»
  └─ UnitUploader.open({ getToken: () => fetch('/api/cdn-upload-token?section=rooms&id=10991') })
бэкенд приложения
  ├─ проверяет, что пользователь может редактировать номер 10991
  ├─ берёт пресет rooms: папка photos/rooms + размеры
  ├─ строит path из id: 10991 → 10/10991
  └─ подписывает токен { folder, sizes, path, types, … }
окно ─▶ отправляет токен с каждым файлом ─▶ CDN читает из него folder/sizes/path,
         делает размеры и раскладывает по папкам
```

Фронтенд — одна функция на все разделы; раздел и id сущности уходят вашему
бэкенду:

```js
function openUploader(section, id, options = {}) {
  return UnitUploader.open({
    uploaderUrl: 'https://cdn.unit.travel/uploader/',
    getToken: () => fetch(`/api/cdn-upload-token?section=${section}&id=${id}`, { method: 'POST' })
                      .then(r => r.json()).then(j => j.token),
    ...options,
  });
}

roomPhotosButton.onclick = () => openUploader('rooms', 10991);
hotelPhotosButton.onclick = () => openUploader('hotels', 512);
visaButton.onclick = () => openUploader('visa', orderId, { title: { ru: 'Документы для визы' } });
```

Бэкенд (PHP, `create_upload_token` — из раздела «Интеграция»): пресеты те же,
что у вас сейчас, только в виде строк `имя-ширина-высота`:

```php
const CDN_PRESETS = [
    'rooms'  => ['folder' => 'photos/rooms', 'types' => ['image'],
                 'sizes'  => ['preview_panel-100-100', 'small-70-0', 'medium-350-0', 'big-1200-800']],
    'hotels' => ['folder' => 'photos/hotels', 'types' => ['image'],
                 'sizes'  => ['small-70-0', 'medium-350-0', 'big-1200-800']],
    'visa'   => ['folder' => 'docs/visa', 'types' => ['image', 'document'], 'access' => 'private'],
];

// POST /api/cdn-upload-token?section=rooms&id=10991
$section = $_GET['section'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$preset = CDN_PRESETS[$section] ?? null;
if (!$preset || $id <= 0 || !user_can_edit($user, $section, $id)) {   // ваша проверка прав
    http_response_code(403);
    exit;
}
echo json_encode(['token' => create_upload_token(
    clientId: 'booking', secret: CDN_SECRET, origin: 'https://app.unit.travel',
    sub: (string)$user->id, maxFiles: 50,
    access: $preset['access'] ?? 'public',
    folder: $preset['folder'], sizes: $preset['sizes'] ?? [], types: $preset['types'],
    path: intdiv($id, 1000) . '/' . $id,                               // 10991 -> 10/10991
)]);
```

Один вызов `UnitUploader.open()` — один раздел и одна сущность. Если на
странице несколько разделов (фото номера и документы брони), у каждой кнопки
свой `openUploader(...)`. Поменять пресет «на лету» внутри уже открытого окна
нельзя.

Почему не прямо из браузера: тогда любой пользователь мог бы загрузить файлы
в папку чужого отеля (`path=10/10991`) или заказать сотню размеров по
4096 px. Бэкенд же перед подписью проверяет права на сущность.

### Формат размеров

```
sizes = ["preview_panel-100-100", "small-70-0", "medium-350-0", "big-1200-800", "thumb-100-100-crop"]
```

* формат размера — `имя-ширина-высота[-fit|-crop]` (или объект
  `{"name": "small", "width": 70, "height": 0, "mode": "fit"}`), до 10 размеров,
  стороны до 4096 px; имя — латиница, цифры и `_`;
* `0` по одной стороне — пропорционально: `small-70-0` → ширина 70;
* обе стороны, режим `fit` (по умолчанию) — вписать в рамку без обрезки:
  `big-1200-800` для 1600×1200 → 1067×800;
* `crop` — заполнить рамку ровно и обрезать лишнее по центру:
  `thumb-100-100-crop` → 100×100;
* фото **никогда не увеличиваются**: если оригинал меньше, размер остаётся
  размером оригинала;
* `orig` создаётся всегда — это очищенный оригинал (перекодирован, EXIF и
  прочие метаданные удалены, поворот по EXIF применён), в исходном разрешении;
* формат размеров тот же, что у оригинала (jpg/png/gif/webp), прозрачность
  сохраняется, анимированные GIF/WebP остаются анимированными;
* документы, архивы и видео размеров не имеют и кладутся прямо в папку, без
  `orig`: `f/<folder>/<path>/<hash>.pdf`. Размеры из токена на них не
  влияют, и одинаковый файл в одной папке получает тот же ключ.

Раскладка и ссылки — у всех размеров одно имя файла, отличается только папка
размера:

```
folder=photos/rooms, path=10/10991:
https://cdn.unit.travel/f/photos/rooms/orig/10/10991/<hash>.jpg
https://cdn.unit.travel/f/photos/rooms/preview_panel/10/10991/<hash>.jpg
https://cdn.unit.travel/f/photos/rooms/small/10/10991/<hash>.jpg
https://cdn.unit.travel/f/photos/rooms/medium/10/10991/<hash>.jpg
https://cdn.unit.travel/f/photos/rooms/big/10/10991/<hash>.jpg

документ или видео с тем же токеном:
https://cdn.unit.travel/f/photos/rooms/10/10991/<hash>.pdf
```

Без `path` вместо `10/10991` используется шардинг по хешу (`ab/cd`). Чтобы
адреса начинались, как сейчас, с `/static/`: `UPLOADER_STORAGE_DIR=/srv/unit-cdn/static`,
`UPLOADER_CDN_BASE_URL=https://cdn.unit.travel/static`, а в `deploy/nginx.conf`
замените `/f/` на `/static/` в `location /f/` и в двух `location ~ "^/f/…"` под ним
(`root /srv/unit-cdn;` остаётся). Ключи в ответе по-прежнему начинаются с `f/`.

В ответе загрузки (и в `UnitUploader.open()`) у фото появляются размеры и
`variants`; у каждого размера свой `key`, `url` и `receipt`:

```json
{"key": "f/photos/rooms/orig/10/10991/382a…bd8a.jpg", "url": "https://cdn.unit.travel/f/photos/rooms/orig/…",
 "folder": "photos/rooms", "width": 1600, "height": 1200, "receipt": "…",
 "variants": {
   "orig":          {"key": "f/photos/rooms/orig/10/10991/382a…bd8a.jpg",          "url": "…", "width": 1600, "height": 1200, "receipt": "…"},
   "preview_panel": {"key": "f/photos/rooms/preview_panel/10/10991/382a…bd8a.jpg", "url": "…", "width": 100,  "height": 75,   "receipt": "…"},
   "small":         {"key": "f/photos/rooms/small/10/10991/382a…bd8a.jpg",         "url": "…", "width": 70,   "height": 53,   "receipt": "…"},
   "medium":        {"key": "f/photos/rooms/medium/10/10991/382a…bd8a.jpg",        "url": "…", "width": 350,  "height": 263,  "receipt": "…"},
   "big":           {"key": "f/photos/rooms/big/10/10991/382a…bd8a.jpg",           "url": "…", "width": 1067, "height": 800,  "receipt": "…"}}}
```

Уровни доступа работают и для размеров: при `access: "private"` ключи
выглядят как `p/<клиент>/passports/small/…`, и каждый открывается только по
токену чтения (в токен можно перечислить нужные размеры).

Настройки размеров входят в хеш имени: если поменять пресет (например,
`small` станет 100 px), новые загрузки получат новые имена, а старые файлы
останутся как были — под одним именем никогда не окажутся разные «small».
Замер: фото 7000×5000 (35 Мп) — около 3 с на очистку оригинала и ещё ~1,3 с
на четыре размера.

## White label: сайты на других доменах

Окно работает на любом домене, не только на `*.unit.travel`: это iframe с
`cdn.unit.travel` (или с вашего домена файлов, см. ниже), связь со страницей —
через `postMessage`, авторизация — токеном в заголовке. Окно не использует
cookies и localStorage, поэтому блокировка сторонних cookies (Safari ITP,
Chrome) на него не влияет. Проверено сквозным тестом: страница на одном сайте,
CDN — на другом.

### Один общий клиент для всех сайтов

Все white-label сайты подключаются к одному клиенту: у него один секрет,
который хранится только на вашем общем бэкенде, а каждый сайт описывается в
`sites` — своими доменами и, по желанию, своим доменом файлов:

```json
{
  "b2c": {
    "secret": "…",
    "origins": ["https://admin.unit.travel"],
    "corp": "cross-origin",
    "sites": {
      "brand-a": {"origins": ["https://brand-a.com", "https://www.brand-a.com"],
                  "cdn_base_url": "https://files.brand-a.com/f",
                  "protected_base_url": "https://files.brand-a.com"},
      "brand-b": {"origins": ["https://*.brand-b.travel"], "default_access": "private"}
    }
  }
}
```

* `origins` сайта обязательны: точный домен или маска для поддоменов
  (`https://*.brand-b.travel`; сам `brand-b.travel` под маску не попадает).
* Остальное необязательно и переопределяет значения клиента для этого сайта:
  `default_access`, `corp`, `cdn_base_url`, `protected_base_url`.
* `origins` самого клиента (вне `sites`) — ваши собственные страницы, например
  админка. Если их нет, поле можно не указывать.
* id сайта — латиница, цифры, `_` и `-`, до 32 символов. Он попадает в ключи
  файлов, поэтому однажды выбранный id не меняйте.

Бэкенд выпускает токены как обычно: `cid` — общий клиент (`b2c`), `origin` —
домен сайта, на котором открыто окно. Origin берите из настроек тенанта (или
проверяйте заголовок `Origin` по ним), а не из параметров запроса. Указывать
сайт в токене не нужно: CDN сам определяет его по `origin`.

**Сайты изолированы друг от друга.** Защищённые файлы, загруженные на сайте,
получают ключи вида `p/b2c.brand-a/…` или `s/b2c.brand-a/…`, а сайт входит и в
хеш имени. Такой файл открывается только на своём сайте:

* токен чтения с origin другого сайта получает `403`, даже если ключ в него
  включён. Это страховка от ошибки в общем бэкенде, например если
  пользователь `brand-b` подставит чужой ключ;
* CORS и предзапросы `OPTIONS` разрешаются только доменам этого сайта;
* временная ссылка `signed` открывается где угодно, пока не истекла, — для
  этого она и нужна. Но скрипты другого сайта не могут прочитать её через
  `fetch`: CORS им не разрешён;
* ваши собственные `origins` клиента (админка) видят файлы всех сайтов.
  Файлы, загруженные с них, получают ключи `p/b2c/…` и на сайтах не
  открываются;
* публичные файлы (`f/…`) общие: одинаковый файл хранится один раз, и ссылка
  на него открывается у всех. Так и задумано для публичных файлов.

Каждый домен относится ровно к одному месту. Если один и тот же домен указан
в двух сайтах или и в сайте, и у клиента, это ошибка конфигурации. Из
нескольких подходящих масок выбирается самая точная: `*.vip.brand-b.travel`
сработает раньше `*.brand-b.travel`.

Что общий клиент не разделяет:

* **Секрет у всех сайтов один.** Кто его знает, может выпустить токен для
  любого сайта, поэтому храните его только на общем бэкенде. Если у партнёра
  свой бэкенд, заведите ему отдельный клиент: это та же запись, только
  со своим `secret` и `origins` и без `sites`.
* **`sub` должен быть уникальным во всех сайтах**, например `brand-a:1042`, а
  не просто `1042`. Квитанция подтверждает связку «ключ + `sub`», и у
  публичных файлов ключ на всех сайтах общий.

Клиентов удобно держать в файле (`UPLOADER_CLIENTS_FILE`). Он перечитывается
при изменении раз в `UPLOADER_CLIENTS_RELOAD_SECONDS` (по умолчанию 5 с), так
что новые сайты и домены подключаются без перезапуска. Если в файле ошибка,
она пишется в лог, а сервис продолжает работать со старым списком.

### Что нужно для каждого сайта

1. **Добавить сайт** в `sites` общего клиента (см. выше).
2. **Выдавать токены с `origin` этого сайта.**
3. **`"corp": "cross-origin"`** у клиента или сайта, если файлы `signed`
   показываются в `<img>`/`<video>` на домене не из `*.unit.travel`. Иначе
   браузер заблокирует их (`ERR_BLOCKED_BY_RESPONSE.NotSameSite`). Для
   временных ссылок защитой служит сама подпись.
4. **CSP сайта** должен разрешать `cdn.unit.travel` (или домен файлов сайта):
   `script-src` для SDK, `frame-src` для окна, `img-src`/`media-src` для
   файлов, а также `connect-src` и `img-src blob:` для `private` через
   `UnitUploader.fileUrl`.

Окно разрешает встраивать себя только сайту, который его открыл. SDK
передаёт свой origin, и если он зарегистрирован, сервер отвечает
`frame-ancestors` с этим одним доменом, иначе `'none'`. Поэтому список ваших
партнёров нигде не публикуется, и заголовок не растёт с их числом.

**Оформление под бренд:**

```js
UnitUploader.open({
  …,
  theme: { accent: '#e11d48', accentText: '#ffffff', radius: 4, font: 'Inter', colorScheme: 'auto' },
});
```

* `accent`/`accentText` — hex-цвета.
* `radius` — от 0 до 24 px.
* `font` — имя шрифта. Используются только установленные шрифты: внешние
  веб-шрифты в окно не грузятся.
* `colorScheme` — `light`, `dark` или `auto` (по системе).

Также можно задать `title`, `translations` и язык — см. «Языки».

**Свой домен файлов (необязательно).** Чтобы `unit.travel` нигде не было видно,
партнёр направляет CNAME (например, `files.brand-a.com`) на ваш сервер, а у
сайта задаются `cdn_base_url` и `protected_base_url`, как у `brand-a` выше.
Ссылки в ответах загрузки будут на `files.brand-a.com`, а окно открывается с
`uploaderUrl: 'https://files.brand-a.com/uploader/'`. Ссылки `signed` бэкенд
подписывает с тем же адресом: `sign_link(key, "b2c", SECRET,
"https://files.brand-a.com")`. Хранилище и сервис общие. В nginx домен
добавляется в `server_name`, а сертификат выбирается по имени:

```nginx
map $ssl_server_name $cdn_cert {          # в http {}, рядом с limit_req_zone
    default            cdn.unit.travel;
    files.brand-a.com  files.brand-a.com;
}
# в server {}:
server_name cdn.unit.travel files.brand-a.com;
ssl_certificate     /etc/letsencrypt/live/$cdn_cert/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/$cdn_cert/privkey.pem;
```

Сертификаты выпускаются для каждого домена: `certbot certonly --webroot` или
через DNS-01. Окно и файлы тогда находятся на том же сайте, что и партнёр.

## Языки

Окно поддерживает **английский** (по умолчанию), **украинский** и **русский**:

* язык берётся из `lang`, иначе из `<html lang>` страницы приложения, иначе из
  браузера; `uk-UA` → `uk`, неизвестный язык → английский;
* `UnitUploader.setLang('ru')` — переключение уже открытого окна, когда
  пользователь меняет язык в приложении (все тексты, статусы файлов и ошибки
  перерисовываются, загрузки не прерываются);
* `languages: ['en', 'uk', 'ru']` (или `true`) — меню языка в самом окне;
  выбор приходит в `onLanguageChange(lang)`, чтобы приложение переключилось тоже;
* `title` — строка или `{ en, uk, ru }`;
* `translations` — свои строки или новые языки без изменений на CDN, например
  `{ pl: { langName: 'Polski', title: 'Prześlij pliki', … } }`; всё, что не
  задано, берётся из английского. Постоянно добавить язык — блок в
  `static/uploader/i18n.js`.

Числа и размеры форматируются по локали (`1,5 МБ`, `1.5 MB`). Коды ошибок API
не зависят от языка; переводы — в окне.

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
{"key": "p/booking/5a/31/5a31…1c35.jpg", "access": "private", "url": "https://cdn.unit.travel/p/booking/5a/31/5a31…1c35.jpg",
 "receipt": "Q2xh…", "hash": "5a31…1c35", "name": "Паспорт.jpg", "ext": "jpg", "mime": "image/jpeg",
 "category": "image", "size": 183211, "original_size": 201733}
```

Для `signed` в `url` — временная ссылка и `expires_at`; для `public` — постоянная ссылка.

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

Защищённые файлы:

* `GET /s/<клиент>/…?exp=…&sig=…[&ip=1][&name=…]` — файл `signed` по временной ссылке;
* `GET /p/<клиент>/…` (и `/s/…`) с `Authorization: Bearer <токен чтения>` — файл
  по токену чтения; CORS разрешён только для origin'ов клиента, поддерживаются
  `Range` и `HEAD`;
* для файлов white-label сайта путь — `/s/<клиент>.<сайт>/…` и `/p/<клиент>.<сайт>/…`.

Ошибки: `{"error": "<код>", "message": "<подробности>"}`. На `429` и `503`
сервер присылает `Retry-After` с точным временем ожидания.

| HTTP | Коды |
|---|---|
| 401 / 403 | `invalid_token`, `token_expired`, `origin_not_allowed`; для защищённых файлов `invalid_link`, `link_expired`, `not_allowed` |
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
   sudo mkdir -p /opt/unit-uploader /srv/unit-cdn/f /srv/unit-cdn/protected /srv/unit-cdn/tmp /srv/unit-cdn/nginx-body
   sudo chown unitcdn:unitcdn /srv/unit-cdn/f /srv/unit-cdn/protected /srv/unit-cdn/tmp
   sudo chown www-data:www-data /srv/unit-cdn/nginx-body
   ```

   `tmp` — недогруженные файлы и рабочие файлы ffmpeg; он на том же диске,
   что и хранилище, чтобы готовое видео перемещалось, а не копировалось.
   `protected` — файлы `signed`/`private`: nginx отдаёт их только из
   `internal`-локации после проверки приложением (`UPLOADER_ACCEL_REDIRECT=/_protected/`).

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
* Публичные ссылки неугадываемые, но открываются у любого, у кого они есть.
  Всё персональное загружайте с `access: "private"` или `"signed"`.
* Смена секрета клиента делает недействительными все его временные ссылки,
  токены и квитанции (сами файлы и ключи остаются).

## Структура

```
uploader/
  app.py            HTTP API, заголовки безопасности, раздача UI
  tokens.py         токены загрузки/чтения, временные ссылки, квитанции (+ CLI)
  config.py         настройки из переменных окружения
  storage.py        хранение по HMAC-хешу содержимого
  antivirus.py      клиент clamd
  ratelimit.py      лимиты запросов и файлов по токену
  jobs.py           фоновая обработка видео
  validators/       проверка и очистка каждого типа файлов (video.py — ffmpeg)
static/
  uploader/         модальное окно (HTML/CSS/JS, работает в iframe), i18n.js — переводы
  sdk/              unit-uploader.js для родительского приложения
deploy/             nginx и systemd
examples/           демо родительского приложения
tests/              pytest: валидаторы, видео, токены, доступ, API
```
