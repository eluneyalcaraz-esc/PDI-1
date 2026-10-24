<?php

declare(strict_types=1);

$producto = $producto ?? [];
$error = $error ?? null;
?>
<main class="page-content narrow-content">
  <a class="back-link" href="/">← Volver al inicio</a>
  <section class="form-card">
    <p class="eyebrow">Tus publicaciones</p>
    <h1>Editar producto</h1>
    <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= html($error) ?></p><?php endif; ?>
    <form class="stacked-form" action="/productos/<?= rawurlencode((string) ($producto['id'] ?? '')) ?>/actualizar" method="post">
      <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
      <div class="form-field">
        <label for="nombre">Nombre del producto</label>
        <input id="nombre" name="nombre" type="text" maxlength="255" value="<?= html((string) ($producto['nombre'] ?? '')) ?>" required>
      </div>
      <div class="form-field">
        <label for="precio">Precio</label>
        <input id="precio" name="precio" type="number" min="0" step="0.01" value="<?= html((string) ($producto['precio'] ?? '')) ?>" required>
      </div>
      <div class="form-field">
        <label for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion" maxlength="1000" rows="5" required><?= html((string) ($producto['descripcion'] ?? '')) ?></textarea>
      </div>
      <button class="button button-primary button-wide" type="submit">Guardar cambios</button>
    </form>
  </section>
</main>