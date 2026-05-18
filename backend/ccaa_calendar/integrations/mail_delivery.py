from __future__ import annotations

import base64
import logging
import smtplib
import ssl
from email.message import EmailMessage
from typing import Literal

from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

from ccaa_calendar.integrations.google_oauth import read_json
from ccaa_calendar.settings import Settings

logger = logging.getLogger(__name__)


class MailDeliveryError(RuntimeError):
    pass


class MailNotConfiguredError(MailDeliveryError):
    pass


def _gmail_can_send(settings: Settings) -> bool:
    token = read_json(settings.google_token_path)
    if not token.get("token"):
        return False
    return settings.google_gmail_scopes in set(token.get("scopes", []))


def _smtp_configured(settings: Settings) -> bool:
    return bool(
        settings.smtp_host.strip()
        and settings.smtp_username.strip()
        and settings.smtp_password.strip()
        and settings.smtp_from_email.strip()
    )


def mail_from_address(settings: Settings) -> str:
    return (
        settings.mail_from_email.strip()
        or settings.google_center_account_email.strip()
        or settings.smtp_from_email.strip()
    )


def mail_from_header(settings: Settings) -> str:
    address = mail_from_address(settings)
    name = settings.mail_from_name.strip() or settings.public_brand_name
    return f"{name} <{address}>" if address else name


def _send_via_gmail_api(settings: Settings, message: EmailMessage) -> dict:
    token = read_json(settings.google_token_path)
    if settings.google_gmail_scopes not in set(token.get("scopes", [])):
        raise MailNotConfiguredError("Gmail API sin scope gmail.send.")

    credentials = Credentials(
        token=token.get("token"),
        refresh_token=token.get("refresh_token"),
        token_uri=token.get("token_uri"),
        client_id=token.get("client_id"),
        client_secret=token.get("client_secret"),
        scopes=token.get("scopes", []),
    )
    service = build("gmail", "v1", credentials=credentials, cache_discovery=False)
    raw = base64.urlsafe_b64encode(message.as_bytes()).decode("utf-8")
    return service.users().messages().send(userId="me", body={"raw": raw}).execute()


def _send_via_smtp(settings: Settings, message: EmailMessage) -> None:
    if not _smtp_configured(settings):
        raise MailNotConfiguredError("SMTP no configurado.")

    context = ssl.create_default_context()
    host = settings.smtp_host.strip()
    port = settings.smtp_port
    username = settings.smtp_username.strip()
    password = settings.smtp_password

    if settings.smtp_use_ssl:
        with smtplib.SMTP_SSL(host, port, context=context, timeout=25) as client:
            client.login(username, password)
            client.send_message(message)
        return

    with smtplib.SMTP(host, port, timeout=25) as client:
        if settings.smtp_use_tls:
            client.starttls(context=context)
        client.login(username, password)
        client.send_message(message)


def _send_via_console(settings: Settings, message: EmailMessage) -> None:
    logger.info(
        "MAIL(console) to=%s subject=%s",
        message.get("To"),
        message.get("Subject"),
    )
    if settings.is_local:
        print(f"[CCAACalendar mail] To: {message.get('To')} | {message.get('Subject')}")


def send_email(
    settings: Settings,
    *,
    to: str,
    subject: str,
    body_text: str,
    body_html: str | None = None,
    reply_to: str | None = None,
    prefer: Literal["auto", "gmail", "smtp", "console"] = "auto",
) -> str:
    """Envia correo. Retorna el proveedor usado: gmail, smtp o console."""
    message = EmailMessage()
    message["To"] = to
    message["Subject"] = subject
    from_header = mail_from_header(settings)
    if from_header:
        message["From"] = from_header
    if reply_to:
        message["Reply-To"] = reply_to
    elif mail_from_address(settings):
        message["Reply-To"] = mail_from_address(settings)

    message.set_content(body_text)
    if body_html:
        message.add_alternative(body_html, subtype="html")

    providers: list[str]
    if prefer == "gmail":
        providers = ["gmail"]
    elif prefer == "smtp":
        providers = ["smtp"]
    elif prefer == "console":
        providers = ["console"]
    else:
        providers = []
        if _gmail_can_send(settings):
            providers.append("gmail")
        if _smtp_configured(settings):
            providers.append("smtp")
        if settings.is_local or settings.mail_fallback_console:
            providers.append("console")
        if not providers:
            raise MailNotConfiguredError(
                "No hay proveedor de correo. Conecta Google con Gmail o configura SMTP en .env."
            )

    last_error: Exception | None = None
    for provider in providers:
        try:
            if provider == "gmail":
                _send_via_gmail_api(settings, message)
                return "gmail"
            if provider == "smtp":
                _send_via_smtp(settings, message)
                return "smtp"
            _send_via_console(settings, message)
            return "console"
        except Exception as exc:
            last_error = exc
            logger.warning("Mail provider %s failed: %s", provider, exc)

    raise MailDeliveryError(str(last_error or "No se pudo enviar el correo."))
