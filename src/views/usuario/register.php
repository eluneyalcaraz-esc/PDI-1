<?php

declare(strict_types=1);

$error = $error ?? null;
$formData = $formData ?? [];
?>
<main class="page-content narrow-content">
	<a class="back-link" href="/">← Volver al inicio</a>
	<section class="form-card">
		<p class="eyebrow">Un espacio para comprar y publicar</p>
		<h1>Crear tu cuenta</h1>
		<p class="form-intro">Completá tus datos para empezar. Todas las cuentas tienen los mismos permisos.</p>

		<?php if ($error !== null): ?>
			<p class="notice notice-error" role="alert"><?= html($error) ?></p>
		<?php endif; ?>

		<form class="stacked-form" method="post" action="/usuarios">
			<input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
			<div class="form-grid">
				<div class="form-field">
					<label for="nombre">Nombre</label>
					<input id="nombre" name="nombre" type="text" maxlength="255" autocomplete="name" value="<?= html((string) ($formData['nombre'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="gmail">Correo electrónico</label>
					<input id="gmail" name="gmail" type="email" maxlength="255" autocomplete="email" value="<?= html((string) ($formData['gmail'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="telefono">Teléfono</label>
					<input id="telefono" name="telefono" type="tel" maxlength="30" autocomplete="tel" value="<?= html((string) ($formData['telefono'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="direccion">Dirección</label>
					<input id="direccion" name="direccion" type="text" maxlength="255" autocomplete="street-address" value="<?= html((string) ($formData['direccion'] ?? '')) ?>" required>
				</div>
				<div class="form-field">
					<label for="password">Contraseña</label>
					<input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
				</div>
				<div class="form-field">
					<label for="password_confirmation">Repetir contraseña</label>
					<input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
				</div>
			</div>
			<button class="button button-primary button-wide" type="submit">Crear cuenta</button>
		</form>
		<p class="form-footer">¿Ya tenés cuenta? <a href="/usuarios/login">Iniciá sesión</a></p>
	</section>
</main>
