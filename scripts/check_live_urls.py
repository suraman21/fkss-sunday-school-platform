#!/usr/bin/env python3
"""Read-only live check: do key URLs still answer as expected?

Run it before and after any folder or server change, against dev first and
production only when the change has reached production:

    python3 scripts/check_live_urls.py --server dev
    python3 scripts/check_live_urls.py --server production
    python3 scripts/check_live_urls.py --server dev --json   # machine output

Safety:
* Only GET requests, never logs in, never sends data, never follows redirects.
* Every request carries a unique cache-busting query (?_fkss_check=...), so a
  CDN such as Cloudflare never stores a copy of a private file that this
  script probes (a plain request can make the CDN cache it for 30 days).
* Only the status code is kept; response bodies are discarded.

Cloudflare 520-525 answers are retried (they are short origin hiccups) and,
if they persist, reported as TRANSIENT, separate from real differences.
Exit code: 0 = all as expected, 1 = real differences, 2 = only transient.
"""
import argparse
import json
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

BASELINE = Path(__file__).with_name("live_urls.json")
TRANSIENT = {520, 521, 522, 523, 524, 525}
RETRIES = 2
UA = "FKSS-live-check/1.0 (+read-only)"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def fetch_status(url, timeout):
    sep = "&" if "?" in url else "?"
    req = urllib.request.Request(
        f"{url}{sep}_fkss_check={uuid.uuid4().hex}",
        headers={"User-Agent": UA, "Cache-Control": "no-cache"}, method="GET")
    try:
        with OPENER.open(req, timeout=timeout) as resp:
            return resp.status
    except urllib.error.HTTPError as e:
        return e.code
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return f"error: {getattr(e, 'reason', e)}"


def expected_for(check, server):
    exp = check["expect"]
    if isinstance(exp, dict):
        exp = exp.get(server, exp.get("default"))
    return exp


def run(server, base, checks, timeout):
    results = []
    for check in checks:
        expect = expected_for(check, server)
        url = base.rstrip("/") + check["path"]
        status = fetch_status(url, timeout)
        tries = 1
        while (status in TRANSIENT or isinstance(status, str)) and tries <= RETRIES:
            time.sleep(2 * tries)
            status = fetch_status(url, timeout)
            tries += 1
        if status in expect:
            verdict = "ok"
        elif status in TRANSIENT or isinstance(status, str):
            verdict = "transient"
        else:
            verdict = "DIFFERENT"
        results.append({"path": check["path"], "group": check["group"], "expect": expect,
                        "status": status, "tries": tries, "verdict": verdict,
                        "why": check["why"]})
    return results


def main():
    data = json.loads(BASELINE.read_text(encoding="utf-8"))
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--server", required=True, choices=sorted(data["servers"]))
    ap.add_argument("--timeout", type=float, default=20)
    ap.add_argument("--json", action="store_true", help="print JSON instead of a table")
    args = ap.parse_args()

    results = run(args.server, data["servers"][args.server], data["checks"], args.timeout)
    if args.json:
        print(json.dumps(results, indent=2))
    else:
        print(f"Live URL check: {args.server} ({data['servers'][args.server]})\n")
        for r in results:
            mark = {"ok": "ok  ", "transient": "WAIT", "DIFFERENT": "FAIL"}[r["verdict"]]
            exp = "/".join(map(str, r["expect"]))
            print(f"  {mark} {str(r['status']):>4} (want {exp:<7}) {r['group']:<9} {r['path']}")
    bad = [r for r in results if r["verdict"] == "DIFFERENT"]
    wait = [r for r in results if r["verdict"] == "transient"]
    print(f"\n{len(results)} checked: {len(results) - len(bad) - len(wait)} ok, "
          f"{len(bad)} different, {len(wait)} transient", file=sys.stderr)
    for r in bad:
        print(f"  DIFFERENT {r['path']}: got {r['status']}, want {r['expect']} ({r['why']})",
              file=sys.stderr)
    return 1 if bad else (2 if wait else 0)


if __name__ == "__main__":
    sys.exit(main())
