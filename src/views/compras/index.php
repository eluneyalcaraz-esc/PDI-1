<?php

declare(strict_types=1);

$compras = $compras ?? [];
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al inicio</a>
  <p class="eyebrow">Tu actividad</p>
  <h1>Mis compras</h1>
  <p class="form-intro">Cada pedido corresponde a un vendedor. El pago solo se confirma mediante la notificación segura de Mercado Pago.</p>
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
              <p><?= html((string) $compra['creado_en']) ?> · <?= html(ucfirst(str_replace('_', ' ', (string) $compra['estado']))) ?></p>
            </div>
            <strong class="price-wholesale">$<?= html(number_format((float) $compra['total'], 2, ',', '.')) ?></strong>
          </div>
          <?php foreach ($compra['items'] as $item): ?>
            <div class="purchase-item">
              <span><?= html((string) $item['nombre_producto']) ?> × <?= html((string) $item['cantidad']) ?> <?= html((string) $item['producto_unidad']) ?></span>
              <span>Vendedor: <?= html((string) $item['nombre_vendedor']) ?></span>
              <strong>$<?= html(number_format((float) $item['precio_unitario'] * (int) $item['cantidad'], 2, ',', '.')) ?></strong>
            </div>
          <?php endforeach; ?>
          <p>Envío: <?= html(ucfirst(str_replace('_', ' ', (string) ($compra['envio_estado'] ?? 'pendiente')))) ?></p>
          <?php if ($compra['envio_costo'] !== null): ?>
            <p>Costo de envío cotizado: $<?= html(number_format((float) $compra['envio_costo'], 2, ',', '.')) ?></p>
          <?php endif; ?>
          <?php if ($compra['envio_tracking']): ?><p>Seguimiento: <?= html((string) $compra['envio_tracking']) ?></p><?php endif; ?>
          <p class="summary-note">Estado del pago: <?= html(ucfirst((string) $compra['estado_pago'])) ?></p>
          <?php if ($compra['estado'] === 'pendiente_pago'): ?>
            <?php if ($compra['puede_pagar']): ?>
              <form method="post" action="/compras/<?= rawurlencode((string) $compra['id']) ?>/pagar">
                <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                <button class="button button-primary" type="submit">Pagar con Mercado Pago</button>
              </form>
            <?php else: ?>
              <p class="notice notice-review">Pago deshabilitado: Emprendi todavía no tiene credenciales de Mercado Pago y una URL HTTPS pública configuradas.</p>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($compra['estado'] === 'entregado'): ?>
            <?php foreach ($compra['items'] as $item): ?>
              <?php if (empty($item['resena_id']) && $item['producto_id'] !== null): ?>
                <form class="stacked-form review-form" method="post" action="/compras/items/<?= rawurlencode((string) $item['compra_item_id']) ?>/resena">
                  <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                  <label>Puntuación
                    <select name="puntaje" required>
                      <option value="5">5 — Excelente</option>
                      <option value="4">4 — Muy bueno</option>
                      <option value="3">3 — Bueno</option>
                      <option value="2">2 — Regular</option>
                      <option value="1">1 — Malo</option>
                    </select>
                  </label>
                  <label>Tu opinión verificada
                    <textarea name="comentario" minlength="10" maxlength="1000" required></textarea>
                  </label>
                  <button class="button button-outline" type="submit">Publicar opinión</button>
                </form>
              <?php elseif (!empty($item['resena_id'])): ?>
                <p class="summary-note">Opinión verificada publicada.</p>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
