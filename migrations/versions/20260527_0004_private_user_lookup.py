"""private lookup index for encrypted user emails

Revision ID: 20260527_0004
Revises: 20260518_0003
Create Date: 2026-05-27
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "20260527_0004"
down_revision: str | None = "20260518_0003"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    # La aplicacion rellena el indice HMAC al arrancar con PII_ENCRYPTION_KEYS configurada.
    op.add_column("users", sa.Column("email_lookup_hash", sa.String(length=64), nullable=True))
    op.create_index(
        "ix_users_org_email_lookup_hash",
        "users",
        ["organization_id", "email_lookup_hash"],
        unique=True,
    )


def downgrade() -> None:
    op.drop_index("ix_users_org_email_lookup_hash", table_name="users")
    op.drop_column("users", "email_lookup_hash")
