#!/usr/bin/env python3
"""Bundle the whole Mithaas site into one self-contained HTML file.

Produces `mithaas-offline.html` — all 11 pages behind a small hash router,
with Bootstrap, the icons and both typefaces embedded. It makes zero network
requests, so it opens by double-click on any machine, online or not. Useful for
showing the design to a client, or for a USB stick / email attachment.

    cd mithaas
    npm install bootstrap@5.3.3 bootstrap-icons@1.11.3 \
                @fontsource/cormorant-garamond @fontsource/jost
    python3 tools/build-standalone.py

    python3 tools/build-standalone.py --cdn    # keep Bootstrap/fonts on the CDN
                                               # (smaller file, needs internet)

Re-run it after adding the logo or real photographs and they are baked in.
Note: images are referenced from assets/img/ as usual, so keep that folder next
to the output file if you want photographs to appear.
"""
import base64
import io
import os
import re
import sys
from urllib.parse import quote

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.dirname(HERE)
OFFLINE = "--cdn" not in sys.argv

PAGES = ["index", "our-story", "sweets", "bakery", "restaurant", "menu",
         "gallery", "contact", "privacy-policy", "terms", "404"]
ICONS = ["arrow-right", "chevron-left", "chevron-right", "facebook", "instagram",
         "journal-text", "search", "telephone", "whatsapp", "x-lg"]

FONTS = ([("Cormorant Garamond",
           "@fontsource/cormorant-garamond/files/cormorant-garamond-latin-%d-%s.woff2", w, st)
          for w in (300, 400) for st in ("normal", "italic")]
         + [("Jost", "@fontsource/jost/files/jost-latin-%d-%s.woff2", w, "normal")
            for w in (300, 400, 500)])


def find_modules():
    for cand in (os.path.join(SITE, "node_modules"),
                 os.path.join(os.path.dirname(SITE), "node_modules"),
                 os.path.join(os.getcwd(), "node_modules")):
        if os.path.isdir(cand):
            return cand
    sys.exit("node_modules not found. Run the npm install shown at the top of this file.")


NM = find_modules()


def read(*parts):
    path = os.path.join(*parts)
    if not os.path.exists(path):
        sys.exit("missing file: " + path)
    return io.open(path, encoding="utf-8").read()


def icon_css():
    """Replace the 130 KB icon webfont with masks for the ten icons in use."""
    out = [".bi{display:inline-block;width:1em;height:1em;vertical-align:-.125em;"
           "background-color:currentColor;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;"
           "-webkit-mask-position:center;mask-position:center;-webkit-mask-size:contain;"
           "mask-size:contain;-webkit-mask-image:var(--bi);mask-image:var(--bi);flex:0 0 auto}"]
    for name in ICONS:
        svg = read(NM, "bootstrap-icons/icons/%s.svg" % name)
        svg = re.sub(r'\s+class="[^"]*"', "", svg)
        svg = re.sub(r"\s*\n\s*", "", svg).strip()
        out.append('.bi-%s{--bi:url("data:image/svg+xml,%s")}' % (name, quote(svg, safe="")))
    return "\n".join(out)


def font_css():
    faces = []
    for family, pattern, weight, style in FONTS:
        suffix = "italic" if style == "italic" else "normal"
        path = os.path.join(NM, pattern % (weight, suffix))
        if not os.path.exists(path):
            sys.exit("missing font: " + path)
        b64 = base64.b64encode(open(path, "rb").read()).decode("ascii")
        faces.append("@font-face{font-family:'%s';font-style:%s;font-weight:%d;"
                     "font-display:swap;src:url(data:font/woff2;base64,%s) format('woff2')}"
                     % (family, style, weight, b64))
    return "\n".join(faces)


def rewrite_links(s):
    def sub(m):
        page, frag = m.group(1), m.group(2) or ""
        return 'href="#/%s%s"' % (page, ("/" + frag[1:]) if frag else "")
    return re.sub(r'href="([a-z0-9-]+)\.html(#[a-z-]+)?"', sub, s)


def page_body(slug):
    html = read(SITE, slug + ".html")
    i = html.index('<nav class="actionbar"')
    j = html.index("</nav>", i) + len("</nav>")
    k = html.index('<footer class="footer">')
    # Only one element may own id="main" once all pages share a document.
    return html[j:k].replace('<main id="main">', "<main>").strip()


