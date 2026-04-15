<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

require_user();

$sql = "SELECT DISTINCT hedef_bolge
        FROM egzersizler
        WHERE hedef_bolge IS NOT NULL AND hedef_bolge <> ''
        ORDER BY hedef_bolge ASC";

$res = $conn->query($sql);
if (!$res) json_fail('Sistem hatası.', 500);

$items = [];
while ($row = $res->fetch_assoc()) {
  $hb = trim((string)($row['hedef_bolge'] ?? ''));
  if ($hb !== '') $items[] = $hb;
}

json_ok(['items' => $items]);