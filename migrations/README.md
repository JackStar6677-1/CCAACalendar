# Database migrations

CCAACalendar usa Alembic para preparar el salto de SQLite local a PostgreSQL.

Comandos utiles:

```powershell
uv run alembic current
uv run alembic upgrade head
uv run alembic revision --autogenerate -m "describe change"
```

En desarrollo local todavia se conserva `create_all()` para que el MVP arranque rapido, pero los cambios de esquema deben quedar representados aqui antes de pensar en VPS o produccion.
