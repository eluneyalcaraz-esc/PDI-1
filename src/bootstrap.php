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
  $productos = [];
  $error = null;
  $tags = [];
  $categories = productCategories();

  try {
    $pdo = (new Database())->getConnection();

    if ($category !== '' && !array_key_exists($category, $categories)) {
      $error = 'La categoría seleccionada no es válida.';
    }

    $names = $pdo->query("SELECT DISTINCT nombre FROM productos WHERE estado = 'aprobado' ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
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

    if ($error === null && $search !== '') {
      $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
      $conditions = [];
      $parameters = [];
      foreach ($terms as $index => $term) {
        $nameParameter = 'name_search_' . $index;
        $descriptionParameter = 'description_search_' . $index;
        $categoryParameter = 'category_search_' . $index;
        $conditions[] = "(LOWER(nombre) LIKE :$nameParameter OR LOWER(descripcion) LIKE :$descriptionParameter OR LOWER(categoria) LIKE :$categoryParameter)";
        $searchTerm = '%' . mb_strtolower($term) . '%';
        $parameters[$nameParameter] = $searchTerm;
        $parameters[$descriptionParameter] = $searchTerm;
        $parameters[$categoryParameter] = $searchTerm;
      }

      $categoryCondition = '';
      if ($category !== '') {
        $categoryCondition = ' AND categoria = :category';
        $parameters['category'] = $category;
      }

      $stmt = $pdo->prepare(
        "SELECT id, nombre, precio, descripcion, usuario_id, categoria FROM productos WHERE estado = 'aprobado' AND ("
        . implode(' AND ', $conditions)
        . ')'
        . $categoryCondition
        . ' ORDER BY id DESC'
      );
      $stmt->execute($parameters);
      $productos = $stmt->fetchAll();
    } elseif ($error === null) {
      $sql = "SELECT id, nombre, precio, descripcion, usuario_id, categoria FROM productos WHERE estado = 'aprobado'";
      if ($category !== '') {
        $stmt = $pdo->prepare($sql . ' AND categoria = :category ORDER BY id DESC');
        $stmt->execute(['category' => $category]);
        $productos = $stmt->fetchAll();
      } else {
        $productos = $pdo->query($sql . ' ORDER BY id DESC')->fetchAll();
      }
    }
    $productos = attachProductImages($pdo, $productos);
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
    'error' => $error,
  ]);
});

$app->get('/productos', function ($request, $response) use ($renderer, $renderError) {
  try {
    $pdo = (new Database())->getConnection();
    $productos = $pdo->query(
      "SELECT id, nombre, precio, descripcion, creado_en, categoria FROM productos WHERE estado = 'aprobado' ORDER BY id DESC"
    )->fetchAll();

    return view($renderer, $response, '/productos/index.php', [
      'productos' => attachProductImages($pdo, $productos),
      'categorias' => productCategories(),
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
      'SELECT id, nombre, precio, descripcion, categoria, motivo_revision FROM productos WHERE id = :id AND usuario_id = :usuario_id'
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
      'SELECT id, nombre, precio, descripcion, usuario_id, categoria, estado, motivo_revision FROM productos WHERE id = :id'
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

    return view($renderer, $response, '/productos/show.php', [
      'producto' => $producto,
      'categorias' => productCategories(),
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
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->prepare(
      'SELECT id, nombre, precio, descripcion, categoria, estado, motivo_revision FROM productos WHERE usuario_id = :usuario_id ORDER BY id DESC'
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
      "INSERT INTO productos (nombre, precio, descripcion, categoria, usuario_id, estado, busquedas) VALUES (:nombre, :precio, :descripcion, :categoria, :usuario_id, 'pendiente', 0)"
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
      "UPDATE productos SET nombre = :nombre, precio = :precio, descripcion = :descripcion, categoria = :categoria, estado = 'pendiente', motivo_revision = NULL WHERE id = :id AND usuario_id = :usuario_id"
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
    $product = $pdo->prepare("SELECT id FROM productos WHERE id = :id AND estado = 'aprobado'");
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
      'SELECT c.id, c.cantidad, p.id AS producto_id, p.nombre, p.precio, p.descripcion, p.estado FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id WHERE c.usuario_id = :usuario_id ORDER BY c.id DESC'
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    $hasUnavailableItems = count(array_filter(
      $items,
      static fn(array $item): bool => $item['estado'] !== 'aprobado'
    )) > 0;
    $total = array_sum(array_map(
      static fn(array $item): float => $item['estado'] === 'aprobado'
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
      "SELECT c.producto_id, c.cantidad, p.nombre, p.precio, p.usuario_id, p.estado, COALESCE(u.nombre, 'Vendedor no disponible') AS vendedor_nombre FROM carrito_items c INNER JOIN productos p ON p.id = c.producto_id LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE c.usuario_id = :usuario_id FOR UPDATE"
    );
    $stmt->execute(['usuario_id' => $user['id']]);
    $items = $stmt->fetchAll();
    if ($items === []) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Tu carrito está vacío.';
      return $response->withHeader('Location', '/carrito')->withStatus(303);
    }
    if (array_filter($items, static fn(array $item): bool => $item['estado'] !== 'aprobado') !== []) {
      $pdo->rollBack();
      $_SESSION['flash'] = 'Quitá del carrito los productos que todavía están pendientes o ya no están disponibles.';
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

$renderAdminPanel = static function ($response, bool $includeData = true, array $viewData = []) use ($renderer, $renderError) {
  $productos = [];
  $usuarios = [];
  if ($includeData) {
    try {
      $pdo = (new Database())->getConnection();
      $productos = attachProductImages($pdo, $pdo->query(
        "SELECT p.id, p.nombre, p.precio, p.descripcion, p.categoria, p.creado_en, p.usuario_id, p.motivo_revision, u.nombre AS vendedor FROM productos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.estado = 'pendiente' ORDER BY p.creado_en ASC"
      )->fetchAll());
      $usuarios = $pdo->query(
        'SELECT id, nombre, gmail, telefono, direccion, estado FROM usuarios ORDER BY id DESC'
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
