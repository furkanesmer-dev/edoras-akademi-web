<?php
// admin-dashboard.php

include "inc/header.php"; // session + db($conn) + tema + güvenlik

// Admin yetkisi zorunlu (header.php session kontrolü yapar; rol kontrolü burada)
if ($yetki !== 'admin') {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

$user  = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';

// Sadece admin
if ($yetki !== 'admin') {
  echo "<div class='p-4 app-card'><h4>⛔ Yetkisiz</h4><div class='app-muted'>Bu sayfa sadece admin içindir.</div></div>";
  include "inc/footer.php";
  exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  echo "<div class='p-4 app-card'><h4>⛔ Hata</h4><div class='app-muted'>DB bağlantısı (\$conn) bulunamadı.</div></div>";
  include "inc/footer.php";
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function compute_member_status_key(array $u): string {
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

  if ($bitis !== '' && $bitis !== '0000-00-00' && $today > $bitis) {
    return 'renew';
  }

  return 'active';
}

function compute_member_status_text(array $u): string {
  $k = compute_member_status_key($u);

  return match ($k) {
    'active' => 'Aktif',
    'passive' => 'Pasif',
    'renew' => 'Yenileme',
    'frozen' => 'Donduruldu',
    default => 'Bilinmiyor',
  };
}

$form_error = '';
$form_success = '';

// =========================
// POST: Üye Ekle (Modal)
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_member') {

  // CSRF doğrulaması
  csrf_verify();

  $ad     = trim((string)($_POST['ad'] ?? ''));
  $soyad  = trim((string)($_POST['soyad'] ?? ''));
  $eposta = trim((string)($_POST['eposta_adresi'] ?? ''));
  $tel    = trim((string)($_POST['tel_no'] ?? ''));
  $uyelik = trim((string)($_POST['uyelik_numarasi'] ?? ''));
  $sifre  = (string)($_POST['sifre'] ?? '');

  if ($ad === '' || $soyad === '' || $eposta === '' || $sifre === '') {
    $form_error = "Lütfen Ad, Soyad, E-posta ve Şifre alanlarını doldurun.";
  } elseif (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
    $form_error = "E-posta adresi geçersiz.";
  } else {

    $tel_db    = ($tel === '') ? null : $tel;
    $uyelik_db = ($uyelik === '') ? null : $uyelik;

    $hash = password_hash($sifre, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("
      INSERT INTO uye_kullanicilar
        (ad, soyad, eposta_adresi, sifre, tel_no, uyelik_numarasi, yetki, uye_aktif, odeme_alindi, egitmen_id)
      VALUES
        (?, ?, ?, ?, ?, ?, 'kullanici', 0, 0, NULL)
    ");

    if (!$stmt) {
      $form_error = "Sorgu hazırlanamadı.";
    } else {
      $stmt->bind_param("ssssss", $ad, $soyad, $eposta, $hash, $tel_db, $uyelik_db);

      if (!$stmt->execute()) {
        $err = $stmt->error;

        if (stripos($err, 'eposta_adresi') !== false || stripos($err, 'uniq_eposta') !== false) {
          $form_error = "Bu e-posta zaten kayıtlı.";
        } elseif (stripos($err, 'uyelik_numarasi') !== false || stripos($err, 'uniq_uyelik') !== false) {
          $form_error = "Bu üyelik numarası zaten kayıtlı.";
        } else {
          $form_error = "Kayıt sırasında hata oluştu: " . $err;
        }
      } else {
        $form_success = "Üye başarıyla eklendi (pasif durumda).";
      }
      $stmt->close();
    }
  }
}

// =========================
// Dashboard verileri
// =========================
$toplam_uye = 0;
$yenileme_sayisi = 0;
$pasif_sayisi = 0;
$dondurulan_sayisi = 0;
$aktif_sayisi = 0;

$son_uyeler = [];
$yenilemesi_gelenler = [];
$pasif_uyeler = [];

// Tüm üyeleri tek seferde çek ve durumları PHP tarafında aynı mantıkla hesapla
$uyeler = [];
$stmt = $conn->prepare("
  SELECT
    id,
    ad,
    soyad,
    tel_no,
    eposta_adresi,
    kayit_tarihi,
    bitis_tarihi,
    uye_aktif,
    odeme_alindi,
    abonelik_tipi,
    abonelik_durum,
    paket_kalan_seans,
    donduruldu
  FROM uye_kullanicilar
  WHERE yetki='kullanici'
  ORDER BY kayit_tarihi DESC
");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $r['_status_key'] = compute_member_status_key($r);
  $r['_status_text'] = compute_member_status_text($r);
  $uyeler[] = $r;
}
$stmt->close();

$toplam_uye = count($uyeler);

// sayaçlar
foreach ($uyeler as $u) {
  switch ($u['_status_key']) {
    case 'active':
      $aktif_sayisi++;
      break;
    case 'passive':
      $pasif_sayisi++;
      $pasif_uyeler[] = $u;
      break;
    case 'renew':
      $yenileme_sayisi++;
      $yenilemesi_gelenler[] = $u;
      break;
    case 'frozen':
      $dondurulan_sayisi++;
      break;
  }
}

// son eklenen üyeler
$son_uyeler = array_slice($uyeler, 0, 8);

// listeleri sınırla
$yenilemesi_gelenler = array_slice($yenilemesi_gelenler, 0, 12);
$pasif_uyeler = array_slice($pasif_uyeler, 0, 12);
?>

<style>
  body.page-admin-dashboard{
    background: var(--app-bg, #f6f7fb);
    color: var(--app-text, #111827);
  }

  body.page-admin-dashboard .dash-wrap{
    max-width: 1180px;
    margin: 0 auto;
  }

  body.page-admin-dashboard{
    --dash-card: #ffffff;
    --dash-border: rgba(17,24,39,.10);
    --dash-muted: rgba(17,24,39,.60);
    --dash-soft: rgba(17,24,39,.04);
    --dash-soft2: rgba(17,24,39,.06);
    --dash-shadow: 0 10px 30px rgba(17,24,39,.08);
    --dash-radius: 18px;
  }

  body.page-admin-dashboard .app-card{
    background: var(--dash-card);
    border: 1px solid var(--dash-border);
    border-radius: var(--dash-radius);
    box-shadow: var(--dash-shadow);
  }

  body.page-admin-dashboard .app-muted{
    color: var(--dash-muted) !important;
  }

  body.page-admin-dashboard h1{
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  body.page-admin-dashboard .app-chip{
    display:inline-flex; align-items:center; gap:8px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid var(--dash-border);
    background: var(--dash-soft);
    color: rgba(17,24,39,.85);
    font-size: .86rem;
    font-weight: 800;
    white-space: nowrap;
  }

  body.page-admin-dashboard .action-card{
    cursor:pointer;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
  }
  body.page-admin-dashboard .action-card:hover{
    transform: translateY(-2px);
    box-shadow: 0 14px 35px rgba(17,24,39,.12);
    border-color: rgba(17,24,39,.16);
    background: #fff;
  }

  body.page-admin-dashboard .action-icon{
    width:48px; height:48px;
    display:flex; align-items:center; justify-content:center;
    border-radius: 14px;
    border: 1px solid var(--dash-border);
    background: var(--dash-soft);
    color: rgba(17,24,39,.90);
    flex: 0 0 auto;
  }

  body.page-admin-dashboard .app-table{
    background: #fff;
    border: 1px solid var(--dash-border);
    border-radius: 16px;
    padding: 12px;
    overflow:auto;
    -webkit-overflow-scrolling: touch;
  }

  body.page-admin-dashboard .app-table .table{
    margin:0;
    color: rgba(17,24,39,.90);
  }

  body.page-admin-dashboard .app-table .table thead th{
    position: sticky; top:0; z-index:2;
    background: #f3f4f6;
    color: rgba(17,24,39,.85);
    border-color: rgba(17,24,39,.10);
    font-weight: 800;
  }

  body.page-admin-dashboard .app-table .table td,
  body.page-admin-dashboard .app-table .table th{
    border-color: rgba(17,24,39,.10);
    vertical-align: middle;
  }

  body.page-admin-dashboard .app-table .table tbody tr:hover{
    background: rgba(17,24,39,.03);
  }

  body.page-admin-dashboard .badge-status{
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

  body.page-admin-dashboard .badge-status .dot{
    width:10px; height:10px; border-radius:999px;
    background:#9ca3af;
  }

  body.page-admin-dashboard .badge-status.active{
    border-color: rgba(16,185,129,0.35);
    background: rgba(16,185,129,0.10);
    color: rgba(6,95,70,1);
  }
  body.page-admin-dashboard .badge-status.active .dot{ background: rgba(16,185,129,1); }

  body.page-admin-dashboard .badge-status.passive{
    border-color: rgba(239,68,68,0.35);
    background: rgba(239,68,68,0.10);
    color: rgba(127,29,29,1);
  }
  body.page-admin-dashboard .badge-status.passive .dot{ background: rgba(239,68,68,1); }

  body.page-admin-dashboard .badge-status.renew{
    border-color: rgba(245,158,11,0.45);
    background: rgba(245,158,11,0.14);
    color: rgba(146,64,14,1);
  }
  body.page-admin-dashboard .badge-status.renew .dot{ background: rgba(245,158,11,1); }

  body.page-admin-dashboard .badge-status.frozen{
    border-color: rgba(59,130,246,0.40);
    background: rgba(59,130,246,0.10);
    color: rgba(30,58,138,1);
  }
  body.page-admin-dashboard .badge-status.frozen .dot{ background: rgba(59,130,246,1); }

  body.page-admin-dashboard .modal-content{
    border-radius: 18px;
    border: 1px solid rgba(17,24,39,.12);
    box-shadow: 0 18px 50px rgba(17,24,39,.18);
  }
  body.page-admin-dashboard .modal-header{
    border-bottom: 1px solid rgba(17,24,39,.10);
  }
  body.page-admin-dashboard .modal-footer{
    border-top: 1px solid rgba(17,24,39,.10);
  }

  @media (max-width: 576px){
    body.page-admin-dashboard .app-chip{ width: 100%; justify-content: center; }
    body.page-admin-dashboard .action-icon{ width:44px; height:44px; }
    body.page-admin-dashboard h1{ font-size: 1.35rem; }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('page-admin-dashboard');

    <?php if ($form_error !== ''): ?>
      var m = new bootstrap.Modal(document.getElementById('uyeEkleModal'));
      m.show();
    <?php endif; ?>
  });
</script>

<div class="dash-wrap">

  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
    <div>
      <h1 class="mb-1">Admin Dashboard</h1>
      <div class="app-muted small">
        <?= h(($user['ad'] ?? '') . ' ' . ($user['soyad'] ?? '')) ?> • Admin
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
      <span class="app-chip"><i class="fa-solid fa-layer-group"></i> Toplam Üye: <?= (int)$toplam_uye ?></span>
      <span class="app-chip"><i class="fa-solid fa-user-check"></i> Aktif: <?= (int)$aktif_sayisi ?></span>
      <span class="app-chip"><i class="fa-solid fa-rotate"></i> Yenilemesi Gelen: <?= (int)$yenileme_sayisi ?></span>
      <span class="app-chip"><i class="fa-solid fa-user-slash"></i> Pasif: <?= (int)$pasif_sayisi ?></span>
      <span class="app-chip"><i class="fa-solid fa-snowflake"></i> Dondurulan: <?= (int)$dondurulan_sayisi ?></span>
    </div>
  </div>

  <?php if ($form_success): ?>
    <div class="alert alert-success app-card p-3 mb-3"><?= h($form_success) ?></div>
  <?php endif; ?>

  <?php if ($form_error): ?>
    <div class="alert alert-danger app-card p-3 mb-3"><?= h($form_error) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <a href="#" class="text-decoration-none text-reset" data-bs-toggle="modal" data-bs-target="#uyeEkleModal">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-user-plus"></i></div>
            <div>
              <div class="fw-bold">Üye Ekle</div>
              <div class="app-muted small">Panelden yeni üye oluştur</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a href="/egzersiz-ekle.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-dumbbell"></i></div>
            <div>
              <div class="fw-bold">Egzersiz Ekle</div>
              <div class="app-muted small">Egzersiz havuzunu yönet</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a href="/besin-ekle.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-bowl-food"></i></div>
            <div>
              <div class="fw-bold">Besin Ekle</div>
              <div class="app-muted small">Besin veritabanını yönet</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a href="/salon-takvim.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-regular fa-calendar"></i></div>
            <div>
              <div class="fw-bold">Salon Takvimi</div>
              <div class="app-muted small">Eğitmen seanslarını gör</div>
            </div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="app-card p-3 p-lg-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="fw-bold">Son Eklenen Üyeler</div>
      <div class="app-muted small"><?= !empty($son_uyeler) ? 'Son 8 kayıt' : 'Kayıt yok' ?></div>
    </div>

    <?php if (empty($son_uyeler)): ?>
      <div class="app-muted">Henüz üye eklenmemiş.</div>
    <?php else: ?>
      <div class="app-table">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th style="min-width:220px;">Ad Soyad</th>
              <th style="min-width:170px;">Telefon</th>
              <th style="min-width:240px;">E-posta</th>
              <th style="min-width:170px;">Kayıt</th>
              <th style="min-width:140px;">Durum</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($son_uyeler as $u): ?>
              <tr>
                <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                <td><?= h($u['tel_no'] ?? '-') ?></td>
                <td><?= h($u['eposta_adresi'] ?? '-') ?></td>
                <td><?= h($u['kayit_tarihi'] ?? '-') ?></td>
                <td>
                  <span class="badge-status <?= h($u['_status_key']) ?>">
                    <span class="dot"></span> <?= h($u['_status_text']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="app-card p-3 p-lg-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="fw-bold">Yenilemesi Gelen Üyeler</div>
      <div class="app-muted small">Süresi/paketi bitmiş üyeler</div>
    </div>

    <?php if (empty($yenilemesi_gelenler)): ?>
      <div class="app-muted">Şu an yenilemesi gelen üye yok.</div>
    <?php else: ?>
      <div class="app-table">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th style="min-width:220px;">Ad Soyad</th>
              <th style="min-width:170px;">Telefon</th>
              <th style="min-width:240px;">E-posta</th>
              <th style="min-width:160px;">Bitiş Tarihi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($yenilemesi_gelenler as $u): ?>
              <tr>
                <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                <td><?= h($u['tel_no'] ?? '-') ?></td>
                <td><?= h($u['eposta_adresi'] ?? '-') ?></td>
                <td><span class="badge text-bg-warning"><?= h($u['bitis_tarihi'] ?? '-') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="app-card p-3 p-lg-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="fw-bold">Pasif Üyeler</div>
      <div class="app-muted small">Ödeme alınmamış üyeler</div>
    </div>

    <?php if (empty($pasif_uyeler)): ?>
      <div class="app-muted">Şu an pasif üye yok.</div>
    <?php else: ?>
      <div class="app-table">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th style="min-width:220px;">Ad Soyad</th>
              <th style="min-width:170px;">Telefon</th>
              <th style="min-width:240px;">E-posta</th>
              <th style="min-width:170px;">Kayıt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pasif_uyeler as $u): ?>
              <tr>
                <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                <td><?= h($u['tel_no'] ?? '-') ?></td>
                <td><?= h($u['eposta_adresi'] ?? '-') ?></td>
                <td><?= h($u['kayit_tarihi'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<div class="modal fade" id="uyeEkleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="add_member">
        <?= csrf_field() ?>

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Üye Ekle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Ad *</label>
              <input type="text" name="ad" class="form-control" required value="<?= h($_POST['ad'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Soyad *</label>
              <input type="text" name="soyad" class="form-control" required value="<?= h($_POST['soyad'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">E-posta *</label>
              <input type="email" name="eposta_adresi" class="form-control" required value="<?= h($_POST['eposta_adresi'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Telefon</label>
              <input type="text" name="tel_no" class="form-control" value="<?= h($_POST['tel_no'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Üyelik Numarası</label>
              <input type="text" name="uyelik_numarasi" class="form-control" value="<?= h($_POST['uyelik_numarasi'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Şifre *</label>
              <input type="password" name="sifre" class="form-control" required>
              <div class="form-text">Üye daha sonra profilinden şifreyi değiştirebilir.</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i> Kaydet</button>
        </div>

      </form>
    </div>
  </div>
</div>

<?php include "inc/footer.php"; ?>