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
POST /api/auth/password-reset/request
```

Flujo actual:

1. El RUT se normaliza y se compara por hash con `.local/admin_roster.json`.
2. Si el roster local marca al admin como `active` y el RUT valida, `/activate` crea o completa el usuario.
3. La clave se guarda con PBKDF2-SHA256 y sal aleatoria, no en claro.
4. `/login` valida RUT + clave y registra auditoria `auth.login`.
5. `/password-reset/request` guarda solo el hash de un token temporal y responde siempre con mensaje neutral.

Pendiente deliberado:

- Enviar el token de recuperacion por correo real.
- Persistir sesiones/tokens de acceso con expiracion y revocacion.
- Crear pantalla privada de activacion para administradores invitados.
- Cifrar tokens OAuth de Google antes de guardar integraciones de produccion.

## Google

Si usamos login Google, el RUT sigue sirviendo como identificador interno y control de roles. El correo Google conectado debe coincidir con el correo asociado al RUT o quedar aprobado manualmente por un admin.
