<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

$token = bearer_token();
if (!$token) json_fail('Token yok.', 401);

$payload = jwt_verify($token, JWT_SECRET);
if (!$payload || empty($payload['uid'])) json_fail('Token geçersiz/expired.', 401);

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

if (!$user) json_fail('Kullanıcı bulunamadı.', 404);

if (empty($user['yetki'])) $user['yetki'] = 'kullanici';
$user['id'] = (int)$user['id'];
$user['egitmen_id'] = $user['egitmen_id'] !== null ? (int)$user['egitmen_id'] : null;
$user['uye_aktif'] = (int)$user['uye_aktif'];
$user['odeme_alindi'] = (int)$user['odeme_alindi'];

json_ok($user);