<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('apache_request_headers')) {
  $headers = apache_request_headers();
  $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
  json_fail('Token yok.', 401);
}
$token = $m[1];

$payload = jwt_verify($token, JWT_SECRET);
if (!$payload || empty($payload['uid'])) {
  json_fail('Geçersiz token.', 401);
}
$uid = (int)$payload['uid'];

$stmt = $conn->prepare("
  SELECT id, created_at, plan_data
  FROM workout_plans
  WHERE user_id = ?
  ORDER BY id DESC
");
if (!$stmt) json_fail('Sistem hatası.', 500);

$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
  $pd = json_decode($row['plan_data'] ?? '', true);
  if (!is_array($pd)) $pd = null;

  $name = null;
  $daysCount = 0;
  $exCount = 0;

  if (is_array($pd)) {
    $name = $pd['plan_name'] ?? $pd['name'] ?? $pd['title'] ?? null;
    if (is_string($name)) $name = trim($name);
    if ($name === '') $name = null;

    $days = $pd['days'] ?? null;
    if (is_array($days)) {
      $daysCount = count($days);
      foreach ($days as $d) {
        if (is_array($d)) {
          $ex = $d['exercises'] ?? null;
          if (is_array($ex)) $exCount += count($ex);
        }
      }
    }
  }

  $items[] = [
    'id' => (int)$row['id'],
    'created_at' => $row['created_at'],
    'plan_name' => $name,
    'days_count' => $daysCount,
    'exercises_count' => $exCount,
  ];
}
$stmt->close();

// ✅ en son plan = aktif
$active_id = !empty($items) ? $items[0]['id'] : null;

json_ok([
  'active_id' => $active_id,
  'items' => $items,
]);