<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database/database.php';
require_once __DIR__ . '/utils/html.php';
require_once __DIR__ . '/utils/auth.php';
require_once __DIR__ . '/utils/view.php';

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$env = $_ENV['APP_ENV'] ?? 'prod';
$allowedEnvs = ['dev', 'prod'];

if (!in_array($env, $allowedEnvs, true)) {
  throw new RuntimeException("APP_ENV inválido: $env");
}

$debug = $env === "dev";

if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
  session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
  ]);
  session_start();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$renderer = new PhpRenderer(
  templatePath: __DIR__ . '/views',
  attributes: ['title' => 'Overshine Contacts'],
);

$renderError = static function ($response, int $status, string $message) use ($renderer) {
  return view($renderer, $response->withStatus($status), 'error.php', [
    'error' => $message,
  ]);
};

$requireCsrf = static function ($request, $response) use ($renderError) {
  return csrfIsValid($request) ? null : $renderError($response, 403, 'La sesión del formulario venció. Volvé a intentarlo.');
};

$loginUrl = static fn(string $path): string => '/usuarios/login?return_to=' . rawurlencode($path);

$app->get('/', function ($request, $response) use ($renderer) {
  $queryParams = $request->getQueryParams();
  $search = mb_substr(trim((string) ($queryParams['q'] ?? '')), 0, 100);
  $productos = [];
  $error = null;
  $tags = [];

  try {
    $pdo = (new Database())->getConnection();

    $names = $pdo->query('SELECT DISTINCT nombre FROM productos ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name !== '') {
        $tags[] = $name;
      }

      foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        if (mb_strlen($word) > 2) {
          $tags[] = $word;
        }
      }
    }
    $tags = array_values(array_unique($tags));

    if ($search !== '') {
      $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
      $conditions = [];
      $parameters = [];
      foreach ($terms as $index => $term) {
        $parameter = 'search_' . $index;
        $conditions[] = "(nombre LIKE :$parameter OR descripcion LIKE :$parameter)";
        $parameters[$parameter] = '%' . $term . '%';
      }

      $stmt = $pdo->prepare(
        'SELECT id, nombre, precio, descripcion, usuario_id FROM productos WHERE '
        . implode(' AND ', $conditions)
        . ' ORDER BY id DESC'
      );
      $stmt->execute($parameters);
      $productos = $stmt->fetchAll();
    } else {
      $productos = $pdo->query(
        'SELECT id, nombre, precio, descripcion, usuario_id FROM productos ORDER BY id DESC'
      )->fetchAll();
    }
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'No se pudieron cargar los productos.';
  }

  return view($renderer, $response, 'index.php', [
    'productos' => $productos,
    'search' => $search,
    'tags' => $tags,
    'error' => $error,
  ]);
});

