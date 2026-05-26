"""user email preferences and event email queue

Revision ID: 20260518_0003
Revises: 20260516_0002
Create Date: 2026-05-18
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "20260518_0003"
down_revision: str | None = "20260516_0002"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.add_column(
        "users",
        sa.Column(
            "email_notifications_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
    )
    op.create_table(
        "event_email_queue",
        sa.Column("id", sa.String(length=36), nullable=False),
        sa.Column("event_id", sa.String(length=36), nullable=False),
        sa.Column("user_id", sa.String(length=36), nullable=False),
        sa.Column("recipient_email", sa.String(length=254), nullable=False),
        sa.Column("kind", sa.String(length=40), nullable=False),
        sa.Column("minutes_before", sa.Integer(), nullable=True),
        sa.Column("fire_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("sent_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("status", sa.String(length=40), nullable=False, server_default="pending"),
        sa.Column("last_error", sa.String(length=500), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(["event_id"], ["events.id"]),
        sa.ForeignKeyConstraint(["user_id"], ["users.id"]),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_event_email_queue_event_id", "event_email_queue", ["event_id"])
    op.create_index("ix_event_email_queue_user_id", "event_email_queue", ["user_id"])
    op.create_index("ix_event_email_queue_fire_at", "event_email_queue", ["fire_at"])
    op.create_index(
        "ix_event_email_queue_dedupe",
        "event_email_queue",
        ["event_id", "user_id", "kind", "minutes_before"],
        unique=True,
    )


def downgrade() -> None:
    op.drop_index("ix_event_email_queue_dedupe", table_name="event_email_queue")
    op.drop_index("ix_event_email_queue_fire_at", table_name="event_email_queue")
    op.drop_index("ix_event_email_queue_user_id", table_name="event_email_queue")
    op.drop_index("ix_event_email_queue_event_id", table_name="event_email_queue")
    op.drop_table("event_email_queue")
    op.drop_column("users", "email_notifications_enabled")
