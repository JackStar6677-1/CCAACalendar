from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.models import Center, Organization
from ccaa_calendar.schemas import CenterCreate, CenterRead

router = APIRouter(prefix="/api/centers", tags=["centers"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("", response_model=list[CenterRead])
def list_centers(session: SessionDep) -> list[Center]:
    return list(session.scalars(select(Center).order_by(Center.name)))


@router.post("", response_model=CenterRead, status_code=status.HTTP_201_CREATED)
def create_center(payload: CenterCreate, session: SessionDep) -> Center:
    organization = session.get(Organization, payload.organization_id)
    if not organization:
        raise HTTPException(status_code=404, detail="Organization not found.")

    exists = session.scalar(
        select(Center).where(
            Center.organization_id == payload.organization_id,
            Center.slug == payload.slug,
        )
    )
    if exists:
        raise HTTPException(status_code=409, detail="Center slug already exists.")

    center = Center(**payload.model_dump())
    session.add(center)
    session.commit()
    session.refresh(center)
    return center

