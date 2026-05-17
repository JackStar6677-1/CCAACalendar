from __future__ import annotations

from datetime import UTC
from typing import Any

from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

from kika_orbit.integrations.google_oauth import read_json
from kika_orbit.models import Event
from kika_orbit.settings import Settings


class GoogleCalendarTokenMissingError(RuntimeError):
    pass


def google_event_payload(event: Event) -> dict[str, Any]:
    start = event.starts_at.astimezone(UTC).isoformat()
    end = event.ends_at.astimezone(UTC).isoformat()
    return {
        "summary": event.title,
        "description": event.description,
        "start": {"dateTime": start, "timeZone": "UTC"},
        "end": {"dateTime": end, "timeZone": "UTC"},
        "extendedProperties": {
            "private": {
                "kika_orbit_event_id": event.id,
                "kika_orbit_source": event.source,
                "kika_orbit_visibility": event.visibility,
            }
        },
    }


def token_metadata(settings: Settings) -> dict[str, Any]:
    token = read_json(settings.google_token_path)
    if not token:
        return {
            "token_present": False,
            "scopes": [],
            "calendar_id": settings.google_calendar_id,
        }
    return {
        "token_present": True,
        "scopes": token.get("scopes", []),
        "calendar_id": token.get("calendar_id") or settings.google_calendar_id,
    }


def insert_google_calendar_event(event: Event, settings: Settings) -> dict[str, Any]:
    token = read_json(settings.google_token_path)
    if not token:
        raise GoogleCalendarTokenMissingError("Google Calendar token is missing.")

    credentials = Credentials(
        token=token.get("token"),
        refresh_token=token.get("refresh_token"),
        token_uri=token.get("token_uri"),
        client_id=token.get("client_id"),
        client_secret=token.get("client_secret"),
        scopes=token.get("scopes", []),
    )
    service = build("calendar", "v3", credentials=credentials, cache_discovery=False)
    calendar_id = token.get("calendar_id") or settings.google_calendar_id
    return (
        service.events()
        .insert(calendarId=calendar_id, body=google_event_payload(event), sendUpdates="all")
        .execute()
    )
