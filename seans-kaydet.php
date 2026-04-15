<?php
require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google-calendar-rest.php';

if (!isset($_SESSION['user'])) { header("Location: /login.php"); exit; }

$user = $_SESSION['user'];
$yetki = $user['yetki'] ?? 'kullanici';
if (!in_array($yetki, ['egitmen','admin'], true)) { http_response_code(403); exit('Yetkisiz'); }

// CSRF doğrulaması
csrf_verify();

$egitmen_id = (int)($user['id'] ?? 0);

$uye_id  = (int)($_POST['uye_id'] ?? 0);
$baslik  = trim($_POST['baslik'] ?? 'Koçluk Seansı');
$tarih   = trim($_POST['tarih'] ?? '');
$saat    = trim($_POST['saat'] ?? '');
$sure_dk = (int)($_POST['sure_dk'] ?? 60);
$konum   = trim($_POST['konum'] ?? '');
$aciklama= trim($_POST['aciklama'] ?? '');

if ($uye_id <= 0 || !$tarih || !$saat || $sure_dk <= 0) {
  header("Location: /seans_olustur.php?err=" . urlencode("Eksik bilgi."));
  exit;
}

// Eğitmen, sadece kendi üyesine seans açabilsin (admin hariç)
if ($yetki === 'egitmen') {
  $stmt = $conn->prepare("SELECT id FROM uye_kullanicilar WHERE id=? AND yetki='kullanici' AND egitmen_id=? LIMIT 1");
  $stmt->bind_param("ii", $uye_id, $egitmen_id);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    header("Location: /seans_olustur.php?err=" . urlencode("Bu üyeye seans oluşturamazsın."));
    exit;
  }
}

// Üye adı (event title/desc için)
$stmt = $conn->prepare("SELECT ad, soyad, uyelik_numarasi FROM uye_kullanicilar WHERE id=? AND yetki='kullanici' LIMIT 1");
$stmt->bind_param("i", $uye_id);
$stmt->execute();
$uye = $stmt->get_result()->fetch_assoc();
$stmt->close();

$uyeAd = trim(($uye['ad'] ?? '') . ' ' . ($uye['soyad'] ?? ''));
$uyeNo = $uye['uyelik_numarasi'] ?? '';

$startDT = $tarih . ' ' . $saat . ':00';
$startTs = strtotime($startDT);
if (!$startTs) {
  header("Location: /seans_olustur.php?err=" . urlencode("Tarih/saat geçersiz."));
  exit;
}
$endTs = $startTs + ($sure_dk * 60);

$baslangic = date('Y-m-d H:i:s', $startTs);
$bitis     = date('Y-m-d H:i:s', $endTs);

// DB kaydı
$stmt = $conn->prepare("
  INSERT INTO seanslar (egitmen_id, uye_id, baslangic, bitis, baslik, aciklama, konum)
  VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iisssss", $egitmen_id, $uye_id, $baslangic, $bitis, $baslik, $aciklama, $konum);
$ok = $stmt->execute();
$seansId = $conn->insert_id;
$stmt->close();

if (!$ok) {
  header("Location: /seans_olustur.php?err=" . urlencode("Seans DB kaydı başarısız."));
  exit;
}

// Google event oluştur (ISO format +03:00)
$startIso = date('c', $startTs); // c: ISO 8601
$endIso   = date('c', $endTs);

$event = [
  'summary' => $baslik . ' - ' . $uyeAd,
  'description' => "Üye: {$uyeAd} ({$uyeNo})\n\n" . $aciklama,
  'location' => $konum,
  'start' => $startIso,
  'end' => $endIso,
];

$warn = '';
$g = gcal_create_event($conn, $egitmen_id, $event);

if ($g['ok'] && !empty($g['eventId'])) {
  $stmt = $conn->prepare("UPDATE seanslar SET google_event_id=? WHERE id=?");
  $stmt->bind_param("si", $g['eventId'], $seansId);
  $stmt->execute();
  $stmt->close();
} else {
  // Seans DB’de var ama takvime düşmedi
  $warn = $g['message'] ?? 'Google Takvim’e eklenemedi.';
}

$qs = "ok=1";
if ($warn) $qs .= "&warn=" . urlencode($warn);

header("Location: /seans_olustur.php?$qs");
exit;
