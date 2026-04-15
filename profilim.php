<?php
require_once __DIR__ . '/inc/security.php';
configure_error_reporting();

// session_start() ve session kontrolü security.php'de yapılır
$user = require_session(); // yönlendirme gerekiyorsa otomatik login.php'ye yönlendirir

include 'inc/db.php'; // DB bağlantısı (conn)

$user_id = (int)($user['id'] ?? 0);
if ($user_id <= 0) {
    die("Oturum kullanıcı id bulunamadı. Lütfen yeniden giriş yapın.");
}

$error = '';
$success = '';
$sifre_error = '';
$sifre_success = '';

/* =========================
   PROFİL VERİSİNİ ÇEK (ID ile)
========================= */
$stmt = $conn->prepare("SELECT * FROM uye_kullanicilar WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profil = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profil) {
    die("Kullanıcı kaydı bulunamadı (id: {$user_id}).");
}

/* =========================
   ŞİFRE DEĞİŞTİRME
========================= */
if (isset($_POST['sifre_degistir'])) {
    csrf_verify();

    $mevcut_sifre = trim($_POST['mevcut_sifre'] ?? '');
    $yeni_sifre = trim($_POST['yeni_sifre'] ?? '');
    $yeni_sifre_tekrar = trim($_POST['yeni_sifre_tekrar'] ?? '');

    if ($yeni_sifre !== $yeni_sifre_tekrar) {
        $sifre_error = "Yeni şifreler eşleşmiyor.";
    } elseif (strlen($yeni_sifre) < 6) {
        $sifre_error = "Yeni şifre en az 6 karakter olmalıdır.";
    } else {

        $stmt = $conn->prepare("SELECT sifre FROM uye_kullanicilar WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($mevcut_sifre, $row['sifre'])) {
            $sifre_error = "Mevcut şifre yanlış.";
        } else {
            $yeni_hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);

            $update = $conn->prepare("UPDATE uye_kullanicilar SET sifre = ? WHERE id = ?");
            $update->bind_param("si", $yeni_hash, $user_id);
            $update->execute();

            if ($update->affected_rows > 0) {
                $sifre_success = "Şifreniz başarıyla güncellendi.";
            } else {
                $sifre_error = "Şifre güncellenemedi (aynı şifre olabilir).";
            }
            $update->close();
        }
    }
}

