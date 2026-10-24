<?php

declare(strict_types=1);

$productos = $productos ?? [];
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al inicio</a>
  <div class="section-heading">
    <div>
      <p class="eyebrow">Tu actividad</p>
      <h1>Mis productos</h1>
    </div>
    <a class="button button-primary" href="/productos/create">Publicar producto</a>
  </div>
  <?php if ($productos === []): ?>
    <section class="empty-state">
      <h2>Todavía no publicaste productos</h2>
      <p>Cuando publiques uno, vas a poder editarlo o eliminarlo desde acá.</p>
      <a class="button button-primary" href="/productos/create">Publicar mi primer producto</a>
    </section>
  <?php else: ?>
    <section class="products-grid">
      <?php foreach ($productos as $producto): ?>
        <article class="product-card">
          <div class="product-info">
            <span class="product-tag">Tu publicación</span>
            <h2 class="product-name"><?= html((string) $producto['nombre']) ?></h2>
            <p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
            <p class="price-wholesale">$<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></p>
            <div class="card-actions">
              <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">Ver</a>
              <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>/editar">Editar</a>
              <form method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/eliminar">
                <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                <button class="button button-danger" type="submit">Eliminar</button>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
