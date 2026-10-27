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
require_once __DIR__ . '/utils/products.php';
require_once __DIR__ . '/utils/payments.php';

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
  attributes: ['title' => 'Emprendi'],
);

$renderError = static function ($response, int $status, string $message) use ($renderer) {
  return view($renderer, $response->withStatus($status), 'error.php', [
    'error' => $message,
  ]);
};

$app->add(static function ($request, $handler) use ($app) {
  $user = $_SESSION['usuario'] ?? null;
  if (is_array($user) && isset($user['id'])) {
    try {
      $stmt = (new Database())->getConnection()->prepare(
        'SELECT nombre, gmail, estado FROM usuarios WHERE id = :id'
      );
      $stmt->execute(['id' => (int) $user['id']]);
      $account = $stmt->fetch();

      if ($account === false || $account['estado'] !== 'activo') {
        unset($_SESSION['usuario']);
        session_regenerate_id(true);
      } else {
        $_SESSION['usuario']['nombre'] = (string) $account['nombre'];
        $_SESSION['usuario']['gmail'] = (string) $account['gmail'];
      }
    } catch (Throwable $exception) {
      error_log($exception->getMessage());
      $response = $app->getResponseFactory()->createResponse(503)
        ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
      $response->getBody()->write('No se pudo verificar el estado de la cuenta.');
      return $response;
    }
  }

  return $handler->handle($request);
});

$requireCsrf = static function ($request, $response) use ($renderError) {
  return csrfIsValid($request) ? null : $renderError($response, 403, 'La sesión del formulario venció. Volvé a intentarlo.');
};

$requireAdmin = static function ($request, $response) {
  if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    return $response
      ->withHeader('Location', '/admin')
      ->withStatus(303);
  }

  return null;
};

$loginUrl = static fn(string $path): string => '/usuarios/login?return_to=' . rawurlencode($path);

$app->get('/', function ($request, $response) use ($renderer) {
  $queryParams = $request->getQueryParams();
  $search = mb_substr(trim((string) ($queryParams['q'] ?? '')), 0, 100);
  $category = (string) ($queryParams['categoria'] ?? '');
  $sort = (string) ($queryParams['orden'] ?? 'recientes');
  $page = max(1, min(100000, (int) ($queryParams['pagina'] ?? 1)));
  $perPage = 12;
  $productos = [];
  $error = null;
  $totalProducts = 0;
  $favoriteProductIds = [];
  $tags = [];
  $categories = productCategories();
  $availabilityOnly = ($queryParams['disponible'] ?? '') === '1';
  $minimumPrice = trim((string) ($queryParams['precio_min'] ?? ''));
  $maximumPrice = trim((string) ($queryParams['precio_max'] ?? ''));
  $sortOptions = [
    'recientes' => 'Más recientes',
    'precio_asc' => 'Menor precio',
    'precio_desc' => 'Mayor precio',
    'nombre' => 'Nombre A-Z',
  ];
  if (!isset($sortOptions[$sort])) {
    $sort = 'recientes';
  }
  if (
    ($minimumPrice !== '' && (!is_numeric($minimumPrice) || (float) $minimumPrice < 0))
    || ($maximumPrice !== '' && (!is_numeric($maximumPrice) || (float) $maximumPrice < 0))
    || ($minimumPrice !== '' && $maximumPrice !== '' && (float) $minimumPrice > (float) $maximumPrice)
  ) {
    $error = 'El rango de precios seleccionado no es válido.';
  }

  try {
    $pdo = (new Database())->getConnection();

    if ($category !== '' && !array_key_exists($category, $categories)) {
      $error = 'La categoría seleccionada no es válida.';
    }

    $names = $pdo->query("SELECT DISTINCT nombre FROM productos WHERE estado = 'aprobado' ORDER BY nombre LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
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

    if ($error === null) {
      $conditions = ["estado = 'aprobado'"];
      $parameters = [];
      if ($search !== '') {
      $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
      foreach ($terms as $index => $term) {
        $nameParameter = 'name_search_' . $index;
        $descriptionParameter = 'description_search_' . $index;
        $categoryParameter = 'category_search_' . $index;
        $conditions[] = "(LOWER(nombre) LIKE :$nameParameter OR LOWER(descripcion) LIKE :$descriptionParameter OR LOWER(categoria) LIKE :$categoryParameter)";
        $escapedTerm = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term));
        $searchTerm = '%' . $escapedTerm . '%';
        $parameters[$nameParameter] = $searchTerm;
        $parameters[$descriptionParameter] = $searchTerm;
        $parameters[$categoryParameter] = $searchTerm;
      }
      }
      if ($category !== '') {
        $conditions[] = 'categoria = :category';
        $parameters['category'] = $category;
      }
      if ($availabilityOnly) {
        $conditions[] = 'stock > 0';
      }
      if ($minimumPrice !== '') {
        $conditions[] = 'precio >= :precio_min';
        $parameters['precio_min'] = $minimumPrice;
      }
      if ($maximumPrice !== '') {
        $conditions[] = 'precio <= :precio_max';
        $parameters['precio_max'] = $maximumPrice;
      }
      $where = ' WHERE ' . implode(' AND ', $conditions);
      $count = $pdo->prepare('SELECT COUNT(*) FROM productos' . $where);
      $count->execute($parameters);
      $totalProducts = (int) $count->fetchColumn();
      $stmt = $pdo->prepare(
        'SELECT p.id, p.nombre, p.precio, p.descripcion, p.usuario_id, p.categoria, p.condicion, p.unidad, p.cantidad_minima, p.stock, COALESCE(r.promedio, 0) AS promedio_resenas, COALESCE(r.cantidad, 0) AS cantidad_resenas FROM productos p LEFT JOIN (SELECT producto_id, AVG(puntaje) AS promedio, COUNT(*) AS cantidad FROM producto_resenas GROUP BY producto_id) r ON r.producto_id = p.id'
        . $where . catalogOrderBy($sort) . ' LIMIT :limit OFFSET :offset'
      );
      foreach ($parameters as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
      }
      $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
      $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
      $stmt->execute();
      $productos = $stmt->fetchAll();
      $productos = attachProductImages($pdo, $productos);
      $user = currentUser();
      if ($user !== null && $productos !== []) {
        $ids = array_map(static fn(array $product): int => (int) $product['id'], $productos);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $favorites = $pdo->prepare("SELECT producto_id FROM producto_favoritos WHERE usuario_id = ? AND producto_id IN ($placeholders)");
        $favorites->execute([$user['id'], ...$ids]);
        $favoriteProductIds = array_map('intval', $favorites->fetchAll(PDO::FETCH_COLUMN));
      }
    }
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'No se pudieron cargar los productos.';
  }

  return view($renderer, $response, 'index.php', [
    'productos' => $productos,
    'search' => $search,
    'tags' => $tags,
    'categories' => $categories,
    'category' => $category,
    'sort' => $sort,
    'sortOptions' => $sortOptions,
    'page' => $page,
    'pages' => max(1, (int) ceil($totalProducts / $perPage)),
    'totalProducts' => $totalProducts,
    'favoriteProductIds' => $favoriteProductIds,
    'availabilityOnly' => $availabilityOnly,
    'minimumPrice' => $minimumPrice,
    'maximumPrice' => $maximumPrice,
    'error' => $error,
  ]);
});

$app->get('/productos', static fn($request, $response) => $response->withHeader('Location', '/')->withStatus(301));

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
      'SELECT id, nombre, precio, descripcion, categoria, condicion, unidad, cantidad_minima, stock, motivo_revision FROM productos WHERE id = :id AND usuario_id = :usuario_id'
    );
    $stmt->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
    $producto = $stmt->fetch();

    if ($producto === false) {
      return $renderError($response, 404, 'No encontramos ese producto entre tus publicaciones.');
    }

    $producto['imagenes'] = productImages($pdo, (int) $producto['id']);

    return view($renderer, $response, '/productos/update/update.php', [
      'producto' => $producto,
      'categorias' => productCategories(),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar el producto.');
  }
});

