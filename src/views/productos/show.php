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
            <p class="eyebrow">Producto de la comunidad</p>
            <h1><?= html((string) $producto['nombre']) ?></h1>
            <p class="product-detail-description"><?= html((string) $producto['descripcion']) ?></p>
            <p class="product-detail-price"><strong>Precio:</strong> $<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></p>
            <?php if ($currentUser !== null && (int) $producto['usuario_id'] === $currentUser['id']): ?>
                <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>/editar">Editar producto</a>
                <form class="inline-form" method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/eliminar">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <button class="button button-danger" type="submit">Eliminar producto</button>
                </form>
            <?php elseif ($currentUser !== null): ?>
                <form class="stacked-form" method="post" action="/carrito/agregar">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <input type="hidden" name="producto_id" value="<?= html((string) $producto['id']) ?>">
                    <div class="form-field quantity-field">
                        <label for="cantidad">Cantidad</label>
                        <input id="cantidad" name="cantidad" type="number" min="1" max="99" value="1" required>
                    </div>
                    <button class="button button-primary" type="submit">Agregar al carrito</button>
                </form>
            <?php else: ?>
                <a class="button button-primary" href="/usuarios/login?return_to=<?= rawurlencode('/productos/' . (string) $producto['id']) ?>">Ingresá para agregar al carrito</a>
            <?php endif; ?>
        </article>
    <?php endif; ?>
</main>