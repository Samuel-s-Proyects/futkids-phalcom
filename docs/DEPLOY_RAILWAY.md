# Despliegue de FutKids en Railway

Esta guía resume qué cambiamos en el proyecto para publicarlo en [Railway](https://railway.com), cómo está configurado el servicio y cómo conectarse a la base de datos.

## Resumen

- La aplicación (PHP 8.3 + Phalcon 5.22.1) corre en **un solo contenedor** con Nginx y PHP-FPM.
- La base de datos es un servicio **MySQL** de Railway, dentro del mismo proyecto.
- Railway construye automáticamente desde el repositorio de GitHub cada vez que se hace push a `main`.
- Al arrancar, el contenedor aplica las migraciones y, si la base está vacía, carga los datos de demostración.

## ¿Por qué hizo falta cambiar algo?

En local usamos `docker compose` con **tres contenedores**: `db` (MySQL), `app` (PHP-FPM) y `web` (Nginx). Railway despliega **un contenedor por servicio** y espera que escuche HTTP en el puerto que indica la variable `PORT`. El `Dockerfile` original solo levanta PHP-FPM (puerto 9000, protocolo FastCGI), así que Railway no podía servir la aplicación.

La solución fue crear una imagen aparte para Railway que incluye Nginx y PHP-FPM juntos. **El `Dockerfile` y el `compose.yaml` locales no se tocaron**: el entorno de desarrollo sigue igual.

## Archivos nuevos

| Archivo | Para qué sirve |
| --- | --- |
| `railway.json` | Le dice a Railway que construya con `Dockerfile.railway`, que verifique la salud en `/api/health` y que reinicie el servicio si falla. |
| `Dockerfile.railway` | Igual que el `Dockerfile` original, pero además instala Nginx y arranca con el entrypoint de Railway. Por defecto usa `APP_ENV=production`. |
| `docker/railway/nginx.conf.template` | Configuración de Nginx. Escucha en `__PORT__`, que se sustituye por `$PORT` al arrancar, y envía las peticiones a PHP-FPM en `127.0.0.1:9000`. |
| `docker/railway/entrypoint.sh` | Script de arranque: prepara carpetas y permisos, ejecuta migraciones (con hasta 10 reintentos si MySQL aún no responde), carga los datos iniciales, genera la configuración de Nginx con el puerto real, inicia PHP-FPM y luego Nginx. |

Además, en el `README.md` se agregó la sección **"Publicación en Railway"**.

### Detalles importantes de la configuración de Nginx

- **HTTPS detrás del proxy:** Railway termina el HTTPS en su proxy y envía la petición al contenedor por HTTP con el encabezado `X-Forwarded-Proto`. Nginx traduce ese encabezado a `HTTPS=on` para PHP.
- **IP real del cliente:** sin ajuste, todas las peticiones parecerían venir de la IP del proxy de Railway. Como el login bloquea durante 10 minutos tras 10 intentos fallidos *por IP*, un solo usuario equivocándose bloquearía a todos. Por eso Nginx toma la IP real de `X-Forwarded-For` (`set_real_ip_from` + `real_ip_header`).

## Configuración en Railway

El proyecto en Railway tiene dos servicios:

1. **MySQL**: base de datos creada con *New → Database → MySQL*.
2. **cantera-phalcom**: la aplicación, creada desde el repositorio de GitHub.

### Volumen

En el servicio de la aplicación hay un volumen montado en:

```
/var/www/html/public/uploads
```

Ahí se guardan las fotografías de los jugadores. Sin el volumen, las fotos se perderían en cada nuevo despliegue.

### Variables de entorno del servicio de la aplicación

| Variable | Valor |
| --- | --- |
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `DB_NAME` | `${{MySQL.MYSQLDATABASE}}` |
| `DB_USER` | `${{MySQL.MYSQLUSER}}` |
| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` |
| `APP_ENV` | `production` |
| `APP_TIMEZONE` | `America/Guatemala` |
| `SEED_ADMIN_PASSWORD` | contraseña del administrador |
| `SEED_REFEREE_PASSWORD` | contraseña del árbitro |

Opcionales: `AGE_MIN`, `AGE_MAX`, `AGE_REFERENCE_DATE`.

Notas:

- Los valores `${{MySQL.…}}` son **referencias** de Railway: toman automáticamente los datos del servicio MySQL, por lo que no hay que copiar contraseñas a mano. Si el servicio de base de datos tuviera otro nombre, hay que cambiar `MySQL` por ese nombre.
- **No** se define `PORT`; Railway lo asigna solo.
- Las contraseñas `SEED_*` solo se usan en el **primer arranque**, cuando la base está vacía. Para cambiarlas después se usa `scripts/change_password.php`.

### Dominio

En *Settings → Networking* del servicio de la aplicación se generó un dominio público (*Generate Domain*). Para comprobar que todo funciona:

```
https://<dominio>/api/health
```

Debe responder `{"status":"ok","framework":"Phalcon 5.22.1"}`.

## Conectarse a la base de datos con DBeaver

La base de datos **no está expuesta a internet** (no activamos el acceso público). Para entrar desde nuestra computadora usamos un túnel cifrado con la CLI de Railway.

### Instalar y preparar la CLI (una sola vez)

```bash
brew install railway
railway login
cd ruta/al/proyecto/cantera-phalcom
railway link
```

En `railway link` se elige el workspace, el proyecto, el entorno `production` y el servicio `cantera-phalcom`.

### Abrir el túnel (cada vez que se quiera usar DBeaver)

```bash
railway connect MySQL --tunnel-only
```

El comando muestra el **host** y el **puerto local** del túnel. La terminal debe quedar abierta mientras se usa DBeaver; `Ctrl + C` cierra el túnel.

### Credenciales

En otra terminal:

```bash
railway variables --service MySQL
```

O en el panel web: servicio MySQL → *Variables*. Se usan `MYSQLUSER`, `MYSQLPASSWORD` y `MYSQLDATABASE`.

### Conexión en DBeaver

| Campo | Valor |
| --- | --- |
| Server Host | el host que muestra el túnel (por ejemplo `127.0.0.1`) |
| Port | el puerto que muestra el túnel |
| Database | `MYSQLDATABASE` (normalmente `railway`) |
| Username | `MYSQLUSER` (normalmente `root`) |
| Password | `MYSQLPASSWORD` |

En la pestaña **Driver properties**:

- `allowPublicKeyRetrieval` = `true` (evita el error *"Public Key Retrieval is not allowed"* de MySQL 8).
- `useSSL` = `false` (el túnel ya va cifrado).

### Alternativa: consola de MySQL en la terminal

```bash
brew install mysql-client
echo 'export PATH="$(brew --prefix mysql-client)/bin:$PATH"' >> ~/.zshrc && source ~/.zshrc
railway connect MySQL
```

## Cómo desplegar cambios

1. Hacer commit y push a `main` en GitHub.
2. Railway detecta el push, construye con `Dockerfile.railway` y reemplaza el contenedor.
3. Las migraciones nuevas se aplican solas al arrancar (`scripts/migrate.php`); los datos existentes no se sobrescriben.

Los registros del arranque y de Nginx se ven en el servicio → *Deployments* → *View logs*.

## Limitaciones conocidas

- **Sesiones:** se guardan dentro del contenedor (`var/session`), así que cada nuevo despliegue cierra las sesiones abiertas y hay que volver a iniciar sesión.
- **Un solo volumen:** Railway permite un volumen por servicio; lo usamos para las fotos.

## Prueba local de la imagen de Railway

Antes de subirla, la imagen se probó en local con un MySQL 8.4 en Docker y un puerto distinto al 80. Se verificó:

- Reintentos mientras MySQL arrancaba, migraciones 001–007 y carga de datos.
- `/api/health`, página principal y archivos estáticos respondiendo `200`.
- Login del administrador, con cookie `secure` cuando llega `X-Forwarded-Proto: https`.
- Registro de la IP real del cliente en los logs de Nginx.
