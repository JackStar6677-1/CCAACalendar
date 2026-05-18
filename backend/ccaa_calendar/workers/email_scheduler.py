from __future__ import annotations

import asyncio
import logging

from ccaa_calendar.database import SessionLocal
from ccaa_calendar.domain.event_notifications import process_due_email_queue
from ccaa_calendar.settings import get_settings

logger = logging.getLogger(__name__)


async def run_email_worker(stop_event: asyncio.Event) -> None:
    settings = get_settings()
    interval = max(15, settings.event_email_worker_interval_seconds)

    while not stop_event.is_set():
        try:
            with SessionLocal() as session:
                stats = process_due_email_queue(settings, session)
                session.commit()
                if stats.get("sent"):
                    logger.info("Event email worker: %s", stats)
        except Exception:
            logger.exception("Event email worker tick failed")

        try:
            await asyncio.wait_for(stop_event.wait(), timeout=interval)
        except asyncio.TimeoutError:
            continue
