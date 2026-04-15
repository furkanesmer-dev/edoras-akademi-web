<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

$body = get_json_body();
$login = trim((string)($body['login'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($login === '' || $password === '') {
  json_fail('E-posta/telefon ve şifre zorunludur.', 400);
}

$stmt = $conn->prepare("
  SELECT id, ad, soyad, eposta_adresi, tel_no, sifre, yetki, egitmen_id,
         uye_aktif, odeme_alindi, abonelik_durum, baslangic_tarihi, bitis_tarihi
  FROM uye_kullanicilar
  WHERE eposta_adresi = ? OR tel_no = ?
  LIMIT 1
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("ss", $login, $login);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user || empty($user['sifre']) || !password_verify($password, $user['sifre'])) {
  json_fail('E-posta/telefon veya şifre hatalı.', 401);
}

$yetki = $user['yetki'] ?: 'kullanici';

// Token payload (minimum)
$token = jwt_sign([
  'uid'   => (int)$user['id'],
  'yetki' => $yetki,
], JWT_SECRET, JWT_TTL_SECONDS);

// Mobilde işine yarayacak “me” datası
$me = [
  'id' => (int)$user['id'],
  'ad' => $user['ad'],
  'soyad' => $user['soyad'],
  'eposta_adresi' => $user['eposta_adresi'],
  'tel_no' => $user['tel_no'],
  'yetki' => $yetki,
  'egitmen_id' => $user['egitmen_id'] !== null ? (int)$user['egitmen_id'] : null,

  // abonelik/aktiflik (mobilde karar için)
  'uye_aktif' => (int)$user['uye_aktif'],
  'odeme_alindi' => (int)$user['odeme_alindi'],
  'abonelik_durum' => $user['abonelik_durum'],
  'baslangic_tarihi' => $user['baslangic_tarihi'],
  'bitis_tarihi' => $user['bitis_tarihi'],
];

json_ok([
  'token' => $token,
  'me' => $me,
]);