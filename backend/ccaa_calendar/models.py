import uuid
from datetime import UTC, datetime

from sqlalchemy import JSON, Boolean, DateTime, ForeignKey, Index, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ccaa_calendar.database import Base


def new_id() -> str:
    return str(uuid.uuid4())


def utcnow() -> datetime:
    return datetime.now(UTC)


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        onupdate=utcnow,
    )


class Organization(TimestampMixin, Base):
    __tablename__ = "organizations"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    name: Mapped[str] = mapped_column(String(180), nullable=False)
    slug: Mapped[str] = mapped_column(String(80), nullable=False, unique=True, index=True)
    domain_hint: Mapped[str | None] = mapped_column(String(180))
    brand_config: Mapped[dict] = mapped_column(JSON, default=dict)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    centers: Mapped[list["Center"]] = relationship(back_populates="organization")
    events: Mapped[list["Event"]] = relationship(back_populates="organization")


class Center(TimestampMixin, Base):
    __tablename__ = "centers"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    name: Mapped[str] = mapped_column(String(180), nullable=False)
    slug: Mapped[str] = mapped_column(String(90), nullable=False)
    official_email: Mapped[str | None] = mapped_column(String(254))
    color: Mapped[str] = mapped_column(String(20), default="#3657d8", nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    organization: Mapped[Organization] = relationship(back_populates="centers")
    events: Mapped[list["Event"]] = relationship(back_populates="center")
    google_connections: Mapped[list["GoogleCalendarConnection"]] = relationship(
        back_populates="center"
    )

    __table_args__ = (Index("ix_centers_org_slug", "organization_id", "slug", unique=True),)


class User(TimestampMixin, Base):
    __tablename__ = "users"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    center_id: Mapped[str | None] = mapped_column(ForeignKey("centers.id"))
    rut_hash: Mapped[str | None] = mapped_column(String(64), index=True)
    rut_masked: Mapped[str | None] = mapped_column(String(20))
    email: Mapped[str] = mapped_column(String(254), nullable=False, index=True)
    email_lookup_hash: Mapped[str | None] = mapped_column(String(64), index=True)
    display_name: Mapped[str] = mapped_column(String(180), nullable=False)
    role: Mapped[str] = mapped_column(String(40), nullable=False, default="viewer")
    password_hash: Mapped[str | None] = mapped_column(String(255))
    password_reset_token_hash: Mapped[str | None] = mapped_column(String(255))
    password_reset_expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    email_notifications_enabled: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    __table_args__ = (
        Index("ix_users_org_email", "organization_id", "email", unique=True),
        Index(
            "ix_users_org_email_lookup_hash",
            "organization_id",
            "email_lookup_hash",
            unique=True,
        ),
        Index("ix_users_org_rut_hash", "organization_id", "rut_hash", unique=True),
    )


class AccessRequest(TimestampMixin, Base):
    """Solicitud publica pendiente de revision por una administradora."""

    __tablename__ = "access_requests"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    center_id: Mapped[str | None] = mapped_column(ForeignKey("centers.id"))
    rut_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    rut_masked: Mapped[str] = mapped_column(String(20), nullable=False)
    email: Mapped[str] = mapped_column(Text, nullable=False)
    email_lookup_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    display_name: Mapped[str] = mapped_column(Text, nullable=False)
    desired_role: Mapped[str] = mapped_column(String(40), default="editor", nullable=False)
    note: Mapped[str] = mapped_column(Text, default="", nullable=False)
    status: Mapped[str] = mapped_column(String(40), default="pending", nullable=False)
    notification_status: Mapped[str] = mapped_column(String(40), default="pending", nullable=False)
    admin_notified_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    reviewed_by_user_id: Mapped[str | None] = mapped_column(ForeignKey("users.id"))
    reviewed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    __table_args__ = (
        Index("ix_access_requests_org_status_created", "organization_id", "status", "created_at"),
        Index("ix_access_requests_org_rut_status", "organization_id", "rut_hash", "status"),
    )


class Space(TimestampMixin, Base):
    __tablename__ = "spaces"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    name: Mapped[str] = mapped_column(String(180), nullable=False)
    slug: Mapped[str] = mapped_column(String(90), nullable=False)
    capacity: Mapped[int | None]
    location: Mapped[str | None] = mapped_column(String(180))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    __table_args__ = (Index("ix_spaces_org_slug", "organization_id", "slug", unique=True),)


class AcademicCalendar(TimestampMixin, Base):
    __tablename__ = "academic_calendars"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    year: Mapped[int] = mapped_column(nullable=False)
    source_filename: Mapped[str | None] = mapped_column(String(260))
    import_status: Mapped[str] = mapped_column(String(40), default="draft", nullable=False)
    extracted_payload: Mapped[dict] = mapped_column(JSON, default=dict)

    __table_args__ = (Index("ix_academic_calendars_org_year", "organization_id", "year"),)


class GoogleCalendarConnection(TimestampMixin, Base):
    __tablename__ = "google_calendar_connections"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    center_id: Mapped[str] = mapped_column(ForeignKey("centers.id"), nullable=False)
    account_email: Mapped[str] = mapped_column(String(254), nullable=False)
    calendar_id: Mapped[str] = mapped_column(String(260), default="primary", nullable=False)
    status: Mapped[str] = mapped_column(String(40), default="pending", nullable=False)
    scopes: Mapped[dict] = mapped_column(JSON, default=dict)
    token_reference: Mapped[str | None] = mapped_column(String(260))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    center: Mapped[Center] = relationship(back_populates="google_connections")

    __table_args__ = (
        Index("ix_google_connections_center_provider", "center_id", "account_email"),
        Index("ix_google_connections_org_status", "organization_id", "status"),
    )


class Event(TimestampMixin, Base):
    __tablename__ = "events"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    center_id: Mapped[str | None] = mapped_column(ForeignKey("centers.id"))
    space_id: Mapped[str | None] = mapped_column(ForeignKey("spaces.id"))
    title: Mapped[str] = mapped_column(String(220), nullable=False)
    description: Mapped[str] = mapped_column(Text, default="", nullable=False)
    category: Mapped[str] = mapped_column(String(60), default="general", nullable=False)
    visibility: Mapped[str] = mapped_column(String(40), default="organization", nullable=False)
    source: Mapped[str] = mapped_column(String(40), default="manual", nullable=False)
    status: Mapped[str] = mapped_column(String(40), default="confirmed", nullable=False)
    starts_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    ends_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    google_calendar_id: Mapped[str | None] = mapped_column(String(260))
    google_event_id: Mapped[str | None] = mapped_column(String(260))
    created_by_user_id: Mapped[str | None] = mapped_column(ForeignKey("users.id"))
    metadata_json: Mapped[dict] = mapped_column(JSON, default=dict)

    organization: Mapped[Organization] = relationship(back_populates="events")
    center: Mapped[Center | None] = relationship(back_populates="events")

    __table_args__ = (
        Index("ix_events_org_starts", "organization_id", "starts_at"),
        Index("ix_events_center_starts", "center_id", "starts_at"),
    )


class EventEmailQueue(TimestampMixin, Base):
    __tablename__ = "event_email_queue"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    event_id: Mapped[str] = mapped_column(ForeignKey("events.id"), nullable=False, index=True)
    user_id: Mapped[str] = mapped_column(ForeignKey("users.id"), nullable=False, index=True)
    recipient_email: Mapped[str] = mapped_column(String(254), nullable=False)
    kind: Mapped[str] = mapped_column(String(40), nullable=False)
    minutes_before: Mapped[int | None]
    fire_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, index=True)
    sent_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    status: Mapped[str] = mapped_column(String(40), default="pending", nullable=False)
    last_error: Mapped[str | None] = mapped_column(String(500))

    __table_args__ = (
        Index(
            "ix_event_email_queue_dedupe",
            "event_id",
            "user_id",
            "kind",
            "minutes_before",
            unique=True,
        ),
    )


class AuditLog(TimestampMixin, Base):
    __tablename__ = "audit_log"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    organization_id: Mapped[str] = mapped_column(ForeignKey("organizations.id"), nullable=False)
    actor_user_id: Mapped[str | None] = mapped_column(ForeignKey("users.id"))
    action: Mapped[str] = mapped_column(String(80), nullable=False)
    entity_type: Mapped[str] = mapped_column(String(80), nullable=False)
    entity_id: Mapped[str] = mapped_column(String(80), nullable=False)
    payload: Mapped[dict] = mapped_column(JSON, default=dict)

