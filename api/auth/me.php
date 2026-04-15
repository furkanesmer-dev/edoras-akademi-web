<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

// Authorization: Bearer <token>
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('apache_request_headers')) {
  $headers = apache_request_headers();
  $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
  json_fail('Token yok.', 401);
}
$token = $m[1];

// Token doğrula
// utils/jwt.php içinde hangi fonksiyon varsa ona göre:
// - jwt_verify($token, JWT_SECRET)
// - jwt_verify($token, JWT_SECRET, ...)
// - jwt_decode / jwt_unsign vs.
//
// Aşağıdaki satırdaki fonksiyon adı SENİN jwt.php’ndeki verify fonksiyonuyla aynı olmalı.
$payload = jwt_verify($token, JWT_SECRET);

if (!$payload || empty($payload['uid'])) {
  json_fail('Geçersiz token.', 401);
}

$uid = (int)$payload['uid'];

$stmt = $conn->prepare("
  SELECT id, ad, soyad, eposta_adresi, tel_no, yetki, egitmen_id,
         uye_aktif, odeme_alindi, abonelik_durum, baslangic_tarihi, bitis_tarihi
  FROM uye_kullanicilar
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
  json_fail('Kullanıcı bulunamadı.', 404);
}

$yetki = $user['yetki'] ?: 'kullanici';

$me = [
  'id' => (int)$user['id'],
  'ad' => $user['ad'],
  'soyad' => $user['soyad'],
  'eposta_adresi' => $user['eposta_adresi'],
  'tel_no' => $user['tel_no'],
  'yetki' => $yetki,
  'egitmen_id' => $user['egitmen_id'] !== null ? (int)$user['egitmen_id'] : null,

  'uye_aktif' => (int)$user['uye_aktif'],
  'odeme_alindi' => (int)$user['odeme_alindi'],
  'abonelik_durum' => $user['abonelik_durum'],
  'baslangic_tarihi' => $user['baslangic_tarihi'],
  'bitis_tarihi' => $user['bitis_tarihi'],
];

json_ok([
  'me' => $me,
]);