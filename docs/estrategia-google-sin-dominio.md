# Estrategia Google sin dominio propio

CCAACalendar no necesita partir con dominio institucional ni Google Workspace.

La ruta recomendada para el piloto de Psicologia es usar OAuth con una sola cuenta Google del centro, configurada fuera del repo publico mediante `.env` o secretos del servidor. Esa cuenta representa el calendario oficial del Centro de Estudiantes de Psicologia. Las administradoras no entran a CCAACalendar con Google: cada una usa autenticacion interna por RUT y clave, con roles y auditoria propios.

## Decision inicial

- Usar Google Calendar como primera integracion.
- Crear eventos desde la cuenta oficial del centro autorizada por OAuth.
- Agregar asistentes para que Google envie invitaciones del calendario.
- No mezclar eventos privados: CCAACalendar solo lee/escribe el calendario autorizado del centro.
- Mantener Google OAuth separado del login interno de administradoras.
- No usar SMTP webmail como dependencia principal porque este proyecto no parte con dominio propio.

## Lo que se hereda de Castel

Castel tenia una idea util: plantillas de avisos, destinatarios autorizados, mensajes de aprobacion/rechazo y pruebas de envio.

En CCAACalendar se conserva el concepto, pero cambia el proveedor:

- Castel: SMTP/webmail del hosting.
- CCAACalendar: Google OAuth sobre la cuenta calendario del centro.

Asi evitamos que CCAA tenga que crear un correo institucional solo para desarrollar el producto.

## Fase 1: Invitaciones por Calendar

Esta es la forma mas limpia para empezar.

Flujo:

1. Una administradora autorizada conecta el Google del centro.
2. La app guarda un token OAuth cifrado.
3. Al crear un evento, la app lo inserta en Google Calendar.
4. Si hay asistentes, se agregan en el campo `attendees`.
5. Google envia la invitacion desde la cuenta organizadora.

Ventajas:

- No requiere dominio.
- No requiere Google Workspace.
- No requiere manejar SMTP.
- La invitacion queda viva en Google Calendar.
- Los cambios al evento se propagan a asistentes.

Limitacion:

- La invitacion sale desde la cuenta Google conectada del centro, no desde la cuenta personal de cada administradora.

## Fase 2: Gmail API para avisos

Solo si hacen falta correos mas personalizados:

- recordatorios internos
- aprobaciones/rechazos
- avisos de cambio de sala
- resumen semanal

La app puede pedir permiso Gmail limitado para enviar mensajes desde la cuenta autorizada.

Regla de producto:

- primero Calendar
- Gmail solo cuando Calendar no alcance
- nunca pedir permiso para leer todo el correo si no es estrictamente necesario

## Fase 3: Proveedor intercambiable

Si mas adelante CCAA, una universidad o un centro compra dominio, no se rehace el producto. Se cambia el conector.

Proveedores posibles:

- Google Calendar personal
- Google Calendar de centro de estudiantes
- Google Workspace institucional
- Microsoft 365
- SMTP propio
- proveedor transaccional tipo Resend, Mailgun o Brevo

La app debe guardar la configuracion por organizacion/centro, no cableada al codigo.

## Variables necesarias

Para desarrollo local:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/api/integrations/google/callback
GOOGLE_CALENDAR_SCOPES=https://www.googleapis.com/auth/calendar.events
GOOGLE_GMAIL_SCOPES=https://www.googleapis.com/auth/gmail.send
GOOGLE_CENTER_ACCOUNT_EMAIL=
GOOGLE_CALENDAR_ID=primary
```

## Pendiente tecnico

- Persistir cada conexion OAuth en la tabla de cuentas conectadas por centro.
- Guardar tokens cifrados.
- Crear pantalla de conexion Google.
- Implementar callback OAuth.
- Crear servicio de sincronizacion Calendar.
- Crear cola de notificaciones.
- Definir plantillas de correo heredadas conceptualmente de Castel.
