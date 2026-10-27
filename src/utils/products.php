<?php

declare(strict_types=1);

use Psr\Http\Message\UploadedFileInterface;

const PRODUCT_IMAGE_MAX_COUNT = 5;
const PRODUCT_IMAGE_MAX_BYTES = 5242880;

final class ProductImageException extends RuntimeException
{
}

final class ProductPublishingLimitException extends RuntimeException
{
}

/**
 * @return array<string, string>
 */
function productCategories(): array
{
  return [
    'tecnologia' => 'Tecnología',
    'hogar' => 'Para el hogar',
    'ropa' => 'Ropa y accesorios',
    'carpinteria' => 'Carpintería',
    'alimentos' => 'Alimentos y bebidas',
    'belleza' => 'Belleza y cuidado personal',
    'deportes' => 'Deportes y aire libre',
    'jardin' => 'Jardín',
    'artesanias' => 'Artesanías',
    'juguetes' => 'Juguetes',
    'libros' => 'Libros',
    'otros' => 'Otros',
  ];
}

function catalogOrderBy(string $sort): string
{
  return match ($sort) {
    'precio_asc' => ' ORDER BY precio ASC, id DESC',
    'precio_desc' => ' ORDER BY precio DESC, id DESC',
    'nombre' => ' ORDER BY nombre ASC, id DESC',
    default => ' ORDER BY creado_en DESC, id DESC',
  };
}

/**
 * @param array<string, string> $product
 */
function isValidProductPublication(array $product, bool $confirmed): bool
{
  preg_match_all('/[\p{L}\p{N}]{2,}/u', $product['descripcion'], $descriptionWords);
  $quantity = filter_var($product['stock'] ?? null, FILTER_VALIDATE_INT);
  $minimum = filter_var($product['cantidad_minima'] ?? null, FILTER_VALIDATE_INT);

  return mb_strlen($product['nombre']) >= 3
    && mb_strlen($product['nombre']) <= 255
    && preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/D', $product['precio']) === 1
    && (float) $product['precio'] > 0
    && mb_strlen($product['descripcion']) >= 30
    && strlen($product['descripcion']) <= 1000
    && count($descriptionWords[0]) >= 4
    && array_key_exists($product['categoria'], productCategories())
    && in_array($product['condicion'] ?? '', ['nuevo', 'usado', 'reacondicionado'], true)
    && preg_match('/^[\p{L}\p{N} -]{1,40}$/uD', $product['unidad'] ?? '') === 1
    && $quantity !== false
    && $quantity >= 1
    && $minimum !== false
    && $minimum >= 1
    && $minimum <= $quantity
    && $confirmed;
}

function formatArs(float|string|int $amount): string
{
  return '$ ' . number_format((float) $amount, 2, ',', '.') . ' ARS';
}

/**
 * @return array<int, array{ruta: string, url: string}>
 */
function productImages(PDO $pdo, int $productId): array
{
  $stmt = $pdo->prepare(
    'SELECT ruta FROM producto_imagenes WHERE producto_id = :producto_id ORDER BY orden ASC'
  );
  $stmt->execute(['producto_id' => $productId]);

  return array_map(
    static fn(string $path): array => ['ruta' => $path, 'url' => $path],
    array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))
  );
}

/**
 * @param array<int, array<string, mixed>> $products
 * @return array<int, array<string, mixed>>
 */
function attachProductImages(PDO $pdo, array $products): array
{
  if ($products === []) {
    return [];
  }

  $ids = array_map(static fn(array $product): int => (int) $product['id'], $products);
  $placeholders = [];
  $parameters = [];
  foreach ($ids as $index => $id) {
    $placeholder = 'product_' . $index;
    $placeholders[] = ':' . $placeholder;
    $parameters[$placeholder] = $id;
  }

  $stmt = $pdo->prepare(
    'SELECT producto_id, ruta FROM producto_imagenes WHERE producto_id IN ('
    . implode(', ', $placeholders)
    . ') ORDER BY orden ASC'
  );
  $stmt->execute($parameters);
  $imagesByProduct = [];
  foreach ($stmt->fetchAll() as $image) {
    $imagesByProduct[(int) $image['producto_id']][] = [
      'ruta' => (string) $image['ruta'],
      'url' => (string) $image['ruta'],
    ];
  }

  foreach ($products as &$product) {
    $product['imagenes'] = $imagesByProduct[(int) $product['id']] ?? [];
  }
  unset($product);

  return $products;
}

/**
 * @param mixed $uploads
 * @return array<int, UploadedFileInterface>
 */
