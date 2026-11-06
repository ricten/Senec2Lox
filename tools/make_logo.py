"""Erzeugt die Logos (SVG + PNG) und die LoxBerry-Plugin-Icons.

Aufruf (aus dem Projektordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift fuer die PNGs: Arial Bold (Windows) bzw. DejaVu Sans Bold.

Ausgabe:
  docs/logo/logo_loxberry.svg|png   gruener LoxBerry-Stil mit Schriftzug Senec2Lox (wird als Plugin-Icon verwendet)
  docs/logo/logo.svg|png            klassisches Logo (dunkelblau)
  senec2lox/icons/icon_{64,128,256,512}.png  = LoxBerry-Stil
"""
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

# ---------------------------------------------------------------- klassisch


def fit_bolt(points, center, scale):
    """Blitz um seine Bounding-Box-Mitte skalieren und auf `center` setzen."""
    xs, ys = [p[0] for p in points], [p[1] for p in points]
    ox, oy = (min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2
    return [(round(center[0] + (x - ox) * scale, 1), round(center[1] + (y - oy) * scale, 1)) for x, y in points]

BG, WHITE, GREEN, YELLOW, BLUE = "#0b3c5d", "#ffffff", "#3ddc84", "#ffd23f", "#7fd1ff"
BOLT = fit_bolt([(205, 232), (138, 306), (180, 306), (165, 360), (240, 282), (198, 282)], (190, 295), 0.7)
ARC_CENTER = (340, 175)
ARC_RADII = (36, 72, 108)
STROKE = 18


def render_classic(size: int) -> Image.Image:
    k = size * SS / 512.0
    img = Image.new("RGBA", (size * SS, size * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    d.rounded_rectangle(r(0, 0, 512, 512), radius=96 * k, fill=BG)
    d.rounded_rectangle(r(60, 200, 320, 390), radius=26 * k, outline=WHITE, width=int(STROKE * k))
    d.rounded_rectangle(r(86, 226, 294, 364), radius=10 * k, fill=GREEN)
    d.rounded_rectangle(r(320, 255, 350, 335), radius=9 * k, fill=WHITE)
    d.polygon([(x * k, y * k) for x, y in BOLT], fill=YELLOW)
    cx, cy = ARC_CENTER
    d.ellipse(r(cx - 14, cy - 14, cx + 14, cy + 14), fill=BLUE)
    w = int(STROKE * k)
    for rad in ARC_RADII:
        o = STROKE / 2
        d.arc(r(cx - rad - o, cy - rad - o, cx + rad + o, cy + rad + o), start=270, end=360, fill=BLUE, width=w)
        for ex, ey in ((cx, cy - rad), (cx + rad, cy)):
            d.ellipse(r(ex - 9, ey - 9, ex + 9, ey + 9), fill=BLUE)
    return img.resize((size, size), Image.LANCZOS)


ARCS = "".join(
    f'    <path d="M{ARC_CENTER[0]} {ARC_CENTER[1] - r} A{r} {r} 0 0 1 {ARC_CENTER[0] + r} {ARC_CENTER[1]}"/>\n'
    for r in ARC_RADII
)

SVG_CLASSIC = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="Senec2Lox">
  <rect width="512" height="512" rx="96" fill="{BG}"/>
  <rect x="69" y="209" width="242" height="172" rx="17" fill="none" stroke="{WHITE}" stroke-width="{STROKE}"/>
  <rect x="86" y="226" width="208" height="138" rx="10" fill="{GREEN}"/>
  <rect x="320" y="255" width="30" height="80" rx="9" fill="{WHITE}"/>
  <polygon points="{' '.join(f'{x},{y}' for x, y in BOLT)}" fill="{YELLOW}"/>
  <circle cx="{ARC_CENTER[0]}" cy="{ARC_CENTER[1]}" r="14" fill="{BLUE}"/>
  <g fill="none" stroke="{BLUE}" stroke-width="{STROKE}" stroke-linecap="round">
{ARCS}  </g>
</svg>
"""

# ---------------------------------------------------------------- LoxBerry-Stil
G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
G_TEXT = "#3f7d10"
# Szene: Speicherschrank (links) -> Miniserver (rechts, schematisch); eigene, vereinfachte Symbole
STORAGE = (118, 118, 196, 268)             # x1, y1, x2, y2  (Speicherschrank)
MINISERVER = (270, 152, 426, 240)          # x1, y1, x2, y2  (gruenes Gehaeuse, Draufsicht)
LB_BOLT = fit_bolt([(222, 168), (184, 214), (208, 214), (196, 254), (244, 200), (219, 200)], (157, 205), 0.55)
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (208, 258)
TERMINALS = 8                              # Schraubklemmen je Reihe


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht) fuer PNG und SVG; eigene, vereinfachte Darstellung ohne Herstellerlogo.

    Gehaeuse mit weissem Rand, dunkler Beschriftungsstreifen, farbige Anschluesse oben (tuerkis, orange)
    und unten links (orange, blau, weiss), rote Klemmleiste unten rechts.
    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))                 # Anschluss oben (tuerkis), weisser Rand
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))               # Anschluss oben (orange)
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):                                    # Anschluesse unten links
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))                 # Klemmleiste unten rechts (rot)
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))                  # Gehaeuse: weisser Rand
    sh.append(("rrect", x1, y1, x2, y2, 0, green))                                  # Gehaeuse: gruen
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))                # Beschriftungsstreifen
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def _rgb(c):
    return f"rgb({c[0]},{c[1]},{c[2]})"


def shapes_svg(shapes):
    out = []
    for sh in shapes:
        kind = sh[0]
        if kind == "rrect":
            _, x1, y1, x2, y2, rad, c = sh
            out.append(f'  <rect x="{x1}" y="{y1}" width="{x2 - x1}" height="{y2 - y1}" rx="{rad}" fill="{_rgb(c)}"/>')
        elif kind == "circle":
            _, cx, cy, r, c = sh
            out.append(f'  <circle cx="{cx}" cy="{cy}" r="{r}" fill="{_rgb(c)}"/>')
        else:
            _, x1, y1, x2, y2, w, c = sh
            out.append(f'  <line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{_rgb(c)}" stroke-width="{w}" stroke-linecap="round"/>')
    return "\n".join(out) + "\n"


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


LABEL = "Senec2Lox"
LABEL_WIDTH = 330      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist (gemessen mit der PNG-Schrift)."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def render_loxberry(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))
    # Speicherschrank: weisser Korpus, LED-Streifen, Blitz, Lueftungsschlitze
    x1, y1, x2, y2 = STORAGE
    d.rounded_rectangle(r(x1, y1, x2, y2), radius=12 * k, fill="white")
    d.rounded_rectangle(r(x1 + 14, y1 + 12, x2 - 14, y1 + 20), radius=4 * k, fill=G_TEXT)
    d.polygon([(x * k, y * k) for x, y in LB_BOLT], fill=G_TEXT)
    for i in range(3):
        yy = y2 - 30 + i * 8
        d.rounded_rectangle(r(x1 + 18, yy, x2 - 18, yy + 3), radius=1.5 * k, fill=(63, 125, 16, 140))
    # Miniserver (Aussenansicht)
    for sh in miniserver_shapes():
        if sh[0] == "rrect":
            _, x1, y1, x2, y2, rad, c = sh
            d.rounded_rectangle(r(x1, y1, x2, y2), radius=rad * k, fill=c)
        elif sh[0] == "circle":
            _, cx, cy, rr, c = sh
            d.ellipse(r(cx - rr, cy - rr, cx + rr, cy + rr), fill=c)
        else:
            _, x1, y1, x2, y2, w, c = sh
            d.line([(x1 * k, y1 * k), (x2 * k, y2 * k)], fill=c, width=max(1, int(w * k)))
    # Verbindung mit Pfeilspitze
    d.line([(LINK_X[0] * k, LINK_Y * k), ((LINK_X[1] - 4) * k, LINK_Y * k)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8) * k, LINK_Y * k), ((LINK_X[1] - 6) * k, (LINK_Y - 11) * k),
               ((LINK_X[1] - 6) * k, (LINK_Y + 11) * k)], fill="white")
    # Beschriftung: "Senec2Lox" in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, LABEL_BASE * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


SVG_LOXBERRY = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="Senec2Lox">
  <defs>
    <radialGradient id="g" cx="0.40" cy="0.34" r="0.9">
      <stop offset="0" stop-color="rgb{G_LIGHT}"/>
      <stop offset="1" stop-color="rgb{G_DARK}"/>
    </radialGradient>
  </defs>
  <circle cx="256" cy="256" r="240" fill="url(#g)"/>
  <ellipse cx="256" cy="137" rx="196" ry="113" fill="#fff" fill-opacity="0.13"/>
  <circle cx="256" cy="256" r="222" fill="none" stroke="#fff" stroke-opacity="0.27" stroke-width="3"/>
  <rect x="{STORAGE[0]}" y="{STORAGE[1]}" width="{STORAGE[2] - STORAGE[0]}" height="{STORAGE[3] - STORAGE[1]}" rx="12" fill="#fff"/>
  <rect x="{STORAGE[0] + 14}" y="{STORAGE[1] + 12}" width="{STORAGE[2] - STORAGE[0] - 28}" height="8" rx="4" fill="{G_TEXT}"/>
  <polygon points="{' '.join(f'{x},{y}' for x, y in LB_BOLT)}" fill="{G_TEXT}"/>
  <g fill="{G_TEXT}" fill-opacity="0.55">
    <rect x="{STORAGE[0] + 18}" y="{STORAGE[3] - 30}" width="{STORAGE[2] - STORAGE[0] - 36}" height="3" rx="1.5"/>
    <rect x="{STORAGE[0] + 18}" y="{STORAGE[3] - 22}" width="{STORAGE[2] - STORAGE[0] - 36}" height="3" rx="1.5"/>
    <rect x="{STORAGE[0] + 18}" y="{STORAGE[3] - 14}" width="{STORAGE[2] - STORAGE[0] - 36}" height="3" rx="1.5"/>
  </g>
{shapes_svg(miniserver_shapes())}  <line x1="{LINK_X[0]}" y1="{LINK_Y}" x2="{LINK_X[1] - 4}" y2="{LINK_Y}" stroke="#fff" stroke-width="7"/>
  <polygon points="{LINK_X[1] + 8},{LINK_Y} {LINK_X[1] - 6},{LINK_Y - 11} {LINK_X[1] - 6},{LINK_Y + 11}" fill="#fff"/>
  <g fill="#fff" font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">
    <text x="256" y="{LABEL_BASE}" font-size="{label_px():.1f}" textLength="{LABEL_WIDTH}" lengthAdjust="spacingAndGlyphs">{LABEL}</text>
  </g>
</svg>
"""

if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "senec2lox", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    for name, svg in (("logo.svg", SVG_CLASSIC), ("logo_loxberry.svg", SVG_LOXBERRY)):
        with open(os.path.join(logo_dir, name), "w", encoding="utf-8", newline="\n") as f:
            f.write(svg)
    render_classic(512).save(os.path.join(logo_dir, "logo.png"))
    render_loxberry(512).save(os.path.join(logo_dir, "logo_loxberry.png"))
    for s in (64, 128, 256, 512):
        render_loxberry(s).save(os.path.join(icon_dir, f"icon_{s}.png"))
    print("ok")
