#!/usr/bin/env python3
"""
Scrapes all routes Wizz Air operates by driving the real flight-search form
on wizzair.com with a headless browser (Playwright) and capturing the
route-network data the page loads to power the "From" / "To" pickers.

Wizz Air does not publish a stable public API, and the internal endpoint it
uses (something under be.wizzair.com/<version>/Api/...) changes version
periodically. So instead of hardcoding a URL, this script:

  1. Opens the search form and listens to every XHR/fetch response.
  2. Heuristically recognizes a response shaped like a route graph
     (a list of airports, each with a list of connected airports) no
     matter what the endpoint/field names currently are.
  3. Falls back to literally clicking through the "From" dropdown for
     every origin airport and reading the "To" dropdown it populates,
     if no such network response was seen.

Install:
    pip install playwright
    playwright install chromium

Usage:
    python wizzair_route_scraper.py --out-csv routes.csv --out-json routes.json
    python wizzair_route_scraper.py --no-headless --debug   # watch it work / troubleshoot
    python wizzair_route_scraper.py --from-json captured.json  # re-parse a saved payload

If Wizz Air changes their markup and the DOM fallback stops finding
elements, run with --no-headless --debug: it saves a screenshot and the
page HTML to ./debug/ on failure so the selectors in list_dropdown_texts()
can be adjusted.
"""

import argparse
import csv
import json
import re
import sys
import time
from pathlib import Path

IATA_RE = re.compile(r"^[A-Z]{3}$")


def looks_like_route_graph(payload):
    """Return the list of airport records if `payload` looks like a Wizz Air
    route graph (each record has an IATA code and a list of connections)."""
    if isinstance(payload, list):
        items = payload
    elif isinstance(payload, dict):
        items = None
        for key in ("cities", "stations", "airports", "items", "data"):
            if isinstance(payload.get(key), list):
                items = payload[key]
                break
        if items is None:
            return None
    else:
        return None

    sample = [i for i in items if isinstance(i, dict)][:5]
    if len(sample) < 2:
        return None

    for item in sample:
        if _iata_of(item) is None or _connections_of(item) is None:
            return None
    return items


def _iata_of(item):
    for key in ("iata", "code", "stationCode", "iataCode"):
        val = item.get(key)
        if val and IATA_RE.match(str(val).upper()):
            return str(val).upper()
    return None


def _connections_of(item):
    for key in ("connections", "destinations", "routes", "connectedStations", "connected"):
        val = item.get(key)
        if isinstance(val, list):
            return val
    return None


def _name_of(item):
    for key in ("name", "shortName", "cityName", "city"):
        val = item.get(key)
        if val:
            return str(val)
    return ""


def extract_routes(items):
    """Turn a route-graph item list into {(origin_iata, dest_iata): (origin_name, dest_name)}."""
    names = {}
    for item in items:
        iata = _iata_of(item)
        if iata:
            names[iata] = _name_of(item)

    routes = {}
    for item in items:
        origin = _iata_of(item)
        conns = _connections_of(item)
        if not origin or not conns:
            continue
        for c in conns:
            if isinstance(c, dict):
                dest = _iata_of(c)
                dest_name = _name_of(c) or names.get(dest, "")
            else:
                dest = str(c).upper() if IATA_RE.match(str(c).upper()) else None
                dest_name = names.get(dest, "")
            if dest and dest != origin:
                routes[(origin, dest)] = (names.get(origin, ""), dest_name)
    return routes


def sniff_route_graph(page, log):
    """Attach a response listener that keeps the first payload that looks
    like a full route graph."""
    found = {}

    def on_response(response):
        if found:
            return
        ct = response.headers.get("content-type", "")
        if "json" not in ct:
            return
        try:
            data = response.json()
        except Exception:
            return
        items = looks_like_route_graph(data)
        if items:
            found["items"] = items
            found["url"] = response.url
            log(f"Captured route graph from: {response.url}")

    page.on("response", on_response)
    return found


def accept_cookies(page):
    for text in ("Accept All Cookies", "Accept all", "I accept", "Accept", "Agree"):
        try:
            btn = page.get_by_role("button", name=re.compile(text, re.I))
            if btn.count():
                btn.first.click(timeout=3000)
                return True
        except Exception:
            continue
    return False


FROM_FIELD_SELECTORS = [
    "[data-test*='origin' i]",
    "[data-test*='departure' i]",
    "[data-test*='from' i]",
    "input[placeholder*='From' i]",
    "input[placeholder*='leaving' i]",
]

TO_FIELD_SELECTORS = [
    "[data-test*='destination' i]",
    "[data-test*='arrival' i]",
    "[data-test*='to' i]",
    "input[placeholder*='To' i]",
    "input[placeholder*='going' i]",
]

OPTION_LIST_SELECTORS = [
    "[role='option']",
    "[data-test*='airport-item' i]",
    "[class*='airport'] li",
    "ul[class*='list'] li",
]


def click_first_match(page, selectors, timeout=4000):
    for sel in selectors:
        loc = page.locator(sel)
        try:
            if loc.count():
                loc.first.click(timeout=timeout)
                return True
        except Exception:
            continue
    return False


def list_dropdown_texts(page, timeout=8000):
    for sel in OPTION_LIST_SELECTORS:
        loc = page.locator(sel)
        try:
            loc.first.wait_for(timeout=timeout)
        except Exception:
            continue
        texts = loc.all_inner_texts()
        if texts:
            return texts
    return []


