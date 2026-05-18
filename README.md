# CCAACalendar

![CCAACalendar orbital banner](docs/brand/ccaa-calendar-readme-banner.svg)

**Calendario vivo para centros de estudiantes, coordinación universitaria y reservas de espacios.**

> Carpeta local: `CastelRoomKeeper` · Producto público: **CCAACalendar** · Piloto: **CE Psicología · UDLA Maipú**

## En una mirada

```mermaid
flowchart TB
    U[("Integrantes del centro\nRUT + clave propia")]
    P["CCAACalendar PWA"]
    C[("Calendario oficial\nGoogle del CE")]
    E[("Correos personales\nsolo reciben avisos")]

    U -->|"crean y consultan"| P
    P <-->|"sync eventos"| C
    P -->|"plantillas HTML"| E

    classDef user fill:#4b286f,stroke:#8f5cff,color:#fff7e8
    classDef app fill:#8b4513,stroke:#ff7a2f,color:#fff7e8
    classDef google fill:#1b4d3e,stroke:#81e6c3,color:#fff7e8
    classDef mail fill:#3d2a5c,stroke:#ffd166,color:#fff7e8

    class U user
    class P app
    class C google
    class E mail
```

## Estado del piloto · mayo 2026

```mermaid
flowchart LR
    subgraph ok ["Operativo"]
        direction TB
        O1[API FastAPI]
        O2[Auth RUT]
        O3[PWA + alertas]
        O4[Correos masivos]
        O5[Reservas + choques]
    end

    subgraph wip ["Parcial"]
        direction TB
        W1[Google Calendar]
        W2[Gmail del centro]
        W3[Feriados y categorías]
    end

    subgraph next ["Pendiente"]
        direction TB
        N1[Importar calendario anual]
        N2[Capas multi-centro]
        N3[Mapa visual espacios]
    end

    ok ~~~ wip ~~~ next
```

**Despliegue demo:** túnel Cloudflare → instancia local · Variables en [`.env.example`](.env.example) (sin secretos en el repo).

## Avance vs requerimientos del piloto

```mermaid
flowchart TB
    R1["Web PWA\nsin Gmail personal"]
    R2["Calendario +\nGoogle del centro"]
    R3["Feriados y\ncategorías"]
    R4["Auditorio y\nchoques de horario"]
    R5["Roles y\npermisos"]
    R6["Recordatorios\nweb + correo"]
    R7["Importar Word\nPDF Excel"]
    R8["Varios centros\nen una app"]
    R9["Sync Google\nbidireccional"]
    R10["Anuncios y\nestadísticas"]

    R1 --> R2 --> R4
    R2 --> R9
    R6 --> R2
    R7 --> R3
    R8 --> R4

    classDef cDone fill:#2d6a4f,stroke:#81e6c3,color:#fff7e8
    classDef cWip fill:#7a4a1a,stroke:#ffd166,color:#fff7e8
    classDef cTodo fill:#3d2460,stroke:#8f5cff,color:#fff7e8

    class R1 cDone
    class R2,R3,R4,R5,R6,R9,R10 cWip
    class R7,R8 cTodo
```

Leyenda: verde **hecho** · ámbar **parcial** · violeta **pendiente**. El boceto para demo está listo en identidad, login y calendario; la escala universidad pasa por importación académica y multi-centro.

## Cerrar el piloto Psicología

```mermaid
flowchart LR
    A["1. Reconectar OAuth\nCalendar + Gmail"] --> B["2. Demo completa\nperfil + evento + mail"]
    B --> C["3. Reservas visibles\nchoques claros"]
    C --> D["4. Fechas académicas\nCSV o import"]
    D --> E["5. Segundo centro\nsolo lectura"]

    classDef step fill:#251933,stroke:#ff7a2f,color:#fff7e8
    class A,B,C,D,E step
```

## Problema y solución

