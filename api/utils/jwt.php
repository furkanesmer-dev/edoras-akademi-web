<?php
// Basit JWT HS256 (MVP için yeterli)

function b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64url_decode(string $data): string {
  $remainder = strlen($data) % 4;
  if ($remainder) $data .= str_repeat('=', 4 - $remainder);
  return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_sign(array $payload, string $secret, int $ttl_seconds = 60*60*24*7): string {
  $header = ['alg'=>'HS256', 'typ'=>'JWT'];
  $now = time();
  $payload = array_merge($payload, [
    'iat' => $now,
    'exp' => $now + $ttl_seconds,
  ]);

  $h = b64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE));
  $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
  $sig = hash_hmac('sha256', "$h.$p", $secret, true);
  $s = b64url_encode($sig);

  return "$h.$p.$s";
}

function jwt_verify(string $jwt, string $secret): ?array {
  $parts = explode('.', $jwt);
  if (count($parts) !== 3) return null;

  [$h, $p, $s] = $parts;
  $sig = b64url_decode($s);
  $expected = hash_hmac('sha256', "$h.$p", $secret, true);
  if (!hash_equals($expected, $sig)) return null;

  $payload = json_decode(b64url_decode($p), true);
  if (!is_array($payload)) return null;

  if (isset($payload['exp']) && time() > (int)$payload['exp']) return null;

  return $payload;
}