$app->get('/productos/{id}', function ($request, $response, array $args) use ($renderer, $renderError) {
  try {
    $currentUser = currentUser();
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->prepare(
      'SELECT p.id, p.nombre, p.precio, p.descripcion, p.usuario_id, p.categoria, p.estado, p.motivo_revision, p.condicion, p.unidad, p.cantidad_minima, p.stock, u.nombre AS vendedor_nombre, u.localidad AS vendedor_localidad, u.provincia AS vendedor_provincia, u.descripcion_vendedor FROM productos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = :id'
    );
    $stmt->execute(['id' => (int) $args['id']]);
    $producto = $stmt->fetch();

    if (
      $producto === false
      || (
        $producto['estado'] !== 'aprobado'
        && ($currentUser === null || (int) $producto['usuario_id'] !== $currentUser['id'])
      )
    ) {
      return $renderError($response, 404, 'No encontramos ese producto.');
    }

    $producto['imagenes'] = productImages($pdo, (int) $producto['id']);
    $rating = $pdo->prepare('SELECT AVG(puntaje) AS promedio, COUNT(*) AS cantidad FROM producto_resenas WHERE producto_id = :producto_id');
    $rating->execute(['producto_id' => (int) $producto['id']]);
    $producto['resenas'] = $rating->fetch() ?: ['promedio' => null, 'cantidad' => 0];
    $reviews = $pdo->prepare(
      'SELECT r.puntaje, r.comentario, r.creado_en, u.nombre AS comprador FROM producto_resenas r LEFT JOIN usuarios u ON u.id = r.comprador_id WHERE r.producto_id = :producto_id ORDER BY r.creado_en DESC LIMIT 10'
    );
    $reviews->execute(['producto_id' => (int) $producto['id']]);
    $producto['ultimas_resenas'] = $reviews->fetchAll();
    $currentUser = currentUser();
    $isFavorite = false;
    if ($currentUser !== null) {
      $favorite = $pdo->prepare('SELECT 1 FROM producto_favoritos WHERE usuario_id = :usuario_id AND producto_id = :producto_id');
      $favorite->execute(['usuario_id' => $currentUser['id'], 'producto_id' => (int) $producto['id']]);
      $isFavorite = $favorite->fetchColumn() !== false;
    }

    return view($renderer, $response, '/productos/show.php', [
      'producto' => $producto,
      'categorias' => productCategories(),
      'isFavorite' => $isFavorite,
      'pageTitle' => (string) $producto['nombre'] . ' | Emprendi',
      'metaDescription' => mb_substr((string) $producto['descripcion'], 0, 160),
      'ogImage' => $producto['imagenes'][0]['url'] ?? null,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar el producto.');
  }
});

$app->get('/vendedores/{id}', function ($request, $response, array $args) use ($renderer, $renderError) {
  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  if ($id === false || $id < 1) {
    return $renderError($response, 404, 'No encontramos ese vendedor.');
  }

  try {
    $pdo = (new Database())->getConnection();
    $sellerQuery = $pdo->prepare(
      "SELECT id, nombre, localidad, provincia, descripcion_vendedor FROM usuarios WHERE id = :id AND estado = 'activo'"
    );
    $sellerQuery->execute(['id' => $id]);
    $seller = $sellerQuery->fetch();
    if ($seller === false) {
      return $renderError($response, 404, 'No encontramos ese vendedor.');
    }

    $productsQuery = $pdo->prepare(
      "SELECT p.id, p.nombre, p.precio, p.descripcion, p.categoria, p.condicion, p.unidad, p.cantidad_minima, p.stock, COALESCE(r.promedio, 0) AS promedio_resenas, COALESCE(r.cantidad, 0) AS cantidad_resenas FROM productos p LEFT JOIN (SELECT producto_id, AVG(puntaje) AS promedio, COUNT(*) AS cantidad FROM producto_resenas GROUP BY producto_id) r ON r.producto_id = p.id WHERE p.usuario_id = :usuario_id AND p.estado = 'aprobado' ORDER BY p.id DESC LIMIT 48"
    );
    $productsQuery->execute(['usuario_id' => $id]);
    $sellerProducts = attachProductImages($pdo, $productsQuery->fetchAll());
    $sellerRating = $pdo->prepare(
      'SELECT AVG(puntaje) AS promedio, COUNT(*) AS cantidad FROM producto_resenas WHERE vendedor_id = :vendedor_id'
    );
    $sellerRating->execute(['vendedor_id' => $id]);

    return view($renderer, $response, '/vendedores/show.php', [
      'seller' => $seller,
      'sellerProducts' => $sellerProducts,
      'sellerRating' => $sellerRating->fetch() ?: ['promedio' => null, 'cantidad' => 0],
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo cargar el perfil del vendedor.');
  }
});

$app->get('/favoritos', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/favoritos'))->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $query = $pdo->prepare(
      "SELECT p.id, p.nombre, p.precio, p.descripcion, p.categoria, p.condicion, p.unidad, p.cantidad_minima, p.stock FROM producto_favoritos f INNER JOIN productos p ON p.id = f.producto_id WHERE f.usuario_id = :usuario_id AND p.estado = 'aprobado' ORDER BY f.creado_en DESC"
    );
    $query->execute(['usuario_id' => $user['id']]);
    return view($renderer, $response, '/productos/favoritos.php', [
      'productos' => attachProductImages($pdo, $query->fetchAll()),
      'categorias' => productCategories(),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudieron cargar tus favoritos.');
  }
});

$app->post('/productos/{id}/reportar', function ($request, $response, array $args) use ($requireCsrf, $renderError, $loginUrl) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  $data = $request->getParsedBody() ?? [];
  $reason = (string) ($data['motivo'] ?? '');
  $detail = trim((string) ($data['detalle'] ?? ''));
  $reasons = ['producto_distinto', 'informacion_falsa', 'fraude', 'categoria_incorrecta', 'otro'];
  if ($id === false || $id < 1 || !in_array($reason, $reasons, true) || mb_strlen($detail) < 10 || mb_strlen($detail) > 500) {
    $_SESSION['flash'] = 'Elegí un motivo válido y explicalo en al menos 10 caracteres.';
    return $response->withHeader('Location', '/productos/' . max(1, (int) $id) . '#reportar')->withStatus(303);
  }
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/productos/' . $id))->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $product = $pdo->prepare("SELECT id FROM productos WHERE id = :id AND estado = 'aprobado'");
    $product->execute(['id' => $id]);
    if ($product->fetchColumn() === false) {
      return $renderError($response, 404, 'No encontramos ese producto.');
    }

    $existing = $pdo->prepare('SELECT id FROM producto_reportes WHERE producto_id = :producto_id AND usuario_id = :usuario_id AND estado = \'pendiente\'');
    $existing->execute(['producto_id' => $id, 'usuario_id' => $user['id']]);
    if ($existing->fetchColumn() !== false) {
      $_SESSION['flash'] = 'Ya enviaste un reporte de esta publicación; el equipo lo revisará.';
      return $response->withHeader('Location', '/productos/' . $id)->withStatus(303);
    }
    $insert = $pdo->prepare(
      'INSERT INTO producto_reportes (producto_id, usuario_id, motivo, detalle) VALUES (:producto_id, :usuario_id, :motivo, :detalle)'
    );
    $insert->execute([
      'producto_id' => $id,
      'usuario_id' => $user['id'],
      'motivo' => $reason,
      'detalle' => $detail,
    ]);
    $pdo->prepare('UPDATE productos SET reportes = reportes + 1 WHERE id = :id')->execute(['id' => $id]);
    $_SESSION['flash'] = 'Gracias por avisarnos. El equipo revisará esta publicación.';
    return $response->withHeader('Location', '/productos/' . $id)->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo enviar el reporte.');
  }
});

$app->post('/productos/{id}/favorito', function ($request, $response, array $args) use ($requireCsrf, $loginUrl, $renderError) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/productos/' . max(1, (int) $id)))->withStatus(303);
  }
  if ($id === false || $id < 1) {
    return $renderError($response, 404, 'No encontramos ese producto.');
  }

  try {
    $pdo = (new Database())->getConnection();
    $check = $pdo->prepare("SELECT id FROM productos WHERE id = :id AND estado = 'aprobado'");
    $check->execute(['id' => $id]);
    if ($check->fetchColumn() === false) {
      return $renderError($response, 404, 'No encontramos ese producto.');
    }
    $delete = $pdo->prepare('DELETE FROM producto_favoritos WHERE usuario_id = :usuario_id AND producto_id = :producto_id');
    $delete->execute(['usuario_id' => $user['id'], 'producto_id' => $id]);
    if ($delete->rowCount() === 0) {
      $insert = $pdo->prepare('INSERT INTO producto_favoritos (usuario_id, producto_id) VALUES (:usuario_id, :producto_id)');
      $insert->execute(['usuario_id' => $user['id'], 'producto_id' => $id]);
      $_SESSION['flash'] = 'Producto guardado en tus favoritos.';
    } else {
      $_SESSION['flash'] = 'Producto quitado de tus favoritos.';
    }
    return $response->withHeader('Location', '/productos/' . $id)->withStatus(303);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo actualizar tu lista de favoritos.');
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
    'localidad' => trim((string) ($data['localidad'] ?? '')),
    'provincia' => trim((string) ($data['provincia'] ?? '')),
    'codigo_postal' => trim((string) ($data['codigo_postal'] ?? '')),
  ];
  $password = (string) ($data['password'] ?? '');
  $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

  if (
    $user['nombre'] === ''
    || $user['gmail'] === ''
    || $user['telefono'] === ''
    || $user['direccion'] === ''
    || !filter_var($user['gmail'], FILTER_VALIDATE_EMAIL)
    || strlen($user['nombre']) > 255
    || strlen($user['gmail']) > 255
    || strlen($user['telefono']) > 30
    || strlen($user['direccion']) > 255
    || strlen($user['localidad']) > 100
    || strlen($user['provincia']) > 100
    || strlen($user['codigo_postal']) > 12
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
      'INSERT INTO usuarios (nombre, rol, gmail, telefono, direccion, localidad, provincia, codigo_postal, password_hash) VALUES (:nombre, :rol, :gmail, :telefono, :direccion, :localidad, :provincia, :codigo_postal, :password_hash)'
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
      'SELECT id, nombre, gmail, password_hash, estado FROM usuarios WHERE gmail = :gmail'
    );
    $stmt->execute(['gmail' => $gmail]);
    $user = $stmt->fetch();

    if (
      $user === false
      || $user['estado'] !== 'activo'
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
      'SELECT nombre, gmail, telefono, direccion, localidad, provincia, codigo_postal, descripcion_vendedor FROM usuarios WHERE id = :id'
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
    'localidad' => trim((string) ($data['localidad'] ?? '')),
    'provincia' => trim((string) ($data['provincia'] ?? '')),
    'codigo_postal' => trim((string) ($data['codigo_postal'] ?? '')),
    'descripcion_vendedor' => trim((string) ($data['descripcion_vendedor'] ?? '')),
  ];
  $password = (string) ($data['password'] ?? '');

  if (
    $profile['nombre'] === ''
    || $profile['gmail'] === ''
    || $profile['telefono'] === ''
    || $profile['direccion'] === ''
    || !filter_var($profile['gmail'], FILTER_VALIDATE_EMAIL)
    || strlen($profile['nombre']) > 255
    || strlen($profile['gmail']) > 255
    || strlen($profile['telefono']) > 30
    || strlen($profile['direccion']) > 255
    || strlen($profile['localidad']) > 100
    || strlen($profile['provincia']) > 100
    || strlen($profile['codigo_postal']) > 12
    || strlen($profile['descripcion_vendedor']) > 500
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

    $sql = 'UPDATE usuarios SET nombre = :nombre, gmail = :gmail, telefono = :telefono, direccion = :direccion, localidad = :localidad, provincia = :provincia, codigo_postal = :codigo_postal, descripcion_vendedor = :descripcion_vendedor';
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
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->prepare(
      'SELECT id, nombre, precio, descripcion, categoria, condicion, unidad, cantidad_minima, stock, estado, motivo_revision FROM productos WHERE usuario_id = :usuario_id ORDER BY id DESC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $productos = attachProductImages($pdo, $stmt->fetchAll());
    return view($renderer, $response, '/productos/mis-productos.php', [
      'productos' => $productos,
      'categorias' => productCategories(),
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
    'categoria' => trim((string) ($data['categoria'] ?? '')),
    'condicion' => trim((string) ($data['condicion'] ?? '')),
    'unidad' => trim((string) ($data['unidad'] ?? '')),
    'cantidad_minima' => trim((string) ($data['cantidad_minima'] ?? '')),
    'stock' => trim((string) ($data['stock'] ?? '')),
  ];
  $categories = productCategories();
  $uploads = $request->getUploadedFiles()['imagenes'] ?? [];

  if (!isValidProductPublication($product, ($data['producto_real'] ?? '') === '1')) {
    return view($renderer, $response->withStatus(422), '/productos/create/create.php', [
      'error' => 'Revisá los datos: usá un nombre de al menos 3 caracteres, una descripción de 30 caracteres y 4 palabras como mínimo, un precio mayor a cero, una categoría válida y confirmá que es un producto real.',
      'producto' => $product,
      'categorias' => $categories,
    ]);
  }

  $savedImages = [];
  try {
    $pdo = (new Database())->getConnection();
    $pdo->beginTransaction();
    $accountLock = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
    $accountLock->execute(['id' => $user['id']]);
    if ($accountLock->fetchColumn() === false) {
      $pdo->rollBack();
      return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
    }

    enforceProductPublishingLimit($pdo, (int) $user['id']);
    $duplicate = $pdo->prepare(
      'SELECT id FROM productos WHERE usuario_id = :usuario_id AND LOWER(nombre) = LOWER(:nombre)'
    );
    $duplicate->execute(['usuario_id' => $user['id'], 'nombre' => $product['nombre']]);
    if ($duplicate->fetchColumn() !== false) {
      $pdo->rollBack();
      return view($renderer, $response->withStatus(409), '/productos/create/create.php', [
        'error' => 'Ya tenés un producto con ese nombre. Editá la publicación existente en lugar de duplicarla.',
        'producto' => $product,
        'categorias' => $categories,
      ]);
    }

    $imageFiles = validateProductImageUploads($uploads);
    if ($imageFiles === []) {
      $pdo->rollBack();
      return view($renderer, $response->withStatus(422), '/productos/create/create.php', [
        'error' => 'Subí al menos una imagen del producto para poder publicarlo.',
        'producto' => $product,
        'categorias' => $categories,
      ]);
    }
    $savedImages = storeProductImages($imageFiles);
    $stmt = $pdo->prepare(
      "INSERT INTO productos (nombre, precio, descripcion, categoria, condicion, unidad, cantidad_minima, stock, usuario_id, estado, busquedas) VALUES (:nombre, :precio, :descripcion, :categoria, :condicion, :unidad, :cantidad_minima, :stock, :usuario_id, 'pendiente', 0)"
    );
    $stmt->execute([...$product, 'usuario_id' => $user['id']]);
    $productId = (int) $pdo->lastInsertId();
    insertProductImages($pdo, $productId, $savedImages);
    $pdo->commit();
    $_SESSION['flash'] = 'Tu producto se envió y quedó pendiente de revisión antes de aparecer en el catálogo.';
    return $response->withHeader('Location', '/mis-productos')->withStatus(303);
  } catch (ProductImageException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    deleteProductImageFiles($savedImages);
    return view($renderer, $response->withStatus(422), '/productos/create/create.php', [
      'error' => $exception->getMessage(),
      'producto' => $product,
      'categorias' => $categories,
    ]);
  } catch (ProductPublishingLimitException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    return view($renderer, $response->withStatus(429), '/productos/create/create.php', [
      'error' => $exception->getMessage(),
      'producto' => $product,
      'categorias' => $categories,
    ]);
  } catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    deleteProductImageFiles($savedImages);
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/create/create.php', [
      'error' => 'No se pudo guardar el producto. Intentá nuevamente más tarde.',
      'producto' => $product,
      'categorias' => $categories,
    ]);
  } catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    deleteProductImageFiles($savedImages);
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/create/create.php', [
      'error' => 'No se pudieron procesar las imágenes o guardar el producto.',
      'producto' => $product,
      'categorias' => $categories,
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
    'categoria' => trim((string) ($data['categoria'] ?? '')),
    'condicion' => trim((string) ($data['condicion'] ?? '')),
    'unidad' => trim((string) ($data['unidad'] ?? '')),
    'cantidad_minima' => trim((string) ($data['cantidad_minima'] ?? '')),
    'stock' => trim((string) ($data['stock'] ?? '')),
  ];
  $id = (int) $args['id'];
  $categories = productCategories();
  $uploads = $request->getUploadedFiles()['imagenes'] ?? [];

  if (!isValidProductPublication($product, ($data['producto_real'] ?? '') === '1')) {
    return view($renderer, $response->withStatus(422), '/productos/update/update.php', [
      'error' => 'Revisá los datos: usá un nombre de al menos 3 caracteres, una descripción de 30 caracteres y 4 palabras como mínimo, un precio mayor a cero, una categoría válida y confirmá que es un producto real.',
      'producto' => [...$product, 'id' => $id],
      'categorias' => $categories,
    ]);
  }

  $savedImages = [];
  $currentImages = [];
  try {
    $pdo = (new Database())->getConnection();
    $productStmt = $pdo->prepare(
      'SELECT id FROM productos WHERE id = :id AND usuario_id = :usuario_id'
    );
    $productStmt->execute(['id' => $id, 'usuario_id' => $user['id']]);
    if ($productStmt->fetchColumn() === false) {
      return $response->withHeader('Location', '/mis-productos')->withStatus(303);
    }

    $currentImages = productImages($pdo, $id);
    $currentPaths = array_column($currentImages, 'ruta');
    $requestedRemovals = $data['eliminar_imagenes'] ?? [];
    if (!is_array($requestedRemovals)) {
      $requestedRemovals = [];
    }
    $removePaths = array_values(array_intersect($currentPaths, array_map('strval', $requestedRemovals)));
    $keptImages = array_values(array_filter(
      $currentImages,
      static fn(array $image): bool => !in_array($image['ruta'], $removePaths, true)
    ));
    $imageFiles = validateProductImageUploads($uploads, count($keptImages));
    if ($keptImages === [] && $imageFiles === []) {
      return view($renderer, $response->withStatus(422), '/productos/update/update.php', [
        'error' => 'El producto debe conservar o tener al menos una imagen.',
        'producto' => [...$product, 'id' => $id, 'imagenes' => $currentImages],
        'categorias' => $categories,
      ]);
    }

    $savedImages = storeProductImages($imageFiles);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
      "UPDATE productos SET nombre = :nombre, precio = :precio, descripcion = :descripcion, categoria = :categoria, condicion = :condicion, unidad = :unidad, cantidad_minima = :cantidad_minima, stock = :stock, estado = 'pendiente', motivo_revision = NULL WHERE id = :id AND usuario_id = :usuario_id"
    );
    $stmt->execute([...$product, 'id' => $id, 'usuario_id' => $user['id']]);
    if ($removePaths !== [] || $savedImages !== []) {
      $pdo->prepare('DELETE FROM producto_imagenes WHERE producto_id = :producto_id')
        ->execute(['producto_id' => $id]);
      insertProductImages($pdo, $id, [...$keptImages, ...$savedImages]);
    }
    $pdo->commit();
    deleteProductImageFiles(array_map(
      static fn(string $path): array => ['ruta' => $path],
      $removePaths
    ));
    $_SESSION['flash'] = 'Los cambios se guardaron y el producto quedó pendiente de revisión.';
    return $response->withHeader('Location', '/mis-productos')->withStatus(303);
  } catch (ProductImageException $exception) {
    deleteProductImageFiles($savedImages);
    return view($renderer, $response->withStatus(422), '/productos/update/update.php', [
      'error' => $exception->getMessage(),
      'producto' => [...$product, 'id' => $id, 'imagenes' => $currentImages],
      'categorias' => $categories,
    ]);
  } catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    deleteProductImageFiles($savedImages);
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/update/update.php', [
      'error' => 'No se pudo actualizar el producto.',
      'producto' => [...$product, 'id' => $id, 'imagenes' => $currentImages],
      'categorias' => $categories,
    ]);
  } catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    deleteProductImageFiles($savedImages);
    error_log($exception->getMessage());
    return view($renderer, $response->withStatus(500), '/productos/update/update.php', [
      'error' => 'No se pudieron procesar las imágenes o actualizar el producto.',
      'producto' => [...$product, 'id' => $id, 'imagenes' => $currentImages],
      'categorias' => $categories,
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

  $pdo = (new Database())->getConnection();
  $id = (int) $args['id'];
  $images = productImages($pdo, $id);
  $stmt = $pdo->prepare(
    'DELETE FROM productos WHERE id = :id AND usuario_id = :usuario_id'
  );
  $stmt->execute(['id' => $id, 'usuario_id' => $user['id']]);
  if ($stmt->rowCount() > 0) {
    deleteProductImageFiles($images);
  }
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
    $product = $pdo->prepare("SELECT id, usuario_id FROM productos WHERE id = :id AND estado = 'aprobado'");
    $product->execute(['id' => $productId]);
    $availableProduct = $product->fetch();
    if ($availableProduct === false) {
      $_SESSION['flash'] = 'Ese producto ya no está disponible.';
      return $response->withHeader('Location', '/')->withStatus(303);
    }
    if ((int) $availableProduct['usuario_id'] === (int) $user['id']) {
      $_SESSION['flash'] = 'No podés comprar tus propias publicaciones.';
      return $response->withHeader('Location', '/productos/' . $productId)->withStatus(303);
    }

    $availability = $pdo->prepare(
      'SELECT precio, cantidad_minima, stock FROM productos WHERE id = :id'
    );
    $availability->execute(['id' => $productId]);
    $productDetails = $availability->fetch();
    $existingQuantityQuery = $pdo->prepare(
      'SELECT cantidad FROM carrito_items WHERE usuario_id = :usuario_id AND producto_id = :producto_id'
    );
    $existingQuantityQuery->execute(['usuario_id' => $user['id'], 'producto_id' => $productId]);
    $totalQuantity = $quantity + (int) ($existingQuantityQuery->fetchColumn() ?: 0);
    if (
      $productDetails === false
      || $productDetails['stock'] === null
      || $totalQuantity < (int) $productDetails['cantidad_minima']
      || $totalQuantity > (int) $productDetails['stock']
    ) {
      $_SESSION['flash'] = $productDetails !== false && $productDetails['stock'] === null
        ? 'El vendedor debe confirmar el stock antes de habilitar pedidos.'
        : 'La cantidad debe respetar el mínimo de compra y el stock confirmado.';
      return $response->withHeader('Location', '/productos/' . $productId)->withStatus(303);
    }

    $stmt = $pdo->prepare(
      'INSERT INTO carrito_items (usuario_id, producto_id, cantidad) VALUES (:usuario_id, :producto_id, :cantidad) ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)'
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
      'SELECT c.id, c.cantidad, p.id AS producto_id, p.usuario_id AS vendedor_id, p.nombre, p.precio, p.descripcion, p.estado, p.stock, p.cantidad_minima, p.unidad FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id WHERE c.usuario_id = :usuario_id ORDER BY c.id DESC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    $hasUnavailableItems = count(array_filter(
      $items,
      static fn(array $item): bool => $item['estado'] !== 'aprobado'
        || (int) $item['vendedor_id'] === (int) $user['id']
        || $item['stock'] === null
        || (int) $item['stock'] < (int) $item['cantidad_minima']
        || (int) $item['cantidad'] < (int) $item['cantidad_minima']
        || (int) $item['cantidad'] > (int) $item['stock']
    )) > 0;
    $total = array_sum(array_map(
      static fn(array $item): float => $item['estado'] === 'aprobado' && (int) $item['vendedor_id'] !== (int) $user['id'] && $item['stock'] !== null && (int) $item['cantidad'] <= (int) $item['stock']
        ? (float) $item['precio'] * (int) $item['cantidad']
        : 0,
      $items
    ));

    return view($renderer, $response, '/carrito/index.php', [
      'items' => $items,
      'total' => $total,
      'hasUnavailableItems' => $hasUnavailableItems,
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

  $pdo = (new Database())->getConnection();
  $product = $pdo->prepare(
    'SELECT p.stock, p.cantidad_minima FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id WHERE c.id = :id AND c.usuario_id = :usuario_id'
  );
  $product->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
  $limits = $product->fetch();
  if (
    $limits === false || $limits['stock'] === null
    || $quantity < (int) $limits['cantidad_minima']
    || $quantity > (int) $limits['stock']
  ) {
    $_SESSION['flash'] = 'La cantidad debe respetar la compra mínima y el stock disponible.';
    return $response->withHeader('Location', '/carrito')->withStatus(303);
  }
  $stmt = $pdo->prepare('UPDATE carrito_items SET cantidad = :cantidad WHERE id = :id AND usuario_id = :usuario_id');
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

$app->get('/compras/checkout', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/carrito'))->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $profileStmt = $pdo->prepare(
      'SELECT nombre, telefono, direccion, localidad, provincia, codigo_postal FROM usuarios WHERE id = :id'
    );
    $profileStmt->execute(['id' => $user['id']]);
    $profile = $profileStmt->fetch();
    $itemsStmt = $pdo->prepare(
      'SELECT c.cantidad, p.nombre, p.precio, p.stock, p.cantidad_minima, p.estado, p.usuario_id AS vendedor_id FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id WHERE c.usuario_id = :usuario_id'
    );
    $itemsStmt->execute(['usuario_id' => $user['id']]);
    $items = $itemsStmt->fetchAll();

    if ($profile === false || $items === []) {
      $_SESSION['flash'] = 'Agregá productos al carrito y completá tus datos de envío antes de continuar.';
      return $response->withHeader('Location', '/carrito')->withStatus(303);
    }

    $hasUnavailableItems = count(array_filter(
      $items,
      static fn(array $item): bool => $item['estado'] !== 'aprobado'
        || (int) $item['vendedor_id'] === (int) $user['id']
        || $item['stock'] === null
        || (int) $item['cantidad'] > (int) $item['stock']
        || (int) $item['cantidad'] < (int) $item['cantidad_minima']
    )) > 0;

    return view($renderer, $response, '/compras/checkout.php', [
      'profile' => $profile,
      'hasUnavailableItems' => $hasUnavailableItems,
      'items' => $items,
      'total' => array_sum(array_map(
        static fn(array $item): float => (float) $item['precio'] * (int) $item['cantidad'],
        $items
      )),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo preparar el pedido.');
  }
});

$app->post('/compras/confirmar', function ($request, $response) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $data = $request->getParsedBody() ?? [];
  $destination = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'telefono' => trim((string) ($data['telefono'] ?? '')),
    'direccion' => trim((string) ($data['direccion'] ?? '')),
    'localidad' => trim((string) ($data['localidad'] ?? '')),
    'provincia' => trim((string) ($data['provincia'] ?? '')),
    'codigo_postal' => trim((string) ($data['codigo_postal'] ?? '')),
  ];
  if (
    in_array('', $destination, true)
    || strlen($destination['nombre']) > 255
    || strlen($destination['telefono']) > 30
    || strlen($destination['direccion']) > 255
    || strlen($destination['localidad']) > 100
    || strlen($destination['provincia']) > 100
    || strlen($destination['codigo_postal']) > 12
  ) {
    $_SESSION['flash'] = 'Completá todos los datos de envío con valores válidos.';
    return $response->withHeader('Location', '/compras/checkout')->withStatus(303);
  }

  $pdo = (new Database())->getConnection();
  try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
      "SELECT c.producto_id, c.cantidad, p.nombre, p.precio, p.usuario_id, p.estado, p.stock, p.cantidad_minima, p.unidad, COALESCE(u.nombre, 'Vendedor no disponible') AS vendedor_nombre FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE c.usuario_id = :usuario_id ORDER BY p.id FOR UPDATE"
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    if ($items === []) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Tu carrito está vacío.';
      return $response->withHeader('Location', '/carrito')->withStatus(303);
    }
    $currentUserId = (int) $user['id'];
    if (array_filter(
      $items,
      static fn(array $item): bool => $item['estado'] !== 'aprobado'
        || $item['stock'] === null
        || (int) $item['cantidad'] < (int) $item['cantidad_minima']
        || (int) $item['cantidad'] > (int) $item['stock']
        || (int) $item['usuario_id'] === $currentUserId
    ) !== []) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Quitá del carrito los productos sin stock confirmado, propios o que ya no están disponibles.';
      return $response->withHeader('Location', '/carrito')->withStatus(303);
    }

    $itemsBySeller = [];
    foreach ($items as $item) {
      $itemsBySeller[(int) $item['usuario_id']][] = $item;
    }
    $order = $pdo->prepare(
      "INSERT INTO compras (usuario_id, estado, total, moneda, estado_pago, envio_proveedor, envio_estado, destino_nombre, destino_direccion, destino_localidad, destino_provincia, destino_codigo_postal, destino_telefono) VALUES (:usuario_id, 'pendiente_envio', :total, 'ARS', 'sin_pago', 'correo_argentino', 'cotizacion_pendiente', :destino_nombre, :destino_direccion, :destino_localidad, :destino_provincia, :destino_codigo_postal, :destino_telefono)"
    );
    $insertItem = $pdo->prepare(
      'INSERT INTO compra_items (compra_id, producto_id, vendedor_id, nombre_producto, nombre_vendedor, precio_unitario, cantidad, producto_unidad) VALUES (:compra_id, :producto_id, :vendedor_id, :nombre_producto, :nombre_vendedor, :precio_unitario, :cantidad, :producto_unidad)'
    );
    $reserveStock = $pdo->prepare(
      "UPDATE productos SET stock = stock - :cantidad WHERE id = :id AND estado = 'aprobado' AND stock >= :minimum_stock"
    );
    $notifySeller = $pdo->prepare(
      "INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (:usuario_id, 'pedido', :mensaje, '/ventas')"
    );

    foreach ($itemsBySeller as $sellerId => $sellerItems) {
      $sellerTotal = array_sum(array_map(
        static fn(array $item): float => (float) $item['precio'] * (int) $item['cantidad'],
        $sellerItems
      ));
      $order->execute([
        'usuario_id' => $user['id'],
        'total' => number_format($sellerTotal, 2, '.', ''),
        'destino_nombre' => $destination['nombre'],
        'destino_direccion' => $destination['direccion'],
        'destino_localidad' => $destination['localidad'],
        'destino_provincia' => $destination['provincia'],
        'destino_codigo_postal' => $destination['codigo_postal'],
        'destino_telefono' => $destination['telefono'],
      ]);
      $orderId = (int) $pdo->lastInsertId();

      foreach ($sellerItems as $item) {
        $reserveStock->execute([
          'cantidad' => (int) $item['cantidad'],
          'minimum_stock' => (int) $item['cantidad'],
          'id' => (int) $item['producto_id'],
        ]);
        if ($reserveStock->rowCount() !== 1) {
          throw new RuntimeException('El stock cambió mientras se confirmaba el pedido.');
        }
        $insertItem->execute([
          'compra_id' => $orderId,
          'producto_id' => $item['producto_id'],
          'vendedor_id' => $sellerId,
          'nombre_producto' => $item['nombre'],
          'nombre_vendedor' => $item['vendedor_nombre'],
          'precio_unitario' => $item['precio'],
          'cantidad' => $item['cantidad'],
          'producto_unidad' => $item['unidad'],
        ]);
      }
      $notifySeller->execute([
        'usuario_id' => $sellerId,
        'mensaje' => 'Recibiste el pedido #' . $orderId . '. Cotizá el envío desde Ventas.',
      ]);
    }

    $clearCart = $pdo->prepare('DELETE FROM carrito_items WHERE usuario_id = :usuario_id');
    $clearCart->execute(['usuario_id' => $user['id']]);
    $pdo->commit();
    $_SESSION['flash'] = 'Pedido registrado. El vendedor debe cotizar el envío; todavía no se realizó ningún pago.';
    return $response->withHeader('Location', '/mis-compras')->withStatus(303);
  } catch (Throwable $exception) {
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
      'SELECT c.id AS compra_id, c.estado, c.total, c.creado_en, c.estado_pago, c.envio_estado, c.envio_costo, c.envio_tracking, c.mp_preference_id, i.id AS compra_item_id, i.producto_id, i.nombre_producto, i.nombre_vendedor, i.precio_unitario, i.cantidad, i.producto_unidad, r.id AS resena_id FROM compras c LEFT JOIN compra_items i ON i.compra_id = c.id LEFT JOIN producto_resenas r ON r.compra_item_id = i.id WHERE c.usuario_id = :usuario_id ORDER BY c.id DESC, i.id ASC'
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
          'estado_pago' => $row['estado_pago'],
          'envio_estado' => $row['envio_estado'],
          'envio_costo' => $row['envio_costo'],
          'envio_tracking' => $row['envio_tracking'],
          'puede_pagar' => mercadoPagoConfiguration() !== null,
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

$app->post('/compras/items/{id}/resena', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }
  $data = $request->getParsedBody() ?? [];
  $score = filter_var($data['puntaje'] ?? null, FILTER_VALIDATE_INT);
  $comment = trim((string) ($data['comentario'] ?? ''));
  if ($score === false || $score < 1 || $score > 5 || mb_strlen($comment) < 10 || mb_strlen($comment) > 1000) {
    $_SESSION['flash'] = 'La opinión debe incluir una puntuación y un comentario de entre 10 y 1000 caracteres.';
    return $response->withHeader('Location', '/mis-compras')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $itemQuery = $pdo->prepare(
      "SELECT i.id, i.producto_id, i.vendedor_id, c.id AS compra_id FROM compra_items i INNER JOIN compras c ON c.id = i.compra_id WHERE i.id = :id AND c.usuario_id = :usuario_id AND c.estado = 'entregado' AND i.producto_id IS NOT NULL"
    );
    $itemQuery->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
    $item = $itemQuery->fetch();
    if ($item === false) {
      $_SESSION['flash'] = 'Solo podés opinar sobre productos de pedidos entregados.';
      return $response->withHeader('Location', '/mis-compras')->withStatus(303);
    }

    $insert = $pdo->prepare(
      'INSERT INTO producto_resenas (compra_item_id, producto_id, comprador_id, vendedor_id, puntaje, comentario) VALUES (:compra_item_id, :producto_id, :comprador_id, :vendedor_id, :puntaje, :comentario)'
    );
    $insert->execute([
      'compra_item_id' => (int) $item['id'],
      'producto_id' => (int) $item['producto_id'],
      'comprador_id' => (int) $user['id'],
      'vendedor_id' => $item['vendedor_id'] === null ? null : (int) $item['vendedor_id'],
      'puntaje' => $score,
      'comentario' => $comment,
    ]);
    if ($item['vendedor_id'] !== null) {
      $pdo->prepare(
        "INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (:usuario_id, 'resena', :mensaje, '/mis-productos')"
      )->execute([
        'usuario_id' => (int) $item['vendedor_id'],
        'mensaje' => 'Recibiste una opinión verificada sobre una venta.',
      ]);
    }
    $_SESSION['flash'] = 'Gracias. Tu opinión verificada quedó publicada.';
  } catch (PDOException $exception) {
    if (($exception->errorInfo[1] ?? null) === 1062) {
      $_SESSION['flash'] = 'Ya dejaste una opinión para este producto de ese pedido.';
    } else {
      error_log($exception->getMessage());
      $_SESSION['flash'] = 'No se pudo guardar la opinión. Intentá nuevamente más tarde.';
    }
  }
  return $response->withHeader('Location', '/mis-compras')->withStatus(303);
});

