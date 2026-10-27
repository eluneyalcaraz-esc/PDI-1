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
    <?php if (!empty($producto['motivo_revision'])): ?><p class="notice notice-review">Motivo de revisión: <?= html((string) $producto['motivo_revision']) ?></p><?php endif; ?>
    <form class="stacked-form" action="/productos/<?= rawurlencode((string) ($producto['id'] ?? '')) ?>/actualizar" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
      <div class="form-field">
        <label for="nombre">Nombre del producto</label>
        <input id="nombre" name="nombre" type="text" minlength="3" maxlength="255" value="<?= html((string) ($producto['nombre'] ?? '')) ?>" required>
      </div>
      <div class="form-field">
        <label for="categoria">Categoría</label>
        <select id="categoria" name="categoria" required>
          <option value="">Elegí una categoría</option>
          <?php foreach (($categorias ?? productCategories()) as $value => $label): ?>
            <option value="<?= html($value) ?>" <?= ($producto['categoria'] ?? '') === $value ? 'selected' : '' ?>><?= html($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-field">
        <label for="precio">Precio</label>
        <input id="precio" name="precio" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= html((string) ($producto['precio'] ?? '')) ?>" required>
      </div>
      <div class="form-field">
        <label for="descripcion">Descripción (mínimo 30 caracteres)</label>
        <textarea id="descripcion" name="descripcion" minlength="30" maxlength="1000" rows="5" required><?= html((string) ($producto['descripcion'] ?? '')) ?></textarea>
        <small>Describí el producto con al menos 30 caracteres y 4 palabras.</small>
      </div>
      <div class="form-field">
        <label for="imagenes">Agregar imágenes</label>
        <input id="imagenes" name="imagenes[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <small>Hasta 5 imágenes en total; JPEG, PNG o WebP de hasta 5 MB cada una.</small>
      </div>
      <?php if (!empty($producto['imagenes'])): ?>
        <fieldset class="existing-images">
          <legend>Imágenes actuales</legend>
          <div class="existing-images-grid">
            <?php foreach ($producto['imagenes'] as $image): ?>
              <label>
                <img src="<?= html($image['url']) ?>" alt="Imagen actual del producto">
                <span><input type="checkbox" name="eliminar_imagenes[]" value="<?= html($image['ruta']) ?>"> Eliminar</span>
              </label>
            <?php endforeach; ?>
          </div>
          <small>El producto debe conservar al menos una imagen.</small>
        </fieldset>
      <?php endif; ?>
      <label class="checkbox-field">
        <input type="checkbox" name="producto_real" value="1" required>
        Confirmo que el producto existe, la información y el precio son correctos, y tengo derecho a publicar estas imágenes.
      </label>
      <button class="button button-primary button-wide" type="submit">Guardar cambios</button>
    </form>
  </section>
</main>