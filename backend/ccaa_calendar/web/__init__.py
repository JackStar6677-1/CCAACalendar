from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import FileResponse

WEB_DIR = Path(__file__).resolve().parent
STATIC_DIR = WEB_DIR / "static"

router = APIRouter(tags=["web"])


@router.get("/", include_in_schema=False)
@router.get("/login", include_in_schema=False)
@router.get("/app", include_in_schema=False)
def home() -> FileResponse:
    return FileResponse(STATIC_DIR / "index.html")


@router.get("/manifest.webmanifest", include_in_schema=False)
def manifest() -> FileResponse:
    return FileResponse(STATIC_DIR / "manifest.webmanifest", media_type="application/manifest+json")


@router.get("/sw.js", include_in_schema=False)
def service_worker() -> FileResponse:
    return FileResponse(STATIC_DIR / "sw.js", media_type="text/javascript")


@router.get("/offline", include_in_schema=False)
def offline() -> FileResponse:
    return FileResponse(STATIC_DIR / "offline.html")
