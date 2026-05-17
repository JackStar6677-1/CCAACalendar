import json
import uuid
from datetime import UTC, datetime

from ccaa_calendar.domain.admin_roster import load_admin_roster
from ccaa_calendar.domain.rut import is_valid_rut, mask_rut, normalize_rut
from ccaa_calendar.integrations.google_calendar import (
    _is_calendar_event_allowed,
    google_event_payload,
)
from ccaa_calendar.integrations.google_oauth import is_google_oauth_configured
from ccaa_calendar.main import app
from ccaa_calendar.models import Event
from ccaa_calendar.settings import Settings, get_settings
from fastapi.testclient import TestClient


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
    assert "configurada fuera del repo publico" in response.text
    assert styles_response.status_code == 200
    assert manifest_response.status_code == 200


def test_create_organization_and_event() -> None:
    with TestClient(app) as client:
        organization_response = client.post(
            "/api/organizations",
            json={
                "name": "Universidad Demo",
                "slug": "universidad-demo",
                "domain_hint": "demo.edu",
            },
        )

        assert organization_response.status_code in {201, 409}
        organizations = client.get("/api/organizations").json()
        organization = next(item for item in organizations if item["slug"] == "universidad-demo")

        event_response = client.post(
            "/api/events",
            json={
                "organization_id": organization["id"],
                "title": "Reunion centro de estudiantes",
                "category": "reunion",
                "visibility": "organization",
                "starts_at": "2026-05-26T14:00:00-04:00",
                "ends_at": "2026-05-26T16:00:00-04:00",
            },
        )

    assert event_response.status_code == 201
    assert event_response.json()["title"] == "Reunion centro de estudiantes"


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


def test_google_login_persists_pkce_code_verifier(tmp_path) -> None:
    state_path = tmp_path / "oauth_state.json"
    settings = Settings(
        google_client_id="demo-client-id.apps.googleusercontent.com",
        google_client_secret="demo-client-secret",
        google_oauth_state_path=str(state_path),
        google_center_account_email="calendar-owner@example.com",
    )
    app.dependency_overrides[get_settings] = lambda: settings

    try:
        with TestClient(app) as client:
            response = client.get("/api/integrations/google/login", follow_redirects=False)

        assert response.status_code == 307
        assert "code_challenge=" in response.headers["location"]
        state = json.loads(state_path.read_text(encoding="utf-8"))
        assert state["state"]
        assert len(state["code_verifier"]) >= 43
    finally:
        app.dependency_overrides.clear()


def test_google_event_sync_preview_uses_calendar_payload() -> None:
    with TestClient(app) as client:
        organization_response = client.post(
            "/api/organizations",
            json={
                "name": f"Universidad Sync {uuid.uuid4()}",
                "slug": f"universidad-sync-{uuid.uuid4()}",
                "domain_hint": "sync.example",
            },
        )
        organization = organization_response.json()
        event_response = client.post(
            "/api/events",
            json={
                "organization_id": organization["id"],
                "title": "Asamblea sincronizable",
                "category": "centro",
                "visibility": "organization",
                "starts_at": "2026-05-28T10:00:00-04:00",
                "ends_at": "2026-05-28T11:00:00-04:00",
                "description": "Evento listo para previsualizar en Google Calendar.",
            },
        )
        sync_response = client.post(
            f"/api/integrations/google/events/{event_response.json()['id']}/sync"
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
    try:
        with TestClient(app) as client:
            organization = client.post(
                "/api/organizations",
                json={
                    "name": f"Universidad Gmail {uuid.uuid4()}",
                    "slug": f"universidad-gmail-{uuid.uuid4()}",
                    "domain_hint": "gmail.example",
                },
            ).json()
            created_event = client.post(
                "/api/events",
                json={
                    "organization_id": organization["id"],
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

        assert activate_response.status_code == 201
        assert activate_response.json()["rut_masked"] == "***686-7"
        assert activate_response.json()["role"] == "admin"
        assert login_response.status_code == 200
        assert login_response.json()["email"] == test_email
        assert login_response.json()["token"]
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
