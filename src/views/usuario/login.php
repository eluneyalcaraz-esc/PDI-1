<?php

declare(strict_types=1);

$error = $error ?? null;
$returnTo = $returnTo ?? '/';
?>
<main class="page-content narrow-content">
	<a class="back-link" href="/">← Volver al inicio</a>
	<section class="form-card form-card-small">
		<p class="eyebrow">Qué bueno verte de nuevo</p>
		<h1>Iniciar sesión</h1>
		<p class="form-intro">Ingresá con el correo y la contraseña de tu cuenta.</p>

		<?php if ($error !== null): ?>
			<p class="notice notice-error" role="alert"><?= html($error) ?></p>
		<?php endif; ?>

		<form class="stacked-form" method="post" action="/usuarios/login">
			<input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
			<input type="hidden" name="return_to" value="<?= html($returnTo) ?>">
			<div class="form-field">
				<label for="gmail">Correo electrónico</label>
				<input id="gmail" name="gmail" type="email" maxlength="255" autocomplete="email" required>
			</div>
			<div class="form-field">
				<label for="password">Contraseña</label>
				<input id="password" name="password" type="password" autocomplete="current-password" required>
			</div>
			<button class="button button-primary button-wide" type="submit">Ingresar</button>
		</form>
		<p class="form-footer">¿Todavía no tenés cuenta? <a href="/usuarios/register">Registrate</a></p>
	</section>
</main>
