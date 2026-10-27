<?php

declare(strict_types=1);

$items = $items ?? [];
$total = $total ?? 0;
$hasUnavailableItems = $hasUnavailableItems ?? false;
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al inicio</a>
  <p class="eyebrow">Tu lista para después</p>
  <h1>Carrito</h1>
  <?php if ($items === []): ?>
    <section class="empty-state">
      <h2>Tu carrito está vacío</h2>
      <p>Explorá el catálogo y agregá los productos que quieras guardar.</p>
      <a class="button button-primary" href="/">Explorar productos</a>
    </section>
  <?php else: ?>
    <div class="cart-layout">
      <section class="cart-items" aria-label="Productos en el carrito">
        <?php foreach ($items as $item): ?>
          <article class="cart-item">
            <div class="cart-item-details">
              <h2><?= html((string) $item['nombre']) ?></h2>
              <?php if ($item['estado'] !== 'aprobado'): ?>
                <p class="notice notice-review">No disponible: pendiente de revisión o no aprobado. Quitalo antes de confirmar el pedido.</p>
              <?php endif; ?>
              <p><?= html((string) $item['descripcion']) ?></p>
              <strong>$<?= html(number_format((float) $item['precio'], 2, ',', '.')) ?> c/u</strong>
            </div>
            <form class="quantity-form" method="post" action="/carrito/<?= rawurlencode((string) $item['id']) ?>/cantidad">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <label for="cantidad-<?= html((string) $item['id']) ?>">Cantidad</label>
              <input id="cantidad-<?= html((string) $item['id']) ?>" name="cantidad" type="number" min="1" max="<?= html((string) ($item['stock'] ?? 1000000)) ?>" value="<?= html((string) $item['cantidad']) ?>" required>
              <button class="button button-outline" type="submit">Actualizar</button>
            </form>
            <form method="post" action="/carrito/<?= rawurlencode((string) $item['id']) ?>/eliminar">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <button class="text-button" type="submit">Quitar</button>
            </form>
          </article>
        <?php endforeach; ?>
      </section>
      <aside class="order-summary">
        <h2>Resumen</h2>
        <p><span>Productos</span><strong><?= count($items) ?></strong></p>
        <p class="summary-total"><span>Total estimado</span><strong>$<?= html(number_format((float) $total, 2, ',', '.')) ?></strong></p>
        <p class="summary-note">Todavía no se realiza ningún pago.</p>
        <?php if ($hasUnavailableItems): ?>
          <button class="button button-primary button-wide" type="button" disabled>Continuar con el pedido</button>
        <?php else: ?>
          <a class="button button-primary button-wide" href="/compras/checkout">Continuar con el pedido</a>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</main>
