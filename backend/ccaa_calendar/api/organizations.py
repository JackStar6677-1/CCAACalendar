from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import AdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.models import Organization
from ccaa_calendar.schemas import OrganizationCreate, OrganizationRead

router = APIRouter(prefix="/api/organizations", tags=["organizations"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("", response_model=list[OrganizationRead])
def list_organizations(session: SessionDep) -> list[Organization]:
    return list(session.scalars(select(Organization).order_by(Organization.name)))


@router.post("", response_model=OrganizationRead, status_code=status.HTTP_201_CREATED)
def create_organization(
    payload: OrganizationCreate,
    current_user: AdminUserDep,
    session: SessionDep,
) -> Organization:
    del current_user
    exists = session.scalar(select(Organization).where(Organization.slug == payload.slug))
    if exists:
        raise HTTPException(status_code=409, detail="Organization slug already exists.")

    organization = Organization(
        name=payload.name,
        slug=payload.slug,
        domain_hint=payload.domain_hint,
        brand_config={"public_name": payload.name},
    )
    session.add(organization)
    session.commit()
    session.refresh(organization)
    return organization

