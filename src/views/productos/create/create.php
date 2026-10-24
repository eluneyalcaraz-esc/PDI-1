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
        <p class="form-intro">La publicación se guardará en tus productos y estará disponible en el catálogo.</p>

    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= html($error) ?></p>
    <?php endif; ?>

    <form class="stacked-form" method="post" action="/productos">
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
            <textarea id="descripcion" name="descripcion" maxlength="1000" rows="4" required><?= html((string) ($producto['descripcion'] ?? '')) ?></textarea>
        </div>

        <button class="button button-primary button-wide" type="submit">Publicar producto</button>
    </form>
    </section>
</main>