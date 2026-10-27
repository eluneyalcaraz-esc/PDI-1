<?php

declare(strict_types=1);

$productos = $productos ?? [];
$categorias = $categorias ?? productCategories();
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al catálogo</a>
  <h1>Mis favoritos</h1>
  <?php if ($productos === []): ?>
    <section class="empty-state">
      <h2>Todavía no guardaste productos</h2>
      <p>Guardá los productos que te interesan y los vas a encontrar acá.</p>
      <a class="button button-primary" href="/">Explorar el catálogo</a>
    </section>
  <?php else: ?>
    <section class="products-grid">
      <?php foreach ($productos as $producto): ?>
        <article class="product-card">
          <a class="product-card-image-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">
            <?php if (!empty($producto['imagenes'][0]['url'])): ?>
              <img class="product-card-image" src="<?= html($producto['imagenes'][0]['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
            <?php else: ?>
              <span class="product-card-image-placeholder">Sin imagen</span>
            <?php endif; ?>
          </a>
          <div class="product-info">
            <span class="product-tag"><?= html($categorias[$producto['categoria']] ?? 'Otros') ?></span>
            <h2 class="product-name"><a class="product-card-title-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>"><?= html((string) $producto['nombre']) ?></a></h2>
            <p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
            <span class="price-wholesale"><?= html(formatArs($producto['precio'])) ?></span>
            <form method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/favorito">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <button class="button button-outline" type="submit">Quitar de favoritos</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
