from typing import Annotated

from fastapi import APIRouter, Depends
from sqlalchemy import text
from sqlalchemy.orm import Session

from ccaa_calendar.database import get_session
from ccaa_calendar.settings import get_settings

router = APIRouter(prefix="/api", tags=["health"])
SessionDep = Annotated[Session, Depends(get_session)]


@router.get("/health")
def healthcheck(session: SessionDep) -> dict[str, str]:
    session.execute(text("SELECT 1"))
    settings = get_settings()
    return {
        "status": "ok",
        "app": settings.app_name,
        "environment": settings.environment,
    }

