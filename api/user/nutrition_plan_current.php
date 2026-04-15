<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

try {
  $payload = require_user();
  $uid = (int)($payload['uid'] ?? 0);

  if ($uid <= 0) {
    json_fail('Yetkisiz.', 401);
  }

  // 1) Son program
  $stmt = $conn->prepare("
    SELECT id, user_id, hedef, notlar, created_at
    FROM beslenme_programlar
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  if (!$stmt) {
    throw new Exception("Prepare failed (program): " . $conn->error);
  }

  $stmt->bind_param("i", $uid);
  if (!$stmt->execute()) {
    throw new Exception("Execute failed (program): " . $stmt->error);
  }

  $res = $stmt->get_result();
  $program = $res->fetch_assoc();
  $stmt->close();

  if (!$program) {
    json_ok([
      'has_plan' => false,
      'program' => null,
      'items' => [],
      'by_ogun' => new stdClass(),
    ]);
  }

  $program_id = (int)$program['id'];

  // 2) Öğeler
  // besin_id ve porsiyon_id mutlaka dönüyor
  $stmt2 = $conn->prepare("
    SELECT
      id,
      program_id,
      besin_id,
      porsiyon_id,
      ogun,
      yemek,
      miktar,
      birim,
      kalori,
      karbonhidrat,
      protein,
      yag
    FROM beslenme_program_ogeler
    WHERE program_id = ?
    ORDER BY FIELD(ogun,'Sabah','Kahvaltı','Öğle','Og̈le','Öğlen','Ara Öğün','Ara Ogun','Akşam','Gece'), id ASC
  ");
  if (!$stmt2) {
    throw new Exception("Prepare failed (ogeler): " . $conn->error);
  }

  $stmt2->bind_param("i", $program_id);
  if (!$stmt2->execute()) {
    throw new Exception("Execute failed (ogeler): " . $stmt2->error);
  }

  $res2 = $stmt2->get_result();

  $items = [];
  while ($r = $res2->fetch_assoc()) {
    $items[] = [
      'id' => (int)$r['id'],
      'program_id' => (int)$r['program_id'],
      'besin_id' => isset($r['besin_id']) ? (int)$r['besin_id'] : null,
      'porsiyon_id' => isset($r['porsiyon_id']) ? (int)$r['porsiyon_id'] : null,
      'ogun' => $r['ogun'],
      'yemek' => $r['yemek'],
      'miktar' => $r['miktar'] !== null ? (float)$r['miktar'] : 0,
      'birim' => $r['birim'],
      'kalori' => $r['kalori'] !== null ? (float)$r['kalori'] : 0,
      'karbonhidrat' => $r['karbonhidrat'] !== null ? (float)$r['karbonhidrat'] : 0,
      'protein' => $r['protein'] !== null ? (float)$r['protein'] : 0,
      'yag' => $r['yag'] !== null ? (float)$r['yag'] : 0,
    ];
  }
  $stmt2->close();

  // 3) Öğüne göre grupla
  $byOgun = [];
  foreach ($items as $it) {
    $k = trim((string)($it['ogun'] ?? ''));
    if ($k === '') $k = 'Diğer';

    if (!isset($byOgun[$k])) {
      $byOgun[$k] = [];
    }
    $byOgun[$k][] = $it;
  }

  json_ok([
    'has_plan' => true,
    'program' => [
      'id' => $program_id,
      'created_at' => $program['created_at'],
      'hedef' => $program['hedef'],
      'notlar' => $program['notlar'],
    ],
    'items' => $items,
    'by_ogun' => $byOgun,
  ]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}