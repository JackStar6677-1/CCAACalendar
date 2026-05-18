from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import and_, select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import CurrentAdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.models import AuditLog, Event, Organization, Space
from ccaa_calendar.schemas import EventRead, SpaceCreate, SpaceRead, SpaceReservationCreate

router = APIRouter(prefix="/api/spaces", tags=["spaces"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("", response_model=list[SpaceRead])
def list_spaces(
    session: SessionDep,
    organization_id: str | None = Query(default=None),
) -> list[Space]:
    stmt = select(Space).where(Space.is_active.is_(True)).order_by(Space.name)
    if organization_id:
        stmt = stmt.where(Space.organization_id == organization_id)
    return list(session.scalars(stmt))


@router.post("", response_model=SpaceRead, status_code=status.HTTP_201_CREATED)
def create_space(
    payload: SpaceCreate,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
) -> Space:
    if payload.organization_id != current_user.organization_id:
        raise HTTPException(
            status_code=403,
            detail="No puedes crear espacios en otra organizacion.",
        )

    organization = session.get(Organization, payload.organization_id)
    if not organization:
        raise HTTPException(status_code=404, detail="Organization not found.")

    exists = session.scalar(
        select(Space).where(
            Space.organization_id == payload.organization_id,
            Space.slug == payload.slug,
        )
    )
    if exists:
        raise HTTPException(status_code=409, detail="Space slug already exists.")

    space = Space(**payload.model_dump())
    session.add(space)
    session.flush()
    session.add(
        AuditLog(
            organization_id=payload.organization_id,
            actor_user_id=current_user.id,
            action="space.create",
            entity_type="space",
            entity_id=space.id,
            payload={"name": space.name, "slug": space.slug},
        )
    )
    session.commit()
    session.refresh(space)
    return space


@router.get("/reservations", response_model=list[EventRead])
def list_space_reservations(
    session: SessionDep,
    organization_id: str | None = Query(default=None),
    space_id: str | None = Query(default=None),
) -> list[Event]:
    stmt = (
        select(Event)
        .where(Event.space_id.is_not(None), Event.category == "espacio")
        .order_by(Event.starts_at)
    )
    if organization_id:
        stmt = stmt.where(Event.organization_id == organization_id)
    if space_id:
        stmt = stmt.where(Event.space_id == space_id)
    return list(session.scalars(stmt))


@router.post("/reservations", response_model=EventRead, status_code=status.HTTP_201_CREATED)
def create_space_reservation(
    payload: SpaceReservationCreate,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
) -> Event:
    if payload.organization_id != current_user.organization_id:
        raise HTTPException(status_code=403, detail="No puedes reservar en otra organizacion.")
    if payload.ends_at <= payload.starts_at:
        raise HTTPException(status_code=422, detail="Reservation end must be after start.")

    space = session.get(Space, payload.space_id)
    if not space or space.organization_id != payload.organization_id or not space.is_active:
        raise HTTPException(status_code=404, detail="Space not found.")

    conflict = session.scalar(
        select(Event)
        .where(
            Event.organization_id == payload.organization_id,
            Event.space_id == payload.space_id,
            Event.status != "cancelled",
            and_(Event.starts_at < payload.ends_at, Event.ends_at > payload.starts_at),
        )
        .limit(1)
    )
    if conflict:
        raise HTTPException(
            status_code=409,
            detail=f"El espacio ya esta ocupado por: {conflict.title}",
        )

    event = Event(
        organization_id=payload.organization_id,
        center_id=payload.center_id,
        space_id=payload.space_id,
        title=payload.title,
        description=payload.description,
        category="espacio",
        visibility="organization",
        starts_at=payload.starts_at,
        ends_at=payload.ends_at,
        created_by_user_id=current_user.id,
        metadata_json={"space_name": space.name},
    )
    session.add(event)
    session.flush()
    session.add(
        AuditLog(
            organization_id=payload.organization_id,
            actor_user_id=current_user.id,
            action="space.reserve",
            entity_type="event",
            entity_id=event.id,
            payload={"space_id": space.id, "space_name": space.name, "title": event.title},
        )
    )
    session.commit()
    session.refresh(event)
    return event
