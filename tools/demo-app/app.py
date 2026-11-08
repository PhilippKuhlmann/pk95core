"""
PK95 demo app: the smallest app that speaks app protocol v1 (docs/app-protocol.md).
Used to try Core against a real Docker daemon before the real apps have their Core mode.

  GET  /core/health          no auth
  GET  /core/manifest        Bearer PK95_CORE_TOKEN
  POST /core/backup/prepare  Bearer
  GET  /api/items            Bearer
  POST /api/items            Bearer, {"title": "..."}
"""

import hmac
import json
import os
import signal
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = os.environ.get("PK95_CORE_TOKEN", "")
DATA = os.path.join(os.environ.get("DATA_DIR", "/data"), "items.json")
VERSION = os.environ.get("DEMO_VERSION", "1.0.0")

MANIFEST = {
    "id": "demo",
    "name": "Demo-App",
    "version": VERSION,
    "protocol": 1,
    "icon": "list",
    "menu": [{"key": "items", "label": "Einträge", "icon": "list"}],
    "capabilities": ["backup"],
    "events": ["item.created"],
}


def load():
    try:
        with open(DATA, encoding="utf-8") as f:
            return json.load(f)
    except FileNotFoundError:
        return [{"id": 1, "title": "Hallo aus der Demo-App"}]


def save(items):
    os.makedirs(os.path.dirname(DATA), exist_ok=True)
    with open(DATA, "w", encoding="utf-8") as f:
        json.dump(items, f, ensure_ascii=False)


class Handler(BaseHTTPRequestHandler):
    def send(self, status, body=None):
        raw = b"" if body is None else json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def authorized(self):
        header = self.headers.get("Authorization", "")
        given = header[7:] if header.startswith("Bearer ") else ""
        if TOKEN and hmac.compare_digest(given.encode(), TOKEN.encode()):
            return True
        self.send(401, {"error": "unauthorized"})
        return False

    def do_GET(self):
        if self.path == "/core/health":
            return self.send(200, {"ok": True})
        if not self.authorized():
            return None
        if self.path == "/core/manifest":
            return self.send(200, MANIFEST)
        if self.path == "/api/items":
            return self.send(200, load())
        return self.send(404, {"error": "not found"})

    def do_POST(self):
        if not self.authorized():
            return None
        if self.path == "/core/backup/prepare":
            return self.send(204)
        if self.path == "/api/items":
            length = int(self.headers.get("Content-Length") or 0)
            body = json.loads(self.rfile.read(length) or b"{}")
            items = load()
            item = {"id": max([i["id"] for i in items] or [0]) + 1, "title": str(body.get("title", "")).strip()}
            items.append(item)
            save(items)
            return self.send(201, item)
        return self.send(404, {"error": "not found"})

    def log_message(self, fmt, *args):
        print("%s %s" % (self.address_string(), fmt % args), flush=True)


if __name__ == "__main__":
    # PID 1 in the container: without a handler "docker stop" waits for the timeout and kills.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    print(f"demo app {VERSION} listening on :8080 (core mode: {os.environ.get('PK95_CORE_MODE') == '1'})", flush=True)
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
