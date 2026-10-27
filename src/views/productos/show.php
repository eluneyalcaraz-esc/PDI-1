<?php

declare(strict_types=1);

$producto = $producto ?? null;
$error = $error ?? null;
$isFavorite = $isFavorite ?? false;
?>
<main class="page-content product-detail-page">
  <a class="back-link" href="/">← Volver al catálogo</a>

  <?php if ($error !== null): ?>
    <p class="notice notice-error" role="alert"><?= html($error) ?></p>
  <?php elseif ($producto === null): ?>
    <h1>Producto no encontrado</h1>
  <?php else: ?>
    <article class="product-detail form-card">
      <div class="product-detail-main">
        <div>
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
            <?php if (count($producto['imagenes']) > 1): ?><script src="/product-carousel.js" defer></script><?php endif; ?>
          <?php else: ?>
            <div class="product-gallery-placeholder">Este producto todavía no tiene imágenes.</div>
          <?php endif; ?>
        </div>

        <div class="product-detail-summary">
          <div class="product-card-badges">
            <span class="product-tag"><?= $producto['stock'] !== null && (int) $producto['stock'] > 0 ? 'Stock confirmado' : 'Disponibilidad a confirmar' ?></span>
            <span class="product-condition"><?= html(ucfirst((string) $producto['condicion'])) ?></span>
          </div>
          <h1><?= html((string) $producto['nombre']) ?></h1>
          <p class="product-detail-price"><?= html(formatArs($producto['precio'])) ?> <span>por <?= html((string) $producto['unidad']) ?></span></p>
          <div class="wholesale-summary">
            <p><strong>Compra mínima:</strong> <?= (int) $producto['cantidad_minima'] ?> <?= html((string) $producto['unidad']) ?></p>
            <p><strong>Disponibilidad:</strong> <?= $producto['stock'] === null ? 'A confirmar con el vendedor' : (int) $producto['stock'] . ' ' . html((string) $producto['unidad']) ?></p>
            <p><strong>Envío:</strong> Correo Argentino; el vendedor carga la cotización manualmente. La integración de tarifas y etiquetas está pendiente.</p>
          </div>
          <?php if ((int) $producto['resenas']['cantidad'] > 0): ?>
            <p class="product-rating">★ <?= number_format((float) $producto['resenas']['promedio'], 1, ',', '.') ?> <span>(<?= (int) $producto['resenas']['cantidad'] ?> opiniones de compras entregadas)</span></p>
          <?php else: ?>
            <p class="product-rating product-rating-empty">Todavía sin opiniones verificadas</p>
          <?php endif; ?>
          <p class="product-detail-description"><?= html((string) $producto['descripcion']) ?></p>

          <section class="seller-summary">
            <p class="eyebrow">Vendido por</p>
            <a href="/vendedores/<?= rawurlencode((string) $producto['usuario_id']) ?>"><?= html((string) ($producto['vendedor_nombre'] ?? 'Vendedor')) ?></a>
            <?php if (!empty($producto['vendedor_localidad']) || !empty($producto['vendedor_provincia'])): ?>
              <p><?= html(implode(', ', array_filter([$producto['vendedor_localidad'] ?? '', $producto['vendedor_provincia'] ?? '']))) ?></p>
            <?php endif; ?>
            <?php if (!empty($producto['descripcion_vendedor'])): ?><p><?= html((string) $producto['descripcion_vendedor']) ?></p><?php endif; ?>
          </section>

          <?php if ($currentUser !== null && (int) $producto['usuario_id'] === $currentUser['id']): ?>
            <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>/editar">Editar producto</a>
            <form class="inline-form" method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/eliminar">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <button class="button button-danger" type="submit">Eliminar producto</button>
            </form>
          <?php elseif ($producto['estado'] === 'aprobado' && $currentUser !== null): ?>
            <?php if ($isFavorite): ?>
              <form method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/favorito">
                <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                <button class="button button-outline favorite-button" type="submit">♥ Quitar de favoritos</button>
              </form>
            <?php else: ?>
              <form method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/favorito">
                <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                <button class="button button-outline favorite-button" type="submit">♡ Guardar en favoritos</button>
              </form>
            <?php endif; ?>
            <?php if ($producto['stock'] !== null && (int) $producto['stock'] >= (int) $producto['cantidad_minima']): ?>
              <form class="stacked-form" method="post" action="/carrito/agregar">
                <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                <input type="hidden" name="producto_id" value="<?= html((string) $producto['id']) ?>">
                <div class="form-field quantity-field">
                  <label for="cantidad">Cantidad (mínimo <?= (int) $producto['cantidad_minima'] ?>)</label>
                  <input id="cantidad" name="cantidad" type="number" min="<?= (int) $producto['cantidad_minima'] ?>" max="<?= (int) $producto['stock'] ?>" value="<?= (int) $producto['cantidad_minima'] ?>" required>
                </div>
                <button class="button button-primary" type="submit">Agregar al carrito</button>
              </form>
            <?php else: ?>
              <p class="notice notice-review">El stock está pendiente de confirmación. El vendedor debe actualizar la publicación para habilitar pedidos.</p>
            <?php endif; ?>
          <?php elseif ($producto['estado'] === 'aprobado'): ?>
            <a class="button button-primary" href="/usuarios/login?return_to=<?= rawurlencode('/productos/' . (string) $producto['id']) ?>">Ingresá para consultar o comprar</a>
          <?php endif; ?>

          <?php if ($producto['estado'] === 'aprobado' && ($currentUser === null || (int) $producto['usuario_id'] !== $currentUser['id'])): ?>
            <details class="product-report" id="reportar">
              <summary>Reportar esta publicación</summary>
              <?php if ($currentUser === null): ?>
                <p><a href="/usuarios/login?return_to=<?= rawurlencode('/productos/' . (string) $producto['id']) ?>">Iniciá sesión para enviar un reporte y ayudar a mantener el catálogo confiable.</a></p>
              <?php else: ?>
                <form class="stacked-form" method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/reportar">
                  <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                  <label for="motivo-reporte">Motivo</label>
                  <select id="motivo-reporte" name="motivo" required>
                    <option value="">Elegí un motivo</option>
                    <option value="producto_distinto">La imagen no coincide con el producto</option>
                    <option value="informacion_falsa">Información o precio engañosos</option>
                    <option value="fraude">Posible fraude o producto prohibido</option>
                    <option value="categoria_incorrecta">Categoría incorrecta</option>
                    <option value="otro">Otro</option>
                  </select>
                  <label for="detalle-reporte">Contanos qué observaste</label>
                  <textarea id="detalle-reporte" name="detalle" minlength="10" maxlength="500" required></textarea>
                  <button class="button button-outline" type="submit">Enviar reporte</button>
                </form>
              <?php endif; ?>
            </details>
          <?php endif; ?>
        </div>
      </div>

      <section class="product-reviews">
        <h2>Opiniones de compradores</h2>
        <?php if ($producto['ultimas_resenas'] === []): ?>
          <p class="product-rating-empty">Las opiniones aparecen después de confirmar la entrega de una compra.</p>
        <?php else: ?>
          <div class="review-list">
            <?php foreach ($producto['ultimas_resenas'] as $review): ?>
              <article class="review-card">
                <p class="product-rating"><?= str_repeat('★', (int) $review['puntaje']) ?><?= str_repeat('☆', 5 - (int) $review['puntaje']) ?></p>
                <p><?= html((string) $review['comentario']) ?></p>
                <small>Compra verificada · <?= html((string) ($review['comprador'] ?? 'Comprador')) ?> · <?= html((string) $review['creado_en']) ?></small>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </article>
  <?php endif; ?>
</main>
