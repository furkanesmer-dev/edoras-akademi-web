<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../jwt_auth.php'; // $user_id

if (!isset($user_id) || !$user_id) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Unauthorized"]);
  exit;
}

$stmt = $conn->prepare("
  SELECT user_id, formula, bmr, tdee, target_kcal, protein_g, karb_g, yag_g, is_manual_override, created_at, updated_at
  FROM uye_beslenme_hedefleri
  WHERE user_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

echo json_encode(["success" => true, "data" => $row ?: null]);