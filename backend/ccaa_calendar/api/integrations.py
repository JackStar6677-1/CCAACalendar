from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.integrations.google_calendar import (
    GoogleCalendarTokenMissingError,
    google_event_payload,
    insert_google_calendar_event,
    token_metadata,
)
from ccaa_calendar.integrations.google_oauth import (
    GoogleOAuthNotConfiguredError,
    google_oauth_config_source,
    is_google_oauth_configured,
    make_flow,
    oauth_scopes,
    read_json,
    write_json,
)
from ccaa_calendar.models import Event
from ccaa_calendar.settings import Settings, get_settings

router = APIRouter(prefix="/api/integrations/google", tags=["integrations"])
SettingsDep = Annotated[Settings, Depends(get_settings)]
SessionDep = Annotated[Session, Depends(get_session)]


def _mask_email(email: str) -> str:
    return "configured" if email else ""


@router.get("/status")
def google_status(settings: SettingsDep) -> dict[str, object]:
    token = token_metadata(settings)
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
        "token_scopes": token.get("scopes", []),
        "internal_auth": "rut_password",
        "ready_to_connect": is_google_oauth_configured(settings)
        and bool(settings.google_center_account_email),
        "notes": [
            "Google OAuth conecta solo el calendario oficial del centro.",
            "Cada administradora entra con su cuenta interna de CCAACalendar.",
        ],
    }


@router.get("/login")
def google_login(
    settings: SettingsDep,
    include_gmail: bool = Query(default=False),
) -> RedirectResponse:
    try:
        flow = make_flow(settings, include_gmail=include_gmail)
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
            "scopes": oauth_scopes(settings, include_gmail),
        },
    )
    return RedirectResponse(authorization_url)


@router.post("/events/{event_id}/sync")
def sync_google_event(
    event_id: str,
    session: SessionDep,
    settings: SettingsDep,
    dry_run: bool = Query(default=True),
    confirm: str = Query(default=""),
) -> dict[str, object]:
    event = session.get(Event, event_id)
    if not event:
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

    try:
        google_event = insert_google_calendar_event(event, settings)
    except GoogleCalendarTokenMissingError as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc

    event.google_calendar_id = settings.google_calendar_id
    event.google_event_id = google_event.get("id")
    event.source = "google_sync"
    session.add(event)
    session.commit()

    return {
        "mode": "synced",
        "event_id": event.id,
        "google_event_id": event.google_event_id,
        "html_link": google_event.get("htmlLink"),
    }


@router.get("/callback", response_class=HTMLResponse)
def google_callback(request: Request, settings: SettingsDep) -> HTMLResponse:
    expected = read_json(settings.google_oauth_state_path)
    state = request.query_params.get("state", "")
    if not expected or state != expected.get("state"):
        raise HTTPException(status_code=400, detail="Invalid Google OAuth state.")

    try:
        flow = make_flow(settings, include_gmail=bool(expected.get("include_gmail")))
        flow.fetch_token(authorization_response=str(request.url))
    except GoogleOAuthNotConfiguredError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    credentials = flow.credentials
    write_json(
        settings.google_token_path,
        {
            "token": credentials.token,
            "refresh_token": credentials.refresh_token,
            "token_uri": credentials.token_uri,
            "client_id": credentials.client_id,
            "client_secret": credentials.client_secret,
            "scopes": list(credentials.scopes or []),
            "mode": "center_calendar",
            "account_email": expected.get("account_email", settings.google_center_account_email),
            "calendar_id": expected.get("calendar_id", settings.google_calendar_id),
        },
    )

    return HTMLResponse(
        """
        <!doctype html>
        <html lang="es">
          <head><meta charset="utf-8"><title>CCAACalendar conectado</title></head>
          <body style="font-family: system-ui; padding: 2rem;">
            <h1>Calendario oficial conectado con CCAACalendar</h1>
            <p>La cuenta Google del centro quedo enlazada. Las administradoras siguen entrando
            con su usuario interno de CCAACalendar.</p>
            <p><a href="/app">Abrir CCAACalendar</a></p>
          </body>
        </html>
        """
    )

