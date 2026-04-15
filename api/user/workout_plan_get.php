<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../utils/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

$planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($planId <= 0) json_fail('Geçersiz id.', 400);

/* === AUTH === */
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

/* === PLAN === */
$stmt = $conn->prepare("
  SELECT id, user_id, created_at, plan_data
  FROM workout_plans
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $planId, $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) json_fail('Plan bulunamadı.', 404);

$pd = json_decode($row['plan_data'] ?? '', true);
if (!is_array($pd)) $pd = [];

/* === EGZERSİZ GİF’LERİNİ TOPLA === */
$exerciseNames = [];

foreach (($pd['days'] ?? []) as $day) {
  foreach (($day['exercises'] ?? []) as $ex) {
    if (!empty($ex['exercise_name'])) {
      $exerciseNames[] = $ex['exercise_name'];
    }
  }
}

$exerciseNames = array_unique($exerciseNames);
$gifMap = [];

if (!empty($exerciseNames)) {
  $placeholders = implode(',', array_fill(0, count($exerciseNames), '?'));
  $types = str_repeat('s', count($exerciseNames));

  $sql = "
    SELECT egzersiz_ismi, egzersiz_gif
    FROM egzersizler
    WHERE egzersiz_ismi IN ($placeholders)
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$exerciseNames);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $gifMap[$r['egzersiz_ismi']] = $r['egzersiz_gif'];
  }
  $stmt->close();
}

/* === GİF’LERİ PLAN DATA’YA EKLE === */
foreach ($pd['days'] ?? [] as $dIdx => $day) {
  foreach ($day['exercises'] ?? [] as $eIdx => $ex) {
    $name = $ex['exercise_name'] ?? null;
    if ($name && isset($gifMap[$name])) {
      $pd['days'][$dIdx]['exercises'][$eIdx]['exercise_gif'] = $gifMap[$name];
    }
  }
}

json_ok([
  'id' => (int)$row['id'],
  'created_at' => $row['created_at'],
  'plan' => $pd,
]);