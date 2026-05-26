import json
import os
import uuid
from datetime import UTC, datetime

from ccaa_calendar.api.auth import ACTIVE_SESSIONS
from ccaa_calendar.database import SessionLocal, get_session
from ccaa_calendar.domain.academic_import import parse_academic_calendar
from ccaa_calendar.domain.admin_roster import load_admin_roster
from ccaa_calendar.domain.rut import is_valid_rut, mask_rut, normalize_rut
from ccaa_calendar.integrations.google_calendar import (
    _is_calendar_event_allowed,
    google_event_payload,
)
from ccaa_calendar.integrations.google_oauth import is_google_oauth_configured, make_flow
from ccaa_calendar.main import app
from ccaa_calendar.models import Event, EventEmailQueue, Organization, User
from ccaa_calendar.security import hash_token, new_public_token
from ccaa_calendar.settings import Settings, get_settings
from fastapi.testclient import TestClient
from sqlalchemy import select


def authorized_user(role: str = "admin") -> tuple[dict[str, str], Organization, User]:
    """Build an isolated signed-in user for protected API tests."""
    with SessionLocal() as session:
        suffix = uuid.uuid4().hex
        organization = Organization(
            name=f"Centro Test {suffix}",
            slug=f"centro-test-{suffix}",
            brand_config={"public_name": "Centro Test"},
        )
        session.add(organization)
        session.flush()
        user = User(
            organization_id=organization.id,
            email=f"user-{suffix}@example.com",
            display_name="Administradora Test",
            role=role,
            is_active=True,
            email_notifications_enabled=True,
        )
        session.add(user)
        session.commit()
        session.refresh(organization)
        session.refresh(user)
        session.expunge(organization)
        session.expunge(user)

    token = f"test-session-{uuid.uuid4().hex}"
    ACTIVE_SESSIONS[token] = user.id
    return {"Authorization": f"Bearer {token}"}, organization, user


def test_healthcheck() -> None:
    with TestClient(app) as client:
        response = client.get("/api/health")

    assert response.status_code == 200
    assert response.json()["status"] == "ok"


def test_web_home_loads() -> None:
    with TestClient(app) as client:
        response = client.get("/")
        styles_response = client.get("/assets/styles.css")
        manifest_response = client.get("/manifest.webmanifest")

    assert response.status_code == 200
    assert "CCAACalendar" in response.text
    assert "La cuenta Google del centro no sirve para" in response.text
    assert "Tu acceso es personal" in response.text
    assert styles_response.status_code == 200
    assert manifest_response.status_code == 200


def test_create_event_requires_signed_in_editor() -> None:
    headers, organization, user = authorized_user("editor")
    with TestClient(app) as client:
        anonymous = client.post(
            "/api/events",
            json={
                "organization_id": organization.id,
                "title": "Evento anonimo",
                "starts_at": "2026-05-26T14:00:00-04:00",
                "ends_at": "2026-05-26T16:00:00-04:00",
            },
        )
        event_response = client.post(
            "/api/events",
            headers=headers,
            json={
                "organization_id": organization.id,
                "title": "Reunion centro de estudiantes",
                "category": "reunion",
                "visibility": "organization",
                "starts_at": "2026-05-26T14:00:00-04:00",
                "ends_at": "2026-05-26T16:00:00-04:00",
            },
        )

    assert anonymous.status_code == 401
    assert event_response.status_code == 201
    assert event_response.json()["created_by_user_id"] == user.id
    assert event_response.json()["title"] == "Reunion centro de estudiantes"


def test_editor_can_update_and_cancel_local_event() -> None:
    headers, organization, _ = authorized_user("editor")
    with TestClient(app) as client:
        created = client.post(
            "/api/events",
            headers=headers,
            json={
                "organization_id": organization.id,
                "title": "Actividad inicial",
                "starts_at": "2026-06-02T14:00:00-04:00",
                "ends_at": "2026-06-02T15:00:00-04:00",
            },
        )
        updated = client.patch(
            f"/api/events/{created.json()['id']}",
            headers=headers,
            json={"title": "Actividad actualizada", "ends_at": "2026-06-02T16:00:00-04:00"},
        )
        cancelled = client.delete(f"/api/events/{created.json()['id']}", headers=headers)

    assert created.status_code == 201
    assert updated.status_code == 200
    assert updated.json()["title"] == "Actividad actualizada"
    assert cancelled.status_code == 200
    assert cancelled.json()["status"] == "cancelled"


