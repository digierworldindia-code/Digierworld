# -*- coding: utf-8 -*-
"""Hand-draw the Mithaas cartouche as vector artwork.

This is a DRAWN STAND-IN, not the official file. Every curve here was authored
by hand; nothing was traced from the supplied artwork. Replace it with the real
export as soon as it is available.
"""
import base64, math, os, sys

SP = sys.argv[1]
OUT = sys.argv[2]
FONT = os.path.join(SP, "node_modules/@fontsource/cormorant-garamond/files",
                    "cormorant-garamond-latin-600-normal.woff2")

W, H = 1200, 820
CX = 600.0

# ---------------------------------------------------------------- palette
GOLD_HI, GOLD_MID, GOLD_LO, GOLD_DP = "#F6E6B8", "#D9B463", "#A87E2C", "#7A5A1C"
IVORY, IVORY_SH = "#FBF4E6", "#F1E5CF"
RED, RED_DK, ORANGE, WINE = "#C62828", "#8E1B1B", "#F07C1E", "#7B1C22"


# --------------------------------------------------- path algebra helpers
# A segment is (c1, c2, endpoint); a contour is (start, [segments]).

def mx(pt):   return (2 * CX - pt[0], pt[1])          # mirror across the centre line
def fy(pt):   return (pt[0], 900.0 - pt[1])           # flip across y = 450


def apply(fn, start, segs):
    return fn(start), [tuple(fn(q) for q in seg) for seg in segs]


def reverse(start, segs):
    """Walk a contour backwards: segment order reverses and c1/c2 swap."""
    anchors = [start] + [sg[2] for sg in segs]
    out = []
    for i in range(len(segs) - 1, -1, -1):
        c1, c2, _ = segs[i]
        out.append((c2, c1, anchors[i]))
    return anchors[-1], out


def draw(start, *contours):
    d = ["M%.1f,%.1f" % start]
    for segs in contours:
        for (c1, c2, pt) in segs:
            d.append("C%.1f,%.1f %.1f,%.1f %.1f,%.1f"
                     % (c1[0], c1[1], c2[0], c2[1], pt[0], pt[1]))
    d.append("Z")
    return " ".join(d)


# ---------------------------------------------------------------- cartouche
# Top-left quarter: from the left point up to the centre crest. Everything
# else is this quarter mirrored and flipped, so the mark stays symmetrical.
TL_START = (60.0, 450.0)
TL = [
    ((60, 400),  (86, 372),  (128, 360)),
    ((172, 348), (196, 330), (206, 300)),
    ((219, 262), (259, 239), (311, 248)),
    ((361, 256), (391, 243), (421, 224)),
    ((471, 195), (531, 189), (600, 195)),
]


def cartouche_path():
    # top-right = top-left mirrored, then walked in the opposite direction
    tr = reverse(*apply(mx, TL_START, TL))[1]
    # bottom-right = top-right flipped, walked backwards
    br_start, br = apply(fy, (600.0, 195.0), tr)
    br = reverse(br_start, br)[1]
    # bottom-left = top-left flipped, walked backwards
    bl_start, bl = apply(fy, TL_START, TL)
    bl = reverse(bl_start, bl)[1]
    return draw(TL_START, TL, tr, br, bl)


def ring(cx, cy, r, n, rr, fill):
    """A circle of small beads — the medallion's milled edge."""
    out = []
    for i in range(n):
        a = 2 * math.pi * i / n
        out.append('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>'
                   % (cx + r * math.cos(a), cy + r * math.sin(a), rr, fill))
    return "".join(out)


def scroll(flip=False, sx=1.0, sy=1.0, tx=0.0, ty=0.0):
    """One filigree scroll: a long sweep ending in a tight curl."""
    d = ("M0,0 C40,-4 74,-20 96,-46 C112,-65 108,-88 90,-95 "
         "C74,-101 60,-90 62,-75 C64,-63 76,-58 85,-63")
    t = "translate(%.1f,%.1f) scale(%.3f,%.3f)" % (tx, ty, -sx if flip else sx, sy)
    return ('<path d="%s" transform="%s" fill="none" stroke="url(#gold)" '
            'stroke-width="5" stroke-linecap="round"/>' % (d, t))


def petal(cx, cy, angle, length, width, fill):
    """A single lotus petal, drawn upright then rotated into place."""
    d = ("M0,0 C%.1f,%.1f %.1f,%.1f 0,%.1f C%.1f,%.1f %.1f,%.1f 0,0 Z"
         % (width, -length * 0.32, width * 0.72, -length * 0.78, -length,
            -width * 0.72, -length * 0.78, -width, -length * 0.32))
    return ('<path d="%s" transform="translate(%.1f,%.1f) rotate(%.1f)" fill="%s"/>'
            % (d, cx, cy, angle, fill))


def lotus(cx, cy, scale=1.0):
    p = []
    for ang, ln, wd, col in ((-52, 46, 15, RED_DK), (-26, 58, 17, RED),
                             (0, 68, 18, ORANGE), (26, 58, 17, RED), (52, 46, 15, RED_DK)):
        p.append(petal(0, 0, ang, ln, wd, col))
    return '<g transform="translate(%.1f,%.1f) scale(%.3f)">%s</g>' % (cx, cy, scale, "".join(p))


def fleur(cx, cy, scale=1.0, fill=RED):
    d = ("M0,0 C7,-10 9,-22 0,-34 C-9,-22 -7,-10 0,0 Z")
    side = ("M0,-4 C10,-10 15,-19 14,-28 C6,-26 1,-16 0,-8 Z")
    return ('<g transform="translate(%.1f,%.1f) scale(%.3f)" fill="%s">'
            '<path d="%s"/><path d="%s"/><path d="%s" transform="scale(-1,1)"/></g>'
            % (cx, cy, scale, fill, d, side, side))


