from typing import Annotated

from fastapi import APIRouter, Depends, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import CurrentAdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.models import AuditLog, User
from ccaa_calendar.schemas import AdminUserRead, AuditLogRead

router = APIRouter(prefix="/api/admin", tags=["admin"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("/users", response_model=list[AdminUserRead])
def list_admin_users(current_user: CurrentAdminUserDep, session: SessionDep) -> list[User]:
    stmt = (
        select(User)
        .where(User.organization_id == current_user.organization_id)
        .order_by(User.is_active.desc(), User.display_name)
    )
    return list(session.scalars(stmt))


@router.get("/audit", response_model=list[AuditLogRead])
def list_audit_log(
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    limit: int = Query(default=30, ge=1, le=100),
) -> list[AuditLog]:
    stmt = (
        select(AuditLog)
        .where(AuditLog.organization_id == current_user.organization_id)
        .order_by(AuditLog.created_at.desc())
        .limit(limit)
    )
    return list(session.scalars(stmt))
