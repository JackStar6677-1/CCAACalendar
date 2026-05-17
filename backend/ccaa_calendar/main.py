from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles

from ccaa_calendar.api import auth, centers, events, health, holidays, integrations, organizations
from ccaa_calendar.database import init_database
from ccaa_calendar.settings import get_settings
from ccaa_calendar.web import STATIC_DIR
from ccaa_calendar.web import router as web_router


@asynccontextmanager
async def lifespan(_: FastAPI) -> AsyncIterator[None]:
    init_database()
    yield


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(title=settings.app_name, version="0.1.0", lifespan=lifespan)

    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origins,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

    app.mount("/assets", StaticFiles(directory=STATIC_DIR), name="assets")
    app.include_router(web_router)
    app.include_router(auth.router)
    app.include_router(health.router)
    app.include_router(organizations.router)
    app.include_router(centers.router)
    app.include_router(events.router)
    app.include_router(holidays.router)
    app.include_router(integrations.router)
    return app


app = create_app()

