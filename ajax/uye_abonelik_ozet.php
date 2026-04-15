<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/../inc/db.php';

function fail($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['user']['id'])) fail('Oturum yok.', 401);

// Rol kontrolü: yalnızca egitmen veya admin erişebilir
$_yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
if (!in_array($_yetki, ['egitmen', 'admin'], true)) {
  fail('Bu işlem için yetkiniz yok.', 403);
}

$egitmen_id = (int)$_SESSION['user']['id'];

$uye_id = (int)($_GET['uye_id'] ?? 0);
if ($uye_id <= 0) {
  echo json_encode(['ok'=>true,'item'=>null], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = $conn->prepare("
  SELECT
    id,
    ad, soyad,
    abonelik_tipi,
    abonelik_durum,
    baslangic_tarihi,
    bitis_tarihi,
    paket_toplam_seans,
    paket_kalan_seans,
    uye_aktif,
    donduruldu,
    odeme_alindi
  FROM uye_kullanicilar
  WHERE id = ?
    AND egitmen_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $uye_id, $egitmen_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) fail('Üye bulunamadı veya sana atanmış değil.', 403);

// UI kolaylığı: bugün bitiş geçti mi?
$today = date('Y-m-d');
$expired = false;
if (!empty($row['bitis_tarihi']) && $row['bitis_tarihi'] < $today) $expired = true;

echo json_encode([
  'ok' => true,
  'item' => [
    'uye_id' => (int)$row['id'],
    'ad_soyad' => trim(($row['ad'] ?? '').' '.($row['soyad'] ?? '')),
    'abonelik_tipi' => $row['abonelik_tipi'],
    'abonelik_durum' => $row['abonelik_durum'],
    'baslangic_tarihi' => $row['baslangic_tarihi'],
    'bitis_tarihi' => $row['bitis_tarihi'],
    'paket_toplam_seans' => isset($row['paket_toplam_seans']) ? (int)$row['paket_toplam_seans'] : null,
    'paket_kalan_seans' => isset($row['paket_kalan_seans']) ? (int)$row['paket_kalan_seans'] : null,
    'uye_aktif' => (int)$row['uye_aktif'],
    'donduruldu' => (int)$row['donduruldu'],
    'odeme_alindi' => (int)$row['odeme_alindi'],
    'expired' => $expired ? 1 : 0
  ]
], JSON_UNESCAPED_UNICODE);
