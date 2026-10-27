<?php

declare(strict_types=1);

$producto = $producto ?? null;
$error = $error ?? null;
?>
<main class="page-content narrow-content">
    <a class="back-link" href="/">← Volver al inicio</a>

    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= html($error) ?></p>
    <?php elseif ($producto === null): ?>
        <h1>Producto no encontrado</h1>
    <?php else: ?>
        <article class="product-detail form-card">
            <p class="eyebrow"><?= html(productCategories()[$producto['categoria']] ?? 'Otros') ?></p>
            <?php if ($producto['estado'] !== 'aprobado'): ?>
                <p class="notice notice-review">
                    <?= $producto['estado'] === 'pendiente' ? 'Esta publicación está pendiente de revisión y solo vos podés verla.' : 'Esta publicación no fue aprobada y solo vos podés verla.' ?>
                    <?php if (!empty($producto['motivo_revision'])): ?><br>Motivo: <?= html((string) $producto['motivo_revision']) ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($producto['imagenes'])): ?>
                <section class="product-gallery" data-product-carousel aria-label="Imágenes de <?= html((string) $producto['nombre']) ?>">
                    <div class="product-gallery-slides">
                        <?php foreach ($producto['imagenes'] as $index => $image): ?>
                            <figure class="product-gallery-slide" data-carousel-slide <?= $index > 0 ? 'hidden' : '' ?>>
                                <img src="<?= html($image['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>, imagen <?= $index + 1 ?>">
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($producto['imagenes']) > 1): ?>
                        <div class="product-gallery-controls">
                            <button class="button button-outline" type="button" data-carousel-prev aria-label="Imagen anterior">Anterior</button>
                            <span data-carousel-status aria-live="polite">1 / <?= count($producto['imagenes']) ?></span>
                            <button class="button button-outline" type="button" data-carousel-next aria-label="Imagen siguiente">Siguiente</button>
                        </div>
                    <?php endif; ?>
                </section>
                <?php if (count($producto['imagenes']) > 1): ?>
                    <script src="/product-carousel.js" defer></script>
                <?php endif; ?>
            <?php else: ?>
                <div class="product-gallery-placeholder">Este producto todavía no tiene imágenes.</div>
            <?php endif; ?>
            <h1><?= html((string) $producto['nombre']) ?></h1>
            <p class="product-detail-description"><?= html((string) $producto['descripcion']) ?></p>
            <p class="product-detail-price"><strong>Precio:</strong> $<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></p>
            <?php if ($currentUser !== null && (int) $producto['usuario_id'] === $currentUser['id']): ?>
                <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>/editar">Editar producto</a>
                <form class="inline-form" method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/eliminar">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <button class="button button-danger" type="submit">Eliminar producto</button>
                </form>
            <?php elseif ($producto['estado'] === 'aprobado' && $currentUser !== null): ?>
                <form class="stacked-form" method="post" action="/carrito/agregar">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <input type="hidden" name="producto_id" value="<?= html((string) $producto['id']) ?>">
                    <div class="form-field quantity-field">
                        <label for="cantidad">Cantidad</label>
                        <input id="cantidad" name="cantidad" type="number" min="1" max="99" value="1" required>
                    </div>
                    <button class="button button-primary" type="submit">Agregar al carrito</button>
                </form>
            <?php elseif ($producto['estado'] === 'aprobado'): ?>
                <a class="button button-primary" href="/usuarios/login?return_to=<?= rawurlencode('/productos/' . (string) $producto['id']) ?>">Ingresá para agregar al carrito</a>
            <?php endif; ?>
        </article>
    <?php endif; ?>
</main>