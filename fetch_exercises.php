<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/inc/db.php';

// Oturum kontrolü: giriş yapmamış kullanıcılar erişemez
if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Oturum yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = "SELECT egzersiz_ismi FROM egzersizler";
$result = $conn->query($query);

if (!$result) {
  error_log('fetch_exercises.php query hatası: ' . $conn->error);
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Sunucu hatası.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$exercises = [];
while ($row = $result->fetch_assoc()) {
  if (isset($row['egzersiz_ismi'])) {
    $exercises[] = $row['egzersiz_ismi'];
  }
}

echo json_encode($exercises, JSON_UNESCAPED_UNICODE);