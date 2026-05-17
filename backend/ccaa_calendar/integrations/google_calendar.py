from __future__ import annotations

from datetime import UTC, datetime, timedelta
from typing import Any

from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

from ccaa_calendar.integrations.google_oauth import read_json
from ccaa_calendar.models import Event
from ccaa_calendar.settings import Settings


class GoogleCalendarTokenMissingError(RuntimeError):
    pass


class GoogleCalendarSyncError(RuntimeError):
    pass


ACADEMIC_EVENT_KEYWORDS = {
    "academ",
    "asamblea",
    "catedra",
    "cátedra",
    "centro",
    "clase",
    "comision",
    "consejo",
    "dae",
    "evaluacion",
    "evaluación",
    "examen",
    "facultad",
    "psico",
    "psicologia",
    "psicología",
    "reunion",
    "reunión",
    "seminario",
    "taller",
    "trabajo",
}


def _calendar_service(settings: Settings):
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
    calendar_id = token.get("calendar_id") or settings.google_calendar_id
    service = build("calendar", "v3", credentials=credentials, cache_discovery=False)
    return service, calendar_id


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
                "ccaa_calendar_event_id": event.id,
                "ccaa_calendar_source": event.source,
                "ccaa_calendar_visibility": event.visibility,
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
    service, calendar_id = _calendar_service(settings)
    return (
        service.events()
        .insert(calendarId=calendar_id, body=google_event_payload(event), sendUpdates="all")
        .execute()
    )


def google_calendar_events(settings: Settings, max_results: int = 40) -> list[dict[str, Any]]:
    service, calendar_id = _calendar_service(settings)
    now = datetime.now(UTC)
    until = now + timedelta(days=120)
    try:
        response = (
            service.events()
            .list(
                calendarId=calendar_id,
                timeMin=now.isoformat(),
                timeMax=until.isoformat(),
                maxResults=max_results,
                singleEvents=True,
                orderBy="startTime",
            )
            .execute()
        )
    except Exception as exc:
        raise GoogleCalendarSyncError(str(exc)) from exc

    return [
        _normalize_google_event(item)
        for item in response.get("items", [])
        if _is_calendar_event_allowed(item)
    ]


def _is_calendar_event_allowed(item: dict[str, Any]) -> bool:
    text = f"{item.get('summary', '')} {item.get('description', '')}".casefold()
    return any(keyword in text for keyword in ACADEMIC_EVENT_KEYWORDS)


def _normalize_google_event(item: dict[str, Any]) -> dict[str, Any]:
    start = item.get("start", {})
    end = item.get("end", {})
    starts_at = start.get("dateTime") or f"{start.get('date')}T00:00:00"
    ends_at = end.get("dateTime") or f"{end.get('date')}T23:59:00"
    return {
        "id": f"google-{item.get('id')}",
        "google_event_id": item.get("id"),
        "title": item.get("summary") or "Evento sin titulo",
        "description": item.get("description") or "",
        "category": "google",
        "visibility": "center",
        "source": "google_calendar",
        "status": item.get("status") or "confirmed",
        "starts_at": starts_at,
        "ends_at": ends_at,
        "all_day": "date" in start,
    }

