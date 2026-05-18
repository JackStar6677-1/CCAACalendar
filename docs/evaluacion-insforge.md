# Evaluacion de InsForge para CCAACalendar

Fecha: 2026-05-18

## Resumen corto

InsForge es una plataforma backend open source orientada a desarrollo asistido por agentes. Junta Postgres, auth, storage, funciones, realtime, hosting y un MCP para que una IA pueda operar el backend con herramientas estandarizadas.

Para CCAACalendar **no conviene migrar ahora**. El proyecto ya tiene FastAPI, SQLAlchemy, OAuth Google, RUT interno, reservas y reglas de negocio propias. Cambiar a InsForge en este punto agregaria una capa nueva de infraestructura y podria distraer del piloto de Psicologia.

Si conviene rescatar algunas ideas de arquitectura.

## Que podria aportar

- **Postgres como base principal:** CCAACalendar ya lo tiene como objetivo. InsForge refuerza que el salto desde SQLite debe hacerse pronto si queremos multi-centro real.
- **RLS / aislamiento por organizacion:** para futuro multi-centro, las reglas de permisos deben vivir tambien en la base, no solo en FastAPI.
- **Storage tipo S3:** ideal para la importacion de Word/PDF/Excel del calendario academico anual.
- **Auth persistente:** InsForge usa sesiones/JWT; CCAACalendar todavia tiene sesiones internas en memoria para el piloto. Hay que persistirlas antes de produccion.
- **Funciones programadas:** la idea de scheduled functions aplica perfecto para recordatorios automaticos, reintentos de correos y sincronizacion periodica con Google Calendar.
- **Realtime:** util mas adelante para que varias administradoras vean cambios de calendario/reservas sin refrescar.
- **Logs y auditoria de plataforma:** coincide con lo que necesitamos para saber por que falla OAuth, correos o reservas.

## Por que no adoptarlo ahora

- CCAACalendar tiene reglas especificas: RUT chileno, cuenta Google unica del centro, feriados chilenos, reservas con choques y auditoria por centro.
- FastAPI ya expresa bien esas reglas y evita depender de APIs genericas para logica sensible.
- InsForge agregaria Docker/Node/PostgREST/Deno a un piloto que hoy corre simple en Python.
- La prioridad actual es terminar funcionalidad visible para Kika/CCAA, no rehacer la base.
- El despliegue objetivo ya esta claro: VPS Ubuntu, Docker, Caddy, PostgreSQL y backups.

## Decision

No integrar InsForge como runtime de CCAACalendar en esta etapa.

Usarlo como referencia de arquitectura para:

1. migrar a PostgreSQL;
2. disenar RLS por `organization_id` y `center_id`;
3. preparar storage privado para archivos importados;
4. persistir sesiones internas con expiracion;
5. crear workers/schedules para recordatorios y sync Google;
6. mejorar logs, auditoria y diagnostico.

## Camino recomendado para CCAACalendar

### Fase piloto

- Mantener FastAPI + SQLite local.
- Seguir con Cloudflare Tunnel para demos.
- Completar importacion academica minima desde CSV/Excel antes de Word/PDF.
- Persistir sesiones internas en base de datos.

### Fase pre-produccion

- Migrar a PostgreSQL.
- Agregar tabla de sesiones o JWT firmado propio.
- Guardar documentos importados en almacenamiento privado local o S3 compatible.
- Separar worker de recordatorios y sincronizacion Google.
- Crear migraciones Alembic formales para `spaces`, `events`, `users`, `audit_log`.

### Fase SaaS / multi-centro

- RLS en Postgres para aislamiento de centros.
- Storage S3 compatible para archivos de calendario anual.
- Realtime opcional para cambios de calendario.
- Observabilidad: logs estructurados, errores frontend y auditoria consultable desde admin.

## Nota tecnica

Si mas adelante queremos probar InsForge, conviene hacerlo en un branch o repo laboratorio, no sobre `main`. La prueba deberia ser acotada:

- levantar InsForge con Docker;
- modelar solo `organizations`, `centers`, `spaces` y `events`;
- probar auth y RLS;
- comparar complejidad contra FastAPI actual.

Si no reduce complejidad real, se descarta.
