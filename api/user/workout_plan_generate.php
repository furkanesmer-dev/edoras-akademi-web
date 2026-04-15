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

// --- input ---
$hedefBolgeler = $body['hedef_bolgeler'] ?? $body['hedef_bolge'] ?? null;
$setSayisi = (int)($body['set_sayisi'] ?? 0);
$tekrarSayisi = (int)($body['tekrar_sayisi'] ?? 0);

$planNameIn = trim((string)($body['plan_name'] ?? ''));
$dayCount = (int)($body['day_count'] ?? 1);
$perRegion = (int)($body['per_region'] ?? 3);
$maxTotal = (int)($body['max_total'] ?? 12);

if (!is_array($hedefBolgeler)) json_fail('hedef_bolgeler array olmalı.', 400);

// temizle + uniq
$clean = [];
foreach ($hedefBolgeler as $hb) {
  $hb = trim((string)$hb);
  if ($hb !== '') $clean[] = $hb;
}
$clean = array_values(array_unique($clean));

if (count($clean) < 1) json_fail('En az 1 hedef bölge seçmelisin.', 400);
if ($setSayisi <= 0 || $setSayisi > 50) json_fail('Set sayısı geçersiz (1-50).', 400);
if ($tekrarSayisi <= 0 || $tekrarSayisi > 200) json_fail('Tekrar sayısı geçersiz (1-200).', 400);

if ($dayCount < 1 || $dayCount > 7) json_fail('day_count geçersiz (1-7).', 400);
if ($perRegion < 1 || $perRegion > 6) json_fail('per_region geçersiz (1-6).', 400);
if ($maxTotal < 1 || $maxTotal > 30) json_fail('max_total geçersiz (1-30).', 400);

// --- seçim ---
$selected = [];
$seenNames = [];

$sql = "SELECT egzersiz_ismi
        FROM egzersizler
        WHERE hedef_bolge = ?
        ORDER BY RAND()
        LIMIT $perRegion";

$stmt = $conn->prepare($sql);
if (!$stmt) json_fail('Sistem hatası.', 500);

foreach ($clean as $hb) {
  $stmt->bind_param('s', $hb);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $name = trim((string)($row['egzersiz_ismi'] ?? ''));
    if ($name === '') continue;
    if (isset($seenNames[$name])) continue;

    $seenNames[$name] = true;
    $selected[] = ['name' => $name, 'region' => $hb];

    if (count($selected) >= $maxTotal) break 2;
  }
}
$stmt->close();

if (count($selected) < 1) {
  json_fail('Seçilen hedef bölgeler için egzersiz bulunamadı.', 404);
}

// --- plan name ---
$today = date('Y-m-d');
$planName = $planNameIn !== '' ? $planNameIn : ('Kendi Planım • ' . $today);

// --- day distribution (round-robin) ---
$days = [];
for ($i = 1; $i <= $dayCount; $i++) {
  $days[] = [
    'day_name' => 'Gün ' . $i,
    'exercises' => [],
  ];
}

for ($i = 0; $i < count($selected); $i++) {
  $dIndex = $i % $dayCount;
  $days[$dIndex]['exercises'][] = [
    'exercise_name' => $selected[$i]['name'],
    'sets' => $setSayisi,
    'reps' => $tekrarSayisi,
  ];
}

$planData = [
  'plan_name' => $planName,
  'source' => 'self',
  'selected_regions' => $clean,
  'created_by_user_id' => $uid,
  'days' => $days,
];

$planJson = json_encode($planData, JSON_UNESCAPED_UNICODE);
if ($planJson === false) json_fail('Plan encode hatası.', 500);

// --- insert ---
$ins = $conn->prepare("INSERT INTO workout_plans (user_id, plan_data) VALUES (?, ?)");
if (!$ins) json_fail('Sistem hatası.', 500);

$ins->bind_param('is', $uid, $planJson);
$ok = $ins->execute();
$newId = (int)$ins->insert_id;
$ins->close();

if (!$ok || $newId <= 0) json_fail('Plan kaydedilemedi.', 500);

json_ok([
  'id' => $newId,
  'plan' => $planData,
]);