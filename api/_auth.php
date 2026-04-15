<?php
// /api/_auth.php

function json_fail(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function get_bearer_token(): ?string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$hdr) return null;
  if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) return trim($m[1]);
  return null;
}

function base64url_decode(string $data): string {
  $remainder = strlen($data) % 4;
  if ($remainder) {
    $data .= str_repeat('=', 4 - $remainder);
  }
  $data = strtr($data, '-_', '+/');
  return base64_decode($data);
}

/**
 * HS256 JWT verify + payload decode
 * returns payload array
 */
function jwt_decode_hs256(string $jwt, string $secret): array {
  $parts = explode('.', $jwt);
  if (count($parts) !== 3) {
    json_fail(401, "Geçersiz token formatı");
  }

  [$h64, $p64, $s64] = $parts;

  $headerJson = base64url_decode($h64);
  $payloadJson = base64url_decode($p64);

  $header = json_decode($headerJson, true);
  $payload = json_decode($payloadJson, true);

  if (!is_array($header) || !is_array($payload)) {
    json_fail(401, "Token çözümlenemedi");
  }

  // alg kontrol
  $alg = $header['alg'] ?? null;
  if ($alg !== 'HS256') {
    json_fail(401, "Desteklenmeyen alg: " . ($alg ?? 'null'));
  }

  // signature verify
  $signingInput = $h64 . "." . $p64;
  $expected = hash_hmac('sha256', $signingInput, $secret, true);
  $given = base64url_decode($s64);

  if (!hash_equals($expected, $given)) {
    json_fail(401, "Token imzası geçersiz");
  }

  // exp kontrol (varsa)
  if (isset($payload['exp']) && is_numeric($payload['exp'])) {
    if (time() >= (int)$payload['exp']) {
      json_fail(401, "Token süresi dolmuş");
    }
  }

  return $payload;
}

/**
 * JWT içinden kullanıcı id alır.
 * payload içinde "user_id" veya "id" bekler.
 */
function require_auth_user_id(): int {
  // ✅ Burayı login.php’de kullandığın secret ile aynı yap!
  // Seçenek 1: db.php içinde define ediyorsan:
  // define('JWT_SECRET', '...');
  // Seçenek 2: ayrı config.php’de
  // Seçenek 3: env (cPanel’de zor olabiliyor)

  if (!defined('JWT_SECRET') || !JWT_SECRET) {
    json_fail(500, "JWT_SECRET tanımlı değil. Token ürettiğin secret ile aynı olmalı.");
  }

  $token = get_bearer_token();
  if (!$token) json_fail(401, "Authorization token yok");

  $payload = jwt_decode_hs256($token, JWT_SECRET);

  $uid = $payload['user_id'] ?? $payload['id'] ?? null;
  if ($uid === null || !is_numeric($uid)) {
    json_fail(401, "Token içinde user_id/id yok");
  }

  return (int)$uid;
}