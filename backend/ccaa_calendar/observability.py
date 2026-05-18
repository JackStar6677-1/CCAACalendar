from __future__ import annotations

import json
import time
import uuid
from collections.abc import Awaitable, Callable
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from fastapi import Request, Response
from starlette.middleware.base import BaseHTTPMiddleware

from ccaa_calendar.settings import Settings

SENSITIVE_KEYS = {"authorization", "cookie", "token", "refresh_token", "client_secret", "code"}


def _utc_iso() -> str:
    return datetime.now(UTC).isoformat()


def _safe_value(key: str, value: Any) -> Any:
    if key.lower() in SENSITIVE_KEYS:
        return "[redacted]"
    if isinstance(value, dict):
        return {str(k): _safe_value(str(k), v) for k, v in value.items()}
    if isinstance(value, list):
        return [_safe_value(key, item) for item in value]
    if isinstance(value, str) and len(value) > 600:
        return f"{value[:600]}...[truncated]"
    return value


def sanitize_payload(payload: dict[str, Any]) -> dict[str, Any]:
    return {str(key): _safe_value(str(key), value) for key, value in payload.items()}


def write_app_log(settings: Settings, event: str, payload: dict[str, Any]) -> None:
    path = Path(settings.app_log_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    record = {
        "ts": _utc_iso(),
        "event": event,
        **sanitize_payload(payload),
    }
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n")


class RequestLogMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, settings: Settings) -> None:
        super().__init__(app)
        self.settings = settings

    async def dispatch(
        self,
        request: Request,
        call_next: Callable[[Request], Awaitable[Response]],
    ) -> Response:
        request_id = request.headers.get("x-request-id") or str(uuid.uuid4())
        start = time.perf_counter()
        try:
            response = await call_next(request)
        except Exception as exc:
            duration_ms = round((time.perf_counter() - start) * 1000, 2)
            write_app_log(
                self.settings,
                "request.exception",
                {
                    "request_id": request_id,
                    "method": request.method,
                    "path": request.url.path,
                    "duration_ms": duration_ms,
                    "error_type": type(exc).__name__,
                    "error": str(exc),
                },
            )
            raise

        duration_ms = round((time.perf_counter() - start) * 1000, 2)
        response.headers["x-request-id"] = request_id
        if response.status_code >= 400 or duration_ms >= 1500:
            write_app_log(
                self.settings,
                "request.completed",
                {
                    "request_id": request_id,
                    "method": request.method,
                    "path": request.url.path,
                    "status_code": response.status_code,
                    "duration_ms": duration_ms,
                },
            )
        return response
