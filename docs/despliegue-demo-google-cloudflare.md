# Demo publica: Google Calendar y Cloudflare Tunnel

Este documento describe la demo de CCAACalendar en `https://ccaa.drakescraft.cl`.
Versiona estructura y comandos, pero nunca credenciales.

## Modelo de acceso

- Las integrantes ingresan con RUT y clave interna.
- Una administradora conecta la cuenta Google oficial una sola vez.
- La autorización del panel solicita `calendar.events` y `gmail.send`.
- `gmail.send` permite enviar avisos y recuperaciones desde la cuenta oficial, sin leer correos.

## Google Cloud Console

En el proyecto OAuth de CCAACalendar:

1. Habilitar **Google Calendar API**.
2. Habilitar **Gmail API** para avisos y recuperaciones de clave.
3. Configurar el cliente OAuth como **Aplicación web**.
4. Agregar estos URI de redirección autorizados:

```text
http://localhost:8000/api/integrations/google/callback
https://ccaa.drakescraft.cl/api/integrations/google/callback
```

5. Agregar este origen JavaScript autorizado:

```text
https://ccaa.drakescraft.cl
```

6. Mientras la pantalla de consentimiento siga en prueba, agregar la cuenta oficial
   del centro como usuario de prueba.

Scopes que solicita la app:

```text
https://www.googleapis.com/auth/calendar.events
https://www.googleapis.com/auth/gmail.send
```

## Archivos privados locales

Los siguientes archivos deben existir localmente y están excluidos de Git:

```text
.env
.local/google_oauth_client_secret.json
.local/google_token.json
.local/admin_roster.json
%USERPROFILE%\.cloudflared\ccaa-calendar.yml
%USERPROFILE%\.cloudflared\<tunnel-id>.json
```

`PII_ENCRYPTION_KEYS`, `PII_LOOKUP_PEPPER` y `ADMIN_IDENTITY_PEPPER` se guardan
solo en `.env` o en el gestor de secretos del servidor. Con clave configurada,
CCAACalendar cifra nombres, correos, roster privado y tokens OAuth locales. Los
RUT se localizan mediante hash con pepper y no se guardan completos en la base.

Usar [`.env.example`](../.env.example) para desarrollo local o
[`.env.production.example`](../.env.production.example) para un servidor.

## Cloudflare Tunnel para la laptop

Crear el túnel una sola vez:

```powershell
cloudflared tunnel login
cloudflared tunnel create ccaa-calendar
cloudflared tunnel route dns ccaa-calendar ccaa.drakescraft.cl
```

Copiar [`deploy/cloudflare/config.example.yml`](../deploy/cloudflare/config.example.yml)
a `%USERPROFILE%\.cloudflared\ccaa-calendar.yml` y completar localmente el UUID y la
ruta del archivo de credenciales generado por Cloudflare.

Iniciar la API y el túnel sin ventanas visibles:

```powershell
.\scripts\start-public-demo.ps1
```

Instalar el arranque al iniciar sesión de Windows:

```powershell
.\scripts\install-public-demo-task.ps1
```

## Verificación segura

Estas comprobaciones no imprimen tokens:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/api/health
Invoke-RestMethod http://127.0.0.1:8000/api/integrations/google/status
Invoke-RestMethod https://ccaa.drakescraft.cl/api/health
```

Resultado esperado antes de vincular Google: OAuth configurado, pero token ausente.
Resultado esperado tras conectar desde el panel administrador: token presente,
`gmail_authorized=true`, eventos sincronizables y avisos por correo habilitados.

## No subir a Git

- `.env` y `.local/`
- JSON del cliente OAuth o tokens Google
- JSON/PEM de Cloudflare Tunnel
- RUT, correos reales o roster operativo
