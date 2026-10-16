# Evidencia de verificación

Última verificación: 16/10/2026, rama `feature/torneos-liga-validaciones-ui`. Todas las pruebas usan la fecha congelada 2026-10-16 09:00 (America/Guatemala), de modo que las reglas de "no en el pasado" dan el mismo resultado cualquier día.

| Conjunto | Resultado | Alcance |
| --- | --- | --- |
| PHPUnit (`docker compose run --rm --build test`) | 269 pruebas, 3300 aserciones correctas | Unit: cuadro, penales, calendario de liga (2 a 12 equipos, 1 y 2 vueltas), tabla y desempates, validador, normalización de canchas. Integration: ciclo de vida de clasificación y liga, programación y choques, flujo de goles (incluida la regresión de goles duplicados), jornadas, usuarios, reportes. Migration: actualización desde la versión anterior con SQLite. |
| Reglas del navegador (node:test) | 20 pruebas correctas | Mismos casos de validación y de tabla de posiciones que PHP (`tests/fixtures/*.json`). |
| Interfaz (jsdom) | 44 pruebas correctas | Secciones, campos con hint y error enlazados, errores del servidor, asistente de torneo, cuadro, hoja de partido, calendario y tabla de liga, aplazar y mover partidos, usuarios y cambio de contraseña. |
| Estilos (stylelint) | Sin problemas | Todos los tamaños de letra salen de la escala; ninguno menor a 13 px. |
| E2E (Playwright, Chromium) | 24 pruebas correctas | Acceso y contraseña temporal, validación en cliente y servidor, torneo de clasificación completo por la interfaz, liga, usuarios, accesibilidad con axe en modo claro y oscuro, diseño a 375 px. |
| HTTP (`tests/http-smoke.mjs`) | 47 verificaciones correctas | Rutas, roles, CSRF, fotografías, cuentas y flujo de liga contra la aplicación en ejecución. |
| Actualización en MySQL 8.4 (`tests/mysql/upgrade-check.php`) | 9 verificaciones correctas | Base en la migración 011 con jornadas globales → versión actual: torneo histórico, ids y goles conservados, número de jornada único por torneo. |

La CI (`.github/workflows/ci.yml`) repite estos conjuntos en cada push; la prueba HTTP y la actualización corren allí contra un servicio MySQL 8.4.

Las pruebas jsdom validan el comportamiento de la interfaz con una API simulada; la presentación real en navegador la cubren las pruebas Playwright. Las reglas de cada tipo de torneo están documentadas en [REGLAS_TORNEOS.md](REGLAS_TORNEOS.md).
