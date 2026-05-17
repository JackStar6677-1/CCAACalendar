from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from fastapi.responses import HTMLResponse, RedirectResponse

from kika_orbit.integrations.google_oauth import (
    GoogleOAuthNotConfiguredError,
    is_google_oauth_configured,
    make_flow,
    oauth_scopes,
    read_json,
    write_json,
)
from kika_orbit.settings import Settings, get_settings

router = APIRouter(prefix="/api/integrations/google", tags=["integrations"])
SettingsDep = Annotated[Settings, Depends(get_settings)]


@router.get("/status")
def google_status(settings: SettingsDep) -> dict[str, object]:
    token = read_json(settings.google_token_path)
    return {
        "provider": "google",
        "mode": "center_calendar",
        "account_role": "official_center_calendar",
        "account_email": settings.google_center_account_email,
        "calendar_id": settings.google_calendar_id,
        "configured": is_google_oauth_configured(settings),
        "redirect_uri": settings.google_redirect_uri,
        "calendar_scope": settings.google_calendar_scopes,
        "gmail_scope": settings.google_gmail_scopes,
        "token_present": bool(token),
        "token_scopes": token.get("scopes", []),
        "internal_auth": "rut_password",
        "notes": [
            "Google OAuth conecta solo el calendario oficial del centro.",
            "Cada administradora entra con su cuenta interna de Kika Orbit.",
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
          <head><meta charset="utf-8"><title>Kika Orbit conectado</title></head>
          <body style="font-family: system-ui; padding: 2rem;">
            <h1>Calendario oficial conectado con Kika Orbit</h1>
            <p>La cuenta Google del centro quedo enlazada. Las administradoras siguen entrando
            con su usuario interno de Kika Orbit.</p>
            <p><a href="/app">Abrir Kika Orbit</a></p>
          </body>
        </html>
        """
    )
