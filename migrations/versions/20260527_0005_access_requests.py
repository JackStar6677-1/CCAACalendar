"""access requests reviewed by center administrators

Revision ID: 20260527_0005
Revises: 20260527_0004
Create Date: 2026-05-27
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "20260527_0005"
down_revision: str | None = "20260527_0004"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table(
        "access_requests",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("organization_id", sa.String(length=36), nullable=False),
        sa.Column("center_id", sa.String(length=36), nullable=True),
        sa.Column("rut_hash", sa.String(length=64), nullable=False),
        sa.Column("rut_masked", sa.String(length=20), nullable=False),
        sa.Column("email", sa.Text(), nullable=False),
        sa.Column("email_lookup_hash", sa.String(length=64), nullable=False),
        sa.Column("display_name", sa.Text(), nullable=False),
        sa.Column("desired_role", sa.String(length=40), nullable=False),
        sa.Column("note", sa.Text(), nullable=False),
        sa.Column("status", sa.String(length=40), nullable=False),
        sa.Column("notification_status", sa.String(length=40), nullable=False),
        sa.Column("admin_notified_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("reviewed_by_user_id", sa.String(length=36), nullable=True),
        sa.Column("reviewed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=True),
        sa.ForeignKeyConstraint(["center_id"], ["centers.id"]),
        sa.ForeignKeyConstraint(["organization_id"], ["organizations.id"]),
        sa.ForeignKeyConstraint(["reviewed_by_user_id"], ["users.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "ix_access_requests_org_status_created",
        "access_requests",
        ["organization_id", "status", "created_at"],
    )
    op.create_index(
        "ix_access_requests_org_rut_status",
        "access_requests",
        ["organization_id", "rut_hash", "status"],
    )


def downgrade() -> None:
    op.drop_index("ix_access_requests_org_rut_status", table_name="access_requests")
    op.drop_index("ix_access_requests_org_status_created", table_name="access_requests")
    op.drop_table("access_requests")
