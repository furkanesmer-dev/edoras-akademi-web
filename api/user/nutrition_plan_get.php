<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) json_fail('Geçersiz id.', 400);

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

// Program çek (user kontrolü şart)
$stmt = $conn->prepare("
  SELECT id, user_id, hedef, notlar, created_at
  FROM beslenme_programlar
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
if (!$stmt) json_fail('Sistem hatası.', 500);
$stmt->bind_param("ii", $id, $uid);
$stmt->execute();
$program = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$program) {
  json_fail('Program bulunamadı.', 404);
}

// Öğeleri çek
$stmt2 = $conn->prepare("
  SELECT ogun, yemek, miktar, birim, kalori, karbonhidrat, protein, yag
  FROM beslenme_program_ogeler
  WHERE program_id = ?
  ORDER BY id ASC
");
if (!$stmt2) json_fail('Sistem hatası.', 500);
$stmt2->bind_param("i", $id);
$stmt2->execute();
$res = $stmt2->get_result();

$byOgun = [];
while ($r = $res->fetch_assoc()) {
  $ogun = $r['ogun'] ?? 'Diğer';
  if (!isset($byOgun[$ogun])) $byOgun[$ogun] = [];
  $byOgun[$ogun][] = [
    'yemek' => $r['yemek'] ?? '',
    'miktar' => (float)($r['miktar'] ?? 0),
    'birim' => $r['birim'] ?? '',
    'kalori' => (float)($r['kalori'] ?? 0),
    'karbonhidrat' => (float)($r['karbonhidrat'] ?? 0),
    'protein' => (float)($r['protein'] ?? 0),
    'yag' => (float)($r['yag'] ?? 0),
  ];
}
$stmt2->close();

json_ok([
  'has_plan' => true,
  'id' => (int)$program['id'],
  'created_at' => $program['created_at'] ?? null,
  'program' => [
    'hedef' => $program['hedef'] ?? '',
    'notlar' => $program['notlar'] ?? '',
  ],
  'by_ogun' => $byOgun,
]);