<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../jwt_auth.php'; // $user_id

if (!isset($user_id) || !$user_id) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Unauthorized"]);
  exit;
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$target = isset($body["target_kcal"]) ? intval($body["target_kcal"]) : 0;
$protein = isset($body["protein_g"]) ? intval($body["protein_g"]) : 0;
$carb = isset($body["karb_g"]) ? intval($body["karb_g"]) : 0;
$fat = isset($body["yag_g"]) ? intval($body["yag_g"]) : 0;

if ($target <= 0 || $protein < 0 || $carb < 0 || $fat < 0) {
  http_response_code(422);
  echo json_encode(["success" => false, "message" => "Geçersiz değer."]);
  exit;
}

$up = $conn->prepare("
  INSERT INTO uye_beslenme_hedefleri
    (user_id, formula, bmr, tdee, target_kcal, protein_g, karb_g, yag_g, is_manual_override)
  VALUES
    (?, 'mifflin', 0, 0, ?, ?, ?, ?, 1)
  ON DUPLICATE KEY UPDATE
    target_kcal = VALUES(target_kcal),
    protein_g = VALUES(protein_g),
    karb_g = VALUES(karb_g),
    yag_g = VALUES(yag_g),
    is_manual_override = 1,
    updated_at = CURRENT_TIMESTAMP
");
$uid = intval($user_id);
$up->bind_param("iiiii", $uid, $target, $protein, $carb, $fat);

if (!$up->execute()) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "DB write failed"]);
  exit;
}

echo json_encode(["success" => true]);