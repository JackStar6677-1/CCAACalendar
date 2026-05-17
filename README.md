# Kika Orbit

Plataforma web tipo SaaS para centros de estudiantes y coordinación universitaria.

La idea central es convertir un calendario académico anual en un sistema vivo que permita cargar actividades de la universidad, sincronizar eventos institucionales y coordinar el uso de espacios sin mezclar información personal con información oficial.

## Nombre y marca

`Kika Orbit` es el nombre de trabajo del proyecto.

La identidad visual y el nombre público deben poder cambiarse sin rehacer la base técnica. La recomendación es centralizar eso en una sola capa de configuración cuando empecemos el desarrollo real:

- nombre público
- nombre corto
- logo
- colores
- dominio
- textos visibles

Así, si Kika quiere cambiar la marca, venderlo a otra universidad o sacar una edición nueva, solo se ajusta la capa de branding y no todo el proyecto.

## Objetivo

Construir una web privada y multiusuario donde cada centro de estudiantes o unidad institucional pueda:

- ver su calendario
- crear y editar eventos autorizados
- sincronizar actividades con Google Calendar
- coordinar reservas de espacios como auditorios o salas
- evitar que entren eventos personales de cuentas ajenas
- compartir una vista común con otros centros y con la universidad

El foco no es “una app más”, sino una herramienta de coordinación real para la universidad.

## Decisión técnica recomendada

### Recomendación principal

**Backend:** Python  
**Frontend:** web responsive con TypeScript/React o server-rendered templates según la primera fase  
**Base de datos:** PostgreSQL  
**Tareas en segundo plano:** workers Python + cron/celery/rq  
**Integración externa:** Google Calendar API vía OAuth 2.0

### Por qué Python

- es fuerte para leer y transformar documentos Word, PDF y Excel
- tiene buena compatibilidad con Google APIs
- facilita tareas de automatización y procesamiento de calendarios
- permite crecer a una arquitectura SaaS limpia sin inventar demasiadas piezas

### Por qué no partir con una ensalada de lenguajes

Se puede mezclar tecnología, pero solo donde haya una razón clara.  
Para este proyecto, empezar con PHP + Python + Java + algo más al mismo tiempo solo agregaría:

- más complejidad
- más despliegues
- más puntos de falla
- más tiempo de mantenimiento

La recomendación es:

- una sola base principal
- una sola base de datos
- una sola API
- una sola forma de autenticación

## Stack propuesto por fases

### Fase 1: MVP web

- Web responsive
- Login institucional por correo
- Calendario por centro
- Carga manual de eventos
- Roles básicos
- Base de datos PostgreSQL
- Vista mensual y agenda

### Fase 2: Importación de calendario académico

- Subir documento Word del calendario anual
- Extraer fechas y eventos
- Detectar:
  - inicio de semestre
  - fin de semestre
  - vacaciones
  - feriados
  - semanas de cátedras
  - semanas especiales
  - eventos institucionales
- Permitir revisión antes de publicar

### Fase 3: Integración con Google Calendar

- Sincronización con calendarios conectados por OAuth
- Importación y exportación de eventos
- OAuth 2.0
- Asociación por centro de estudiantes o unidad
- Evitar mezclar calendarios personales no seleccionados

Para partir no se requiere dominio institucional ni Google Workspace. La primera integración puede usar la cuenta Google personal autorizada por Kika, creando eventos e invitaciones desde Calendar. La estrategia completa está en [`docs/estrategia-google-sin-dominio.md`](docs/estrategia-google-sin-dominio.md).

### Fase 4: Multi-centro / multiunidad

- Psicología
- Veterinaria
- Kinesiología
- Enfermería
- DAE u otros organismos

Cada uno con su propio espacio lógico, colores, permisos y calendario.

### Fase 5: SaaS completo

- Panel administrador central
- Gestión de organizaciones
- Gestión de usuarios
- Estadísticas
- Historial
- Auditoría
- Plantillas de permisos
- Reserva y coordinación de espacios

## Requerimientos funcionales del proyecto

### 1. Calendario anual dinámico

El sistema debe permitir que el calendario académico cambie de año sin rehacer toda la aplicación.

Requisitos:

- cargar calendario anual nuevo
- reutilizar estructura base
- actualizar fechas automáticamente
- mantener la información histórica

### 2. Carga desde documento Word

El documento base de la universidad será el punto de entrada principal.

Debe poder:

- subir Word como fuente inicial
- identificar fechas y eventos
- clasificar los elementos detectados
- revisar y confirmar antes de publicar

### 3. Aislamiento institucional

No se deben mezclar datos personales con los institucionales.

El sistema debe:

- limitar acceso a cuentas autorizadas
- usar correos institucionales o de centro
- impedir que eventos personales aparezcan en el calendario común
- separar eventos privados de eventos oficiales

### 4. Calendarios por centro

Cada centro debe tener su propio calendario lógico.

Ejemplos:

- Psicología
- Veterinaria
- Kinesiología
- Enfermería

Cada calendario debe poder:

- verse solo por autorizados
- compartir eventos públicos de coordinación
- ocultar detalles sensibles si corresponde
- tener color propio

### 5. Vista común para coordinación

Debe existir una vista central donde se vea:

- qué centro tiene evento
- qué espacio está ocupado
- qué día se usa un auditorio
- qué evento choca con otro

Esto sirve para que centros y universidad coordinen mejor sus actividades.

### 6. Reserva de espacios

El proyecto no es solo calendario. También es coordinación de espacios.

Debe incluir:

- reservas de auditorio
- reservas de salas
- bloques horarios
- disponibilidad visual
- advertencia de choques

### 7. Permisos y roles

Roles mínimos:

- superadmin
- admin institucional
- coordinación
- centro de estudiantes
- solo lectura

Cada rol debe tener límites claros para:

- crear eventos
- editar eventos
- aprobar cambios
- ver todo
- exportar
- administrar usuarios

### 8. Google Calendar

La integración con Google Calendar es parte del proyecto.

Debe contemplar:

- OAuth 2.0
- credenciales seguras
- sincronización de eventos
- asociación con calendarios institucionales
- exportación/importación

### 9. Notificaciones

El sistema debe poder avisar por:

- correo
- notificación web
- recordatorios internos

La gestión de correos hereda la idea de Castel (plantillas y avisos), pero no el acoplamiento al webmail del hosting. En este proyecto la prioridad es:

- primero invitaciones por Google Calendar
- después Gmail API para avisos puntuales
- SMTP solo si aparece un dominio o proveedor propio

Más adelante podría extenderse a otros canales, pero no es requisito base.

### 10. Historial y auditoría

Debe quedar registro de:

- quién creó el evento
- quién lo editó
- quién aprobó el cambio
- cuándo se publicó
- qué cambió exactamente

### 11. Estadísticas

Deseable para fases posteriores:

- uso de espacios
- cantidad de eventos por centro
- eventos por mes
- espacios más ocupados

## Requerimientos no funcionales

- Debe verse bien en celular y escritorio
- Debe cargar rápido
- Debe funcionar con datos reales y con datos de prueba
- Debe tener una arquitectura fácil de mantener
- Debe ser segura con credenciales y tokens
- Debe ser desplegable de forma simple mientras se desarrolla

## Qué no debe hacer el MVP

- No debe mezclar eventos personales con institucionales
- No debe depender de una app nativa desde el inicio
- No debe obligar a instalar nada para usarlo
- No debe requerir varios lenguajes sin necesidad
- No debe nacer con demasiadas integraciones al mismo tiempo

## Ruta de desarrollo recomendada

### Paso 1

Diseñar la base funcional:

- autenticación
- base de datos
- estructura de calendarios
- roles
- vista web

### Paso 2

Implementar el calendario anual dinámico:

- importación manual
- interpretación de fechas
- publicación del calendario

### Paso 3

Agregar la coordinación de centros:

- calendario por centro
- vista común
- reservas de espacios

### Paso 4

Integrar Google Calendar:

- login OAuth
- sincronización
- publicación de eventos

### Paso 5

Escalar a SaaS:

- organizaciones múltiples
- métricas
- auditoría completa
- administración central

## Diseño sugerido del producto

- Web moderna
- Responsive
- Colores por centro
- Interfaz clara para celular
- Panel de administración separado
- Calendario principal muy visible

## Datos y base