def test_chile_holidays_include_irrenunciables() -> None:
    with TestClient(app) as client:
        response = client.get("/api/holidays?year=2026")

    assert response.status_code == 200
    holidays = response.json()
    labels = {item["label"] for item in holidays if item["is_irrenunciable"]}
    assert {"Anio Nuevo", "Dia del Trabajador", "Independencia Nacional", "Navidad"} <= labels


def test_google_integration_status_shape() -> None:
    with TestClient(app) as client:
        response = client.get("/api/integrations/google/status")

    assert response.status_code == 200
    payload = response.json()
    assert payload["provider"] == "google"
    assert payload["mode"] == "center_calendar"
    assert payload["account_role"] == "official_center_calendar"
    assert "account_email" not in payload
    assert "account_email_configured" in payload
    assert "account_hint" in payload
    assert payload["internal_auth"] == "rut_password"
    assert "configured" in payload
    assert payload["calendar_scope"] == "https://www.googleapis.com/auth/calendar.events"


def test_google_oauth_can_use_local_client_secret_file(tmp_path) -> None:
    secret_file = tmp_path / "client_secret.json"
    secret_file.write_text(
        json.dumps(
            {
                "web": {
                    "client_id": "demo-client-id.apps.googleusercontent.com",
                    "client_secret": "demo-client-secret",
                    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
                    "token_uri": "https://oauth2.googleapis.com/token",
                }
            }
        ),
        encoding="utf-8",
    )

    settings = Settings(
        google_client_id="",
        google_client_secret="",
        google_client_secret_file=str(secret_file),
    )

    assert is_google_oauth_configured(settings)


def test_google_oauth_relaxes_scope_expansion(tmp_path) -> None:
    secret_file = tmp_path / "client_secret.json"
    secret_file.write_text(
        json.dumps(
            {
                "web": {
                    "client_id": "demo-client-id.apps.googleusercontent.com",
                    "client_secret": "demo-client-secret",
                    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
                    "token_uri": "https://oauth2.googleapis.com/token",
                }
            }
        ),
        encoding="utf-8",
    )
    settings = Settings(google_client_secret_file=str(secret_file))

    make_flow(settings, include_gmail=True, code_verifier="a" * 64)

    assert os.environ["OAUTHLIB_RELAX_TOKEN_SCOPE"] == "1"


def test_client_error_report_writes_local_log(tmp_path) -> None:
    log_path = tmp_path / "ccaa-calendar.jsonl"
    settings = Settings(database_url="sqlite:///:memory:", app_log_path=str(log_path))
    app.dependency_overrides[get_settings] = lambda: settings
    try:
        with TestClient(app) as client:
            response = client.post(
                "/api/diagnostics/client-error",
                json={
                    "kind": "client.error",
                    "message": "Example browser failure",
                    "stack": "token=secret should be shortened",
                    "metadata": {"token": "fake-redaction-value", "component": "calendar"},
                },
            )
    finally:
        app.dependency_overrides.clear()

    assert response.status_code == 202
    line = json.loads(log_path.read_text(encoding="utf-8").splitlines()[0])
    assert line["event"] == "client.error"
    assert line["metadata"]["token"] == "[redacted]"


