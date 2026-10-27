<?php

declare(strict_types=1);

$profile = $profile ?? [];
$error = $error ?? null;
?>
<main class="page-content narrow-content">
	<a class="back-link" href="/">← Volver al inicio</a>
	<section class="form-card">
		<p class="eyebrow">Tu cuenta</p>
		<h1>Mi perfil</h1>
		<p class="form-intro">Actualizá tus datos. La contraseña es opcional; dejala vacía si no querés cambiarla.</p>
		<?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= html($error) ?></p><?php endif; ?>
		<form class="stacked-form" method="post" action="/perfil">
			<input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
			<div class="form-grid">
				<div class="form-field">
					<label for="nombre">Nombre</label>
					<input id="nombre" name="nombre" type="text" maxlength="255" value="<?= html((string) ($profile['nombre'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="gmail">Correo electrónico</label>
					<input id="gmail" name="gmail" type="email" maxlength="255" value="<?= html((string) ($profile['gmail'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="telefono">Teléfono</label>
					<input id="telefono" name="telefono" type="tel" maxlength="30" value="<?= html((string) ($profile['telefono'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="direccion">Dirección</label>
					<input id="direccion" name="direccion" type="text" maxlength="255" value="<?= html((string) ($profile['direccion'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="localidad">Localidad (visible en tu perfil público de vendedor)</label>
					<input id="localidad" name="localidad" type="text" maxlength="100" value="<?= html((string) ($profile['localidad'] ?? '')) ?>">
				</div>
				<div class="form-field">
					<label for="provincia">Provincia (visible en tu perfil público de vendedor)</label>
					<input id="provincia" name="provincia" type="text" maxlength="100" value="<?= html((string) ($profile['provincia'] ?? '')) ?>">
				</div>
				<div class="form-field">
					<label for="codigo_postal">Código postal para envíos</label>
					<input id="codigo_postal" name="codigo_postal" type="text" maxlength="12" value="<?= html((string) ($profile['codigo_postal'] ?? '')) ?>">
				</div>
				<div class="form-field form-field-full">
					<label for="descripcion_vendedor">Presentación de tu emprendimiento</label>
					<textarea id="descripcion_vendedor" name="descripcion_vendedor" maxlength="500" rows="3"><?= html((string) ($profile['descripcion_vendedor'] ?? '')) ?></textarea>
				</div>
				<div class="form-field form-field-full">
					<label for="password">Nueva contraseña (opcional)</label>
					<input id="password" name="password" type="password" minlength="8" autocomplete="new-password">
				</div>
			</div>
			<button class="button button-primary button-wide" type="submit">Guardar cambios</button>
		</form>
	</section>
</main>