/* =========================
   PROFİL GÜNCELLEME (SADECE UPDATE)
   NOT: abonelik/dondurma alanları ÜYE TARAFINDAN GÜNCELLENMEZ!
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profil_kaydet'])) {
    csrf_verify();

    $spor_hedefi      = trim($_POST['spor_hedefi'] ?? '');
    $spor_deneyimi    = trim($_POST['spor_deneyimi'] ?? '');
    $saglik_sorunlari = trim($_POST['saglik_sorunlari'] ?? '');

    $yas              = ($_POST['yas'] ?? '') !== '' ? (int)$_POST['yas'] : null;
    $boy_cm           = ($_POST['boy_cm'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['boy_cm']) : null;
    $kilo_kg          = ($_POST['kilo_kg'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['kilo_kg']) : null;
    $boyun_cevresi    = ($_POST['boyun_cevresi'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['boyun_cevresi']) : null;
    $bel_cevresi      = ($_POST['bel_cevresi'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['bel_cevresi']) : null;
    $basen_cevresi    = ($_POST['basen_cevresi'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['basen_cevresi']) : null;

    // ✅ Yeni alanlar
    $cinsiyet = trim($_POST['cinsiyet'] ?? '');
    if ($cinsiyet === '') $cinsiyet = null;
    if ($cinsiyet !== null && !in_array($cinsiyet, ['erkek','kadin'], true)) {
        $error = "Cinsiyet geçersiz.";
    }

    $dogum_tarihi = trim($_POST['dogum_tarihi'] ?? '');
    if ($dogum_tarihi === '') $dogum_tarihi = null;
    if ($error === '' && $dogum_tarihi !== null) {
        $dt = DateTime::createFromFormat('Y-m-d', $dogum_tarihi);
        if (!$dt) $error = "Doğum tarihi formatı geçersiz. (YYYY-AA-GG)";
        else $dogum_tarihi = $dt->format('Y-m-d');
    }

    $aktivite_seviyesi = trim($_POST['aktivite_seviyesi'] ?? '');
    if ($aktivite_seviyesi === '') $aktivite_seviyesi = null;
    if ($aktivite_seviyesi !== null && !in_array($aktivite_seviyesi, ['sedanter','hafif','orta','yuksek','cok_yuksek'], true)) {
        $error = "Aktivite seviyesi geçersiz.";
    }

    $kilo_hedefi = trim($_POST['kilo_hedefi'] ?? '');
    if ($kilo_hedefi === '') $kilo_hedefi = null;
    if ($kilo_hedefi !== null && !in_array($kilo_hedefi, ['kilo_ver','koru','kilo_al'], true)) {
        $error = "Kilo hedefi geçersiz.";
    }

    $hedef_tempo = trim($_POST['hedef_tempo'] ?? '');
    if ($hedef_tempo === '') $hedef_tempo = null;
    if ($hedef_tempo !== null && !in_array($hedef_tempo, ['yavas','orta','hizli'], true)) {
        $error = "Hedef temposu geçersiz.";
    }

    $foto_yolu = $profil['foto_yolu'] ?? null;

    if (!empty($_FILES['foto']['name'])) {

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $maxSize = 5 * 1024 * 1024;

        if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            $error = "Dosya yükleme hatası.";
        } elseif (!is_uploaded_file($_FILES['foto']['tmp_name'])) {
            $error = "Geçersiz yükleme.";
        } elseif ($_FILES['foto']['size'] > $maxSize) {
            $error = "Profil fotoğrafı en fazla 5MB olabilir.";
        } else {
            // Sunucu taraflı MIME tespiti (client'ın bildirdiği $_FILES['type'] güvenilmez)
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $fi->file($_FILES['foto']['tmp_name']);
            if (!isset($allowedMimes[$detectedMime])) {
                $error = "Sadece JPG, PNG veya WEBP yükleyebilirsiniz.";
            } else {
                $safeExt = $allowedMimes[$detectedMime];
                $newName = 'pp_' . $user_id . '_' . time() . '.' . $safeExt;
                $dest = 'uploads/' . $newName;

                if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                    $foto_yolu = $dest;
                } else {
                    $error = "Fotoğraf yüklenemedi. Lütfen tekrar deneyin.";
                }
            }
        }
    }

    if ($error === '') {

        $stmt = $conn->prepare("
            UPDATE uye_kullanicilar SET
                spor_hedefi = ?,
                spor_deneyimi = ?,
                saglik_sorunlari = ?,
                yas = ?,
                boy_cm = ?,
                kilo_kg = ?,
                boyun_cevresi = ?,
                bel_cevresi = ?,
                basen_cevresi = ?,
                foto_yolu = ?,

                -- ✅ yeni alanlar
                cinsiyet = ?,
                dogum_tarihi = ?,
                aktivite_seviyesi = ?,
                kilo_hedefi = ?,
                hedef_tempo = ?

            WHERE id = ?
        ");

        $stmt->bind_param(
            "sssidddddssssssi",
            $spor_hedefi,
            $spor_deneyimi,
            $saglik_sorunlari,
            $yas,
            $boy_cm,
            $kilo_kg,
            $boyun_cevresi,
            $bel_cevresi,
            $basen_cevresi,
            $foto_yolu,
            $cinsiyet,
            $dogum_tarihi,
            $aktivite_seviyesi,
            $kilo_hedefi,
            $hedef_tempo,
            $user_id
        );

        if ($stmt->execute()) {
            $success = 'Profil bilgileriniz güncellendi.';

            // ✅ Update sonrası otomatik hedefleri hesapla (eksik bilgi varsa sayfayı bozmayız)
            try {
                // Bu dosya yolunu projene göre düzelt: API klasörün konumuna göre
                require_once __DIR__ . '/api/nutrition/targets/_logic.php';
                recalcTargets($conn, $user_id);
            } catch (Throwable $t) {
                // İstersen debug için $t->getMessage() yazdırabiliriz, şimdilik sessiz geçiyoruz.
            }

            $stmt2 = $conn->prepare("SELECT * FROM uye_kullanicilar WHERE id = ?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            $profil = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
        } else {
            error_log('profilim.php güncelleme hatası: ' . $stmt->error);
            $error = 'Güncelleme sırasında bir hata oluştu. Lütfen tekrar deneyin.';
        }
        $stmt->close();
    }
}

/* =========================
   ABONELIK + DONDURMA + YENILEME (HESAPLANAN) GÖSTERİM
========================= */
function safe_date_tr($dateStr){
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '' || $dateStr === '0000-00-00') return '—';
    $dt = DateTime::createFromFormat('Y-m-d', substr($dateStr, 0, 10));
    if (!$dt) return '—';
    return $dt->format('d.m.Y');
}
function normalize_ymd($dateStr){
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '' || $dateStr === '0000-00-00') return '';
    return substr($dateStr, 0, 10);
}

