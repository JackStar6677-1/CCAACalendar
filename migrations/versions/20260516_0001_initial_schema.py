"""Initial CCAACalendar schema.

Revision ID: 0001_initial_schema
Revises:
Create Date: 2026-05-16 21:00:00
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0001_initial_schema"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def timestamps() -> list[sa.Column]:
    return [
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
    ]


def upgrade() -> None:
    op.create_table(
        "organizations",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("slug", sa.String(length=80), nullable=False),
        sa.Column("domain_hint", sa.String(length=180), nullable=True),
        sa.Column("brand_config", sa.JSON(), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False),
        *timestamps(),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_organizations_slug", "organizations", ["slug"], unique=True)

    op.create_table(
        "centers",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("slug", sa.String(length=90), nullable=False),
        sa.Column("official_email", sa.String(length=254), nullable=True),
        sa.Column("color", sa.String(length=20), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_centers_org_slug", "centers", ["organization_id", "slug"], unique=True)

    op.create_table(
        "users",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("center_id", sa.String(length=36), nullable=True),
        sa.Column("rut_hash", sa.String(length=64), nullable=True),
        sa.Column("rut_masked", sa.String(length=20), nullable=True),
        sa.Column("email", sa.String(length=254), nullable=False),
        sa.Column("display_name", sa.String(length=180), nullable=False),
        sa.Column("role", sa.String(length=40), nullable=False),
        sa.Column("password_hash", sa.String(length=255), nullable=True),
        sa.Column("password_reset_token_hash", sa.String(length=255), nullable=True),
        sa.Column("password_reset_expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_login_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["center_id"], ["centers.id"]),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_users_org_email", "users", ["organization_id", "email"], unique=True)
    op.create_index("ix_users_org_rut_hash", "users", ["organization_id", "rut_hash"], unique=True)
    op.create_index("ix_users_rut_hash", "users", ["rut_hash"], unique=False)
    op.create_index("ix_users_email", "users", ["email"], unique=False)

    op.create_table(
        "spaces",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("slug", sa.String(length=90), nullable=False),
        sa.Column("capacity", sa.Integer(), nullable=True),
        sa.Column("location", sa.String(length=180), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_spaces_org_slug", "spaces", ["organization_id", "slug"], unique=True)

    op.create_table(
        "academic_calendars",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("year", sa.Integer(), nullable=False),
        sa.Column("source_filename", sa.String(length=260), nullable=True),
        sa.Column("import_status", sa.String(length=40), nullable=False),
        sa.Column("extracted_payload", sa.JSON(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "ix_academic_calendars_org_year",
        "academic_calendars",
        ["organization_id", "year"],
        unique=False,
    )

    op.create_table(
        "events",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("center_id", sa.String(length=36), nullable=True),
        sa.Column("space_id", sa.String(length=36), nullable=True),
        sa.Column("title", sa.String(length=220), nullable=False),
        sa.Column("description", sa.Text(), nullable=False),
        sa.Column("category", sa.String(length=60), nullable=False),
        sa.Column("visibility", sa.String(length=40), nullable=False),
        sa.Column("source", sa.String(length=40), nullable=False),
        sa.Column("status", sa.String(length=40), nullable=False),
        sa.Column("starts_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("ends_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("google_calendar_id", sa.String(length=260), nullable=True),
        sa.Column("google_event_id", sa.String(length=260), nullable=True),
        sa.Column("metadata_json", sa.JSON(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["center_id"], ["centers.id"]),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.ForeignKeyConstraint(["space_id"], ["spaces.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_events_center_starts", "events", ["center_id", "starts_at"], unique=False)
    op.create_index(
        "ix_events_org_starts",
        "events",
        ["organization_id", "starts_at"],
        unique=False,
    )

    op.create_table(
        "audit_log",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("actor_user_id", sa.String(length=36), nullable=True),
        sa.Column("action", sa.String(length=80), nullable=False),
        sa.Column("entity_type", sa.String(length=80), nullable=False),
        sa.Column("entity_id", sa.String(length=80), nullable=False),
        sa.Column("payload", sa.JSON(), nullable=False),
        *timestamps(),
        sa.ForeignKeyConstraint(["actor_user_id"], ["users.id"]),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.PrimaryKeyConstraint("id"),
    )


def downgrade() -> None:
    op.drop_table("audit_log")
    op.drop_index("ix_events_org_starts", table_name="events")
    op.drop_index("ix_events_center_starts", table_name="events")
    op.drop_table("events")
    op.drop_index("ix_academic_calendars_org_year", table_name="academic_calendars")
    op.drop_table("academic_calendars")
    op.drop_index("ix_spaces_org_slug", table_name="spaces")
    op.drop_table("spaces")
    op.drop_index("ix_users_email", table_name="users")
    op.drop_index("ix_users_rut_hash", table_name="users")
    op.drop_index("ix_users_org_rut_hash", table_name="users")
    op.drop_index("ix_users_org_email", table_name="users")
    op.drop_table("users")
    op.drop_index("ix_centers_org_slug", table_name="centers")
    op.drop_table("centers")
    op.drop_index("ix_organizations_slug", table_name="organizations")
    op.drop_table("organizations")
