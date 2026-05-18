from __future__ import annotations

from html import escape
from pathlib import Path

from ccaa_calendar.settings import Settings

BRAND = {
    "ink": "#160f1f",
    "ink_soft": "#251933",
    "panel": "#22182e",
    "text": "#fff7e8",
    "muted": "#c9b7d8",
    "orange": "#ff7a2f",
    "violet": "#8f5cff",
    "gold": "#ffd166",
    "bg_outer": "#130d1b",
    "bg_card": "#1b1128",
}

_STATIC_DIR = Path(__file__).resolve().parents[1] / "web" / "static"
_LOGO_PATH = _STATIC_DIR / "orbit-icon.svg"


def _load_logo_svg() -> str:
    if not _LOGO_PATH.exists():
        return ""
    raw = _LOGO_PATH.read_text(encoding="utf-8")
    return (
        raw.replace('id="core"', 'id="ccaaEmailCore"')
        .replace("url(#core)", "url(#ccaaEmailCore)")
        .replace('id="title"', 'id="ccaaEmailTitle"')
        .replace('id="desc"', 'id="ccaaEmailDesc"')
    )


def _logo_block(settings: Settings) -> str:
    app_url = settings.app_public_url.rstrip("/")
    logo_url = escape(f"{app_url}/assets/orbit-icon.svg")
    svg = _load_logo_svg()
    svg_markup = ""
    if svg:
        svg_markup = svg.replace(
            "<svg ",
            '<svg width="72" height="72" role="img" aria-label="CCAACalendar" '
            'style="display:block;margin:0 auto 12px;" ',
            1,
        )
    outlook_img = (
        f'<img src="{logo_url}" alt="CCAACalendar" width="72" height="72" '
        f'style="display:block;border:0;outline:none;margin:0 auto 12px;" />'
    )
    modern_logo = (
        f'<div style="margin:0 auto 12px;width:72px;height:72px;line-height:0;">{svg_markup}</div>'
        if svg_markup
        else outlook_img
    )

    return f"""
    <td align="center" style="padding:28px 24px 8px;">
      <!--[if mso]>
      {outlook_img}
      <![endif]-->
      <!--[if !mso]><!-->
      {modern_logo}
      <!--<![endif]-->
      <p style="margin:8px 0 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:11px;
         letter-spacing:0.14em;text-transform:uppercase;color:{BRAND['gold']};">
        CCAACalendar
      </p>
      <p style="margin:4px 0 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;
         color:{BRAND['muted']};">
        Centro de Estudiantes de Psicología · UDLA Maipú
      </p>
    </td>
    """


def _signature_block(settings: Settings) -> str:
    brand = settings.mail_from_name.strip() or settings.public_brand_name
    return f"""
    <tr>
      <td style="padding:20px 28px 8px;border-top:1px solid rgba(255,255,255,0.08);">
        <p style="margin:0 0 6px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;
           color:{BRAND['text']};font-weight:600;">
          {escape(brand)}
        </p>
        <p style="margin:0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;
           line-height:1.5;color:{BRAND['muted']};">
          Calendario institucional del centro · Coordinación universitaria
        </p>
      </td>
    </tr>
    """


def _footer_block(settings: Settings, *, note: str | None = None) -> str:
    app_url = settings.app_public_url.rstrip("/")
    lines = [
        note or "Este mensaje se envió desde la cuenta oficial del centro de estudiantes.",
        f'<a href="{escape(app_url)}/app" style="color:{BRAND["orange"]};text-decoration:none;">Abrir calendario</a>',
        "Puedes desactivar avisos por correo en <strong>Mi perfil</strong> dentro de la app.",
    ]
    body = "<br/>".join(lines)
    return f"""
    <tr>
      <td style="padding:12px 28px 28px;">
        <p style="margin:0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:11px;
           line-height:1.6;color:{BRAND['muted']};">
          {body}
        </p>
      </td>
    </tr>
    """


def _cta_button(href: str, label: str) -> str:
    return f"""
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"
           style="margin:20px auto 8px;">
      <tr>
        <td align="center" style="border-radius:999px;background:linear-gradient(135deg,{BRAND['orange']},{BRAND['violet']});">
          <a href="{escape(href)}" target="_blank"
             style="display:inline-block;padding:14px 28px;font-family:Segoe UI,Helvetica,Arial,sans-serif;
             font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:999px;">
            {escape(label)}
          </a>
        </td>
      </tr>
    </table>
    """


