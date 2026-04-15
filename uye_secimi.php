<?php
include "inc/header.php"; // senin sistemde yetki + session kontrolü burada

if (!isset($conn)) { require_once 'db.php'; }

$user = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';
$mevcut_egitmen_id = (int)($user['id'] ?? 0);

// Eğitmen listesi (admin dropdown için)
$egitmenler = [];
$egq = $conn->query("SELECT id, ad, soyad FROM uye_kullanicilar WHERE yetki='egitmen' ORDER BY ad, soyad");
if ($egq) {
  while ($r = $egq->fetch_assoc()) $egitmenler[] = $r;
}

// Üye listesi (admin tüm üyeler, eğitmen sadece kendine atanmışlar)
$uyeler = [];
if ($yetki === 'admin') {
  // sifre sütununu hariç tut (HTML'de açığa çıkmaması için)
  $sql = "
    SELECT u.id, u.ad, u.soyad, u.eposta_adresi, u.tel_no, u.uyelik_numarasi,
           u.kayit_tarihi, u.bitis_tarihi, u.baslangic_tarihi,
           u.uye_aktif, u.odeme_alindi, u.abonelik_tipi, u.abonelik_durum,
           u.abonelik_suresi_ay, u.paket_toplam_seans, u.paket_kalan_seans,
           u.donduruldu, u.dondurma_baslangic, u.dondurma_bitis, u.dondurma_notu,
           u.egitmen_id, u.kilo_kg, u.boy_cm, u.yag_orani,
           u.boyun_cevresi, u.bel_cevresi, u.basen_cevresi,
           CONCAT(COALESCE(e.ad,''), ' ', COALESCE(e.soyad,'')) AS egitmen_adi
    FROM uye_kullanicilar u
    LEFT JOIN uye_kullanicilar e ON e.id = u.egitmen_id
    WHERE u.yetki='kullanici'
    ORDER BY u.ad, u.soyad
  ";
  $rq = $conn->query($sql);
} elseif ($yetki === 'egitmen') {
  $stmt = $conn->prepare("
    SELECT u.id, u.ad, u.soyad, u.eposta_adresi, u.tel_no, u.uyelik_numarasi,
           u.kayit_tarihi, u.bitis_tarihi, u.baslangic_tarihi,
           u.uye_aktif, u.odeme_alindi, u.abonelik_tipi, u.abonelik_durum,
           u.abonelik_suresi_ay, u.paket_toplam_seans, u.paket_kalan_seans,
           u.donduruldu, u.dondurma_baslangic, u.dondurma_bitis, u.dondurma_notu,
           u.egitmen_id, u.kilo_kg, u.boy_cm, u.yag_orani,
           u.boyun_cevresi, u.bel_cevresi, u.basen_cevresi,
           CONCAT(COALESCE(e.ad,''), ' ', COALESCE(e.soyad,'')) AS egitmen_adi
    FROM uye_kullanicilar u
    LEFT JOIN uye_kullanicilar e ON e.id = u.egitmen_id
    WHERE u.yetki='kullanici' AND u.egitmen_id = ?
    ORDER BY u.ad, u.soyad
  ");
  $stmt->bind_param("i", $mevcut_egitmen_id);
  $stmt->execute();
  $rq = $stmt->get_result();
} else {
  $rq = false;
}

if ($rq) {
  while ($row = $rq->fetch_assoc()) $uyeler[] = $row;
  if (isset($stmt)) $stmt->close();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<style>
  /* =========================================================
     ÜYE SEÇİMİ (LIGHT) - SADECE BU SAYFAYA SCOPE
     ========================================================= */
  body.page-uye-secimi{
    background: var(--app-bg, #f6f7fb);
    color: var(--app-text, #111827);
  }

  body.page-uye-secimi .uye-wrap{
    max-width: 1180px;
    margin: 0 auto;
  }

  body.page-uye-secimi{
    --u-card: #ffffff;
    --u-border: rgba(17,24,39,.10);
    --u-muted: rgba(17,24,39,.60);
    --u-soft: rgba(17,24,39,.04);
    --u-shadow: 0 10px 30px rgba(17,24,39,.08);
    --u-radius: 18px;
    --u-focus: rgba(13,110,253,.18);
    --u-focus-border: rgba(13,110,253,.45);
  }

  body.page-uye-secimi h1{
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  body.page-uye-secimi .app-card{
    background: var(--u-card);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    box-shadow: var(--u-shadow);
  }

  body.page-uye-secimi .app-muted{
    color: var(--u-muted) !important;
  }

  .badge-soft{
    border: 1px solid var(--u-border);
    background: var(--u-soft);
    color: rgba(17,24,39,.85);
    font-weight: 800;
  }

  /* status badge variants */
  .badge-status{
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
  .badge-status .dot{
    width:10px; height:10px; border-radius:999px;
    background:#9ca3af;
  }
  .badge-status.active{
    border-color: rgba(16,185,129,0.35);
    background: rgba(16,185,129,0.10);
    color: rgba(6,95,70,1);
  }
  .badge-status.active .dot{ background: rgba(16,185,129,1); }

  .badge-status.passive{
    border-color: rgba(239,68,68,0.35);
    background: rgba(239,68,68,0.10);
    color: rgba(127,29,29,1);
  }
  .badge-status.passive .dot{ background: rgba(239,68,68,1); }

  .badge-status.renew{
    border-color: rgba(245,158,11,0.45);
    background: rgba(245,158,11,0.14);
    color: rgba(146,64,14,1);
  }
  .badge-status.renew .dot{ background: rgba(245,158,11,1); }

  .badge-status.frozen{
    border-color: rgba(59,130,246,0.40);
    background: rgba(59,130,246,0.10);
    color: rgba(30,58,138,1);
  }
  .badge-status.frozen .dot{ background: rgba(59,130,246,1); }

  body.page-uye-secimi .app-input{
    background: #fff !important;
    border: 1px solid var(--u-border) !important;
    color: rgba(17,24,39,.92) !important;
    border-radius: 12px !important;
  }
  body.page-uye-secimi .app-input::placeholder{
    color: rgba(17,24,39,.45) !important;
  }
  body.page-uye-secimi .app-input:focus{
    box-shadow: 0 0 0 0.25rem var(--u-focus) !important;
    border-color: var(--u-focus-border) !important;
  }

  body.page-uye-secimi .app-table{
    background: #fff;
    border: 1px solid var(--u-border);
    border-radius: 16px;
    padding: 12px;
    overflow:auto;
    -webkit-overflow-scrolling: touch;
  }

  body.page-uye-secimi .app-table .table{
    margin:0;
    color: rgba(17,24,39,.90);
  }
  body.page-uye-secimi .app-table .table td,
  body.page-uye-secimi .app-table .table th{
    border-color: rgba(17,24,39,.10);
    vertical-align: middle;
  }

  body.page-uye-secimi .app-table thead th{
    position: sticky; top:0; z-index:2;
    background: #f3f4f6;
    color: rgba(17,24,39,.85);
    border-color: rgba(17,24,39,.10);
    font-weight: 800;
  }

  body.page-uye-secimi .row-select{ cursor:pointer; }
  body.page-uye-secimi .row-select:hover td{ background: rgba(17,24,39,.03); }

  body.page-uye-secimi .table-active td{
    background: rgba(13,110,253,.08) !important;
  }

  body.page-uye-secimi .light-hr{
    border-color: rgba(17,24,39,.10) !important;
  }

  body.page-uye-secimi .app-input[readonly]{
    background: #f9fafb !important;
    cursor: not-allowed;
  }

  @media (max-width: 576px){
    body.page-uye-secimi h1{ font-size: 1.35rem; }
    body.page-uye-secimi #searchBox{ max-width: 100% !important; }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('page-uye-secimi');
  });
</script>

<div class="uye-wrap">
  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
    <div>
      <h1 class="mb-1"><?= ($yetki === 'admin') ? 'Üyeler' : 'Üyelerim' ?></h1>
      <div class="app-muted small">
        <?= ($yetki === 'admin') ? 'Üyeleri görüntüle, eğitmen ata ve abonelik bilgilerini yönet.' : 'Sadece sana atanmış üyeleri görüntülüyorsun.' ?>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
      <span class="badge rounded-pill badge-soft"><?= count($uyeler) ?> üye</span>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="app-card p-3 p-lg-4">
        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between mb-3">
          <div class="fw-bold">Üye Listesi</div>
          <input id="searchBox" class="form-control app-input" style="max-width:320px;"
                 type="text" placeholder="Yazarak üye ara">
        </div>

        <div class="app-table">
          <table class="table table-bordered align-middle" id="uyeTable">
            <thead>
              <tr>
                <th style="min-width:220px;">Ad Soyad</th>
                <?php if ($yetki === 'admin'): ?>
                  <th style="min-width:220px;">Atanan Eğitmen</th>
                <?php endif; ?>
                <th style="min-width:180px;">Program Geçmişi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($uyeler as $u): ?>
                <tr class="row-select" data-uye='<?= h(json_encode($u, JSON_UNESCAPED_UNICODE)) ?>'>
                  <td><?= h(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? '')) ?></td>
                  <?php if ($yetki === 'admin'): ?>
                    <td><?= h($u['egitmen_adi'] ?? '—') ?></td>
                  <?php endif; ?>
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
                <tr><td colspan="<?= ($yetki === 'admin') ? 3 : 2 ?>" class="text-center app-muted">Üye yok.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="app-muted small mt-3">
          İpucu: Satıra tıklayınca sağ tarafta üye detayları açılır.
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="app-card p-3 p-lg-4" id="detailCard">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="fw-bold">Üye Detayı</div>
          <span class="badge rounded-pill badge-soft" id="detailBadge">Seçilmedi</span>
        </div>
        <div class="app-muted small mb-3">Listeden bir üye seç.</div>

        <div id="detailBody" class="d-none">
          <div class="row g-2">
            <div class="col-6">
              <div class="app-muted small">Ad Soyad</div>
              <div class="fw-bold" id="d_name"></div>
            </div>

            <div class="col-6">
              <div class="app-muted small">Telefon</div>
              <div id="d_tel"></div>
            </div>

            <div class="col-12">
              <div class="app-muted small">E-posta</div>
              <div id="d_mail"></div>
            </div>

            <div class="col-12 mt-1">
              <div class="app-muted small mb-1">Üyelik Durumu</div>
              <div id="statusWrap"></div>
              <div class="app-muted small mt-2" id="statusHint"></div>
            </div>

            <div class="col-4">
              <div class="app-muted small">Kilo</div>
              <div id="d_kilo"></div>
            </div>
            <div class="col-4">
              <div class="app-muted small">Boy</div>
              <div id="d_boy"></div>
            </div>
            <div class="col-4">
              <div class="app-muted small">Yağ Oranı</div>
              <div id="d_yag"></div>
            </div>
            <div class="col-4">
              <div class="app-muted small">Boyun</div>
              <div id="d_boyun"></div>
            </div>
            <div class="col-4">
              <div class="app-muted small">Bel</div>
              <div id="d_bel"></div>
            </div>
            <div class="col-4">
              <div class="app-muted small">Basen</div>
              <div id="d_basen"></div>
            </div>
          </div>

          <?php if ($yetki === 'admin'): ?>
            <hr class="my-4 light-hr">

            <div class="fw-bold mb-2">Eğitmen Atama</div>
            <div class="app-muted small mb-2">Seçili üyeye eğitmen ata / kaldır.</div>

            <input type="hidden" id="selectedUyeId" value="">

            <select id="egitmenSelect" class="form-control app-input">
              <option value="0">— Eğitmen seç (kaldırmak için boş bırak) —</option>
              <?php foreach ($egitmenler as $e): ?>
                <option value="<?= (int)$e['id'] ?>"><?= h($e['ad'] . ' ' . $e['soyad']) ?></option>
              <?php endforeach; ?>
            </select>

            <hr class="my-4 light-hr">

            <div class="fw-bold mb-2">Abonelik Bilgileri</div>
            <div class="app-muted small mb-2">Abonelik tipi, dönem ve ödeme durumunu yönet.</div>

            <div class="row g-2">
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

            <div class="fw-bold mb-2">Üyelik Dondurma</div>
            <div class="app-muted small mb-2">Dondurma süresince abonelik süresi durur. Çözünce bitiş tarihi otomatik uzar.</div>

            <div class="row g-2">
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

            <button id="btnSaveAll" class="btn btn-primary mt-3 w-100" type="button">
              Kaydet
            </button>

            <div id="saveMsg" class="app-muted small mt-2"></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const searchBox = document.getElementById('searchBox');
  const table = document.getElementById('uyeTable');
  const rows = Array.from(table.querySelectorAll('tbody tr'));

  searchBox.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    rows.forEach(r => {
      const text = r.innerText.toLowerCase();
      r.style.display = text.includes(q) ? '' : 'none';
    });
  });

  const detailBody = document.getElementById('detailBody');
  const detailBadge = document.getElementById('detailBadge');

  function setText(id, val){
    const el = document.getElementById(id);
    if (el) el.textContent = (val ?? '') === '' ? '—' : val;
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
    d.setMonth(d.getMonth() + months);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function addDays(dateStr, days){
    if(!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + (parseInt(days,10)||0));
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

    // ders paketi: kalan hak 0 ise yenileme
    if (tip === 'ders_paketi') {
      const kalan = parseInt(data.paket_kalan_seans ?? '0', 10);
      const bitis = normalizeDate(data.bitis_tarihi ?? '');
      const t = todayYMD();
      if ((kalan <= 0) || (bitis && t > bitis) || (String(data.abonelik_durum||'') === 'yenileme')) {
        return { key:'renew', text:'Yenileme', hint:'Paket hakkı bitmiş veya dönem sona ermiş. Yenileme bekleniyor.' };
      }
      return { key:'active', text:'Aktif', hint:'Paket aktif.' };
    }

    // aylık
    const bitis = normalizeDate(data.bitis_tarihi ?? '');
    if (!bitis) {
      return { key:'active', text:'Aktif', hint:'Ödeme alındı. Bitiş tarihi tanımlı değil.' };
    }
    const t = todayYMD();
    if (t > bitis) {
      return { key:'renew', text:'Yenileme', hint:'Abonelik bitmiş. Yenileme bekleniyor (admin manuel aktif eder).' };
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

  <?php if ($yetki === 'admin'): ?>
    const selectedUyeIdEl = document.getElementById('selectedUyeId');
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

    function refreshBitis(){
      const end = calcEndDate(baslangicInput.value, abonelikSelect.value);
      bitisInput.value = end;
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
        if (baslangicInput.value) bitisInput.value = addDays(baslangicInput.value, 30);
      } else {
        aylikBox.classList.remove('d-none');
        paketBox.classList.add('d-none');
        refreshBitis();
      }
    }

    abonelikTipSelect.addEventListener('change', refreshAbonelikUI);
    abonelikSelect.addEventListener('change', refreshAbonelikUI);
    baslangicInput.addEventListener('change', refreshAbonelikUI);

    paketSeansInput.addEventListener('input', function(){
      const v = parseInt(paketSeansInput.value||'0',10);
      paketInfoBadge.style.display = v>0 ? 'inline-block' : 'none';
      paketInfoBadge.textContent = v>0 ? `Paket: ${v} seans/30 gün` : 'Paket: —';
    });

    odemeCheck.addEventListener('change', refreshAktifBadge);
    dondurCheck.addEventListener('change', refreshDondurBadge);
  <?php endif; ?>

  rows.forEach(r => {
    r.addEventListener('click', () => {
      rows.forEach(x => x.classList.remove('table-active'));
      r.classList.add('table-active');

      const data = JSON.parse(r.getAttribute('data-uye') || '{}');

      detailBody.classList.remove('d-none');
      detailBadge.textContent = 'Seçildi';

      setText('d_name', (data.ad || '') + ' ' + (data.soyad || ''));
      setText('d_tel', data.tel_no || '—');
      setText('d_mail', data.eposta_adresi || '—');

      const status = computeStatus(data);
      document.getElementById('statusWrap').innerHTML = renderStatusPill(status);
      document.getElementById('statusHint').textContent = status.hint;

      setText('d_kilo', data.kilo_kg ? (data.kilo_kg + ' kg') : '—');
      setText('d_boy', data.boy_cm ? (data.boy_cm + ' cm') : '—');
      setText('d_yag', data.yag_orani ? (data.yag_orani + ' %') : '—');
      setText('d_boyun', data.boyun_cevresi ? (data.boyun_cevresi + ' cm') : '—');
      setText('d_bel', data.bel_cevresi ? (data.bel_cevresi + ' cm') : '—');
      setText('d_basen', data.basen_cevresi ? (data.basen_cevresi + ' cm') : '—');

      <?php if ($yetki === 'admin'): ?>
        selectedUyeIdEl.value = data.id || '';

        egitmenSelect.value = String(data.egitmen_id || 0);

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

        // otomatik hesap
        refreshAbonelikUI();
        if (!bitisInput.value && baslangicInput.value) refreshAbonelikUI();

        dondurCheck.checked = String(data.donduruldu ?? '0') === '1';
        dondurBas.value = normalizeDate(data.dondurma_baslangic ?? '');
        dondurBit.value = normalizeDate(data.dondurma_bitis ?? '');
        dondurNot.value = (data.dondurma_notu ?? '') || '';
        refreshDondurBadge();

        document.getElementById('saveMsg').textContent = '';
      <?php endif; ?>
    });
  });

  <?php if ($yetki === 'admin'): ?>
  document.getElementById('btnSaveAll').addEventListener('click', async function(){
    const uyeId = selectedUyeIdEl.value;
    if (!uyeId) {
      alert('Lütfen önce listeden bir üye seçin.');
      return;
    }

    // ✅ Validasyon (yeni kurallar)
    if (!baslangicInput.value) { alert('Başlangıç tarihi zorunludur.'); return; }

    const tip = abonelikTipSelect.value || 'aylik';
    if (tip === 'aylik') {
      if (!abonelikSelect.value) { alert('Aylık abonelikte süre (ay) seçmelisin.'); return; }
    } else {
      const p = parseInt(paketSeansInput.value||'0',10);
      if (!p || p <= 0) { alert('Ders paketi için aylık seans hakkı zorunludur.'); return; }
    }

    const payload = new FormData();
    payload.append('uye_id', uyeId);

    payload.append('egitmen_id', egitmenSelect.value);

    // ✅ yeni alanlar
    payload.append('abonelik_tipi', tip);
    payload.append('abonelik_suresi_ay', tip === 'aylik' ? abonelikSelect.value : '0');
    payload.append('paket_toplam_seans', tip === 'ders_paketi' ? (paketSeansInput.value || '0') : '0');

    payload.append('baslangic_tarihi', baslangicInput.value);
    payload.append('odeme_alindi', odemeCheck.checked ? '1' : '0');
    payload.append('bitis_tarihi', bitisInput.value);

    payload.append('donduruldu', dondurCheck.checked ? '1' : '0');
    payload.append('dondurma_baslangic', dondurBas.value);
    payload.append('dondurma_bitis', dondurBit.value);
    payload.append('dondurma_notu', dondurNot.value);

    this.disabled = true;
    document.getElementById('saveMsg').textContent = 'Kaydediliyor...';

    try{
      payload.append('csrf_token', <?= json_encode(csrf_token()) ?>);
      const res = await fetch('uye_abonelik_kaydet.php', { method:'POST', body: payload });
      const json = await res.json();

      if (json.ok) {
        document.getElementById('saveMsg').textContent = '✅ ' + (json.message || 'Kaydedildi.');
        setTimeout(() => location.reload(), 500);
      } else {
        document.getElementById('saveMsg').textContent = '❌ ' + (json.message || 'Hata');
      }
    } catch(e){
      document.getElementById('saveMsg').textContent = '❌ Bağlantı hatası.';
    } finally {
      this.disabled = false;
    }
  });
  <?php endif; ?>
</script>

<?php include "inc/footer.php"; ?>
