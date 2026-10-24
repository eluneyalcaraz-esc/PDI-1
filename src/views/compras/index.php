<?php

declare(strict_types=1);

$compras = $compras ?? [];
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al inicio</a>
  <p class="eyebrow">Tu actividad</p>
  <h1>Mis compras</h1>
  <p class="form-intro">Acá aparecen los pedidos que confirmaste. Confirmar un pedido no procesa ningún pago.</p>
  <?php if ($compras === []): ?>
    <section class="empty-state">
      <h2>Todavía no tenés compras</h2>
      <p>Los productos que confirmes desde el carrito van a aparecer en este historial.</p>
      <a class="button button-primary" href="/">Explorar productos</a>
    </section>
  <?php else: ?>
    <section class="purchase-list">
      <?php foreach ($compras as $compra): ?>
        <article class="purchase-card">
          <div class="section-heading">
            <div>
              <h2>Pedido #<?= html((string) $compra['id']) ?></h2>
              <p><?= html((string) $compra['creado_en']) ?> · <?= html(ucfirst((string) $compra['estado'])) ?></p>
            </div>
            <strong class="price-wholesale">$<?= html(number_format((float) $compra['total'], 2, ',', '.')) ?></strong>
          </div>
          <?php foreach ($compra['items'] as $item): ?>
            <div class="purchase-item">
              <span><?= html((string) $item['nombre_producto']) ?> × <?= html((string) $item['cantidad']) ?></span>
              <span>Vendedor: <?= html((string) $item['nombre_vendedor']) ?></span>
              <strong>$<?= html(number_format((float) $item['precio_unitario'] * (int) $item['cantidad'], 2, ',', '.')) ?></strong>
            </div>
          <?php endforeach; ?>
          <p class="summary-note">Pedido registrado sin pago.</p>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
