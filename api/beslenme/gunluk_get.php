<?php
// api/beslenme/gunluk_get.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

function table_exists(mysqli $conn, string $table): bool {
  $stmt = $conn->prepare("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?
    LIMIT 1
  ");
  if (!$stmt) return false;

  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = (bool)$res->fetch_assoc();
  $stmt->close();

  return $ok;
}

try {
  $payload = require_user();
  $uid = (int)($payload['uid'] ?? 0);
  if ($uid <= 0) json_fail('Yetkisiz.', 401);

  $tarih = trim((string)($_GET['tarih'] ?? ''));
  if ($tarih === '') {
    $tarih = date('Y-m-d');
  }

  $dt = DateTime::createFromFormat('Y-m-d', $tarih);
  if (!$dt || $dt->format('Y-m-d') !== $tarih) {
    json_fail('Geçersiz tarih formatı. (YYYY-MM-DD)', 400);
  }

  $gstmt = $conn->prepare("
    SELECT id
    FROM uye_beslenme_gunluk
    WHERE user_id = ? AND tarih = ?
    LIMIT 1
  ");
  if (!$gstmt) throw new Exception("Prepare failed (gunluk): " . $conn->error);

  $gstmt->bind_param("is", $uid, $tarih);
  $gstmt->execute();
  $gres = $gstmt->get_result();
  $grow = $gres->fetch_assoc();
  $gstmt->close();

  $gunluk_id = $grow ? (int)$grow['id'] : 0;

  $items_by_meal = [
    '1' => [],
    '2' => [],
    '3' => [],
    '4' => [],
    '5' => [],
  ];

  if ($gunluk_id > 0) {
    $hasPorsiyonTable = table_exists($conn, 'besin_porsiyonlari');

    if ($hasPorsiyonTable) {
      $sql = "
        SELECT
          o.id,
          o.meal,
          o.besin_id,
          o.porsiyon_id,
          o.adet,
          o.gram,
          o.kalori,
          o.protein,
          o.karbonhidrat,
          o.yag,
          o.created_at,
          b.ad AS besin_ad,
          bp.etiket AS porsiyon_etiket
        FROM uye_beslenme_ogeler o
        LEFT JOIN besinler b ON b.id = o.besin_id
        LEFT JOIN besin_porsiyonlari bp ON bp.id = o.porsiyon_id
        WHERE o.gunluk_id = ?
        ORDER BY o.meal ASC, o.id ASC
      ";
    } else {
      $sql = "
        SELECT
          o.id,
          o.meal,
          o.besin_id,
          o.porsiyon_id,
          o.adet,
          o.gram,
          o.kalori,
          o.protein,
          o.karbonhidrat,
          o.yag,
          o.created_at,
          b.ad AS besin_ad,
          NULL AS porsiyon_etiket
        FROM uye_beslenme_ogeler o
        LEFT JOIN besinler b ON b.id = o.besin_id
        WHERE o.gunluk_id = ?
        ORDER BY o.meal ASC, o.id ASC
      ";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed (ogeler): " . $conn->error);

    $stmt->bind_param("i", $gunluk_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
      $meal = (string)(int)$r['meal'];
      if (!isset($items_by_meal[$meal])) $items_by_meal[$meal] = [];

      $items_by_meal[$meal][] = [
        'id' => (int)$r['id'],
        'meal' => (int)$r['meal'],
        'besin_id' => (int)$r['besin_id'],
        'porsiyon_id' => (int)$r['porsiyon_id'],
        'adet' => (float)$r['adet'],
        'gram' => (float)$r['gram'],
        'kalori' => (float)$r['kalori'],
        'protein' => (float)$r['protein'],
        'karbonhidrat' => (float)$r['karbonhidrat'],
        'yag' => (float)$r['yag'],
        'besin_ad' => $r['besin_ad'] ?? '',
        'porsiyon_etiket' => $r['porsiyon_etiket'] ?? '',
        'created_at' => $r['created_at'],
      ];
    }

    $stmt->close();
  }

  json_ok([
    'tarih' => $tarih,
    'gunluk_id' => $gunluk_id,
    'items_by_meal' => $items_by_meal,
  ]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}