$app->get('/notificaciones', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/notificaciones'))->withStatus(303);
  }
  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT id, tipo, mensaje, enlace, leida_en, creado_en FROM notificaciones WHERE usuario_id = :usuario_id ORDER BY id DESC LIMIT 100'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    return view($renderer, $response, '/notificaciones/index.php', [
      'notificaciones' => $stmt->fetchAll(),
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudieron cargar tus notificaciones.');
  }
});

$app->post('/notificaciones/leer', function ($request, $response) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }
  (new Database())->getConnection()->prepare(
    'UPDATE notificaciones SET leida_en = CURRENT_TIMESTAMP WHERE usuario_id = :usuario_id AND leida_en IS NULL'
  )->execute(['usuario_id' => $user['id']]);
  return $response->withHeader('Location', '/notificaciones')->withStatus(303);
});

$app->get('/ventas', function ($request, $response) use ($renderer, $loginUrl, $renderError) {
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', $loginUrl('/ventas'))->withStatus(303);
  }

  try {
    $stmt = (new Database())->getConnection()->prepare(
      'SELECT c.id, c.estado, c.total, c.creado_en, c.estado_pago, c.mp_preference_id, c.envio_estado, c.envio_costo, c.envio_tracking, c.destino_nombre, c.destino_direccion, c.destino_localidad, c.destino_provincia, c.destino_codigo_postal, c.destino_telefono, u.nombre AS comprador, GROUP_CONCAT(CONCAT(i.nombre_producto, \' × \', i.cantidad, \' \', i.producto_unidad) ORDER BY i.id SEPARATOR \', \') AS productos FROM compras c INNER JOIN compra_items i ON i.compra_id = c.id INNER JOIN usuarios u ON u.id = c.usuario_id WHERE i.vendedor_id = :vendedor_id GROUP BY c.id ORDER BY c.id DESC'
    );
    $stmt->execute(['vendedor_id' => $user['id']]);
    return view($renderer, $response, '/ventas/index.php', [
      'ventas' => $stmt->fetchAll(),
      'paymentConfigured' => mercadoPagoConfiguration() !== null,
    ]);
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudieron cargar tus ventas.');
  }
});

