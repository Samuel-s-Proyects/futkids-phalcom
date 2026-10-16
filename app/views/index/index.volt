<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="FutKids, la liga de las futuras estrellas: gestión de torneos de fútbol infantil, equipos, jugadores y reportes.">
  <meta name="theme-color" content="#ffd60a">
  <title>FutKids · La liga de las futuras estrellas</title>
  <link rel="icon" href="/images/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
  <link rel="stylesheet" href="/css/tokens.css">
  <link rel="stylesheet" href="/css/base.css">
  <link rel="stylesheet" href="/css/layout.css">
  <link rel="stylesheet" href="/css/components.css">
  <link rel="stylesheet" href="/css/views.css">
  <link rel="stylesheet" href="/css/print.css">
  <script type="module" src="/js/main.js"></script>
</head>
<body>
  <a class="skip-link" href="#main-content">Saltar al contenido</a>

  <div id="app" class="app-shell" hidden>
    <aside class="sidebar" id="sidebar" aria-label="Menú principal">
      <a class="brand" href="#panel">
        <img class="brand__logo" src="/images/logo.png" alt="FutKids" width="720" height="275">
        <span class="brand__tagline">La liga de las futuras estrellas</span>
      </a>
      <nav class="nav" id="navigation" aria-label="Secciones"></nav>
      <div class="sidebar__footer">
        <div class="account">
          <span class="account__avatar" id="account-avatar" aria-hidden="true"></span>
          <div class="account__info"><span class="account__name" id="account-name"></span><span class="account__role" id="account-role"></span></div>
        </div>
        <div class="account__actions">
          <button class="account__action" type="button" data-action="own-password"><span id="password-icon"></span>Contraseña</button>
          <button class="account__action" type="button" data-action="logout"><span id="logout-icon"></span>Cerrar sesión</button>
        </div>
      </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop" data-action="close-menu"></div>

    <div class="workspace">
      <header class="topbar">
        <button class="icon-button menu-toggle" type="button" data-action="toggle-menu" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false" id="menu-icon"></button>
        <div class="topbar__actions">
          <button class="icon-button" type="button" data-action="toggle-theme" id="theme-toggle" aria-label="Cambiar a modo oscuro"></button>
        </div>
      </header>
      <main id="main-content" tabindex="-1"><div class="loading">Cargando…</div></main>
    </div>
  </div>

  <section id="login" class="login-screen" hidden>
    <div class="login-story">
      <a class="brand brand--plate" href="#"><img class="brand__logo" src="/images/logo.png" alt="FutKids" width="720" height="275"></a>
      <div>
        <h1>La liga de las<br>futuras estrellas.</h1>
        <p>Torneos, equipos, partidos y goles del fútbol infantil, en un solo lugar.</p>
      </div>
      <small>Fútbol infantil · Guatemala</small>
    </div>
    <div class="login-panel">
      <div class="login-panel__inner">
        <h2>Iniciar sesión</h2>
        <p class="muted">Ingrese con su cuenta de administrador o de árbitro.</p>
        <form id="login-form" class="stack" novalidate>
          <label class="field">
            <span class="field__label">Correo electrónico</span>
            <input type="email" name="email" required maxlength="200" autocomplete="username" placeholder="nombre@correo.com">
          </label>
          <label class="field">
            <span class="field__label">Contraseña</span>
            <input type="password" name="password" required maxlength="200" autocomplete="current-password">
          </label>
          <label class="check"><input type="checkbox" id="show-password"> Mostrar contraseña</label>
          <p id="login-error" class="form-error" role="alert"></p>
          <button class="button button--primary button--block" type="submit">Ingresar</button>
        </form>
        <div id="demo-accounts" class="demo-accounts" hidden>
          <p class="demo-accounts__title">Cuentas de demostración</p>
          <div class="cluster">
            <button class="button button--small" type="button" data-action="prefill" data-role="admin">Administrador</button>
            <button class="button button--small" type="button" data-action="prefill" data-role="referee">Árbitro</button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div id="startup" class="startup" role="status"><img src="/images/logo.png" alt="" width="720" height="275"><span>Cargando FutKids…</span></div>

  <dialog id="form-dialog" class="dialog" aria-labelledby="dialog-title"><div id="dialog-content"></div></dialog>
  <dialog id="wide-dialog" class="dialog dialog--wide" aria-labelledby="wide-dialog-title"><div id="wide-dialog-content"></div></dialog>
  <dialog id="confirm-dialog" class="dialog dialog--small" aria-labelledby="confirm-title"><div id="confirm-content"></div></dialog>
  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
