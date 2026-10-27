<?php

declare(strict_types=1);

$ventas = $ventas ?? [];
$paymentConfigured = $paymentConfigured ?? false;
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al catálogo</a>
  <p class="eyebrow">Herramientas para vendedores</p>
  <h1>Ventas recibidas</h1>
  <p class="form-intro">Correo Argentino todavía no está conectado a una API de tarifas o etiquetas. Cotizá cada envío con el operador y cargá aquí el importe real; no inventes una tarifa. El seguimiento también se registra manualmente.</p>
  <?php if ($ventas === []): ?>
    <section class="empty-state">
      <h2>Todavía no recibiste pedidos</h2>
      <p>Cuando alguien compre uno de tus productos, el pedido aparecerá aquí.</p>
    </section>
  <?php else: ?>
    <section class="purchase-list">
      <?php foreach ($ventas as $venta): ?>
        <article class="purchase-card">
          <div class="section-heading">
            <div>
              <h2>Pedido #<?= html((string) $venta['id']) ?></h2>
              <p><?= html((string) $venta['creado_en']) ?> · <?= html(ucfirst(str_replace('_', ' ', (string) $venta['estado']))) ?></p>
            </div>
            <strong class="price-wholesale">$<?= html(number_format((float) $venta['total'], 2, ',', '.')) ?></strong>
          </div>
          <p><strong>Comprador:</strong> <?= html((string) $venta['comprador']) ?></p>
          <p><?= html((string) $venta['productos']) ?></p>
          <p><strong>Entrega:</strong> <?= html((string) $venta['destino_nombre']) ?> · <?= html((string) $venta['destino_telefono']) ?><br>
            <?= html((string) $venta['destino_direccion']) ?>, <?= html((string) $venta['destino_localidad']) ?>, <?= html((string) $venta['destino_provincia']) ?> (<?= html((string) $venta['destino_codigo_postal']) ?>)
          </p>
          <p>Pago: <?= html(ucfirst((string) $venta['estado_pago'])) ?> · Envío: <?= html(ucfirst(str_replace('_', ' ', (string) $venta['envio_estado']))) ?></p>
          <?php if ($venta['envio_tracking']): ?><p>Seguimiento: <?= html((string) $venta['envio_tracking']) ?></p><?php endif; ?>

          <?php if ($venta['estado'] === 'pendiente_envio'): ?>
            <form class="stacked-form" method="post" action="/ventas/<?= rawurlencode((string) $venta['id']) ?>/cotizar-envio">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <label for="envio-<?= html((string) $venta['id']) ?>">Cotización real de Correo Argentino (ARS)</label>
              <input id="envio-<?= html((string) $venta['id']) ?>" name="envio_costo" type="number" min="0" max="99999999.99" step="0.01" required>
              <button class="button button-primary" type="submit">Enviar cotización al comprador</button>
            </form>
          <?php elseif ($venta['estado'] === 'pendiente_pago'): ?>
            <p class="notice notice-review"><?= $paymentConfigured ? 'Esperando que el comprador complete el pago.' : 'Mercado Pago no está habilitado; no aceptes pagos por fuera de Emprendi para este pedido.' ?></p>
          <?php elseif ($venta['estado'] === 'pagado'): ?>
            <form method="post" action="/ventas/<?= rawurlencode((string) $venta['id']) ?>/estado">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <input type="hidden" name="estado" value="preparando">
              <button class="button button-primary" type="submit">Comenzar a preparar</button>
            </form>
          <?php elseif ($venta['estado'] === 'preparando'): ?>
            <form class="stacked-form" method="post" action="/ventas/<?= rawurlencode((string) $venta['id']) ?>/estado">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <input type="hidden" name="estado" value="enviado">
              <label for="tracking-<?= html((string) $venta['id']) ?>">Número de seguimiento de Correo Argentino</label>
              <input id="tracking-<?= html((string) $venta['id']) ?>" name="tracking" maxlength="100" required>
              <button class="button button-primary" type="submit">Marcar como despachado</button>
            </form>
          <?php elseif ($venta['estado'] === 'enviado'): ?>
            <form method="post" action="/ventas/<?= rawurlencode((string) $venta['id']) ?>/estado">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <input type="hidden" name="estado" value="entregado">
              <button class="button button-outline" type="submit">Confirmar entrega</button>
            </form>
          <?php endif; ?>

          <?php if (in_array($venta['estado'], ['pendiente_envio', 'pendiente_pago'], true) && $venta['estado_pago'] !== 'aprobado' && $venta['mp_preference_id'] === null): ?>
            <form method="post" action="/ventas/<?= rawurlencode((string) $venta['id']) ?>/estado">
              <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
              <input type="hidden" name="estado" value="cancelado">
              <button class="text-button" type="submit">Cancelar pedido y liberar stock</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
