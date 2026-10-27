<?php

declare(strict_types=1);

$productos = $productos ?? [];
$error = $error ?? null;
?>
<main>
  <a class="back-link" href="/">← Volver al inicio</a>
  <h1>Productos</h1>
  <p><a class="button button-primary" href="/productos/create">Publicar producto</a></p>

  <?php if ($error !== null): ?>
    <p role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  <?php elseif ($productos === []): ?>
    <p>Todavía no hay productos cargados.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Imagen</th>
          <th>Nombre</th>
          <th>Categoría</th>
          <th>Precio</th>
          <th>Descripción</th>
          <th>Creado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($productos as $producto): ?>
          <tr>
            <td><?= htmlspecialchars((string) $producto['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if (!empty($producto['imagenes'][0]['url'])): ?>
                <img class="product-table-image" src="<?= html($producto['imagenes'][0]['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
              <?php else: ?>
                Sin imagen
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string) $producto['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= html($categorias[$producto['categoria']] ?? 'Otros') ?></td>
            <td><?= htmlspecialchars((string) $producto['precio'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $producto['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $producto['creado_en'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</main>