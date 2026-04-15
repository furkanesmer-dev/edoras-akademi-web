<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/../inc/db.php';

function fail($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['id'])) {
    fail('Oturum yok.', 401);
}

// Rol kontrolü
$_yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
if (!in_array($_yetki, ['egitmen', 'admin'], true)) {
    fail('Bu işlem için yetkiniz yok.', 403);
}

// CSRF doğrulaması
csrf_verify(true);

$egitmen_id = (int)$_SESSION['user']['id'];
$seans_id   = (int)($_POST['seans_id'] ?? 0);
$durum      = trim($_POST['durum'] ?? '');
$notlar     = array_key_exists('notlar', $_POST) ? trim((string)$_POST['notlar']) : null;

$allowed = ['planned','done','canceled','no_show'];
if ($seans_id <= 0) fail('Seans seçilmedi.');
if (!in_array($durum, $allowed, true)) fail('Durum geçersiz.');

$consumeStatuses = ['done','no_show'];      // hak düşer
$refundStatuses  = ['planned','canceled'];  // hak geri gelir (daha önce düşmüşse)

$conn->set_charset('utf8mb4');

try {
  $conn->begin_transaction();

  // Seansı kilitle + sahiplik kontrolü
  $q = $conn->prepare("
    SELECT id, egitmen_id, uye_id, durum, COALESCE(hak_dusuldu,0) AS hak_dusuldu
    FROM seans_ornekleri
    WHERE id = ?
    FOR UPDATE
  ");
  $q->bind_param("i", $seans_id);
  $q->execute();
  $seans = $q->get_result()->fetch_assoc();
  $q->close();

  if (!$seans) { $conn->rollback(); fail('Seans bulunamadı.', 404); }
  if ((int)$seans['egitmen_id'] !== $egitmen_id) { $conn->rollback(); fail('Bu seans sana ait değil.', 403); }

  $uye_id      = (int)$seans['uye_id'];
  $hak_dusuldu = (int)$seans['hak_dusuldu'];

  // Seansı güncelle
  if ($notlar !== null) {
    $u = $conn->prepare("UPDATE seans_ornekleri SET durum=?, notlar=? WHERE id=? AND egitmen_id=?");
    $u->bind_param("ssii", $durum, $notlar, $seans_id, $egitmen_id);
  } else {
    $u = $conn->prepare("UPDATE seans_ornekleri SET durum=? WHERE id=? AND egitmen_id=?");
    $u->bind_param("sii", $durum, $seans_id, $egitmen_id);
  }
  $u->execute();
  $u->close();

  // Üyeyi kilitle (paket ise hak düş/geri al)
  $uq = $conn->prepare("
    SELECT id, abonelik_tipi, abonelik_durum, paket_toplam_seans, paket_kalan_seans
    FROM uye_kullanicilar
    WHERE id = ?
    FOR UPDATE
  ");
  $uq->bind_param("i", $uye_id);
  $uq->execute();
  $uye = $uq->get_result()->fetch_assoc();
  $uq->close();

  if (!$uye) { $conn->rollback(); fail('Üye bulunamadı.', 404); }

  $abonelik_tipi  = $uye['abonelik_tipi'] ?? null;
  $abonelik_durum = $uye['abonelik_durum'] ?? null;
  $toplam         = isset($uye['paket_toplam_seans']) ? (int)$uye['paket_toplam_seans'] : null;
  $kalan          = isset($uye['paket_kalan_seans']) ? (int)$uye['paket_kalan_seans'] : null;

  $warning = null;

  if ($abonelik_tipi === 'ders_paketi') {
    $willConsume = in_array($durum, $consumeStatuses, true);
    $willRefund  = in_array($durum, $refundStatuses, true);

    if ($kalan === null) $kalan = 0;
    if ($toplam === null) $toplam = 0;

    // Hak düş
    if ($willConsume && $hak_dusuldu === 0) {
      if ($kalan > 0) {
        $kalan--;
      } else {
        $kalan = 0;
        $warning = "Üyenin paket hakkı zaten bitmiş (Yenileme).";
      }

      $abonelik_durum = ($kalan <= 0) ? 'yenileme' : 'aktif';

      $uu = $conn->prepare("UPDATE uye_kullanicilar SET paket_kalan_seans=?, abonelik_durum=? WHERE id=?");
      $uu->bind_param("isi", $kalan, $abonelik_durum, $uye_id);
      $uu->execute();
      $uu->close();

      $hs = $conn->prepare("UPDATE seans_ornekleri SET hak_dusuldu=1 WHERE id=? AND egitmen_id=?");
      $hs->bind_param("ii", $seans_id, $egitmen_id);
      $hs->execute();
      $hs->close();

      $hak_dusuldu = 1;
    }

    // Hak geri al
    if ($willRefund && $hak_dusuldu === 1) {
      $kalan = ($toplam > 0) ? min($toplam, $kalan + 1) : ($kalan + 1);
      $abonelik_durum = ($kalan <= 0) ? 'yenileme' : 'aktif';

      $uu = $conn->prepare("UPDATE uye_kullanicilar SET paket_kalan_seans=?, abonelik_durum=? WHERE id=?");
      $uu->bind_param("isi", $kalan, $abonelik_durum, $uye_id);
      $uu->execute();
      $uu->close();

      $hs = $conn->prepare("UPDATE seans_ornekleri SET hak_dusuldu=0 WHERE id=? AND egitmen_id=?");
      $hs->bind_param("ii", $seans_id, $egitmen_id);
      $hs->execute();
      $hs->close();

      $hak_dusuldu = 0;
    }

    if ($kalan <= 0) {
      $warning = $warning ?: "Üyenin paket hakkı bitti (Yenileme). Seans oluşturulabilir ancak yenileme gerekir.";
    }
  }

  $conn->commit();

  $msg = 'Güncellendi.';
  if ($warning) $msg .= ' ' . $warning;

  echo json_encode([
    'ok' => true,
    'msg' => $msg,
    'uye_id' => $uye_id,
    'durum' => $durum,
    'hak_dusuldu' => (int)$hak_dusuldu,
    'abonelik_tipi' => $abonelik_tipi,
    'abonelik_durum' => $abonelik_durum,
    'kalan_seans' => $kalan
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn) { $conn->rollback(); }
  fail('Sunucu hatası: '.$e->getMessage(), 500);
}
