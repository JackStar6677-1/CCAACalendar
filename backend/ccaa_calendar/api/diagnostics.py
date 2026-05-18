from typing import Annotated, Any

from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, Field

from ccaa_calendar.observability import write_app_log
from ccaa_calendar.settings import Settings, get_settings

router = APIRouter(prefix="/api/diagnostics", tags=["diagnostics"])
SettingsDep = Annotated[Settings, Depends(get_settings)]


class ClientErrorReport(BaseModel):
    kind: str = Field(default="client.error", max_length=80)
    message: str = Field(max_length=800)
    source: str = Field(default="browser", max_length=120)
    path: str = Field(default="", max_length=260)
    stack: str = Field(default="", max_length=1600)
    metadata: dict[str, Any] = Field(default_factory=dict)


@router.post("/client-error", status_code=202)
def report_client_error(
    payload: ClientErrorReport,
    request: Request,
    settings: SettingsDep,
) -> dict[str, str]:
    write_app_log(
        settings,
        payload.kind,
        {
            "source": payload.source,
            "path": payload.path or request.url.path,
            "message": payload.message,
            "stack": payload.stack,
            "metadata": payload.metadata,
            "user_agent": request.headers.get("user-agent", ""),
        },
    )
    return {"status": "accepted"}