$app->post('/ventas/{id}/cotizar-envio', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }

  $rawCost = str_replace(',', '.', trim((string) (($request->getParsedBody() ?? [])['envio_costo'] ?? '')));
  $shippingCost = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $rawCost) === 1 ? (float) $rawCost : -1;
  if ($shippingCost < 0) {
    $_SESSION['flash'] = 'Ingresá una cotización de envío válida en pesos.';
    return $response->withHeader('Location', '/ventas')->withStatus(303);
  }

  $pdo = (new Database())->getConnection();
  try {
    $pdo->beginTransaction();
    $orderQuery = $pdo->prepare(
      "SELECT c.id, c.usuario_id, c.estado, c.total FROM compras c WHERE c.id = :id AND c.estado = 'pendiente_envio' AND EXISTS (SELECT 1 FROM compra_items i WHERE i.compra_id = c.id AND i.vendedor_id = :vendedor_id) FOR UPDATE"
    );
    $orderQuery->execute(['id' => (int) $args['id'], 'vendedor_id' => $user['id']]);
    $order = $orderQuery->fetch();
    if ($order === false) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'No encontramos un pedido pendiente de cotización para tu cuenta.';
      return $response->withHeader('Location', '/ventas')->withStatus(303);
    }

    $update = $pdo->prepare(
      "UPDATE compras SET envio_costo = :envio_costo, total = total + :envio_total, estado = 'pendiente_pago', envio_estado = 'cotizado_manual' WHERE id = :id"
    );
    $formattedCost = number_format($shippingCost, 2, '.', '');
    $update->execute([
      'envio_costo' => $formattedCost,
      'envio_total' => $formattedCost,
      'id' => (int) $order['id'],
    ]);
    $notify = $pdo->prepare(
      "INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (:usuario_id, 'envio', :mensaje, '/mis-compras')"
    );
    $notify->execute([
      'usuario_id' => (int) $order['usuario_id'],
      'mensaje' => 'El vendedor agregó la cotización manual del envío al pedido #' . (int) $order['id'] . '.',
    ]);
    $pdo->commit();
    $_SESSION['flash'] = 'Cotización registrada. El comprador podrá pagar cuando Mercado Pago esté configurado.';
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo guardar la cotización del envío.';
  }
  return $response->withHeader('Location', '/ventas')->withStatus(303);
});

