<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

// Bearer token al
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('apache_request_headers')) {
  $headers = apache_request_headers();
  $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
  json_fail('Token yok.', 401);
}
$token = $m[1];

// JWT doğrula
$payload = jwt_verify($token, JWT_SECRET);
if (!$payload || empty($payload['uid'])) {
  json_fail('Geçersiz token.', 401);
}
$uid = (int)$payload['uid'];

/**
 * Varsayım:
 * - beslenme_programlar: id, user_id, hedef, notlar, created_at (veya benzeri)
 * - "aktif" = son eklenen plan (id DESC)
 */

// Toplam plan sayısı
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM beslenme_programlar WHERE user_id = ?");
if (!$stmt) json_fail('Sistem hatası.', 500);
$stmt->bind_param("i", $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total = (int)($row['cnt'] ?? 0);

// Aktif = son plan
$active = null;
$stmt2 = $conn->prepare("
  SELECT id, created_at, hedef
  FROM beslenme_programlar
  WHERE user_id = ?
  ORDER BY id DESC
  LIMIT 1
");
if ($stmt2) {
  $stmt2->bind_param("i", $uid);
  $stmt2->execute();
  $active = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();
}

json_ok([
  'total' => $total,
  'active_exists' => $active ? true : false,
  'active_id' => $active ? (int)$active['id'] : null,
  'active_created_at' => $active['created_at'] ?? null,
  'active_hedef' => $active['hedef'] ?? null, // ✅ Home başlığı
]);