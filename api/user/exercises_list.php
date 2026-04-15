<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

require_user(); // sadece login kullanıcı erişsin

$q = trim((string)($_GET['q'] ?? ''));
$hb = trim((string)($_GET['hedef_bolge'] ?? ''));
$limit = (int)($_GET['limit'] ?? 30);
if ($limit < 1) $limit = 30;
if ($limit > 50) $limit = 50;

$where = [];
$params = [];
$types = '';

if ($hb !== '') {
  $where[] = "hedef_bolge = ?";
  $types .= 's';
  $params[] = $hb;
}

if ($q !== '') {
  $where[] = "egzersiz_ismi LIKE ?";
  $types .= 's';
  $params[] = '%' . $q . '%';
}

$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT id, egzersiz_ismi, egzersiz_gif, hedef_bolge, hareket_turu
        FROM egzersizler
        $whereSql
        ORDER BY egzersiz_ismi ASC
        LIMIT $limit";

$stmt = $conn->prepare($sql);
if (!$stmt) json_fail('Sistem hatası.', 500);

if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
  $items[] = [
    'id' => (int)$row['id'],
    'egzersiz_ismi' => $row['egzersiz_ismi'],
    'egzersiz_gif' => $row['egzersiz_gif'],
    'hedef_bolge' => $row['hedef_bolge'],
    'hareket_turu' => $row['hareket_turu'],
  ];
}
$stmt->close();

json_ok(['items' => $items]);