# Evidencia de verificación

Verificación del flujo de partidos desde el cuadro de eliminación.

| Conjunto | Resultado | Cobertura / alcance |
| --- | --- | --- |
| DOM | 47 verificaciones correctas | Incluye abrir el detalle desde el cuadro, iniciar un encuentro programado, registrar un gol y volver a cargar el marcador y goleador desde el estado persistido de la demo. |
| Integración PHP | 72 verificaciones correctas | Ejecutada en Docker con SQLite temporal; incluye rechazo de jugador/equipo inconsistente y persistencia del evento durante el partido. |
| Sintaxis JavaScript | Correcta | `node --check` en interfaz, adaptador demo y suite DOM. |
| Esquema | Sin migración nueva | Se reutilizó `tournament_match_goal_events`, creada por la migración 006. |
| Aplicación | Reconstruida y saludable | Servicio PHP actualizado; `/api/health` responde `status: ok`. |

La prueba DOM usa `jsdom`, no un navegador visual; valida el comportamiento simulado de la interfaz, no la presentación en pantalla real. La suite PHP valida las reglas y persistencia sobre SQLite temporal.

El cuadro ahora ofrece «Ver partido». El detalle permite programar encuentros que aún no tienen horario, iniciar uno programado y, mientras está en juego, registrar goles con equipo, jugador y minuto. Los eventos se guardan en la tabla existente y el marcador/listado se recalculan al cargar el estado. El servicio comprueba que el jugador pertenezca al equipo participante seleccionado y conserva la autorización de administrador o árbitro asignado.

La captura de goles en juego no finaliza ni confirma el partido ni avanza equipos a otra fase.