font_b64 = base64.b64encode(open(FONT, "rb").read()).decode("ascii")

beads = ring(CX, 196, 122, 76, 3.4, "url(#goldDeep)")

svg = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}"
     role="img" aria-labelledby="t d">
<title id="t">Mithaas — Sweets, Bakery, Restaurant</title>
<desc id="d">Hand-drawn stand-in for the Mithaas cartouche logo. Replace with the official artwork.</desc>
<!-- ============================================================
     DRAWN STAND-IN — not the official Mithaas logo file.
     Every path here was authored by hand. Swap in the real export:
       python3 tools/add-logo.py /path/to/mithaas-logo.png
     ============================================================ -->
<defs>
  <style>
    @font-face {{
      font-family:'CormorantSVG'; font-style:normal; font-weight:600;
      src:url(data:font/woff2;base64,{font_b64}) format('woff2');
    }}
    .wm {{ font-family:'CormorantSVG',Georgia,'Times New Roman',serif; font-weight:600; }}
  </style>

  <linearGradient id="gold" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="{GOLD_HI}"/><stop offset="34%" stop-color="{GOLD_MID}"/>
    <stop offset="62%" stop-color="{GOLD_LO}"/><stop offset="100%" stop-color="{GOLD_HI}"/>
  </linearGradient>
  <linearGradient id="goldDeep" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="{GOLD_MID}"/><stop offset="55%" stop-color="{GOLD_DP}"/>
    <stop offset="100%" stop-color="{GOLD_LO}"/>
  </linearGradient>
  <linearGradient id="word" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#FFF3D0"/><stop offset="26%" stop-color="{GOLD_MID}"/>
    <stop offset="52%" stop-color="{GOLD_DP}"/><stop offset="72%" stop-color="{GOLD_LO}"/>
    <stop offset="100%" stop-color="#F3E0AC"/>
  </linearGradient>
  <linearGradient id="plate" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#FFFDF8"/><stop offset="100%" stop-color="{IVORY_SH}"/>
  </linearGradient>
  <radialGradient id="gem" cx="35%" cy="30%">
    <stop offset="0%" stop-color="#E86A6A"/><stop offset="60%" stop-color="{RED}"/>
    <stop offset="100%" stop-color="{RED_DK}"/>
  </radialGradient>
</defs>

<!-- cartouche plate + double gold frame -->
<g>
  <path d="{cartouche_path()}" fill="url(#plate)" stroke="url(#gold)" stroke-width="11"/>
  <path d="{cartouche_path()}" transform="translate({CX},450) scale(0.962) translate(-{CX},-450)"
        fill="none" stroke="url(#goldDeep)" stroke-width="3.4"/>
  <path d="{cartouche_path()}" transform="translate({CX},450) scale(0.934) translate(-{CX},-450)"
        fill="none" stroke="url(#gold)" stroke-width="1.7" opacity=".85"/>
</g>

<!-- side gems -->
<circle cx="78" cy="450" r="9" fill="url(#gem)"/>
<circle cx="1122" cy="450" r="9" fill="url(#gem)"/>

<!-- medallion -->
<g>
  {beads}
  <circle cx="{CX}" cy="196" r="112" fill="url(#plate)" stroke="url(#gold)" stroke-width="8"/>
  <circle cx="{CX}" cy="196" r="100" fill="none" stroke="url(#goldDeep)" stroke-width="2.2"/>
  {fleur(CX, 150, 1.15)}
  <text class="wm" x="{CX}" y="252" text-anchor="middle" font-size="150"
        fill="url(#word)" stroke="url(#goldDeep)" stroke-width="1.2">M</text>
</g>

<!-- filigree flanking the medallion -->
{scroll(True, 1.32, 1.10, 556, 350)}
{scroll(False, 1.32, 1.10, 644, 350)}
{fleur(CX, 388, 1.7, RED)}

<!-- wordmark -->
<text class="wm" x="{CX}" y="536" text-anchor="middle" font-size="205"
      letter-spacing="6" fill="url(#word)" stroke="url(#goldDeep)" stroke-width="1.6">MITHAAS</text>

<!-- swash beneath the wordmark -->
<path d="M232,556 C360,600 470,596 600,566 C730,536 850,532 968,570"
      fill="none" stroke="url(#gold)" stroke-width="7" stroke-linecap="round"/>

<!-- descriptor -->
<line x1="330" y1="592" x2="870" y2="592" stroke="{GOLD_LO}" stroke-width="1" opacity=".55"/>
<text class="wm" x="{CX}" y="630" text-anchor="middle" font-size="36"
      letter-spacing="5.5" fill="{WINE}">SWEETS &#8226; BAKERY &#8226; RESTAURANT</text>

<!-- bottom rule + lotus -->
<line x1="345" y1="704" x2="524" y2="704" stroke="{GOLD_LO}" stroke-width="1.4" opacity=".7"/>
<line x1="676" y1="704" x2="855" y2="704" stroke="{GOLD_LO}" stroke-width="1.4" opacity=".7"/>
{lotus(CX, 710, 0.88)}

<!-- closing filigree -->
{scroll(False, 0.78, 0.58, 520, 786)}
{scroll(True, 0.78, 0.58, 680, 786)}
<circle cx="{CX}" cy="792" r="4.5" fill="url(#goldDeep)"/>
</svg>
"""

open(OUT, "w", encoding="utf-8").write(svg)
print("wrote %s  (%.0f KB)" % (OUT, os.path.getsize(OUT) / 1024))
