<?php
// api/beslenme/gunluk_sil.php

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

  $oge_id = (int)($body['oge_id'] ?? 0);
  $tarih = trim((string)($body['tarih'] ?? ''));
  $meal = (int)($body['meal'] ?? 0);
  $besin_id = (int)($body['besin_id'] ?? 0);

  // 1) Direkt oge_id ile silme
  if ($oge_id > 0) {
    $s = $conn->prepare("
      SELECT o.id
      FROM uye_beslenme_ogeler o
      JOIN uye_beslenme_gunluk g ON g.id = o.gunluk_id
      WHERE o.id = ? AND g.user_id = ?
      LIMIT 1
    ");
    if (!$s) throw new Exception("Prepare failed (select by oge_id): " . $conn->error);

    $s->bind_param("ii", $oge_id, $uid);
    if (!$s->execute()) throw new Exception("Execute failed (select by oge_id): " . $s->error);

    $res = $s->get_result();
    $row = $res->fetch_assoc();
    $s->close();

    if ($row) {
      $d = $conn->prepare("DELETE FROM uye_beslenme_ogeler WHERE id = ? LIMIT 1");
      if (!$d) throw new Exception("Prepare failed (delete by oge_id): " . $conn->error);

      $d->bind_param("i", $oge_id);
      if (!$d->execute()) throw new Exception("Execute failed (delete by oge_id): " . $d->error);

      json_ok(['deleted' => $d->affected_rows]);
    }
  }

  // 2) Fallback: tarih + meal + besin_id ile sil
  if ($tarih === '' || $meal < 1 || $meal > 5 || $besin_id <= 0) {
    json_ok(['deleted' => 0]);
  }

  $dt = DateTime::createFromFormat('Y-m-d', $tarih);
  if (!$dt || $dt->format('Y-m-d') !== $tarih) {
    json_fail('Geçersiz tarih formatı. (YYYY-MM-DD)', 400);
  }

  $g = $conn->prepare("
    SELECT id
    FROM uye_beslenme_gunluk
    WHERE user_id = ? AND tarih = ?
    LIMIT 1
  ");
  if (!$g) throw new Exception("Prepare failed (select gunluk): " . $conn->error);

  $g->bind_param("is", $uid, $tarih);
  if (!$g->execute()) throw new Exception("Execute failed (select gunluk): " . $g->error);

  $gres = $g->get_result();
  $grow = $gres->fetch_assoc();
  $g->close();

  if (!$grow) {
    json_ok(['deleted' => 0]);
  }

  $gunluk_id = (int)$grow['id'];

  // Aynı besinin o öğündeki tüm tekrarlarını temizle
  $d2 = $conn->prepare("
    DELETE FROM uye_beslenme_ogeler
    WHERE gunluk_id = ? AND meal = ? AND besin_id = ?
  ");
  if (!$d2) throw new Exception("Prepare failed (delete by besin): " . $conn->error);

  $d2->bind_param("iii", $gunluk_id, $meal, $besin_id);
  if (!$d2->execute()) throw new Exception("Execute failed (delete by besin): " . $d2->error);

  json_ok(['deleted' => $d2->affected_rows]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}