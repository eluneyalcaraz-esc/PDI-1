<?php

declare(strict_types=1);

$productos = $productos ?? [];
$usuarios = $usuarios ?? [];
$categorias = $categorias ?? productCategories();
$adminAuthenticated = $adminAuthenticated ?? false;
$error = $error ?? null;
?>
<main class="page-content admin-page">
  <?php if (!$adminAuthenticated): ?>
    <section class="form-card admin-login-card">
      <p class="eyebrow">Emprendi · Administración</p>
      <h1>Ingresar al panel</h1>
      <p class="form-intro">Usá el PIN compartido por el equipo administrador.</p>
      <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= html($error) ?></p>
      <?php endif; ?>
      <form class="stacked-form" method="post" action="/admin">
        <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
        <div class="form-field">
          <label for="pin">PIN de administración</label>
          <input id="pin" name="pin" type="password" autocomplete="current-password" required autofocus>
        </div>
        <button class="button button-primary button-wide" type="submit">Ingresar al panel</button>
      </form>
    </section>
  <?php else: ?>
    <div class="admin-heading">
      <div>
        <p class="eyebrow">Emprendi</p>
        <h1>Panel de administración</h1>
        <p>Revisá publicaciones y gestioná las cuentas desde un solo lugar.</p>
      </div>
      <form method="post" action="/admin/logout">
        <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
        <button class="button button-outline" type="submit">Cerrar sesión</button>
      </form>
    </div>

    <nav class="admin-sections" aria-label="Secciones del panel">
      <a href="#productos">Publicaciones <span><?= count($productos) ?></span></a>
      <a href="#usuarios">Usuarios <span><?= count(array_filter($usuarios, static fn(array $usuario): bool => $usuario['estado'] !== 'eliminado')) ?></span></a>
    </nav>

    <section class="admin-section" id="productos">
      <div class="admin-section-heading">
        <div>
          <h2>Publicaciones pendientes</h2>
          <p>Las publicaciones aprobadas aparecen en el catálogo.</p>
        </div>
        <span class="admin-count"><?= count($productos) ?> pendientes</span>
      </div>

      <?php if ($productos === []): ?>
        <div class="empty-state">
          <h3>No hay publicaciones para revisar</h3>
          <p>Las nuevas publicaciones aparecerán acá antes de mostrarse en el catálogo.</p>
        </div>
      <?php else: ?>
        <div class="moderation-list">
          <?php foreach ($productos as $producto): ?>
            <article class="moderation-card">
              <div class="moderation-images">
                <?php if ($producto['imagenes'] === []): ?>
                  <p class="notice notice-review">Esta publicación no tiene imágenes y no se puede aprobar.</p>
                <?php else: ?>
                  <?php foreach ($producto['imagenes'] as $image): ?>
                    <img src="<?= html($image['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="moderation-content">
                <p class="eyebrow"><?= html($categorias[$producto['categoria']] ?? 'Otros') ?></p>
                <h3><?= html((string) $producto['nombre']) ?></h3>
                <p><?= html((string) $producto['descripcion']) ?></p>
                <p><strong>Precio:</strong> $<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></p>
                <p><strong>Vendedor:</strong> <?= html((string) ($producto['vendedor'] ?? 'Usuario eliminado')) ?></p>
                <p class="moderation-date">Enviada: <?= html((string) $producto['creado_en']) ?></p>
                <form class="moderation-actions" method="post" action="/admin/productos/<?= rawurlencode((string) $producto['id']) ?>/aprobar">
                  <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                  <button class="button button-primary" type="submit" <?= $producto['imagenes'] === [] ? 'disabled' : '' ?>>Aprobar publicación</button>
                </form>
                <form class="moderation-reject-form" method="post" action="/admin/productos/<?= rawurlencode((string) $producto['id']) ?>/rechazar">
                  <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                  <label for="reason-<?= html((string) $producto['id']) ?>">Motivo del rechazo (mínimo 10 caracteres)</label>
                  <textarea id="reason-<?= html((string) $producto['id']) ?>" name="motivo" minlength="10" maxlength="500" required></textarea>
                  <button class="button button-danger" type="submit">Rechazar publicación</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-section" id="usuarios">
      <div class="admin-section-heading">
        <div>
          <h2>Usuarios</h2>
          <p>Editá sus datos, bloqueá el acceso o eliminá una cuenta.</p>
        </div>
      </div>

      <?php if ($usuarios === []): ?>
        <div class="empty-state">
          <h3>Todavía no hay usuarios registrados</h3>
        </div>
      <?php else: ?>
        <div class="admin-users">
          <?php foreach ($usuarios as $usuario): ?>
            <article class="admin-user-card">
              <div class="admin-user-summary">
                <div>
                  <h3><?= html((string) $usuario['nombre']) ?></h3>
                  <p><?= html((string) $usuario['gmail']) ?></p>
                  <p><?= html((string) $usuario['telefono']) ?> · <?= html((string) $usuario['direccion']) ?></p>
                </div>
                <span class="admin-user-status admin-user-status-<?= html((string) $usuario['estado']) ?>">
                  <?= $usuario['estado'] === 'activo' ? 'Activa' : ($usuario['estado'] === 'bloqueado' ? 'Bloqueada' : 'Eliminada') ?>
                </span>
              </div>

              <?php if ($usuario['estado'] !== 'eliminado'): ?>
                <details class="admin-user-edit">
                  <summary>Editar datos</summary>
                  <form class="admin-user-edit-form" method="post" action="/admin/usuarios/<?= rawurlencode((string) $usuario['id']) ?>/editar">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <label>Nombre<input name="nombre" maxlength="255" value="<?= html((string) $usuario['nombre']) ?>" required></label>
                    <label>Correo electrónico<input name="gmail" type="email" maxlength="255" value="<?= html((string) $usuario['gmail']) ?>" required></label>
                    <label>Teléfono<input name="telefono" maxlength="30" value="<?= html((string) $usuario['telefono']) ?>" required></label>
                    <label>Dirección<input name="direccion" maxlength="255" value="<?= html((string) $usuario['direccion']) ?>" required></label>
                    <button class="button button-primary" type="submit">Guardar cambios</button>
                  </form>
                </details>

                <div class="admin-user-actions">
                  <form method="post" action="/admin/usuarios/<?= rawurlencode((string) $usuario['id']) ?>/estado">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <input type="hidden" name="estado" value="<?= $usuario['estado'] === 'activo' ? 'bloqueado' : 'activo' ?>">
                    <button class="button button-outline" type="submit"><?= $usuario['estado'] === 'activo' ? 'Bloquear cuenta' : 'Desbloquear cuenta' ?></button>
                  </form>
                  <form method="post" action="/admin/usuarios/<?= rawurlencode((string) $usuario['id']) ?>/eliminar" onsubmit="return confirm('Se eliminará el acceso, se anonimizarán los datos personales y se retirarán sus productos. El historial de pedidos se conservará. ¿Continuar?')">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <button class="button button-danger" type="submit">Eliminar cuenta</button>
                  </form>
                </div>
              <?php else: ?>
                <p class="admin-user-deleted-note">Los datos personales se anonimizaron; se conservó el historial de pedidos.</p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
