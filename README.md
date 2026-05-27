# CCAACalendar

![CCAACalendar orbital banner](docs/brand/ccaa-calendar-readme-banner.svg)

**Calendario institucional para centros de estudiantes, actividades, avisos y coordinación de espacios.**

> Piloto activo: **Centro de Estudiantes de Psicología · UDLA Maipú**

## Qué Es

CCAACalendar es una web/PWA creada para manejar un calendario oficial sin compartir la
contraseña de la cuenta Google del centro. Cada integrante trabaja con su propia identidad
interna y cada acción relevante puede auditarse.

| Necesidad | Solución en CCAACalendar |
| --- | --- |
| Calendario oficial del centro | Una sola cuenta Google autorizada mediante OAuth |
| Identificar quién realiza cambios | Acceso individual por RUT y clave propia |
| Incorporar nuevas integrantes | Solicitud pública y aprobación administrativa |
| Avisar actividades importantes | Gmail API desde la cuenta oficial y alertas web |
| Cargar fechas académicas | Importador con previsualización y edición previa |
| Evitar choques de espacios | Reservas con validación de conflicto |

## Estado Del Piloto

Estado verificado durante el desarrollo local y la demo pública de mayo de 2026:

| Área | Estado | Detalle |
| --- | --- | --- |
| Web/PWA responsive | Operativo | Portada, panel, calendario, formularios y navegación móvil |
| Autenticación interna | Operativo | RUT + clave propia, roles y auditoría |
| Solicitudes de acceso | Operativo | Formulario público, bandeja admin, aprobación y anti-spam básico |
| Google Calendar | Operativo en demo | Cuenta oficial conectada para sincronización de eventos |
| Gmail API | Operativo en demo | Scope `gmail.send` autorizado para avisos del sistema |
| Privacidad local | Operativo | RUT hasheado; nombres y correos cifrados; secretos fuera de Git |
| Importador académico | Operativo en piloto | Previsualización y edición antes de aprobar hitos |
| Reservas de espacios | En mejora | Validación de choques disponible; falta pulir la experiencia visual |
| Sincronización entrante Google | Pendiente | Falta reconciliación automática robusta desde Calendar hacia la app |
| Multi-centro | Pendiente | Dirección prevista para una segunda fase |

## Demo Pública

La demostración temporal se publica mediante Cloudflare Tunnel:

