# Reglas de los torneos

Este documento describe cómo funciona cada tipo de torneo y qué valida el sistema. Las mismas reglas se aplican en el servidor (PHP) y, cuando es posible, en el formulario antes de enviar.

## Tipos de torneo

| | Clasificación (eliminación directa) | Por puntos (liga) |
| --- | --- | --- |
| Quién gana | El ganador de la final | El primero de la tabla al confirmar el último partido |
| Organización | Rondas de un cuadro | Jornadas (todos contra todos) |
| Empate en un partido | No existe: tiempo extra y penales | Es un resultado válido |
| Mínimo de equipos | 2 | 3 |

## Ciclo de vida

1. **Configuración** (borrador): datos, fechas y reglas. Solo en este estado se pueden editar.
2. **Inscripción**: se inscriben equipos activos, sin repetir; se pueden ordenar y retirar.
3. **Cierre**: se elige el orden de los equipos, por sorteo aleatorio u orden manual. En una liga el sorteo se hace al cerrar, para que la vista previa del calendario sea exactamente la que se genera.
4. **Cuadro o calendario**: se generan todos los partidos y el torneo pasa a *En curso*.
5. **Partidos**: programar, iniciar, registrar goles y tarjetas, enviar y confirmar el resultado.
6. **Campeón o tabla final**: al confirmarse la final (clasificación) o el último partido (liga), el torneo queda *Finalizado* con su campeón. Corregir un resultado confirmado lo reabre.

Con el torneo en curso solo se puede **extender** la fecha final, para dar espacio a partidos aplazados.

## Clasificación

- **Cuadro**: se completa hasta la siguiente potencia de 2. Los sembrados se enfrentan 1 contra el último, 2 contra el penúltimo, etc. Si faltan rivales, los primeros del orden pasan directo (bye) a la siguiente ronda. Los dos mejores sembrados solo pueden cruzarse en la final.
- **Empate**: si el tiempo reglamentario termina empatado se juegan los períodos extra configurados (0 a 4). Si sigue el empate, hay una tanda de penales alternada desde el equipo elegido. La tanda termina en cuanto un equipo ya no puede alcanzar al otro; tras los tiros iniciales, la muerte súbita (si está activa) sigue por parejas. Sin muerte súbita, una tanda empatada se rechaza.
- **Avance**: el ganador solo pasa a la ronda siguiente cuando se **confirma** el resultado. Un resultado confirmado solo lo corrige un administrador y solo mientras el partido siguiente no haya comenzado; la corrección retira al clasificado hasta confirmarla de nuevo.

## Por puntos (liga)

### Jornadas

- El número de jornadas se calcula solo: con **N** equipos son **N − 1** por vuelta, o **N** si N es impar (cada jornada descansa un equipo). Con ida y vuelta se duplican.
- Calendario por el método del círculo: cada pareja se enfrenta una vez por vuelta, nadie juega dos veces en la misma jornada, cada equipo descansa una vez por vuelta (si son impares), local y visitante quedan equilibrados (diferencia de 1 como máximo) y nadie juega tres jornadas seguidas de local o de visitante. La segunda vuelta repite la primera invirtiendo local y visitante.
- Antes de generar se muestra una **vista previa**: primera fecha e intervalo (7 días por defecto); cada fecha se puede ajustar. Deben ir en orden, no antes de hoy y dentro del torneo.
- Una jornada regular puede cambiar de fecha si sigue después de la anterior, antes de la siguiente y no deja partidos programados fuera.
- **Jornada extraordinaria**: se agrega al final para reponer partidos aplazados. Solo se elimina si está vacía.

### Partidos aplazados

- **Aplazar** (motivo obligatorio): el partido pierde fecha, cancha y árbitro y queda en *Pendientes de reprogramar*. Se guarda la jornada original.
- **Mover de jornada**: solo a jornadas futuras del mismo torneo en las que ninguno de los dos equipos juegue.
- Un partido de liga solo se programa dentro de las fechas de su jornada.

### Tabla de posiciones

Cuentan solo los resultados **confirmados**; si hay resultados por confirmar, la tabla se marca como provisional. Puntos configurables por torneo (por defecto 3 ganar, 1 empatar, 0 perder; ganar debe dar más que empatar y empatar no menos que perder).

