from collections.abc import Generator
from pathlib import Path

from sqlalchemy import create_engine, select, text
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from ccaa_calendar.settings import get_settings


class Base(DeclarativeBase):
    pass


def _connect_args(database_url: str) -> dict[str, object]:
    if database_url.startswith("sqlite"):
        return {"check_same_thread": False}
    return {}


def _ensure_sqlite_parent(database_url: str) -> None:
    if not database_url.startswith("sqlite:///"):
        return
    raw_path = database_url.removeprefix("sqlite:///")
    if raw_path in {":memory:", ""}:
        return
    Path(raw_path).parent.mkdir(parents=True, exist_ok=True)


settings = get_settings()
_ensure_sqlite_parent(settings.database_url)

engine = create_engine(
    settings.database_url,
    connect_args=_connect_args(settings.database_url),
    pool_pre_ping=True,
)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, expire_on_commit=False)


def get_session() -> Generator[Session, None, None]:
    session = SessionLocal()
    try:
        yield session
    finally:
        session.close()


def init_database() -> None:
    from ccaa_calendar import models  # noqa: F401

    Base.metadata.create_all(bind=engine)
    _apply_sqlite_dev_migrations()
    _protect_sensitive_local_data()


def _apply_sqlite_dev_migrations() -> None:
    if not settings.database_url.startswith("sqlite"):
        return

    required_user_columns = {
        "rut_hash": "VARCHAR(64)",
        "rut_masked": "VARCHAR(20)",
        "email_lookup_hash": "VARCHAR(64)",
        "password_hash": "VARCHAR(255)",
        "password_reset_token_hash": "VARCHAR(255)",
        "password_reset_expires_at": "DATETIME",
        "last_login_at": "DATETIME",
        "email_notifications_enabled": "BOOLEAN DEFAULT 1",
    }
    required_event_columns = {
        "created_by_user_id": "VARCHAR(36)",
    }
    with engine.begin() as connection:
        table_exists = connection.execute(
            text("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
        ).first()
        if not table_exists:
            return

        existing_columns = {
            row[1] for row in connection.execute(text("PRAGMA table_info(users)")).all()
        }
        for column_name, column_type in required_user_columns.items():
            if column_name not in existing_columns:
                statement = f"ALTER TABLE users ADD COLUMN {column_name} {column_type}"
                connection.execute(text(statement))
        connection.execute(
            text(
                "UPDATE users SET email_notifications_enabled = 1 "
                "WHERE email_notifications_enabled IS NULL"
            )
        )
        connection.execute(
            text(
                "CREATE UNIQUE INDEX IF NOT EXISTS ix_users_org_email_lookup_hash "
                "ON users (organization_id, email_lookup_hash)"
            )
        )

        event_table_exists = connection.execute(
            text("SELECT name FROM sqlite_master WHERE type='table' AND name='events'")
        ).first()
        if not event_table_exists:
            return

        existing_event_columns = {
            row[1] for row in connection.execute(text("PRAGMA table_info(events)")).all()
        }
        for column_name, column_type in required_event_columns.items():
            if column_name not in existing_event_columns:
                statement = f"ALTER TABLE events ADD COLUMN {column_name} {column_type}"
                connection.execute(text(statement))


def _protect_sensitive_local_data() -> None:
    """Convierte datos locales heredados a campos cifrados al iniciar la aplicacion."""
    from ccaa_calendar.domain.pii import (
        data_protection_configured,
        lookup_hash,
        protect_json_file_if_plaintext,
        protect_text,
        reveal_text,
    )
    from ccaa_calendar.models import (
        AccessRequest,
        Center,
        EventEmailQueue,
        GoogleCalendarConnection,
        User,
    )

    if not data_protection_configured(settings):
        if not settings.is_local:
            raise RuntimeError("PII_ENCRYPTION_KEYS es obligatoria para iniciar produccion.")
        return

    protect_json_file_if_plaintext(settings.admin_roster_path, settings)
    protect_json_file_if_plaintext(settings.google_oauth_state_path, settings)
    protect_json_file_if_plaintext(settings.google_token_path, settings)

    with SessionLocal() as session:
        for user in session.scalars(select(User)):
            email = reveal_text(user.email, settings) or ""
            user.email_lookup_hash = lookup_hash(email, settings)
            user.email = protect_text(email, settings) or ""
            user.display_name = protect_text(
                reveal_text(user.display_name, settings), settings
            ) or ""
            session.add(user)

        for center in session.scalars(select(Center)):
            center.official_email = protect_text(
                reveal_text(center.official_email, settings), settings
            )
            session.add(center)

        for connection in session.scalars(select(GoogleCalendarConnection)):
            connection.account_email = protect_text(
                reveal_text(connection.account_email, settings), settings
            ) or ""
            session.add(connection)

        for item in session.scalars(select(EventEmailQueue)):
            item.recipient_email = protect_text(
                reveal_text(item.recipient_email, settings), settings
            ) or ""
            session.add(item)

        for request in session.scalars(select(AccessRequest)):
            email = reveal_text(request.email, settings) or ""
            request.email_lookup_hash = lookup_hash(email, settings)
            request.email = protect_text(email, settings) or ""
            request.display_name = protect_text(
                reveal_text(request.display_name, settings), settings
            ) or ""
            request.note = protect_text(reveal_text(request.note, settings), settings) or ""
            session.add(request)
        session.commit()

