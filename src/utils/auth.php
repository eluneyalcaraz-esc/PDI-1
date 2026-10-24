<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;

function currentUser(): ?array
{
  $user = $_SESSION['usuario'] ?? null;

  if (!is_array($user) || !isset($user['id'], $user['nombre'], $user['gmail'])) {
    return null;
  }

  return [
    'id' => (int) $user['id'],
    'nombre' => (string) $user['nombre'],
    'gmail' => (string) $user['gmail'],
  ];
}

function csrfToken(): string
{
  if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }

  return $_SESSION['csrf_token'];
}

function csrfIsValid(ServerRequestInterface $request): bool
{
  $body = $request->getParsedBody();
  $token = is_array($body) ? ($body['csrf_token'] ?? null) : null;

  return is_string($token)
    && isset($_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $token);
}

function setAuthenticatedUser(array $user): void
{
  session_regenerate_id(true);
  $_SESSION['usuario'] = [
    'id' => (int) $user['id'],
    'nombre' => (string) $user['nombre'],
    'gmail' => (string) $user['gmail'],
  ];
}

function safeReturnPath(mixed $path): string
{
  if (
    !is_string($path)
    || !str_starts_with($path, '/')
    || str_starts_with($path, '//')
    || preg_match('/[\r\n\\\\]/', $path)
  ) {
    return '/';
  }

  $parts = parse_url($path);

  if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
    return '/';
  }

  return $path;
}
