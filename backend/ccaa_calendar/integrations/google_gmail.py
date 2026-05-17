from __future__ import annotations

import base64
from datetime import UTC
from email.message import EmailMessage

from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

from ccaa_calendar.integrations.google_oauth import read_json
from ccaa_calendar.models import Event
from ccaa_calendar.settings import Settings


class GmailSendNotAuthorizedError(RuntimeError):
    pass


class GmailSendError(RuntimeError):
    pass


def _gmail_service(settings: Settings):
    token = read_json(settings.google_token_path)
    scopes = set(token.get("scopes", []))
    if not token or settings.google_gmail_scopes not in scopes:
        raise GmailSendNotAuthorizedError(
            "Gmail send scope is missing. Reconnect Google with include_gmail=true."
        )

    credentials = Credentials(
        token=token.get("token"),
        refresh_token=token.get("refresh_token"),
        token_uri=token.get("token_uri"),
        client_id=token.get("client_id"),
        client_secret=token.get("client_secret"),
        scopes=token.get("scopes", []),
    )
    return build("gmail", "v1", credentials=credentials, cache_discovery=False)


def reminder_subject(event: Event) -> str:
    return f"Recordatorio CCAACalendar: {event.title}"


def reminder_body(event: Event, minutes_before: int, note: str = "") -> str:
    starts_at = event.starts_at.astimezone(UTC).strftime("%Y-%m-%d %H:%M UTC")
    body = [
        f"Recordatorio del calendario oficial: {event.title}",
        "",
        f"Inicio: {starts_at}",
        f"Aviso configurado: {minutes_before} minutos antes.",
    ]
    if event.description:
        body.extend(["", event.description])
    if note:
        body.extend(["", f"Nota interna: {note}"])
    body.extend(["", "Enviado desde CCAACalendar usando la cuenta Google oficial conectada."])
    return "\n".join(body)


def send_event_reminder_email(
    event: Event,
    settings: Settings,
    recipient_email: str,
    minutes_before: int = 60,
    note: str = "",
) -> dict:
    service = _gmail_service(settings)
    message = EmailMessage()
    message["To"] = recipient_email
    message["Subject"] = reminder_subject(event)
    message.set_content(reminder_body(event, minutes_before, note))
    raw = base64.urlsafe_b64encode(message.as_bytes()).decode("utf-8")

    try:
        return service.users().messages().send(userId="me", body={"raw": raw}).execute()
    except Exception as exc:
        raise GmailSendError(str(exc)) from exc
