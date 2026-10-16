# Recorrido de demostración y preparación del grupo

La duración siguiente es una sugerencia de 8 a 12 minutos; la conferencia no fijó una duración. Antes de exponer, cada integrante debe ejecutar y comprender los pasos, personalizar los comentarios del grupo y tener abierta la instancia PHP real.

1. **Objetivo y framework.** Mostrar el panel y explicar que se administran torneos infantiles. Indicar Phalcon 5.22.1 y que es una extensión de PHP. Mostrar `/api/health` desde la instancia real.
2. **Relación maestro-detalle.** Abrir equipos y luego sus jugadores. Explicar que `teams.id` se relaciona con `players.team_id` y que un jugador tiene un equipo.
3. **Ingreso de datos y validación.** Crear un jugador con nombre, apellido, nacimiento, dorsal y foto JPG/PNG. Escribir `Juan#` en el nombre o letras en el dorsal: el campo muestra el error y su ayuda. Consultar su ficha.
4. **Torneo de clasificación.** *Torneos → Nuevo torneo*, tipo clasificación. Inscribir equipos, cerrar con sorteo y generar el cuadro. Intentar programar un partido en una fecha pasada (se rechaza) y luego programarlo con cancha y árbitro.
5. **Hoja del partido.** Iniciar el partido, agregar goles por equipo (incluido un autogol) y ver que el marcador se calcula solo. Finalizar con empate para mostrar tiempo extra y penales; confirmar y ver que el ganador avanza en el cuadro.
6. **Torneo por puntos.** Crear una liga con 3 o más equipos: el asistente indica cuántas jornadas habrá. Revisar la vista previa del calendario (fechas editables y quién descansa) y generarlo. Confirmar un resultado y mostrar la **Tabla** con sus desempates. Aplazar un partido, agregar una jornada extraordinaria y moverlo allí.
7. **Incidencias.** Registrar una roja con fecha de suspensión e intentar que ese jugador anote ese día: debe rechazarse.
8. **Histórico y reportes.** En *Histórico* mostrar las jornadas y goles anteriores. Generar el listado del árbitro (aparecen los suspendidos), filtrar incidencias y guardar los goleadores como PDF desde impresión.
9. **Usuarios y permisos.** Como administrador crear un árbitro (contraseña temporal). Entrar con esa cuenta: debe cambiar la contraseña, puede consultar y registrar solo sus partidos. Desactivar la cuenta cierra su sesión.
10. **Experiencia y código.** Mostrar un controlador, un servicio (`TournamentService`, `League/StandingsCalculator`) y un modelo. Mencionar las pruebas automáticas, el modo oscuro y la vista en teléfono. Cerrar con la URL PHP y las recomendaciones.

## Preguntas de práctica

| Pregunta | Respuesta que debe comprender el grupo |
| --- | --- |
| ¿Por qué Composer no instala por sí solo Phalcon? | Phalcon se carga como extensión del intérprete PHP. Composer valida su existencia y administra el proyecto. |
| ¿Dónde entra una solicitud? | Nginx envía las rutas a `public/index.php`; el router y el dispatcher de Phalcon seleccionan el controlador. |
| ¿Para qué sirve DI? | Los controladores y servicios reutilizan la conexión, la sesión, Security, ACL, el reloj y los servicios de negocio sin construirlos de nuevo. |
| ¿En qué se diferencian los dos tipos de torneo? | Clasificación: cuadro por rondas, sin empates (tiempo extra y penales), gana la final. Puntos: jornadas todos contra todos, empates válidos, gana el primero de la tabla. Ambos implementan `Format/TournamentFormat`. |
| ¿Cómo se calcula el número de jornadas? | N − 1 por vuelta con N equipos par, N si es impar (uno descansa); se duplica con ida y vuelta. Se pueden agregar jornadas extraordinarias. |
| ¿Cómo se desempata la tabla? | Puntos, diferencia de goles, goles a favor, enfrentamiento directo, juego limpio y, al final, orden alfabético marcado como empate técnico. |
| ¿Por qué el marcador no se escribe a mano? | Los goles registrados son la única fuente; el marcador y los goleadores se calculan a partir de ellos, así no pueden contradecirse. |
| ¿Dónde están las reglas de suspensión? | En `SuspensionService`: se busca una roja cuya fecha de suspensión coincide con la fecha del partido o jornada. |
| ¿Cómo se evita programar en el pasado? | El servidor define "hoy" con un reloj inyectable y rechaza fechas anteriores; el formulario usa la misma fecha para limitar el calendario. |
| ¿Cómo se validan los caracteres? | Las reglas están en `config/validation-rules.json`; el servidor y el navegador usan las mismas expresiones y se prueban con los mismos casos. |
| ¿Qué es ORM? | Los modelos representan las tablas y sus relaciones; `find`, `save` y `delete` operan sobre la base mediante Phalcon. |
| ¿Dónde se usa Volt? | En `app/views/index/index.volt`, registrada como motor de vistas. La interacción posterior usa módulos JavaScript y la API PHP. |
| ¿Cómo se valida una imagen? | Se comprueba tamaño, MIME, dimensiones y decodificación; se genera un JPEG con nombre aleatorio. |
| ¿Cómo se protegen las contraseñas y las cuentas? | `Phalcon\Encryption\Security` genera y verifica hashes. Las cuentas se desactivan en vez de borrarse y el usuario se relee en cada solicitud. |
| ¿Para qué sirve CSRF? | Para que una escritura necesite el token de la sesión; se envía en `X-CSRF-Token`. |
| ¿Cómo se probó? | PHPUnit (reglas y migraciones), pruebas de interfaz con jsdom, Playwright en un navegador real con revisión de accesibilidad, y pruebas HTTP sobre MySQL. |

## Comentarios del grupo: borrador para adaptar

El patrón MVC ayudó a ubicar la función de cada archivo: controladores para solicitudes, modelos para datos y servicios para las reglas. Separar cada formato de torneo en su propio servicio permitió agregar la liga sin tocar el cuadro de eliminación. La parte que requiere mayor cuidado es preparar PHP con la extensión Phalcon; Docker concentra ese entorno y facilita repetir la instalación.

Se recomienda validar tanto en el formulario como en el servidor con las mismas reglas, congelar la fecha en las pruebas para que sean repetibles y preparar los reportes antes de la exposición. El grupo debe ajustar este borrador a su experiencia real y no afirmar pruebas o publicaciones que no haya realizado.
