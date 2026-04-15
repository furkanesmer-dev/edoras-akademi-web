<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user']['id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Oturum yok.'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Rol kontrolü: yalnızca egitmen veya admin erişebilir
$_yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
if (!in_array($_yetki, ['egitmen', 'admin'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$egitmen_id = (int)$_SESSION['user']['id'];

$stmt = $conn->prepare("
  SELECT id,
         CONCAT(TRIM(COALESCE(ad,'')),' ',TRIM(COALESCE(soyad,''))) AS ad_soyad,
         uyelik_numarasi
  FROM uye_kullanicilar
  WHERE egitmen_id = ?
  ORDER BY ad ASC, soyad ASC
");
$stmt->bind_param("i", $egitmen_id);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
  $name = trim($r['ad_soyad'] ?? '');
  if ($name === '') $name = 'Üye #' . (int)$r['id'];
  $items[] = [
    'id' => (int)$r['id'],
    'ad_soyad' => $name,
    'uyelik_numarasi' => $r['uyelik_numarasi'] ?? ''
  ];
}
$stmt->close();

echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
