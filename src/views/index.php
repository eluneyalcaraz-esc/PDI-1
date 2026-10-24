<?php

declare(strict_types=1);

$productos = $productos ?? [];
$search = $search ?? '';
$tags = $tags ?? [];
$error = $error ?? null;
?>
<section class="hero">
	<div class="hero-content">
		<p class="eyebrow">Encontrá lo que necesitás</p>
		<h1>Productos para hacer crecer tu negocio</h1>
		<p>Explorá publicaciones de la comunidad y guardá lo que te interese en tu carrito.</p>
		<a class="button button-light" href="#catalogo">Ver productos</a>
	</div>
</section>

<main class="page-content" id="catalogo">
	<section class="catalog-heading">
		<div>
			<p class="eyebrow">Catálogo</p>
			<h2><?= $search !== '' ? 'Resultados de búsqueda' : 'Productos publicados' ?></h2>
		</div>
		<form class="search-form" action="/" method="get" role="search">
			<label class="visually-hidden" for="product-search">Buscar productos</label>
			<input id="product-search" type="search" name="q" list="product-tags" value="<?= html($search) ?>" placeholder="Ej.: madera, tela, harina">
			<datalist id="product-tags">
				<?php foreach ($tags as $tag): ?>
					<option value="<?= html($tag) ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<button class="button button-primary" type="submit">Buscar</button>
		</form>
	</section>

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
			<p><?= $search !== '' ? 'Probá con otra palabra o elegí una de las etiquetas disponibles.' : 'Cuando haya publicaciones, vas a poder encontrarlas acá.' ?></p>
			<?php if ($search !== ''): ?><a class="button button-outline" href="/">Ver todo el catálogo</a><?php endif; ?>
		</section>
	<?php else: ?>
		<section class="products-grid" aria-label="Catálogo de productos">
			<?php foreach ($productos as $producto): ?>
				<article class="product-card">
					<div class="product-card-banner"><span><?= html(mb_strtoupper(mb_substr((string) $producto['nombre'], 0, 1))) ?></span></div>
					<div class="product-info">
						<span class="product-tag">Disponible</span>
						<h3 class="product-name"><?= html((string) $producto['nombre']) ?></h3>
						<p class="product-description"><?= html((string) $producto['descripcion']) ?></p>
						<div class="product-price-box">
							<span class="price-wholesale">$<?= html(number_format((float) $producto['precio'], 2, ',', '.')) ?></span>
							<span class="price-label">Precio publicado</span>
						</div>
						<a class="button button-outline" href="/productos/<?= rawurlencode((string) $producto['id']) ?>">Ver producto</a>
					</div>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>
</main>
