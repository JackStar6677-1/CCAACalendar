from datetime import datetime
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.api.auth import AdminUserDep
from ccaa_calendar.database import get_session
from ccaa_calendar.domain.academic_import import parse_academic_calendar
from ccaa_calendar.models import AcademicCalendar, AuditLog, Event
from ccaa_calendar.schemas import (
    AcademicImportCommitRead,
    AcademicImportCommitRequest,
    AcademicImportPreviewRead,
)

router = APIRouter(prefix="/api/imports", tags=["imports"])
SessionDep = Annotated[Session, Depends(get_session)]
ImportYearDep = Annotated[int, Form(..., ge=2020, le=2100)]
ImportFileDep = Annotated[UploadFile, File(...)]


@router.post(
    "/academic/preview",
    response_model=AcademicImportPreviewRead,
    status_code=status.HTTP_201_CREATED,
)
async def preview_academic_calendar(
    current_user: AdminUserDep,
    session: SessionDep,
    year: ImportYearDep,
    file: ImportFileDep,
) -> dict:
    """Recibe un archivo academico y devuelve una previsualizacion revisable."""
    content = await file.read()
    if not content:
        raise HTTPException(status_code=400, detail="El archivo esta vacio.")

    try:
        extracted = parse_academic_calendar(file.filename or "calendario", content)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    # Guardamos la previsualizacion como borrador: auditable, repetible y segura.
    academic_import = AcademicCalendar(
        organization_id=current_user.organization_id,
        year=year,
        source_filename=file.filename,
        import_status="preview",
        extracted_payload=extracted,
    )
    session.add(academic_import)
    session.flush()
    session.add(
        AuditLog(
            organization_id=current_user.organization_id,
            actor_user_id=current_user.id,
            action="academic_import.preview",
            entity_type="academic_calendar",
            entity_id=academic_import.id,
            payload={
                "filename": file.filename,
                "year": year,
                "candidates": len(extracted.get("candidates", [])),
            },
        )
    )
    session.commit()
    session.refresh(academic_import)
    return _preview_payload(academic_import)


@router.get("/academic/{import_id}", response_model=AcademicImportPreviewRead)
def read_academic_import(
    import_id: str,
    current_user: AdminUserDep,
    session: SessionDep,
) -> dict:
    academic_import = session.get(AcademicCalendar, import_id)
    if not academic_import or academic_import.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Importacion no encontrada.")
    return _preview_payload(academic_import)


@router.post("/academic/{import_id}/commit", response_model=AcademicImportCommitRead)
def commit_academic_import(
    import_id: str,
    payload: AcademicImportCommitRequest,
    current_user: AdminUserDep,
    session: SessionDep,
) -> AcademicImportCommitRead:
    """Convierte candidatos aprobados en eventos reales del calendario."""
    academic_import = session.get(AcademicCalendar, import_id)
    if not academic_import or academic_import.organization_id != current_user.organization_id:
        raise HTTPException(status_code=404, detail="Importacion no encontrada.")
    if academic_import.import_status == "committed":
        raise HTTPException(status_code=409, detail="Esta importacion ya fue aprobada.")

    candidates = academic_import.extracted_payload.get("candidates", [])
    commit_candidates = _commit_candidates(payload, candidates)

    created_events: list[Event] = []
    for candidate in commit_candidates:
        duplicate = _existing_imported_event(session, academic_import.organization_id, candidate)
        if duplicate:
            continue
        event = Event(
            organization_id=academic_import.organization_id,
            title=candidate["title"],
            description=candidate["description"],
            category=candidate["category"],
            visibility="organization",
            source="academic_import",
            starts_at=datetime.fromisoformat(candidate["starts_at"]),
            ends_at=datetime.fromisoformat(candidate["ends_at"]),
            created_by_user_id=payload.created_by_user_id or current_user.id,
            metadata_json={
                "academic_import_id": academic_import.id,
                "source_filename": academic_import.source_filename,
                "source_line": candidate["source_line"],
                "confidence": candidate["confidence"],
            },
        )
        session.add(event)
        created_events.append(event)

    session.flush()
    academic_import.import_status = "committed"
    session.add(academic_import)
    session.add(
        AuditLog(
            organization_id=academic_import.organization_id,
            actor_user_id=current_user.id,
            action="academic_import.commit",
            entity_type="academic_calendar",
            entity_id=academic_import.id,
            payload={
                "created_events": len(created_events),
                "selected_candidates": len(commit_candidates),
                "edited_before_commit": bool(payload.candidates),
                "notify_subscribers": payload.notify_subscribers,
            },
        )
    )
    session.commit()

    return AcademicImportCommitRead(
        import_id=academic_import.id,
        imported_events=len(created_events),
        skipped_candidates=len(commit_candidates) - len(created_events),
        event_ids=[event.id for event in created_events],
    )


def _commit_candidates(
    payload: AcademicImportCommitRequest,
    stored_candidates: list[dict],
) -> list[dict]:
    """Resuelve que hitos entran al calendario: editados por UI o originales."""
    if payload.candidates is not None:
        return [candidate.model_dump(mode="json") for candidate in payload.candidates]

    indexes = (
        payload.selected_indexes
        if payload.selected_indexes is not None
        else list(range(len(stored_candidates)))
    )
    return [stored_candidates[index] for index in indexes if 0 <= index < len(stored_candidates)]


def _existing_imported_event(
    session: Session,
    organization_id: str,
    candidate: dict,
) -> Event | None:
    """Evita duplicar eventos si una administradora aprueba el mismo archivo otra vez."""
    return session.scalar(
        select(Event)
        .where(
            Event.organization_id == organization_id,
            Event.title == candidate["title"],
            Event.starts_at == datetime.fromisoformat(candidate["starts_at"]),
            Event.source == "academic_import",
        )
        .limit(1)
    )


def _preview_payload(academic_import: AcademicCalendar) -> dict:
    payload = academic_import.extracted_payload or {}
    return {
        "import_id": academic_import.id,
        "organization_id": academic_import.organization_id,
        "year": academic_import.year,
        "source_filename": academic_import.source_filename or "calendario",
        "import_status": academic_import.import_status,
        "line_count": payload.get("line_count", 0),
        "candidates": payload.get("candidates", []),
        "warnings": payload.get("warnings", []),
    }
