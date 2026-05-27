# Checklist privado para habilitar el piloto

Este documento indica que solicitar para operar CCAACalendar. No rellenarlo con
datos personales reales en Git; la informacion recibida se carga en el entorno
privado cifrado del despliegue.

## Integrantes autorizadas

Solicitar por cada integrante que tendra acceso:

- nombre para mostrar en auditoria
- RUT, con autorizacion expresa para usarlo como identificador interno
- rol inicial: administradora, editora o consulta
- correo donde recibira recuperacion de clave y avisos, si opta por recibirlos

No solicitar contrasenas. Cada integrante debe crear su propia clave dentro de
CCAACalendar.

## Cuenta Google oficial

Confirmar:

- quien realizara la autorizacion OAuth desde el panel administrador
- si el calendario operativo corresponde al calendario principal de esa cuenta
- que se permite enviar avisos del sistema desde esa cuenta mediante Gmail API

No solicitar ni compartir la contrasena de Google.

## Contenido del calendario

Solicitar:

- documento anual academico vigente en Word, PDF o Excel
- categorias o nombres que quieren ver en la vista publica
- eventos que deben ser privados del centro y eventos visibles para otros centros
- plazos de recordatorio deseados, por ejemplo 24 horas y 1 hora antes

## Espacios y coordinacion

Para que el sistema deje de ser solo calendario, confirmar:

- auditorios o salas disponibles para reserva
- horarios permitidos y reglas de choque
- quien aprueba o administra reservas
- si DAE u otros centros entraran en una siguiente fase

## Tratamiento de datos

- RUT: se guarda como hash HMAC con pepper privado para identificar la cuenta.
- Nombre y correo: se almacenan cifrados para mostrarlos solo a usuarios autorizados.
- Tokens Google y roster de habilitacion: permanecen cifrados y fuera de Git.
- Claves de acceso: se guardan como hash, nunca recuperables en texto claro.
