<?php

declare(strict_types=1);

$profile = $profile ?? [];
$items = $items ?? [];
$total = $total ?? 0;
$hasUnavailableItems = $hasUnavailableItems ?? true;
?>
<main class="page-content narrow-content">
  <a class="back-link" href="/carrito">← Volver al carrito</a>
  <section class="form-card">
    <p class="eyebrow">Antes de confirmar</p>
    <h1>Datos de envío</h1>
    <p class="form-intro">Cada vendedor preparará un pedido separado. El costo de Correo Argentino se cotiza manualmente antes de pagar; todavía no hay una integración de tarifas o etiquetas.</p>
    <form class="stacked-form" method="post" action="/compras/confirmar">
      <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
      <div class="form-grid">
        <div class="form-field">
          <label for="nombre">Nombre de quien recibe</label>
          <input id="nombre" name="nombre" maxlength="255" autocomplete="name" value="<?= html((string) ($profile['nombre'] ?? '')) ?>" required>
        </div>
        <div class="form-field">
          <label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono" maxlength="30" autocomplete="tel" value="<?= html((string) ($profile['telefono'] ?? '')) ?>" required>
        </div>
        <div class="form-field form-field-full">
          <label for="direccion">Dirección</label>
          <input id="direccion" name="direccion" maxlength="255" autocomplete="street-address" value="<?= html((string) ($profile['direccion'] ?? '')) ?>" required>
        </div>
        <div class="form-field">
          <label for="localidad">Localidad</label>
          <input id="localidad" name="localidad" maxlength="100" autocomplete="address-level2" value="<?= html((string) ($profile['localidad'] ?? '')) ?>" required>
        </div>
        <div class="form-field">
          <label for="provincia">Provincia</label>
          <input id="provincia" name="provincia" maxlength="100" autocomplete="address-level1" value="<?= html((string) ($profile['provincia'] ?? '')) ?>" required>
        </div>
        <div class="form-field">
          <label for="codigo_postal">Código postal</label>
          <input id="codigo_postal" name="codigo_postal" maxlength="12" autocomplete="postal-code" value="<?= html((string) ($profile['codigo_postal'] ?? '')) ?>" required>
        </div>
      </div>
      <h2>Productos</h2>
      <?php foreach ($items as $item): ?>
        <p><?= html((string) $item['nombre']) ?> × <?= html((string) $item['cantidad']) ?> — $<?= html(number_format((float) $item['precio'] * (int) $item['cantidad'], 2, ',', '.')) ?></p>
      <?php endforeach; ?>
      <p class="summary-total">Subtotal de productos: $<?= html(number_format((float) $total, 2, ',', '.')) ?></p>
      <p class="notice notice-review">El stock se reserva al confirmar. No se realizará un cobro hasta que el vendedor cotice el envío y el pago esté habilitado.</p>
      <button class="button button-primary button-wide" type="submit" <?= $hasUnavailableItems ? 'disabled' : '' ?>>Confirmar pedido</button>
    </form>
  </section>
</main>
