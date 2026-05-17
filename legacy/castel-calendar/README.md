# Castel calendar legacy

Esta carpeta conserva la base heredada de Castel que se uso como punto de partida conceptual para CCAACalendar.

No es el producto principal nuevo. CCAACalendar debe crecer desde `backend/ccaa_calendar`, `data`, `docs` y `tests`.

## Que se conserva aqui

- Panel PHP original del calendario Castel.
- PWA y assets heredados del calendario.
- Ejemplos publicos de datos del calendario Castel.
- SQL y reglas operativas que sirven como referencia para reservas, bloqueos, avisos y auditoria.

## Como usarlo

Usar esta carpeta como referencia para migrar ideas, no como runtime principal:

- reservas y bloqueos de espacios
- vista mensual
- avisos y plantillas
- reglas de seguridad del calendario privado
- flujos de aprobacion o cambios

## Limites

- No subir secretos reales.
- No copiar `mail_config.php` ni JSON privados al repo publico.
- No reactivar PHP como dependencia central de CCAACalendar salvo decision explicita.
- Si se extrae logica, llevarla a Python/FastAPI con modelos SQL y tests.
