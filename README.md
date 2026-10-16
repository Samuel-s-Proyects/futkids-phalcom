# Cantera · Torneo infantil

Aplicación real en **PHP 8.3 y Phalcon 5.22.1**, con MVC, modelos ORM, vistas Volt y MySQL/MariaDB. Gestiona torneos de **clasificación** (eliminación directa) y **por puntos** (liga por jornadas), equipos, jugadores con fotografía, partidos con hoja de goles, tarjetas y suspensiones, reportes, y cuentas de administradores y árbitros.

## Inicio rápido en Mac o Windows

Con Docker Desktop instalado y abierto, descomprime la entrega y entra a la carpeta `cantera-phalcon`:

```sh
cp .env.example .env
docker compose up --build -d
```

Abre **http://localhost:8080**. El primer arranque descarga las imágenes y compila la extensión Phalcon; los siguientes usan la imagen ya construida. Las migraciones y los datos de prueba se aplican automáticamente sin reemplazar los datos existentes.

En Mac también puedes ejecutar:

```sh
sh iniciar.sh
```

`iniciar.command` permite iniciar desde Finder si macOS autoriza la ejecución del archivo descargado. El comando en Terminal es la alternativa más directa.

```sh
docker compose ps
docker compose logs -f app
curl http://localhost:8080/api/health
```

La respuesta de salud debe mostrar `status: ok` y `Phalcon 5.22.1`. Si el puerto 8080 está ocupado, cambia `APP_PORT` en `.env` y vuelve a ejecutar `docker compose up -d`.

> **Actualización de una instalación anterior.** Respalda el volumen `database` antes de actualizar. Al iniciar, las migraciones 008 a 012 convierten los datos existentes: los resultados quedan guardados en cada partido, los goles registrados dos veces por el flujo anterior se depuran (y quedan en el historial del partido), y las jornadas y goles globales pasan a un «Torneo histórico» de solo consulta.

## Cuentas de prueba

| Cuenta | Correo | Contraseña | Permisos |
| --- | --- | --- | --- |
| Administrador | admin@cantera.local | Cantera2026! | Configura torneos, catálogo y usuarios |
| Árbitro | arbitro@cantera.local | Arbitro2026! | Consulta todo; registra goles, tarjetas y resultados de sus partidos |

Los botones del acceso rellenan estas credenciales cuando `APP_ENV=development`. En producción se ocultan. Las contraseñas se almacenan con hash de Phalcon. Desde **Usuarios** un administrador crea más administradores o árbitros.

## Datos precargados

- 4 equipos y 20 jugadores ficticios, con imágenes ilustrativas de prueba.
- «Torneo histórico 2026» (por puntos, finalizado) con 5 jornadas: 3, 10, 17, 24 y 31 de octubre de 2026, goles de las jornadas 1 y 2 y seis incidencias.
- Santiago Rodríguez, Leonardo Castillo y Javier Reyes están suspendidos el 17/10/2026, jornada 3.
- Mateo López encabeza la tabla de goleadores inicial con 10 goles.

## Cómo se usa

1. **Torneos → Nuevo torneo.** Un asistente pide el tipo (clasificación o por puntos), los datos y las reglas: tiempo extra y penales para clasificación; vueltas y puntos para la liga. Cada campo explica qué admite.
2. **Abrir inscripciones** e inscribir equipos en la pestaña *Equipos*. Se pueden ordenar con las flechas.
3. **Cerrar inscripción** eligiendo sorteo aleatorio u orden manual.
4. **Generar cuadro** (clasificación) o **Generar calendario** (liga): una vista previa propone la fecha de cada jornada y quién descansa; se puede ajustar antes de generar.
5. Abrir cada partido para **programarlo** (fecha y hora, cancha y árbitro), **iniciarlo**, registrar **goles** y **tarjetas**, y al final **Finalizar y enviar resultado** con la hoja del partido. El resultado se **confirma** después; en clasificación el ganador avanza, en liga se suma a la tabla.
6. En una liga, un partido que no se pudo jugar se **aplaza** y luego se **mueve** a otra jornada, por ejemplo a una **jornada extraordinaria**.

La página de cada torneo muestra en qué etapa está y cuál es el siguiente paso. Las reglas completas (desempates, fechas, choques de horario, validaciones) están en [docs/REGLAS_TORNEOS.md](docs/REGLAS_TORNEOS.md).

