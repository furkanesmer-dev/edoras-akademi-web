<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../jwt_auth.php'; // $user_id
require_once __DIR__ . '/_logic.php';

if (!isset($user_id) || !$user_id) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Unauthorized"]);
  exit;
}

try {
  $data = recalcTargets($conn, intval($user_id));
  echo json_encode(["success" => true, "data" => $data]);
} catch (Throwable $e) {
  http_response_code(422);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}