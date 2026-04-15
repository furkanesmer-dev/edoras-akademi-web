<?php
// api/beslenme/besin_ara.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

try {
  $payload = require_user();
  $uid = (int)($payload['uid'] ?? 0);
  if ($uid <= 0) json_fail('Yetkisiz.', 401);

  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
    json_ok(['items' => []]);
  }

  // Türkçe normalize (DB'de ad_normalize ile eşleştirmek için)
  function tr_normalize(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $map = ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','â'=>'a','î'=>'i','û'=>'u'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  }

  $qn = tr_normalize($q);
  if ($qn === '' || mb_strlen($qn, 'UTF-8') < 2) {
    json_ok(['items' => []]);
  }

  $likeAny = '%' . $qn . '%';

  // ✅ Aynı besin için birden fazla varsayılan porsiyon vb. olursa dublikasyon olmasın
  // ✅ varsayilan porsiyon bulunamazsa 100g fallback
  $sql = "
    SELECT
      b.id,
      b.ad,
      b.marka,
      bd.kalori_100,
      bd.protein_100,
      bd.yag_100,
      bd.karbonhidrat_100,
      COALESCE(bp.id, 0) AS varsayilan_porsiyon_id,
      COALESCE(bp.etiket, '100 g') AS varsayilan_porsiyon_etiket,
      COALESCE(bp.gram, 100) AS varsayilan_porsiyon_gram
    FROM besinler b
    JOIN besin_degerleri bd ON bd.besin_id = b.id
    LEFT JOIN besin_porsiyonlari bp
      ON bp.id = (
        SELECT bp2.id
        FROM besin_porsiyonlari bp2
        WHERE bp2.besin_id = b.id
          AND bp2.varsayilan = 1
        ORDER BY bp2.id ASC
        LIMIT 1
      )
    WHERE b.onayli = 1
      AND b.ad_normalize LIKE ?
    ORDER BY
      CASE WHEN b.ad_normalize LIKE CONCAT(?, '%') THEN 0 ELSE 1 END,
      b.ad_normalize ASC
    LIMIT 30
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

  $stmt->bind_param('ss', $likeAny, $qn);

  if (!$stmt->execute()) {
    throw new Exception("Execute failed: " . $stmt->error);
  }

  $res = $stmt->get_result();
  $items = [];

  while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['kalori_100'] = $row['kalori_100'] !== null ? (float)$row['kalori_100'] : 0.0;
    $row['protein_100'] = $row['protein_100'] !== null ? (float)$row['protein_100'] : 0.0;
    $row['yag_100'] = $row['yag_100'] !== null ? (float)$row['yag_100'] : 0.0;
    $row['karbonhidrat_100'] = $row['karbonhidrat_100'] !== null ? (float)$row['karbonhidrat_100'] : 0.0;
    $row['varsayilan_porsiyon_id'] = (int)$row['varsayilan_porsiyon_id'];
    $row['varsayilan_porsiyon_gram'] = (float)$row['varsayilan_porsiyon_gram'];
    $items[] = $row;
  }

  json_ok(['items' => $items]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}