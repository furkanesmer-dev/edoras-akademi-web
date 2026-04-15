<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$__sent = false;

function __send_json(array $payload, int $code = 200): void {
  global $__sent;
  if ($__sent) return;
  $__sent = true;

  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// Fatal (E_ERROR) yakala: content-length 0 olmasın
register_shutdown_function(function () {
  global $__sent;
  if ($__sent) return;

  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log('photo_upload.php fatal: ' . ($err['message'] ?? ''));
    __send_json(['ok' => false, 'error' => 'Sunucu hatası.'], 500);
  }
});

set_exception_handler(function (Throwable $e) {
  error_log('photo_upload.php exception: ' . $e->getMessage());
  __send_json(['ok' => false, 'error' => 'Sunucu hatası.'], 500);
});

// ✅ Auth zinciri burada: require_user() bu dosyadan geliyor
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

$user = require_user();

// ✅ Senin auth.php fonksiyonun uid döndürüyor
$user_id = (int)($user['uid'] ?? 0);
if ($user_id <= 0) {
  json_fail('Yetkisiz.', 401);
}

if (!isset($_FILES['photo'])) {
  json_fail('photo dosyası zorunlu.', 400);
}

$f = $_FILES['photo'];

if (!is_array($f) || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
  $err = $f['error'] ?? null;
  json_fail('Dosya yükleme hatası: ' . (string)$err, 400);
}

$maxBytes = 5 * 1024 * 1024;
$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
  json_fail('Dosya boyutu 0 olamaz ve 5MB üstü kabul edilmez.', 400);
}

$tmpPath = (string)($f['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
  json_fail('Geçersiz yükleme.', 400);
}

// MIME tespiti (finfo yoksa fallback)
$mime = '';
if (class_exists('finfo')) {
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)($fi->file($tmpPath) ?: '');
} elseif (function_exists('mime_content_type')) {
  $mime = (string)(mime_content_type($tmpPath) ?: '');
} else {
  $info = @getimagesize($tmpPath);
  $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
}

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
  json_fail('Sadece JPG, PNG, WEBP kabul edilir. Gelen mime: ' . $mime, 400);
}

$ext = $allowed[$mime];

// /api/profile/photo_upload.php -> 2 yukarı (genelde public_html)
$publicRoot = realpath(__DIR__ . '/../../');
if ($publicRoot === false) {
  json_fail('Sunucu yolu çözümlenemedi.', 500);
}

$relativeDir = 'uploads/profile/' . $user_id;
$absoluteDir = $publicRoot . DIRECTORY_SEPARATOR . $relativeDir;

if (!is_dir($absoluteDir)) {
  if (!mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
    error_log('photo_upload.php: klasör oluşturulamadı: ' . $absoluteDir);
    json_fail('Sunucu hatası: klasör oluşturulamadı.', 500);
  }
}

$filename = 'profile_' . time() . '.' . $ext;

$absoluteFile = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
$relativePath = $relativeDir . '/' . $filename;

if (!move_uploaded_file($tmpPath, $absoluteFile)) {
  json_fail('Dosya taşınamadı.', 500);
}

// DB bağlantı kontrolü (inc/db.php içinde $conn bekleniyor)
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (is_file($absoluteFile)) @unlink($absoluteFile);
  json_fail('DB bağlantısı bulunamadı. inc/db.php içinde $conn (mysqli) olmalı.', 500);
}

try {
  $stmtOld = $conn->prepare("SELECT foto_yolu FROM uye_kullanicilar WHERE id=? LIMIT 1");
  $stmtOld->bind_param("i", $user_id);
  $stmtOld->execute();
  $resOld = $stmtOld->get_result();
  $old = $resOld ? (string)($resOld->fetch_assoc()['foto_yolu'] ?? '') : '';
  $stmtOld->close();

  $stmt = $conn->prepare("UPDATE uye_kullanicilar SET foto_yolu=? WHERE id=?");
  $stmt->bind_param("si", $relativePath, $user_id);
  $stmt->execute();
  $stmt->close();

  if ($old !== '' && $old !== $relativePath) {
    $oldAbs = $publicRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old);
    if (is_file($oldAbs)) @unlink($oldAbs);
  }
} catch (Throwable $e) {
  error_log('photo_upload.php DB hatası: ' . $e->getMessage());
  if (is_file($absoluteFile)) @unlink($absoluteFile);
  json_fail('Veritabanı hatası oluştu.', 500);
}

$baseUrl = getenv('APP_URL') ?: 'https://kocluk.edorasakademi.com';
$photoUrl = rtrim($baseUrl, '/') . '/' . $relativePath;

json_ok([
  'photo_path' => $relativePath,
  'photo_url'  => $photoUrl,
]);