$app->post('/ventas/{id}/estado', function ($request, $response, array $args) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }
  $data = $request->getParsedBody() ?? [];
  $nextState = (string) ($data['estado'] ?? '');
  $tracking = trim((string) ($data['tracking'] ?? ''));
  $pdo = (new Database())->getConnection();

  try {
    $pdo->beginTransaction();
    $query = $pdo->prepare(
      'SELECT c.id, c.usuario_id, c.estado, c.estado_pago, c.mp_preference_id FROM compras c WHERE c.id = :id AND EXISTS (SELECT 1 FROM compra_items i WHERE i.compra_id = c.id AND i.vendedor_id = :vendedor_id) FOR UPDATE'
    );
    $query->execute(['id' => (int) $args['id'], 'vendedor_id' => $user['id']]);
    $order = $query->fetch();
    if ($order === false) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'No encontramos ese pedido entre tus ventas.';
      return $response->withHeader('Location', '/ventas')->withStatus(303);
    }

    $newOrderState = null;
    $newShippingState = null;
    if ($nextState === 'preparando' && $order['estado'] === 'pendiente_pago' && $order['estado_pago'] === 'aprobado') {
      $newOrderState = 'preparando';
      $newShippingState = 'preparando';
    } elseif ($nextState === 'enviado' && $order['estado'] === 'preparando' && $tracking !== '' && strlen($tracking) <= 100) {
      $newOrderState = 'enviado';
      $newShippingState = 'despachado';
    } elseif ($nextState === 'entregado' && $order['estado'] === 'enviado') {
      $newOrderState = 'entregado';
      $newShippingState = 'entregado';
    } elseif (
      $nextState === 'cancelado'
      && in_array($order['estado'], ['pendiente_envio', 'pendiente_pago'], true)
      && $order['estado_pago'] !== 'aprobado'
      && $order['mp_preference_id'] === null
    ) {
      $itemQuery = $pdo->prepare(
        'SELECT producto_id, cantidad FROM compra_items WHERE compra_id = :compra_id AND producto_id IS NOT NULL FOR UPDATE'
      );
      $itemQuery->execute(['compra_id' => (int) $order['id']]);
      $restoreStock = $pdo->prepare('UPDATE productos SET stock = stock + :cantidad WHERE id = :id');
      foreach ($itemQuery->fetchAll() as $item) {
        $restoreStock->execute(['cantidad' => (int) $item['cantidad'], 'id' => (int) $item['producto_id']]);
      }
      $pdo->prepare("UPDATE compras SET estado = 'cancelado', envio_estado = 'cancelado' WHERE id = :id")
        ->execute(['id' => (int) $order['id']]);
      $newOrderState = 'cancelado';
      $newShippingState = 'cancelado';
    }

    if ($newOrderState === null) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Ese cambio de estado no está permitido para el pedido.';
      return $response->withHeader('Location', '/ventas')->withStatus(303);
    }

    if ($newOrderState !== 'cancelado') {
      $update = $pdo->prepare(
        'UPDATE compras SET estado = :estado, envio_estado = :envio_estado, envio_tracking = COALESCE(:tracking, envio_tracking) WHERE id = :id'
      );
      $update->execute([
        'estado' => $newOrderState,
        'envio_estado' => $newShippingState,
        'tracking' => $tracking !== '' ? $tracking : null,
        'id' => (int) $order['id'],
      ]);
    }
    $notify = $pdo->prepare(
      "INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) VALUES (:usuario_id, 'pedido', :mensaje, '/mis-compras')"
    );
    $notify->execute([
      'usuario_id' => (int) $order['usuario_id'],
      'mensaje' => 'El pedido #' . (int) $order['id'] . ' cambió a ' . $newOrderState . '.',
    ]);
    $pdo->commit();
    $_SESSION['flash'] = 'Estado del pedido actualizado.';
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo actualizar el estado del pedido.';
  }
  return $response->withHeader('Location', '/ventas')->withStatus(303);
});

