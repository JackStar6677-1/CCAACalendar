import logging
from datetime import UTC, datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import EditorUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.domain.event_notifications import (
    enqueue_event_notifications,
    process_due_email_queue,
)
from ccaa_calendar.models import AuditLog, Event, Organization
from ccaa_calendar.schemas import EventCreate, EventRead, EventUpdate
from ccaa_calendar.settings import Settings, get_settings

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/events", tags=["events"])
SessionDep = Annotated[Session, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_settings)]


def _utc_comparable(value: datetime) -> datetime:
    """Normaliza valores de SQLite que pueden regresar sin zona horaria."""
    if value.tzinfo is None:
        return value.replace(tzinfo=UTC)
    return value.astimezone(UTC)


@router.get("", response_model=list[EventRead])
def list_events(
    session: SessionDep,
    organization_id: str | None = Query(default=None),
) -> list[Event]:
    stmt = select(Event).order_by(Event.starts_at)
    if organization_id:
        stmt = stmt.where(Event.organization_id == organization_id)
    return list(session.scalars(stmt))


@router.post("", response_model=EventRead, status_code=status.HTTP_201_CREATED)
def create_event(
    payload: EventCreate,
    current_user: EditorUserDep,
    session: SessionDep,
    settings: SettingsDep,
) -> Event:
    if payload.ends_at <= payload.starts_at:
        raise HTTPException(status_code=422, detail="Event end must be after start.")
    if payload.organization_id != current_user.organization_id:
        raise HTTPException(status_code=403, detail="No puedes crear eventos en otra organizacion.")

    organization = session.get(Organization, payload.organization_id)
    if not organization:
        raise HTTPException(status_code=404, detail="Organization not found.")

    event_fields = payload.model_dump(exclude={"notify_subscribers", "created_by_user_id"})
    event_fields["created_by_user_id"] = current_user.id
    event = Event(**event_fields)
    session.add(event)
    session.flush()
    session.add(
        AuditLog(
            organization_id=organization.id,
            actor_user_id=current_user.id,
            action="event.create",
            entity_type="event",
            entity_id=event.id,
            payload={"title": event.title, "category": event.category},
        )
    )
    if payload.notify_subscribers:
        try:
            queue_stats = enqueue_event_notifications(settings, session, event)
            mail_stats = process_due_email_queue(settings, session, event_id=event.id)
            session.add(
                AuditLog(
                    organization_id=organization.id,
                    actor_user_id=current_user.id,
                    action="event.notify_queued",
                    entity_type="event",
                    entity_id=event.id,
                    payload={
                        **queue_stats,
                        **mail_stats,
                    },
                )
            )
        except Exception as exc:
            logger.warning("Event notification pipeline failed for %s: %s", event.id, exc)

    session.commit()
    session.refresh(event)
    return event


@router.patch("/{event_id}", response_model=EventRead)
def update_event(
    event_id: str,
    payload: EventUpdate,
    current_user: EditorUserDep,
    session: SessionDep,
) -> Event:
    """Edita un evento local conservando su enlace para sincronizar Google."""
    event = session.get(Event, event_id)
    if not event or event.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Evento no encontrado.")
    if event.source == "google_calendar":
        raise HTTPException(
            status_code=409,
            detail="Edita eventos importados desde Google en Google Calendar.",
        )

    changes = payload.model_dump(exclude_unset=True)
    starts_at = changes.get("starts_at", event.starts_at)
    ends_at = changes.get("ends_at", event.ends_at)
    if _utc_comparable(ends_at) <= _utc_comparable(starts_at):
        raise HTTPException(status_code=422, detail="El termino debe ser posterior al inicio.")

    for field, value in changes.items():
        setattr(event, field, value)
    event.status = "confirmed"
    session.add(event)
    session.add(
        AuditLog(
            organization_id=event.organization_id,
            actor_user_id=current_user.id,
            action="event.update",
            entity_type="event",
            entity_id=event.id,
            payload={"fields": sorted(changes), "title": event.title},
        )
    )
    session.commit()
    session.refresh(event)
    return event


@router.delete("/{event_id}", response_model=EventRead)
def cancel_event(
    event_id: str,
    current_user: EditorUserDep,
    session: SessionDep,
) -> Event:
    """Cancela sin borrar para mantener el historial disponible en auditoria."""
    event = session.get(Event, event_id)
    if not event or event.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Evento no encontrado.")
    if event.source == "google_calendar":
        raise HTTPException(
            status_code=409,
            detail="Cancela eventos externos desde Google Calendar.",
        )

    event.status = "cancelled"
    session.add(event)
    session.add(
        AuditLog(
            organization_id=event.organization_id,
            actor_user_id=current_user.id,
            action="event.cancel",
            entity_type="event",
            entity_id=event.id,
            payload={"title": event.title, "google_event_id": event.google_event_id},
        )
    )
    session.commit()
    session.refresh(event)
    return event