$app->get('/productos', function ($request, $response) use ($renderer) {
  try {
    $pdo = (new Database())->getConnection();
    $productos = $pdo->query(
      'SELECT id, nombre, precio, descripcion, creado_en FROM productos ORDER BY id DESC'
    )->fetchAll();

    return view($renderer, $response, '/productos/index.php', [
      'productos' => $productos,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudieron cargar los productos.');
  }
});

$app->get('/productos/create', function ($request, $response) use ($renderer, $loginUrl) {
  if (currentUser() === null) {
    return $response->withHeader('Location', $loginUrl('/productos/create'))->withStatus(303);
  }

  return view($renderer, $response, '/productos/create/create.php');
});

$app->get('/productos/{id}/editar', function ($request, $response, array $args) use ($renderer, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->prepare(
      'SELECT id, nombre, precio, descripcion FROM productos WHERE id = :id AND usuario_id = :usuario_id'
    );
    $stmt->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
    $producto = $stmt->fetch();

    if ($producto === false) {
      return $renderError($response, 404, 'No encontramos ese producto entre tus publicaciones.');
    }

    return view($renderer, $response, '/productos/update/update.php', [
      'producto' => $producto,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar el producto.');
  }
});

$app->get('/productos/{id}', function ($request, $response, array $args) use ($renderer, $renderError) {
  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT id, nombre, precio, descripcion, usuario_id FROM productos WHERE id = :id'
    );
    $stmt->execute(['id' => (int) $args['id']]);
    $producto = $stmt->fetch();

    if ($producto === false) {
      return $renderError($response, 404, 'No encontramos ese producto.');
    }

    return view($renderer, $response, '/productos/show.php', [
      'producto' => $producto,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar el producto.');
  }
});

$showRegistration = static fn($request, $response) => view($renderer, $response, '/usuario/register.php');
$app->get('/usuarios/register', $showRegistration);
$app->get('/usuarios/create', $showRegistration);

$app->get('/usuarios/login', function ($request, $response) use ($renderer) {
  return view($renderer, $response, '/usuario/login.php', [
    'returnTo' => safeReturnPath($request->getQueryParams()['return_to'] ?? '/'),
  ]);
});

$app->post('/usuarios', function ($request, $response) use ($renderer, $requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $data = $request->getParsedBody() ?? [];
  $user = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'gmail' => strtolower(trim((string) ($data['gmail'] ?? ''))),
    'telefono' => trim((string) ($data['telefono'] ?? '')),
    'direccion' => trim((string) ($data['direccion'] ?? '')),
  ];
  $password = (string) ($data['password'] ?? '');
  $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

  if (
    in_array('', $user, true)
    || !filter_var($user['gmail'], FILTER_VALIDATE_EMAIL)
    || strlen($user['nombre']) > 255
    || strlen($user['gmail']) > 255
    || strlen($user['telefono']) > 30
    || strlen($user['direccion']) > 255
  ) {
    return view($renderer, $response->withStatus(422), '/usuario/register.php', [
      'error' => 'Completá todos los campos con datos válidos.',
      'formData' => $user,
    ]);
  }

  if (strlen($password) < 8) {
    return view($renderer, $response->withStatus(422), '/usuario/register.php', [
      'error' => 'La contraseña debe tener al menos 8 caracteres.',
      'formData' => $user,
    ]);
  }

  if ($password !== $passwordConfirmation) {
    return view($renderer, $response->withStatus(422), '/usuario/register.php', [
      'error' => 'Las contraseñas no coinciden.',
      'formData' => $user,
    ]);
  }

  try {
    $pdo = (new Database())->getConnection();
    $check = $pdo->prepare('SELECT id FROM usuarios WHERE gmail = :gmail');
    $check->execute(['gmail' => $user['gmail']]);
    if ($check->fetchColumn() !== false) {
      return view($renderer, $response->withStatus(409), '/usuario/register.php', [
        'error' => 'Ese correo ya está registrado. Iniciá sesión o usá otro correo.',
        'formData' => $user,
      ]);
    }

    $stmt = $pdo->prepare(
      'INSERT INTO usuarios (nombre, rol, gmail, telefono, direccion, password_hash) VALUES (:nombre, :rol, :gmail, :telefono, :direccion, :password_hash)'
    );
    $stmt->execute([
      ...$user,
      'rol' => 'usuario',
      'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    setAuthenticatedUser([
      'id' => (int) $pdo->lastInsertId(),
      'nombre' => $user['nombre'],
      'gmail' => $user['gmail'],
    ]);
    $_SESSION['flash'] = 'Tu cuenta ya está lista.';

    return $response->withHeader('Location', '/perfil')->withStatus(303);
  } catch (PDOException $exception) {
    if (($exception->errorInfo[1] ?? null) === 1062) {
      return view($renderer, $response->withStatus(409), '/usuario/register.php', [
        'error' => 'Ese correo ya está registrado. Iniciá sesión o usá otro correo.',
        'formData' => $user,
      ]);
    }

    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/usuario/register.php', [
      'error' => 'No se pudo crear la cuenta. Intentá nuevamente más tarde.',
      'formData' => $user,
    ]);
  }
});

