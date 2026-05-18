from __future__ import annotations

from datetime import UTC

from ccaa_calendar.integrations.mail_delivery import send_email
from ccaa_calendar.models import Event, User
from ccaa_calendar.settings import Settings


def _format_event_datetime(event: Event) -> str:
    return event.starts_at.astimezone(UTC).strftime("%d/%m/%Y %H:%M UTC")


def event_created_email(settings: Settings, event: Event, user: User) -> str:
    name = user.display_name or "integrante del centro"
    when = _format_event_datetime(event)
    app_url = settings.app_public_url.rstrip("/")
    subject = f"Agendado en CCAACalendar: {event.title}"
    body_text = "\n".join(
        [
            f"Hola {name},",
            "",
            "Se registro un nuevo evento en el calendario del Centro de Estudiantes:",
            "",
            f"Titulo: {event.title}",
            f"Inicio: {when}",
            f"Categoria: {event.category}",
            "",
            event.description or "(sin detalle adicional)",
            "",
            f"Ver calendario: {app_url}/app",
            "",
            "Puedes desactivar estos avisos en Mi perfil dentro de CCAACalendar.",
        ]
    )
    body_html = f"""
    <p>Hola {name},</p>
    <p>Se registro un nuevo evento en el calendario del centro:</p>
    <p><strong>{event.title}</strong><br/>Inicio: {when}<br/>Categoria: {event.category}</p>
    <p>{event.description or ""}</p>
    <p><a href="{app_url}/app">Abrir CCAACalendar</a></p>
    <p><small>Puedes desactivar estos avisos en Mi perfil.</small></p>
    """
    return send_email(
        settings,
        to=user.email,
        subject=subject,
        body_text=body_text,
        body_html=body_html,
        prefer="gmail",
    )


def event_reminder_email(
    settings: Settings,
    event: Event,
    user: User,
    *,
    minutes_before: int,
) -> str:
    name = user.display_name or "integrante del centro"
    when = _format_event_datetime(event)
    app_url = settings.app_public_url.rstrip("/")
    subject = f"Recordatorio CCAACalendar: {event.title}"
    body_text = "\n".join(
        [
            f"Hola {name},",
            "",
            f"Recordatorio ({minutes_before} min antes): {event.title}",
            f"Inicio: {when}",
            "",
            event.description or "",
            "",
            f"Ver calendario: {app_url}/app",
            "",
            "Puedes desactivar estos avisos en Mi perfil dentro de CCAACalendar.",
        ]
    )
    body_html = f"""
    <p>Hola {name},</p>
    <p>Recordatorio <strong>{minutes_before} min antes</strong>:</p>
    <p><strong>{event.title}</strong><br/>Inicio: {when}</p>
    <p>{event.description or ""}</p>
    <p><a href="{app_url}/app">Abrir CCAACalendar</a></p>
    """
    return send_email(
        settings,
        to=user.email,
        subject=subject,
        body_text=body_text,
        body_html=body_html,
        prefer="gmail",
    )

def password_reset_email(
    settings: Settings,
    user: User,
    reset_token: str,
) -> str:
    base = settings.app_public_url.rstrip("/")
    reset_link = f"{base}/?reset_token={reset_token}"
    name = user.display_name or "integrante del centro"
    subject = "Recuperar clave de CCAACalendar"
    body_text = "\n".join(
        [
            f"Hola {name},",
            "",
            "Recibimos una solicitud para restablecer tu clave de CCAACalendar.",
            "",
            f"Abre este enlace (vence en 30 minutos):",
            reset_link,
            "",
            f"Si el enlace no abre, copia este codigo en la pantalla de recuperacion: {reset_token}",
            "",
            "Si no pediste este cambio, ignora el correo o avisa a la directiva.",
            "",
            f"Correo del centro (calendario compartido): {settings.google_center_account_email}",
        ]
    )
    body_html = f"""
    <p>Hola {name},</p>
    <p>Recibimos una solicitud para restablecer tu clave de <strong>CCAACalendar</strong>.</p>
    <p><a href="{reset_link}">Restablecer clave</a> (vence en 30 minutos)</p>
    <p>Codigo manual: <strong>{reset_token}</strong></p>
    <p>Si no pediste este cambio, ignora el correo.</p>
    """
    return send_email(
        settings,
        to=user.email,
        subject=subject,
        body_text=body_text,
        body_html=body_html,
    )
