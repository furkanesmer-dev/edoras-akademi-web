<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Oturum yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rol kontrolü
$_yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
if (!in_array($_yetki, ['egitmen', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$egitmen_id = (int)$_SESSION['user']['id'];
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');
$uye_id = (int)($_GET['uye_id'] ?? 0);

if ($start === '' || $end === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'start/end zorunlu.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$sql = "
  SELECT so.id, so.baslik, so.notlar, so.seans_tarih_saat, so.sure_dk, so.durum, so.uye_id,
         CONCAT(TRIM(COALESCE(uk.ad,'')),' ',TRIM(COALESCE(uk.soyad,''))) AS ad_soyad
  FROM seans_ornekleri so
  JOIN uye_kullanicilar uk ON uk.id = so.uye_id
  WHERE so.egitmen_id = ?
    AND so.seans_tarih_saat >= ?
    AND so.seans_tarih_saat < ?
";
$types = "iss";
$params = [$egitmen_id, $start, $end];

if ($uye_id > 0) {
  $sql .= " AND so.uye_id = ? ";
  $types .= "i";
  $params[] = $uye_id;
}
$sql .= " ORDER BY so.seans_tarih_saat ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$events = [];
while ($r = $res->fetch_assoc()) {
  $s = $r['seans_tarih_saat'];
  $end_dt = date('Y-m-d H:i:s', strtotime($s . ' +' . ((int)$r['sure_dk']) . ' minutes'));

  $member = trim($r['ad_soyad'] ?? '');
  $title = ($r['baslik'] ?? 'Seans') . ($member ? " • {$member}" : '');

  $events[] = [
    'id' => (string)$r['id'],
    'title' => $title,
    'start' => $s,
    'end' => $end_dt,
    'extendedProps' => [
      'durum' => $r['durum'],
      'uye' => $member,
      'uye_id' => (int)$r['uye_id'],
      'sure_dk' => (int)$r['sure_dk'],
      'notlar' => $r['notlar'] ?? ''
    ]
  ];
}
$stmt->close();

echo json_encode($events, JSON_UNESCAPED_UNICODE);