$app->post('/usuarios/login', function ($request, $response) use ($renderer, $requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $data = $request->getParsedBody() ?? [];
  $gmail = strtolower(trim((string) ($data['gmail'] ?? '')));
  $password = (string) ($data['password'] ?? '');
  $returnTo = safeReturnPath($data['return_to'] ?? '/');

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT id, nombre, gmail, password_hash FROM usuarios WHERE gmail = :gmail'
    );
    $stmt->execute(['gmail' => $gmail]);
    $user = $stmt->fetch();

    if (
      $user === false
      || !is_string($user['password_hash'])
      || $user['password_hash'] === ''
      || !password_verify($password, $user['password_hash'])
    ) {
      return view($renderer, $response->withStatus(401), '/usuario/login.php', [
        'error' => 'El correo o la contraseña son incorrectos.',
        'returnTo' => $returnTo,
      ]);
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
      $update = (new Database())->getConnection()->prepare(
        'UPDATE usuarios SET password_hash = :password_hash WHERE id = :id'
      );
      $update->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => (int) $user['id'],
      ]);
    }

    setAuthenticatedUser($user);
    return $response->withHeader('Location', $returnTo)->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/usuario/login.php', [
      'error' => 'No se pudo iniciar sesión. Intentá nuevamente más tarde.',
      'returnTo' => $returnTo,
    ]);
  }
});

$app->post('/usuarios/logout', function ($request, $response) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $_SESSION = [];
  session_destroy();
  return $response->withHeader('Location', '/')->withStatus(303);
});

$app->get('/perfil', function ($request, $response) use ($renderer, $renderError, $loginUrl) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/perfil'))->withStatus(303);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT nombre, gmail, telefono, direccion FROM usuarios WHERE id = :id'
    );
    $stmt->execute(['id' => $user['id']]);
    $profile = $stmt->fetch();

    if ($profile === false) {
      return $renderError($response, 404, 'No encontramos tu cuenta.');
    }

    return view($renderer, $response, '/usuario/profile.php', [
      'profile' => $profile,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar tu perfil.');
  }
});

$app->post('/perfil', function ($request, $response) use ($renderer, $requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $data = $request->getParsedBody() ?? [];
  $profile = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'gmail' => strtolower(trim((string) ($data['gmail'] ?? ''))),
    'telefono' => trim((string) ($data['telefono'] ?? '')),
    'direccion' => trim((string) ($data['direccion'] ?? '')),
  ];
  $password = (string) ($data['password'] ?? '');

  if (
    in_array('', $profile, true)
    || !filter_var($profile['gmail'], FILTER_VALIDATE_EMAIL)
    || strlen($profile['nombre']) > 255
    || strlen($profile['gmail']) > 255
    || strlen($profile['telefono']) > 30
    || strlen($profile['direccion']) > 255
    || ($password !== '' && strlen($password) < 8)
  ) {
    return view($renderer, $response->withStatus(422), '/usuario/profile.php', [
      'profile' => $profile,
      'error' => 'Revisá los datos. Si cambiás la contraseña, debe tener al menos 8 caracteres.',
    ]);
  }

  try {
    $pdo = (new Database())->getConnection();
    $check = $pdo->prepare('SELECT id FROM usuarios WHERE gmail = :gmail AND id <> :id');
    $check->execute(['gmail' => $profile['gmail'], 'id' => $user['id']]);
    if ($check->fetchColumn() !== false) {
      return view($renderer, $response->withStatus(409), '/usuario/profile.php', [
        'profile' => $profile,
        'error' => 'Ese correo ya está registrado en otra cuenta.',
      ]);
    }

    $sql = 'UPDATE usuarios SET nombre = :nombre, gmail = :gmail, telefono = :telefono, direccion = :direccion';
    $params = [...$profile, 'id' => $user['id']];
    if ($password !== '') {
      $sql .= ', password_hash = :password_hash';
      $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    setAuthenticatedUser(['id' => $user['id'], ...$profile]);
    $_SESSION['flash'] = 'Tu perfil se actualizó correctamente.';

    return $response->withHeader('Location', '/perfil')->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/usuario/profile.php', [
      'profile' => $profile,
      'error' => 'No se pudo actualizar el perfil. Intentá nuevamente más tarde.',
    ]);
  }
});

