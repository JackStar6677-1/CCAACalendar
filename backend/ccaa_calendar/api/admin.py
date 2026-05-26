from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import CurrentAdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.models import AuditLog, User
from ccaa_calendar.schemas import AdminUserRead, AdminUserUpdate, AuditLogRead

router = APIRouter(prefix="/api/admin", tags=["admin"])
SessionDep = Annotated[Session, Depends(get_session)]
ADMIN_ROLES = {"admin", "owner"}


@router.get("/users", response_model=list[AdminUserRead])
def list_admin_users(current_user: CurrentAdminUserDep, session: SessionDep) -> list[User]:
    _require_admin(current_user)
    stmt = (
        select(User)
        .where(User.organization_id == current_user.organization_id)
        .order_by(User.is_active.desc(), User.display_name)
    )
    return list(session.scalars(stmt))


@router.patch("/users/{user_id}", response_model=AdminUserRead)
def update_admin_user(
    user_id: str,
    payload: AdminUserUpdate,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
) -> User:
    """Actualiza permisos operativos sin tocar RUT ni contraseña de la usuaria."""
    _require_admin(current_user)
    user = session.get(User, user_id)
    if not user or user.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Usuaria no encontrada.")

    changes = {}
    if payload.role is not None and payload.role != user.role:
        _guard_self_lockout(current_user, user, "No puedes cambiar tu propio rol aqui.")
        changes["role"] = {"from": user.role, "to": payload.role}
        user.role = payload.role
    if payload.is_active is not None and payload.is_active != user.is_active:
        _guard_self_lockout(current_user, user, "No puedes pausar tu propia cuenta.")
        changes["is_active"] = {"from": user.is_active, "to": payload.is_active}
        user.is_active = payload.is_active
    if (
        payload.email_notifications_enabled is not None
        and payload.email_notifications_enabled != user.email_notifications_enabled
    ):
        changes["email_notifications_enabled"] = {
            "from": user.email_notifications_enabled,
            "to": payload.email_notifications_enabled,
        }
        user.email_notifications_enabled = payload.email_notifications_enabled

    if changes:
        session.add(user)
        session.add(
            AuditLog(
                organization_id=current_user.organization_id,
                actor_user_id=current_user.id,
                action="admin.user_update",
                entity_type="user",
                entity_id=user.id,
                payload=changes,
            )
        )
        session.commit()
        session.refresh(user)
    return user


@router.get("/audit", response_model=list[AuditLogRead])
def list_audit_log(
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    limit: int = Query(default=30, ge=1, le=100),
) -> list[AuditLog]:
    _require_admin(current_user)
    stmt = (
        select(AuditLog)
        .where(AuditLog.organization_id == current_user.organization_id)
        .order_by(AuditLog.created_at.desc())
        .limit(limit)
    )
    return list(session.scalars(stmt))


def _require_admin(user: User) -> None:
    if user.role not in ADMIN_ROLES:
        raise HTTPException(status_code=403, detail="Permiso de administracion requerido.")


def _guard_self_lockout(current_user: User, target_user: User, detail: str) -> None:
    if current_user.id == target_user.id:
        raise HTTPException(status_code=400, detail=detail)
