<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Oturum yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$user  = $_SESSION['user'];
$yetki = $user['yetki'] ?? 'kullanici';
if ($yetki !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Yetkisiz.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF doğrulaması
csrf_verify(true);

require_once 'inc/db.php';
if (!isset($conn)) { require_once 'db.php'; }

function fail($msg){ echo json_encode(['ok'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function is_date_ymd($s){ return $s === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

// ---- Inputs
$uye_id = (int)($_POST['uye_id'] ?? 0);
if ($uye_id <= 0) fail('Üye seçilmedi.');

$egitmen_id = (int)($_POST['egitmen_id'] ?? 0);

// ✅ yeni: abonelik tipi
$abonelik_tipi = trim((string)($_POST['abonelik_tipi'] ?? ''));
if ($abonelik_tipi === '') $abonelik_tipi = 'aylik';
$allowedTypes = ['aylik','ders_paketi'];
if (!in_array($abonelik_tipi, $allowedTypes, true)) fail('Geçersiz abonelik tipi.');

// aylık için
$abonelik_suresi_ay = (int)($_POST['abonelik_suresi_ay'] ?? 0); // 0 => NULL
$allowedMonths = [0,1,3,6,12];
if (!in_array($abonelik_suresi_ay, $allowedMonths, true)) fail('Geçersiz abonelik süresi.');

// paket için
$paket_toplam_seans = (int)($_POST['paket_toplam_seans'] ?? 0);

// ortak
$baslangic_tarihi = trim((string)($_POST['baslangic_tarihi'] ?? '')); // '' => NULL
$odeme_alindi     = (int)($_POST['odeme_alindi'] ?? 0);

// dondurma
$donduruldu_in        = (int)($_POST['donduruldu'] ?? 0); // 0/1
$dondurma_baslangic_in = trim((string)($_POST['dondurma_baslangic'] ?? ''));
$dondurma_bitis_in     = trim((string)($_POST['dondurma_bitis'] ?? ''));
$dondurma_notu_in      = trim((string)($_POST['dondurma_notu'] ?? ''));

if (!is_date_ymd($baslangic_tarihi)) fail('Başlangıç tarihi geçersiz.');
if (!is_date_ymd($dondurma_baslangic_in)) fail('Dondurma başlangıç tarihi geçersiz.');
if (!is_date_ymd($dondurma_bitis_in)) fail('Dondurma bitiş tarihi geçersiz.');

// ✅ Kurallar:
// - ödeme yoksa aktif değil
$uye_aktif = ($odeme_alindi === 1) ? 1 : 0;

// ---- Üyeyi oku
$st = $conn->prepare("SELECT id, yetki, bitis_tarihi, donduruldu, dondurma_baslangic, dondurma_bitis FROM uye_kullanicilar WHERE id=? LIMIT 1");
if (!$st) { error_log('uye_abonelik_kaydet prepare: '.$conn->error); fail('Sistem hatası.', 500); }
$st->bind_param("i", $uye_id);
$st->execute();
$cur = $st->get_result()->fetch_assoc();
$st->close();

if (!$cur || ($cur['yetki'] ?? '') !== 'kullanici') fail('Üye bulunamadı.');

$cur_donduruldu = (int)($cur['donduruldu'] ?? 0);
$cur_bitis      = trim((string)($cur['bitis_tarihi'] ?? ''));
$cur_d_bas      = trim((string)($cur['dondurma_baslangic'] ?? ''));
$cur_d_bit      = trim((string)($cur['dondurma_bitis'] ?? ''));

// ✅ Tip bazlı validasyon
if ($baslangic_tarihi === '') {
  // aylıkta da pakette de başlangıç tarihi şart (senin yeni akışın)
  fail('Başlangıç tarihi zorunludur.');
}
if ($abonelik_tipi === 'ders_paketi') {
  if ($paket_toplam_seans <= 0) fail('Ders paketi için aylık seans hakkı zorunludur.');
  // ders paketinde aylık süre alanını kullanmayacağız
  $abonelik_suresi_ay = 0;
}

// ---- Abonelik bitiş tarihini (server-side) hesapla
$bitis_tarihi = '';
$dt = DateTime::createFromFormat('Y-m-d', $baslangic_tarihi);
if (!$dt) fail('Başlangıç tarihi parse edilemedi.');

if ($abonelik_tipi === 'aylik') {
  if ($abonelik_suresi_ay <= 0) fail('Aylık abonelikte süre (ay) seçilmelidir.');
  $dt->modify('+' . $abonelik_suresi_ay . ' months');
  $bitis_tarihi = $dt->format('Y-m-d');
} else {
  // ✅ ders paketi: başlangıç + 30 gün
  $dt->modify('+30 days');
  $bitis_tarihi = $dt->format('Y-m-d');
}

// ---- Dondurma Model 1: Çözülürken bitişi ileri at
$new_bitis_override = ''; // boşsa dokunmayacağız

// DONDURMA AÇILIYOR
if ($donduruldu_in === 1) {
  if ($dondurma_baslangic_in === '') {
    $dondurma_baslangic_in = ($cur_d_bas !== '' && $cur_d_bas !== '0000-00-00') ? substr($cur_d_bas,0,10) : date('Y-m-d');
  }
}

// DONDURMA KAPANIYOR (ve önceki durum donduruluyduysa)
if ($donduruldu_in === 0 && $cur_donduruldu === 1) {
  $start = $dondurma_baslangic_in !== '' ? $dondurma_baslangic_in : (($cur_d_bas !== '' && $cur_d_bas !== '0000-00-00') ? substr($cur_d_bas,0,10) : date('Y-m-d'));
  $end   = $dondurma_bitis_in     !== '' ? $dondurma_bitis_in     : (($cur_d_bit !== '' && $cur_d_bit !== '0000-00-00') ? substr($cur_d_bit,0,10) : date('Y-m-d'));

  $dStart = DateTime::createFromFormat('Y-m-d', $start);
  $dEnd   = DateTime::createFromFormat('Y-m-d', $end);
  if (!$dStart || !$dEnd) fail('Dondurma tarihleri parse edilemedi.');

  $days = ($dEnd < $dStart) ? 0 : ((int)$dStart->diff($dEnd)->days + 1);

  if ($dondurma_bitis_in === '') $dondurma_bitis_in = $end;
  if ($dondurma_baslangic_in === '') $dondurma_baslangic_in = $start;

  $curB = trim($cur_bitis);
  if ($days > 0 && $curB !== '' && $curB !== '0000-00-00') {
    $b = DateTime::createFromFormat('Y-m-d', substr($curB,0,10));
    if ($b) {
      $b->modify('+' . $days . ' days');
      $new_bitis_override = $b->format('Y-m-d');
    }
  }
}

$computed_bitis = $bitis_tarihi;
if ($new_bitis_override !== '') $computed_bitis = $new_bitis_override;

// ✅ abonelik_durum setle
$abonelik_durum = ($uye_aktif === 1) ? 'aktif' : 'pasif';

// ders paketinde: kalan=toplam (yenilemede sıfırlanıp yeni tanımlanır kuralınla uyumlu)
$paket_kalan_seans = null;
if ($abonelik_tipi === 'ders_paketi') {
  $paket_kalan_seans = $paket_toplam_seans;
}

// ---- Update
$sql = "
  UPDATE uye_kullanicilar
  SET
    egitmen_id = NULLIF(?, 0),

    abonelik_tipi = ?,
    abonelik_durum = ?,

    -- aylık için
    abonelik_suresi_ay = NULLIF(?, 0),

    -- ortak dönem
    baslangic_tarihi = NULLIF(?, ''),
    bitis_tarihi = NULLIF(?, ''),

    -- paket için
    paket_toplam_seans = ?,
    paket_kalan_seans = ?,

    odeme_alindi = ?,
    uye_aktif = ?,

    donduruldu = ?,
    dondurma_baslangic = NULLIF(?, ''),
    dondurma_bitis = NULLIF(?, ''),
    dondurma_notu = NULLIF(?, '')

  WHERE id = ? AND yetki='kullanici'
";

$stmt = $conn->prepare($sql);
if (!$stmt) { error_log('uye_abonelik_kaydet prepare: '.$conn->error); fail('Sistem hatası.', 500); }

// types:
// egitmen_id(i),
// abonelik_tipi(s), abonelik_durum(s),
// abonelik_suresi_ay(i),
// baslangic(s), bitis(s),
// paket_toplam(i), paket_kalan(i),
// odeme(i), uye_aktif(i),
// donduruldu(i),
// dondurma_bas(s), dondurma_bitis(s), dondurma_notu(s),
// uye_id(i)
$stmt->bind_param(
  "ississiiiiisssi",
  $egitmen_id,
  $abonelik_tipi,
  $abonelik_durum,
  $abonelik_suresi_ay,
  $baslangic_tarihi,
  $computed_bitis,
  $paket_toplam_seans,
  $paket_kalan_seans,
  $odeme_alindi,
  $uye_aktif,
  $donduruldu_in,
  $dondurma_baslangic_in,
  $dondurma_bitis_in,
  $dondurma_notu_in,
  $uye_id
);

if (!$stmt->execute()) {
  error_log('uye_abonelik_kaydet execute: ' . $stmt->error);
  fail('Kayıt sırasında hata oluştu.', 500);
}

echo json_encode(['ok'=>true,'message'=>'Kaydedildi.'], JSON_UNESCAPED_UNICODE);
exit;

