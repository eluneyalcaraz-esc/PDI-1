<?php

declare(strict_types=1);

$usuarios = $usuarios ?? [];
?>
<main>
  <h1>Usuarios registrados</h1>

  <?php if ($usuarios === []): ?>
    <p>Todavía no hay usuarios registrados.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Correo</th>
          <th>Teléfono</th>
          <th>Dirección</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $usuario): ?>
          <tr>
            <td><?= htmlspecialchars((string) $usuario['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $usuario['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $usuario['gmail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $usuario['telefono'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $usuario['direccion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>