La base de datos debe ser SQL desde el inicio.

Recomendación:

- PostgreSQL
- migraciones versionadas
- tablas para usuarios, centros, calendarios, eventos, permisos, auditoría y sincronización

## Desarrollo local

El backend inicial está en `backend/kika_orbit` y usa FastAPI + SQLAlchemy.
La primera vista web vive en `backend/kika_orbit/web/static` y se sirve desde el mismo proceso para mantener el desarrollo simple.

Estructura actual del repo:

- `backend/kika_orbit`: producto principal Kika Orbit en FastAPI.
- `backend/kika_orbit/web/static`: PWA y UI inicial.
- `data`: ejemplos publicos propios de Kika, como `admin_roster.example.json`.
- `docs`: decisiones de producto, seguridad, Google, RUT y requerimientos.
- `legacy/castel-calendar`: calendario Castel preservado como base de referencia, no runtime principal.
- `migrations`: migraciones Alembic para preparar PostgreSQL y despliegue serio.
- `tests`: pruebas de API, dominio y seguridad base.

Instalar dependencias:

```powershell
uv sync
```

Levantar API local:

```powershell
uv run uvicorn kika_orbit.main:app --app-dir backend --reload
```

Verificar salud:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/api/health
```

Abrir la interfaz:

```text
http://127.0.0.1:8000/
```

Rutas web disponibles en este corte:

- `/`: login demo y entrada al tablero
- `/login`: alias para el login
- `/app`: alias preparado para el tablero
- `/manifest.webmanifest`: manifiesto PWA
- `/sw.js`: service worker base
- `/offline`: pantalla offline

Endpoints de autenticacion disponibles en este corte:

- `POST /api/auth/activate`: activa un administrador desde `.local/admin_roster.json`.
- `POST /api/auth/login`: valida RUT + clave y registra auditoria.
- `POST /api/auth/password-reset/request`: inicia recuperacion con respuesta neutral.

Ejecutar tests y lint:

```powershell
uv run ruff check .
uv run pytest
```

Migraciones:

```powershell
uv run alembic upgrade head
uv run alembic revision --autogenerate -m "describe change"
```

Por defecto se usa SQLite en `.local/kika_orbit.db` para desarrollo rapido. La base objetivo del producto sigue siendo PostgreSQL; se configura cambiando `DATABASE_URL` en `.env`.

## Metodologia de trabajo

- cambios pequeños y verificables
- tests antes de subir
- SQL como fuente real de datos
- secretos fuera de git
- marca configurable desde una capa central
- integraciones externas por fases, no todas al mismo tiempo

## Integraciones que podrían necesitarse

- Google Calendar API
- Gmail API para avisos opcionales
- SMTP solo como proveedor alternativo futuro
- almacenamiento de archivos para documentos Word
- tareas programadas

## Identidad de administradores

El acceso administrativo se modela por RUT unico + correo asociado + rol. El RUT completo no debe subirse al repo publico; para desarrollo local va en `.local/admin_roster.json`, mientras que el ejemplo publico esta en `data/admin_roster.example.json`. Mas detalle en [`docs/identidad-admin-rut.md`](docs/identidad-admin-rut.md).

## Base Castel preservada

El calendario de Castel se conserva en [`legacy/castel-calendar`](legacy/castel-calendar) porque aporta logica util para calendario mensual, reservas, avisos y bloqueos. La regla de trabajo es migrar ideas hacia Python/FastAPI con SQL y tests; no mezclar el PHP heredado como runtime principal de Kika Orbit salvo instruccion explicita.

Los requerimientos reales resumidos desde el texto de Kika estan en [`docs/requerimientos-kika.md`](docs/requerimientos-kika.md).

## Qué podrías pedirme después

- Diseñar la base de datos
- Crear el árbol de carpetas del proyecto
- Empezar el backend
- Definir el flujo de importación de Word
- Hacer la primera pantalla web
- Preparar la integración con Google Calendar

## Resumen de decisión

La mejor ruta para Kika es:

**web SaaS + Python + PostgreSQL + integración con Google Calendar + PWA progresiva**

Eso deja el proyecto ordenado, escalable y con menos riesgo que partir con una mezcla de tecnologías sin necesidad.
