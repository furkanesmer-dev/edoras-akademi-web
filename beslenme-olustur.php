<?php
// beslenme-olustur.php (FINAL - single table food model)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- DB include (robust) ---
$__db_loaded = false;
foreach ([__DIR__ . '/inc/db.php', __DIR__ . '/db.php', __DIR__ . '/baglanti.php', __DIR__ . '/config/db.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__db_loaded = true; break; }
}
if (!$__db_loaded) { http_response_code(500); exit('DB bağlantı dosyası bulunamadı.'); }
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (isset($mysqli) && ($mysqli instanceof mysqli)) { $conn = $mysqli; }
}
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('DB bağlantısı ($conn) bulunamadı.'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// --- Current user normalize ---
$user = $_SESSION['user'] ?? $_SESSION['kullanici'] ?? null;
if (!$user) { header("Location: giris.php"); exit; }

$selfUserId = (int)($user['id'] ?? 0);
$role = $user['yetki'] ?? $user['rol'] ?? $_SESSION['yetki'] ?? $_SESSION['rol'] ?? 'kullanici';
$role = in_array($role, ['kullanici','egitmen','admin'], true) ? $role : 'kullanici';

// --- Helper: fetch user by id ---
function fetch_user_by_id(mysqli $conn, int $id): ?array {
  $st = $conn->prepare("SELECT * FROM uye_kullanicilar WHERE id=? LIMIT 1");
  $st->bind_param("i", $id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

// --- Target user selection ---
$targetUserId = $selfUserId;
if ($role === 'admin' || $role === 'egitmen') {
  if (isset($_GET['user_id'])) { $targetUserId = (int)$_GET['user_id']; }
  if (isset($_POST['kullanici'])) { $targetUserId = (int)$_POST['kullanici']; }
}

// --- Authorization for trainer ---
if ($role === 'egitmen' && $targetUserId !== $selfUserId) {
  $chk = $conn->prepare("SELECT 1 FROM uye_kullanicilar WHERE id=? AND egitmen_id=? LIMIT 1");
  $chk->bind_param("ii", $targetUserId, $selfUserId);
  $chk->execute();
  $ok = (bool)$chk->get_result()->fetch_row();
  $chk->close();
  if (!$ok) { http_response_code(403); exit("Yetkisiz erişim."); }
}

// --- Build user list for dropdown (server-side) ---
$usersForSelect = [];
if ($role === 'admin') {
  $res = $conn->query("SELECT id, ad, soyad, uyelik_numarasi, kilo_kg, yag_orani, spor_hedefi FROM uye_kullanicilar ORDER BY ad, soyad");
  while ($row = $res->fetch_assoc()) { $usersForSelect[] = $row; }
} elseif ($role === 'egitmen') {
  $st = $conn->prepare("SELECT id, ad, soyad, uyelik_numarasi, kilo_kg, yag_orani, spor_hedefi FROM uye_kullanicilar WHERE egitmen_id=? ORDER BY ad, soyad");
  $st->bind_param("i", $selfUserId);
  $st->execute();
  $usersForSelect = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

// --- Pull target user's kilo/yag/hedef to prefill ---
$targetUser = ($targetUserId === $selfUserId) ? $user : fetch_user_by_id($conn, $targetUserId);
$targetKilo  = $targetUser['kilo_kg'] ?? ($targetUser['kilo'] ?? '');
$targetYag   = $targetUser['yag_orani'] ?? '';
$targetHedef = $targetUser['spor_hedefi'] ?? '';

$pageCss = "/css/beslenme.css?v=20260415-2";
$justSaved = isset($_GET['saved']) && (int)$_GET['saved'] === 1;

include "inc/header.php";
?>
<div class="container page-wrap">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="m-0">🍽️ Beslenme Programı Oluştur</h3>
    <?php if ($justSaved): ?>
      <div class="alert alert-success m-0 py-2 px-3">✅ Program kaydedildi.</div>
    <?php endif; ?>
  </div>

  <div class="glass-card card-pad mb-4">
    <form method="POST" action="program_kaydet.php" id="programForm">
      <?php if ($role === 'kullanici'): ?>
        <input type="hidden" name="kullanici" value="<?= (int)$selfUserId ?>">
      <?php else: ?>
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Kullanıcı</label>
            <select id="kullanici" name="kullanici" class="form-select" required>
              <option value="">Seçiniz</option>
              <?php foreach ($usersForSelect as $u):
                $label = trim(($u['ad'] ?? '') . ' ' . ($u['soyad'] ?? ''));
                $uno = $u['uyelik_numarasi'] ?? '';
                if ($uno !== '') { $label .= " (#" . htmlspecialchars($uno) . ")"; }
                $dkilo  = $u['kilo_kg'] ?? '';
                $dyag   = $u['yag_orani'] ?? '';
                $dhedef = $u['spor_hedefi'] ?? '';
              ?>
                <option value="<?= (int)$u['id'] ?>"
                        data-kilo="<?= htmlspecialchars((string)$dkilo) ?>"
                        data-yag="<?= htmlspecialchars((string)$dyag) ?>"
                        data-hedef="<?= htmlspecialchars((string)$dhedef) ?>"
                        <?= ((int)$u['id'] === $targetUserId ? 'selected' : '') ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Kilo</label>
            <input type="number" class="form-control" id="kilo" name="kilo" placeholder="Kilo (kg)" value="<?= htmlspecialchars((string)$targetKilo) ?>" step="0.1" readonly>
          </div>
          <div class="col-md-2">
            <label class="form-label">Yağ Oranı</label>
            <input type="number" class="form-control" id="yag_orani" name="yag_orani" placeholder="Yağ Oranı (%)" value="<?= htmlspecialchars((string)$targetYag) ?>" step="0.1" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Hedef</label>
            <input type="text" class="form-control" id="hedef" name="hedef" placeholder="Örn: Yağ Kaybı / Kas Artışı" value="<?= htmlspecialchars((string)$targetHedef) ?>" readonly>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($role === 'kullanici'): ?>
        <div class="row g-3 align-items-end mt-1">
          <div class="col-md-2">
            <label class="form-label">Kilo</label>
            <input type="number" class="form-control" id="kilo" name="kilo" placeholder="Kilo (kg)" value="<?= htmlspecialchars((string)$targetKilo) ?>" step="0.1" readonly>
          </div>
          <div class="col-md-2">
            <label class="form-label">Yağ Oranı</label>
            <input type="number" class="form-control" id="yag_orani" name="yag_orani" placeholder="Yağ Oranı (%)" value="<?= htmlspecialchars((string)$targetYag) ?>" step="0.1" readonly>
          </div>
          <div class="col-md-8">
            <label class="form-label">Hedef</label>
            <input type="text" class="form-control" id="hedef" name="hedef" placeholder="Örn: Yağ Kaybı / Kas Artışı" value="<?= htmlspecialchars((string)$targetHedef) ?>" readonly>
          </div>
        </div>
      <?php endif; ?>

      <hr class="my-4">

      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Öğün</label>
          <select id="ogun" class="form-select">
            <option value="Sabah">Sabah</option>
            <option value="Ara-Ogun-1">Ara Öğün 1</option>
            <option value="Oglen">Öğlen</option>
            <option value="Ara-Ogun-2">Ara Öğün 2</option>
            <option value="Aksam">Akşam</option>
          </select>
        </div>

        <div class="col-md-5 position-relative">
          <label class="form-label">Yemek / Besin</label>
          <input type="text" id="yemek" class="form-control" placeholder="Yemek adı yazın..." autocomplete="off">
          <div id="yemekSug" class="autocomplete-box" style="display:none;"></div>
        </div>

        <div class="col-md-2">
          <label class="form-label">Miktar</label>
          <input type="number" id="miktar" class="form-control" placeholder="Örn: 3 / 150 / 250" min="0.01" step="0.01">
        </div>

        <div class="col-md-2 d-grid">
          <button type="button" id="addItem" class="btn btn-light">+ Ekle</button>
        </div>
      </div>

      <div class="row g-3 mt-1">
        <div class="col-md-12">
          <label class="form-label">Notlar</label>
          <textarea class="form-control" name="notlar" rows="3" placeholder="Varsa ek not girin..."></textarea>
        </div>
      </div>

      <div class="glass-card card-pad mt-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h5 class="m-0">📋 Liste</h5>
          <button type="button" class="btn btn-sm btn-outline-danger" id="clearList">Temizle</button>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Öğün</th>
                <th>Yemek</th>
                <th class="text-end">Miktar</th>
                <th class="text-end">Kalori</th>
                <th class="text-end">Karb</th>
                <th class="text-end">Protein</th>
                <th class="text-end">Yağ</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="listBody"></tbody>
            <tfoot>
              <tr>
                <th colspan="3" class="text-end">TOPLAM</th>
                <th class="text-end" id="topKal">0</th>
                <th class="text-end" id="topKarb">0</th>
                <th class="text-end" id="topProt">0</th>
                <th class="text-end" id="topYag">0</th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <input type="hidden" name="items_json" id="items_json" value="[]">

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-success">💾 Kaydet</button>
        <a href="beslenme-programim.php" class="btn btn-outline-light">📄 Programlarım</a>
      </div>
    </form>
  </div>
</div>

<style>
.autocomplete-box{
  position:absolute;
  left:0;
  right:0;
  top:100%;
  z-index:1060;
  background:#fff;
  border:1px solid rgba(15,23,42,.10);
  border-radius:14px;
  box-shadow:0 16px 40px rgba(15,23,42,.10);
  margin-top:8px;
  overflow:hidden;
}
.ac-item{
  padding:12px 14px;
  cursor:pointer;
  border-bottom:1px solid rgba(15,23,42,.06);
}
.ac-item:last-child{ border-bottom:none; }
.ac-item:hover{ background:rgba(15,23,42,.03); }
.ac-title{ font-weight:700; color:#0f172a; }
.ac-sub{ font-size:12px; color:#64748b; margin-top:2px; }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function(){
  let items = [];
  let acTimer = null;
  let selectedFood = null;

  function applyUserMeta(){
    const sel = document.getElementById('kullanici');
    const kiloEl = document.getElementById('kilo');
    const yagEl = document.getElementById('yag_orani');
    const hedefEl = document.getElementById('hedef');
    if(!sel || !kiloEl || !hedefEl) return;

    const opt = sel.options[sel.selectedIndex];
    if(!opt || !opt.value){
      kiloEl.value = '';
      if (yagEl) yagEl.value = '';
      hedefEl.value = '';
      return;
    }
    kiloEl.value = opt.dataset.kilo || '';
    if (yagEl) yagEl.value = opt.dataset.yag || '';
    hedefEl.value = opt.dataset.hedef || '';
  }

  const selEl = document.getElementById('kullanici');
  if (selEl) {
    selEl.addEventListener('change', applyUserMeta);
    setTimeout(applyUserMeta, 0);
  }

  function fmt2(x){
    return (Math.round((Number(x) + Number.EPSILON) * 100) / 100).toFixed(2);
  }

  function escapeHtml(v){
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function unitLabel(birimTip){
    if (birimTip === 'adet') return 'adet';
    if (birimTip === 'ml') return 'ml';
    return 'gram';
  }

  function normalizeMealLabel(v){
    const map = {
      'Sabah': 'Sabah',
      'Ara-Ogun-1': 'Ara Öğün 1',
      'Oglen': 'Öğlen',
      'Ara-Ogun-2': 'Ara Öğün 2',
      'Aksam': 'Akşam'
    };
    return map[v] || v;
  }

  function recalcTotals(){
    let k = 0, c = 0, p = 0, y = 0;
    items.forEach(it => {
      k += Number(it.kalori || 0);
      c += Number(it.karb || 0);
      p += Number(it.prot || 0);
      y += Number(it.yag || 0);
    });
    $("#topKal").text(fmt2(k));
    $("#topKarb").text(fmt2(c));
    $("#topProt").text(fmt2(p));
    $("#topYag").text(fmt2(y));
  }

  function render(){
    const $b = $("#listBody").empty();

    items.forEach((it, idx) => {
      const tr = $(`
        <tr>
          <td>${escapeHtml(normalizeMealLabel(it.ogun))}</td>
          <td>
            <div class="fw-semibold">${escapeHtml(it.yemek)}</div>
            <div class="text-muted small">${escapeHtml(fmt2(it.baz_miktar))} ${escapeHtml(it.birim)}</div>
          </td>
          <td class="text-end">${escapeHtml(fmt2(it.miktar))} ${escapeHtml(it.birim)}</td>
          <td class="text-end">${escapeHtml(fmt2(it.kalori))}</td>
          <td class="text-end">${escapeHtml(fmt2(it.karb))}</td>
          <td class="text-end">${escapeHtml(fmt2(it.prot))}</td>
          <td class="text-end">${escapeHtml(fmt2(it.yag))}</td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger btnRemove" data-idx="${idx}">Sil</button>
          </td>
        </tr>
      `);
      $b.append(tr);
    });

    $("#items_json").val(JSON.stringify(items));
    recalcTotals();
  }

  function showSug(list){
    const $box = $("#yemekSug").empty();
    if(!list || !list.length){
      $box.hide();
      return;
    }

    list.forEach(item => {
      const birimLabel = item.birim_label || (item.birim_tip === 'adet' ? 'Adet' : (item.birim_tip === 'ml' ? 'Ml' : 'Gram'));
      const sub = `${fmt2(item.baz_miktar || 0)} ${birimLabel} • ${fmt2(item.kalori || 0)} kcal • P ${fmt2(item.protein || 0)} • Y ${fmt2(item.yag || 0)} • K ${fmt2(item.karbonhidrat || 0)}`;

      const row = $(`
        <div class="ac-item">
          <div class="ac-title"></div>
          <div class="ac-sub"></div>
        </div>
      `);

      row.find(".ac-title").text(item.besin_adi || item.label || item.value || '');
      row.find(".ac-sub").text(sub);
      row.data("food", item);
      $box.append(row);
    });

    $box.show();
  }

  $("#yemek").on("input", function(){
    const q = $(this).val().trim();
    selectedFood = null;

    if (acTimer) clearTimeout(acTimer);

    if (q.length < 2){
      $("#yemekSug").hide().empty();
      return;
    }

    acTimer = setTimeout(() => {
      $.getJSON("autocomplete_besin.php", { q }, function(res){
        showSug(res || []);
      }).fail(function(){
        $("#yemekSug").hide().empty();
      });
    }, 250);
  });

  $(document).on("click", ".ac-item", function(){
    const x = $(this).data("food") || {};

    selectedFood = {
      besin_id: parseInt(x.besin_id || 0, 10) || null,
      ad: x.besin_adi || x.value || x.label || '',
      birim_tip: x.birim_tip || 'gram',
      birim: unitLabel(x.birim_tip || 'gram'),
      baz_miktar: parseFloat(x.baz_miktar || 1),
      kalori: parseFloat(x.kalori || x.Kalori || 0),
      karb: parseFloat(x.karbonhidrat || x.Karbonhidrat || 0),
      protein: parseFloat(x.protein || x.Protein || 0),
      yag: parseFloat(x.yag || x.Yag || 0)
    };

    $("#yemek").val(selectedFood.ad);
    $("#yemekSug").hide().empty();
  });

  $(document).on("click", function(e){
    if (!$(e.target).closest("#yemek, #yemekSug").length) {
      $("#yemekSug").hide().empty();
    }
  });

  $("#addItem").on("click", function(){
    const ogun = $("#ogun").val();
    const yemek = $("#yemek").val().trim();
    const miktar = parseFloat($("#miktar").val());

    if(!yemek || !miktar || miktar <= 0){
      alert("Yemek ve miktar giriniz.");
      return;
    }

    if(!selectedFood || selectedFood.ad !== yemek){
      alert("Lütfen listeden geçerli bir besin seçiniz.");
      return;
    }

    const bazMiktar = parseFloat(selectedFood.baz_miktar || 1);
    if (!bazMiktar || bazMiktar <= 0) {
      alert("Seçilen besinin baz miktarı geçersiz.");
      return;
    }

    const ratio = miktar / bazMiktar;

    const kal  = parseFloat(selectedFood.kalori || 0) * ratio;
    const karb = parseFloat(selectedFood.karb || 0) * ratio;
    const prot = parseFloat(selectedFood.protein || 0) * ratio;
    const yag  = parseFloat(selectedFood.yag || 0) * ratio;

    items.push({
      ogun: ogun,
      yemek: yemek,
      besin_id: selectedFood.besin_id,
      baz_miktar: bazMiktar,
      birim_tip: selectedFood.birim_tip,
      miktar: miktar,
      birim: selectedFood.birim,
      kalori: kal,
      karb: karb,
      prot: prot,
      protein: prot,
      yag: yag
    });

    $("#miktar").val("");
    $("#yemek").val("");
    selectedFood = null;
    render();
  });

  $(document).on("click", ".btnRemove", function(){
    const idx = parseInt($(this).data("idx"), 10);
    if(Number.isNaN(idx)) return;
    items.splice(idx, 1);
    render();
  });

  $("#clearList").on("click", function(){
    if(!confirm("Tüm satırlar silinsin mi?")) return;
    items = [];
    render();
  });

  $("#programForm").on("submit", function(e){
    if(!items.length){
      e.preventDefault();
      alert("Program içeriği boş olamaz.");
      return false;
    }
    $("#items_json").val(JSON.stringify(items));
  });

  render();
});
</script>

<?php include "inc/footer.php"; ?>