#!/usr/bin/env python3
"""Собирает единый self-contained HTML dev-portal (Redoc) из трёх спек
openapi.{ru,en,uk}.yaml с переключателем языка. Результат: dist/index.html."""
import json, os, pathlib, yaml

HERE = pathlib.Path(__file__).parent
LANGS = [("ru", "Русский"), ("en", "English"), ("uk", "Українська")]

specs = {code: yaml.safe_load(open(HERE / f"openapi.{code}.yaml", encoding="utf-8"))
         for code, _ in LANGS}
specs_json = json.dumps(specs, ensure_ascii=False).replace("</", "<\\/")

# Redoc bundle встраивается ВНУТРЬ файла -> портал работает офлайн, без CDN.
redoc_js = (HERE / "vendor" / "redoc.standalone.js").read_text(encoding="utf-8")
redoc_js = redoc_js.replace("</script>", "<\\/script>")

buttons = "\n".join(
    f'      <button class="lang" data-lang="{c}">{name}</button>' for c, name in LANGS
)

HTML = f"""<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unit.Travel Partner Inventory API</title>
<style>
  :root {{ --bar:#0b3d91; --bar2:#0a2f6e; }}
  * {{ box-sizing:border-box; }}
  body {{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }}
  header {{
    position:sticky; top:0; z-index:1000; background:var(--bar); color:#fff;
    display:flex; align-items:center; gap:16px; padding:10px 18px;
    box-shadow:0 2px 8px rgba(0,0,0,.25);
  }}
  header .brand {{ font-weight:700; font-size:16px; letter-spacing:.2px; }}
  header .brand small {{ opacity:.75; font-weight:400; margin-left:8px; }}
  header .spacer {{ flex:1; }}
  header .langs {{ display:flex; gap:6px; }}
  button.lang {{
    border:1px solid rgba(255,255,255,.35); background:transparent; color:#fff;
    padding:6px 12px; border-radius:6px; cursor:pointer; font-size:13px;
  }}
  button.lang:hover {{ background:rgba(255,255,255,.12); }}
  button.lang.active {{ background:#fff; color:var(--bar); border-color:#fff; font-weight:600; }}
  #redoc {{ min-height:calc(100vh - 52px); }}
</style>
</head>
<body>
<header>
  <div class="brand">Unit.Travel <small>Partner Inventory API · v1.0.0</small></div>
  <div class="spacer"></div>
  <div class="langs">
{buttons}
  </div>
</header>
<div id="redoc"></div>

<script>{redoc_js}</script>
<script>
  var SPECS = {specs_json};
  var OPTS = {{
    hideDownloadButton: false,
    expandResponses: "200,202",
    jsonSampleExpandLevel: 3,
    theme: {{ colors: {{ primary: {{ main: "#0b3d91" }} }},
             typography: {{ fontSize: "15px" }} }}
  }};
  function render(lang) {{
    Redoc.init(SPECS[lang], OPTS, document.getElementById("redoc"));
    document.documentElement.lang = lang;
    var btns = document.querySelectorAll("button.lang");
    for (var i=0;i<btns.length;i++)
      btns[i].classList.toggle("active", btns[i].dataset.lang === lang);
    try {{ localStorage.setItem("utapi_lang", lang); }} catch(e) {{}}
  }}
  var initial = "ru";
  try {{ var s = localStorage.getItem("utapi_lang"); if (SPECS[s]) initial = s; }} catch(e) {{}}
  document.querySelectorAll("button.lang").forEach(function(b) {{
    b.addEventListener("click", function() {{ render(this.dataset.lang); }});
  }});
  render(initial);
</script>
</body>
</html>
"""

out = HERE / "dist"
out.mkdir(exist_ok=True)
(out / "index.html").write_text(HTML, encoding="utf-8")
size_kb = round((out / "index.html").stat().st_size / 1024, 1)
print(f"dist/index.html written ({size_kb} KB), languages: {[c for c,_ in LANGS]}")
