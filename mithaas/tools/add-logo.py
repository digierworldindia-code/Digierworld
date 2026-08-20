#!/usr/bin/env python3
"""Install the official Mithaas logo and generate the icons derived from it.

    python3 tools/add-logo.py ~/Downloads/mithaas-logo.png

Copies your file to assets/img/brand/mithaas-logo.png — the path every page
references — and, if Pillow is installed, also writes the favicon, the
apple-touch icon and the social share card from that same artwork.

The logo itself is never redrawn or altered; the derived icons are straight
scales of the file you supply, padded onto the brand ivory where a square or
landscape frame is needed.

    pip install pillow      # only needed for the derived icons
"""
import glob
import os
import re
import shutil
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(os.path.dirname(HERE), "assets", "img", "brand")
IVORY = (251, 247, 239)


def repoint(ext):
    """Point every page at the logo file that is actually present."""
    site = os.path.dirname(HERE)
    pat = re.compile(r"assets/img/brand/mithaas-logo\.(png|svg|jpg|jpeg|webp)")
    changed = 0
    for page in sorted(glob.glob(os.path.join(site, "*.html"))):
        s = open(page, encoding="utf-8").read()
        new = pat.sub("assets/img/brand/mithaas-logo" + ext, s)
        if new != s:
            open(page, "w", encoding="utf-8").write(new)
            changed += 1
    if changed:
        print("  pages     -> repointed %d HTML file(s) to mithaas-logo%s" % (changed, ext))


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    src = os.path.expanduser(sys.argv[1])
    if not os.path.isfile(src):
        sys.exit("No such file: " + src)

    os.makedirs(BRAND, exist_ok=True)
    ext = os.path.splitext(src)[1].lower()
    if ext not in (".png", ".svg", ".jpg", ".jpeg", ".webp"):
        sys.exit("Expected a .png, .svg, .jpg or .webp — got " + (ext or "no extension"))

    keep = ".svg" if ext == ".svg" else ".png"
    dest = os.path.join(BRAND, "mithaas-logo" + keep)
    shutil.copyfile(src, dest)
    print("  logo      -> %s" % os.path.relpath(dest, os.path.dirname(HERE)))

    # Remove any previous logo in a different format, then repoint the pages,
    # so swapping formats never leaves the site referencing a stale file.
    for old in glob.glob(os.path.join(BRAND, "mithaas-logo.*")):
        if os.path.abspath(old) != os.path.abspath(dest):
            os.remove(old)
            print("  removed   -> %s" % os.path.relpath(old, os.path.dirname(HERE)))
    repoint(keep)

    if ext == ".svg":
        print("\n  SVG installed. Icons are not derived from SVG here — export\n"
              "  favicon.png (512), apple-touch-icon.png (180) and og-mithaas.jpg\n"
              "  (1200x630) from the same artwork, or supply a PNG instead.")
        return

    try:
        from PIL import Image
    except ImportError:
        print("\n  Pillow not installed, so the favicon / touch icon / share card were\n"
              "  skipped. Either run `pip install pillow` and try again, or export\n"
              "  these three by hand from the same artwork:\n"
              "    brand/favicon.png          512 x 512\n"
              "    brand/apple-touch-icon.png 180 x 180\n"
              "    brand/og-mithaas.jpg      1200 x 630")
        return

    logo = Image.open(dest).convert("RGBA")

    def fit(size, out, fmt=None, pad=0.86):
        canvas = Image.new("RGBA", size, IVORY + (255,))
        box = (int(size[0] * pad), int(size[1] * pad))
        art = logo.copy()
        art.thumbnail(box, Image.LANCZOS)
        canvas.paste(art, ((size[0] - art.width) // 2, (size[1] - art.height) // 2), art)
        path = os.path.join(BRAND, out)
        if fmt == "JPEG":
            canvas.convert("RGB").save(path, "JPEG", quality=92, optimize=True)
        else:
            canvas.save(path, "PNG", optimize=True)
        print("  %-9s -> %s  (%dx%d)" % (out.split(".")[0], os.path.relpath(path, os.path.dirname(HERE)), *size))

    fit((512, 512), "favicon.png")
    fit((180, 180), "apple-touch-icon.png")
    fit((1200, 630), "og-mithaas.jpg", "JPEG", pad=0.62)

    print("\n  Done. Reload the site — the logo replaces the dashed placeholder\n"
          "  in the header, the hero and the footer automatically.")


if __name__ == "__main__":
    main()
