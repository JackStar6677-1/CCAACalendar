from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.models import AuditLog, Event, Organization, User
from ccaa_calendar.schemas import EventCreate, EventRead

router = APIRouter(prefix="/api/events", tags=["events"])
SessionDep = Annotated[Session, Depends(get_session)]


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
def create_event(payload: EventCreate, session: SessionDep) -> Event:
    if payload.ends_at <= payload.starts_at:
        raise HTTPException(status_code=422, detail="Event end must be after start.")

    organization = session.get(Organization, payload.organization_id)
    if not organization:
        raise HTTPException(status_code=404, detail="Organization not found.")

    if payload.created_by_user_id:
        user = session.get(User, payload.created_by_user_id)
        if not user or user.organization_id != organization.id:
            raise HTTPException(status_code=422, detail="Invalid event creator.")

    event = Event(**payload.model_dump())
    session.add(event)
    session.flush()
    if payload.created_by_user_id:
        session.add(
            AuditLog(
                organization_id=organization.id,
                actor_user_id=payload.created_by_user_id,
                action="event.create",
                entity_type="event",
                entity_id=event.id,
                payload={"title": event.title, "category": event.category},
            )
        )
    session.commit()
    session.refresh(event)
    return event

