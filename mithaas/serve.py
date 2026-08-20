#!/usr/bin/env python3
"""Run the Mithaas website on your own machine.

    python3 serve.py            ->  http://localhost:8000
    python3 serve.py 3000       ->  http://localhost:3000

No dependencies — this uses only the Python standard library. Serves from this
folder regardless of where you run it from, sends a proper 404.html, and
disables caching so a refresh always shows your latest edit.

Press Ctrl+C to stop.
"""
import functools
import http.server
import os
import socket
import socketserver
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))


class Handler(http.server.SimpleHTTPRequestHandler):
    extensions_map = {
        **http.server.SimpleHTTPRequestHandler.extensions_map,
        ".js": "text/javascript",
        ".mjs": "text/javascript",
        ".json": "application/json",
        ".webp": "image/webp",
        ".avif": "image/avif",
        ".svg": "image/svg+xml",
        ".woff2": "font/woff2",
        ".woff": "font/woff",
    }

    def end_headers(self):
        # Always serve fresh files while editing.
        self.send_header("Cache-Control", "no-store, max-age=0")
        super().end_headers()

    def send_error(self, code, message=None, explain=None):
        # Use the site's own 404 page rather than the stock Python one.
        if code == 404:
            page = os.path.join(ROOT, "404.html")
            if os.path.exists(page):
                body = open(page, "rb").read()
                self.send_response(404)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(body)
                return
        super().send_error(code, message, explain)

    def log_message(self, fmt, *args):
        # Quiet the per-request noise; missing photographs are expected until
        # real images are added to assets/img/.
        status = str(args[1]) if len(args) > 1 else ""
        if status.startswith("4") or status.startswith("5"):
            return
        sys.stderr.write("  %s\n" % (fmt % args))


class Server(socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main():
    port = 8000
    if len(sys.argv) > 1:
        try:
            port = int(sys.argv[1])
        except ValueError:
            sys.exit("Usage: python3 serve.py [port]")

    handler = functools.partial(Handler, directory=ROOT)

    try:
        httpd = Server(("", port), handler)
    except OSError as err:
        if getattr(err, "errno", None) in (48, 98):  # address already in use
            sys.exit(
                "Port %d is already in use.\n"
                "Try another one:  python3 serve.py %d" % (port, port + 1)
            )
        raise

    host = socket.gethostname()
    print("\n  MITHAAS — running locally")
    print("  " + "-" * 38)
    print("  Local:    http://localhost:%d" % port)
    try:
        print("  Network:  http://%s:%d" % (socket.gethostbyname(host), port))
    except OSError:
        pass
    print("\n  Serving:  %s" % ROOT)
    print("  Stop:     Ctrl+C\n")

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\n  Stopped.\n")
    finally:
        httpd.server_close()


if __name__ == "__main__":
    main()
