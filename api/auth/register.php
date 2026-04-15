<?php
// /api/auth/register.php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

$body = get_json_body();

$ad     = trim((string)($body['ad'] ?? ''));
$soyad  = trim((string)($body['soyad'] ?? ''));
$email  = trim((string)($body['email'] ?? ''));
$phone  = trim((string)($body['phone'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($ad === '' || $soyad === '' || $email === '' || $phone === '' || $password === '') {
  json_fail('Ad, soyad, telefon, e-posta ve şifre zorunludur.', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_fail('Geçerli bir e-posta giriniz.', 400);
}

if (strlen($password) < 6) {
  json_fail('Şifre en az 6 karakter olmalıdır.', 400);
}

// telefon normalize
$phone = preg_replace('/\D+/', '', $phone);
if (strlen($phone) < 10) {
  json_fail('Telefon numarası geçersiz.', 400);
}

// aynı email / telefon var mı?
$stmt = $conn->prepare("
  SELECT id
  FROM uye_kullanicilar
  WHERE eposta_adresi = ? OR tel_no = ?
  LIMIT 1
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("ss", $email, $phone);
$stmt->execute();
$res = $stmt->get_result();
$exists = $res->fetch_assoc();
$stmt->close();

if ($exists) {
  json_fail('Bu e-posta veya telefon zaten kayıtlı.', 409);
}

// insert
$hash  = password_hash($password, PASSWORD_DEFAULT);
$yetki = 'kullanici';

$stmt = $conn->prepare("
  INSERT INTO uye_kullanicilar
    (ad, soyad, eposta_adresi, tel_no, sifre, yetki, uye_aktif)
  VALUES (?, ?, ?, ?, ?, ?, 1)
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("ssssss", $ad, $soyad, $email, $phone, $hash, $yetki);
$stmt->execute();
$userId = $stmt->insert_id;
$stmt->close();

// JWT üret (login.php ile aynı stil)
$token = jwt_sign([
  'uid'   => (int)$userId,
  'yetki' => $yetki,
], JWT_SECRET, JWT_TTL_SECONDS);

json_ok([
  'token' => $token,
]);