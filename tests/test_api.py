import json
import uuid

from fastapi.testclient import TestClient
from kika_orbit.domain.admin_roster import load_admin_roster
from kika_orbit.domain.rut import is_valid_rut, mask_rut, normalize_rut
from kika_orbit.main import app
from kika_orbit.settings import Settings, get_settings


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
    assert "Kika Orbit" in response.text
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
    assert payload["account_email"] == ""
    assert payload["internal_auth"] == "rut_password"
    assert "configured" in payload
    assert payload["calendar_scope"] == "https://www.googleapis.com/auth/calendar.events"


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
