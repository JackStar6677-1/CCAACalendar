import os
import shutil
import tempfile
from pathlib import Path

TEST_RUNTIME_DIR = Path(tempfile.mkdtemp(prefix="ccaa-calendar-tests-"))

# La base y los logs de pytest nunca deben mezclarse con la instancia local visible.
os.environ["DATABASE_URL"] = f"sqlite:///{(TEST_RUNTIME_DIR / 'tests.db').as_posix()}"
os.environ["APP_LOG_PATH"] = str(TEST_RUNTIME_DIR / "tests.jsonl")


def pytest_sessionfinish() -> None:
    """Libera la SQLite temporal y elimina los residuos producidos por la suite."""
    try:
        from ccaa_calendar.database import engine

        engine.dispose()
    finally:
        shutil.rmtree(TEST_RUNTIME_DIR, ignore_errors=True)
