<?php
header('Content-Type: application/json; charset=utf-8');

// -----------------------------
// Includes
// -----------------------------
require_once __DIR__ . '/../inc/config.php'; // JWT_SECRET, JWT_TTL_SECONDS
require_once __DIR__ . '/../inc/db.php';     // $conn
require_once __DIR__ . '/../utils/jwt.php';  // jwt_verify()

// -----------------------------
// Authorization (robust)
// -----------------------------
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

$authHeader =
  $headers['authorization']
  ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Token yok'], JSON_UNESCAPED_UNICODE);
  exit;
}

// "Bearer xxx" -> token
$jwt = trim(substr($authHeader, 7));

// -----------------------------
// JWT Verify
// -----------------------------
$payload = jwt_verify($jwt, JWT_SECRET);

// 🔑 Senin sisteminde kullanıcı ID = uid
if (!$payload || !isset($payload['uid'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Geçersiz token'], JSON_UNESCAPED_UNICODE);
  exit;
}

$uye_id = (int)$payload['uid'];

// -----------------------------
// Query: En yakın planned seans
// -----------------------------
$sql = "
SELECT
  so.id,
  so.baslik,
  so.notlar,
  so.seans_tarih_saat,
  so.sure_dk,
  so.durum,
  ss.lokasyon
FROM seans_ornekleri so
LEFT JOIN seans_sablonlari ss ON ss.id = so.sablon_id
WHERE so.uye_id = ?
  AND so.seans_tarih_saat >= NOW()
  AND so.durum = 'planned'
ORDER BY so.seans_tarih_saat ASC
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $uye_id);
$stmt->execute();
$res = $stmt->get_result();

$item = $res->fetch_assoc();

// -----------------------------
// Response
// -----------------------------
echo json_encode([
  'ok' => true,
  'item' => $item ? [
    'id' => (int)$item['id'],
    'baslik' => $item['baslik'],
    'notlar' => $item['notlar'],
    'lokasyon' => $item['lokasyon'],
    'seans_tarih_saat' => $item['seans_tarih_saat'],
    'sure_dk' => (int)$item['sure_dk'],
    'durum' => $item['durum'],
  ] : null
], JSON_UNESCAPED_UNICODE);