def test_google_login_persists_pkce_code_verifier(tmp_path) -> None:
    state_path = tmp_path / "oauth_state.json"
    settings = Settings(
        google_client_id="demo-client-id.apps.googleusercontent.com",
        google_client_secret="demo-client-secret",
        google_oauth_state_path=str(state_path),
        google_center_account_email="calendar-owner@example.com",
    )
    app.dependency_overrides[get_settings] = lambda: settings
    headers, _, _ = authorized_user("admin")

    try:
        with TestClient(app) as client:
            response = client.get(
                "/api/integrations/google/login",
                headers=headers,
                follow_redirects=False,
            )

        assert response.status_code == 307
        assert "code_challenge=" in response.headers["location"]
        state = json.loads(state_path.read_text(encoding="utf-8"))
        assert state["state"]
        assert state["include_gmail"] is True
        assert settings.google_gmail_scopes in state["scopes"]
        assert len(state["code_verifier"]) >= 43
    finally:
        app.dependency_overrides.clear()


def test_google_event_sync_preview_uses_calendar_payload() -> None:
    headers, organization, _ = authorized_user("editor")
    with TestClient(app) as client:
        event_response = client.post(
            "/api/events",
            headers=headers,
            json={
                "organization_id": organization.id,
                "title": "Asamblea sincronizable",
                "category": "centro",
                "visibility": "organization",
                "starts_at": "2026-05-28T10:00:00-04:00",
                "ends_at": "2026-05-28T11:00:00-04:00",
                "description": "Evento listo para previsualizar en Google Calendar.",
            },
        )
        sync_response = client.post(
            f"/api/integrations/google/events/{event_response.json()['id']}/sync",
            headers=headers,
        )

    assert sync_response.status_code == 200
    payload = sync_response.json()
    assert payload["mode"] == "dry_run"
    assert payload["payload"]["summary"] == "Asamblea sincronizable"
    assert {"method": "popup", "minutes": 30} in payload["payload"]["reminders"]["overrides"]
    assert {"method": "email", "minutes": 60} in payload["payload"]["reminders"]["overrides"]
    assert payload["payload"]["extendedProperties"]["private"]["ccaa_calendar_event_id"]


def test_google_reminder_email_requires_gmail_scope(tmp_path) -> None:
    token_path = tmp_path / "google_token.json"
    token_path.write_text(
        json.dumps(
            {
                "token": "fake-token",
                "refresh_token": "fake-refresh",
                "token_uri": "https://oauth2.googleapis.com/token",
                "client_id": "fake-client",
                "client_secret": "fake-secret",
                "scopes": ["https://www.googleapis.com/auth/calendar.events"],
            }
        ),
        encoding="utf-8",
    )

    settings = Settings(
        database_url="sqlite:///:memory:",
        google_token_path=str(token_path),
        google_gmail_scopes="https://www.googleapis.com/auth/gmail.send",
    )
    app.dependency_overrides[get_settings] = lambda: settings
    headers, organization, _ = authorized_user("editor")
    try:
        with TestClient(app) as client:
            created_event = client.post(
                "/api/events",
                headers=headers,
                json={
                    "organization_id": organization.id,
                    "title": "Asamblea con correo",
                    "category": "centro",
                    "visibility": "organization",
                    "starts_at": "2026-05-28T10:00:00-04:00",
                    "ends_at": "2026-05-28T11:00:00-04:00",
                    "description": "Evento para probar scope Gmail.",
                },
            ).json()
            response = client.post(
                f"/api/integrations/google/events/{created_event['id']}/reminder-email",
                headers=headers,
                json={"recipient_email": "directiva@example.com"},
            )
    finally:
        app.dependency_overrides.clear()

    assert response.status_code == 409
    assert "Gmail send scope is missing" in response.json()["detail"]


def test_google_event_payload_includes_default_reminders() -> None:
    payload = google_event_payload(
        Event(
            id="event-reminders",
            organization_id="org-reminders",
            title="Asamblea con recordatorios",
            description="Prueba de recordatorios.",
            category="centro",
            visibility="organization",
            starts_at=datetime(2026, 5, 28, 10, 0, tzinfo=UTC),
            ends_at=datetime(2026, 5, 28, 11, 0, tzinfo=UTC),
        )
    )

    assert payload["reminders"]["useDefault"] is False
    assert len(payload["reminders"]["overrides"]) == 2


