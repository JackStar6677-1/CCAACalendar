from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import AdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.models import Center, Organization
from ccaa_calendar.schemas import CenterCreate, CenterRead

router = APIRouter(prefix="/api/centers", tags=["centers"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("", response_model=list[CenterRead])
def list_centers(session: SessionDep) -> list[dict]:
    """Expone capas publicas sin revelar la direccion de correo del centro."""
    return [
        {
            "id": center.id,
            "organization_id": center.organization_id,
            "name": center.name,
            "slug": center.slug,
            "official_email": None,
            "color": center.color,
            "is_active": center.is_active,
        }
        for center in session.scalars(select(Center).order_by(Center.name))
    ]


@router.post("", response_model=CenterRead, status_code=status.HTTP_201_CREATED)
def create_center(payload: CenterCreate, current_user: AdminUserDep, session: SessionDep) -> Center:
    if payload.organization_id != current_user.organization_id:
        raise HTTPException(status_code=403, detail="No puedes crear centros en otra organizacion.")
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

