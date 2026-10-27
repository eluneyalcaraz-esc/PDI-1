<?php

declare(strict_types=1);

$productos = $productos ?? [];
$search = $search ?? '';
$tags = $tags ?? [];
$categories = $categories ?? productCategories();
$category = $category ?? '';
$error = $error ?? null;
$sort = $sort ?? 'recientes';
$sortOptions = $sortOptions ?? [];
$page = $page ?? 1;
$pages = $pages ?? 1;
$totalProducts = $totalProducts ?? 0;
$favoriteProductIds = $favoriteProductIds ?? [];
$availabilityOnly = $availabilityOnly ?? false;
$minimumPrice = $minimumPrice ?? '';
$maximumPrice = $maximumPrice ?? '';
$currentQuery = [
  'q' => $search,
  'categoria' => $category,
  'orden' => $sort,
  'disponible' => $availabilityOnly ? '1' : '',
  'precio_min' => $minimumPrice,
  'precio_max' => $maximumPrice,
];
$currentQuery = array_filter($currentQuery, static fn(string $value): bool => $value !== '');
?>
<section class="hero">
  <div class="hero-content">
    <p class="eyebrow">Emprendi conecta emprendedores</p>
    <h1>Encontrá productos para tu emprendimiento</h1>
    <p>Explorá el catálogo y descubrí nuevas oportunidades.</p>
    <form class="search-form" action="/" method="get" role="search">
      <label class="visually-hidden" for="product-search">Buscar productos</label>
      <input id="product-search" type="search" name="q" list="product-tags" value="<?= html($search) ?>" placeholder="¿Qué producto estás buscando?">
      <datalist id="product-tags">
        <?php foreach ($tags as $tag): ?>
          <option value="<?= html($tag) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <button class="button button-primary" type="submit">Buscar</button>
    </form>
  </div>
</section>