def parse_iata_from_texts(texts):
    codes = []
    seen = set()
    for t in texts:
        m = re.search(r"\(([A-Z]{3})\)|\b([A-Z]{3})\b", t)
        if not m:
            continue
        code = m.group(1) or m.group(2)
        if code not in seen:
            seen.add(code)
            codes.append(code)
    return codes


def scrape_via_dom(page, log, delay=1.0, max_origins=None, debug_dir=None):
    """Fallback: click through the From/To pickers for every origin airport."""
    if not click_first_match(page, FROM_FIELD_SELECTORS):
        raise RuntimeError("Could not open the 'From' field — selectors need updating")

    origin_texts = list_dropdown_texts(page)
    origin_codes = parse_iata_from_texts(origin_texts)
    if not origin_codes:
        raise RuntimeError("Could not read origin airport list — selectors need updating")

    if max_origins:
        origin_codes = origin_codes[:max_origins]
    log(f"Found {len(origin_codes)} origin airports; scraping destinations for each")

    routes = {}
    for i, origin in enumerate(origin_codes, 1):
        log(f"[{i}/{len(origin_codes)}] {origin}")
        try:
            click_first_match(page, FROM_FIELD_SELECTORS)
            page.get_by_text(re.compile(rf"\({origin}\)|^{origin}$")).first.click(timeout=5000)
            page.wait_for_timeout(300)
            click_first_match(page, TO_FIELD_SELECTORS)
            dest_texts = list_dropdown_texts(page)
            dest_codes = parse_iata_from_texts(dest_texts)
            for dest in dest_codes:
                if dest != origin:
                    routes[(origin, dest)] = ("", "")
        except Exception as e:
            log(f"  skipped {origin}: {e}")
            if debug_dir:
                Path(debug_dir).mkdir(parents=True, exist_ok=True)
                page.screenshot(path=str(Path(debug_dir) / f"error_{origin}.png"))
        time.sleep(delay)
    return routes


def run(args):
    def log(msg):
        if not args.quiet:
            print(msg, file=sys.stderr)

    if args.from_json:
        data = json.loads(Path(args.from_json).read_text())
        items = looks_like_route_graph(data)
        if not items:
            sys.exit("The provided JSON doesn't look like a route graph")
        return extract_routes(items)

    from playwright.sync_api import sync_playwright

    routes = {}
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not args.no_headless)
        page = browser.new_page(locale=args.locale.replace("-", "_"))
        found = sniff_route_graph(page, log)

        url = f"https://wizzair.com/{args.locale}/booking/select-flight/"
        log(f"Navigating to {url}")
        page.goto(url, wait_until="domcontentloaded", timeout=60000)
        accept_cookies(page)

        try:
            click_first_match(page, FROM_FIELD_SELECTORS)
        except Exception:
            pass
        page.wait_for_timeout(3000)

        if found:
            routes = extract_routes(found["items"])
            log(f"Extracted {len(routes)} routes from captured network response")
        else:
            log("No route-graph network response captured; falling back to DOM scraping")
            try:
                routes = scrape_via_dom(
                    page, log,
                    delay=args.delay,
                    max_origins=args.max_origins,
                    debug_dir="debug" if args.debug else None,
                )
            except Exception as e:
                if args.debug:
                    Path("debug").mkdir(exist_ok=True)
                    page.screenshot(path="debug/failure.png")
                    Path("debug/page.html").write_text(page.content())
                    log("Saved debug/failure.png and debug/page.html")
                sys.exit(f"Scraping failed: {e}")

        browser.close()

    return routes


def write_outputs(routes, out_csv, out_json):
    if out_csv:
        with open(out_csv, "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["origin_iata", "origin_name", "dest_iata", "dest_name"])
            for (origin, dest), (oname, dname) in sorted(routes.items()):
                w.writerow([origin, oname, dest, dname])
        print(f"Wrote {len(routes)} routes to {out_csv}", file=sys.stderr)

    if out_json:
        by_origin = {}
        for (origin, dest), (oname, dname) in routes.items():
            by_origin.setdefault(origin, {"name": oname, "destinations": []})
            by_origin[origin]["destinations"].append({"iata": dest, "name": dname})
        for v in by_origin.values():
            v["destinations"].sort(key=lambda d: d["iata"])
        Path(out_json).write_text(json.dumps(by_origin, ensure_ascii=False, indent=2))
        print(f"Wrote route graph to {out_json}", file=sys.stderr)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--locale", default="en-gb", help="Wizz Air site locale, e.g. en-gb, de-de")
    ap.add_argument("--out-csv", default="wizzair_routes.csv")
    ap.add_argument("--out-json", default="wizzair_routes.json")
    ap.add_argument("--from-json", help="Skip the browser and parse a previously saved JSON payload")
    ap.add_argument("--no-headless", action="store_true", help="Show the browser window")
    ap.add_argument("--debug", action="store_true", help="Save screenshots/HTML on failure")
    ap.add_argument("--delay", type=float, default=1.0, help="Delay between origins in DOM fallback (seconds)")
    ap.add_argument("--max-origins", type=int, default=None, help="Limit origins scraped (testing)")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    routes = run(args)
    if not routes:
        sys.exit("No routes found")
    write_outputs(routes, args.out_csv, args.out_json)


if __name__ == "__main__":
    main()