$odeme_alindi = (int)($profil['odeme_alindi'] ?? 0);
$uye_aktif    = (int)($profil['uye_aktif'] ?? 0);

$baslangic_tr = safe_date_tr($profil['baslangic_tarihi'] ?? '');
$bitis_tr     = safe_date_tr($profil['bitis_tarihi'] ?? '');

$bitis_ymd = normalize_ymd($profil['bitis_tarihi'] ?? '');
$today_ymd = date('Y-m-d');

$abonelik_ay = (int)($profil['abonelik_suresi_ay'] ?? 0);
$abonelik_text = '—';
if (in_array($abonelik_ay, [1,3,6,12], true)) {
    $abonelik_text = $abonelik_ay . " Aylık";
}

$odeme_text = $odeme_alindi === 1 ? 'Ödeme alındı' : 'Ödeme alınmadı';

// Dondurma
$donduruldu = (int)($profil['donduruldu'] ?? 0);
$dond_bas_tr = safe_date_tr($profil['dondurma_baslangic'] ?? '');
$dond_bit_tr = safe_date_tr($profil['dondurma_bitis'] ?? '');
$dond_not = trim((string)($profil['dondurma_notu'] ?? ''));

// --- Durum hesapla (öncelik: dondurma)
$is_renew = false;

if ($donduruldu === 1) {
    $card_status_class = 'frozen';
    $card_status_text  = 'Donduruldu';
    $card_status_hint  = 'Üyeliğiniz geçici olarak dondurulmuştur. Süre bu süreçte durur.';
} else {
    if ($odeme_alindi !== 1) {
        $card_status_class = 'passive';
        $card_status_text  = 'Pasif';
        $card_status_hint  = 'Ödeme alınmadı. Üyelik pasif durumda.';
    } else {
        if ($bitis_ymd !== '' && $today_ymd > $bitis_ymd) {
            $is_renew = true;
            $card_status_class = 'renew';
            $card_status_text  = 'Yenileme';
            $card_status_hint  = '';
        } else {
            $card_status_class = 'active';
            $card_status_text  = 'Aktif';
            $card_status_hint  = 'Aboneliğiniz aktif görünüyor.';
        }
    }
}

/* SAYFA TASARIM AYARI */
$pageBodyClass = 'page-profil';
include "inc/header.php";
?>

<!-- (CSS ve HTML’nin geri kalanı seninkiyle aynı, aşağıda sadece form içine yeni alanlar eklendi) -->

