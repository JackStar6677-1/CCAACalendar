from collections.abc import Generator
from pathlib import Path

from sqlalchemy import create_engine, text
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


def _apply_sqlite_dev_migrations() -> None:
    if not settings.database_url.startswith("sqlite"):
        return

    required_user_columns = {
        "rut_hash": "VARCHAR(64)",
        "rut_masked": "VARCHAR(20)",
        "password_hash": "VARCHAR(255)",
        "password_reset_token_hash": "VARCHAR(255)",
        "password_reset_expires_at": "DATETIME",
        "last_login_at": "DATETIME",
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

