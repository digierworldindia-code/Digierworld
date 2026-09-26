#!/usr/bin/env python3
"""Run the Mithaas website on your own machine.

    python3 serve.py            ->  http://localhost:8000
    python3 serve.py 3000       ->  http://localhost:3000

The site is PHP now, so this starts PHP's own built-in web server. You need
PHP 8 installed:

    Ubuntu / Debian   sudo apt install php-cli
    macOS             brew install php
    Windows           https://windows.php.net/download

Press Ctrl+C to stop.
"""
import os
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))


def main():
    port = 8000
    if len(sys.argv) > 1:
        try:
            port = int(sys.argv[1])
        except ValueError:
            sys.exit("Usage: python3 serve.py [port]")

    php = shutil.which("php")
    if not php:
        sys.exit(
            "PHP was not found on this machine.\n\n"
            "The website needs PHP to run, because the pages share one header\n"
            "and footer through PHP includes. Install it with:\n\n"
            "    Ubuntu / Debian   sudo apt install php-cli\n"
            "    macOS             brew install php\n"
            "    Windows           https://windows.php.net/download\n\n"
            "Just want to look at the design? Open mithaas-offline.html — that\n"
            "is the whole site in one file and needs nothing installed."
        )

    print("\n  MITHAAS — running locally")
    print("  " + "-" * 38)
    print("  Local:    http://localhost:%d" % port)
    print("\n  Serving:  %s" % ROOT)
    print("  Stop:     Ctrl+C\n")

    try:
        subprocess.run([php, "-S", "localhost:%d" % port, "-t", ROOT], cwd=ROOT)
    except KeyboardInterrupt:
        print("\n  Stopped.\n")


if __name__ == "__main__":
    main()
