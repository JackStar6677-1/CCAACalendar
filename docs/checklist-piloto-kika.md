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

### Estado del piloto Psicologia

Kika ya envio una primera nomina privada de integrantes para el piloto. Esa
nomina no debe copiarse en este documento ni en GitHub: se carga solamente en
el entorno local o de produccion mediante el roster protegido.

El criterio inicial queda asi:

- presidencia/tesoreria: rol administrador
- vicepresidencia/secretaria, redes/marketing, voceria y editoras: rol editor
- todas crean su propia clave al entrar por primera vez con RUT
- los correos personales se usan para recuperacion de clave y avisos, si la
  integrante mantiene activados los correos en su perfil

## Cuenta Google oficial

Confirmar:

- quien realizara la autorizacion OAuth desde el panel administrador
- si el calendario operativo corresponde al calendario principal de esa cuenta
- que se permite enviar avisos del sistema desde esa cuenta mediante Gmail API

No solicitar ni compartir la contrasena de Google.

Estado actual: la presidencia del centro queda como responsable de autorizar
la conexion OAuth de la cuenta oficial. Se autorizo el uso de Gmail API para
correos de aviso y recordatorios desde esa cuenta, siempre sin pedir ni guardar
la contrasena de Google.

## Contenido del calendario

Solicitar:

- documento anual academico vigente en Word, PDF o Excel
- categorias o nombres que quieren ver en la vista publica
- eventos que deben ser privados del centro y eventos visibles para otros centros
- plazos de recordatorio deseados, por ejemplo 24 horas y 1 hora antes

Pendiente principal: recibir el calendario academico oficial en PDF, Word o
Excel para importarlo con previsualizacion y edicion previa.

## Espacios y coordinacion

Para que el sistema deje de ser solo calendario, confirmar:

- auditorios o salas disponibles para reserva
- horarios permitidos y reglas de choque
- quien aprueba o administra reservas
- si DAE u otros centros entraran en una siguiente fase

Decision recibida para el piloto: CCAACalendar no aprueba reservas frente a
DAE. La plataforma registra y muestra actividades o espacios que ya fueron
coordinados externamente con la universidad. Por eso el campo de espacio debe
seguir siendo flexible/manual y no depender solo de una lista cerrada.

Ejemplos esperados:

- auditorios
- salas
- pasillos
- quinchos
- espacios abiertos o techados

## Tratamiento de datos

- RUT: se guarda como hash HMAC con pepper privado para identificar la cuenta.
- Nombre y correo: se almacenan cifrados para mostrarlos solo a usuarios autorizados.
- Tokens Google y roster de habilitacion: permanecen cifrados y fuera de Git.
- Claves de acceso: se guardan como hash, nunca recuperables en texto claro.
