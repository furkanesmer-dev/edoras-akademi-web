<?php
// api/beslenme/porsiyonlar.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

try {
  $payload = require_user();
  $uid = (int)($payload['uid'] ?? 0);
  if ($uid <= 0) json_fail('Yetkisiz.', 401);

  $besin_id = (int)($_GET['besin_id'] ?? 0);
  if ($besin_id <= 0) {
    json_fail('besin_id zorunlu.', 400);
  }

  $sql = "
    SELECT id, besin_id, etiket, gram, varsayilan
    FROM besin_porsiyonlari
    WHERE besin_id = ?
    ORDER BY varsayilan DESC, gram ASC, id ASC
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

  $stmt->bind_param('i', $besin_id);

  if (!$stmt->execute()) {
    throw new Exception("Execute failed: " . $stmt->error);
  }

  $res = $stmt->get_result();
  $items = [];

  while ($row = $res->fetch_assoc()) {
    $items[] = [
      'id' => (int)$row['id'],
      'besin_id' => (int)$row['besin_id'],
      'etiket' => (string)$row['etiket'],
      'gram' => $row['gram'] !== null ? (float)$row['gram'] : 0.0,
      // ✅ flutter tarafı bool’a çeviriyor ama burada da stabil olsun
      'varsayilan' => (int)$row['varsayilan'],
    ];
  }

  json_ok(['items' => $items]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}