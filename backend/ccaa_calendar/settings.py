from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "CCAACalendar"
    public_brand_name: str = "CCAACalendar"
    environment: str = "local"
    database_url: str = "sqlite:///./.local/ccaa_calendar.db"
    allowed_origins: str = "http://localhost:5173,http://127.0.0.1:5173"
    google_client_id: str = ""
    google_client_secret: str = ""
    google_redirect_uri: str = "http://localhost:8000/api/integrations/google/callback"
    google_calendar_scopes: str = "https://www.googleapis.com/auth/calendar.events"
    google_gmail_scopes: str = ""
    google_center_account_email: str = ""
    google_calendar_id: str = "primary"
    google_client_secret_file: str = ".local/google_oauth_client_secret.json"
    google_oauth_state_path: str = ".local/google_oauth_state.json"
    google_token_path: str = ".local/google_token.json"
    admin_roster_path: str = ".local/admin_roster.json"
    admin_identity_pepper: str = "change-this-local-secret"
    app_log_path: str = ".local/logs/ccaa-calendar.jsonl"
    app_public_url: str = "https://ccaa.drakescraft.cl"
    mail_from_email: str = ""
    mail_from_name: str = "CCAACalendar · CE Psicología UDLA"
    mail_fallback_console: bool = True
    smtp_host: str = ""
    smtp_port: int = 587
    smtp_username: str = ""
    smtp_password: str = ""
    smtp_from_email: str = ""
    smtp_from_name: str = ""
    smtp_use_tls: bool = True
    smtp_use_ssl: bool = False
    event_email_reminder_minutes: str = "1440,60"
    event_email_worker_interval_seconds: int = 60

    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    def event_reminder_offsets(self) -> list[int]:
        offsets: list[int] = []
        for part in self.event_email_reminder_minutes.split(","):
            part = part.strip()
            if not part:
                continue
            try:
                value = int(part)
            except ValueError:
                continue
            if 5 <= value <= 10080:
                offsets.append(value)
        return sorted(set(offsets), reverse=True)

    @property
    def cors_origins(self) -> list[str]:
        return [origin.strip() for origin in self.allowed_origins.split(",") if origin.strip()]

    @property
    def is_local(self) -> bool:
        return self.environment.lower() in {"local", "dev", "development"}

    @property
    def google_scopes(self) -> list[str]:
        scopes = f"{self.google_calendar_scopes},{self.google_gmail_scopes}"
        return [scope.strip() for scope in scopes.split(",") if scope.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()