$app->post('/compras/{id}/pagar', function ($request, $response, array $args) use ($requireCsrf, $renderError) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }
  $user = currentUser();
  if ($user === null) {
    return $response->withHeader('Location', '/usuarios/login')->withStatus(303);
  }
  $config = mercadoPagoConfiguration();
  if ($config === null) {
    $_SESSION['flash'] = 'Los pagos están deshabilitados: faltan credenciales de Mercado Pago o una URL HTTPS pública.';
    return $response->withHeader('Location', '/mis-compras')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $orderQuery = $pdo->prepare(
      "SELECT id, total, mp_preference_id, mp_init_point FROM compras WHERE id = :id AND usuario_id = :usuario_id AND estado = 'pendiente_pago' AND estado_pago <> 'aprobado' AND envio_costo IS NOT NULL"
    );
    $orderQuery->execute(['id' => (int) $args['id'], 'usuario_id' => $user['id']]);
    $order = $orderQuery->fetch();
    if ($order === false) {
      $_SESSION['flash'] = 'El pedido todavía no está listo para pagar.';
      return $response->withHeader('Location', '/mis-compras')->withStatus(303);
    }
    if (is_string($order['mp_init_point']) && $order['mp_init_point'] !== '') {
      return $response->withHeader('Location', $order['mp_init_point'])->withStatus(303);
    }
    $itemsQuery = $pdo->prepare(
      'SELECT nombre_producto, precio_unitario, cantidad FROM compra_items WHERE compra_id = :id'
    );
    $itemsQuery->execute(['id' => (int) $order['id']]);
    $paymentItems = $itemsQuery->fetchAll();
    $items = array_map(static fn(array $item): array => [
      'title' => (string) $item['nombre_producto'],
      'quantity' => (int) $item['cantidad'],
      'unit_price' => (float) $item['precio_unitario'],
      'currency_id' => 'ARS',
    ], $paymentItems);
    $shippingCost = (float) $order['total'] - array_sum(array_map(
      static fn(array $item): float => (float) $item['precio_unitario'] * (int) $item['cantidad'],
      $paymentItems
    ));
    if ($shippingCost > 0) {
    $items[] = [
      'title' => 'Envío por Correo Argentino (cotización manual)',
      'quantity' => 1,
      'unit_price' => $shippingCost,
      'currency_id' => 'ARS',
    ];
    }
    $preference = mercadoPagoRequest('POST', 'https://api.mercadopago.com/checkout/preferences', $config['token'], [
      'items' => $items,
      'external_reference' => (string) $order['id'],
      'notification_url' => $config['app_url'] . '/integraciones/mercadopago/webhook',
      'back_urls' => [
        'success' => $config['app_url'] . '/mis-compras',
        'pending' => $config['app_url'] . '/mis-compras',
        'failure' => $config['app_url'] . '/mis-compras',
      ],
      'statement_descriptor' => 'EMPRENDI',
      'metadata' => ['compra_id' => (int) $order['id']],
    ]);
    if (!isset($preference['id'], $preference['init_point'])) {
      throw new RuntimeException('Mercado Pago no devolvió el enlace de pago.');
    }
    $pdo->prepare('UPDATE compras SET mp_preference_id = :preference, mp_init_point = :init_point WHERE id = :id')
      ->execute([
        'preference' => $preference['id'],
        'init_point' => $preference['init_point'],
        'id' => (int) $order['id'],
      ]);
    return $response->withHeader('Location', (string) $preference['init_point'])->withStatus(303);
  } catch (Throwable $exception) {
    error_log($exception->getMessage());
    return $renderError($response, 502, 'No se pudo iniciar el pago. No se realizó ningún cobro; intentá nuevamente más tarde.');
  }
});

