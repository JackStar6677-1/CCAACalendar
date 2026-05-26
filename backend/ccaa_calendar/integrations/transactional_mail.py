from __future__ import annotations

from datetime import UTC

from ccaa_calendar.integrations.email_templates import (
    category_accent,
    category_label,
    render_email_html,
)
from ccaa_calendar.integrations.mail_delivery import send_email
from ccaa_calendar.models import Event, User
from ccaa_calendar.settings import Settings


def _format_event_datetime(event: Event) -> str:
    return event.starts_at.astimezone(UTC).strftime("%d/%m/%Y %H:%M")


def _event_detail_rows(event: Event) -> list[tuple[str, str]]:
    rows = [
        ("Inicio", _format_event_datetime(event)),
        ("Categoría", category_label(event.category)),
    ]
    if event.description.strip():
        rows.append(("Detalle", event.description.strip()[:500]))
    return rows


def event_created_email(settings: Settings, event: Event, user: User) -> str:
    name = user.display_name or "integrante del centro"
    app_url = settings.app_public_url.rstrip("/")
    subject = f"Agendado en CCAACalendar: {event.title}"
    body_text = "\n".join(
        [
            f"Hola {name},",
            "",
            "Se registró un nuevo evento en el calendario del Centro de Estudiantes:",
            "",
            f"Título: {event.title}",
            f"Inicio: {_format_event_datetime(event)}",
            f"Categoría: {category_label(event.category)}",
            "",
            event.description.strip() or "(sin detalle adicional)",
            "",
            f"Ver calendario: {app_url}/app",
            "",
            "Puedes desactivar estos avisos en Mi perfil dentro de CCAACalendar.",
        ]
    )
    body_html = render_email_html(
        settings,
        preheader=f"Nuevo evento: {event.title}",
        headline="Nuevo evento en el calendario",
        greeting=f"Hola {name},",
        paragraphs=[
            "Se registró un movimiento en el calendario oficial del centro. "
            "Este aviso llega a tu correo personal porque tienes activadas las "
            "notificaciones en CCAACalendar.",
        ],
        highlight=(
            event.title,
            _event_detail_rows(event),
            category_accent(event.category),
        ),
        cta=(f"{app_url}/app", "Ver en CCAACalendar"),
    )
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
    app_url = settings.app_public_url.rstrip("/")
    hours = minutes_before // 60
    time_label = (
        f"{hours} h"
        if minutes_before >= 60 and minutes_before % 60 == 0
        else f"{minutes_before} min"
    )
    subject = f"Recordatorio CCAACalendar: {event.title}"
    body_text = "\n".join(
        [
            f"Hola {name},",
            "",
            f"Recordatorio ({time_label} antes): {event.title}",
            f"Inicio: {_format_event_datetime(event)}",
            "",
            event.description.strip() or "",
            "",
            f"Ver calendario: {app_url}/app",
            "",
            "Puedes desactivar estos avisos en Mi perfil dentro de CCAACalendar.",
        ]
    )
    body_html = render_email_html(
        settings,
        preheader=f"En {time_label}: {event.title}",
        headline="Recordatorio de evento",
        greeting=f"Hola {name},",
        paragraphs=[
            f"Te recordamos que el siguiente evento comienza en aproximadamente {time_label}.",
        ],
        highlight=(
            event.title,
            _event_detail_rows(event),
            category_accent(event.category),
        ),
        cta=(f"{app_url}/app", "Abrir calendario"),
    )
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
            "Abre este enlace (vence en 30 minutos):",
            reset_link,
            "",
            "Si el enlace no abre, copia este código en la pantalla de recuperación: "
            f"{reset_token}",
            "",
            "Si no pediste este cambio, ignora el correo o avisa a la directiva.",
        ]
    )
    body_html = render_email_html(
        settings,
        preheader="Solicitud de recuperación de clave",
        headline="Recuperar tu clave",
        greeting=f"Hola {name},",
        paragraphs=[
            "Recibimos una solicitud para restablecer tu clave de acceso a CCAACalendar.",
            "El enlace y el código siguientes vencen en 30 minutos.",
            "Si no solicitaste este cambio, puedes ignorar este correo.",
        ],
        cta=(reset_link, "Restablecer clave"),
        code_block=reset_token,
        footer_note=(
            "Correo de seguridad de tu cuenta interna (RUT + clave). "
            "No compartas el código."
        ),
    )
    return send_email(
        settings,
        to=user.email,
        subject=subject,
        body_text=body_text,
        body_html=body_html,
    )


def branded_test_email(settings: Settings, *, to: str, recipient_name: str = "equipo") -> str:
    """Correo de prueba con plantilla completa (desarrollo)."""
    app_url = settings.app_public_url.rstrip("/")
    subject = "CCAACalendar · correo de marca (prueba)"
    body_text = (
        f"Hola {recipient_name},\n\n"
        "Correo de prueba con plantilla HTML, colores de marca y logo SVG.\n\n"
        f"Calendario: {app_url}/app\n"
    )
    body_html = render_email_html(
        settings,
        preheader="Prueba de diseño de correos CCAACalendar",
        headline="Correo de marca listo",
        greeting=f"Hola {recipient_name},",
        paragraphs=[
            "Este mensaje confirma que los correos transaccionales usan la identidad visual "
            "de CCAACalendar: fondo oscuro, acentos naranjo y violeta, logo orbital "
            "en SVG y firma del centro.",
            "Los avisos de eventos, recordatorios y recuperación de clave comparten "
            "esta misma plantilla.",
        ],
        highlight=(
            "Detalle de prueba",
            [
                ("Estado", "Plantilla HTML activa"),
                ("Logo", "orbit-icon.svg (inline + fallback Outlook)"),
                ("Remitente", "Cuenta oficial del centro"),
            ],
            category_accent("centro"),
        ),
        cta=(f"{app_url}/app", "Abrir CCAACalendar"),
        footer_note="Mensaje de prueba del sistema de correos.",
    )
    return send_email(
        settings,
        to=to,
        subject=subject,
        body_text=body_text,
        body_html=body_html,
        prefer="gmail",
    )
