<?php
include "inc/header.php";

$user = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';

if (!in_array($yetki, ['egitmen','admin'], true)) {
  echo "<div class='p-4 app-card'><h4>⛔ Yetkisiz</h4><div class='app-muted'>Bu sayfa sadece eğitmen ve admin içindir.</div></div>";
  include "inc/footer.php";
  exit;
}

$egitmen_id = (int)($user['id'] ?? 0);

// Üye listesi (admin: tüm üyeler, eğitmen: kendi üyeleri)
$uyeler = [];
if ($yetki === 'admin') {
  $q = $conn->query("SELECT id, uyelik_numarasi, ad, soyad FROM uye_kullanicilar WHERE yetki='kullanici' ORDER BY ad, soyad");
  while ($r = $q->fetch_assoc()) $uyeler[] = $r;
} else {
  $stmt = $conn->prepare("SELECT id, uyelik_numarasi, ad, soyad FROM uye_kullanicilar WHERE yetki='kullanici' AND egitmen_id=? ORDER BY ad, soyad");
  $stmt->bind_param("i", $egitmen_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $uyeler[] = $r;
  $stmt->close();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<style>
  .app-card{background:rgba(255,255,255,0.035);border:1px solid rgba(255,255,255,0.10);border-radius:18px;}
  .app-muted{color:rgba(255,255,255,0.65)!important;}
  .app-input{background:rgba(255,255,255,0.04)!important;border:1px solid rgba(255,255,255,0.10)!important;color:rgba(255,255,255,0.92)!important;border-radius:12px!important;}
  .app-input:focus{box-shadow:0 0 0 .25rem rgba(13,110,253,.18)!important;border-color:rgba(13,110,253,.55)!important;}
  .app-table{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:12px;overflow:auto;}
  .app-table table{margin:0;color:rgba(255,255,255,0.88);border-color:rgba(255,255,255,0.08);}
  .app-table thead th{position:sticky;top:0;z-index:2;background:rgba(20,20,20,0.92);color:rgba(255,255,255,0.92);border-color:rgba(255,255,255,0.10);}
  .row-pick{cursor:pointer;}
  .row-pick:hover td{background:rgba(255,255,255,0.03);}
</style>

<div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-4">
  <div>
    <h1 class="mb-1">Seans Oluştur</h1>
    <div class="app-muted small">
      <?= ($yetki==='admin') ? 'Admin görünümü: istediğin üyeye seans oluşturabilirsin.' : 'Eğitmen görünümü: sadece atanmış üyelerde seans oluşturabilirsin.' ?>
    </div>
  </div>

  <div class="d-flex gap-2 align-items-center">
    <a class="btn btn-outline-light" href="/google-connect.php">
      <i class="fa-brands fa-google me-1"></i> Google Takvim Bağla
    </a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="app-card p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">Üyeler</div>
        <input id="searchBox" class="form-control app-input" style="max-width:320px;" placeholder="Yazarak ara (ad/soyad/no)">
      </div>

      <div class="app-table">
        <table class="table table-bordered align-middle" id="uyeTable">
          <thead>
            <tr>
              <th style="min-width:140px;">Üyelik No</th>
              <th style="min-width:240px;">Ad Soyad</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($uyeler as $u): ?>
              <tr class="row-pick" data-id="<?= (int)$u['id'] ?>"
                  data-name="<?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?>"
                  data-no="<?= h($u['uyelik_numarasi'] ?? '') ?>">
                <td><?= h($u['uyelik_numarasi'] ?? '-') ?></td>
                <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="app-muted small mt-3">İpucu: Satıra tıklayıp sağda seans formunu doldur.</div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="app-card p-3 p-lg-4">
      <div class="fw-bold mb-2">Seans Detayları</div>
      <div class="app-muted small mb-3">Önce soldan bir üye seç.</div>

      <form method="POST" action="/seans-kaydet.php" id="seansForm">
        <input type="hidden" name="uye_id" id="uye_id" value="">
        <?= csrf_field() ?>

        <div class="mb-2">
          <label class="app-muted small">Seçili Üye</label>
          <input type="text" class="form-control app-input" id="uye_text" value="—" readonly>
        </div>

        <div class="mb-2">
          <label class="app-muted small">Başlık</label>
          <input type="text" class="form-control app-input" name="baslik" value="Koçluk Seansı" required>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="app-muted small">Tarih</label>
            <input type="date" class="form-control app-input" name="tarih" required>
          </div>
          <div class="col-6">
            <label class="app-muted small">Saat</label>
            <input type="time" class="form-control app-input" name="saat" required>
          </div>
        </div>

        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="app-muted small">Süre (dk)</label>
            <input type="number" class="form-control app-input" name="sure_dk" value="60" min="15" step="15" required>
          </div>
          <div class="col-6">
            <label class="app-muted small">Konum (opsiyonel)</label>
            <input type="text" class="form-control app-input" name="konum" placeholder="Salon / Zoom link / vs">
          </div>
        </div>

        <div class="mt-2">
          <label class="app-muted small">Açıklama (opsiyonel)</label>
          <textarea class="form-control app-input" name="aciklama" rows="3" placeholder="Seans notu..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary mt-3" id="btnSubmit">
          Seansı Oluştur (Google Takvim’e de düşer)
        </button>

        <?php if (!empty($_GET['ok'])): ?>
          <div class="mt-3 alert alert-success">✅ Seans oluşturuldu.</div>
        <?php endif; ?>

        <?php if (!empty($_GET['err'])): ?>
          <div class="mt-3 alert alert-danger">❌ <?= h($_GET['err']) ?></div>
        <?php endif; ?>

        <?php if (!empty($_GET['warn'])): ?>
          <div class="mt-3 alert alert-warning">⚠️ <?= h($_GET['warn']) ?></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<script>
  const rows = Array.from(document.querySelectorAll('.row-pick'));
  const searchBox = document.getElementById('searchBox');

  searchBox.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    rows.forEach(r=>{
      const txt = (r.dataset.no + ' ' + r.dataset.name).toLowerCase();
      r.style.display = txt.includes(q) ? '' : 'none';
    });
  });

  rows.forEach(r=>{
    r.addEventListener('click', ()=>{
      rows.forEach(x=>x.classList.remove('table-active'));
      r.classList.add('table-active');
      document.getElementById('uye_id').value = r.dataset.id;
      document.getElementById('uye_text').value = `${r.dataset.no} - ${r.dataset.name}`;
    });
  });

  document.getElementById('seansForm').addEventListener('submit', function(e){
    if (!document.getElementById('uye_id').value) {
      e.preventDefault();
      alert('Lütfen önce bir üye seç.');
    }
  });
</script>

<?php include "inc/footer.php"; ?>