$app->get('/mis-productos', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/mis-productos'))->withStatus(303);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT id, nombre, precio, descripcion FROM productos WHERE usuario_id = :usuario_id ORDER BY id DESC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    return view($renderer, $response, '/productos/mis-productos.php', [
      'productos' => $stmt->fetchAll(),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudieron cargar tus productos.');
  }
});

$app->post('/productos', function ($request, $response) use ($renderer, $requireCsrf, $loginUrl) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/productos/create'))->withStatus(303);
  }

  $data = $request->getParsedBody() ?? [];
  $product = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'precio' => trim((string) ($data['precio'] ?? '')),
    'descripcion' => trim((string) ($data['descripcion'] ?? '')),
  ];

  if (
    $product['nombre'] === ''
    || strlen($product['nombre']) > 255
    || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $product['precio'])
    || $product['descripcion'] === ''
    || strlen($product['descripcion']) > 1000
  ) {
    return view($renderer, $response->withStatus(422), '/productos/create/create.php', [
      'error' => 'Completá todos los campos con datos válidos.',
      'producto' => $product,
    ]);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      "INSERT INTO productos (nombre, precio, descripcion, usuario_id, estado, busquedas) VALUES (:nombre, :precio, :descripcion, :usuario_id, 'aprobado', 0)"
    );
    $stmt->execute([...$product, 'usuario_id' => $user['id']]);
    $_SESSION['flash'] = 'Tu producto ya está publicado.';
    return $response->withHeader('Location', '/mis-productos')->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/create/create.php', [
      'error' => 'No se pudo guardar el producto. Intentá nuevamente más tarde.',
      'producto' => $product,
    ]);
  }
});

$app->post('/productos/{id}/actualizar', function ($request, $response, array $args) use ($renderer, $requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $data = $request->getParsedBody() ?? [];
  $product = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'precio' => trim((string) ($data['precio'] ?? '')),
    'descripcion' => trim((string) ($data['descripcion'] ?? '')),
  ];
  $id = (int) $args['id'];

  if (
    $product['nombre'] === ''
    || strlen($product['nombre']) > 255
    || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $product['precio'])
    || $product['descripcion'] === ''
    || strlen($product['descripcion']) > 1000
  ) {
    return view($renderer, $response->withStatus(422), '/productos/update/update.php', [
      'error' => 'Completá todos los campos con datos válidos.',
      'producto' => [...$product, 'id' => $id],
    ]);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'UPDATE productos SET nombre = :nombre, precio = :precio, descripcion = :descripcion WHERE id = :id AND usuario_id = :usuario_id'
    );
    $stmt->execute([...$product, 'id' => $id, 'usuario_id' => $user['id']]);
    if ($stmt->rowCount() === 0) {
      return $response->withHeader('Location', '/mis-productos')->withStatus(303);
    }

    $_SESSION['flash'] = 'El producto se actualizó correctamente.';
    return $response->withHeader('Location', '/mis-productos')->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/update/update.php', [
      'error' => 'No se pudo actualizar el producto.',
      'producto' => [...$product, 'id' => $id],
    ]);
  }
});

$app->post('/productos/{id}/eliminar', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $stmt = (new Database())->getConnection()->prepare(
    'DELETE FROM productos WHERE id = :id AND usuario_id = :usuario_id'
  );
  $stmt->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
  $_SESSION['flash'] = $stmt->rowCount() > 0
    ? 'El producto se eliminó correctamente.'
    : 'No encontramos ese producto entre tus publicaciones.';

  return $response->withHeader('Location', '/mis-productos')->withStatus(303);
});

