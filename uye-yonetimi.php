<?php
// uye-yonetimi.php
// ADMIN - tek sayfada birleşik üye yönetimi

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------------------
// DB include (robust)
// --------------------------------------------------
$__db_loaded = false;
foreach ([__DIR__.'/inc/db.php', __DIR__.'/db.php', __DIR__.'/baglanti.php', __DIR__.'/config/db.php'] as $__p) {
    if (file_exists($__p)) {
        require_once $__p;
        $__db_loaded = true;
        break;
    }
}
if (!$__db_loaded) {
    http_response_code(500);
    exit('DB bağlantı dosyası bulunamadı.');
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && ($mysqli instanceof mysqli)) {
        $conn = $mysqli;
    }
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('DB bağlantısı ($conn) bulunamadı.');
}

// --------------------------------------------------
// Session / yetki
// --------------------------------------------------
$user  = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';

if ($yetki !== 'admin') {
    include "inc/header.php";
    echo "<div class='p-4 app-card'><h4>⛔ Yetkisiz</h4><div class='app-muted'>Bu sayfa sadece admin içindir.</div></div>";
    include "inc/footer.php";
    exit;
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out($arr, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_json($msg, int $status = 400) {
    json_out(['ok' => false, 'message' => $msg], $status);
}

function ok_json($msg, array $extra = []) {
    json_out(array_merge(['ok' => true, 'message' => $msg], $extra));
}

function is_date_ymd($s) {
    return $s === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function normalize_nullable_str($v) {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function compute_status_key(array $u): string {
    $donduruldu = (int)($u['donduruldu'] ?? 0);
    if ($donduruldu === 1) return 'frozen';

    $odeme = (int)($u['odeme_alindi'] ?? 0);
    if ($odeme !== 1) return 'passive';

    $tip = (string)($u['abonelik_tipi'] ?? 'aylik');
    $today = date('Y-m-d');
    $bitis = substr((string)($u['bitis_tarihi'] ?? ''), 0, 10);

    if ($tip === 'ders_paketi') {
        $kalan = (int)($u['paket_kalan_seans'] ?? 0);
        if ($kalan <= 0) return 'renew';
        if ($bitis !== '' && $bitis !== '0000-00-00' && $today > $bitis) return 'renew';
        if (($u['abonelik_durum'] ?? '') === 'yenileme') return 'renew';
        return 'active';
    }

    if ($bitis !== '' && $bitis !== '0000-00-00' && $today > $bitis) return 'renew';
    return ((int)($u['uye_aktif'] ?? 0) === 1) ? 'active' : 'passive';
}

function compute_status_text(array $u): string {
    $k = compute_status_key($u);
    return match ($k) {
        'active' => 'Aktif',
        'passive' => 'Pasif',
        'renew' => 'Yenileme',
        'frozen' => 'Donduruldu',
        default => 'Bilinmiyor'
    };
}

function calc_membership_end_date(string $abonelik_tipi, int $abonelik_suresi_ay, string $baslangic_tarihi): string {
    $dt = DateTime::createFromFormat('Y-m-d', $baslangic_tarihi);
    if (!$dt) {
        throw new RuntimeException('Başlangıç tarihi parse edilemedi.');
    }

    if ($abonelik_tipi === 'aylik') {
        if ($abonelik_suresi_ay <= 0) {
            throw new RuntimeException('Aylık abonelikte süre (ay) seçilmelidir.');
        }
        $dt->modify('+' . $abonelik_suresi_ay . ' months');
        return $dt->format('Y-m-d');
    }

    $dt->modify('+30 days');
    return $dt->format('Y-m-d');
}

function add_freeze_days_to_end_if_needed(mysqli $conn, int $uye_id, int $new_donduruldu, string &$dondurma_baslangic, string &$dondurma_bitis): ?string {
    $st = $conn->prepare("
        SELECT id, yetki, bitis_tarihi, donduruldu, dondurma_baslangic, dondurma_bitis
        FROM uye_kullanicilar
        WHERE id = ?
        LIMIT 1
    ");
    if (!$st) {
        throw new RuntimeException('Prepare hata: ' . $conn->error);
    }
    $st->bind_param("i", $uye_id);
    $st->execute();
    $cur = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$cur || ($cur['yetki'] ?? '') !== 'kullanici') {
        throw new RuntimeException('Üye bulunamadı.');
    }

    $cur_donduruldu = (int)($cur['donduruldu'] ?? 0);
    $cur_bitis      = trim((string)($cur['bitis_tarihi'] ?? ''));
    $cur_d_bas      = trim((string)($cur['dondurma_baslangic'] ?? ''));
    $cur_d_bit      = trim((string)($cur['dondurma_bitis'] ?? ''));

    // Dondurma açılıyorsa başlangıç boşsa doldur
    if ($new_donduruldu === 1) {
        if ($dondurma_baslangic === '') {
            $dondurma_baslangic = ($cur_d_bas !== '' && $cur_d_bas !== '0000-00-00')
                ? substr($cur_d_bas, 0, 10)
                : date('Y-m-d');
        }
        return null;
    }

    // Dondurma kapanıyorsa ve önceki durum donduruluyduysa bitişi ileri at
    if ($new_donduruldu === 0 && $cur_donduruldu === 1) {
        $start = $dondurma_baslangic !== '' ? $dondurma_baslangic
            : (($cur_d_bas !== '' && $cur_d_bas !== '0000-00-00') ? substr($cur_d_bas, 0, 10) : date('Y-m-d'));

        $end = $dondurma_bitis !== '' ? $dondurma_bitis
            : (($cur_d_bit !== '' && $cur_d_bit !== '0000-00-00') ? substr($cur_d_bit, 0, 10) : date('Y-m-d'));

        $dStart = DateTime::createFromFormat('Y-m-d', $start);
        $dEnd   = DateTime::createFromFormat('Y-m-d', $end);

        if (!$dStart || !$dEnd) {
            throw new RuntimeException('Dondurma tarihleri parse edilemedi.');
        }

        $days = ($dEnd < $dStart) ? 0 : ((int)$dStart->diff($dEnd)->days + 1);

        if ($dondurma_baslangic === '') $dondurma_baslangic = $start;
        if ($dondurma_bitis === '') $dondurma_bitis = $end;

        if ($days > 0 && $cur_bitis !== '' && $cur_bitis !== '0000-00-00') {
            $b = DateTime::createFromFormat('Y-m-d', substr($cur_bitis, 0, 10));
            if ($b) {
                $b->modify('+' . $days . ' days');
                return $b->format('Y-m-d');
            }
        }
    }

    return null;
}

// --------------------------------------------------
// AJAX / POST actions
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF doğrulaması (tüm POST istekleri için)
    csrf_verify(true); // JSON modda çık

    $action = trim((string)($_POST['action'] ?? ''));

    // -----------------------------
    // 1) Full save (tek birleşik kayıt)
    // -----------------------------
    if ($action === 'save_member_full') {
        try {
            $uye_id = (int)($_POST['uye_id'] ?? 0);
            if ($uye_id <= 0) {
                fail_json('Üye seçilmedi.');
            }

            // temel bilgiler
            $ad     = trim((string)($_POST['ad'] ?? ''));
            $soyad  = trim((string)($_POST['soyad'] ?? ''));
            $eposta = trim((string)($_POST['eposta_adresi'] ?? ''));
            $tel    = trim((string)($_POST['tel_no'] ?? ''));
            $uyelik = trim((string)($_POST['uyelik_numarasi'] ?? ''));

            if ($ad === '' || $soyad === '' || $eposta === '') {
                fail_json('Ad, Soyad ve E-posta zorunludur.');
            }
            if (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
                fail_json('E-posta geçersiz.');
            }

            // eğitmen
            $egitmen_id = (int)($_POST['egitmen_id'] ?? 0);

            // abonelik
            $abonelik_tipi = trim((string)($_POST['abonelik_tipi'] ?? 'aylik'));
            if ($abonelik_tipi === '') $abonelik_tipi = 'aylik';
            $allowedTypes = ['aylik', 'ders_paketi'];
            if (!in_array($abonelik_tipi, $allowedTypes, true)) {
                fail_json('Geçersiz abonelik tipi.');
            }

            $abonelik_suresi_ay = (int)($_POST['abonelik_suresi_ay'] ?? 0);
            $allowedMonths = [0, 1, 3, 6, 12];
            if (!in_array($abonelik_suresi_ay, $allowedMonths, true)) {
                fail_json('Geçersiz abonelik süresi.');
            }

            $paket_toplam_seans = (int)($_POST['paket_toplam_seans'] ?? 0);

            $baslangic_tarihi = trim((string)($_POST['baslangic_tarihi'] ?? ''));
            $odeme_alindi     = (int)($_POST['odeme_alindi'] ?? 0);

            // dondurma
            $donduruldu = (int)($_POST['donduruldu'] ?? 0);
            $dondurma_baslangic = trim((string)($_POST['dondurma_baslangic'] ?? ''));
            $dondurma_bitis     = trim((string)($_POST['dondurma_bitis'] ?? ''));
            $dondurma_notu      = trim((string)($_POST['dondurma_notu'] ?? ''));

            if (!is_date_ymd($baslangic_tarihi)) fail_json('Başlangıç tarihi geçersiz.');
            if (!is_date_ymd($dondurma_baslangic)) fail_json('Dondurma başlangıç tarihi geçersiz.');
            if (!is_date_ymd($dondurma_bitis)) fail_json('Dondurma bitiş tarihi geçersiz.');

            if ($baslangic_tarihi === '') {
                fail_json('Başlangıç tarihi zorunludur.');
            }

            if ($abonelik_tipi === 'ders_paketi') {
                if ($paket_toplam_seans <= 0) {
                    fail_json('Ders paketi için aylık seans hakkı zorunludur.');
                }
                $abonelik_suresi_ay = 0;
            } else {
                if ($abonelik_suresi_ay <= 0) {
                    fail_json('Aylık abonelikte süre (ay) seçilmelidir.');
                }
                $paket_toplam_seans = 0;
            }

            $uye_aktif = ($odeme_alindi === 1) ? 1 : 0;
            $abonelik_durum = ($uye_aktif === 1) ? 'aktif' : 'pasif';

            $computed_bitis = calc_membership_end_date($abonelik_tipi, $abonelik_suresi_ay, $baslangic_tarihi);

            $new_bitis_override = add_freeze_days_to_end_if_needed(
                $conn,
                $uye_id,
                $donduruldu,
                $dondurma_baslangic,
                $dondurma_bitis
            );
            if ($new_bitis_override !== null && $new_bitis_override !== '') {
                $computed_bitis = $new_bitis_override;
            }

            $paket_kalan_seans = null;
            if ($abonelik_tipi === 'ders_paketi') {
                $paket_kalan_seans = $paket_toplam_seans;
            } else {
                $paket_kalan_seans = 0;
            }

            $sql = "
                UPDATE uye_kullanicilar
                SET
                    ad = ?,
                    soyad = ?,
                    eposta_adresi = ?,
                    tel_no = NULLIF(?, ''),
                    uyelik_numarasi = NULLIF(?, ''),

                    egitmen_id = NULLIF(?, 0),

                    abonelik_tipi = ?,
                    abonelik_durum = ?,
                    abonelik_suresi_ay = NULLIF(?, 0),

                    baslangic_tarihi = NULLIF(?, ''),
                    bitis_tarihi = NULLIF(?, ''),

                    paket_toplam_seans = ?,
                    paket_kalan_seans = ?,

                    odeme_alindi = ?,
                    uye_aktif = ?,

                    donduruldu = ?,
                    dondurma_baslangic = NULLIF(?, ''),
                    dondurma_bitis = NULLIF(?, ''),
                    dondurma_notu = NULLIF(?, '')

                WHERE id = ? AND yetki = 'kullanici'
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                fail_json('Sorgu hazırlanamadı: ' . $conn->error, 500);
            }

            $stmt->bind_param(
                "sssssississiiiiisssi",
                $ad,
                $soyad,
                $eposta,
                $tel,
                $uyelik,
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
                $donduruldu,
                $dondurma_baslangic,
                $dondurma_bitis,
                $dondurma_notu,
                $uye_id
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();

                if (stripos($err, 'eposta_adresi') !== false) {
                    fail_json('Bu e-posta zaten kayıtlı.');
                }
                if (stripos($err, 'uyelik_numarasi') !== false) {
                    fail_json('Bu üyelik numarası zaten kayıtlı.');
                }

                fail_json('DB hata: ' . $err, 500);
            }

            $stmt->close();
            ok_json('Üye bilgileri kaydedildi.');
        } catch (Throwable $e) {
            fail_json($e->getMessage(), 500);
        }
    }

    // -----------------------------
    // 2) Hızlı yenile
    // -----------------------------
    if ($action === 'renew_member') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $ay = (int)($_POST['ay'] ?? 1);
            if (!in_array($ay, [1, 3, 6, 12], true)) $ay = 1;

            if ($id <= 0) {
                fail_json('Geçersiz üye.');
            }

            $st = $conn->prepare("
                SELECT abonelik_tipi, paket_toplam_seans
                FROM uye_kullanicilar
                WHERE id = ? AND yetki = 'kullanici'
                LIMIT 1
            ");
            if (!$st) {
                fail_json('Sorgu hazırlanamadı: ' . $conn->error, 500);
            }
            $st->bind_param("i", $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$row) {
                fail_json('Üye bulunamadı.');
            }

            $tip = (string)($row['abonelik_tipi'] ?? 'aylik');
            if ($tip === '') $tip = 'aylik';

            if ($tip === 'ders_paketi') {
                $paketToplam = (int)($row['paket_toplam_seans'] ?? 0);
                if ($paketToplam <= 0) $paketToplam = 8;

                $stmt = $conn->prepare("
                    UPDATE uye_kullanicilar
                    SET
                        baslangic_tarihi = CURDATE(),
                        bitis_tarihi = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
                        paket_kalan_seans = ?,
                        odeme_alindi = 1,
                        uye_aktif = 1,
                        abonelik_durum = 'aktif',
                        donduruldu = 0,
                        dondurma_baslangic = NULL,
                        dondurma_bitis = NULL,
                        dondurma_notu = NULL
                    WHERE id = ? AND yetki = 'kullanici'
                ");
                if (!$stmt) {
                    fail_json('Sorgu hazırlanamadı: ' . $conn->error, 500);
                }
                $stmt->bind_param("ii", $paketToplam, $id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE uye_kullanicilar
                    SET
                        baslangic_tarihi = CURDATE(),
                        abonelik_tipi = 'aylik',
                        abonelik_suresi_ay = ?,
                        bitis_tarihi = DATE_ADD(CURDATE(), INTERVAL ? MONTH),
                        odeme_alindi = 1,
                        uye_aktif = 1,
                        abonelik_durum = 'aktif',
                        donduruldu = 0,
                        dondurma_baslangic = NULL,
                        dondurma_bitis = NULL,
                        dondurma_notu = NULL
                    WHERE id = ? AND yetki = 'kullanici'
                ");
                if (!$stmt) {
                    fail_json('Sorgu hazırlanamadı: ' . $conn->error, 500);
                }
                $stmt->bind_param("iii", $ay, $ay, $id);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                fail_json('Yenileme hatası: ' . $err, 500);
            }

            $stmt->close();
            ok_json('Üyelik yenilendi.');
        } catch (Throwable $e) {
            fail_json($e->getMessage(), 500);
        }
    }
}

// --------------------------------------------------
// Data fetch
// --------------------------------------------------

// eğitmenler
$egitmenler = [];
$q = $conn->query("
    SELECT id, ad, soyad
    FROM uye_kullanicilar
    WHERE yetki = 'egitmen'
    ORDER BY ad ASC, soyad ASC
");
while ($q && ($r = $q->fetch_assoc())) {
    $egitmenler[] = $r;
}

// tüm üyeler (sifre sütunu dahil edilmez)
$uyeler = [];
$sqlMembers = "
    SELECT
        u.id, u.ad, u.soyad, u.eposta_adresi, u.tel_no, u.uyelik_numarasi,
        u.kayit_tarihi, u.bitis_tarihi, u.baslangic_tarihi,
        u.uye_aktif, u.odeme_alindi, u.abonelik_tipi, u.abonelik_durum,
        u.abonelik_suresi_ay, u.paket_toplam_seans, u.paket_kalan_seans,
        u.donduruldu, u.dondurma_baslangic, u.dondurma_bitis, u.dondurma_notu,
        u.egitmen_id, u.kilo_kg, u.boy_cm, u.yag_orani,
        u.boyun_cevresi, u.bel_cevresi, u.basen_cevresi,
        CONCAT(COALESCE(e.ad,''), ' ', COALESCE(e.soyad,'')) AS egitmen_adi
    FROM uye_kullanicilar u
    LEFT JOIN uye_kullanicilar e ON e.id = u.egitmen_id
    WHERE u.yetki = 'kullanici'
    ORDER BY u.kayit_tarihi DESC, u.ad ASC, u.soyad ASC
";
$rq = $conn->query($sqlMembers);
while ($rq && ($row = $rq->fetch_assoc())) {
    $row['_status_key'] = compute_status_key($row);
    $row['_status_text'] = compute_status_text($row);
    $uyeler[] = $row;
}

// son eklenen
$son_uyeler = [];
foreach ($uyeler as $u) {
    $son_uyeler[] = $u;
}
$son_uyeler = array_slice($son_uyeler, 0, 12);

// yenilemesi gelen
$yenilemesi_gelen = [];
foreach ($uyeler as $u) {
    if (($u['_status_key'] ?? '') === 'renew') {
        $yenilemesi_gelen[] = $u;
    }
}

// pasif
$pasif_uyeler = [];
foreach ($uyeler as $u) {
    if (($u['_status_key'] ?? '') === 'passive') {
        $pasif_uyeler[] = $u;
    }
}

// özet
$aktif_sayi = 0;
$pasif_sayi = 0;
$yenileme_sayi = 0;
$dondurulan_sayi = 0;

foreach ($uyeler as $u) {
    switch ($u['_status_key']) {
        case 'active':
            $aktif_sayi++;
            break;
        case 'passive':
            $pasif_sayi++;
            break;
        case 'renew':
            $yenileme_sayi++;
            break;
        case 'frozen':
            $dondurulan_sayi++;
            break;
    }
}

// --------------------------------------------------
// Render
// --------------------------------------------------
include "inc/header.php";
?>

<style>
  body.page-uye-yonetimi{
    background: var(--app-bg, #f6f7fb);
    color: var(--app-text, #111827);
  }

  body.page-uye-yonetimi{
    --u-card: #ffffff;
    --u-border: rgba(17,24,39,.10);
    --u-muted: rgba(17,24,39,.60);
    --u-soft: rgba(17,24,39,.04);
    --u-shadow: 0 10px 30px rgba(17,24,39,.08);
    --u-radius: 18px;
    --u-focus: rgba(13,110,253,.18);
    --u-focus-border: rgba(13,110,253,.45);
  }

  body.page-uye-yonetimi .uye-wrap{
    max-width: 1380px;
    margin: 0 auto;
  }

  body.page-uye-yonetimi h1{
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  body.page-uye-yonetimi .app-card{
    background: var(--u-card);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    box-shadow: var(--u-shadow);
  }

  body.page-uye-yonetimi .app-muted{
    color: var(--u-muted) !important;
  }

  body.page-uye-yonetimi .chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid var(--u-border);
    background: var(--u-soft);
    color: rgba(17,24,39,.85);
    font-size: .86rem;
    font-weight: 800;
    white-space: nowrap;
  }

  body.page-uye-yonetimi .app-input{
    background: #fff !important;
    border: 1px solid var(--u-border) !important;
    color: rgba(17,24,39,.92) !important;
    border-radius: 12px !important;
  }

  body.page-uye-yonetimi .app-input::placeholder{
    color: rgba(17,24,39,.45) !important;
  }

  body.page-uye-yonetimi .app-input:focus{
    box-shadow: 0 0 0 0.25rem var(--u-focus) !important;
    border-color: var(--u-focus-border) !important;
  }

  body.page-uye-yonetimi .app-input[readonly]{
    background: #f9fafb !important;
    cursor: not-allowed;
  }

  body.page-uye-yonetimi .app-table{
    background: #fff;
    border: 1px solid var(--u-border);
    border-radius: 16px;
    padding: 12px;
    overflow:auto;
    -webkit-overflow-scrolling: touch;
  }

  body.page-uye-yonetimi .app-table .table{
    margin: 0;
    color: rgba(17,24,39,.90);
  }

  body.page-uye-yonetimi .app-table .table td,
  body.page-uye-yonetimi .app-table .table th{
    border-color: rgba(17,24,39,.10);
    vertical-align: middle;
  }

  body.page-uye-yonetimi .app-table thead th{
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f3f4f6;
    color: rgba(17,24,39,.85);
    border-color: rgba(17,24,39,.10);
    font-weight: 800;
  }

  body.page-uye-yonetimi .row-select{ cursor:pointer; }
  body.page-uye-yonetimi .row-select:hover td{ background: rgba(17,24,39,.03); }
  body.page-uye-yonetimi .table-active td{ background: rgba(13,110,253,.08) !important; }

  body.page-uye-yonetimi .badge-soft{
    border: 1px solid var(--u-border);
    background: var(--u-soft);
    color: rgba(17,24,39,.85);
    font-weight: 800;
  }

  body.page-uye-yonetimi .badge-status{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding: 6px 10px;
    border-radius:999px;
    font-weight:900;
    font-size:.85rem;
    border:1px solid rgba(17,24,39,.12);
    background: rgba(17,24,39,.04);
    color: rgba(17,24,39,.85);
    white-space:nowrap;
  }

  body.page-uye-yonetimi .badge-status .dot{
    width:10px;
    height:10px;
    border-radius:999px;
    background:#9ca3af;
  }

  body.page-uye-yonetimi .badge-status.active{
    border-color: rgba(16,185,129,0.35);
    background: rgba(16,185,129,0.10);
    color: rgba(6,95,70,1);
  }
  body.page-uye-yonetimi .badge-status.active .dot{ background: rgba(16,185,129,1); }

  body.page-uye-yonetimi .badge-status.passive{
    border-color: rgba(239,68,68,0.35);
    background: rgba(239,68,68,0.10);
    color: rgba(127,29,29,1);
  }
  body.page-uye-yonetimi .badge-status.passive .dot{ background: rgba(239,68,68,1); }

  body.page-uye-yonetimi .badge-status.renew{
    border-color: rgba(245,158,11,0.45);
    background: rgba(245,158,11,0.14);
    color: rgba(146,64,14,1);
  }
  body.page-uye-yonetimi .badge-status.renew .dot{ background: rgba(245,158,11,1); }

  body.page-uye-yonetimi .badge-status.frozen{
    border-color: rgba(59,130,246,0.40);
    background: rgba(59,130,246,0.10);
    color: rgba(30,58,138,1);
  }
  body.page-uye-yonetimi .badge-status.frozen .dot{ background: rgba(59,130,246,1); }

  body.page-uye-yonetimi .light-hr{
    border-color: rgba(17,24,39,.10) !important;
  }

  body.page-uye-yonetimi .nav-pills .nav-link{
    border-radius: 999px;
    font-weight: 800;
    padding: .7rem 1rem;
  }

  body.page-uye-yonetimi .nav-pills .nav-link.active{
    box-shadow: 0 8px 20px rgba(13,110,253,.15);
  }

  body.page-uye-yonetimi .sticky-side{
    position: sticky;
    top: 16px;
  }

  body.page-uye-yonetimi .section-title{
    font-weight: 800;
    font-size: 1rem;
  }

  @media (max-width: 991.98px){
    body.page-uye-yonetimi .sticky-side{
      position: static;
      top: auto;
    }
  }

  @media (max-width: 576px){
    body.page-uye-yonetimi h1{
      font-size: 1.35rem;
    }
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.body.classList.add('page-uye-yonetimi');
});
</script>

<div class="uye-wrap">

  <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="mb-1">Üye Yönetimi</h1>
      <div class="app-muted small">Admin • üyeleri görüntüle, düzenle, eğitmen ata, abonelik ve dondurma yönet.</div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
      <span class="chip"><i class="fa-solid fa-users"></i> Toplam: <?= count($uyeler) ?></span>
      <span class="chip"><i class="fa-solid fa-user-check"></i> Aktif: <?= (int)$aktif_sayi ?></span>
      <span class="chip"><i class="fa-solid fa-user-slash"></i> Pasif: <?= (int)$pasif_sayi ?></span>
      <span class="chip"><i class="fa-solid fa-rotate"></i> Yenileme: <?= (int)$yenileme_sayi ?></span>
      <span class="chip"><i class="fa-solid fa-snowflake"></i> Dondurulan: <?= (int)$dondurulan_sayi ?></span>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-xl-7">

      <div class="app-card p-3 p-lg-4">
        <div class="d-flex flex-column gap-3">

          <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between">
            <div>
              <div class="section-title">Üye Listesi</div>
              <div class="app-muted small">Satıra tıkla, sağ panelde tüm üyelik detaylarını yönet.</div>
            </div>

            <input id="searchBox" class="form-control app-input" style="max-width:340px;" type="text" placeholder="Ad, soyad, e-posta, telefon ile ara">
          </div>

          <ul class="nav nav-pills gap-2" id="listTabs">
            <li class="nav-item">
              <button class="nav-link active" type="button" data-filter="all">Tüm Üyeler</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" type="button" data-filter="recent">Son Eklenen</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" type="button" data-filter="renew">Yenilemesi Gelen</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" type="button" data-filter="passive">Pasif</button>
            </li>
          </ul>

          <div class="app-table">
            <table class="table table-bordered align-middle" id="uyeTable">
              <thead>
                <tr>
                  <th style="min-width:220px;">Ad Soyad</th>
                  <th style="min-width:220px;">Atanan Eğitmen</th>
                  <th style="min-width:190px;">Durum</th>
                  <th style="min-width:150px;">Bitiş</th>
                  <th style="min-width:180px;">Program Geçmişi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($uyeler as $idx => $u): ?>
                  <?php
                    $isRecent = $idx < 12 ? 1 : 0;
                    $statusKey = $u['_status_key'];
                    $statusText = $u['_status_text'];
                    $bitis = trim((string)($u['bitis_tarihi'] ?? ''));
                  ?>
                  <tr class="row-select"
                      data-filter-status="<?= h($statusKey) ?>"
                      data-is-recent="<?= $isRecent ?>"
                      data-uye='<?= h(json_encode($u, JSON_UNESCAPED_UNICODE)) ?>'>
                    <td>
                      <div class="fw-semibold"><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></div>
                      <div class="app-muted small"><?= h($u['eposta_adresi'] ?? '-') ?></div>
                    </td>
                    <td><?= h(trim((string)($u['egitmen_adi'] ?? '')) ?: '—') ?></td>
                    <td>
                      <span class="badge-status <?= h($statusKey) ?>">
                        <span class="dot"></span> <?= h($statusText) ?>
                      </span>
                    </td>
                    <td><?= h($bitis !== '' ? $bitis : '—') ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-info"
                         href="antrenman-programim.php?user_id=<?= (int)($u['id'] ?? 0) ?>"
                         onclick="event.stopPropagation();">
                        🏋️ Antrenman
                      </a>
                      <a class="btn btn-sm btn-outline-success"
                         href="beslenme-programim.php?user_id=<?= (int)($u['id'] ?? 0) ?>"
                         onclick="event.stopPropagation();">
                        🍽️ Beslenme
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (empty($uyeler)): ?>
                  <tr>
                    <td colspan="5" class="text-center app-muted">Üye yok.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="app-muted small">
            İpucu: “Yenilemesi Gelen” sekmesi süresi bitmiş ya da paket hakkı bitmiş üyeleri filtreler.
          </div>
        </div>
      </div>

      <?php if (!empty($yenilemesi_gelen)): ?>
        <div class="app-card p-3 p-lg-4 mt-3">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <div class="section-title">Hızlı Yenileme</div>
              <div class="app-muted small">Süresi biten üyeleri tek tıkla yenileyebilirsin.</div>
            </div>
          </div>

          <div class="app-table">
            <table class="table table-bordered align-middle">
              <thead>
                <tr>
                  <th style="min-width:220px;">Ad Soyad</th>
                  <th style="min-width:160px;">Tip</th>
                  <th style="min-width:130px;">Bitiş</th>
                  <th style="min-width:220px;">İşlem</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($yenilemesi_gelen as $u): ?>
                  <tr>
                    <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                    <td><?= h(($u['abonelik_tipi'] ?? 'aylik') === 'ders_paketi' ? 'Ders Paketi' : 'Aylık') ?></td>
                    <td><?= h($u['bitis_tarihi'] ?? '—') ?></td>
                    <td>
                      <?php if (($u['abonelik_tipi'] ?? 'aylik') === 'ders_paketi'): ?>
                        <button type="button"
                                class="btn btn-sm btn-success quick-renew-btn"
                                data-id="<?= (int)$u['id'] ?>">
                          Paketi Yenile
                        </button>
                      <?php else: ?>
                        <div class="d-flex gap-2 align-items-center">
                          <select class="form-select form-select-sm quick-renew-month" style="max-width:110px;">
                            <option value="1">1 ay</option>
                            <option value="3">3 ay</option>
                            <option value="6">6 ay</option>
                            <option value="12">12 ay</option>
                          </select>
                          <button type="button"
                                  class="btn btn-sm btn-success quick-renew-btn"
                                  data-id="<?= (int)$u['id'] ?>">
                            Yenile
                          </button>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-xl-5">
      <div class="sticky-side">
        <div class="app-card p-3 p-lg-4" id="detailCard">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="section-title">Üye Detayı</div>
            <span class="badge rounded-pill badge-soft" id="detailBadge">Seçilmedi</span>
          </div>
          <div class="app-muted small mb-3">Listeden bir üye seç.</div>

          <div id="detailBody" class="d-none">
            <input type="hidden" id="selectedUyeId" value="">

            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">Ad *</label>
                <input type="text" id="adInput" class="form-control app-input">
              </div>
              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">Soyad *</label>
                <input type="text" id="soyadInput" class="form-control app-input">
              </div>

              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">E-posta *</label>
                <input type="email" id="mailInput" class="form-control app-input">
              </div>
              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">Telefon</label>
                <input type="text" id="telInput" class="form-control app-input">
              </div>

              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">Üyelik Numarası</label>
                <input type="text" id="uyelikNoInput" class="form-control app-input">
              </div>
              <div class="col-12 col-md-6">
                <label class="app-muted small mb-1">Eğitmen Ata</label>
                <select id="egitmenSelect" class="form-control app-input">
                  <option value="0">— Eğitmen seç / kaldır —</option>
                  <?php foreach ($egitmenler as $e): ?>
                    <option value="<?= (int)$e['id'] ?>"><?= h($e['ad'] . ' ' . $e['soyad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12">
                <div class="app-muted small mb-1">Üyelik Durumu</div>
                <div id="statusWrap"></div>
                <div class="app-muted small mt-2" id="statusHint"></div>
              </div>

              <div class="col-4">
                <div class="app-muted small">Kilo</div>
                <div id="d_kilo">—</div>
              </div>
              <div class="col-4">
                <div class="app-muted small">Boy</div>
                <div id="d_boy">—</div>
              </div>
              <div class="col-4">
                <div class="app-muted small">Yağ Oranı</div>
                <div id="d_yag">—</div>
              </div>
              <div class="col-4">
                <div class="app-muted small">Boyun</div>
                <div id="d_boyun">—</div>
              </div>
              <div class="col-4">
                <div class="app-muted small">Bel</div>
                <div id="d_bel">—</div>
              </div>
              <div class="col-4">
                <div class="app-muted small">Basen</div>
                <div id="d_basen">—</div>
              </div>
            </div>

            <hr class="my-4 light-hr">

            <div class="section-title mb-2">Abonelik Bilgileri</div>
            <div class="app-muted small mb-2">Abonelik tipi, dönem, ödeme ve durum burada yönetilir.</div>

            <div class="row g-3">
              <div class="col-12">
                <label class="app-muted small mb-1">Abonelik Tipi</label>
                <select id="abonelikTipSelect" class="form-control app-input">
                  <option value="aylik">Aylık</option>
                  <option value="ders_paketi">Ders Paketi (30 gün)</option>
                </select>
              </div>

              <div class="col-12" id="aylikBox">
                <label class="app-muted small mb-1">Süre (Ay)</label>
                <select id="abonelikSelect" class="form-control app-input">
                  <option value="">— Seç —</option>
                  <option value="1">1 Aylık</option>
                  <option value="3">3 Aylık</option>
                  <option value="6">6 Aylık</option>
                  <option value="12">12 Aylık</option>
                </select>
              </div>

              <div class="col-12 d-none" id="paketBox">
                <label class="app-muted small mb-1">Aylık Seans Hakkı</label>
                <input id="paketSeansInput" type="number" min="1" class="form-control app-input" placeholder="Örn: 8">
                <div class="app-muted small mt-1">Ders paketi 30 gün geçerlidir. Kullanılmayan haklar yanar.</div>
              </div>

              <div class="col-6">
                <label class="app-muted small mb-1">Başlangıç Tarihi</label>
                <input id="baslangicInput" type="date" class="form-control app-input">
              </div>

              <div class="col-6">
                <label class="app-muted small mb-1">Bitiş Tarihi (Otomatik)</label>
                <input id="bitisInput" type="date" class="form-control app-input" readonly>
              </div>

              <div class="col-12 d-flex align-items-center gap-2 mt-1">
                <input id="odemeCheck" type="checkbox" class="form-check-input" style="transform:scale(1.05);">
                <label for="odemeCheck" class="mb-0">Ödeme Alındı</label>
                <span class="badge rounded-pill badge-soft ms-auto" id="aktifBadge">Durum: —</span>
              </div>

              <div class="col-12">
                <span class="badge rounded-pill badge-soft" id="paketInfoBadge" style="display:none;">Paket: —</span>
              </div>
            </div>

            <hr class="my-4 light-hr">

            <div class="section-title mb-2">Üyelik Dondurma</div>
            <div class="app-muted small mb-2">Dondurma çözülünce bitiş tarihi, dondurulan gün kadar ileri alınır.</div>

            <div class="row g-3">
              <div class="col-12 d-flex align-items-center gap-2">
                <input id="dondurCheck" type="checkbox" class="form-check-input" style="transform:scale(1.05);">
                <label for="dondurCheck" class="mb-0">Üyeliği Dondur</label>
                <span class="badge rounded-pill badge-soft ms-auto" id="dondurBadge">Dondurma: —</span>
              </div>

              <div class="col-6">
                <label class="app-muted small mb-1">Dondurma Başlangıç</label>
                <input id="dondurBasInput" type="date" class="form-control app-input">
              </div>

              <div class="col-6">
                <label class="app-muted small mb-1">Dondurma Bitiş (ops.)</label>
                <input id="dondurBitInput" type="date" class="form-control app-input">
              </div>

              <div class="col-12">
                <label class="app-muted small mb-1">Not (ops.)</label>
                <input id="dondurNotInput" type="text" class="form-control app-input" placeholder="Örn: sağlık nedeniyle ara">
              </div>
            </div>

            <hr class="my-4 light-hr">

            <div class="section-title mb-2">Program Geçmişi</div>
            <div class="d-flex gap-2 flex-wrap">
              <a id="workoutLink" class="btn btn-outline-info" href="#" target="_self">🏋️ Antrenman Geçmişi</a>
              <a id="nutritionLink" class="btn btn-outline-success" href="#" target="_self">🍽️ Beslenme Geçmişi</a>
            </div>

            <button id="btnSaveAll" class="btn btn-primary mt-4 w-100" type="button">
              Kaydet
            </button>

            <div id="saveMsg" class="app-muted small mt-2"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const searchBox = document.getElementById('searchBox');
  const table = document.getElementById('uyeTable');
  const rows = Array.from(table.querySelectorAll('tbody tr'));
  const listTabs = Array.from(document.querySelectorAll('#listTabs .nav-link'));

  let activeFilter = 'all';

  function normalizeText(v){
    return (v || '').toString().toLowerCase().trim();
  }

  function applyFilters(){
    const q = normalizeText(searchBox.value);

    rows.forEach(r => {
      const text = normalizeText(r.innerText);
      const status = r.getAttribute('data-filter-status') || '';
      const isRecent = r.getAttribute('data-is-recent') === '1';

      let filterOk = true;

      if (activeFilter === 'recent') {
        filterOk = isRecent;
      } else if (activeFilter === 'renew') {
        filterOk = (status === 'renew');
      } else if (activeFilter === 'passive') {
        filterOk = (status === 'passive');
      }

      const searchOk = q === '' || text.includes(q);

      r.style.display = (filterOk && searchOk) ? '' : 'none';
    });
  }

  listTabs.forEach(btn => {
    btn.addEventListener('click', function(){
      listTabs.forEach(x => x.classList.remove('active'));
      this.classList.add('active');
      activeFilter = this.getAttribute('data-filter') || 'all';
      applyFilters();
    });
  });

  searchBox.addEventListener('input', applyFilters);

  const detailBody = document.getElementById('detailBody');
  const detailBadge = document.getElementById('detailBadge');

  const selectedUyeIdEl = document.getElementById('selectedUyeId');

  const adInput = document.getElementById('adInput');
  const soyadInput = document.getElementById('soyadInput');
  const mailInput = document.getElementById('mailInput');
  const telInput = document.getElementById('telInput');
  const uyelikNoInput = document.getElementById('uyelikNoInput');

  const egitmenSelect = document.getElementById('egitmenSelect');

  const abonelikTipSelect = document.getElementById('abonelikTipSelect');
  const aylikBox = document.getElementById('aylikBox');
  const paketBox = document.getElementById('paketBox');
  const paketSeansInput = document.getElementById('paketSeansInput');
  const paketInfoBadge = document.getElementById('paketInfoBadge');

  const abonelikSelect = document.getElementById('abonelikSelect');
  const baslangicInput = document.getElementById('baslangicInput');
  const bitisInput = document.getElementById('bitisInput');
  const odemeCheck = document.getElementById('odemeCheck');
  const aktifBadge = document.getElementById('aktifBadge');

  const dondurCheck = document.getElementById('dondurCheck');
  const dondurBas = document.getElementById('dondurBasInput');
  const dondurBit = document.getElementById('dondurBitInput');
  const dondurNot = document.getElementById('dondurNotInput');
  const dondurBadge = document.getElementById('dondurBadge');

  const workoutLink = document.getElementById('workoutLink');
  const nutritionLink = document.getElementById('nutritionLink');

  const saveMsg = document.getElementById('saveMsg');
  const btnSaveAll = document.getElementById('btnSaveAll');

  function setText(id, val){
    const el = document.getElementById(id);
    if (el) {
      el.textContent = (val ?? '') === '' ? '—' : val;
    }
  }

  function normalizeDate(val){
    if (!val) return '';
    return String(val).slice(0, 10);
  }

  function calcEndDate(startDateStr, monthsStr){
    const start = startDateStr ? new Date(startDateStr + 'T00:00:00') : null;
    const months = monthsStr ? parseInt(monthsStr, 10) : 0;
    if (!start || !months) return '';

    const d = new Date(start);
    const originalDay = d.getDate();

    d.setMonth(d.getMonth() + months);

    if (d.getDate() !== originalDay) {
      d.setDate(0);
    }

    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function addDays(dateStr, days){
    if(!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + (parseInt(days,10) || 0));
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function todayYMD(){
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function computeStatus(data){
    if (String(data.donduruldu ?? '0') === '1') {
      return { key:'frozen', text:'Donduruldu', hint:'Üyelik dondurulmuş durumda. Süre bu süreçte durur.' };
    }

    const odeme = String(data.odeme_alindi ?? '0') === '1';
    if (!odeme) {
      return { key:'passive', text:'Pasif', hint:'Ödeme alınmadı. Üyelik pasif durumda.' };
    }

    const tip = (data.abonelik_tipi || 'aylik');
    const bitis = normalizeDate(data.bitis_tarihi ?? '');
    const t = todayYMD();

    if (tip === 'ders_paketi') {
      const kalan = parseInt(data.paket_kalan_seans ?? '0', 10);
      if ((kalan <= 0) || (bitis && t > bitis) || (String(data.abonelik_durum || '') === 'yenileme')) {
        return { key:'renew', text:'Yenileme', hint:'Paket hakkı bitmiş veya dönem sona ermiş. Yenileme bekleniyor.' };
      }
      return { key:'active', text:'Aktif', hint:'Paket aktif.' };
    }

    if (!bitis) {
      return { key:'active', text:'Aktif', hint:'Ödeme alındı. Bitiş tarihi tanımlı değil.' };
    }

    if (t > bitis) {
      return { key:'renew', text:'Yenileme', hint:'Abonelik bitmiş. Yenileme bekleniyor.' };
    }

    return { key:'active', text:'Aktif', hint:'Abonelik aktif.' };
  }

  function renderStatusPill(status){
    return `
      <span class="badge-status ${status.key}">
        <span class="dot"></span> ${status.text}
      </span>
    `;
  }

  function refreshBitis(){
    const tip = abonelikTipSelect.value || 'aylik';

    if (!baslangicInput.value) {
      bitisInput.value = '';
      return;
    }

    if (tip === 'ders_paketi') {
      bitisInput.value = addDays(baslangicInput.value, 30);
    } else {
      bitisInput.value = calcEndDate(baslangicInput.value, abonelikSelect.value);
    }
  }

  function refreshAktifBadge(){
    aktifBadge.textContent = odemeCheck.checked ? 'Durum: Ödeme Var' : 'Durum: Ödeme Yok';
  }

  function refreshDondurBadge(){
    dondurBadge.textContent = dondurCheck.checked ? 'Dondurma: Açık' : 'Dondurma: Kapalı';
  }

  function refreshAbonelikUI(){
    const tip = abonelikTipSelect.value || 'aylik';

    if (tip === 'ders_paketi') {
      aylikBox.classList.add('d-none');
      paketBox.classList.remove('d-none');
    } else {
      aylikBox.classList.remove('d-none');
      paketBox.classList.add('d-none');
    }

    refreshBitis();
  }

  abonelikTipSelect.addEventListener('change', refreshAbonelikUI);
  abonelikSelect.addEventListener('change', refreshAbonelikUI);
  baslangicInput.addEventListener('change', refreshAbonelikUI);

  paketSeansInput.addEventListener('input', function(){
    const v = parseInt(paketSeansInput.value || '0', 10);
    paketInfoBadge.style.display = v > 0 ? 'inline-block' : 'none';
    paketInfoBadge.textContent = v > 0 ? `Paket: ${v} seans/30 gün` : 'Paket: —';
  });

  odemeCheck.addEventListener('change', refreshAktifBadge);
  dondurCheck.addEventListener('change', refreshDondurBadge);

  rows.forEach(r => {
    r.addEventListener('click', () => {
      rows.forEach(x => x.classList.remove('table-active'));
      r.classList.add('table-active');

      const data = JSON.parse(r.getAttribute('data-uye') || '{}');

      detailBody.classList.remove('d-none');
      detailBadge.textContent = 'Seçildi';

      selectedUyeIdEl.value = data.id || '';

      adInput.value = data.ad || '';
      soyadInput.value = data.soyad || '';
      mailInput.value = data.eposta_adresi || '';
      telInput.value = data.tel_no || '';
      uyelikNoInput.value = data.uyelik_numarasi || '';

      egitmenSelect.value = String(data.egitmen_id || 0);

      const status = computeStatus(data);
      document.getElementById('statusWrap').innerHTML = renderStatusPill(status);
      document.getElementById('statusHint').textContent = status.hint;

      setText('d_kilo', data.kilo_kg ? (data.kilo_kg + ' kg') : '—');
      setText('d_boy', data.boy_cm ? (data.boy_cm + ' cm') : '—');
      setText('d_yag', data.yag_orani ? (data.yag_orani + ' %') : '—');
      setText('d_boyun', data.boyun_cevresi ? (data.boyun_cevresi + ' cm') : '—');
      setText('d_bel', data.bel_cevresi ? (data.bel_cevresi + ' cm') : '—');
      setText('d_basen', data.basen_cevresi ? (data.basen_cevresi + ' cm') : '—');

      abonelikTipSelect.value = (data.abonelik_tipi || 'aylik');
      abonelikSelect.value = (data.abonelik_suresi_ay ?? '') ? String(data.abonelik_suresi_ay) : '';
      baslangicInput.value = normalizeDate(data.baslangic_tarihi ?? '');
      bitisInput.value = normalizeDate(data.bitis_tarihi ?? '');

      paketSeansInput.value = (data.paket_toplam_seans ?? '') ? String(data.paket_toplam_seans) : '';
      if (paketSeansInput.value) {
        paketInfoBadge.style.display = 'inline-block';
        paketInfoBadge.textContent = `Paket: ${paketSeansInput.value} seans/30 gün (Kalan: ${(data.paket_kalan_seans ?? '—')})`;
      } else {
        paketInfoBadge.style.display = 'none';
      }

      odemeCheck.checked = String(data.odeme_alindi ?? '0') === '1';
      refreshAktifBadge();

      dondurCheck.checked = String(data.donduruldu ?? '0') === '1';
      dondurBas.value = normalizeDate(data.dondurma_baslangic ?? '');
      dondurBit.value = normalizeDate(data.dondurma_bitis ?? '');
      dondurNot.value = (data.dondurma_notu ?? '') || '';
      refreshDondurBadge();

      refreshAbonelikUI();

      workoutLink.href = `antrenman-programim.php?user_id=${encodeURIComponent(data.id || 0)}`;
      nutritionLink.href = `beslenme-programim.php?user_id=${encodeURIComponent(data.id || 0)}`;

      saveMsg.textContent = '';
    });
  });

  btnSaveAll.addEventListener('click', async function(){
    const uyeId = selectedUyeIdEl.value;
    if (!uyeId) {
      alert('Lütfen önce listeden bir üye seçin.');
      return;
    }

    if (!adInput.value.trim() || !soyadInput.value.trim() || !mailInput.value.trim()) {
      alert('Ad, soyad ve e-posta zorunludur.');
      return;
    }

    if (!baslangicInput.value) {
      alert('Başlangıç tarihi zorunludur.');
      return;
    }

    const tip = abonelikTipSelect.value || 'aylik';
    if (tip === 'aylik') {
      if (!abonelikSelect.value) {
        alert('Aylık abonelikte süre (ay) seçmelisin.');
        return;
      }
    } else {
      const p = parseInt(paketSeansInput.value || '0', 10);
      if (!p || p <= 0) {
        alert('Ders paketi için aylık seans hakkı zorunludur.');
        return;
      }
    }

    const payload = new FormData();
    payload.append('action', 'save_member_full');
    payload.append('csrf_token', <?= json_encode(csrf_token()) ?>);

    payload.append('uye_id', uyeId);
    payload.append('ad', adInput.value.trim());
    payload.append('soyad', soyadInput.value.trim());
    payload.append('eposta_adresi', mailInput.value.trim());
    payload.append('tel_no', telInput.value.trim());
    payload.append('uyelik_numarasi', uyelikNoInput.value.trim());

    payload.append('egitmen_id', egitmenSelect.value);

    payload.append('abonelik_tipi', tip);
    payload.append('abonelik_suresi_ay', tip === 'aylik' ? abonelikSelect.value : '0');
    payload.append('paket_toplam_seans', tip === 'ders_paketi' ? (paketSeansInput.value || '0') : '0');

    payload.append('baslangic_tarihi', baslangicInput.value);
    payload.append('odeme_alindi', odemeCheck.checked ? '1' : '0');

    payload.append('donduruldu', dondurCheck.checked ? '1' : '0');
    payload.append('dondurma_baslangic', dondurBas.value);
    payload.append('dondurma_bitis', dondurBit.value);
    payload.append('dondurma_notu', dondurNot.value);

    btnSaveAll.disabled = true;
    saveMsg.textContent = 'Kaydediliyor...';

    try {
      const res = await fetch(window.location.href, {
        method: 'POST',
        body: payload
      });

      const json = await res.json();

      if (json.ok) {
        saveMsg.textContent = '✅ ' + (json.message || 'Kaydedildi.');
        setTimeout(() => location.reload(), 500);
      } else {
        saveMsg.textContent = '❌ ' + (json.message || 'Hata');
      }
    } catch (e) {
      saveMsg.textContent = '❌ Bağlantı hatası.';
    } finally {
      btnSaveAll.disabled = false;
    }
  });

  document.querySelectorAll('.quick-renew-btn').forEach(btn => {
    btn.addEventListener('click', async function(){
      const id = this.getAttribute('data-id');
      if (!id) return;

      let ay = 1;
      const row = this.closest('tr');
      const monthSelect = row ? row.querySelector('.quick-renew-month') : null;
      if (monthSelect) ay = parseInt(monthSelect.value || '1', 10);

      const payload = new FormData();
      payload.append('action', 'renew_member');
      payload.append('csrf_token', <?= json_encode(csrf_token()) ?>);
      payload.append('id', id);
      payload.append('ay', String(ay));

      this.disabled = true;
      const oldText = this.textContent;
      this.textContent = 'Yenileniyor...';

      try {
        const res = await fetch(window.location.href, {
          method: 'POST',
          body: payload
        });
        const json = await res.json();

        if (json.ok) {
          alert(json.message || 'Üyelik yenilendi.');
          location.reload();
        } else {
          alert(json.message || 'Hata oluştu.');
        }
      } catch (e) {
        alert('Bağlantı hatası.');
      } finally {
        this.disabled = false;
        this.textContent = oldText;
      }
    });
  });

  applyFilters();
</script>

<?php include "inc/footer.php"; ?>