from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import CurrentAdminUserDep, _notify_access_request_admins
from ccaa_calendar.database import get_session
from ccaa_calendar.domain.pii import reveal_text
from ccaa_calendar.models import AccessRequest, AuditLog, User
from ccaa_calendar.schemas import (
    AccessRequestDecision,
    AccessRequestRead,
    AdminUserRead,
    AdminUserUpdate,
    AuditLogRead,
)
from ccaa_calendar.security import utcnow
from ccaa_calendar.settings import Settings, get_settings

router = APIRouter(prefix="/api/admin", tags=["admin"])
SessionDep = Annotated[Session, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_settings)]
ADMIN_ROLES = {"admin", "owner"}


@router.get("/access-requests", response_model=list[AccessRequestRead])
def list_access_requests(
    current_user: CurrentAdminUserDep, session: SessionDep, settings: SettingsDep
) -> list[AccessRequestRead]:
    _require_admin(current_user)
    requests = list(
        session.scalars(
            select(AccessRequest)
            .where(AccessRequest.organization_id == current_user.organization_id)
            .order_by(AccessRequest.created_at.desc())
        )
    )
    return [_access_request_payload(request, settings) for request in requests]


@router.patch("/access-requests/{request_id}", response_model=AccessRequestRead)
def decide_access_request(
    request_id: str,
    payload: AccessRequestDecision,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    settings: SettingsDep,
) -> AccessRequestRead:
    """Aprueba la activacion posterior o rechaza; nunca crea claves por terceros."""
    _require_admin(current_user)
    request = session.get(AccessRequest, request_id)
    if not request or request.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Solicitud no encontrada.")
    if request.status != "pending":
        raise HTTPException(status_code=409, detail="La solicitud ya fue revisada.")

    request.status = payload.decision
    if payload.decision == "approved" and payload.role:
        request.desired_role = payload.role
    request.reviewed_by_user_id = current_user.id
    request.reviewed_at = utcnow()
    session.add(request)
    session.add(
        AuditLog(
            organization_id=current_user.organization_id,
            actor_user_id=current_user.id,
            action=f"access_request.{payload.decision}",
            entity_type="access_request",
            entity_id=request.id,
            payload={"rut_masked": request.rut_masked, "role": request.desired_role},
        )
    )
    session.commit()
    session.refresh(request)
    return _access_request_payload(request, settings)


@router.post("/access-requests/{request_id}/notify", response_model=AccessRequestRead)
def resend_access_request_notice(
    request_id: str,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    settings: SettingsDep,
) -> AccessRequestRead:
    _require_admin(current_user)
    request = session.get(AccessRequest, request_id)
    if not request or request.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Solicitud no encontrada.")
    request.notification_status = _notify_access_request_admins(session, settings, request)
    session.add(request)
    session.add(
        AuditLog(
            organization_id=current_user.organization_id,
            actor_user_id=current_user.id,
            action="access_request.notification_retry",
            entity_type="access_request",
            entity_id=request.id,
            payload={"notification_status": request.notification_status},
        )
    )
    session.commit()
    session.refresh(request)
    return _access_request_payload(request, settings)


@router.get("/users", response_model=list[AdminUserRead])
def list_admin_users(
    current_user: CurrentAdminUserDep, session: SessionDep, settings: SettingsDep
) -> list[AdminUserRead]:
    _require_admin(current_user)
    users = list(
        session.scalars(select(User).where(User.organization_id == current_user.organization_id))
    )
    users.sort(
        key=lambda user: (
            not user.is_active,
            (reveal_text(user.display_name, settings) or "").casefold(),
        )
    )
    return [_admin_user_payload(user, settings) for user in users]


@router.patch("/users/{user_id}", response_model=AdminUserRead)
def update_admin_user(
    user_id: str,
    payload: AdminUserUpdate,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    settings: SettingsDep,
) -> AdminUserRead:
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
    return _admin_user_payload(user, settings)


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


def _admin_user_payload(user: User, settings: Settings) -> AdminUserRead:
    return AdminUserRead(
        id=user.id,
        display_name=reveal_text(user.display_name, settings) or "",
        email=reveal_text(user.email, settings) or "",
        role=user.role,
        rut_masked=user.rut_masked,
        is_active=user.is_active,
        email_notifications_enabled=user.email_notifications_enabled,
        last_login_at=user.last_login_at,
        created_at=user.created_at,
    )


def _access_request_payload(request: AccessRequest, settings: Settings) -> AccessRequestRead:
    return AccessRequestRead(
        id=request.id,
        display_name=reveal_text(request.display_name, settings) or "",
        email=reveal_text(request.email, settings) or "",
        rut_masked=request.rut_masked,
        desired_role=request.desired_role,
        note=reveal_text(request.note, settings) or "",
        status=request.status,
        notification_status=request.notification_status,
        created_at=request.created_at,
        reviewed_at=request.reviewed_at,
    )