$app->post('/carrito/agregar', function ($request, $response) use ($requireCsrf, $loginUrl, $renderError) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $data = $request->getParsedBody() ?? [];
  $productId = filter_var($data['producto_id'] ?? null, FILTER_VALIDATE_INT);
  $quantity = filter_var($data['cantidad'] ?? 1, FILTER_VALIDATE_INT);
  $user = currentUser();
  if ($user === null) {
    $returnTo = $productId !== false && $productId > 0 ? '/productos/' . $productId : '/carrito';
    return $response->withHeader('Location', $loginUrl($returnTo))->withStatus(303);
  }

  if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > 99) {
    $_SESSION['flash'] = 'Elegí un producto y una cantidad válida.';
    return $response->withHeader('Location', '/')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $product = $pdo->prepare('SELECT id FROM productos WHERE id = :id');
    $product->execute(['id' => $productId]);
    if ($product->fetchColumn() === false) {
      $_SESSION['flash'] = 'Ese producto ya no está disponible.';
      return $response->withHeader('Location', '/')->withStatus(303);
    }

    $stmt = $pdo->prepare(
      'INSERT INTO carrito_items (usuario_id, producto_id, cantidad) VALUES (:usuario_id, :producto_id, :cantidad) ON DUPLICATE KEY UPDATE cantidad = LEAST(cantidad + VALUES(cantidad), 99)'
    );
    $stmt->execute([
      'usuario_id' => $user['id'],
      'producto_id' => $productId,
      'cantidad' => $quantity,
    ]);
    $_SESSION['flash'] = 'Producto agregado a tu carrito.';
    return $response->withHeader('Location', '/carrito')->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo agregar el producto al carrito.');
  }
});

$app->get('/carrito', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/carrito'))->withStatus(303);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT c.id, c.cantidad, p.id AS producto_id, p.nombre, p.precio, p.descripcion FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id WHERE c.usuario_id = :usuario_id ORDER BY c.id DESC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    $total = array_sum(array_map(
      static fn(array $item): float => (float) $item['precio'] * (int) $item['cantidad'],
      $items
    ));

    return view($renderer, $response, '/carrito/index.php', [
      'items' => $items,
      'total' => $total,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar tu carrito.');
  }
});

$app->post('/carrito/{id}/cantidad', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $data = $request->getParsedBody() ?? [];
  $quantity = filter_var($data['cantidad'] ?? null, FILTER_VALIDATE_INT);
  if ($quantity === false || $quantity < 1 || $quantity > 99) {
    $_SESSION['flash'] = 'La cantidad debe estar entre 1 y 99.';
    return $response->withHeader('Location', '/carrito')->withStatus(303);
  }

  $stmt = (new Database())->getConnection()->prepare(
    'UPDATE carrito_items SET cantidad = :cantidad WHERE id = :id AND usuario_id = :usuario_id'
  );
  $stmt->execute([
    'cantidad' => $quantity,
    'id' => (int) $args['id'],
    'usuario_id' => $user['id'],
  ]);
  return $response->withHeader('Location', '/carrito')->withStatus(303);
});

$app->post('/carrito/{id}/eliminar', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $stmt = (new Database())->getConnection()->prepare(
    'DELETE FROM carrito_items WHERE id = :id AND usuario_id = :usuario_id'
  );
  $stmt->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
  return $response->withHeader('Location', '/carrito')->withStatus(303);
});

