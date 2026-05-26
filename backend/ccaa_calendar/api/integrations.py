from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import AdminUserDep, CurrentAdminUserDep, EditorUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.integrations.google_calendar import (
    GoogleCalendarSyncError,
    GoogleCalendarTokenMissingError,
    delete_google_calendar_event,
    google_calendar_events,
    google_event_payload,
    insert_google_calendar_event,
    token_metadata,
    update_google_calendar_event,
)
from ccaa_calendar.integrations.google_gmail import (
    GmailSendError,
    GmailSendNotAuthorizedError,
    send_event_reminder_email,
)
from ccaa_calendar.integrations.google_oauth import (
    GoogleOAuthNotConfiguredError,
    google_oauth_config_source,
    is_google_oauth_configured,
    make_flow,
    new_code_verifier,
    oauth_scopes,
    read_json,
    write_json,
)
from ccaa_calendar.models import AuditLog, Event
from ccaa_calendar.observability import write_app_log
from ccaa_calendar.schemas import ReminderEmailRequest
from ccaa_calendar.settings import Settings, get_settings

router = APIRouter(prefix="/api/integrations/google", tags=["integrations"])
SettingsDep = Annotated[Settings, Depends(get_settings)]
SessionDep = Annotated[Session, Depends(get_session)]


def _mask_email(email: str) -> str:
    return "configured" if email else ""


@router.get("/status")
def google_status(settings: SettingsDep) -> dict[str, object]:
    token = token_metadata(settings)
    token_scopes = token.get("scopes", [])
    return {
        "provider": "google",
        "mode": "center_calendar",
        "account_role": "official_center_calendar",
        "account_email_configured": bool(settings.google_center_account_email),
        "account_hint": _mask_email(settings.google_center_account_email),
        "calendar_id": settings.google_calendar_id,
        "configured": is_google_oauth_configured(settings),
        "config_source": google_oauth_config_source(settings),
        "redirect_uri": settings.google_redirect_uri,
        "calendar_scope": settings.google_calendar_scopes,
        "gmail_scope": settings.google_gmail_scopes,
        "token_present": token["token_present"],
        "token_scopes": token_scopes,
        "gmail_authorized": settings.google_gmail_scopes in token_scopes,
        "internal_auth": "rut_password",
        "ready_to_connect": is_google_oauth_configured(settings)
        and bool(settings.google_center_account_email),
        "notes": [
            "Google OAuth conecta solo el calendario oficial del centro.",
            "Cada administradora entra con su cuenta interna de CCAACalendar.",
        ],
    }


@router.post("/events/{event_id}/reminder-email")
def send_google_reminder_email(
    event_id: str,
    payload: ReminderEmailRequest,
    session: SessionDep,
    settings: SettingsDep,
    current_user: EditorUserDep,
) -> dict[str, object]:
    event = session.get(Event, event_id)
    if not event or event.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Event not found.")

    try:
        message = send_event_reminder_email(
            event,
            settings,
            recipient_email=payload.recipient_email,
            minutes_before=payload.minutes_before,
            note=payload.note,
        )
    except GmailSendNotAuthorizedError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc
    except GmailSendError as exc:
        raise HTTPException(status_code=502, detail=f"Gmail send failed: {exc}") from exc

    return {
        "mode": "sent",
        "event_id": event.id,
        "message_id": message.get("id"),
    }


def _google_authorization_url(settings: Settings, include_gmail: bool) -> str:
    """Construye una autorizacion OAuth de un uso para la cuenta oficial."""
    try:
        code_verifier = new_code_verifier()
        flow = make_flow(settings, include_gmail=include_gmail, code_verifier=code_verifier)
    except GoogleOAuthNotConfiguredError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    authorization_url, state = flow.authorization_url(
        access_type="offline",
        include_granted_scopes="true",
        prompt="consent",
    )
    write_json(
        settings.google_oauth_state_path,
        {
            "state": state,
            "include_gmail": include_gmail,
            "mode": "center_calendar",
            "account_email": settings.google_center_account_email,
            "calendar_id": settings.google_calendar_id,
            "code_verifier": code_verifier,
            "scopes": oauth_scopes(settings, include_gmail),
        },
    )
    write_app_log(
        settings,
        "google.oauth.login_started",
        {
            "include_gmail": include_gmail,
            "calendar_id": settings.google_calendar_id,
            "scopes": oauth_scopes(settings, include_gmail),
        },
    )
    return authorization_url


@router.post("/authorize-url")
def google_authorize_url(
    current_user: AdminUserDep,
    settings: SettingsDep,
    include_gmail: bool = Query(default=True),
) -> dict[str, str]:
    del current_user
    return {"authorization_url": _google_authorization_url(settings, include_gmail)}


@router.get("/login")
def google_login(
    current_user: AdminUserDep,
    settings: SettingsDep,
    include_gmail: bool = Query(default=True),
) -> RedirectResponse:
    del current_user
    return RedirectResponse(_google_authorization_url(settings, include_gmail))


@router.get("/events")
def list_google_events(
    current_user: CurrentAdminUserDep,
    settings: SettingsDep,
    max_results: int = Query(default=40, ge=1, le=100),
) -> dict[str, object]:
    del current_user
    try:
        events = google_calendar_events(settings, max_results=max_results)
    except GoogleCalendarTokenMissingError:
        return {
            "connected": False,
            "events": [],
            "message": "Google Calendar todavia no esta conectado.",
        }
    except GoogleCalendarSyncError as exc:
        raise HTTPException(status_code=502, detail=f"Google Calendar sync failed: {exc}") from exc

    return {
        "connected": True,
        "events": events,
        "count": len(events),
    }


