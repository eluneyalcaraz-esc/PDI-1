<?php

declare(strict_types=1);

$seller = $seller ?? [];
$sellerProducts = $sellerProducts ?? [];
$sellerRating = $sellerRating ?? ['promedio' => null, 'cantidad' => 0];
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al catálogo</a>
  <section class="seller-profile form-card">
    <p class="eyebrow">Vendedor de Emprendi</p>
    <h1><?= html((string) $seller['nombre']) ?></h1>
    <?php if (!empty($seller['localidad']) || !empty($seller['provincia'])): ?>
      <p class="seller-location"><?= html(implode(', ', array_filter([$seller['localidad'] ?? '', $seller['provincia'] ?? '']))) ?></p>
    <?php endif; ?>
    <?php if ((int) $sellerRating['cantidad'] > 0): ?>
      <p class="product-rating">★ <?= number_format((float) $sellerRating['promedio'], 1, ',', '.') ?> <span>(<?= (int) $sellerRating['cantidad'] ?> opiniones de compras entregadas)</span></p>
    <?php else: ?>
      <p class="product-rating-empty">Todavía sin opiniones verificadas</p>
    <?php endif; ?>
    <?php if (!empty($seller['descripcion_vendedor'])): ?><p><?= html((string) $seller['descripcion_vendedor']) ?></p><?php endif; ?>
    <p class="seller-trust-note">Las opiniones se habilitan únicamente después de confirmar una entrega.</p>
  </section>

  <section class="seller-products">
    <h2>Productos publicados</h2>
    <?php if ($sellerProducts === []): ?>
      <div class="empty-state"><p>Este vendedor todavía no tiene productos disponibles.</p></div>
    <?php else: ?>
      <div class="products-grid">
        <?php foreach ($sellerProducts as $producto): ?>
          <article class="product-card">
            <a class="product-card-image-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">
              <?php if (!empty($producto['imagenes'][0]['url'])): ?>
                <img class="product-card-image" src="<?= html($producto['imagenes'][0]['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
              <?php else: ?>
                <span class="product-card-image-placeholder">Sin imagen</span>
              <?php endif; ?>
            </a>
            <div class="product-info">
              <span class="product-tag"><?= $producto['stock'] !== null && (int) $producto['stock'] > 0 ? 'Stock confirmado' : 'Consultar disponibilidad' ?></span>
              <h3 class="product-name"><a class="product-card-title-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>"><?= html((string) $producto['nombre']) ?></a></h3>
              <p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
              <span class="price-wholesale"><?= html(formatArs($producto['precio'])) ?></span>
              <span class="price-label">por <?= html((string) $producto['unidad']) ?> · compra mínima <?= (int) $producto['cantidad_minima'] ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
