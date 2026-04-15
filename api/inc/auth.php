<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../utils/jwt.php';

function require_user(): array {
  $token = bearer_token();
  if (!$token) json_fail('Token yok.', 401);

  $payload = jwt_verify($token, JWT_SECRET);
  if (!$payload || empty($payload['uid'])) json_fail('Token geçersiz/expired.', 401);

  return $payload; // ['uid'=>..., 'yetki'=>...]
}