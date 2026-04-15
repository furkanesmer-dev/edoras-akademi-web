<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php'; // require_user()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

$payload = require_user();
$uid = (int)($payload['uid'] ?? 0);
if ($uid <= 0) json_fail('Yetkisiz.', 401);

$body = get_json_body();

$baseId = (int)($body['id'] ?? 0);
$plan = $body['plan'] ?? null;

if ($baseId <= 0) json_fail('id zorunlu.', 400);
if (!is_array($plan)) json_fail('plan map olmalı.', 400);

// sahiplik kontrolü
$chk = $conn->prepare("SELECT id FROM workout_plans WHERE id = ? AND user_id = ? LIMIT 1");
if (!$chk) json_fail('Sistem hatası.', 500);
$chk->bind_param("ii", $baseId, $uid);
$chk->execute();
$r = $chk->get_result();
$exists = (bool)$r->fetch_assoc();
$chk->close();
if (!$exists) json_fail('Plan bulunamadı.', 404);

// minimum validasyon
$planName = trim((string)($plan['plan_name'] ?? ''));
$days = $plan['days'] ?? null;

if ($planName === '') json_fail('plan_name zorunlu.', 400);
if (!is_array($days) || count($days) < 1) json_fail('days zorunlu.', 400);

foreach ($days as $d) {
  if (!is_array($d)) json_fail('days formatı hatalı.', 400);
  $ex = $d['exercises'] ?? null;
  if (!is_array($ex)) json_fail('exercises formatı hatalı.', 400);
  foreach ($ex as $e) {
    if (!is_array($e)) json_fail('exercise formatı hatalı.', 400);
    $en = trim((string)($e['exercise_name'] ?? ''));
    $sets = (int)($e['sets'] ?? 0);
    $reps = (int)($e['reps'] ?? 0);
    if ($en === '') json_fail('exercise_name boş olamaz.', 400);
    if ($sets <= 0 || $sets > 50) json_fail('sets geçersiz.', 400);
    if ($reps <= 0 || $reps > 200) json_fail('reps geçersiz.', 400);
  }
}

// meta ekle
$plan['source'] = $plan['source'] ?? 'self';
$plan['updated_from_id'] = $baseId;
$plan['updated_by_user_id'] = $uid;

$planJson = json_encode($plan, JSON_UNESCAPED_UNICODE);
if ($planJson === false) json_fail('Plan encode hatası.', 500);

// yeni kayıt ekle (versioning)
$ins = $conn->prepare("INSERT INTO workout_plans (user_id, plan_data) VALUES (?, ?)");
if (!$ins) json_fail('Sistem hatası.', 500);

$ins->bind_param('is', $uid, $planJson);
$ok = $ins->execute();
$newId = (int)$ins->insert_id;
$ins->close();

if (!$ok || $newId <= 0) json_fail('Plan kaydedilemedi.', 500);

json_ok([
  'id' => $newId,
  'base_id' => $baseId,
  'plan' => $plan,
]);