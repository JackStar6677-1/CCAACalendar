from __future__ import annotations

import logging
from datetime import UTC, datetime, timedelta

from sqlalchemy import select
from sqlalchemy.orm import Session

from ccaa_calendar.integrations.mail_delivery import MailDeliveryError, MailNotConfiguredError
from ccaa_calendar.integrations.transactional_mail import event_created_email, event_reminder_email
from ccaa_calendar.models import Event, EventEmailQueue, User
from ccaa_calendar.security import utcnow
from ccaa_calendar.settings import Settings

logger = logging.getLogger(__name__)


def _aware(dt: datetime) -> datetime:
    if dt.tzinfo is None:
        return dt.replace(tzinfo=UTC)
    return dt.astimezone(UTC)


def notification_subscribers(session: Session, organization_id: str) -> list[User]:
    return list(
        session.scalars(
            select(User).where(
                User.organization_id == organization_id,
                User.is_active.is_(True),
                User.email_notifications_enabled.is_(True),
            )
        )
    )


def enqueue_event_notifications(
    settings: Settings,
    session: Session,
    event: Event,
) -> dict[str, int]:
    users = notification_subscribers(session, event.organization_id)
    now = _aware(utcnow())
    queued_created = 0
    queued_reminders = 0

    for user in users:
        session.add(
            EventEmailQueue(
                event_id=event.id,
                user_id=user.id,
                recipient_email=user.email,
                kind="created",
                minutes_before=None,
                fire_at=now,
                status="pending",
            )
        )
        queued_created += 1

    for minutes_before in settings.event_reminder_offsets():
        fire_at = _aware(event.starts_at) - timedelta(minutes=minutes_before)
        if fire_at <= now:
            continue
        for user in users:
            session.add(
                EventEmailQueue(
                    event_id=event.id,
                    user_id=user.id,
                    recipient_email=user.email,
                    kind="reminder",
                    minutes_before=minutes_before,
                    fire_at=fire_at,
                    status="pending",
                )
            )
            queued_reminders += 1

    session.flush()
    return {
        "subscribers": len(users),
        "queued_created": queued_created,
        "queued_reminders": queued_reminders,
    }


def process_due_email_queue(
    settings: Settings,
    session: Session,
    *,
    event_id: str | None = None,
    limit: int = 50,
) -> dict[str, int]:
    stmt = (
        select(EventEmailQueue)
        .where(
            EventEmailQueue.status == "pending",
            EventEmailQueue.fire_at <= _aware(utcnow()),
        )
        .order_by(EventEmailQueue.fire_at)
        .limit(limit)
    )
    if event_id:
        stmt = stmt.where(EventEmailQueue.event_id == event_id)

    pending = list(session.scalars(stmt))
    sent = 0
    failed = 0
    skipped = 0

    for item in pending:
        event = session.get(Event, item.event_id)
        user = session.get(User, item.user_id)
        if not event or not user or not user.is_active:
            item.status = "skipped"
            item.last_error = "event_or_user_missing"
            skipped += 1
            continue

        if not user.email_notifications_enabled:
            item.status = "skipped"
            item.last_error = "notifications_disabled"
            skipped += 1
            continue

        try:
            if item.kind == "created":
                provider = event_created_email(settings, event, user)
            elif item.kind == "reminder":
                provider = event_reminder_email(
                    settings,
                    event,
                    user,
                    minutes_before=item.minutes_before or 60,
                )
            else:
                item.status = "skipped"
                item.last_error = f"unknown_kind:{item.kind}"
                skipped += 1
                continue

            item.status = "sent"
            item.sent_at = utcnow()
            item.last_error = provider
            sent += 1
        except MailNotConfiguredError as exc:
            item.last_error = str(exc)[:500]
            logger.warning("Mail not configured for queue item %s: %s", item.id, exc)
        except MailDeliveryError as exc:
            item.status = "failed"
            item.last_error = str(exc)[:500]
            failed += 1
            logger.warning("Mail delivery failed for queue item %s: %s", item.id, exc)
        except Exception as exc:
            item.status = "failed"
            item.last_error = str(exc)[:500]
            failed += 1
            logger.exception("Unexpected mail error for queue item %s", item.id)

    return {"processed": len(pending), "sent": sent, "failed": failed, "skipped": skipped}