def test_google_calendar_filter_skips_private_personal_event() -> None:
    assert _is_calendar_event_allowed({"summary": "Cátedra 2 Psico. Educacional I"})
    assert _is_calendar_event_allowed({"summary": "Trabajo 4 Psico. Educacional I"})
    assert not _is_calendar_event_allowed({"summary": "Cita personal"})


def test_rut_validation_and_masking() -> None:
    assert normalize_rut("21.452.686-7") == "21452686-7"
    assert is_valid_rut("21452686-7")
    assert not is_valid_rut("21248704-2")
    assert mask_rut("21452686-7") == "***686-7"


def test_admin_roster_flags_invalid_ruts(tmp_path) -> None:
    roster = tmp_path / "admins.json"
    roster.write_text(
        """
        {
          "admins": [
            {
              "rut": "21452686-7",
              "email": "demo@example.com",
              "display_name": "Demo",
              "role": "admin"
            },
            {
              "rut": "21248704-2",
              "email": "invalid@example.com",
              "display_name": "Invalid",
              "role": "editor"
            }
          ]
        }
        """,
        encoding="utf-8",
    )

    entries = load_admin_roster(roster, pepper="test-pepper")

    assert entries[0].can_login
    assert entries[1].status == "needs_rut_confirmation"
    assert not entries[1].can_login


def test_auth_lookup_reports_activation_and_login_states(tmp_path) -> None:
    test_email = f"lookup-{uuid.uuid4()}@example.com"
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": test_email,
                        "display_name": "Directiva Demo",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    pepper = f"lookup-pepper-{uuid.uuid4()}"
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=pepper,
    )

    try:
        with TestClient(app) as client:
            needs_activation = client.post(
                "/api/auth/lookup",
                json={"rut": "21.452.686-7"},
            )
            assert needs_activation.status_code == 200
            assert needs_activation.json()["status"] == "needs_activation"
            assert needs_activation.json()["email_hint"] == test_email

            client.post(
                "/api/auth/activate",
                json={
                    "rut": "21.452.686-7",
                    "password": "orbit-demo-seguro",
                    "first_name": "Directiva",
                    "last_name": "Demo",
                    "email": test_email,
                },
            )
            ready = client.post("/api/auth/lookup", json={"rut": "21452686-7"})
            assert ready.status_code == 200
            assert ready.json()["status"] == "ready_to_login"

            unknown = client.post("/api/auth/lookup", json={"rut": "18.765.432-1"})
            assert unknown.status_code == 200
            assert unknown.json()["status"] == "not_in_roster"
    finally:
        app.dependency_overrides.clear()


def test_auth_bootstrap_does_not_expose_center_email(tmp_path) -> None:
    app.dependency_overrides[get_settings] = lambda: Settings(
        google_center_account_email="centro.psicologia@example.com",
    )
    try:
        with TestClient(app) as client:
            response = client.get("/api/auth/bootstrap")
            centers = client.get("/api/centers")
        assert response.status_code == 200
        payload = response.json()
        assert payload["official_email"] is None
        assert payload["official_email_configured"] is True
        assert centers.status_code == 200
        assert all(center["official_email"] is None for center in centers.json())
    finally:
        app.dependency_overrides.clear()


def test_admin_can_activate_and_login_with_rut(tmp_path) -> None:
    test_email = f"directiva-{uuid.uuid4()}@example.com"
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": test_email,
                        "display_name": "Directiva Demo",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    pepper = f"test-pepper-{tmp_path.name}-{uuid.uuid4()}"
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=pepper,
    )

    try:
        with TestClient(app) as client:
            activate_response = client.post(
                "/api/auth/activate",
                json={"rut": "21.452.686-7", "password": "orbit-demo-seguro"},
            )
            login_response = client.post(
                "/api/auth/login",
                json={"rut": "21452686-7", "password": "orbit-demo-seguro"},
            )
            admin_response = client.get(
                "/api/admin/users",
                headers={"Authorization": f"Bearer {login_response.json()['token']}"},
            )

        assert activate_response.status_code == 201
        assert activate_response.json()["rut_masked"] == "***686-7"
        assert activate_response.json()["role"] == "admin"
        assert login_response.status_code == 200
        assert login_response.json()["email"] == test_email
        assert login_response.json()["token"]
        assert admin_response.status_code == 200
        assert admin_response.json()[0]["rut_masked"] == "***686-7"
    finally:
        app.dependency_overrides.clear()


