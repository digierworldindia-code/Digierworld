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
import os
import shutil
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(os.path.dirname(HERE), "assets", "img", "brand")
IVORY = (251, 247, 239)


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

    dest = os.path.join(BRAND, "mithaas-logo" + (".svg" if ext == ".svg" else ".png"))
    shutil.copyfile(src, dest)
    print("  logo      -> %s" % os.path.relpath(dest, os.path.dirname(HERE)))

    if ext == ".svg":
        print("\n  SVG copied. Update the three <img src> values in the pages from\n"
              "  mithaas-logo.png to mithaas-logo.svg, then generate the icons by hand.")
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
