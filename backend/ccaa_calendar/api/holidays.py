from datetime import date

from fastapi import APIRouter, Query
from pydantic import BaseModel

from ccaa_calendar.domain.holidays import chile_holidays

router = APIRouter(prefix="/api/holidays", tags=["holidays"])


class HolidayRead(BaseModel):
    date: date
    label: str
    kind: str
    source: str
    is_irrenunciable: bool


@router.get("", response_model=list[HolidayRead])
def list_holidays(year: int = Query(ge=2020, le=2100)) -> list[HolidayRead]:
    return [
        HolidayRead(
            date=item.date,
            label=item.label,
            kind=item.kind,
            source=item.source,
            is_irrenunciable=item.is_irrenunciable,
        )
        for item in chile_holidays(year)
    ]

