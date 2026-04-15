<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../utils/jwt.php';

// -----------------------------
// Authorization (robust)
// -----------------------------
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$authHeader = $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Token yok'], JSON_UNESCAPED_UNICODE);
  exit;
}

$jwt = trim(substr($authHeader, 7));

// -----------------------------
// JWT Verify (uid)
// -----------------------------
$payload = jwt_verify($jwt, JWT_SECRET);
if (!$payload || !isset($payload['uid'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Geçersiz token'], JSON_UNESCAPED_UNICODE);
  exit;
}
$uye_id = (int)$payload['uid'];

// Optional limits
$up_limit = isset($_GET['up_limit']) ? max(1, min(100, (int)$_GET['up_limit'])) : 20;
$past_limit = isset($_GET['past_limit']) ? max(1, min(100, (int)$_GET['past_limit'])) : 20;

// -----------------------------
// Queries
// -----------------------------
$sqlUpcoming = "
SELECT so.id, so.baslik, so.notlar, so.seans_tarih_saat, so.sure_dk, so.durum, ss.lokasyon
FROM seans_ornekleri so
LEFT JOIN seans_sablonlari ss ON ss.id = so.sablon_id
WHERE so.uye_id = ?
  AND so.seans_tarih_saat >= NOW()
ORDER BY so.seans_tarih_saat ASC
LIMIT $up_limit
";

$sqlPast = "
SELECT so.id, so.baslik, so.notlar, so.seans_tarih_saat, so.sure_dk, so.durum, ss.lokasyon
FROM seans_ornekleri so
LEFT JOIN seans_sablonlari ss ON ss.id = so.sablon_id
WHERE so.uye_id = ?
  AND so.seans_tarih_saat < NOW()
ORDER BY so.seans_tarih_saat DESC
LIMIT $past_limit
";

$upcoming = [];
$past = [];

// Upcoming
$stmt = $conn->prepare($sqlUpcoming);
$stmt->bind_param("i", $uye_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $upcoming[] = [
    'id' => (int)$row['id'],
    'baslik' => $row['baslik'],
    'notlar' => $row['notlar'],
    'lokasyon' => $row['lokasyon'],
    'seans_tarih_saat' => $row['seans_tarih_saat'],
    'sure_dk' => (int)$row['sure_dk'],
    'durum' => $row['durum'],
  ];
}

// Past
$stmt2 = $conn->prepare($sqlPast);
$stmt2->bind_param("i", $uye_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
while ($row = $res2->fetch_assoc()) {
  $past[] = [
    'id' => (int)$row['id'],
    'baslik' => $row['baslik'],
    'notlar' => $row['notlar'],
    'lokasyon' => $row['lokasyon'],
    'seans_tarih_saat' => $row['seans_tarih_saat'],
    'sure_dk' => (int)$row['sure_dk'],
    'durum' => $row['durum'],
  ];
}

// -----------------------------
// ✅ Session / Subscription info (for top cards)
// -----------------------------
$session_info = [
  'abonelik_tipi' => null,
  'abonelik_suresi_ay' => null,
  'baslangic_tarihi' => null,
  'bitis_tarihi' => null,
  'paket_toplam_seans' => null,
  'paket_kalan_seans' => null,
];

$sqlInfo = "
SELECT
  abonelik_tipi,
  abonelik_suresi_ay,
  baslangic_tarihi,
  bitis_tarihi,
  paket_toplam_seans,
  paket_kalan_seans
FROM uye_kullanicilar
WHERE id = ?
LIMIT 1
";

$stmt3 = $conn->prepare($sqlInfo);
$stmt3->bind_param("i", $uye_id);
$stmt3->execute();
$res3 = $stmt3->get_result();
if ($row = $res3->fetch_assoc()) {
  $session_info = [
    'abonelik_tipi' => $row['abonelik_tipi'], // 'aylik' | 'ders_paketi'
    'abonelik_suresi_ay' => isset($row['abonelik_suresi_ay']) ? (int)$row['abonelik_suresi_ay'] : null,
    'baslangic_tarihi' => $row['baslangic_tarihi'], // 'YYYY-MM-DD' veya 'YYYY-MM-DD HH:MM:SS'
    'bitis_tarihi' => $row['bitis_tarihi'],
    'paket_toplam_seans' => isset($row['paket_toplam_seans']) ? (int)$row['paket_toplam_seans'] : null,
    'paket_kalan_seans' => isset($row['paket_kalan_seans']) ? (int)$row['paket_kalan_seans'] : null,
  ];
}

echo json_encode([
  'ok' => true,
  'data' => [
    'upcoming' => $upcoming,
    'past' => $past,
    // ✅ Flutter tarafında data['session_info'] okunuyor
    'session_info' => $session_info,
  ]
], JSON_UNESCAPED_UNICODE);