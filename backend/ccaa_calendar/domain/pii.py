from __future__ import annotations

import hashlib
import hmac
import json
from pathlib import Path
from typing import Any

from cryptography.fernet import Fernet, InvalidToken, MultiFernet

from ccaa_calendar.settings import Settings

PROTECTED_PREFIX = "fernet:v1:"


class DataProtectionError(RuntimeError):
    """Indica configuracion ausente o datos privados que no pueden descifrarse."""


def data_protection_configured(settings: Settings) -> bool:
    return bool(_configured_keys(settings))


def protect_text(value: str | None, settings: Settings) -> str | None:
    """Cifra texto recuperable; en local sin clave conserva compatibilidad de desarrollo."""
    if value is None or value.startswith(PROTECTED_PREFIX):
        return value
    cipher = _cipher(settings)
    if cipher is None:
        _require_protection_outside_local(settings)
        return value
    token = cipher.encrypt(value.encode("utf-8")).decode("ascii")
    return f"{PROTECTED_PREFIX}{token}"


def reveal_text(value: str | None, settings: Settings) -> str | None:
    """Descifra campos protegidos y admite registros antiguos durante la migracion."""
    if value is None or not value.startswith(PROTECTED_PREFIX):
        return value
    cipher = _cipher(settings)
    if cipher is None:
        raise DataProtectionError("PII_ENCRYPTION_KEYS no esta configurada para descifrar datos.")
    try:
        return cipher.decrypt(value.removeprefix(PROTECTED_PREFIX).encode("ascii")).decode("utf-8")
    except InvalidToken as exc:
        raise DataProtectionError("No se pudo descifrar informacion personal.") from exc


def lookup_hash(value: str, settings: Settings) -> str:
    """Genera indice irreversible para buscar emails cifrados sin revelar su valor."""
    pepper = settings.pii_lookup_pepper.strip() or settings.admin_identity_pepper.strip()
    if not pepper or (not settings.is_local and pepper.startswith("change-this")):
        raise DataProtectionError("PII_LOOKUP_PEPPER debe configurarse fuera de Git.")
    normalized = value.strip().casefold().encode("utf-8")
    return hmac.new(pepper.encode("utf-8"), normalized, hashlib.sha256).hexdigest()


def read_protected_json(path: str | Path, settings: Settings) -> dict[str, Any]:
    target = Path(path)
    if not target.exists():
        return {}
    raw = target.read_text(encoding="utf-8")
    plaintext = reveal_text(raw, settings) or "{}"
    return json.loads(plaintext)


def write_protected_json(path: str | Path, payload: dict[str, Any], settings: Settings) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(payload, ensure_ascii=False, indent=2)
    protected = protect_text(raw, settings) or raw
    target.write_text(protected, encoding="utf-8")


def protect_json_file_if_plaintext(path: str | Path, settings: Settings) -> bool:
    """Cifra un JSON privado heredado en sitio cuando ya existe una clave local."""
    target = Path(path)
    if not target.exists() or not data_protection_configured(settings):
        return False
    raw = target.read_text(encoding="utf-8")
    if raw.startswith(PROTECTED_PREFIX):
        return False
    json.loads(raw)
    protected = protect_text(raw, settings)
    target.write_text(protected or raw, encoding="utf-8")
    return True


def _configured_keys(settings: Settings) -> list[str]:
    return [key.strip() for key in settings.pii_encryption_keys.split(",") if key.strip()]


def _cipher(settings: Settings) -> MultiFernet | None:
    keys = _configured_keys(settings)
    if not keys:
        return None
    try:
        return MultiFernet([Fernet(key.encode("ascii")) for key in keys])
    except (TypeError, ValueError) as exc:
        raise DataProtectionError(
            "PII_ENCRYPTION_KEYS contiene una clave Fernet invalida."
        ) from exc


def _require_protection_outside_local(settings: Settings) -> None:
    if not settings.is_local:
        raise DataProtectionError("PII_ENCRYPTION_KEYS es obligatoria fuera del entorno local.")
