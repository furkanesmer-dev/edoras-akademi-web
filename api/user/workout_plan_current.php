<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_fail('Sadece GET.', 405);

$payload = require_user();
$uid = (int)$payload['uid'];

$stmt = $conn->prepare("
  SELECT id, user_id, plan_data, created_at
  FROM workout_plans
  WHERE user_id = ?
  ORDER BY id DESC
  LIMIT 1
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
  json_ok([
    'has_plan' => false,
    'plan' => null
  ]);
}

$planRaw = (string)$row['plan_data'];
$decoded = json_decode($planRaw, true);
$plan = is_array($decoded) ? $decoded : $planRaw;

json_ok([
  'has_plan' => true,
  'id' => (int)$row['id'],
  'created_at' => $row['created_at'],
  'plan' => $plan,
]);