## Reglas principales

1. Un jugador pertenece a un equipo. Si tiene goles o incidencias, conserva ese equipo para proteger el historial. El dorsal (1 a 99) no se repite dentro del equipo.
2. Categoría infantil de 5 a 14 años al 03/10/2026, configurable con `AGE_MIN`, `AGE_MAX` y `AGE_REFERENCE_DATE`.
3. Nada se programa en el pasado: un torneo no empieza antes de hoy, un partido no se programa antes de ahora y una incidencia no tiene fecha futura. Los partidos se juegan dentro de las fechas del torneo (y de su jornada, en liga).
4. Los textos no admiten caracteres especiales (`# @ / < >`, etc.) y los campos numéricos aceptan solo dígitos. El navegador y el servidor aplican las mismas reglas.
5. Un equipo, una cancha o un árbitro no pueden tener dos partidos que se superpongan en ningún torneo. La cancha se compara sin mayúsculas, tildes ni signos.
6. El marcador se calcula a partir de los goles registrados, nunca se escribe a mano. Los autogoles cuentan para el rival y no se atribuyen a ningún goleador.
7. Una tarjeta roja suspende al jugador en la fecha indicada; no puede anotar ese día ni después del minuto de su expulsión en el mismo partido.
8. Liga: 3/1/0 puntos por defecto; desempate por diferencia de goles, goles a favor, enfrentamiento directo y juego limpio.
9. Los registros con historial no se borran; las cuentas se desactivan. Una cuenta desactivada pierde la sesión de inmediato.
10. Fotografías JPG/PNG de hasta 2 MB y 25 megapíxeles; el servidor comprueba el tipo real, las reduce y las convierte a JPEG con nombre aleatorio.

## Reportes y exportación

- **Listado para el árbitro:** para un partido programado (o una jornada del histórico), jugadores habilitados y suspendidos de los equipos que juegan.
- **Incidencias:** por equipo, jugador, tarjeta y rango de fechas inclusivo.
- **Goleadores:** de todos los torneos o de uno, y por jornada.
- **Ficha individual:** datos, foto, goles, tarjetas y suspensiones.

Los reportes ofrecen CSV y **Imprimir o PDF** (diálogo de impresión del navegador → Guardar como PDF).

## Interfaz

Diseño propio con tokens (`public/css/tokens.css`): escala tipográfica en `rem` cuyo tamaño mínimo es 13 px, colores con contraste AA, **modo oscuro** (automático o con el botón de la barra superior), menú lateral que en el teléfono se abre como panel con fondo, y formularios a una columna en pantallas pequeñas. Cada pantalla tiene su dirección (`#torneos/3/tabla`), de modo que recargar y volver atrás funcionan.

## Estructura y uso de Phalcon

| Archivo o carpeta | Responsabilidad |
| --- | --- |
| public/index.php | Punto de entrada MVC, cabeceras y respuestas de error |
| config/bootstrap.php, config/services.php | Contenedor DI: conexión, sesión, Security, ACL, Volt, reloj y servicios de negocio |
| config/routes.php | Rutas y métodos HTTP mediante el router Phalcon |
| config/validation-rules.json | Reglas de texto compartidas por el servidor y el navegador |
| app/Controllers | Autenticación, cuentas, torneos, partidos, catálogo y reportes |
| app/Models | Modelos `Phalcon\Mvc\Model` y relaciones |
| app/Services | Reglas de negocio: `TournamentService`, `Knockout/` (cuadro), `League/` (calendario y tabla), `Match/` (programación, hoja de partido, tarjetas, penales), catálogo, reportes y usuarios |
| app/Validation, app/Support | Validador de formularios, normalización de texto, reloj y transacciones |
| app/Database | Migrador versionado y datos de demostración |
| app/views/index/index.volt | Vista de la aplicación compilada por Volt |
| public/js | Interfaz en módulos ES: `core/`, `ui/`, `domain/`, `views/` |
| public/css | Tokens de diseño, base, componentes, vistas e impresión |
| database/migrations | Migraciones SQL por motor (MySQL y SQLite) y pasos PHP de conversión de datos |
| tests | PHPUnit, pruebas de interfaz (node:test, jsdom), E2E (Playwright) y HTTP |

Cada solicitud que modifica datos se ejecuta en una transacción; en MySQL además toma un bloqueo para que operaciones simultáneas (por ejemplo una roja y un gol del mismo jugador) no se crucen.

