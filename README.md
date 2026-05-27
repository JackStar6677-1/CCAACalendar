# CCAACalendar

![CCAACalendar orbital banner](docs/brand/ccaa-calendar-readme-banner.svg)

**Calendario vivo para centros de estudiantes, coordinación universitaria y reservas de espacios.**

> Carpeta local: `CCAACalendar` · Producto público: **CCAACalendar** · Piloto: **CE Psicología · UDLA Maipú**

## En una mirada

```mermaid
graph TD
    U["Integrantes del centro<br/>RUT y clave propia"] -->|"crean y consultan"| P["CCAACalendar PWA"]
    P -->|"sincroniza eventos"| C["Google Calendar oficial<br/>cuenta unica del centro"]
    P -->|"envia avisos"| E["Correos personales<br/>notificaciones opt-in"]
```

## Estado del piloto · mayo 2026

```mermaid
graph LR
    A["Operativo<br/>PWA y auth por RUT<br/>Google Calendar y Gmail<br/>solicitudes de acceso<br/>importador editable"] --> B["En mejora<br/>reservas visuales<br/>categorias y filtros<br/>reconciliacion Google"]
    B --> C["Siguiente etapa<br/>PostgreSQL y VPS<br/>multi-centro<br/>permisos reforzados"]
```

| Componente | Estado actual |
| --- | --- |
| Sitio público | Activo en [ccaa.drakescraft.cl](https://ccaa.drakescraft.cl) mediante Cloudflare Tunnel |
| Acceso interno | RUT + clave propia, con roles y auditoría |
| Solicitudes de acceso | Formulario público, revisión admin, aviso Gmail y control anti-spam |
| Cuenta Google oficial | Calendar y Gmail autorizados para el piloto |
| Datos personales | RUT hasheado; nombre y correo cifrados; secretos fuera de Git |

**Despliegue demo:** túnel Cloudflare hacia una instancia local. Es una exposición temporal para pruebas, no el hosting definitivo.

## Avance vs requerimientos del piloto

```mermaid
graph TD
    D["Hecho en piloto<br/>PWA, RUT, roles y Google del centro<br/>Gmail, importador y solicitudes admin"] --> M["En desarrollo<br/>reservas mas visuales<br/>filtros y sync entrante robusto"]
    M --> F["Futuro<br/>multi-centro<br/>estadisticas y produccion VPS"]
```

El piloto ya permite aprobar hitos importados antes de publicarlos, recibir solicitudes de nuevas integrantes y separar quién hizo cada cambio. La escala universidad pasa por capas multi-centro y un despliegue permanente endurecido.

## Cerrar el piloto Psicología

```mermaid
graph LR
    A["1. Recibir datos autorizados<br/>nombre, RUT y rol"] --> B["2. Aprobar accesos<br/>desde panel admin"]
    B --> C["3. Cargar calendario<br/>academico real"]
    C --> D["4. Probar eventos<br/>sync y avisos"]
    D --> E["5. Definir hosting<br/>permanente"]
```

## Problema y solución

```mermaid
graph LR
    X["Antes<br/>chats mezclados<br/>calendarios personales<br/>sin trazabilidad"] -->|"piloto Psicologia"| Y["Con CCAACalendar<br/>calendario oficial<br/>roles internos<br/>auditoria por RUT"]
```

## Dos cuentas, dos roles

```mermaid
graph TD
    A["Cuenta Google oficial del centro<br/>una sola conexion OAuth"] -->|"agenda institucional"| B["CCAACalendar"]
    C["Integrante<br/>RUT y clave propia"] -->|"acciones identificadas"| B
    B -->|"avisos voluntarios"| D["Correo personal<br/>sin calendario personal"]
```

## Arquitectura técnica

```mermaid
graph TD
    A["Integrante autorizada<br/>RUT y clave"] --> W["PWA web"]
    W --> API["FastAPI"]
    API --> DB["SQLite local<br/>PostgreSQL objetivo"]
    API --> AUD["Auditoria y roles"]
    API --> FER["Feriados Chile"]
    API --> Q["Cola de correos"]
    Q --> WK["Worker de avisos"]
    API --> OAUTH["OAuth Google"]
    OAUTH --> G["Calendar y Gmail<br/>cuenta del centro"]
    WK --> G
    LEG["Calendario Castel<br/>referencia funcional"] -.-> API
```

## Flujos principales

### Acceso con RUT

```mermaid
graph TD
    S["Ingresa RUT"] --> L{"Acceso habilitado"}
    L -->|"No"| R["Solicitar acceso al centro"]
    R --> V{"Revision administrativa"}
    V -->|"Pendiente o rechazo"| B["Sin permiso de edicion"]
    V -->|"Aprobado"| A["Crear clave personal"]
    L -->|"Primera vez"| A
    L -->|"Cuenta activa"| I["Iniciar sesion"]
    A --> P["Panel CCAACalendar"]
    I --> P
```

### Crear evento, Google y correos

```mermaid
graph LR
    I["Integrante crea evento"] --> P["CCAACalendar valida y guarda"]
    P --> G["Publica en Google Calendar"]
    P --> Q["Encola avisos"]
    Q --> M["Gmail envia recordatorios"]
    P --> N["Alerta opcional<br/>en navegador"]
```

### Reserva de auditorio

```mermaid
graph TD
    A["Integrante pide horario"] --> B{"Existe conflicto"}
    B -->|"No"| C["Reserva confirmada<br/>visible en calendario"]
    B -->|"Si"| D["Solicitud rechazada<br/>elegir otro bloque"]
    C --> E["Ocupacion compartida<br/>en futuras capas"]
```

### Conectar Google del centro (una vez)

```mermaid
graph LR
    S["Directiva autenticada"] --> L["Conectar Google oficial"]
    L --> O["Consentimiento Google<br/>Calendar y Gmail"]
    O --> T["Token protegido<br/>fuera del repositorio"]
    T --> U["Sincronizacion y correos activos"]
```

## Visión multi-centro

```mermaid
graph TD
    APP["CCAACalendar<br/>una plataforma"] --> P["Psicologia<br/>piloto activo"]
    APP --> K["Kinesiologia<br/>futuro"]
    APP --> EN["Enfermeria<br/>futuro"]
    APP --> V["Veterinaria<br/>futuro"]
    APP --> DAE["DAE<br/>futuro"]
    P --> ESP["Espacios compartidos<br/>auditorio y salas"]
```

## Roadmap

```mermaid
graph LR
    H["Hecho<br/>auth RUT, solicitudes, PWA<br/>Google, Gmail e importador"] --> S["Siguiente<br/>calendario pulido<br/>espacios y filtros"]
    S --> P["Preproduccion<br/>VPS y PostgreSQL<br/>sesiones endurecidas"]
    P --> M["Escala<br/>multi-centro<br/>permisos por organizacion"]
```

## Stack

```mermaid
graph LR
    FE["HTML, CSS y JavaScript<br/>PWA"] --> BE["Python y FastAPI"]
    BE --> ORM["SQLAlchemy y Alembic"]
    ORM --> DB["SQLite local<br/>PostgreSQL objetivo"]
    BE --> G["Google Calendar y Gmail APIs"]
    BE --> T["Pytest y Ruff"]
```

## Estructura del repo

```text
backend/ccaa_calendar/          Producto FastAPI
  api/                          REST, auth y bandeja admin
  domain/                       RUT, feriados y proteccion PII
  integrations/                 OAuth, Calendar y correos HTML
  workers/                      Cola de correos y recordatorios
  web/static/                   PWA responsive
docs/                           Producto y marca
legacy/castel-calendar/         Referencia UI
migrations/                     Alembic, incluida tabla de solicitudes
tests/                          API, seguridad y flujos del piloto
```

## Quickstart

```powershell
uv sync
uv run uvicorn ccaa_calendar.main:app --app-dir backend --reload
```

Abrir `http://127.0.0.1:8000/` · Health: `GET /api/health`

```powershell
uv run ruff check .
uv run pytest
uv run alembic upgrade head
```

## Google Cloud (conexión del centro)

```mermaid
graph TD
    GC["Proyecto Google Cloud"] --> CA["Google Calendar API"]
    GC --> GM["Gmail API"]
    GC --> O["Cliente OAuth web"]
    O --> R["Redirect local y publico"]
    O --> U["Cuenta oficial autorizada"]
    U --> OK["Sync de eventos y avisos activos"]
```

Redirect público utilizado en la demo:

```text
https://ccaa.drakescraft.cl/api/integrations/google/callback
```

Scopes autorizados actualmente: `calendar.events` y `gmail.send`.

## API y rutas web

```mermaid
graph TD
    WEB["PWA"] --> AUTH["Auth<br/>lookup, activate y login"]
    WEB --> REQ["Solicitud de acceso<br/>access-requests"]
    WEB --> CAL["Calendario<br/>events y holidays"]
    WEB --> SPA["Espacios<br/>reservations"]
    AUTH --> ADM["Administracion<br/>users y audit"]
    REQ --> ADM
    CAL --> G["Google<br/>OAuth y sync"]
    SPA --> G
```

Detalle en código: prefijo `/api` · PWA en `/`, `/app`, `/login`, `sw.js`. Las solicitudes públicas quedan pendientes hasta una aprobación administrativa y no crean claves automáticamente.

## Variables y secretos

Copiar [`.env.example`](.env.example). **No subir:** `.env`, `.local/`, tokens OAuth, roster real, credenciales de túnel.

## Identidad visual

| Asset | Ruta |
| --- | --- |
| Logo OAuth | [`docs/brand/ccaa-calendar-oauth-logo.svg`](docs/brand/ccaa-calendar-oauth-logo.svg) |
| Banner | [`docs/brand/ccaa-calendar-readme-banner.svg`](docs/brand/ccaa-calendar-readme-banner.svg) |
| UI / icono | [`backend/ccaa_calendar/web/static`](backend/ccaa_calendar/web/static) |

Paleta: naranjo `#ff7a2f` · violeta `#8f5cff` · dorado `#ffd166` · fondo `#160f1f`.

## Castel como base

[`legacy/castel-calendar`](legacy/castel-calendar) aporta ideas de calendario, reservas y avisos. La runtime nueva es Python/FastAPI + SQL.

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
