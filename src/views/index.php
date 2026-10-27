<?php

declare(strict_types=1);

$productos = $productos ?? [];
$search = $search ?? '';
$tags = $tags ?? [];
$categories = $categories ?? productCategories();
$category = $category ?? '';
$error = $error ?? null;
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
	</article>
</section>

<main class="page-content" id="catalogo">
	<section class="catalog-heading">
		<div>
			<p class="eyebrow">Catálogo</p>
			<h2><?= $search !== '' ? 'Resultados de búsqueda' : ($category !== '' ? html($categories[$category] ?? 'Productos publicados') : 'Productos publicados') ?></h2>
		</div>
	</section>

	<nav class="category-list" aria-label="Filtrar productos por categoría">
		<a class="category-chip <?= $category === '' ? 'is-active' : '' ?>" href="<?= $search !== '' ? '/?q=' . rawurlencode($search) : '/' ?>">Todas</a>
		<?php foreach ($categories as $value => $label): ?>
			<a class="category-chip <?= $category === $value ? 'is-active' : '' ?>" href="/?categoria=<?= rawurlencode($value) ?><?= $search !== '' ? '&q=' . rawurlencode($search) : '' ?>"><?= html($label) ?></a>
		<?php endforeach; ?>
	</nav>

	<?php
	$visibleTags = $tags;
	if ($search !== '') {
		$terms = preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$visibleTags = array_values(array_filter(
			$tags,
			static function (string $tag) use ($terms): bool {
				foreach ($terms as $term) {
					if (str_contains(mb_strtolower($tag), $term)) {
						return true;
					}
				}

				return false;
			}
		));
	}
	$visibleTags = array_slice($visibleTags, 0, 12);
	?>
	<?php if ($visibleTags !== []): ?>
		<nav class="tag-list" aria-label="Etiquetas de búsqueda">
			<?php foreach ($visibleTags as $tag): ?>
				<a class="tag-chip" href="/?q=<?= rawurlencode($tag) ?>"><?= html($tag) ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ($error !== null): ?>
		<p class="notice notice-error" role="alert"><?= html($error) ?></p>
	<?php elseif ($productos === []): ?>
		<section class="empty-state">
			<h2><?= $search !== '' ? 'No encontramos coincidencias' : 'Todavía no hay productos publicados' ?></h2>
				<p><?= $search !== '' ? 'Probá con otra palabra o elegí otra categoría.' : 'Cuando haya publicaciones, vas a poder encontrarlas acá.' ?></p>
				<?php if ($search !== '' || $category !== ''): ?><a class="button button-outline" href="/">Ver todo el catálogo</a><?php endif; ?>
		</section>
	<?php else: ?>
			<?php
			$productsByCategory = [];
			foreach ($productos as $producto) {
				$productsByCategory[$producto['categoria'] ?? 'otros'][] = $producto;
			}
			?>
			<?php foreach ($productsByCategory as $categoryValue => $categoryProducts): ?>
				<section class="catalog-category" aria-label="<?= html($categories[$categoryValue] ?? 'Otros') ?>">
					<h3 class="catalog-category-heading"><?= html($categories[$categoryValue] ?? 'Otros') ?></h3>
					<div class="products-grid">
						<?php foreach ($categoryProducts as $producto): ?>
							<article class="product-card">
								<?php if (!empty($producto['imagenes'][0]['url'])): ?>
									<img class="product-card-image" src="<?= html($producto['imagenes'][0]['url']) ?>" alt="<?= html((string) $producto['nombre']) ?>" loading="lazy">
								<?php else: ?>
									<div class="product-card-image-placeholder">Sin imagen</div>
								<?php endif; ?>
								<div class="product-info">
									<span class="product-tag">Disponible</span>
									<h4 class="product-name"><?= html((string) $producto['nombre']) ?></h4>
									<p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
									<div class="product-price-box">
										<span class="price-wholesale">$<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></span>
										<span class="price-label">Precio publicado</span>
									</div>
									<a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">Ver producto</a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		<?php endif; ?>
</main>
