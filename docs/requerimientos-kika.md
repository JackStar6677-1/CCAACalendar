# Requerimientos reales de Kika

Fuente local: `C:\Users\Jack\Downloads\Proyecto para kika.txt`.

Este resumen existe para mantener el norte del producto sin versionar conversaciones completas ni datos privados.

## Producto esperado

Kika quiere una web/PWA para coordinacion universitaria que parta por el centro de estudiantes de Psicologia y pueda escalar a otros centros.

La app debe funcionar como calendario vivo para:

- fechas academicas importantes
- feriados
- inicio y cierre de semestre
- vacaciones
- semanas de catedras o pruebas
- reuniones de centros
- eventos de DAE u otras unidades
- reserva y uso de espacios como auditorios o salas

## Regla central de identidad

No se deben mezclar calendarios personales con el calendario institucional.

El sistema debe operar con cuentas autorizadas de centro o unidad, no con cualquier Gmail personal de estudiantes.

Ejemplos de centros o capas:

- Psicologia
- Kinesiologia
- Enfermeria
- Veterinaria
- DAE

## Google Calendar

Google Calendar es una integracion principal porque ya se usa en la operacion real.

Flujo deseado:

1. Un centro crea o actualiza un evento en su calendario autorizado.
2. Kika Orbit lo refleja en la web.
3. Si Kika Orbit crea un evento, debe poder sincronizarlo hacia Google Calendar.
4. La web debe evitar leer o publicar eventos personales que no pertenezcan al calendario autorizado.

## Coordinacion de espacios

El ejemplo clave es el auditorio:

- Si Psicologia reserva el auditorio el 26 de mayo a las 14:00, otros centros deben verlo.
- Otros centros pueden elegir otro bloque sin choque.
- DAE o la universidad tambien debe poder publicar ocupaciones de espacios.

## Funciones priorizadas

1. Calendario por capas o centros.
2. Reserva visual de espacios.
3. Roles y permisos.
4. Categorias de eventos.
5. Importacion de calendario academico desde Word/PDF/Excel.
6. Vista general de coordinacion.
7. Diseno responsive tipo app.
8. Anuncios o avisos.
9. Sincronizacion bidireccional con Google Calendar.
10. Historial, auditoria y estadisticas.

## Decisiones de producto

- Partir como web/PWA, no app nativa.
- Usar Castel como referencia de calendario, no como arquitectura final.
- Mantener una base Python/FastAPI + SQL.
- Preparar PostgreSQL y migraciones versionadas.
- Gmail queda para avisos futuros; Calendar va primero.