def test_admin_endpoints_require_internal_session() -> None:
    with TestClient(app) as client:
        response = client.get("/api/admin/users")

    assert response.status_code == 401


def test_space_reservation_rejects_time_conflict(tmp_path) -> None:
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": f"spaces-{uuid.uuid4()}@example.com",
                        "display_name": "Reservas Demo",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=f"spaces-pepper-{tmp_path.name}-{uuid.uuid4()}",
    )

    try:
        with TestClient(app) as client:
            session_response = client.post(
                "/api/auth/activate",
                json={"rut": "21.452.686-7", "password": "orbit-demo-seguro"},
            )
            token = session_response.json()["token"]
            organization = next(
                item
                for item in client.get("/api/organizations").json()
                if item["slug"] == "ccaa-psicologia"
            )
            headers = {"Authorization": f"Bearer {token}"}
            space_response = client.post(
                "/api/spaces",
                headers=headers,
                json={
                    "organization_id": organization["id"],
                    "name": f"Auditorio Demo {uuid.uuid4()}",
                    "slug": f"auditorio-demo-{uuid.uuid4()}",
                    "capacity": 120,
                    "location": "Campus Maipu",
                },
            )
            reservation_payload = {
                "organization_id": organization["id"],
                "space_id": space_response.json()["id"],
                "title": "Asamblea Psicologia",
                "description": "Reserva desde CCAACalendar.",
                "starts_at": "2026-05-26T14:00:00-04:00",
                "ends_at": "2026-05-26T16:00:00-04:00",
            }
            first_reservation = client.post(
                "/api/spaces/reservations",
                headers=headers,
                json=reservation_payload,
            )
            conflict_response = client.post(
                "/api/spaces/reservations",
                headers=headers,
                json={**reservation_payload, "title": "Evento Kinesiologia"},
            )

        assert space_response.status_code == 201
        assert first_reservation.status_code == 201
        assert first_reservation.json()["category"] == "espacio"
        assert conflict_response.status_code == 409
    finally:
        app.dependency_overrides.clear()


def test_password_reset_confirm_updates_password(tmp_path) -> None:
    test_email = f"reset-{uuid.uuid4()}@example.com"
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": test_email,
                        "display_name": "Reset Demo",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    pepper = f"reset-pepper-{uuid.uuid4()}"
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=pepper,
        mail_fallback_console=True,
    )

    try:
        with TestClient(app) as client:
            client.post(
                "/api/auth/activate",
                json={"rut": "21.452.686-7", "password": "clave-vieja-12345"},
            )
            request_response = client.post(
                "/api/auth/password-reset/request",
                json={"rut": "21.452.686-7"},
            )
            assert request_response.status_code == 200

            session = next(get_session())
            user = session.scalar(select(User).where(User.email == test_email))
            assert user and user.password_reset_token_hash

            token = new_public_token()
            user.password_reset_token_hash = hash_token(token)
            session.add(user)
            session.commit()

            confirm = client.post(
                "/api/auth/password-reset/confirm",
                json={
                    "rut": "21.452.686-7",
                    "token": token,
                    "password": "clave-nueva-12345",
                },
            )
            assert confirm.status_code == 200
            login = client.post(
                "/api/auth/login",
                json={"rut": "21452686-7", "password": "clave-nueva-12345"},
            )
            assert login.status_code == 200
    finally:
        app.dependency_overrides.clear()


def test_academic_import_parser_detects_dates_from_csv() -> None:
    parsed = parse_academic_calendar(
        "calendario.csv",
        b"fecha,titulo\n2026-03-10,Inicio de semestre\n01/05/2026,Feriado irrenunciable\n",
    )

    assert parsed["line_count"] == 3
    assert len(parsed["candidates"]) == 2
    assert parsed["candidates"][0]["title"] == "Inicio de semestre"
    assert parsed["candidates"][1]["category"] == "feriado"