$app->post('/integraciones/mercadopago/webhook', function ($request, $response) use ($renderError) {
  $config = mercadoPagoConfiguration();
  $query = $request->getQueryParams();
  $dataId = (string) ($query['data.id'] ?? ($query['data']['id'] ?? ''));
  $signature = $request->getHeaderLine('x-signature');
  $requestId = $request->getHeaderLine('x-request-id');
  if (
    $config === null
    || preg_match('/^\d+$/D', $dataId) !== 1
    || !mercadoPagoSignatureIsValid($signature, $requestId, $dataId, $config['secret'])
  ) {
    return $response->withStatus(401);
  }

  try {
    $payment = mercadoPagoRequest(
      'GET',
      'https://api.mercadopago.com/v1/payments/' . rawurlencode($dataId),
      $config['token']
    );
    $orderId = filter_var($payment['external_reference'] ?? null, FILTER_VALIDATE_INT);
    if (
      $orderId === false
      || (string) ($payment['currency_id'] ?? '') !== 'ARS'
      || !in_array((string) ($payment['status'] ?? ''), ['approved', 'rejected', 'cancelled', 'pending', 'in_process'], true)
    ) {
      return $response->withStatus(400);
    }

    $pdo = (new Database())->getConnection();
    $pdo->beginTransaction();
    $queryOrder = $pdo->prepare('SELECT id, total, estado, estado_pago, mp_preference_id FROM compras WHERE id = :id FOR UPDATE');
    $queryOrder->execute(['id' => $orderId]);
    $order = $queryOrder->fetch();
    if (
      $order === false
      || !in_array((string) $order['estado'], ['pendiente_pago', 'pagado', 'preparando', 'enviado', 'entregado'], true)
      || (string) ($payment['preference_id'] ?? '') !== (string) $order['mp_preference_id']
      || !is_numeric($payment['transaction_amount'] ?? null)
      || (int) round((float) $payment['transaction_amount'] * 100) !== (int) round((float) $order['total'] * 100)
    ) {
      $pdo->rollBack();
      return $response->withStatus(400);
    }

    $eventId = $dataId . ':' . (string) $payment['status'];
    $eventExists = $pdo->prepare('SELECT 1 FROM mercado_pago_eventos WHERE evento_id = :evento_id');
    $eventExists->execute(['evento_id' => $eventId]);
    if ($eventExists->fetchColumn() !== false) {
      $pdo->commit();
      return $response->withStatus(200);
    }
    $pdo->prepare('INSERT INTO mercado_pago_eventos (evento_id, tipo) VALUES (:evento_id, :tipo)')
      ->execute(['evento_id' => $eventId, 'tipo' => 'payment.' . (string) $payment['status']]);
    if ($payment['status'] === 'approved' && $order['estado_pago'] !== 'aprobado') {
      $pdo->prepare(
        "UPDATE compras SET estado_pago = 'aprobado', estado = 'pagado', mp_payment_id = :payment_id WHERE id = :id"
      )->execute(['payment_id' => $dataId, 'id' => $orderId]);
      $pdo->prepare(
        "INSERT INTO notificaciones (usuario_id, tipo, mensaje, enlace) SELECT usuario_id, 'pago', :mensaje, '/mis-compras' FROM compras WHERE id = :id"
      )->execute(['mensaje' => 'El pago del pedido #' . $orderId . ' fue aprobado.', 'id' => $orderId]);
    } elseif (in_array($payment['status'], ['rejected', 'cancelled'], true)) {
      $pdo->prepare('UPDATE compras SET estado_pago = :estado_pago WHERE id = :id AND estado_pago <> \'aprobado\'')
        ->execute(['estado_pago' => (string) $payment['status'], 'id' => $orderId]);
    }
    $pdo->commit();
    return $response->withStatus(200);
  } catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    return $renderError($response, 500, 'No se pudo procesar la notificación de pago.');
  }
});

$renderAdminPanel = static function ($response, bool $includeData = true, array $viewData = []) use ($renderer, $renderError) {
  $productos = [];
  $usuarios = [];
  $reportes = [];
  if ($includeData) {
    try {
      $pdo = (new Database())->getConnection();
      $productos = attachProductImages($pdo, $pdo->query(
        "SELECT p.id, p.nombre, p.precio, p.descripcion, p.categoria, p.creado_en, p.usuario_id, p.motivo_revision, u.nombre AS vendedor FROM productos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.estado = 'pendiente' ORDER BY p.creado_en ASC"
      )->fetchAll());
      $usuarios = $pdo->query(
        'SELECT id, nombre, gmail, telefono, direccion, estado FROM usuarios ORDER BY id DESC'
      )->fetchAll();
      $reportes = $pdo->query(
        "SELECT r.id, r.producto_id, r.usuario_id, r.motivo, r.detalle, r.creado_en, p.nombre AS producto, p.estado AS estado_producto, u.nombre AS reportante FROM producto_reportes r INNER JOIN productos p ON p.id = r.producto_id LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.estado = 'pendiente' ORDER BY r.creado_en ASC LIMIT 100"
      )->fetchAll();
    } catch (PDOException $exception) {
      error_log($exception->getMessage());
      return $renderError($response, 500, 'No se pudo cargar el panel de administración.');
    }
  }

  return view($renderer, $response, '/admin/index.php', [
    'adminPanel' => true,
    'adminAuthenticated' => ($_SESSION['admin_authenticated'] ?? false) === true,
    'productos' => $productos,
    'usuarios' => $usuarios,
    'reportes' => $reportes,
    'categorias' => productCategories(),
    ...$viewData,
  ]);
};

$app->get('/admin', function ($request, $response) use ($renderAdminPanel) {
  return $renderAdminPanel($response, ($_SESSION['admin_authenticated'] ?? false) === true);
});

$app->post('/admin', function ($request, $response) use ($renderAdminPanel, $requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $data = $request->getParsedBody() ?? [];
  $expectedPin = $_ENV['ADMIN_PIN'] ?? $_ENV['ADMIN_PASS'] ?? '';
  $providedPin = (string) ($data['pin'] ?? '');

  if ($expectedPin === '' || !hash_equals($expectedPin, $providedPin)) {
    return $renderAdminPanel($response->withStatus(401), false, [
      'error' => 'El PIN ingresado no es correcto.',
    ]);
  }

  session_regenerate_id(true);
  $_SESSION['admin_authenticated'] = true;

  return $response->withHeader('Location', '/admin')->withStatus(303);
});

$app->post('/admin/logout', function ($request, $response) use ($requireCsrf) {
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  unset($_SESSION['admin_authenticated']);
  return $response->withHeader('Location', '/admin')->withStatus(303);
});

$app->get('/admin/login', static fn($request, $response) => $response->withHeader('Location', '/admin')->withStatus(303));
$app->get('/admin/productos', static fn($request, $response) => $response->withHeader('Location', '/admin')->withStatus(303));
$app->get('/admin/usuarios', static fn($request, $response) => $response->withHeader('Location', '/admin#usuarios')->withStatus(303));

$moderateProduct = static function ($request, $response, array $args, string $state, ?string $reason = null) use ($requireAdmin, $requireCsrf) {
  if (($authorizationFailure = $requireAdmin($request, $response)) !== null) {
    return $authorizationFailure;
  }
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  if ($id === false || $id < 1) {
    $_SESSION['flash'] = 'La publicación seleccionada no es válida.';
    return $response->withHeader('Location', '/admin#productos')->withStatus(303);
  }
  if ($state === 'rechazado' && (mb_strlen((string) $reason) < 10 || mb_strlen((string) $reason) > 500)) {
    $_SESSION['flash'] = 'El motivo del rechazo debe tener entre 10 y 500 caracteres.';
    return $response->withHeader('Location', '/admin#productos')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    if ($state === 'aprobado') {
      $imageCount = $pdo->prepare(
        'SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = :producto_id'
      );
      $imageCount->execute(['producto_id' => $id]);
      if ((int) $imageCount->fetchColumn() < 1) {
        $_SESSION['flash'] = 'No se puede aprobar una publicación sin al menos una imagen.';
        return $response->withHeader('Location', '/admin#productos')->withStatus(303);
      }
    }

    $stmt = $pdo->prepare(
      'UPDATE productos SET estado = :estado, motivo_revision = :motivo WHERE id = :id AND estado = \'pendiente\''
    );
    $stmt->execute([
      'estado' => $state,
      'motivo' => $reason,
      'id' => $id,
    ]);
    $audit = $pdo->prepare(
      'INSERT INTO admin_auditoria (accion, entidad, entidad_id, detalle) VALUES (:accion, :entidad, :entidad_id, :detalle)'
    );
    $audit->execute([
      'accion' => $state === 'aprobado' ? 'aprobar' : 'rechazar',
      'entidad' => 'producto',
      'entidad_id' => $id,
      'detalle' => $state === 'aprobado' ? 'Publicación aprobada.' : mb_substr((string) $reason, 0, 500),
    ]);
    $_SESSION['flash'] = $stmt->rowCount() > 0
      ? ($state === 'aprobado' ? 'La publicación se aprobó y ya aparece en el catálogo.' : 'La publicación se rechazó y se informó el motivo al vendedor.')
      : 'La publicación ya no está pendiente de revisión.';
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo actualizar la publicación.';
  }

  return $response->withHeader('Location', '/admin#productos')->withStatus(303);
};

