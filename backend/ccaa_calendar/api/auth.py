import hashlib
import logging
from datetime import UTC, datetime, timedelta
from typing import Annotated

from fastapi import APIRouter, Depends, Header, HTTPException, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.domain.admin_roster import AdminRosterEntry, load_admin_roster
from ccaa_calendar.domain.pii import lookup_hash, protect_text, reveal_text
from ccaa_calendar.domain.rut import is_valid_rut, mask_rut, normalize_rut, rut_hash
from ccaa_calendar.integrations.google_calendar import token_metadata
from ccaa_calendar.integrations.google_oauth import is_google_oauth_configured
from ccaa_calendar.integrations.mail_delivery import MailDeliveryError, MailNotConfiguredError
from ccaa_calendar.integrations.transactional_mail import (
    access_request_admin_email,
    password_reset_email,
)
from ccaa_calendar.models import AccessRequest, AuditLog, Center, Organization, User
from ccaa_calendar.schemas import (
    AccessRequestCreate,
    AccessRequestReceipt,
    AuthActivateRequest,
    AuthLoginRequest,
    AuthLookupRequest,
    AuthLookupResponse,
    AuthSessionRead,
    LoginBootstrapRead,
    PasswordResetConfirmRequest,
    PasswordResetRequest,
    PasswordResetResponse,
    UserNotificationPreferencesUpdate,
    UserProfileRead,
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

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/auth", tags=["auth"])
SessionDep = Annotated[Session, Depends(get_session)]
SettingsDep = Annotated[Settings, Depends(get_settings)]
AuthorizationDep = Annotated[str | None, Header(alias="Authorization")]

ACTIVE_SESSIONS: dict[str, str] = {}
ACCESS_REQUEST_ATTEMPTS: dict[str, list[datetime]] = {}
EDITOR_ROLES = {"editor", "admin", "owner"}
ADMIN_ROLES = {"admin", "owner"}
ACCESS_REQUEST_LIMIT_PER_HOUR = 5

GENERIC_RESET_MESSAGE = (
    "Si los datos existen y estan activos, enviaremos instrucciones al correo asociado."
)


def _split_display_name(display_name: str) -> tuple[str, str]:
    parts = display_name.strip().split(None, 1)
    if len(parts) == 2:
        return parts[0], parts[1]
    if parts:
        return parts[0], ""
    return "", ""


def _compose_display_name(
    first_name: str | None,
    last_name: str | None,
    fallback: str,
) -> str:
    first = (first_name or "").strip()
    last = (last_name or "").strip()
    if first and last:
        return f"{first} {last}"
    if first:
        return first
    if last:
        return last
    return fallback.strip() or "Administradora"


def _normalize_email(value: str | None) -> str:
    return str(value or "").strip().lower()


def _consume_access_request_quota(request: Request) -> bool:
    """Limite liviano para el tunel; en produccion debe reforzarse en proxy/Redis."""
    source = (
        request.headers.get("CF-Connecting-IP")
        or (request.client.host if request.client else "unknown")
    )
    key = hashlib.sha256(source.encode("utf-8")).hexdigest()
    threshold = utcnow() - timedelta(hours=1)
    recent = [attempt for attempt in ACCESS_REQUEST_ATTEMPTS.get(key, []) if attempt > threshold]
    if len(recent) >= ACCESS_REQUEST_LIMIT_PER_HOUR:
        ACCESS_REQUEST_ATTEMPTS[key] = recent
        return False
    recent.append(utcnow())
    ACCESS_REQUEST_ATTEMPTS[key] = recent
    return True


def _default_organization(session: Session) -> Organization:
    organization = session.scalar(
        select(Organization).where(Organization.slug == "ccaa-psicologia")
    )
    if organization:
        organization.name = "Centro de Estudiantes de Psicología · UDLA Maipú"
        organization.domain_hint = "udla-maipu-psicologia"
        brand = dict(organization.brand_config or {})
        brand["public_name"] = organization.name
        brand["university"] = "Universidad de las Américas (UDLA)"
        brand["campus"] = "Maipú"
        organization.brand_config = brand
        session.add(organization)
        return organization

    organization = Organization(
        name="Centro de Estudiantes de Psicología · UDLA Maipú",
        slug="ccaa-psicologia",
        domain_hint="udla-maipu-psicologia",
        brand_config={
            "public_name": "Centro de Estudiantes de Psicología · UDLA Maipú",
            "university": "Universidad de las Américas (UDLA)",
            "campus": "Maipú",
        },
    )
    session.add(organization)
    session.flush()
    return organization


def _default_center(session: Session, organization: Organization, settings: Settings) -> Center:
    center = session.scalar(
        select(Center).where(
            Center.organization_id == organization.id,
            Center.slug == "psicologia",
        )
    )
    official_email = settings.google_center_account_email.strip() or None
    if center:
        center.name = "Centro de Estudiantes de Psicología"
        if official_email and not center.official_email:
            center.official_email = protect_text(official_email, settings)
        session.add(center)
        return center

    center = Center(
        organization_id=organization.id,
        name="Centro de Estudiantes de Psicología",
        slug="psicologia",
        official_email=protect_text(official_email, settings),
        color="#7b3fe4",
    )
    session.add(center)
    session.flush()
    return center


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
    for entry in load_admin_roster(
        settings.admin_roster_path, settings.admin_identity_pepper, settings
    ):
        if entry.rut_hash == target_hash:
            return entry
    return None


def _approved_request_entry(
    session: Session,
    settings: Settings,
    organization_id: str,
    rut: str,
) -> AdminRosterEntry | None:
    normalized_rut = normalize_rut(rut)
    target_hash = rut_hash(normalized_rut, settings.admin_identity_pepper)
    request = session.scalar(
        select(AccessRequest)
        .where(
            AccessRequest.organization_id == organization_id,
            AccessRequest.rut_hash == target_hash,
            AccessRequest.status == "approved",
        )
        .order_by(AccessRequest.reviewed_at.desc(), AccessRequest.created_at.desc())
    )
    if not request:
        return None
    return AdminRosterEntry(
        rut=normalized_rut,
        email=reveal_text(request.email, settings) or "",
        display_name=reveal_text(request.display_name, settings) or "",
        role=request.desired_role,
        status="active",
        is_valid_rut=True,
        rut_masked=request.rut_masked,
        rut_hash=request.rut_hash,
    )


def _authorized_entry(
    session: Session,
    settings: Settings,
    organization_id: str,
    rut: str,
) -> AdminRosterEntry | None:
    return _roster_entry(settings, rut) or _approved_request_entry(
        session, settings, organization_id, rut
    )


def _notify_access_request_admins(
    session: Session,
    settings: Settings,
    request: AccessRequest,
) -> str:
    """Envia avisos sin convertir un fallo de Gmail en perdida de solicitud."""
    admins = list(
        session.scalars(
            select(User).where(
                User.organization_id == request.organization_id,
                User.role.in_(ADMIN_ROLES),
                User.is_active.is_(True),
                User.email_notifications_enabled.is_(True),
            )
        )
    )
    if not admins:
        return "no_admin"

    delivered = 0
    not_configured = False
    for admin in admins:
        try:
            access_request_admin_email(settings, request, admin)
            delivered += 1
        except MailNotConfiguredError:
            not_configured = True
        except MailDeliveryError as exc:
            logger.warning(
                "Access request email failed request=%s admin=%s: %s",
                request.id,
                admin.id,
                exc,
            )

    if delivered:
        request.admin_notified_at = utcnow()
        return "sent"
    if not_configured:
        return "awaiting_gmail"
    return "failed"


def _user_by_rut_hash(session: Session, organization_id: str, rut_hash_value: str) -> User | None:
    return session.scalar(
        select(User).where(
            User.organization_id == organization_id,
            User.rut_hash == rut_hash_value,
        )
    )


def _user_by_email(
    session: Session, organization_id: str, email: str, settings: Settings
) -> User | None:
    return session.scalar(
        select(User).where(
            User.organization_id == organization_id,
            User.email_lookup_hash == lookup_hash(email, settings),
        )
    )


def _lookup_hints(entry: AdminRosterEntry) -> dict[str, str | None]:
    first_name_hint, last_name_hint = _split_display_name(entry.display_name)
    return {
        "display_name_hint": entry.display_name or None,
        "first_name_hint": first_name_hint or None,
        "last_name_hint": last_name_hint or None,
        "email_hint": entry.email or None,
        "role_hint": entry.role,
    }


def _session_payload(user: User, token: str, settings: Settings) -> AuthSessionRead:
    ACTIVE_SESSIONS[token] = user.id
    return AuthSessionRead(
        token=token,
        user_id=user.id,
        display_name=reveal_text(user.display_name, settings) or "",
        email=reveal_text(user.email, settings) or "",
        role=user.role,
        rut_masked=user.rut_masked,
        email_notifications_enabled=bool(user.email_notifications_enabled),
    )


def _profile_payload(user: User, settings: Settings) -> UserProfileRead:
    return UserProfileRead(
        user_id=user.id,
        display_name=reveal_text(user.display_name, settings) or "",
        email=reveal_text(user.email, settings) or "",
        role=user.role,
        rut_masked=user.rut_masked,
        email_notifications_enabled=bool(user.email_notifications_enabled),
    )


def current_admin_user(session: SessionDep, authorization: AuthorizationDep = None) -> User:
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Sesion interna requerida.")

    token = authorization.removeprefix("Bearer ").strip()
    user_id = ACTIVE_SESSIONS.get(token)
    if not user_id:
        raise HTTPException(status_code=401, detail="Sesion interna expirada.")

    user = session.get(User, user_id)
    if not user or not user.is_active:
        raise HTTPException(status_code=401, detail="Usuario interno no disponible.")

    return user


CurrentAdminUserDep = Annotated[User, Depends(current_admin_user)]


def editor_user(current_user: CurrentAdminUserDep) -> User:
    """Restringe escrituras del calendario a integrantes autorizadas."""
    if current_user.role not in EDITOR_ROLES:
        raise HTTPException(status_code=403, detail="Permiso de edicion requerido.")
    return current_user


EditorUserDep = Annotated[User, Depends(editor_user)]


def administrator_user(current_user: CurrentAdminUserDep) -> User:
    """Reserva la configuracion del centro y OAuth para la directiva."""
    if current_user.role not in ADMIN_ROLES:
        raise HTTPException(status_code=403, detail="Permiso de administracion requerido.")
    return current_user


AdminUserDep = Annotated[User, Depends(administrator_user)]


@router.get("/me", response_model=UserProfileRead)
def read_profile(current_user: CurrentAdminUserDep, settings: SettingsDep) -> UserProfileRead:
    return _profile_payload(current_user, settings)


@router.patch("/me/notifications", response_model=UserProfileRead)
def update_notification_preferences(
    payload: UserNotificationPreferencesUpdate,
    current_user: CurrentAdminUserDep,
    session: SessionDep,
    settings: SettingsDep,
) -> UserProfileRead:
    current_user.email_notifications_enabled = payload.email_notifications_enabled
    session.add(current_user)
    session.commit()
    session.refresh(current_user)
    return _profile_payload(current_user, settings)


@router.get("/bootstrap", response_model=LoginBootstrapRead)
def login_bootstrap(session: SessionDep, settings: SettingsDep) -> LoginBootstrapRead:
    organization = _default_organization(session)
    center = _default_center(session, organization, settings)
    token = token_metadata(settings)
    official_email = (
        reveal_text(center.official_email, settings)
        or settings.google_center_account_email.strip()
        or None
    )
    session.commit()

    return LoginBootstrapRead(
        organization_name=organization.name,
        center_name=center.name,
        # La portada publica no debe publicar la direccion del correo oficial.
        official_email=None,
        official_email_configured=bool(official_email),
        google_token_present=bool(token.get("token_present")),
        google_ready_to_connect=bool(
            is_google_oauth_configured(settings) and official_email
        ),
    )


@router.post(
    "/access-requests",
    response_model=AccessRequestReceipt,
    status_code=status.HTTP_202_ACCEPTED,
)
def create_access_request(
    payload: AccessRequestCreate,
    request_context: Request,
    session: SessionDep,
    settings: SettingsDep,
) -> AccessRequestReceipt:
    """Registra una postulacion; solo una aprobacion admin puede habilitar activacion."""
    if not _consume_access_request_quota(request_context):
        raise HTTPException(
            status_code=429,
            detail="Demasiadas solicitudes recientes. Intenta nuevamente mas tarde.",
        )
    normalized_rut = normalize_rut(payload.rut)
    if not is_valid_rut(normalized_rut):
        raise HTTPException(status_code=400, detail="Ingresa un RUT valido.")
    if not payload.consent_personal_data:
        raise HTTPException(
            status_code=400,
            detail="Debes autorizar el uso de tus datos para solicitar acceso.",
        )
    email = _normalize_email(payload.email)
    if "@" not in email:
        raise HTTPException(status_code=400, detail="Ingresa un correo valido.")

    organization = _default_organization(session)
    center = _default_center(session, organization, settings)
    target_hash = rut_hash(normalized_rut, settings.admin_identity_pepper)
    existing_user = _user_by_rut_hash(session, organization.id, target_hash)
    if existing_user and existing_user.is_active:
        raise HTTPException(
            status_code=409,
            detail="Este RUT ya tiene acceso. Usa Verificar acceso para ingresar.",
        )
    if _authorized_entry(session, settings, organization.id, normalized_rut):
        raise HTTPException(
            status_code=409,
            detail="Este RUT ya esta habilitado. Usa Verificar acceso para activar tu clave.",
        )

    pending = session.scalar(
        select(AccessRequest).where(
            AccessRequest.organization_id == organization.id,
            AccessRequest.rut_hash == target_hash,
            AccessRequest.status == "pending",
        )
    )
    if pending:
        return AccessRequestReceipt(
            status="pending",
            message="Tu solicitud ya esta pendiente de revision por la directiva.",
        )

    request = AccessRequest(
        organization_id=organization.id,
        center_id=center.id,
        rut_hash=target_hash,
        rut_masked=mask_rut(normalized_rut),
        email=protect_text(email, settings) or "",
        email_lookup_hash=lookup_hash(email, settings),
        display_name=protect_text(
            _compose_display_name(payload.first_name, payload.last_name, ""), settings
        )
        or "",
        desired_role=payload.desired_role,
        note=protect_text(payload.note.strip(), settings) or "",
    )
    session.add(request)
    session.flush()
    request.notification_status = _notify_access_request_admins(session, settings, request)
    _audit(
        session,
        organization.id,
        "access_request.created",
        "access_request",
        request.id,
        payload={
            "rut_masked": request.rut_masked,
            "desired_role": request.desired_role,
            "notification_status": request.notification_status,
        },
    )
    session.commit()
    return AccessRequestReceipt(
        status="pending",
        message="Solicitud enviada. La directiva debe revisarla antes de habilitar tu cuenta.",
    )


@router.post("/lookup", response_model=AuthLookupResponse)
def lookup_auth_status(
    payload: AuthLookupRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> AuthLookupResponse:
    normalized_rut = normalize_rut(payload.rut)
    if not is_valid_rut(normalized_rut):
        return AuthLookupResponse(status="not_in_roster")

    rut_masked = mask_rut(normalized_rut)
    organization = _default_organization(session)
    entry = _authorized_entry(session, settings, organization.id, payload.rut)
    if not entry or not entry.can_login:
        pending = session.scalar(
            select(AccessRequest).where(
                AccessRequest.organization_id == organization.id,
                AccessRequest.rut_hash
                == rut_hash(normalized_rut, settings.admin_identity_pepper),
                AccessRequest.status == "pending",
            )
        )
        if pending:
            return AuthLookupResponse(status="request_pending", rut_masked=rut_masked)
        return AuthLookupResponse(status="not_in_roster", rut_masked=rut_masked)

    user = _user_by_rut_hash(session, organization.id, entry.rut_hash)
    hints = _lookup_hints(entry)

    if user and not user.is_active:
        return AuthLookupResponse(status="inactive", rut_masked=rut_masked, **hints)

    if user and user.password_hash:
        return AuthLookupResponse(status="ready_to_login", rut_masked=rut_masked, **hints)

    return AuthLookupResponse(status="needs_activation", rut_masked=rut_masked, **hints)


@router.post("/activate", response_model=AuthSessionRead, status_code=status.HTTP_201_CREATED)
def activate_admin_access(
    payload: AuthActivateRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> AuthSessionRead:
    if payload.password_confirm and payload.password_confirm != payload.password:
        raise HTTPException(status_code=400, detail="Las claves no coinciden.")

    organization = _default_organization(session)
    entry = _authorized_entry(session, settings, organization.id, payload.rut)
    if not entry or not entry.can_login:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="El RUT no esta habilitado para activar acceso.",
        )

    existing = _user_by_rut_hash(session, organization.id, entry.rut_hash)
    if existing and existing.password_hash:
        raise HTTPException(status_code=409, detail="Este administrador ya activo su acceso.")

    email = _normalize_email(payload.email) or _normalize_email(entry.email)
    if not email or "@" not in email:
        raise HTTPException(
            status_code=400,
            detail="Debes indicar un correo personal valido para tu cuenta.",
        )

    email_owner = _user_by_email(session, organization.id, email, settings)
    if email_owner and (not existing or email_owner.id != existing.id):
        raise HTTPException(
            status_code=409,
            detail="Ese correo ya esta asociado a otra cuenta del centro.",
        )

    display_name = _compose_display_name(
        payload.first_name,
        payload.last_name,
        entry.display_name,
    )

    user = existing or User(
        organization_id=organization.id,
        rut_hash=entry.rut_hash,
        rut_masked=entry.rut_masked,
        email=protect_text(email, settings) or "",
        email_lookup_hash=lookup_hash(email, settings),
        display_name=protect_text(display_name, settings) or "",
        role=entry.role,
        is_active=True,
        email_notifications_enabled=True,
    )
    user.email = protect_text(email, settings) or ""
    user.email_lookup_hash = lookup_hash(email, settings)
    user.display_name = protect_text(display_name, settings) or ""
    user.role = entry.role
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
        payload={
            "rut_masked": user.rut_masked,
            "role": user.role,
            "email_domain": email.split("@", 1)[-1],
        },
    )
    session.commit()
    return _session_payload(user, token, settings)


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
    return _session_payload(user, token, settings)


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
        delivery = "email_pending"
        try:
            provider = password_reset_email(settings, user, reset_token)
            delivery = f"email_sent_{provider}"
        except MailNotConfiguredError:
            logger.warning("Password reset mail not configured for user %s", user.id)
        except MailDeliveryError as exc:
            logger.warning("Password reset mail failed for user %s: %s", user.id, exc)
        _audit(
            session,
            organization.id,
            "auth.password_reset_requested",
            "user",
            user.id,
            actor_user_id=user.id,
            payload={"delivery": delivery, "rut_masked": user.rut_masked},
        )
        session.commit()

    return PasswordResetResponse(message=GENERIC_RESET_MESSAGE)


@router.post("/password-reset/confirm", response_model=PasswordResetResponse)
def confirm_password_reset(
    payload: PasswordResetConfirmRequest,
    session: SessionDep,
    settings: SettingsDep,
) -> PasswordResetResponse:
    if payload.password_confirm and payload.password_confirm != payload.password:
        raise HTTPException(status_code=400, detail="Las claves no coinciden.")

    organization = _default_organization(session)
    rut_hash_value = rut_hash(normalize_rut(payload.rut), settings.admin_identity_pepper)
    user = _user_by_rut_hash(session, organization.id, rut_hash_value)
    if not user or not user.is_active:
        raise HTTPException(status_code=400, detail="No se pudo restablecer la clave.")

    if not user.password_reset_token_hash or not user.password_reset_expires_at:
        raise HTTPException(status_code=400, detail="No hay recuperacion pendiente para este RUT.")

    expires_at = user.password_reset_expires_at
    if expires_at.tzinfo is None:
        expires_at = expires_at.replace(tzinfo=UTC)
    if expires_at < utcnow():
        raise HTTPException(
            status_code=400,
            detail="El enlace o codigo expiro. Solicita uno nuevo.",
        )

    if user.password_reset_token_hash != hash_token(payload.token):
        raise HTTPException(status_code=400, detail="Codigo o enlace invalido.")

    user.password_hash = hash_password(payload.password)
    user.password_reset_token_hash = None
    user.password_reset_expires_at = None
    session.add(user)
    _audit(
        session,
        organization.id,
        "auth.password_reset_completed",
        "user",
        user.id,
        actor_user_id=user.id,
        payload={"rut_masked": user.rut_masked},
    )
    session.commit()
    return PasswordResetResponse(message="Clave actualizada. Ya puedes iniciar sesion.")