$app->post('/compras/confirmar', function ($request, $response) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $pdo = (new Database())->getConnection();
  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
      "SELECT c.producto_id, c.cantidad, p.nombre, p.precio, p.usuario_id, COALESCE(u.nombre, 'Vendedor no disponible') AS vendedor_nombre FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE c.usuario_id = :usuario_id FOR UPDATE"
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    if ($items === []) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Tu carrito está vacío.';
      return $response->withHeader('Location', '/carrito')->withStatus(303);
    }

    $total = array_sum(array_map(
      static fn(array $item): float => (float) $item['precio'] * (int) $item['cantidad'],
      $items
    ));
    $order = $pdo->prepare(
      "INSERT INTO compras (usuario_id, estado, total) VALUES (:usuario_id, 'pendiente', :total)"
    );
    $order->execute(['usuario_id' => $user['id'], 'total' => number_format($total, 2, '.', '')]);
    $orderId = (int) $pdo->lastInsertId();
    $insertItem = $pdo->prepare(
      'INSERT INTO compra_items (compra_id, producto_id, vendedor_id, nombre_producto, nombre_vendedor, precio_unitario, cantidad) VALUES (:compra_id, :producto_id, :vendedor_id, :nombre_producto, :nombre_vendedor, :precio_unitario, :cantidad)'
    );

    foreach ($items as $item) {
      $insertItem->execute([
        'compra_id' => $orderId,
        'producto_id' => $item['producto_id'],
        'vendedor_id' => $item['usuario_id'],
        'nombre_producto' => $item['nombre'],
        'nombre_vendedor' => $item['vendedor_nombre'],
        'precio_unitario' => $item['precio'],
        'cantidad' => $item['cantidad'],
      ]);
    }

    $clearCart = $pdo->prepare('DELETE FROM carrito_items WHERE usuario_id = :usuario_id');
    $clearCart->execute(['usuario_id' => $user['id']]);
    $pdo->commit();
    $_SESSION['flash'] = 'Pedido confirmado. No se realizó ningún pago.';
    return $response->withHeader('Location', '/mis-compras')->withStatus(303);
  } catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo confirmar el pedido. Tu carrito sigue guardado.';
    return $response->withHeader('Location', '/carrito')->withStatus(303);
  }
});

$app->get('/mis-compras', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/mis-compras'))->withStatus(303);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT c.id AS compra_id, c.estado, c.total, c.creado_en, i.nombre_producto, i.nombre_vendedor, i.precio_unitario, i.cantidad FROM compras c LEFT JOIN compra_items i ON i.compra_id = c.id WHERE c.usuario_id = :usuario_id ORDER BY c.id DESC, i.id ASC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $compras = [];
    foreach ($stmt->fetchAll() as $row) {
      $id = (int) $row['compra_id'];
      if (!isset($compras[$id])) {
        $compras[$id] = [
          'id' => $id,
          'estado' => $row['estado'],
          'total' => $row['total'],
          'creado_en' => $row['creado_en'],
          'items' => [],
        ];
      }
      if ($row['nombre_producto'] !== null) {
        $compras[$id]['items'][] = $row;
      }
    }

    return view($renderer, $response, '/compras/index.php', [
      'compras' => array_values($compras),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar tu historial de compras.');
  }
});

$app->get('/admin/usuarios', function ($request, $response) use ($renderer) {
  $adminUser = $_ENV['ADMIN_USER'] ?? '';
  $adminPassword = $_ENV['ADMIN_PASS'] ?? '';
  $serverParams = $request->getServerParams();
  $providedUser = (string) ($serverParams['PHP_AUTH_USER'] ?? '');
  $providedPassword = (string) ($serverParams['PHP_AUTH_PW'] ?? '');

  if (
    $adminUser === '' ||
    $adminPassword === '' ||
    !hash_equals($adminUser, $providedUser) ||
    !hash_equals($adminPassword, $providedPassword)
  ) {
    return $response
      ->withHeader('WWW-Authenticate', 'Basic realm="Administración", charset="UTF-8"')
      ->withStatus(401);
  }

  try {
    $pdo = (new Database())->getConnection();
    $usuarios = $pdo->query(
      'SELECT id, nombre, gmail, telefono, direccion FROM usuarios ORDER BY id DESC'
    )->fetchAll();

    return view($renderer, $response, '/admin/usuarios.php', [
      'usuarios' => $usuarios,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $response->withStatus(500);
  }
});

$app->addErrorMiddleware($debug, true, true);

return $app;
