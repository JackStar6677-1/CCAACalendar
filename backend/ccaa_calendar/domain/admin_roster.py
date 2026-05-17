from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from ccaa_calendar.domain.rut import is_valid_rut, mask_rut, normalize_rut, rut_hash


@dataclass(frozen=True)
class AdminRosterEntry:
    rut: str
    email: str
    display_name: str
    role: str
    status: str
    is_valid_rut: bool
    rut_masked: str
    rut_hash: str

    @property
    def can_login(self) -> bool:
        return self.status == "active" and self.is_valid_rut


def _entry_from_payload(payload: dict[str, Any], pepper: str) -> AdminRosterEntry:
    normalized_rut = normalize_rut(str(payload.get("rut", "")))
    email = str(payload.get("email", "")).strip().lower()
    is_valid = is_valid_rut(normalized_rut)
    status = str(payload.get("status") or ("active" if is_valid else "needs_rut_confirmation"))

    return AdminRosterEntry(
        rut=normalized_rut,
        email=email,
        display_name=str(payload.get("display_name", "")).strip(),
        role=str(payload.get("role", "viewer")).strip() or "viewer",
        status=status,
        is_valid_rut=is_valid,
        rut_masked=mask_rut(normalized_rut),
        rut_hash=rut_hash(normalized_rut, pepper),
    )


def load_admin_roster(path: str | Path, pepper: str) -> list[AdminRosterEntry]:
    roster_path = Path(path)
    if not roster_path.exists():
        return []

    data = json.loads(roster_path.read_text(encoding="utf-8"))
    entries = data.get("admins", data if isinstance(data, list) else [])
    return [_entry_from_payload(item, pepper) for item in entries if isinstance(item, dict)]