Orden de desempate:

1. Puntos.
2. Diferencia de goles.
3. Goles a favor.
4. Enfrentamiento directo entre los equipos todavía empatados: puntos, diferencia y goles a favor contando solo los partidos entre ellos (ambas vueltas). Se aplica una vez a cada grupo empatado.
5. Juego limpio: menos tarjetas rojas y luego menos amarillas en partidos del torneo.
6. Orden alfabético, marcado como **empate técnico**.

## Programación de partidos

- No antes del momento actual. Una fecha ya guardada puede conservarse aunque haya pasado, pero no moverse a otro momento pasado.
- Dentro de las fechas del torneo (y de la jornada, en liga). En clasificación, después de los partidos que definen a los dos equipos más la duración de un partido.
- Sin choques en **todos** los torneos mientras dura el partido (duración configurable, 60 minutos por defecto): un equipo, una cancha o un árbitro no pueden tener dos partidos que se superpongan.
- **Cancha**: texto libre con sugerencias de las canchas ya usadas. Para detectar choques se compara sin mayúsculas, tildes ni signos: «Cancha 2», «cancha 2.» y «CÁNCHA-2» son el mismo lugar.
- Solo árbitros con cuenta activa.

## Goles y resultado

- Los goles se registran como **eventos**: jugador, minuto (0 a 150), momento (reglamentario o tiempo extra) y si fue autogol. El marcador se calcula solo a partir de ellos; nunca se escribe a mano.
- Un **autogol** se registra en la columna del equipo al que le cuenta, eligiendo al jugador rival que lo hizo. No suma a la tabla de goleadores de nadie.
- No pueden figurar como goleadores los jugadores suspendidos en la fecha del partido ni un jugador después del minuto de su tarjeta roja en ese partido.
- El árbitro asignado (o un administrador) puede registrar goles en vivo, corregirlos y, al final, enviar la hoja completa, que reemplaza lo registrado en vivo. El resultado queda *por confirmar* hasta que se confirma.

## Tarjetas y suspensiones

- Una amarilla no suspende. Una roja exige una fecha de suspensión, igual o posterior a la incidencia; el jugador no puede jugar ese día.
- Las tarjetas de un partido las registra el árbitro asignado o un administrador mientras se juega.
- Una incidencia registrada fuera de un partido no puede tener fecha futura.

## Validación de datos

| Campo | Regla |
| --- | --- |
| Nombres y apellidos | Solo letras (con tildes y ñ); separadores: espacio, guion o apóstrofo |
| Equipos, torneos, canchas, jornadas | Letras y números; separadores: espacio, guion, punto, coma o apóstrofo |
| Descripciones y motivos | Además signos de puntuación comunes; nunca `< > { } [ ] / \ @ # $ % ^ * = + \| ~` |
| Números | Solo dígitos, sin signos ni decimales, dentro de su rango |
| Fechas | Formato válido; torneos e incidencias no en el pasado/futuro según corresponda |

Todo el texto se normaliza (espacios dobles, composición de tildes) antes de validarse y guardarse. Las reglas viven en `config/validation-rules.json` y el navegador las recibe del servidor; ambos lados se prueban con los mismos casos (`tests/fixtures/validation-cases.json`).

## Cuentas

- Roles: **administrador** (configura todo, incluidos usuarios) y **árbitro** (consulta y registra los partidos que tiene asignados).
- Las cuentas no se borran: se desactivan, y la sesión de una cuenta desactivada termina de inmediato.
- Nadie puede desactivarse ni cambiarse el rol a sí mismo; siempre debe quedar un administrador activo; un árbitro con partidos pendientes conserva el rol hasta reasignarlos.
- Las cuentas nuevas y las contraseñas restablecidas son temporales: la persona debe cambiarla al ingresar. Contraseña de al menos 10 caracteres, con letras y números y distinta del correo.

## Histórico

Las jornadas y goles por jornada registrados antes de los torneos se conservan en un torneo finalizado llamado «Torneo histórico», de solo consulta (secciones *Jornadas* y *Goles* del menú *Histórico*). La migración `012_tournament_matchdays` los traslada conservando ids, goles y suspensiones.