home = read(SITE, "index.html")
chrome = rewrite_links(home[home.index('<header class="nav-mithaas"'):
                            home.index("</nav>", home.index('<nav class="actionbar"')) + 6])
footer = rewrite_links(home[home.index('<footer class="footer">'):
                            home.index("</footer>") + len("</footer>")])

doc = ['<meta charset="utf-8">',
       "<title>Mithaas</title>",
       '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">']

if OFFLINE:
    doc.append("<style>\n%s\n</style>" % font_css())
else:
    doc.append('<link rel="preconnect" href="https://fonts.googleapis.com">')
    doc.append('<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>')
    doc.append('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
               "family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400"
               '&family=Jost:wght@300;400;500&display=swap">')

doc.append("<style>\n%s\n</style>" % read(NM, "bootstrap/dist/css/bootstrap.min.css"))
doc.append("<style>\n%s\n</style>" % icon_css())
doc.append("<style>\n%s\n</style>" % read(SITE, "assets/css/mithaas.css"))
doc.append("<style>html,body{background:var(--ivory)}.pv-view[hidden]{display:none!important}</style>")
doc.append('<a class="skip-link" href="#main">Skip to content</a>')
doc.append(chrome)
doc.append('<div id="main">\n%s\n</div>' % "\n".join(
    '<div class="pv-view" data-view="%s" hidden>\n%s\n</div>' % (s, rewrite_links(page_body(s)))
    for s in PAGES))
doc.append(footer)
doc.append("<script>\n%s\n</script>" % read(NM, "bootstrap/dist/js/bootstrap.bundle.min.js"))
for js in ("config.js", "menu-data.js", "mithaas.js"):
    doc.append("<script>\n%s\n</script>" % read(SITE, "assets/js", js))

doc.append("""<script>
(function () {
  var views = {}, order = [];
  document.querySelectorAll('.pv-view').forEach(function (v) {
    views[v.dataset.view] = v; order.push(v.dataset.view);
  });
  function show(name, frag) {
    if (!views[name]) name = '404';
    order.forEach(function (k) { views[k].hidden = (k !== name); });
    var want = name === 'index' ? 'index.html' : name + '.html';
    document.querySelectorAll('[data-navlink]').forEach(function (a) {
      if (a.getAttribute('data-navlink') === want) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
    window.scrollTo(0, 0);
    syncHero();
    if (frag) {
      var chip = document.querySelector('[data-menu-chips] .chip[data-cat="' + frag + '"]');
      if (chip) {
        chip.click();
        setTimeout(function () {
          var el = document.getElementById(frag);
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 120);
      }
    }
    setTimeout(function () {
      views[name].querySelectorAll('[data-reveal]').forEach(function (el) {
        if (el.getBoundingClientRect().top < window.innerHeight * 1.1) el.classList.add('is-in');
      });
    }, 60);
  }
  /* The site captures the first .hero at boot; with every page in one document
     that would always be the homepage's. Recompute against the visible one. */
  function syncHero() {
    var nav = document.querySelector('.nav-mithaas');
    if (!nav) return;
    var active = document.querySelector('.pv-view:not([hidden])');
    var hero = active && active.querySelector('.hero');
    var y = window.scrollY || 0;
    nav.dataset.overDark = (hero && y < hero.offsetHeight - 120) ? 'true' : 'false';
    nav.dataset.solid = y > 24 ? 'true' : 'false';
  }
  window.addEventListener('scroll', syncHero, { passive: true });
  function route() {
    var bits = (location.hash || '#/index').replace(/^#\\//, '').split('/');
    show(bits[0] || 'index', bits[1] || '');
  }
  window.addEventListener('hashchange', route);
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href^="#/"]');
    if (!a) return;
    var oc = a.closest('.offcanvas');
    if (oc && window.bootstrap) {
      var inst = bootstrap.Offcanvas.getInstance(oc);
      if (inst) inst.hide();
    }
  });
  route();
})();
</script>""")

out = "\n".join(doc)
dest = os.path.join(SITE, "mithaas-offline.html")
io.open(dest, "w", encoding="utf-8").write(out)
print("Wrote %s  (%.0f KB, %d pages, fonts embedded: %s)"
      % (dest, len(out.encode("utf-8")) / 1024, len(PAGES), OFFLINE))