@router.post("/events/{event_id}/sync")
def sync_google_event(
    event_id: str,
    session: SessionDep,
    settings: SettingsDep,
    current_user: EditorUserDep,
    dry_run: bool = Query(default=True),
    confirm: str = Query(default=""),
) -> dict[str, object]:
    event = session.get(Event, event_id)
    if not event or event.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Event not found.")

    payload = google_event_payload(event)
    if dry_run:
        return {
            "mode": "dry_run",
            "calendar_id": settings.google_calendar_id,
            "payload": payload,
        }

    if confirm != "sync-google-calendar":
        raise HTTPException(
            status_code=400,
            detail="Set confirm=sync-google-calendar to publish this event to Google Calendar.",
        )

    was_linked = bool(event.google_event_id)
    try:
        google_event = (
            update_google_calendar_event(event, settings)
            if was_linked
            else insert_google_calendar_event(event, settings)
        )
    except GoogleCalendarTokenMissingError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc
    except GoogleCalendarSyncError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    event.google_calendar_id = settings.google_calendar_id
    event.google_event_id = google_event.get("id")
    event.source = "google_sync"
    session.add(event)
    session.add(
        AuditLog(
            organization_id=event.organization_id,
            actor_user_id=current_user.id,
            action="google.event_update" if was_linked else "google.event_publish",
            entity_type="event",
            entity_id=event.id,
            payload={"google_event_id": event.google_event_id},
        )
    )
    session.commit()

    return {
        "mode": "updated" if was_linked else "synced",
        "event_id": event.id,
        "google_calendar_id": event.google_calendar_id,
        "google_event_id": event.google_event_id,
    }


@router.delete("/events/{event_id}/sync")
def delete_synced_google_event(
    event_id: str,
    session: SessionDep,
    settings: SettingsDep,
    current_user: EditorUserDep,
) -> dict[str, str]:
    event = session.get(Event, event_id)
    if not event or event.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Event not found.")

    try:
        delete_google_calendar_event(event, settings)
    except GoogleCalendarTokenMissingError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc
    except GoogleCalendarSyncError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    event.google_event_id = None
    event.google_calendar_id = None
    session.add(event)
    session.add(
        AuditLog(
            organization_id=event.organization_id,
            actor_user_id=current_user.id,
            action="google.event_delete",
            entity_type="event",
            entity_id=event.id,
            payload={},
        )
    )
    session.commit()
    return {"mode": "deleted", "event_id": event.id}


@router.get("/callback", response_class=HTMLResponse)
def google_callback(request: Request, settings: SettingsDep) -> HTMLResponse:
    expected = read_json(settings.google_oauth_state_path)
    state = request.query_params.get("state", "")
    if not expected or state != expected.get("state"):
        raise HTTPException(status_code=400, detail="Invalid Google OAuth state.")

    try:
        flow = make_flow(
            settings,
            include_gmail=bool(expected.get("include_gmail", True)),
            code_verifier=str(expected.get("code_verifier", "")) or None,
        )
        flow.fetch_token(authorization_response=str(request.url))
    except GoogleOAuthNotConfiguredError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:
        write_app_log(
            settings,
            "google.oauth.callback_failed",
            {
                "include_gmail": bool(expected.get("include_gmail")),
                "expected_scopes": expected.get("scopes", []),
                "error_type": type(exc).__name__,
                "error": str(exc),
            },
        )
        return _oauth_error_response(str(exc))

    credentials = flow.credentials
    granted_scopes = list(credentials.scopes or expected.get("scopes", []))
    write_json(
        settings.google_token_path,
        {
            "token": credentials.token,
            "refresh_token": credentials.refresh_token,
            "token_uri": credentials.token_uri,
            "client_id": credentials.client_id,
            "client_secret": credentials.client_secret,
            "scopes": granted_scopes,
            "mode": "center_calendar",
            "account_email": expected.get("account_email", settings.google_center_account_email),
            "calendar_id": expected.get("calendar_id", settings.google_calendar_id),
        },
    )
    write_app_log(
        settings,
        "google.oauth.connected",
        {
            "include_gmail": bool(expected.get("include_gmail")),
            "calendar_id": expected.get("calendar_id", settings.google_calendar_id),
            "scopes": granted_scopes,
        },
    )

    return HTMLResponse(
        """
        <!doctype html>
        <html lang="es">
          <head><meta charset="utf-8"><title>CCAACalendar conectado</title></head>
          <body style="font-family: system-ui; padding: 2rem;">
            <h1>Calendario y correo del centro conectados</h1>
            <p>La cuenta Google del centro quedo enlazada con permisos de calendario y envio
            de correos. Las integrantes siguen entrando con su usuario interno de CCAACalendar.</p>
            <p><a href="/app">Abrir CCAACalendar</a></p>
            <script>
              window.setTimeout(() => {
                window.location.href = "/app?google=connected";
              }, 1400);
            </script>
          </body>
        </html>
        """
    )


def _oauth_error_response(detail: str) -> HTMLResponse:
    safe_detail = detail.replace("<", "&lt;").replace(">", "&gt;")
    return HTMLResponse(
        f"""
        <!doctype html>
        <html lang="es">
          <head><meta charset="utf-8"><title>CCAACalendar OAuth</title></head>
          <body style="font-family: system-ui; padding: 2rem; max-width: 760px;">
            <h1>No se pudo conectar Google Calendar</h1>
            <p>Vuelve a CCAACalendar y presiona nuevamente
            <strong>Conectar Google del centro</strong>.
            El enlace de Google solo sirve una vez y puede expirar si se abre dos veces.</p>
            <p style="color:#8a2b2b;"><strong>Detalle tecnico:</strong> {safe_detail}</p>
            <p><a href="/app">Volver a CCAACalendar</a></p>
          </body>
        </html>
        """,
        status_code=400,
    )