$app->post('/admin/productos/{id}/aprobar', static function ($request, $response, array $args) use ($moderateProduct) {
  return $moderateProduct($request, $response, $args, 'aprobado');
});

$app->post('/admin/reportes/{id}/accion', function ($request, $response, array $args) use ($requireAdmin, $requireCsrf) {
  if (($authorizationFailure = $requireAdmin($request, $response)) !== null) {
    return $authorizationFailure;
  }
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  $action = (string) (($request->getParsedBody() ?? [])['accion'] ?? '');
  if ($id === false || $id < 1 || !in_array($action, ['descartar', 'retirar'], true)) {
    $_SESSION['flash'] = 'La acción solicitada no es válida.';
    return $response->withHeader('Location', '/admin#reportes')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $report = $pdo->prepare("SELECT id, producto_id FROM producto_reportes WHERE id = :id AND estado = 'pendiente'");
    $report->execute(['id' => $id]);
    $entry = $report->fetch();
    if ($entry === false) {
      $_SESSION['flash'] = 'El reporte ya fue revisado o no existe.';
      return $response->withHeader('Location', '/admin#reportes')->withStatus(303);
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE producto_reportes SET estado = 'resuelto' WHERE id = :id")->execute(['id' => $id]);
    if ($action === 'retirar') {
      $pdo->prepare(
        "UPDATE productos SET estado = 'rechazado', motivo_revision = 'Retirada por moderación tras un reporte.' WHERE id = :id AND estado = 'aprobado'"
      )->execute(['id' => (int) $entry['producto_id']]);
    }
    $audit = $pdo->prepare(
      'INSERT INTO admin_auditoria (accion, entidad, entidad_id, detalle) VALUES (:accion, :entidad, :entidad_id, :detalle)'
    );
    $audit->execute([
      'accion' => $action === 'retirar' ? 'retirar_por_reporte' : 'descartar_reporte',
      'entidad' => 'producto_reporte',
      'entidad_id' => $id,
      'detalle' => $action === 'retirar' ? 'Se retiró la publicación reportada.' : 'Reporte revisado; no se retiró la publicación.',
    ]);
    $pdo->commit();
    $_SESSION['flash'] = $action === 'retirar'
      ? 'El reporte se resolvió y la publicación dejó de estar visible.'
      : 'El reporte se revisó y se descartó.';
  } catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo procesar el reporte.';
  }

  return $response->withHeader('Location', '/admin#reportes')->withStatus(303);
});
$app->post('/admin/productos/{id}/rechazar', static function ($request, $response, array $args) use ($moderateProduct) {
  $data = $request->getParsedBody() ?? [];
  return $moderateProduct($request, $response, $args, 'rechazado', trim((string) ($data['motivo'] ?? '')));
});

$app->post('/admin/usuarios/{id}/editar', function ($request, $response, array $args) use ($requireAdmin, $requireCsrf) {
  if (($authorizationFailure = $requireAdmin($request, $response)) !== null) {
    return $authorizationFailure;
  }
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  $data = $request->getParsedBody() ?? [];
  $user = [
    'nombre' => trim((string) ($data['nombre'] ?? '')),
    'gmail' => strtolower(trim((string) ($data['gmail'] ?? ''))),
    'telefono' => trim((string) ($data['telefono'] ?? '')),
    'direccion' => trim((string) ($data['direccion'] ?? '')),
  ];
  if (
    $id === false || $id < 1
    || in_array('', $user, true)
    || !filter_var($user['gmail'], FILTER_VALIDATE_EMAIL)
    || mb_strlen($user['nombre']) > 255
    || mb_strlen($user['gmail']) > 255
    || mb_strlen($user['telefono']) > 30
    || mb_strlen($user['direccion']) > 255
  ) {
    $_SESSION['flash'] = 'Revisá los datos del usuario e intentá nuevamente.';
    return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $check = $pdo->prepare('SELECT id FROM usuarios WHERE gmail = :gmail AND id <> :id');
    $check->execute(['gmail' => $user['gmail'], 'id' => $id]);
    if ($check->fetchColumn() !== false) {
      $_SESSION['flash'] = 'Ese correo ya está registrado por otra cuenta.';
      return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
    }

    $update = $pdo->prepare(
      "UPDATE usuarios SET nombre = :nombre, gmail = :gmail, telefono = :telefono, direccion = :direccion WHERE id = :id AND estado <> 'eliminado'"
    );
    $update->execute([...$user, 'id' => $id]);
    $_SESSION['flash'] = $update->rowCount() > 0
      ? 'Los datos del usuario se actualizaron.'
      : 'No se encontró una cuenta activa para editar.';
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudieron actualizar los datos del usuario.';
  }

  return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
});

$app->post('/admin/usuarios/{id}/estado', function ($request, $response, array $args) use ($requireAdmin, $requireCsrf) {
  if (($authorizationFailure = $requireAdmin($request, $response)) !== null) {
    return $authorizationFailure;
  }
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  $data = $request->getParsedBody() ?? [];
  $state = (string) ($data['estado'] ?? '');
  if ($id === false || $id < 1 || !in_array($state, ['activo', 'bloqueado'], true)) {
    $_SESSION['flash'] = 'La acción solicitada no es válida.';
    return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
  }

  try {
    $update = (new Database())->getConnection()->prepare(
      "UPDATE usuarios SET estado = :estado WHERE id = :id AND estado <> 'eliminado'"
    );
    $update->execute(['estado' => $state, 'id' => $id]);
    $_SESSION['flash'] = $update->rowCount() > 0
      ? ($state === 'bloqueado' ? 'La cuenta quedó bloqueada.' : 'La cuenta volvió a estar activa.')
      : 'No se encontró una cuenta disponible para cambiar.';
  } catch (PDOException $exception) {
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo cambiar el estado de la cuenta.';
  }

  return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
});

$app->post('/admin/usuarios/{id}/eliminar', function ($request, $response, array $args) use ($requireAdmin, $requireCsrf) {
  if (($authorizationFailure = $requireAdmin($request, $response)) !== null) {
    return $authorizationFailure;
  }
  if (($csrfFailure = $requireCsrf($request, $response)) !== null) {
    return $csrfFailure;
  }

  $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
  if ($id === false || $id < 1) {
    $_SESSION['flash'] = 'La cuenta seleccionada no es válida.';
    return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
  }

  try {
    $pdo = (new Database())->getConnection();
    $productQuery = $pdo->prepare('SELECT id FROM productos WHERE usuario_id = :usuario_id');
    $productQuery->execute(['usuario_id' => $id]);
    $productIds = array_map('intval', $productQuery->fetchAll(PDO::FETCH_COLUMN));
    $images = [];
    if ($productIds !== []) {
      $placeholders = implode(',', array_fill(0, count($productIds), '?'));
      $imageQuery = $pdo->prepare("SELECT ruta FROM producto_imagenes WHERE producto_id IN ($placeholders)");
      $imageQuery->execute($productIds);
      $images = $imageQuery->fetchAll();
    }

    $pdo->beginTransaction();
    if ($productIds !== []) {
      $placeholders = implode(',', array_fill(0, count($productIds), '?'));
      $removeProducts = $pdo->prepare("DELETE FROM productos WHERE id IN ($placeholders)");
      $removeProducts->execute($productIds);
    }

    $removeCart = $pdo->prepare('DELETE FROM carrito_items WHERE usuario_id = :id');
    $removeCart->execute(['id' => $id]);
    $removeAccount = $pdo->prepare(
      "UPDATE usuarios SET nombre = 'Cuenta eliminada', gmail = :gmail, telefono = '', direccion = '', password_hash = NULL, estado = 'eliminado' WHERE id = :id AND estado <> 'eliminado'"
    );
    $removeAccount->execute([
      'gmail' => 'eliminado-' . $id . '@cuentas.invalid',
      'id' => $id,
    ]);
    $pdo->commit();

    deleteProductImageFiles($images);
    $_SESSION['flash'] = $removeAccount->rowCount() > 0
      ? 'La cuenta y sus productos se eliminaron; el historial de pedidos se conservó.'
      : 'La cuenta ya estaba eliminada o no existe.';
  } catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log($exception->getMessage());
    $_SESSION['flash'] = 'No se pudo eliminar la cuenta.';
  }

  return $response->withHeader('Location', '/admin#usuarios')->withStatus(303);
});

$app->addErrorMiddleware($debug, true, true);

return $app;