def _highlight_card(title: str, rows: list[tuple[str, str]], *, accent: str = BRAND["orange"]) -> str:
    rows_html = "".join(
        f"""
        <tr>
          <td style="padding:6px 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;
             color:{BRAND['muted']};width:38%;vertical-align:top;">{escape(label)}</td>
          <td style="padding:6px 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;
             color:{BRAND['text']};font-weight:500;">{escape(value)}</td>
        </tr>
        """
        for label, value in rows
    )
    return f"""
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
           style="margin:16px 0;background:{BRAND['ink_soft']};border-radius:16px;
           border-left:4px solid {accent};">
      <tr>
        <td style="padding:18px 20px;">
          <p style="margin:0 0 10px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;
             font-weight:700;color:{BRAND['text']};">{escape(title)}</p>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
            {rows_html}
          </table>
        </td>
      </tr>
    </table>
    """


def render_email_html(
    settings: Settings,
    *,
    preheader: str,
    headline: str,
    greeting: str,
    paragraphs: list[str],
    highlight: tuple[str, list[tuple[str, str]], str] | None = None,
    cta: tuple[str, str] | None = None,
    code_block: str | None = None,
    footer_note: str | None = None,
) -> str:
    paragraphs_html = "".join(
        f"""
        <p style="margin:0 0 14px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;
           line-height:1.55;color:{BRAND['text']};">{escape(p)}</p>
        """
        for p in paragraphs
    )
    highlight_html = ""
    if highlight:
        title, rows, accent = highlight
        highlight_html = _highlight_card(title, rows, accent=accent)

    cta_html = _cta_button(cta[0], cta[1]) if cta else ""
    code_html = ""
    if code_block:
        code_html = f"""
        <p style="margin:12px 0 4px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;
           color:{BRAND['muted']};">Código manual</p>
        <p style="margin:0;padding:14px 16px;background:{BRAND['ink']};border-radius:12px;
           font-family:Consolas,Monaco,monospace;font-size:13px;letter-spacing:0.04em;
           color:{BRAND['gold']};word-break:break-all;">{escape(code_block)}</p>
        """

    preheader_div = (
        f'<div style="display:none;max-height:0;overflow:hidden;opacity:0;'
        f'color:transparent;mso-hide:all;">{escape(preheader)}</div>'
    )

    return f"""<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="color-scheme" content="dark" />
  <meta name="supported-color-schemes" content="dark" />
  <title>{escape(headline)}</title>
</head>
<body style="margin:0;padding:0;background:{BRAND['bg_outer']};">
  {preheader_div}
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
         style="background:{BRAND['bg_outer']};">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
               style="max-width:560px;background:{BRAND['bg_card']};border-radius:24px;
               border:1px solid rgba(255,255,255,0.1);overflow:hidden;
               box-shadow:0 24px 64px rgba(0,0,0,0.45);">
          <tr style="background:linear-gradient(160deg,{BRAND['ink']} 0%,{BRAND['panel']} 100%);">
            {_logo_block(settings)}
          </tr>
          <tr>
            <td style="padding:8px 28px 4px;">
              <h1 style="margin:0 0 8px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:22px;
                 line-height:1.3;color:{BRAND['text']};">{escape(headline)}</h1>
              <p style="margin:0 0 18px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;
                 color:{BRAND['gold']};">{escape(greeting)}</p>
              {paragraphs_html}
              {highlight_html}
              {code_html}
              {cta_html}
            </td>
          </tr>
          {_signature_block(settings)}
          {_footer_block(settings, note=footer_note)}
        </table>
        <p style="margin:16px 0 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:10px;
           color:#6e5c7d;text-align:center;">
          Mensaje automático · No respondas a este correo
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
"""


def category_accent(category: str) -> str:
    mapping = {
        "centro": BRAND["orange"],
        "academico": BRAND["violet"],
        "espacio": BRAND["gold"],
        "general": BRAND["violet"],
    }
    return mapping.get(category.lower(), BRAND["orange"])


def category_label(category: str) -> str:
    mapping = {
        "centro": "Centro de estudiantes",
        "academico": "Académico",
        "espacio": "Espacio / reserva",
        "general": "General",
    }
    return mapping.get(category.lower(), category.capitalize())
