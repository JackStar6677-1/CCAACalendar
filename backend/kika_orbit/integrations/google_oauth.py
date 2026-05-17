from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

from google_auth_oauthlib.flow import Flow

from kika_orbit.settings import Settings

GOOGLE_AUTH_URI = "https://accounts.google.com/o/oauth2/auth"
GOOGLE_TOKEN_URI = "https://oauth2.googleapis.com/token"


class GoogleOAuthNotConfiguredError(RuntimeError):
    pass


def _read_client_secret_file(settings: Settings) -> dict[str, Any]:
    path = Path(settings.google_client_secret_file)
    if not path.exists():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    return payload.get("web") or payload.get("installed") or {}


def is_google_oauth_configured(settings: Settings) -> bool:
    if settings.google_client_id and settings.google_client_secret:
        return True
    file_config = _read_client_secret_file(settings)
    return bool(file_config.get("client_id") and file_config.get("client_secret"))


def google_oauth_config_source(settings: Settings) -> str:
    if settings.google_client_id and settings.google_client_secret:
        return "env"
    if is_google_oauth_configured(settings):
        return "client_secret_file"
    return "missing"


def google_client_config(settings: Settings) -> dict[str, Any]:
    if not is_google_oauth_configured(settings):
        raise GoogleOAuthNotConfiguredError("Google OAuth client id/secret are missing.")

    file_config = _read_client_secret_file(settings)
    client_id = settings.google_client_id or file_config.get("client_id", "")
    client_secret = settings.google_client_secret or file_config.get("client_secret", "")
    auth_uri = file_config.get("auth_uri") or GOOGLE_AUTH_URI
    token_uri = file_config.get("token_uri") or GOOGLE_TOKEN_URI

    return {
        "web": {
            "client_id": client_id,
            "client_secret": client_secret,
            "auth_uri": auth_uri,
            "token_uri": token_uri,
            "redirect_uris": [
                settings.google_redirect_uri,
                "http://127.0.0.1:8000/api/integrations/google/callback",
            ],
        }
    }


def oauth_scopes(settings: Settings, include_gmail: bool = False) -> list[str]:
    scopes = [settings.google_calendar_scopes]
    if include_gmail:
        scopes.append(settings.google_gmail_scopes)
    return [scope for scope in scopes if scope]


def make_flow(settings: Settings, include_gmail: bool = False) -> Flow:
    if settings.is_local:
        os.environ.setdefault("OAUTHLIB_INSECURE_TRANSPORT", "1")

    return Flow.from_client_config(
        google_client_config(settings),
        scopes=oauth_scopes(settings, include_gmail=include_gmail),
        redirect_uri=settings.google_redirect_uri,
    )


def write_json(path: str | Path, payload: dict[str, Any]) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def read_json(path: str | Path) -> dict[str, Any]:
    target = Path(path)
    if not target.exists():
        return {}
    return json.loads(target.read_text(encoding="utf-8"))
