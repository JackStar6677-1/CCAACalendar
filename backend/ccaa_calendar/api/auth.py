from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.domain.admin_roster import AdminRosterEntry, load_admin_roster
from ccaa_calendar.domain.rut import mask_rut, normalize_rut, rut_hash
from ccaa_calendar.models import AuditLog, Organization, User
from ccaa_calendar.schemas import (
    AuthActivateRequest,
    AuthLoginRequest,
    AuthSessionRead,
    PasswordResetRequest,
    PasswordResetResponse,
)
from ccaa_calendar.security import (
    hash_password,
    hash_token,
    new_public_token,
    reset_token_expiry,
    utcnow,
    verify_password,
)
from ccaa_calendar.settings import Settings, get_settings

router = APIRouter(prefix="/api/auth", tags=["auth"])
SessionDep = Annotated[Session, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_settings)]

GENERIC_RESET_MESSAGE = (
    "Si los datos existen y estan activos, enviaremos instrucciones al correo asociado."
)


def _default_organization(session: Session) -> Organization:
    organization = session.scalar(select(Organization).order_by(Organization.created_at))
    if organization:
        return organization

    organization = Organization(
        name="Universidad Demo",
        slug="universidad-demo",
        domain_hint="demo.edu",
        brand_config={"public_name": "CCAACalendar"},
    )
    session.add(organization)
    session.flush()
    return organization


def _audit(
    session: Session,
    organization_id: str,
    action: str,
    entity_type: str,
    entity_id: str,
    actor_user_id: str | None = None,
    payload: dict | None = None,
) -> None:
    session.add(
        AuditLog(
            organization_id=organization_id,
            actor_user_id=actor_user_id,
            action=action,
            entity_type=entity_type,
            entity_id=entity_id,
            payload=payload or {},
        )
    )


def _roster_entry(settings: Settings, rut: str) -> AdminRosterEntry | None:
    target_hash = rut_hash(normalize_rut(rut), settings.admin_identity_pepper)
    for entry in load_admin_roster(settings.admin_roster_path, settings.admin_identity_pepper):
        if entry.rut_hash == target_hash:
            return entry
    return None


def _user_by_rut_hash(session: Session, organization_id: str, rut_hash_value: str) -> User | None:
    return session.scalar(
        select(User).where(
            User.organization_id == organization_id,
            User.rut_hash == rut_hash_value,
        )
    )


def _session_payload(user: User, token: str) -> AuthSessionRead:
    return AuthSessionRead(
        token=token,
        user_id=user.id,
        display_name=user.display_name,
        email=user.email,
        role=user.role,
        rut_masked=user.rut_masked,
    )


@router.post("/activate", response_model=AuthSessionRead, status_code=status.HTTP_201_CREATED)
def activate_admin_access(
    payload: AuthActivateRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> AuthSessionRead:
    entry = _roster_entry(settings, payload.rut)
    if not entry or not entry.can_login:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="El RUT no esta habilitado para activar acceso.",
        )

    organization = _default_organization(session)
    existing = _user_by_rut_hash(session, organization.id, entry.rut_hash)
    if existing and existing.password_hash:
        raise HTTPException(status_code=409, detail="Este administrador ya activo su acceso.")

    user = existing or User(
        organization_id=organization.id,
        rut_hash=entry.rut_hash,
        rut_masked=entry.rut_masked,
        email=entry.email,
        display_name=entry.display_name,
        role=entry.role,
        is_active=True,
    )
    user.password_hash = hash_password(payload.password)
    user.last_login_at = utcnow()
    session.add(user)
    session.flush()
    token = new_public_token()
    _audit(
        session,
        organization.id,
        "auth.activate",
        "user",
        user.id,
        actor_user_id=user.id,
        payload={"rut_masked": user.rut_masked, "role": user.role},
    )
    session.commit()
    return _session_payload(user, token)


@router.post("/login", response_model=AuthSessionRead)
def login_admin(
    payload: AuthLoginRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> AuthSessionRead:
    organization = _default_organization(session)
    rut_hash_value = rut_hash(normalize_rut(payload.rut), settings.admin_identity_pepper)
    user = _user_by_rut_hash(session, organization.id, rut_hash_value)
    if not user or not user.is_active or not verify_password(payload.password, user.password_hash):
        raise HTTPException(status_code=401, detail="Credenciales invalidas.")

    user.last_login_at = utcnow()
    token = new_public_token()
    _audit(
        session,
        organization.id,
        "auth.login",
        "user",
        user.id,
        actor_user_id=user.id,
        payload={"rut_masked": user.rut_masked or mask_rut(payload.rut)},
    )
    session.commit()
    return _session_payload(user, token)


@router.post("/password-reset/request", response_model=PasswordResetResponse)
def request_password_reset(
    payload: PasswordResetRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> PasswordResetResponse:
    organization = _default_organization(session)
    rut_hash_value = rut_hash(normalize_rut(payload.rut), settings.admin_identity_pepper)
    user = _user_by_rut_hash(session, organization.id, rut_hash_value)
    if user and user.is_active:
        reset_token = new_public_token()
        user.password_reset_token_hash = hash_token(reset_token)
        user.password_reset_expires_at = reset_token_expiry()
        _audit(
            session,
            organization.id,
            "auth.password_reset_requested",
            "user",
            user.id,
            actor_user_id=user.id,
            payload={"delivery": "email_pending", "rut_masked": user.rut_masked},
        )
        session.commit()

    return PasswordResetResponse(message=GENERIC_RESET_MESSAGE)

