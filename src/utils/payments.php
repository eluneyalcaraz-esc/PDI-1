<?php

declare(strict_types=1);

function mercadoPagoConfiguration(): ?array
{
  $token = trim((string) ($_ENV['MP_ACCESS_TOKEN'] ?? ''));
  $secret = trim((string) ($_ENV['MP_WEBHOOK_SECRET'] ?? ''));
  $appUrl = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
  $host = parse_url($appUrl, PHP_URL_HOST);

  if (
    $token === ''
    || $secret === ''
    || !is_string($host)
    || parse_url($appUrl, PHP_URL_SCHEME) !== 'https'
    || in_array(strtolower($host), ['localhost', '127.0.0.1'], true)
  ) {
    return null;
  }

  return ['token' => $token, 'secret' => $secret, 'app_url' => $appUrl];
}

function mercadoPagoRequest(string $method, string $url, string $token, ?array $payload = null): array
{
  if (!function_exists('curl_init')) {
    throw new RuntimeException('La extensión cURL de PHP es necesaria para Mercado Pago.');
  }

  $curl = curl_init($url);
  if ($curl === false) {
    throw new RuntimeException('No se pudo iniciar la conexión con Mercado Pago.');
  }

  $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
  $options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => $headers,
  ];
  if ($payload !== null) {
    $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
  }
  curl_setopt_array($curl, $options);
  $body = curl_exec($curl);
  $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
  $error = curl_error($curl);
  curl_close($curl);

  if (!is_string($body) || $status < 200 || $status >= 300) {
    throw new RuntimeException('Mercado Pago no completó la solicitud: ' . ($error !== '' ? $error : 'HTTP ' . $status));
  }

  $result = json_decode($body, true);
  if (!is_array($result)) {
    throw new RuntimeException('Mercado Pago devolvió una respuesta inválida.');
  }

  return $result;
}

function mercadoPagoSignatureIsValid(string $signature, string $requestId, string $dataId, string $secret): bool
{
  $parts = [];
  foreach (explode(',', $signature) as $part) {
    $pair = explode('=', trim($part), 2);
    if (count($pair) === 2) {
      $parts[$pair[0]] = $pair[1];
    }
  }
  if (!isset($parts['ts'], $parts['v1']) || $requestId === '' || $dataId === '') {
    return false;
  }

  $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
  return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
}