function validateProductImageUploads(mixed $uploads, int $existingCount = 0): array
{
  if ($uploads === []) {
    return [];
  }
  if (!is_array($uploads)) {
    throw new ProductImageException('No se pudieron leer las imágenes seleccionadas.');
  }

  $files = [];
  foreach ($uploads as $upload) {
    if (!$upload instanceof UploadedFileInterface) {
      throw new ProductImageException('El formulario de imágenes no es válido.');
    }

    if ($upload->getError() === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    if ($upload->getError() !== UPLOAD_ERR_OK) {
      throw new ProductImageException('No se pudo recibir una de las imágenes. Probá con archivos de hasta 5 MB.');
    }
    if ($upload->getSize() === null || $upload->getSize() < 1 || $upload->getSize() > PRODUCT_IMAGE_MAX_BYTES) {
      throw new ProductImageException('Cada imagen debe pesar como máximo 5 MB.');
    }

    $temporaryPath = $upload->getStream()->getMetadata('uri');
    if (!is_string($temporaryPath) || !is_file($temporaryPath)) {
      throw new ProductImageException('No se pudo verificar una de las imágenes.');
    }

    $imageInfo = @getimagesize($temporaryPath);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowedTypes = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
    ];
    if (
      $imageInfo === false
      || !isset($allowedTypes[$mime])
      || ($imageInfo['mime'] ?? null) !== $mime
      || $imageInfo[0] < 100
      || $imageInfo[1] < 100
      || $imageInfo[0] * $imageInfo[1] > 40000000
    ) {
      throw new ProductImageException('Usá imágenes JPEG, PNG o WebP válidas, de al menos 100 × 100 píxeles.');
    }

    $files[] = $upload;
  }

  if ($existingCount + count($files) > PRODUCT_IMAGE_MAX_COUNT) {
    throw new ProductImageException('Cada producto puede tener como máximo 5 imágenes.');
  }

  return $files;
}

/**
 * @param array<int, UploadedFileInterface> $files
 * @return array<int, array{ruta: string, url: string}>
 */
function storeProductImages(array $files): array
{
  if ($files === []) {
    return [];
  }

  $directory = dirname(__DIR__, 2) . '/public/uploads/productos';
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new RuntimeException('No se pudo crear el directorio para guardar imágenes de productos.');
  }

  $mimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  ];
  $savedImages = [];
  try {
    foreach ($files as $file) {
      $temporaryPath = $file->getStream()->getMetadata('uri');
      $mime = is_string($temporaryPath) ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) : false;
      if (!is_string($mime) || !isset($mimeTypes[$mime])) {
        throw new ProductImageException('Una de las imágenes seleccionadas dejó de ser válida. Volvé a subirla.');
      }

      $filename = bin2hex(random_bytes(16)) . '.' . $mimeTypes[$mime];
      $file->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
      $path = '/uploads/productos/' . $filename;
      $savedImages[] = ['ruta' => $path, 'url' => $path];
    }
  } catch (Throwable $exception) {
    deleteProductImageFiles($savedImages);
    throw $exception;
  }

  return $savedImages;
}

/**
 * @param array<int, array{ruta?: string}> $images
 */
function insertProductImages(PDO $pdo, int $productId, array $images): void
{
  $stmt = $pdo->prepare(
    'INSERT INTO producto_imagenes (producto_id, orden, ruta) VALUES (:producto_id, :orden, :ruta)'
  );
  foreach ($images as $index => $image) {
    $stmt->execute([
      'producto_id' => $productId,
      'orden' => $index,
      'ruta' => $image['ruta'],
    ]);
  }
}

/**
 * @param array<int, array{ruta?: string}> $images
 */
function deleteProductImageFiles(array $images): void
{
  $directory = dirname(__DIR__, 2) . '/public/uploads/productos';
  foreach ($images as $image) {
    $path = (string) ($image['ruta'] ?? '');
    if (!preg_match('~^/uploads/productos/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $path)) {
      continue;
    }

    $filePath = $directory . DIRECTORY_SEPARATOR . basename($path);
    if (is_file($filePath) && !unlink($filePath)) {
      error_log('No se pudo eliminar la imagen del producto: ' . basename($path));
    }
  }
}

function enforceProductPublishingLimit(PDO $pdo, int $userId): void
{
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $hourInterval = $driver === 'pgsql' ? "INTERVAL '1 hour'" : 'INTERVAL 1 HOUR';
  $dayInterval = $driver === 'pgsql' ? "INTERVAL '1 day'" : 'INTERVAL 1 DAY';
  $stmt = $pdo->prepare(
    'SELECT '
    . '(SELECT COUNT(*) FROM productos WHERE usuario_id = :hour_user AND creado_en >= CURRENT_TIMESTAMP - '
    . $hourInterval
    . ') AS last_hour, '
    . '(SELECT COUNT(*) FROM productos WHERE usuario_id = :day_user AND creado_en >= CURRENT_TIMESTAMP - '
    . $dayInterval
    . ') AS last_day'
  );
  $stmt->execute(['hour_user' => $userId, 'day_user' => $userId]);
  $counts = $stmt->fetch();
  if ($counts === false || (int) $counts['last_hour'] >= 5 || (int) $counts['last_day'] >= 20) {
    throw new ProductPublishingLimitException(
      'Alcanzaste el límite de publicaciones (5 por hora y 20 por día). Volvé a intentarlo más tarde.'
    );
  }
}
