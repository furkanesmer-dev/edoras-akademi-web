<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc/db.php'; // ✅ Standart bağlantı

function respond($ok, $msg, $extra = [], $code = 200){
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>$ok, 'msg'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'Sadece POST desteklenir.', [], 405);
}

/**
 * Hem x-www-form-urlencoded (json_data=...) hem de raw JSON body destekle
 */
$raw = trim(file_get_contents('php://input') ?? '');
if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
  $jsonData = $raw;
} else {
  $jsonData = $_POST['json_data'] ?? '';
}

$userId = (int)($_POST['user_id'] ?? 0);

// Eğer body JSON geldiyse ve user_id post'ta yoksa json içinden de oku
if ($userId <= 0 && $jsonData !== '') {
  $tmp = json_decode($jsonData, true);
  if (is_array($tmp) && isset($tmp['user_id'])) {
    $userId = (int)$tmp['user_id'];
  }
}

if ($jsonData === '' || $userId <= 0) {
  respond(false, 'Eksik veri (json_data / user_id).', [
    'user_id' => $userId,
    'json_len' => strlen($jsonData)
  ], 400);
}

/**
 * JSON geçerli mi? (DB’ye bozuk JSON yazmayalım)
 */
$decoded = json_decode($jsonData, true);
if ($decoded === null) {
  respond(false, 'Geçersiz JSON: ' . json_last_error_msg(), [
    'sample' => mb_substr($jsonData, 0, 250)
  ], 400);
}

/**
 * (Opsiyonel) Aşırı büyük payload koruması
 * İstersen limiti artırırız.
 */
if (strlen($jsonData) > 2_000_000) {
  respond(false, 'JSON çok büyük (2MB üstü).', ['json_len'=>strlen($jsonData)], 413);
}

$sql = "INSERT INTO workout_plans (user_id, plan_data) VALUES (?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
  respond(false, 'Prepare hatası', ['error'=>$conn->error], 500);
}

$stmt->bind_param("is", $userId, $jsonData);

if ($stmt->execute()) {
  $newId = $stmt->insert_id;
  $stmt->close();
  respond(true, 'OK', ['id'=>$newId, 'user_id'=>$userId]);
} else {
  $err = $stmt->error;
  $stmt->close();
  respond(false, 'Execute hatası', ['error'=>$err], 500);
}