## Instalación nativa alternativa

Requiere PHP 8.3+, extensión Phalcon 5.22.1+, Composer, MySQL/MariaDB y extensiones `pdo_mysql`, `mbstring`, `intl`, `gd`, `fileinfo` y OpenSSL. Instala Phalcon desde las instrucciones oficiales apropiadas a tu sistema. No basta con `composer install`: Phalcon es una extensión del intérprete.

Configura `.env` con `DB_HOST=127.0.0.1`, el puerto, usuario, contraseña y nombre de una base creada en MySQL. Ejecuta:

```sh
composer install
php scripts/migrate.php
php scripts/seed.php
php -S 127.0.0.1:8080 -t public public/router.php
```

El servidor incorporado de PHP sirve para desarrollo. Para publicar utiliza PHP-FPM y Nginx/Apache, con document root en `public/`.

## Publicación de la aplicación PHP

La carpeta contiene la configuración para un servidor o laboratorio virtual con Docker. Para completar la URL de la presentación:

1. Copia `cantera-phalcon` a ese servidor y configura `.env` con contraseñas propias.
2. Usa `APP_ENV=production`; las cookies requieren HTTPS en este modo.
3. Ejecuta `docker compose up --build -d`.
4. Configura un dominio o la URL del laboratorio y HTTPS mediante el proxy del alojamiento.
5. Dirige el proxy a `127.0.0.1:8080` o al puerto elegido. Si se utiliza proxy local, cambia el mapeo de Compose a `127.0.0.1:8080:80`.
6. Comprueba `/api/health`, inicia sesión y verifica las fotografías y los reportes desde otra computadora.
7. Comparte esa URL durante la presentación.

Para cambiar contraseñas usa **Usuarios → Restablecer contraseña** (la persona deberá cambiarla al ingresar) o, si no hay acceso, el script de emergencia:

```sh
read -s NEW_PASSWORD
export NEW_PASSWORD
docker compose exec -e NEW_PASSWORD app php scripts/change_password.php admin@cantera.local
unset NEW_PASSWORD
```

Los volúmenes `database`, `photographs` y `runtime` mantienen la información al detener la aplicación. `docker compose down` conserva esos volúmenes. Conserva una copia de la base y de las fotos antes de actualizar la instancia.

## Verificación

```sh
docker compose run --rm --build test
npm install --prefix tests
npm --prefix tests test
npm --prefix tests run e2e
node tests/http-smoke.mjs http://localhost:8080
```

| Comando | Qué prueba |
| --- | --- |
| `docker compose run --rm --build test` | PHPUnit: suites `Unit`, `Integration` y `Migration`. Cada prueba usa su propia base SQLite y la fecha congelada en 2026-10-16 09:00 (America/Guatemala). |
| `npm --prefix tests test` | Reglas del navegador con los mismos casos que PHP (node:test), interfaz con jsdom y API simulada, y que ningún tamaño de letra salga de la escala (stylelint). |
| `npm --prefix tests run e2e` | Playwright en Chromium contra la aplicación real (servidor PHP aislado en Docker): flujos completos de clasificación, liga, validaciones y usuarios; accesibilidad con axe en modo claro y oscuro; diseño en teléfono. Primero: `npx --prefix tests playwright install chromium` y `docker build --target base -t cantera-base .`. |
| `node tests/http-smoke.mjs <url>` | Rutas HTTP, roles, CSRF, fotografías, liga y cuentas sobre una instalación de prueba (crea y limpia registros temporales). |

La integración continua (`.github/workflows/ci.yml`) ejecuta todo lo anterior y, además, migra una base **MySQL 8.4** real, comprueba la actualización desde la versión anterior (`tests/mysql/upgrade-check.php`) y corre la prueba HTTP contra MySQL. El detalle de la última verificación está en [docs/VERIFICACION.md](docs/VERIFICACION.md).

## Entrega académica

El ZIP incluye todo el código, este README, el manual técnico PDF y una guía de exposición. Entrega en plataforma: **16/10/2026**. Presentación: **17/10/2026**. Evaluación: funcionamiento 9, documento 4, código 2 y presentación personal 5, total 20 puntos.

Fuentes oficiales: https://github.com/phalcon/cphalcon/releases/tag/v5.22.1 y https://docs.phalcon.io/5.12/ .
