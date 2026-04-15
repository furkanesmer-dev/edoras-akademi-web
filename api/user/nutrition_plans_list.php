<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

// Bearer token
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('apache_request_headers')) {
  $headers = apache_request_headers();
  $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) json_fail('Token yok.', 401);
$token = $m[1];

// JWT doğrula
$payload = jwt_verify($token, JWT_SECRET);
if (!$payload || empty($payload['uid'])) json_fail('Geçersiz token.', 401);
$uid = (int)$payload['uid'];

// Aktif plan id (son plan)
$activeId = null;
$stmtA = $conn->prepare("SELECT id FROM beslenme_programlar WHERE user_id=? ORDER BY id DESC LIMIT 1");
if ($stmtA) {
  $stmtA->bind_param("i", $uid);
  $stmtA->execute();
  $rA = $stmtA->get_result()->fetch_assoc();
  $stmtA->close();
  $activeId = $rA ? (int)$rA['id'] : null;
}

// Liste
$stmt = $conn->prepare("
  SELECT id, created_at, hedef
  FROM beslenme_programlar
  WHERE user_id = ?
  ORDER BY id DESC
");
if (!$stmt) json_fail('Sistem hatası.', 500);
$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
  $id = (int)$row['id'];
  $items[] = [
    'id' => $id,
    'created_at' => $row['created_at'] ?? null,
    'hedef' => $row['hedef'] ?? null,
    'is_active' => ($activeId !== null && $id === $activeId),
  ];
}
$stmt->close();

json_ok([
  'has_plan' => count($items) > 0,
  'active_id' => $activeId,
  'items' => $items,
]);