def test_academic_import_preview_and_commit(tmp_path) -> None:
    import_title = f"Inicio de semestre {uuid.uuid4().hex}"
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": f"import-{uuid.uuid4()}@example.com",
                        "display_name": "Importadora Demo",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=f"import-pepper-{tmp_path.name}-{uuid.uuid4()}",
    )

    try:
        with TestClient(app) as client:
            session_response = client.post(
                "/api/auth/activate",
                json={"rut": "21.452.686-7", "password": "orbit-demo-seguro"},
            )
            token = session_response.json()["token"]
            headers = {"Authorization": f"Bearer {token}"}
            preview = client.post(
                "/api/imports/academic/preview",
                headers=headers,
                data={"year": "2026"},
                files={
                    "file": (
                        "calendario.csv",
                        f"fecha,titulo\n2026-03-10,{import_title}\n".encode(),
                        "text/csv",
                    )
                },
            )
            commit = client.post(
                f"/api/imports/academic/{preview.json()['import_id']}/commit",
                headers=headers,
                json={
                    "candidates": [
                        {
                            **preview.json()["candidates"][0],
                            "title": f"{import_title} editado",
                            "starts_at": "2026-03-12T13:30:00+00:00",
                            "ends_at": "2026-03-12T14:30:00+00:00",
                            "category": "centro",
                        }
                    ],
                    "notify_subscribers": False,
                },
            )

        assert preview.status_code == 201
        assert preview.json()["candidates"][0]["title"] == import_title
        assert commit.status_code == 200
        assert commit.json()["imported_events"] == 1
        with SessionLocal() as session:
            event = session.get(Event, commit.json()["event_ids"][0])
            assert event
            assert event.title == f"{import_title} editado"
            assert event.category == "centro"
    finally:
        app.dependency_overrides.clear()


def test_admin_can_update_other_user_role_and_status(tmp_path) -> None:
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "email": f"admin-{uuid.uuid4()}@example.com",
                        "display_name": "Admin Principal",
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=f"admin-pepper-{tmp_path.name}-{uuid.uuid4()}",
    )

    try:
        with TestClient(app) as client:
            session_response = client.post(
                "/api/auth/activate",
                json={"rut": "21.452.686-7", "password": "orbit-demo-seguro"},
            )
            admin_user_id = session_response.json()["user_id"]
            token = session_response.json()["token"]
            headers = {"Authorization": f"Bearer {token}"}

            with SessionLocal() as session:
                admin_user = session.get(User, admin_user_id)
                assert admin_user
                target = User(
                    organization_id=admin_user.organization_id,
                    email=f"target-{uuid.uuid4()}@example.com",
                    display_name="Editora Operativa",
                    role="viewer",
                    is_active=True,
                    email_notifications_enabled=True,
                )
                session.add(target)
                session.commit()
                target_id = target.id

            response = client.patch(
                f"/api/admin/users/{target_id}",
                headers=headers,
                json={
                    "role": "editor",
                    "is_active": False,
                    "email_notifications_enabled": False,
                },
            )

        assert response.status_code == 200
        assert response.json()["role"] == "editor"
        assert response.json()["is_active"] is False
        assert response.json()["email_notifications_enabled"] is False
    finally:
        app.dependency_overrides.clear()


def test_password_reset_request_uses_neutral_message(tmp_path) -> None:
    roster = tmp_path / "admins.json"
    roster.write_text('{"admins": []}', encoding="utf-8")
    app.dependency_overrides[get_settings] = lambda: Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=f"test-pepper-{tmp_path.name}",
    )

    try:
        with TestClient(app) as client:
            response = client.post(
                "/api/auth/password-reset/request",
                json={"rut": "11.111.111-1"},
            )

        assert response.status_code == 200
        assert response.json()["message"].startswith("Si los datos existen")
    finally:
        app.dependency_overrides.clear()