- Sitio: [https://ccaa.drakescraft.cl](https://ccaa.drakescraft.cl)
- Entorno actual: instancia de desarrollo local expuesta por túnel
- Producción objetivo: VPS Linux, PostgreSQL, proxy HTTPS y respaldos

La disponibilidad de la demo depende de que el equipo de desarrollo y el túnel estén
encendidos. No representa todavía un despliegue productivo permanente.

## Modelo De Acceso

La cuenta Google del centro **no es una cuenta de inicio de sesión para las integrantes**.
Sirve únicamente como calendario y remitente institucional.

### Integrante ya autorizada

1. Ingresa su RUT.
2. Si es primera vez, crea su clave personal.
3. Si ya activó su cuenta, inicia sesión.
4. Según su rol, puede consultar, editar eventos o administrar accesos.

### Integrante que aún no tiene acceso

1. Selecciona **Solicitar acceso al centro**.
2. Completa nombre, RUT, correo, rol solicitado y consentimiento.
3. La solicitud queda pendiente; no obtiene permisos automáticamente.
4. Una administradora recibe el aviso y revisa la solicitud en el panel.
5. Si es aprobada, la integrante vuelve a verificar su RUT y crea su clave personal.

### Roles

| Rol | Alcance |
| --- | --- |
| `viewer` | Consulta información interna permitida |
| `editor` | Crea y modifica actividades o reservas |
| `admin` | Revisa solicitudes, gestiona integrantes y conecta integraciones |
| `owner` | Control administrativo ampliado del centro |

## Privacidad Y Seguridad

Este repositorio es público, pero los datos operativos privados no forman parte de Git.

| Dato | Tratamiento |
| --- | --- |
| RUT | Hash HMAC con pepper privado y versión enmascarada para UI |
| Nombre y correo | Cifrado autenticado mediante `PII_ENCRYPTION_KEYS` |
| Contraseñas | Hash seguro; nunca se almacenan en texto claro |
| Tokens Google OAuth | Archivo local protegido, fuera del repositorio |
| Solicitudes de acceso | Cifradas, auditadas y sujetas a aprobación |
| Formulario público | Deduplicación por RUT pendiente y límite anti-spam básico |

No subir nunca:

```text
.env
.local/
client_secret_*.json
google_token.json
admin_roster.json real
credenciales de Cloudflare o del servidor
```

## Google Calendar Y Gmail

El piloto usa una sola cuenta oficial del centro:

| Uso | API / Scope |
| --- | --- |
| Crear, editar y cancelar eventos institucionales | Google Calendar API · `calendar.events` |
| Avisos, recordatorios y correos operativos | Gmail API · `gmail.send` |

Redirect público usado en la demo:

```text
https://ccaa.drakescraft.cl/api/integrations/google/callback
```

La autorización OAuth se realiza desde el panel administrativo. No se solicita ni se
almacena la contraseña de la cuenta Google.

## Funcionalidad Disponible

### Calendario

- Vista mensual y agenda del día.
- Eventos del centro, categorías y feriados chilenos.
- Creación, edición y cancelación de eventos.
- Sincronización de eventos propios hacia Google Calendar.
- Alertas del navegador y avisos por correo.

### Administración

- Usuarios internos con roles.
- Solicitudes de acceso pendientes, aprobadas o rechazadas.
- Reintento de aviso por correo si una notificación falla.
- Auditoría de accesos y decisiones administrativas.

### Importación Académica

- Lectura inicial de archivos CSV, Excel, Word, PDF o TXT.
- Previsualización de hitos encontrados.
- Edición manual de título, fecha, categoría y descripción.
- Publicación solo después de la aprobación administrativa.

### Espacios

- Registro de espacios compartidos.
- Creación de reservas.
- Rechazo de horarios solapados.
- Base preparada para una futura vista multi-centro.

## Arquitectura

| Capa | Tecnología / Responsabilidad |
| --- | --- |
| Frontend | HTML, CSS y JavaScript servido como PWA |
| Backend | Python + FastAPI |
| Persistencia local | SQLite |
| Persistencia objetivo | PostgreSQL con migraciones Alembic |
| Integraciones | Google Calendar API y Gmail API mediante OAuth 2.0 |
| Seguridad | Hash de claves, cifrado PII, auditoría y control de roles |
| Referencia heredada | Calendario Castel conservado en `legacy/castel-calendar` |

## Estructura Del Repositorio

```text
backend/ccaa_calendar/
  api/                          API REST, auth y panel admin
  domain/                       RUT, feriados y protección de datos
  integrations/                 OAuth, Calendar y correos HTML
  workers/                      Cola de correos y recordatorios
  web/static/                   PWA responsive
docs/                           Requerimientos, despliegue y marca
legacy/castel-calendar/         Referencia funcional de Castel
migrations/                     Migraciones Alembic
tests/                          Pruebas de API, seguridad y flujos
```

## API Principal

| Área | Rutas principales |
| --- | --- |
| Salud | `GET /api/health` |
| Acceso | `POST /api/auth/lookup`, `POST /api/auth/activate`, `POST /api/auth/login` |
| Solicitudes | `POST /api/auth/access-requests` |
| Administración | `GET /api/admin/users`, `GET /api/admin/access-requests`, `GET /api/admin/audit` |
| Decisiones admin | `PATCH /api/admin/access-requests/{id}`, `POST /api/admin/access-requests/{id}/notify` |
| Eventos | `/api/events` |
| Espacios | `/api/spaces`, `/api/spaces/reservations` |
| Feriados | `GET /api/holidays?year=2026` |
| Google | `/api/integrations/google/status`, `/authorize-url`, `/callback` |

Rutas web: `/`, `/login`, `/app`, `/manifest.webmanifest`, `/sw.js`, `/offline`.

## Quickstart Local

```powershell
uv sync
uv run alembic upgrade head
uv run uvicorn ccaa_calendar.main:app --app-dir backend --host 127.0.0.1 --port 8000
```

Abrir:

```text
http://127.0.0.1:8000
```

Validar:

```powershell
uv run ruff check .
uv run pytest
```

## Próximos Pasos

1. Recibir y aprobar los datos autorizados de las integrantes del centro.
2. Cargar el calendario académico real y revisar hitos antes de publicarlos.
3. Validar con el centro la creación de eventos, sincronización y avisos.
4. Mejorar reservas visuales, filtros y vista de espacios.
5. Migrar a un despliegue permanente con PostgreSQL y respaldos.
6. Preparar la arquitectura multi-centro cuando el piloto esté consolidado.

## Identidad Visual

| Asset | Ruta |
| --- | --- |
| Logo OAuth | [`docs/brand/ccaa-calendar-oauth-logo.svg`](docs/brand/ccaa-calendar-oauth-logo.svg) |
| Banner | [`docs/brand/ccaa-calendar-readme-banner.svg`](docs/brand/ccaa-calendar-readme-banner.svg) |
| UI / icono | [`backend/ccaa_calendar/web/static`](backend/ccaa_calendar/web/static) |

Paleta: naranjo `#ff7a2f` · violeta `#8f5cff` · dorado `#ffd166` · fondo `#160f1f`.

## Documentación

- [`docs/requerimientos-ccaa.md`](docs/requerimientos-ccaa.md)
- [`docs/estrategia-google-sin-dominio.md`](docs/estrategia-google-sin-dominio.md)
- [`docs/identidad-admin-rut.md`](docs/identidad-admin-rut.md)
- [`docs/diseno-calendario-multiusuario-y-bloqueos.md`](docs/diseno-calendario-multiusuario-y-bloqueos.md)
- [`docs/evaluacion-insforge.md`](docs/evaluacion-insforge.md)
- [`docs/despliegue-demo-google-cloudflare.md`](docs/despliegue-demo-google-cloudflare.md)
- [`docs/checklist-piloto-kika.md`](docs/checklist-piloto-kika.md)

## Licencia

Ver [`LICENSE`](LICENSE).
