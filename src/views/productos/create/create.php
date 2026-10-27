<?php

declare(strict_types=1);

$error = $error ?? null;
$producto = $producto ?? [];
?>
<main class="page-content narrow-content">
    <a class="back-link" href="/">← Volver al inicio</a>
    <section class="form-card">
        <p class="eyebrow">Compartí lo que ofrecés</p>
        <h1>Publicar un producto</h1>
        <p class="form-intro">Publicá información clara y una foto real del producto. Las publicaciones engañosas, duplicadas o promocionales pueden ser eliminadas.</p>

    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= html($error) ?></p>
    <?php endif; ?>

    <form class="stacked-form" method="post" action="/productos" enctype="multipart/form-data">
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
            <textarea id="descripcion" name="descripcion" minlength="30" maxlength="1000" rows="4" required><?= html((string) ($producto['descripcion'] ?? '')) ?></textarea>
            <small>Describí el producto con al menos 30 caracteres y 4 palabras.</small>
        </div>

        <div class="form-field">
            <label for="imagenes">Imágenes del producto</label>
            <input id="imagenes" name="imagenes[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
            <small>Subí al menos una foto real en formato JPEG, PNG o WebP. Máximo 5 fotos de hasta 5 MB cada una.</small>
        </div>

        <label class="checkbox-field">
            <input type="checkbox" name="producto_real" value="1" required>
            Confirmo que el producto existe, la información y el precio son correctos, y tengo derecho a publicar estas imágenes.
        </label>

        <div class="publishing-guidelines">
            No se admiten publicaciones duplicadas, contenido engañoso, servicios anunciados como productos ni imágenes que no representen lo ofrecido.
        </div>

        <button class="button button-primary button-wide" type="submit">Publicar producto</button>
    </form>
    </section>
</main>