```mermaid
flowchart LR
    subgraph antes ["Hoy sin CCAACalendar"]
        direction TB
        X1[Chats mezclados]
        X2[Calendarios personales]
        X3[Choques de auditorio]
        X4[Sin trazabilidad]
    end

    subgraph despues ["Con CCAACalendar"]
        direction TB
        Y1[Calendario oficial del CE]
        Y2[Vista compartida]
        Y3[Reservas con conflicto]
        Y4[Auditoría por RUT]
    end

    antes -->|"piloto Psicología"| despues
```

## Dos cuentas, dos roles

```mermaid
flowchart TB
    subgraph mal ["No queremos"]
        M1["Gmail personal de cada integrante"]
        M2["Consulta ginecológica 15:00"]
        M2 -.->|"aparecería en el calendario del CE"| M1
    end

    subgraph bien ["Sí queremos"]
        B1["Cuenta Google del centro\nuna sola, OAuth en servidor"]
        B2["Integrante con RUT + clave en la PWA"]
        B3["Correo personal solo recibe avisos opt-in"]
        B1 -->|"eventos oficiales"| B2
        B1 -->|"plantillas HTML"| B3
    end

    mal ~~~ bien
```

## Arquitectura técnica

```mermaid
flowchart TB
    subgraph usuarias ["Integrantes del centro"]
        Admin["Administradora\nRUT y clave propia"]
    end

    subgraph ccaa ["CCAACalendar"]
        Web["PWA web / app"]
        API["API FastAPI"]
        Worker["Worker correos"]
    end

    subgraph persistencia ["Persistencia"]
        DB[(SQLite hoy\nPostgreSQL después)]
        Queue[(Cola de correos)]
    end

    subgraph modulos ["Módulos"]
        Audit["Auditoría"]
        Holidays["Feriados Chile"]
        Mail["Plantillas HTML"]
    end

    subgraph gce ["Google del centro"]
        OAuth["OAuth 2.0"]
        Cal["Calendar y Gmail"]
    end

    Legacy["Castel legacy\nsolo referencia"]

    Admin --> Web --> API
    API --> DB
    API --> Queue --> Worker --> Mail
    Worker --> Cal
    API --> Audit
    API --> Holidays
    API --> OAuth --> Cal
    Legacy -.->|ideas UI| API
```

## Flujos principales

### Acceso con RUT

```mermaid
flowchart TB
    Start([Ingresa RUT]) --> Lookup{¿En roster?}
    Lookup -->|No| Block([Acceso bloqueado])
    Lookup -->|Sí, primera vez| Activate[Crear clave y correo]
    Lookup -->|Sí, activa| Login[Iniciar sesión]
    Activate --> App([Panel CCAACalendar])
    Login --> App
```

### Crear evento, Google y correos

```mermaid
sequenceDiagram
    autonumber
    participant I as Integrante
    participant P as CCAACalendar
    participant D as Base de datos
    participant Q as Cola correos
    participant G as Google del CE

    I->>P: Crear evento
    P->>D: Guardar
    opt Sync marcado
        P->>G: Publicar en Calendar
    end
    opt Avisar integrantes
        P->>Q: Confirmación + recordatorios
        Q->>G: Enviar HTML
        G-->>I: Correo personal
    end
    opt Alertas navegador
        P-->>I: Notificación local 30 min
    end
```

### Reserva de auditorio

```mermaid
flowchart TB
    A[Integrante pide horario] --> B{¿Solapa otra reserva?}
    B -->|No| C[Reserva confirmada\nvisible en calendario]
    B -->|Sí| D[Rechazo con mensaje\nelige otro bloque]
    C --> E[Otros centros ven ocupación]
```

### Conectar Google del centro (una vez)

```mermaid
flowchart LR
    S[Directiva en servidor] --> L["/api/integrations/google/login"]
    L --> O[Consentimiento Google\nCalendar + Gmail]
    O --> T[Token guardado\n.local seguro]
    T --> U[Listo: sync y correos]

    classDef step fill:#251933,stroke:#81e6c3,color:#fff7e8
    class S,L,O,T,U step
```

## Visión multi-centro

