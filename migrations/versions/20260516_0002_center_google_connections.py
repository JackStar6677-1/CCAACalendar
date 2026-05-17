"""Add center-level Google Calendar connections.

Revision ID: 0002_center_google_connections
Revises: 0001_initial_schema
Create Date: 2026-05-16 21:40:00
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0002_center_google_connections"
down_revision: str | None = "0001_initial_schema"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def timestamps() -> list[sa.Column]:
    return [
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
    ]


def upgrade() -> None:
    op.create_table(
        "google_calendar_connections",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("center_id", sa.String(length=36), nullable=False),
        sa.Column("account_email", sa.String(length=254), nullable=False),
        sa.Column("calendar_id", sa.String(length=260), nullable=False),
        sa.Column("status", sa.String(length=40), nullable=False),
        sa.Column("scopes", sa.JSON(), nullable=False),
        sa.Column("token_reference", sa.String(length=260), nullable=True),
        sa.Column("last_synced_at", sa.DateTime(timezone=True), nullable=True),
        *timestamps(),
        sa.ForeignKeyConstraint(["center_id"], ["centers.id"]),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "ix_google_connections_center_provider",
        "google_calendar_connections",
        ["center_id", "account_email"],
        unique=False,
    )
    op.create_index(
        "ix_google_connections_org_status",
        "google_calendar_connections",
        ["organization_id", "status"],
        unique=False,
    )


def downgrade() -> None:
    op.drop_index(
        "ix_google_connections_org_status",
        table_name="google_calendar_connections",
    )
    op.drop_index(
        "ix_google_connections_center_provider",
        table_name="google_calendar_connections",
    )
    op.drop_table("google_calendar_connections")