def test_event_create_queues_email_notifications(tmp_path) -> None:
    db_path = tmp_path / "notifications.db"
    settings = Settings(
        database_url=f"sqlite:///{db_path}",
        event_email_reminder_minutes="1440,60",
        mail_fallback_console=True,
    )
    app.dependency_overrides[get_settings] = lambda: settings
    headers, organization, _ = authorized_user("admin")

    try:
        with TestClient(app) as client:
            with SessionLocal() as session:
                user = User(
                    organization_id=organization.id,
                    email=f"integrante-{uuid.uuid4()}@example.com",
                    display_name="Integrante Prueba",
                    role="admin",
                    is_active=True,
                    email_notifications_enabled=True,
                )
                session.add(user)
                session.commit()
                user_id = user.id

            event = client.post(
                "/api/events",
                headers=headers,
                json={
                    "organization_id": organization.id,
                    "title": "Asamblea general",
                    "category": "centro",
                    "visibility": "organization",
                    "starts_at": "2026-12-01T15:00:00+00:00",
                    "ends_at": "2026-12-01T16:00:00+00:00",
                    "description": "Prueba de cola de correos.",
                    "created_by_user_id": user_id,
                    "notify_subscribers": True,
                },
            )
            assert event.status_code == 201

            with SessionLocal() as session:
                queued = list(
                    session.scalars(
                        select(EventEmailQueue).where(
                            EventEmailQueue.event_id == event.json()["id"]
                        )
                    )
                )
                kinds = {item.kind for item in queued}
                assert "created" in kinds
                assert "reminder" in kinds
                assert len(queued) >= 2
    finally:
        app.dependency_overrides.clear()


def test_profile_notification_preference(tmp_path) -> None:
    test_email = f"perfil-{uuid.uuid4()}@example.com"
    roster = tmp_path / "admins.json"
    roster.write_text(
        json.dumps(
            {
                "admins": [
                    {
                        "rut": "21.452.686-7",
                        "display_name": "Pablo Prueba",
                        "email": test_email,
                        "role": "admin",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    settings = Settings(
        admin_roster_path=str(roster),
        admin_identity_pepper=f"test-pepper-{tmp_path.name}",
        database_url=f"sqlite:///{tmp_path / 'profile.db'}",
    )
    app.dependency_overrides[get_settings] = lambda: settings

    try:
        with TestClient(app) as client:
            lookup = client.post("/api/auth/lookup", json={"rut": "21452686-7"})
            assert lookup.status_code == 200
            if lookup.json()["status"] == "ready_to_login":
                session_response = client.post(
                    "/api/auth/login",
                    json={"rut": "21452686-7", "password": "clave-segura-123"},
                )
            else:
                session_response = client.post(
                    "/api/auth/activate",
                    json={"rut": "21452686-7", "password": "clave-segura-123"},
                )
            assert session_response.status_code in {200, 201}, session_response.text
            token = session_response.json()["token"]
            headers = {"Authorization": f"Bearer {token}"}

            profile = client.get("/api/auth/me", headers=headers)
            assert profile.status_code == 200
            initial = profile.json()["email_notifications_enabled"]

            updated = client.patch(
                "/api/auth/me/notifications",
                headers=headers,
                json={"email_notifications_enabled": not initial},
            )
            assert updated.status_code == 200
            assert updated.json()["email_notifications_enabled"] is not initial
    finally:
        app.dependency_overrides.clear()


def test_email_template_renders_brand_html() -> None:
    from ccaa_calendar.integrations.email_templates import render_email_html
    from ccaa_calendar.settings import Settings

    settings = Settings(app_public_url="https://example.test")
    html = render_email_html(
        settings,
        preheader="Vista previa",
        headline="Titulo <script>",
        greeting="Hola",
        paragraphs=["Parrafo con <em>html</em> escapado."],
        highlight=("Evento", [("Inicio", "01/01/2026 10:00")], "#ff7a2f"),
        cta=("https://example.test/app", "Abrir"),
    )
    assert "CCAACalendar" in html
    assert "#ff7a2f" in html
    assert "orbit-icon.svg" in html
    assert "<svg " in html
    assert "&lt;script&gt;" in html
    assert "<script>" not in html
    assert "Calendario institucional del centro" in html