```mermaid
flowchart TB
    App["CCAACalendar\nuna sola instancia"]

    App --> P["Capa Psicología\npiloto activo"]
    App --> K["Capa Kinesiología\nfuturo"]
    App --> E["Capa Enfermería\nfuturo"]
    App --> V["Capa Veterinaria\nfuturo"]
    App --> D["Capa DAE\nfuturo"]

    P --> S["Auditorio y salas\nvista compartida"]

    classDef live fill:#7a4a1a,stroke:#ff7a2f,color:#fff7e8
    classDef future fill:#3d2460,stroke:#8f5cff,color:#fff7e8
    class P live
    class K,E,V,D future
```

## Roadmap

```mermaid
flowchart LR
    subgraph done ["Hecho"]
        direction TB
        D1[Auth RUT y PWA]
        D2[Calendario y reservas]
        D3[Correos HTML + cola]
        D4[Google OAuth sync]
    end

    subgraph soon ["Siguiente"]
        direction TB
        S1[Importación académica]
        S2[Capas y filtros UI]
        S3[Mapa de espacios]
        S4[Segundo centro prueba]
    end

    subgraph later ["Después"]
        direction TB
        L1[PostgreSQL en VPS]
        L2[Multi-org completa]
        L3[Word PDF Excel]
    end

    D4 --> S1
    S4 --> L1

    classDef cDone fill:#2d6a4f,stroke:#81e6c3,color:#fff7e8
    classDef cSoon fill:#7a4a1a,stroke:#ffd166,color:#fff7e8
    classDef cLater fill:#3d2460,stroke:#8f5cff,color:#fff7e8
    class D1,D2,D3,D4 cDone
    class S1,S2,S3,S4 cSoon
    class L1,L2,L3 cLater
```

## Stack

```mermaid
flowchart LR
    FE["HTML CSS JS\nPWA"] --> BE["Python\nFastAPI"]
    BE --> ORM["SQLAlchemy\nAlembic"]
    ORM --> DB[(SQLite / PostgreSQL)]
    BE --> G["Google APIs"]
    BE --> T["Pytest + Ruff"]

    classDef layer fill:#251933,stroke:#8f5cff,color:#fff7e8
    class FE,BE,ORM,DB,G,T layer
```

## Estructura del repo

```text
backend/ccaa_calendar/          Producto FastAPI
  api/                          REST
  domain/                       RUT, feriados, roster
  integrations/                 OAuth, correos HTML
  workers/                      Cola de correos
  web/static/                   PWA
docs/                           Producto y marca
legacy/castel-calendar/         Referencia UI
migrations/                     Alembic
tests/
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
flowchart TB
    GC[Proyecto Google Cloud] --> API1[Calendar API]
    GC --> API2[Gmail API]
    GC --> OAuth[Pantalla OAuth + cliente Web]
    OAuth --> RU[Redirect URI producción y local]
    OAuth --> TU[Usuarios de prueba\ncorreo oficial del CE]
    TU --> OK["Login /api/integrations/google/login"]

    classDef cloud fill:#1a3a52,stroke:#4a9eff,color:#fff7e8
    class GC,API1,API2,OAuth,RU,TU,OK cloud
```

Redirect de producción (ejemplo):

```text
https://TU-DOMINIO/api/integrations/google/callback
```

Scopes: `calendar.events` · `gmail.send`

## API y rutas web

```mermaid
flowchart LR
    subgraph auth ["Auth"]
        A1[lookup]
        A2[activate / login]
        A3[me / notifications]
    end
    subgraph core ["Calendario"]
        C1[events]
        C2[holidays]
        C3[spaces / reservations]
    end
    subgraph google ["Google"]
        G1[login / callback]
        G2[sync / events]
    end
    subgraph admin ["Admin"]
        D1[users / audit]
    end

    auth --> core
    core --> google
```

Detalle en código: prefijo `/api` · PWA en `/`, `/app`, `/login`, `sw.js`.

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

## Licencia

Ver [`LICENSE`](LICENSE).
