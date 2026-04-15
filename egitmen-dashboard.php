<?php
include "inc/header.php"; // session + yetki kontrol + tema

$user  = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';

if (!in_array($yetki, ['egitmen', 'admin'], true)) {
    echo "<div class='p-4 app-card'><h4>⛔ Yetkisiz</h4><div class='app-muted'>Bu sayfa sadece eğitmen ve admin içindir.</div></div>";
    include "inc/footer.php";
    exit;
}

$egitmen_id = (int)($user['id'] ?? 0);

// Eğitmene atanmış üye sayısı (admin ise toplamı da görebilsin diye iki metrik verdim)
$uye_sayisi = 0;
$toplam_uye_sayisi = 0;

// Eğitmene atanmış son 5 üye
$son_uyeler = [];

if ($egitmen_id > 0 && isset($conn)) {

    // Eğitmen kendi üyeleri
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM uye_kullanicilar WHERE yetki='kullanici' AND egitmen_id = ?");
    $stmt->bind_param("i", $egitmen_id);
    $stmt->execute();
    $uye_sayisi = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // Son 5 üye
    $stmt = $conn->prepare("
        SELECT id, uyelik_numarasi, ad, soyad, tel_no, eposta_adresi
        FROM uye_kullanicilar
        WHERE yetki='kullanici' AND egitmen_id = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $egitmen_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $son_uyeler[] = $r;
    $stmt->close();

    // Admin ise toplam üye sayısını da göster
    if ($yetki === 'admin') {
        $q = $conn->query("SELECT COUNT(*) AS c FROM uye_kullanicilar WHERE yetki='kullanici'");
        $toplam_uye_sayisi = (int)($q->fetch_assoc()['c'] ?? 0);
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<style>
  /* =========================================================
     EĞİTMEN DASHBOARD (LIGHT) - SADECE BU SAYFAYA SCOPE
     ========================================================= */
  body.page-egitmen-dashboard{
    background: var(--app-bg, #f6f7fb);
    color: var(--app-text, #111827);
  }

  body.page-egitmen-dashboard .dash-wrap{
    max-width: 1180px;
    margin: 0 auto;
  }

  /* tokens (tema dosyan yoksa bile düzgün dursun diye fallback verdim) */
  body.page-egitmen-dashboard{
    --dash-card: #ffffff;
    --dash-border: rgba(17,24,39,.10);
    --dash-muted: rgba(17,24,39,.60);
    --dash-soft: rgba(17,24,39,.04);
    --dash-soft2: rgba(17,24,39,.06);
    --dash-shadow: 0 10px 30px rgba(17,24,39,.08);
    --dash-radius: 18px;
  }

  body.page-egitmen-dashboard .app-card{
    background: var(--dash-card);
    border: 1px solid var(--dash-border);
    border-radius: var(--dash-radius);
    box-shadow: var(--dash-shadow);
  }

  body.page-egitmen-dashboard .app-muted{
    color: var(--dash-muted) !important;
  }

  body.page-egitmen-dashboard h1{
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  /* chips */
  body.page-egitmen-dashboard .app-chip{
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

  /* action cards */
  body.page-egitmen-dashboard .action-card{
    cursor:pointer;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
  }
  body.page-egitmen-dashboard .action-card:hover{
    transform: translateY(-2px);
    box-shadow: 0 14px 35px rgba(17,24,39,.12);
    border-color: rgba(17,24,39,.16);
    background: #fff;
  }

  body.page-egitmen-dashboard .action-icon{
    width:48px; height:48px;
    display:flex; align-items:center; justify-content:center;
    border-radius: 14px;
    border: 1px solid var(--dash-border);
    background: var(--dash-soft);
    color: rgba(17,24,39,.90);
    flex: 0 0 auto;
  }

  body.page-egitmen-dashboard a.text-reset:hover .fw-bold{
    text-decoration: none;
  }

  /* table container */
  body.page-egitmen-dashboard .app-table{
    background: #fff;
    border: 1px solid var(--dash-border);
    border-radius: 16px;
    padding: 12px;
    overflow:auto;
    -webkit-overflow-scrolling: touch;
  }

  /* bootstrap table refinements */
  body.page-egitmen-dashboard .app-table .table{
    margin:0;
    color: rgba(17,24,39,.90);
  }

  body.page-egitmen-dashboard .app-table .table thead th{
    position: sticky; top:0; z-index:2;
    background: #f3f4f6;           /* light sticky head */
    color: rgba(17,24,39,.85);
    border-color: rgba(17,24,39,.10);
    font-weight: 800;
  }

  body.page-egitmen-dashboard .app-table .table td,
  body.page-egitmen-dashboard .app-table .table th{
    border-color: rgba(17,24,39,.10);
    vertical-align: middle;
  }

  body.page-egitmen-dashboard .app-table .table tbody tr:hover{
    background: rgba(17,24,39,.03);
  }

  /* mobile spacing */
  @media (max-width: 576px){
    body.page-egitmen-dashboard .app-chip{ width: 100%; justify-content: center; }
    body.page-egitmen-dashboard .action-icon{ width:44px; height:44px; }
    body.page-egitmen-dashboard h1{ font-size: 1.35rem; }
  }
</style>

<!-- Sayfayı scope etmek için BODY class'ı header içinde set edilmiyorsa burada JS ile ekleyelim -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('page-egitmen-dashboard');
  });
</script>

<div class="dash-wrap">
  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
    <div>
      <h1 class="mb-1">Eğitmen Dashboard</h1>
      <div class="app-muted small">
        <?= h(($user['ad'] ?? '') . ' ' . ($user['soyad'] ?? '')) ?> •
        <?= ($yetki === 'admin') ? 'Admin (Eğitmen görünümü)' : 'Eğitmen' ?>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
      <span class="app-chip"><i class="fa-solid fa-users"></i> Üyelerim: <?= (int)$uye_sayisi ?></span>
      <?php if ($yetki === 'admin'): ?>
        <span class="app-chip"><i class="fa-solid fa-layer-group"></i> Toplam Üye: <?= (int)$toplam_uye_sayisi ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Hızlı Aksiyonlar -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <a href="/uye_secimi.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-users"></i></div>
            <div>
              <div class="fw-bold">Üyelerim</div>
              <div class="app-muted small">Atanmış üyeleri görüntüle</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a href="/antrenman-olustur.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-dumbbell"></i></div>
            <div>
              <div class="fw-bold">Antrenman Oluştur</div>
              <div class="app-muted small">Üyeye program tanımla</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a href="/beslenme-olustur.php" class="text-decoration-none text-reset">
        <div class="app-card p-3 p-lg-4 action-card h-100">
          <div class="d-flex gap-3">
            <div class="action-icon"><i class="fa-solid fa-bowl-food"></i></div>
            <div>
              <div class="fw-bold">Beslenme Oluştur</div>
              <div class="app-muted small">Üyeye plan tanımla</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
    <a href="/egitmen-takvim.php" class="text-decoration-none text-reset">    
      <div class="app-card p-3 p-lg-4 h-100">
        <div class="d-flex gap-3">
          <div class="action-icon"><i class="fa-regular fa-calendar"></i></div>
          <div>
            <div class="fw-bold">Seans Takvimi</div>
            <div class="app-muted small">Seans Takvimini Gör</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Son üyeler -->
  <div class="app-card p-3 p-lg-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="fw-bold">Son Atanan Üyeler</div>
      <div class="app-muted small"><?= $uye_sayisi > 0 ? 'Son 5 üye' : 'Henüz üye atanmadı' ?></div>
    </div>

    <?php if (empty($son_uyeler)): ?>
      <div class="app-muted">Bu eğitmene henüz üye atanmadı. Admin panelinden üye ataması yapabilirsin.</div>
    <?php else: ?>
      <div class="app-table">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th style="min-width:220px;">Ad Soyad</th>
              <th style="min-width:170px;">Telefon</th>
              <th style="min-width:240px;">E-posta</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($son_uyeler as $u): ?>
              <tr>
                <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                <td><?= h($u['tel_no'] ?? '-') ?></td>
                <td><?= h($u['eposta_adresi'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include "inc/footer.php"; ?>
