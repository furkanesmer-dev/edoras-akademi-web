<?php
// api/beslenme/gunluk_ekle.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

try {
  $payload = require_user();
  $uid = (int)($payload['uid'] ?? 0);
  if ($uid <= 0) json_fail('Yetkisiz.', 401);

  $body = get_json_body();

  $tarih = trim((string)($body['tarih'] ?? ''));
  if ($tarih === '') $tarih = date('Y-m-d');

  $dt = DateTime::createFromFormat('Y-m-d', $tarih);
  if (!$dt || $dt->format('Y-m-d') !== $tarih) {
    json_fail('Geçersiz tarih formatı. (YYYY-MM-DD)', 400);
  }

  $meal = (int)($body['meal'] ?? 0);
  if ($meal < 1 || $meal > 5) {
    json_fail('meal 1-5 olmalı.', 400);
  }

  $besin_id = (int)($body['besin_id'] ?? 0);
  if ($besin_id <= 0) {
    json_fail('besin_id zorunlu.', 400);
  }

  $porsiyon_id = (int)($body['porsiyon_id'] ?? 0);
  $adet = isset($body['adet']) ? (float)$body['adet'] : 1.0;
  $gram = isset($body['gram']) ? (float)$body['gram'] : 0.0;

  $besin_ad = trim((string)($body['besin_ad'] ?? ''));
  $kalori = isset($body['kalori']) ? (float)$body['kalori'] : null;
  $protein = isset($body['protein']) ? (float)$body['protein'] : null;
  $karbonhidrat = isset($body['karbonhidrat']) ? (float)$body['karbonhidrat'] : null;
  $yag = isset($body['yag']) ? (float)$body['yag'] : null;

  if ($adet <= 0) $adet = 1.0;
  if ($gram < 0) $gram = 0.0;

  $hasSnapshotMacros =
    $kalori !== null &&
    $protein !== null &&
    $karbonhidrat !== null &&
    $yag !== null;

  $conn->begin_transaction();

  // 1) Günlük kaydı oluştur/çek
  $istmt = $conn->prepare("
    INSERT IGNORE INTO uye_beslenme_gunluk (user_id, tarih)
    VALUES (?, ?)
  ");
  if (!$istmt) {
    throw new Exception("Prepare failed (insert gunluk): " . $conn->error);
  }

  $istmt->bind_param("is", $uid, $tarih);
  if (!$istmt->execute()) {
    throw new Exception("Execute failed (insert gunluk): " . $istmt->error);
  }
  $istmt->close();

  $gstmt = $conn->prepare("
    SELECT id
    FROM uye_beslenme_gunluk
    WHERE user_id = ? AND tarih = ?
    LIMIT 1
  ");
  if (!$gstmt) {
    throw new Exception("Prepare failed (select gunluk): " . $conn->error);
  }

  $gstmt->bind_param("is", $uid, $tarih);
  if (!$gstmt->execute()) {
    throw new Exception("Execute failed (select gunluk): " . $gstmt->error);
  }

  $gres = $gstmt->get_result();
  $grow = $gres->fetch_assoc();
  $gstmt->close();

  if (!$grow) {
    throw new Exception("Günlük kaydı oluşturulamadı.");
  }

  $gunluk_id = (int)$grow['id'];

  // 2) Aynı gün / aynı öğün / aynı besin zaten ekliyse tekrar ekleme
  $cstmt = $conn->prepare("
    SELECT id, gunluk_id, meal, besin_id, porsiyon_id, adet, gram, kalori, protein, karbonhidrat, yag
    FROM uye_beslenme_ogeler
    WHERE gunluk_id = ? AND meal = ? AND besin_id = ?
    ORDER BY id ASC
    LIMIT 1
  ");
  if (!$cstmt) {
    throw new Exception("Prepare failed (check duplicate): " . $conn->error);
  }

  $cstmt->bind_param("iii", $gunluk_id, $meal, $besin_id);
  if (!$cstmt->execute()) {
    throw new Exception("Execute failed (check duplicate): " . $cstmt->error);
  }

  $cres = $cstmt->get_result();
  $existing = $cres->fetch_assoc();
  $cstmt->close();

  if ($existing) {
    $conn->commit();

    json_ok([
      'gunluk_id' => (int)$existing['gunluk_id'],
      'oge_id' => (int)$existing['id'],
      'tarih' => $tarih,
      'meal' => (int)$existing['meal'],
      'besin_id' => (int)$existing['besin_id'],
      'porsiyon_id' => (int)$existing['porsiyon_id'],
      'adet' => (float)$existing['adet'],
      'gram' => (float)$existing['gram'],
      'kalori' => (float)$existing['kalori'],
      'protein' => (float)$existing['protein'],
      'karbonhidrat' => (float)$existing['karbonhidrat'],
      'yag' => (float)$existing['yag'],
      'already_exists' => true,
    ]);
  }

  // 3) Snapshot makroları yoksa fallback hesap
  if (!$hasSnapshotMacros) {
    $dst = $conn->prepare("
      SELECT
        kalori_100,
        protein_100,
        yag_100,
        karbonhidrat_100
      FROM besin_degerleri
      WHERE besin_id = ?
      LIMIT 1
    ");

    if ($dst && $dst->bind_param("i", $besin_id) && $dst->execute()) {
      $dres = $dst->get_result();
      $drow = $dres->fetch_assoc();
      $dst->close();

      if ($drow) {
        if ($gram <= 0) {
          $gram = 100.0 * $adet;
        }

        $factor = $gram / 100.0;
        $kalori = ((float)$drow['kalori_100']) * $factor;
        $protein = ((float)$drow['protein_100']) * $factor;
        $karbonhidrat = ((float)$drow['karbonhidrat_100']) * $factor;
        $yag = ((float)$drow['yag_100']) * $factor;
      }
    }

    if ($kalori === null || $protein === null || $karbonhidrat === null || $yag === null) {
      throw new Exception('Plan snapshot makroları yok ve besin_degerleri ile hesap yapılamadı.');
    }
  }

  // 4) Öğeyi ekle
  $ostmt = $conn->prepare("
    INSERT INTO uye_beslenme_ogeler
      (gunluk_id, meal, besin_id, porsiyon_id, adet, gram, kalori, protein, karbonhidrat, yag)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$ostmt) {
    throw new Exception("Prepare failed (insert oge): " . $conn->error);
  }

  $ostmt->bind_param(
    "iiiidddddd",
    $gunluk_id,
    $meal,
    $besin_id,
    $porsiyon_id,
    $adet,
    $gram,
    $kalori,
    $protein,
    $karbonhidrat,
    $yag
  );

  if (!$ostmt->execute()) {
    throw new Exception("Execute failed (insert oge): " . $ostmt->error);
  }

  $oge_id = (int)$conn->insert_id;
  $ostmt->close();

  $conn->commit();

  json_ok([
    'gunluk_id' => $gunluk_id,
    'oge_id' => $oge_id,
    'tarih' => $tarih,
    'meal' => $meal,
    'besin_id' => $besin_id,
    'porsiyon_id' => $porsiyon_id,
    'adet' => $adet,
    'gram' => $gram,
    'kalori' => $kalori,
    'protein' => $protein,
    'karbonhidrat' => $karbonhidrat,
    'yag' => $yag,
    'already_exists' => false,
  ]);

} catch (Throwable $e) {
  if (isset($conn) && $conn instanceof mysqli) {
    try { $conn->rollback(); } catch (Throwable $x) {}
  }
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}