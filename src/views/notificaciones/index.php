<?php

declare(strict_types=1);

$notificaciones = $notificaciones ?? [];
?>
<main class="page-content">
  <a class="back-link" href="/">← Volver al catálogo</a>
  <div class="section-heading">
    <div>
      <p class="eyebrow">Avisos de tu cuenta</p>
      <h1>Notificaciones</h1>
    </div>
    <?php if (count(array_filter($notificaciones, static fn(array $item): bool => $item['leida_en'] === null)) > 0): ?>
      <form method="post" action="/notificaciones/leer">
        <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
        <button class="button button-outline" type="submit">Marcar todas como leídas</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($notificaciones === []): ?>
    <section class="empty-state"><h2>No tenés notificaciones</h2><p>Los avisos de tus pedidos y publicaciones van a aparecer acá.</p></section>
  <?php else: ?>
    <section class="purchase-list" aria-label="Notificaciones">
      <?php foreach ($notificaciones as $item): ?>
        <article class="purchase-card <?= $item['leida_en'] === null ? 'notification-unread' : '' ?>">
          <p class="eyebrow"><?= html(ucfirst((string) $item['tipo'])) ?> · <?= html((string) $item['creado_en']) ?></p>
          <p><?= html((string) $item['mensaje']) ?></p>
          <?php if (!empty($item['enlace'])): ?><a class="button button-outline" href="<?= html((string) $item['enlace']) ?>">Ver detalle</a><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
