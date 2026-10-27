<?php

declare(strict_types=1);

$error = $error ?? 'Ocurrió un error.';
?>
<main class="page-content narrow-content">
  <section class="empty-state">
    <p class="eyebrow">Emprendi</p>
    <h1>No pudimos completar la solicitud</h1>
    <p role="alert"><?= html($error) ?></p>
    <a class="button button-primary" href="/">Volver al inicio</a>
  </section>
</main>
