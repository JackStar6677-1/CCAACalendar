from datetime import datetime

from pydantic import BaseModel, ConfigDict, Field


class OrganizationCreate(BaseModel):
    name: str = Field(min_length=2, max_length=180)
    slug: str = Field(min_length=2, max_length=80, pattern=r"^[a-z0-9-]+$")
    domain_hint: str | None = Field(default=None, max_length=180)


class OrganizationRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    name: str
    slug: str
    domain_hint: str | None
    is_active: bool
    created_at: datetime
    updated_at: datetime


class CenterCreate(BaseModel):
    organization_id: str
    name: str = Field(min_length=2, max_length=180)
    slug: str = Field(min_length=2, max_length=90, pattern=r"^[a-z0-9-]+$")
    official_email: str | None = Field(default=None, max_length=254)
    color: str = Field(default="#3657d8", pattern=r"^#[0-9a-fA-F]{6}$")


class CenterRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    organization_id: str
    name: str
    slug: str
    official_email: str | None
    color: str
    is_active: bool


class SpaceCreate(BaseModel):
    organization_id: str
    name: str = Field(min_length=2, max_length=180)
    slug: str = Field(min_length=2, max_length=90, pattern=r"^[a-z0-9-]+$")
    capacity: int | None = Field(default=None, ge=1, le=10000)
    location: str | None = Field(default=None, max_length=180)


class SpaceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    organization_id: str
    name: str
    slug: str
    capacity: int | None
    location: str | None
    is_active: bool


class EventCreate(BaseModel):
    organization_id: str
    center_id: str | None = None
    space_id: str | None = None
    created_by_user_id: str | None = None
    title: str = Field(min_length=2, max_length=220)
    description: str = ""
    category: str = Field(default="general", max_length=60)
    visibility: str = Field(default="organization", max_length=40)
    starts_at: datetime
    ends_at: datetime


class SpaceReservationCreate(BaseModel):
    organization_id: str
    space_id: str
    center_id: str | None = None
    title: str = Field(min_length=2, max_length=220)
    description: str = Field(default="", max_length=2000)
    starts_at: datetime
    ends_at: datetime


class EventRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    organization_id: str
    center_id: str | None
    space_id: str | None
    title: str
    description: str
    category: str
    visibility: str
    source: str
    status: str
    starts_at: datetime
    ends_at: datetime
    google_calendar_id: str | None
    google_event_id: str | None
    created_by_user_id: str | None


class ReminderEmailRequest(BaseModel):
    recipient_email: str = Field(min_length=5, max_length=254)
    minutes_before: int = Field(default=60, ge=5, le=10080)
    note: str = Field(default="", max_length=500)


class AuthActivateRequest(BaseModel):
    rut: str = Field(min_length=7, max_length=20)
    password: str = Field(min_length=8, max_length=128)


class AuthLoginRequest(BaseModel):
    rut: str = Field(min_length=7, max_length=20)
    password: str = Field(min_length=1, max_length=128)


class AuthSessionRead(BaseModel):
    token: str
    user_id: str
    display_name: str
    email: str
    role: str
    rut_masked: str | None


class AdminUserRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    display_name: str
    email: str
    role: str
    rut_masked: str | None
    is_active: bool
    last_login_at: datetime | None
    created_at: datetime


class AuditLogRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    actor_user_id: str | None
    action: str
    entity_type: str
    entity_id: str
    payload: dict
    created_at: datetime


class PasswordResetRequest(BaseModel):
    rut: str = Field(min_length=7, max_length=20)


class PasswordResetResponse(BaseModel):
    message: str