<style>
/* senin mevcut CSS'in aynen bırakıldı */
body.page-profil{ background: #f6f7fb !important; color: #111827; }
.page-wrap{ max-width: 1100px; }
.glass-card{ background: #ffffff; border: 1px solid rgba(17,24,39,0.10); border-radius: 18px; box-shadow: 0 14px 35px rgba(17,24,39,0.10); }
.card-pad{ padding: 16px; }
.muted{ color: #6b7280; }
.section-title{ font-weight: 800; font-size: 1.05rem; margin: 0; color: #111827; }
.divider{ height: 1px; background: rgba(17,24,39,0.10); margin: 14px 0; }
.avatar{ width: 120px; height: 120px; border-radius: 999px; object-fit: cover; border: 1px solid rgba(17,24,39,0.12); background: rgba(17,24,39,0.03); }
.form-control, .form-select{ background: #ffffff !important; border: 1px solid rgba(17,24,39,0.12) !important; color: #111827 !important; border-radius: 14px !important; }
.form-control::placeholder{ color: #9ca3af; }
.form-control:focus, .form-select:focus{ border-color: rgba(37,99,235,0.55) !important; box-shadow: 0 0 0 .22rem rgba(37,99,235,.18) !important; }
.btn-primary{ border-radius: 14px; font-weight: 800; }
.btn-soft{ background: #f3f4f6; border: 1px solid rgba(17,24,39,0.12); color: #111827; border-radius: 14px; font-weight: 800; }
.btn-soft:hover{ background: #e9ecf3; }
.small-hint{ font-size: .85rem; color: #6b7280; }
.form-control[readonly]{ background: #f9fafb !important; cursor: not-allowed; }
.page-profil .row.g-3.mb-2 .form-control[readonly]{ font-weight: 800; letter-spacing: .2px; }
.sub-card{ background: linear-gradient(180deg, rgba(17,24,39,0.02), rgba(17,24,39,0.00)); border: 1px solid rgba(17,24,39,0.10); border-radius: 16px; padding: 14px; width: 100%; }
.status-pill{ display:inline-flex; align-items:center; gap:8px; padding: 6px 10px; border-radius: 999px; font-weight: 900; font-size: .85rem; border: 1px solid rgba(17,24,39,0.12); background: rgba(17,24,39,0.04); color: rgba(17,24,39,0.85); }
.dot{ width:10px; height:10px; border-radius: 999px; background: #9ca3af; }
.status-pill.active{ border-color: rgba(16,185,129,0.35); background: rgba(16,185,129,0.10); color: rgba(6,95,70,1); }
.status-pill.active .dot{ background: rgba(16,185,129,1); }
.status-pill.passive{ border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.10); color: rgba(127,29,29,1); }
.status-pill.passive .dot{ background: rgba(239,68,68,1); }
.status-pill.frozen{ border-color: rgba(59,130,246,0.40); background: rgba(59,130,246,0.10); color: rgba(30,58,138,1); }
.status-pill.frozen .dot{ background: rgba(59,130,246,1); }
.status-pill.renew{ border-color: rgba(245,158,11,0.45); background: rgba(245,158,11,0.14); color: rgba(146,64,14,1); }
.status-pill.renew .dot{ background: rgba(245,158,11,1); }
.meta-grid{ display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 12px; }
.meta-item{ background:#fff; border: 1px solid rgba(17,24,39,0.10); border-radius: 14px; padding: 10px 12px; }
.meta-label{ font-size: .82rem; color: rgba(17,24,39,0.60); }
.meta-value{ font-weight: 900; color: rgba(17,24,39,0.90); margin-top: 2px; }
</style>

<div class="container page-wrap my-5">

  <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h2 class="m-0">👤 Profilim</h2>
      <div class="muted mt-1">Bilgilerini görüntüle, güncelle ve şifreni değiştir.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($sifre_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($sifre_error) ?></div>
  <?php endif; ?>
  <?php if ($sifre_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sifre_success) ?></div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- SOL -->
    <div class="col-lg-4">
      <div class="glass-card card-pad">
        <div class="d-flex flex-column align-items-center text-center">
          <?php
            $pp = $profil['foto_yolu'] ?? '';
            $ppFallback = "https://www.gravatar.com/avatar/?d=mp";
          ?>
          <img class="avatar mb-3" src="<?= htmlspecialchars($pp ?: $ppFallback) ?>" alt="Profil Fotoğrafı"
               onerror="this.src='<?= $ppFallback ?>';">

          <div class="fw-bold" style="font-size:1.05rem;">
            <?= htmlspecialchars(($user['ad'] ?? '') . ' ' . ($user['soyad'] ?? '')) ?>
          </div>

          <div class="divider w-100"></div>

          <!-- Abonelik Durumu Kartı -->
          <div class="sub-card text-start">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <div class="fw-bold">📌 Abonelik Durumu</div>

              <span class="status-pill <?= htmlspecialchars($card_status_class) ?>">
                <span class="dot"></span> <?= htmlspecialchars($card_status_text) ?>
              </span>
            </div>

            <div class="small-hint mt-1"><?= htmlspecialchars($card_status_hint) ?></div>

            <?php if ($is_renew): ?>
              <div class="small-hint mt-2" style="color: rgba(146,64,14,1); font-weight:800;">
                ⚠️ Aboneliğinizin süresi dolmuş. Yenileme işlemi için lütfen iletişime geçiniz.
              </div>
            <?php endif; ?>

            <div class="meta-grid">
              <div class="meta-item">
                <div class="meta-label">Abonelik</div>
                <div class="meta-value"><?= htmlspecialchars($abonelik_text) ?></div>
              </div>

              <div class="meta-item">
                <div class="meta-label">Ödeme</div>
                <div class="meta-value"><?= htmlspecialchars($odeme_text) ?></div>
              </div>

              <div class="meta-item">
                <div class="meta-label">Başlangıç</div>
                <div class="meta-value"><?= htmlspecialchars($baslangic_tr) ?></div>
              </div>

              <div class="meta-item">
                <div class="meta-label">Bitiş</div>
                <div class="meta-value"><?= htmlspecialchars($bitis_tr) ?></div>
              </div>

              <?php if ((int)($profil['donduruldu'] ?? 0) === 1): ?>
                <div class="meta-item">
                  <div class="meta-label">Dondurma Başlangıç</div>
                  <div class="meta-value"><?= htmlspecialchars($dond_bas_tr) ?></div>
                </div>

                <div class="meta-item">
                  <div class="meta-label">Dondurma Bitiş</div>
                  <div class="meta-value"><?= htmlspecialchars($dond_bit_tr) ?></div>
                </div>

                <?php if (trim((string)($profil['dondurma_notu'] ?? '')) !== ''): ?>
                  <div class="meta-item" style="grid-column: 1 / -1;">
                    <div class="meta-label">Not</div>
                    <div class="meta-value"><?= htmlspecialchars(trim((string)$profil['dondurma_notu'])) ?></div>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="divider w-100"></div>

          <form method="POST" enctype="multipart/form-data" class="w-100">
            <input type="hidden" name="profil_kaydet" value="1">
            <?= csrf_field() ?>
            <div class="mb-2 text-start">
              <label class="form-label">Profil Fotoğrafı</label>
              <input type="file" class="form-control" name="foto" accept="image/jpeg,image/png,image/webp">
              <div class="small-hint mt-2">JPG/PNG/WEBP • Maks 5MB</div>
            </div>
          </form>

          <div class="divider w-100"></div>

          <div class="w-100 text-start">
            <div class="section-title mb-2">🔒 Şifre Değiştir</div>
            <form method="POST">
              <input type="hidden" name="sifre_degistir" value="1">
              <?= csrf_field() ?>

              <div class="mb-2">
                <label class="form-label">Mevcut Şifre</label>
                <input type="password" class="form-control" name="mevcut_sifre" required>
              </div>

              <div class="mb-2">
                <label class="form-label">Yeni Şifre</label>
                <input type="password" class="form-control" name="yeni_sifre" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Yeni Şifre (Tekrar)</label>
                <input type="password" class="form-control" name="yeni_sifre_tekrar" required>
              </div>

              <button class="btn btn-soft w-100" type="submit">
                Şifreyi Güncelle
              </button>
            </form>
          </div>

        </div>
      </div>
    </div>

    <!-- SAĞ -->
    <div class="col-lg-8">
      <div class="glass-card card-pad">

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h5 class="section-title">📝 Profil Bilgileri</h5>
          <div class="small-hint">Bilgilerini güncelleyebilirsin</div>
        </div>

        <div class="divider"></div>

        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="profil_kaydet" value="1">
          <?= csrf_field() ?>

          <div class="row g-3">

            <!-- ✅ Yeni alanlar -->
            <div class="col-md-6">
              <label class="form-label">Cinsiyet</label>
              <?php $cv = $profil['cinsiyet'] ?? ''; ?>
              <select class="form-select" name="cinsiyet">
                <option value="" <?= ($cv==='')?'selected':''; ?>>Seçiniz</option>
                <option value="erkek" <?= ($cv==='erkek')?'selected':''; ?>>Erkek</option>
                <option value="kadin" <?= ($cv==='kadin')?'selected':''; ?>>Kadın</option>
              </select>
              <div class="small-hint mt-1">Kalori hedefi hesabı için gereklidir.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Doğum Tarihi</label>
              <input type="date" class="form-control" name="dogum_tarihi"
                     value="<?= htmlspecialchars($profil['dogum_tarihi'] ?? '') ?>">
              <div class="small-hint mt-1">Yaş buradan hesaplanır (önerilen).</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Aktivite Seviyesi</label>
              <?php $av = $profil['aktivite_seviyesi'] ?? ''; ?>
              <select class="form-select" name="aktivite_seviyesi">
                <option value="" <?= ($av==='')?'selected':''; ?>>Seçiniz</option>
                <option value="sedanter" <?= ($av==='sedanter')?'selected':''; ?>>Sedanter</option>
                <option value="hafif" <?= ($av==='hafif')?'selected':''; ?>>Hafif Aktif</option>
                <option value="orta" <?= ($av==='orta')?'selected':''; ?>>Orta Aktif</option>
                <option value="yuksek" <?= ($av==='yuksek')?'selected':''; ?>>Yüksek Aktif</option>
                <option value="cok_yuksek" <?= ($av==='cok_yuksek')?'selected':''; ?>>Çok Yüksek Aktif</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Kilo Hedefi</label>
              <?php $hv = $profil['kilo_hedefi'] ?? ''; ?>
              <select class="form-select" name="kilo_hedefi">
                <option value="" <?= ($hv==='')?'selected':''; ?>>Seçiniz</option>
                <option value="kilo_ver" <?= ($hv==='kilo_ver')?'selected':''; ?>>Kilo Verme</option>
                <option value="koru" <?= ($hv==='koru')?'selected':''; ?>>Koru</option>
                <option value="kilo_al" <?= ($hv==='kilo_al')?'selected':''; ?>>Kilo Alma</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Hedef Temposu</label>
              <?php $tv = $profil['hedef_tempo'] ?? 'orta'; ?>
              <select class="form-select" name="hedef_tempo">
                <option value="yavas" <?= ($tv==='yavas')?'selected':''; ?>>Yavaş</option>
                <option value="orta" <?= ($tv==='orta')?'selected':''; ?>>Orta</option>
                <option value="hizli" <?= ($tv==='hizli')?'selected':''; ?>>Hızlı</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Spor Hedefiniz</label>
              <select class="form-select" name="spor_hedefi" required>
                <?php $v = $profil['spor_hedefi'] ?? ''; ?>
                <option value="Kas Kütlesi Artırma" <?= ($v==='Kas Kütlesi Artırma')?'selected':''; ?>>Kas Kütlesi Artırma</option>
                <option value="Kilo Verme" <?= ($v==='Kilo Verme')?'selected':''; ?>>Kilo Verme</option>
                <option value="Yağ Kaybı" <?= ($v==='Yağ Kaybı')?'selected':''; ?>>Yağ Kaybı</option>
                <option value="Form Korumak" <?= ($v==='Form Korumak')?'selected':''; ?>>Form Korumak</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Spor Deneyimi</label>
              <select class="form-select" name="spor_deneyimi" required>
                <?php $v = $profil['spor_deneyimi'] ?? ''; ?>
                <option value="Yeni Başlayan" <?= ($v==='Yeni Başlayan')?'selected':''; ?>>Yeni Başlayan</option>
                <option value="Orta Seviye" <?= ($v==='Orta Seviye')?'selected':''; ?>>Orta Seviye</option>
                <option value="İleri Seviye" <?= ($v==='İleri Seviye')?'selected':''; ?>>İleri Seviye</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Varsa Sağlık Sorunlarınız</label>
              <textarea class="form-control" name="saglik_sorunlari" rows="3"
                        placeholder="Örn: bel fıtığı, diz ağrısı..."><?= htmlspecialchars($profil['saglik_sorunlari'] ?? '') ?></textarea>
            </div>

            <div class="col-md-4">
              <label class="form-label">Yaş</label>
              <input type="number" class="form-control" name="yas" value="<?= htmlspecialchars($profil['yas'] ?? '') ?>">
              <div class="small-hint mt-1">Doğum tarihi girersen burası opsiyonel.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Boy (cm)</label>
              <input type="number" class="form-control" name="boy_cm" value="<?= htmlspecialchars($profil['boy_cm'] ?? '') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label">Kilo (kg)</label>
              <input type="number" class="form-control" name="kilo_kg" value="<?= htmlspecialchars($profil['kilo_kg'] ?? '') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label">Boyun (cm)</label>
              <input type="number" class="form-control" name="boyun_cevresi" value="<?= htmlspecialchars($profil['boyun_cevresi'] ?? '') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label">Bel (cm)</label>
              <input type="number" class="form-control" name="bel_cevresi" value="<?= htmlspecialchars($profil['bel_cevresi'] ?? '') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label">Basen (cm) (Kadınlar İçin)</label>
              <input type="number" class="form-control" name="basen_cevresi" value="<?= htmlspecialchars($profil['basen_cevresi'] ?? '') ?>">
            </div>
          </div>

          <div class="divider"></div>

<?php
  $yag_orani = $profil['yag_orani'] ?? null;
  $yag_orani_text = ($yag_orani === null || $yag_orani === '') ? '—' : (rtrim(rtrim(number_format((float)$yag_orani, 1, '.', ''), '0'), '.') . ' %');

  $vki_text = trim((string)($profil['vki_durum'] ?? ''));
  if ($vki_text === '') { $vki_text = '—'; }
?>

<div class="row g-3 mb-2">
  <div class="col-md-6">
    <label class="form-label">Yağ Oranı</label>
    <input type="text" class="form-control" value="<?= htmlspecialchars($yag_orani_text) ?>" readonly>
  </div>

  <div class="col-md-6">
    <label class="form-label">Vücut Kitle Endeksi</label>
    <input type="text" class="form-control" value="<?= htmlspecialchars($vki_text) ?>" readonly>
  </div>
</div>

<div class="divider"></div>

<button type="submit" class="btn btn-primary w-100">
  Bilgileri Kaydet
</button>

        </form>

      </div>
    </div>

  </div>
</div>

<?php include "inc/footer.php"; ?>