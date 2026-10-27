<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title><?= html($title ?? 'Emprendi') ?></title>
        <?php if (!empty($stylesheet)): ?>
            <link rel="stylesheet" href="<?= html((string) $stylesheet) ?>" />
        <?php endif; ?>
    </head>
    <body>
        <header class="site-header">
            <div class="site-header-inner">
                <a class="site-logo" href="/" aria-label="Emprendi, ir al inicio">
                    <img src="/img/emprendi.png" alt="Emprendi">
                </a>
                <?php if (!empty($adminPanel)): ?>
                    <span class="admin-header-label">Panel de administración</span>
                    <a class="home-link" href="/">Volver al sitio</a>
                <?php else: ?>
                    <a class="home-link" href="/">Inicio</a>
                    <nav class="site-actions" aria-label="Acciones principales">
                        <a class="nav-link" href="/productos/create">Publicar producto</a>
                        <a class="nav-link cart-link" href="/carrito">Carrito</a>
                        <?php if ($currentUser !== null): ?>
                            <details class="account-menu">
                                <summary>Menú</summary>
                                <div class="account-menu-panel">
                                    <span class="menu-greeting">Hola, <?= html($currentUser['nombre']) ?></span>
                                    <a href="/mis-productos">Ver mis productos</a>
                                    <a href="/mis-compras">Ver mis compras</a>
                                    <a href="/perfil">Mi perfil</a>
                                    <form action="/usuarios/logout" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= html($csrfToken) ?>">
                                        <button type="submit">Cerrar sesión</button>
                                    </form>
                                </div>
                            </details>
                        <?php else: ?>
                            <a class="nav-link" href="/usuarios/login">Ingresar</a>
                            <a class="nav-link nav-link-primary" href="/usuarios/register">Registrarme</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </header>
        <?php if (is_string($flash) && $flash !== ''): ?>
            <p class="flash-message" role="status"><?= html($flash) ?></p>
        <?php endif; ?>
        <?= $content ?>
    </body>
</html>
