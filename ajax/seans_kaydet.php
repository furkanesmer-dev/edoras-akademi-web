<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/../inc/db.php';

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['id'])) {
    fail('Oturum yok.', 401);
}

// Rol kontrolü: sadece egitmen veya admin bu endpoint'i kullanabilir
$_yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
if (!in_array($_yetki, ['egitmen', 'admin'], true)) {
    fail('Bu işlem için yetkiniz yok.', 403);
}

// CSRF doğrulaması
csrf_verify(true);

$egitmen_id = (int)$_SESSION['user']['id'];

$uye_id = (int)($_POST['uye_id'] ?? 0);
$baslik = trim((string)($_POST['baslik'] ?? 'Seans'));
$notlar = trim((string)($_POST['notlar'] ?? ''));
$lokasyon = trim((string)($_POST['lokasyon'] ?? ''));

$baslangic_tarihi = trim((string)($_POST['baslangic_tarihi'] ?? ''));
$saat = trim((string)($_POST['saat'] ?? ''));
$sure_dk = (int)($_POST['sure_dk'] ?? 60);

$tekrar = trim((string)($_POST['tekrar'] ?? 'none')); // none | weekly
$gunler = $_POST['gunler'] ?? [];
$bitis_tarihi = trim((string)($_POST['bitis_tarihi'] ?? ''));

if ($uye_id <= 0) fail('Üye seçmelisin.');
if ($baslangic_tarihi === '' || $saat === '') fail('Tarih ve saat zorunlu.');
if ($sure_dk <= 0 || $sure_dk > 600) fail('Süre geçersiz.');
if (!in_array($tekrar, ['none','weekly'], true)) fail('Tekrar tipi geçersiz.');

$dt_start = date_create($baslangic_tarihi . ' ' . $saat);
if (!$dt_start) fail('Tarih/saat geçersiz.');

$gunler_int = [];
if ($tekrar === 'weekly') {
  if (!is_array($gunler) || count($gunler) === 0) fail('Haftalık tekrar için gün seçmelisin.');
  foreach ($gunler as $g) {
    $gi = (int)$g;
    if ($gi >= 1 && $gi <= 7) $gunler_int[] = $gi;
  }
  $gunler_int = array_values(array_unique($gunler_int));
  if (count($gunler_int) === 0) fail('Gün seçimi geçersiz.');

  if ($bitis_tarihi === '') fail('Haftalık tekrar için bitiş tarihi seçmelisin.');
  $dt_end_date = date_create($bitis_tarihi);
  if (!$dt_end_date) fail('Bitiş tarihi geçersiz.');
  if ($dt_end_date < date_create($baslangic_tarihi)) fail('Bitiş tarihi başlangıçtan küçük olamaz.');
}

// Üye bu eğitmene bağlı mı + abonelik özetini al (uyarı için)
$chk = $conn->prepare("
  SELECT
    id,
    abonelik_tipi,
    abonelik_durum,
    paket_toplam_seans,
    paket_kalan_seans,
    bitis_tarihi,
    odeme_alindi,
    donduruldu
  FROM uye_kullanicilar
  WHERE id=? AND egitmen_id=?
  LIMIT 1
");
$chk->bind_param("ii", $uye_id, $egitmen_id);
$chk->execute();
$uye = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$uye) fail('Bu üye sana bağlı değil.', 403);

// ✅ Paket hakkı uyarısı (ENGELLEMEZ, SADECE UYARIR)
$warning = null;
$abonelik_tipi  = $uye['abonelik_tipi'] ?? null;
$abonelik_durum = $uye['abonelik_durum'] ?? null;
$kalan_seans    = isset($uye['paket_kalan_seans']) ? (int)$uye['paket_kalan_seans'] : null;
$toplam_seans   = isset($uye['paket_toplam_seans']) ? (int)$uye['paket_toplam_seans'] : null;
$bitis_db       = isset($uye['bitis_tarihi']) ? (string)$uye['bitis_tarihi'] : '';

$today = date('Y-m-d');
$expired = ($bitis_db && $bitis_db !== '0000-00-00' && $bitis_db < $today);

