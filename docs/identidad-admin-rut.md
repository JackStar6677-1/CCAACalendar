# Identidad de administradores por RUT

CCAACalendar puede usar RUT como identificador unico de administradores y directiva, pero no debe depender solo del RUT para iniciar sesion.

## Enfoque recomendado

- RUT: identificador legal/unico para evitar duplicados.
- Correo: canal de contacto y recuperacion.
- Password propia de CCAACalendar o login Google: metodo de acceso.
- Rol: define que puede hacer cada persona.
- Auditoria: todo cambio importante debe registrar actor, fecha y accion.

## Regla de seguridad

El RUT completo es dato personal sensible. No se debe commitear en el repo publico.

## Proteccion de datos en reposo

- El RUT se persiste solo como HMAC con pepper local; no requiere descifrado para autenticar.
- Nombres y correos se cifran con Fernet autenticado mediante `PII_ENCRYPTION_KEYS`.
- La busqueda por correo usa `PII_LOOKUP_PEPPER`, sin consultar por texto claro.
- Tokens OAuth y el roster privado local se cifran con la misma capa de proteccion.
- Las claves viven en `.env` local o secretos del servidor, nunca en Git.

No se usa un algoritmo propio: inventar criptografia reduce la seguridad. La
implementacion usa la biblioteca `cryptography` y admite rotacion de claves.

Para desarrollo local se usa:

```text
.local/admin_roster.json
```

Para documentar estructura se usa:

```text
data/admin_roster.example.json
```

## Estados sugeridos

- `active`: puede entrar y operar.
- `pending`: solicito acceso desde la portada y aun requiere revision.
- `approved`: la directiva aprobo la solicitud; puede crear su clave personal.
- `rejected`: la directiva rechazo la solicitud; no habilita ingreso.
- `needs_rut_confirmation`: el RUT no valida o falta confirmacion.
- `invited`: existe en la directiva pero aun no configuro acceso.
- `disabled`: ya no debe entrar.

## Restablecimiento de contrasenia

Si usamos password propia:

1. La persona ingresa RUT.
2. El sistema busca el RUT normalizado o su hash.
3. Si existe y esta activo, envia un link/codigo al correo asociado.
4. El token se guarda hasheado y vence rapido.
5. La persona crea nueva clave.
6. Se invalida el token y se registra auditoria.

No conviene mostrar si un RUT existe o no. El mensaje publico debe ser neutral:

```text
Si los datos existen y estan activos, enviaremos instrucciones al correo asociado.
```

## Corte implementado en la API

El primer bloque real de autenticacion vive en:

```text
POST /api/auth/activate
POST /api/auth/login
POST /api/auth/access-requests
POST /api/auth/password-reset/request
GET /api/admin/access-requests
PATCH /api/admin/access-requests/{id}
POST /api/admin/access-requests/{id}/notify
```

Flujo actual:

1. El RUT se normaliza y se compara por hash con `.local/admin_roster.json` o con solicitudes aprobadas.
2. Una integrante no habilitada puede enviar una solicitud; nombre, correo y nota se cifran, mientras el RUT solo queda como hash y version enmascarada.
3. La solicitud queda `pending`, se registra en auditoria y se intenta avisar por Gmail a administradoras activas.
4. Una administradora aprueba o rechaza desde el panel. Un correo enviado no aprueba acceso.
5. Solo tras quedar `approved`, `/activate` permite que la integrante cree su propia clave.
6. La clave se guarda con PBKDF2-SHA256 y sal aleatoria, no en claro.
7. `/login` valida RUT + clave y registra auditoria `auth.login`.
8. `/password-reset/request` guarda solo el hash de un token temporal y responde siempre con mensaje neutral.

Proteccion anti abuso del piloto: solicitudes pendientes se deduplican por RUT
y la API limita envios recientes por origen. En un despliegue multi-instancia,
el limite debe moverse a Cloudflare o un almacenamiento compartido.

Pendiente deliberado:

- Persistir sesiones/tokens de acceso con expiracion y revocacion.
- Validar el envio real de avisos y recuperacion tras reconectar Google con `gmail.send`.

## Google

Si usamos login Google, el RUT sigue sirviendo como identificador interno y control de roles. El correo Google conectado debe coincidir con el correo asociado al RUT o quedar aprobado manualmente por un admin.