<main class="page-content" id="catalogo">
  <section class="catalog-heading">
    <div>
      <p class="eyebrow">Catálogo</p>
      <h2><?= $search !== '' ? 'Resultados de búsqueda' : ($category !== '' ? html($categories[$category] ?? 'Productos publicados') : 'Productos publicados') ?></h2>
      <p class="catalog-result-count"><?= (int) $totalProducts ?> productos encontrados</p>
    </div>
  </section>

  <nav class="category-list" aria-label="Filtrar productos por categoría">
    <a class="category-chip <?= $category === '' ? 'is-active' : '' ?>" href="<?= html('/?' . http_build_query(array_filter([...$currentQuery, 'categoria' => ''], static fn(string $value): bool => $value !== ''))) ?>">Todas</a>
    <?php foreach ($categories as $value => $label): ?>
      <?php $categoryQuery = [...$currentQuery, 'categoria' => $value]; unset($categoryQuery['pagina']); ?>
      <a class="category-chip <?= $category === $value ? 'is-active' : '' ?>" href="/?<?= html(http_build_query($categoryQuery)) ?>"><?= html($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($search !== '' && $tags !== []): ?>
    <?php
    $terms = preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $visibleTags = array_slice(array_values(array_filter(
      $tags,
      static function (string $tag) use ($terms): bool {
        foreach ($terms as $term) {
          if (str_contains(mb_strtolower($tag), $term)) {
            return true;
          }
        }
        return false;
      }
    )), 0, 12);
    ?>
    <?php if ($visibleTags !== []): ?>
      <nav class="tag-list" aria-label="Sugerencias de búsqueda">
        <?php foreach ($visibleTags as $tag): ?>
          <a class="tag-chip" href="/?<?= html(http_build_query([...$currentQuery, 'q' => $tag])) ?>"><?= html($tag) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($error !== null): ?>
    <p class="notice notice-error" role="alert"><?= html($error) ?></p>
  <?php else: ?>
    <form class="catalog-filters" action="/" method="get">
      <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= html($search) ?>"><?php endif; ?>
      <?php if ($category !== ''): ?><input type="hidden" name="categoria" value="<?= html($category) ?>"><?php endif; ?>
      <label>
        Ordenar por
        <select name="orden">
          <?php foreach ($sortOptions as $value => $label): ?>
            <option value="<?= html($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= html($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="catalog-available-filter">
        <input type="checkbox" name="disponible" value="1" <?= $availabilityOnly ? 'checked' : '' ?>>
        Con stock confirmado
      </label>
      <label>
        Precio mínimo (ARS)
        <input name="precio_min" type="number" min="0" step="0.01" value="<?= html($minimumPrice) ?>" inputmode="decimal">
      </label>
      <label>
        Precio máximo (ARS)
        <input name="precio_max" type="number" min="0" step="0.01" value="<?= html($maximumPrice) ?>" inputmode="decimal">
      </label>
      <button class="button button-outline" type="submit">Aplicar filtros</button>
    </form>

    <?php if ($productos === []): ?>
      <section class="empty-state">
        <h2><?= $search !== '' ? 'No encontramos coincidencias' : 'Todavía no hay productos publicados' ?></h2>
        <p><?= $search !== '' ? 'Probá con otra palabra o ajustá los filtros.' : 'Cuando haya publicaciones, vas a poder encontrarlas acá.' ?></p>
        <?php if ($search !== '' || $category !== '' || $availabilityOnly || $minimumPrice !== '' || $maximumPrice !== ''): ?>
          <a class="button button-outline" href="/">Ver todo el catálogo</a>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="products-grid" aria-label="Productos">
        <?php foreach ($productos as $producto): ?>
          <?php $hasStock = $producto['stock'] !== null && (int) $producto['stock'] > 0; ?>
          <article class="product-card">
            <a class="product-card-image-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>" aria-label="Ver <?= html((string) $producto['nombre']) ?>">
              <?php if (!empty($producto['imagenes'][0]['url'])): ?>
                <img class="product-card-image" src="<?= html($producto['imagenes'][0]['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
              <?php else: ?>
                <span class="product-card-image-placeholder">Sin imagen</span>
              <?php endif; ?>
            </a>
            <div class="product-info">
              <div class="product-card-badges">
                <span class="product-tag"><?= $hasStock ? 'Stock confirmado' : 'Consultar disponibilidad' ?></span>
                <span class="product-condition"><?= html(ucfirst((string) $producto['condicion'])) ?></span>
              </div>
              <a class="product-card-title-link" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">
                <h3 class="product-name"><?= html((string) $producto['nombre']) ?></h3>
              </a>
              <p class="product-card-category"><?= html($categories[$producto['categoria']] ?? 'Otros') ?></p>
              <p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
              <div class="product-price-box">
                <span class="price-wholesale"><?= html(formatArs($producto['precio'])) ?></span>
                <span class="price-label">por <?= html((string) $producto['unidad']) ?> · compra mínima <?= (int) $producto['cantidad_minima'] ?></span>
              </div>
              <?php if ((int) $producto['cantidad_resenas'] > 0): ?>
                <p class="product-rating" aria-label="Calificación <?= number_format((float) $producto['promedio_resenas'], 1, ',', '.') ?> de 5">
                  ★ <?= number_format((float) $producto['promedio_resenas'], 1, ',', '.') ?>
                  <span>(<?= (int) $producto['cantidad_resenas'] ?> opiniones verificadas)</span>
                </p>
              <?php else: ?>
                <p class="product-rating product-rating-empty">Todavía sin opiniones verificadas</p>
              <?php endif; ?>
              <div class="product-card-actions">
                <a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">Ver producto</a>
                <?php if ($currentUser !== null): ?>
                  <form method="post" action="/productos/<?= rawurlencode((string) $producto['id']) ?>/favorito">
                    <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                    <button class="button button-outline favorite-button" type="submit" aria-label="<?= in_array((int) $producto['id'], $favoriteProductIds, true) ? 'Quitar de favoritos' : 'Guardar en favoritos' ?>">
                      <?= in_array((int) $producto['id'], $favoriteProductIds, true) ? '♥ Guardado' : '♡ Guardar' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Páginas del catálogo">
          <?php if ($page > 1): ?>
            <a class="button button-outline" href="/?<?= html(http_build_query([...$currentQuery, 'pagina' => $page - 1])) ?>">Anterior</a>
          <?php endif; ?>
          <span>Página <?= (int) $page ?> de <?= (int) $pages ?></span>
          <?php if ($page < $pages): ?>
            <a class="button button-outline" href="/?<?= html(http_build_query([...$currentQuery, 'pagina' => $page + 1])) ?>">Siguiente</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</main>