if ((int)($uye['donduruldu'] ?? 0) === 1) {
  $warning = "Üyelik şu an dondurulmuş görünüyor. Seans kaydedildi ama kontrol etmen önerilir.";
} elseif ((int)($uye['odeme_alindi'] ?? 0) !== 1) {
  $warning = "Üyenin ödemesi görünmüyor (pasif). Seans kaydedildi ama üyelik durumunu kontrol et.";
} elseif ($abonelik_tipi === 'ders_paketi') {
  if ($expired) {
    $warning = "Üyenin paket dönemi bitmiş görünüyor. Seans kaydedildi ama yenileme gerekir.";
  } elseif ($abonelik_durum === 'yenileme') {
    $warning = "Üye yenileme statüsünde. Seans kaydedildi ama yenileme gerekir.";
  } elseif ($kalan_seans !== null && $kalan_seans <= 0) {
    $warning = "Üyenin paket hakkı bitmiş (kalan 0). Seans kaydedildi ama yenileme gerekir.";
  }
}

// ✅ ÇAKIŞMA KONTROLÜ KALDIRILDI (EĞİTMEN AYNI SAATTE ÇOKLU SEANS AÇABİLİR)

$conn->begin_transaction();

try {
  $gunler_json = ($tekrar === 'weekly') ? json_encode($gunler_int, JSON_UNESCAPED_UNICODE) : null;
  $bt = ($tekrar === 'weekly') ? $bitis_tarihi : null;

  // Şablon
  $stmt = $conn->prepare("INSERT INTO seans_sablonlari
    (egitmen_id, uye_id, baslik, notlar, lokasyon, baslangic_tarihi, bitis_tarihi, saat_baslangic, sure_dk, tekrar_tipi, gunler_json, aktif)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,1)");

  // 11 parametre -> 11 type
  $stmt->bind_param(
    "iissssssiss",
    $egitmen_id, $uye_id, $baslik, $notlar, $lokasyon,
    $baslangic_tarihi, $bt, $saat, $sure_dk, $tekrar, $gunler_json
  );

  $stmt->execute();
  $sablon_id = (int)$stmt->insert_id;
  $stmt->close();

  // Seans örnekleri
  $insert = $conn->prepare("INSERT INTO seans_ornekleri
    (sablon_id, egitmen_id, uye_id, baslik, notlar, seans_tarih_saat, sure_dk, durum)
    VALUES (?,?,?,?,?,?,?,'planned')");

  $created = 0;

  if ($tekrar === 'none') {
    $dt_str = $dt_start->format('Y-m-d H:i:s');

    $insert->bind_param("iiisssi", $sablon_id, $egitmen_id, $uye_id, $baslik, $notlar, $dt_str, $sure_dk);
    $insert->execute();
    $created = 1;

  } else {
    $cur = date_create($baslangic_tarihi . ' ' . $saat);
    $end = date_create($bitis_tarihi . ' 23:59:59');

    while ($cur <= $end) {
      $dow = (int)$cur->format('N'); // 1..7
      if (in_array($dow, $gunler_int, true)) {
        $dt_str = $cur->format('Y-m-d H:i:s');

        $insert->bind_param("iiisssi", $sablon_id, $egitmen_id, $uye_id, $baslik, $notlar, $dt_str, $sure_dk);
        $insert->execute();
        $created++;
      }
      $cur->modify('+1 day');
    }
  }

  $insert->close();
  $conn->commit();

  $msg = 'Seans kaydedildi.';
  if ($warning) $msg .= ' ' . $warning;

  echo json_encode([
    'ok' => true,
    'msg' => $msg,
    'created' => $created,
    'warning' => $warning,
    'abonelik_tipi' => $abonelik_tipi,
    'abonelik_durum' => $abonelik_durum,
    'kalan_seans' => $kalan_seans,
    'toplam_seans' => $toplam_seans
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();

  $msg = $e->getMessage();

  // Duplicate: uniq_uye_dt_baslik
  if (
    stripos($msg, 'Duplicate entry') !== false &&
    (stripos($msg, 'uniq_uye_dt_baslik') !== false || stripos($msg, 'for key') !== false)
  ) {
    fail('Bu üye için aynı tarih/saat ve aynı başlıkla zaten bir seans var. (Çift tıklama olduysa bir kez kaydedilmiş olabilir.)', 409);
  }

  fail('Kayıt hatası: ' . $msg, 400);
}
