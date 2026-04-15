<?php
// besin-ekle.php (LIGHT THEME - SINGLE TABLE FINAL)
declare(strict_types=1);

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();

// require_session ['egitmen','admin'] ile hem oturum hem rol kontrolü yapar
$user = require_session(['egitmen', 'admin']);

// --- DB include (robust) ---
$__db_loaded = false;
foreach ([__DIR__ . '/inc/db.php', __DIR__ . '/db.php', __DIR__ . '/baglanti.php', __DIR__ . '/config/db.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__db_loaded = true; break; }
}
if (!$__db_loaded) { http_response_code(500); exit('DB bağlantı dosyası bulunamadı.'); }
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (isset($mysqli) && ($mysqli instanceof mysqli)) $conn = $mysqli;
}
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('DB bağlantısı ($conn) bulunamadı.'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$success = '';
$error = '';

$BIRIM_OPTIONS = [
  'adet' => 'Adet',
  'gram' => 'Gram',
  'ml'   => 'Ml',
];

function clean_str(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function to_decimal($v): float {
  $v = trim((string)$v);
  if ($v === '') return 0.0;
  $v = str_replace(',', '.', $v);
  $v = preg_replace('/[^0-9\.\-]/', '', $v);
  return (float)$v;
}

// --- KAYDET ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  csrf_verify();
  $ad = clean_str((string)($_POST['ad'] ?? ''));
  $birim_tip = clean_str((string)($_POST['birim_tip'] ?? ''));
  $baz_miktar = to_decimal($_POST['baz_miktar'] ?? 0);

  $kalori = to_decimal($_POST['kalori'] ?? 0);
  $protein = to_decimal($_POST['protein'] ?? 0);
  $yag = to_decimal($_POST['yag'] ?? 0);
  $karbonhidrat = to_decimal($_POST['karbonhidrat'] ?? 0);

  if ($ad === '') {
    $error = 'Besin adı boş olamaz.';
  } elseif (mb_strlen($ad) > 150) {
    $error = 'Besin adı en fazla 150 karakter olabilir.';
  } elseif (!array_key_exists($birim_tip, $BIRIM_OPTIONS)) {
    $error = 'Geçerli bir birim seçmelisiniz.';
  } elseif ($baz_miktar <= 0) {
    $error = 'Baz miktar 0’dan büyük olmalıdır.';
  } elseif ($kalori < 0 || $protein < 0 || $yag < 0 || $karbonhidrat < 0) {
    $error = 'Makro değerleri negatif olamaz.';
  } else {
    try {
      $stmt = $conn->prepare("SELECT id FROM besinler WHERE ad = ? LIMIT 1");
      $stmt->bind_param("s", $ad);
      $stmt->execute();
      $exists = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($exists) {
        $error = 'Bu besin zaten kayıtlı.';
      } else {
        $stmt = $conn->prepare("
          INSERT INTO besinler
          (ad, birim_tip, baz_miktar, kalori, protein, yag, karbonhidrat, aktif)
          VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param(
          "ssddddd",
          $ad,
          $birim_tip,
          $baz_miktar,
          $kalori,
          $protein,
          $yag,
          $karbonhidrat
        );
        $stmt->execute();
        $stmt->close();

        $success = 'Besin başarıyla kaydedildi.';
      }
    } catch (Throwable $e) {
      error_log('besin-ekle.php kayıt hatası: ' . $e->getMessage());
      $error = 'Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.';
    }
  }
}

// --- Liste + filtre ---
$q = clean_str((string)($_GET['q'] ?? ''));
$f_birim = clean_str((string)($_GET['birim_tip'] ?? ''));

$where = ["aktif = 1"];
$params = [];
$types = '';

if ($q !== '') {
  $where[] = "ad LIKE ?";
  $params[] = "%{$q}%";
  $types .= 's';
}

if ($f_birim !== '' && array_key_exists($f_birim, $BIRIM_OPTIONS)) {
  $where[] = "birim_tip = ?";
  $params[] = $f_birim;
  $types .= 's';
}

$sql = "SELECT id, ad, birim_tip, baz_miktar, kalori, protein, yag, karbonhidrat
        FROM besinler
        WHERE " . implode(" AND ", $where) . "
        ORDER BY id DESC
        LIMIT 400";

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Header include (robust) ---
$__hdr_loaded = false;
foreach ([__DIR__.'/header.php', __DIR__.'/inc/header.php', __DIR__.'/partials/header.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__hdr_loaded = true; break; }
}
if (!$__hdr_loaded) {
  echo "<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
  echo "<title>Besin Ekle</title></head><body>";
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
/* SADECE bu sayfa */
.page-besin{
  --bg:#f6f7fb;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#64748b;
  --border:rgba(15,23,42,.10);
  --shadow:0 18px 45px rgba(15,23,42,.08);
  --shadow2:0 10px 22px rgba(15,23,42,.06);
  --radius:18px;
}
.page-besin{
  background: var(--bg);
  color: var(--text);
  padding-bottom: 24px;
}
.page-besin h3,
.page-besin h5{ color: var(--text); letter-spacing:-.2px; }
.page-besin .subtle{ color: var(--muted); }

/* Kart */
.page-besin .glass{
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}

/* Form */
.page-besin .form-label{ color: var(--text); font-weight:600; }
.page-besin .form-text{ color: var(--muted) !important; }

.page-besin .form-control,
.page-besin .form-select{
  background: #fff !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  border-radius: 14px !important;
  padding: .70rem .85rem;
  box-shadow: none !important;
}
.page-besin .form-control::placeholder{ color: rgba(100,116,139,.85); }
.page-besin .form-control:focus,
.page-besin .form-select:focus{
  border-color: rgba(59,130,246,.45) !important;
  box-shadow: 0 0 0 .25rem rgba(59,130,246,.12) !important;
}

/* Butonlar */
.page-besin .btn{
  border-radius: 14px;
  padding: .65rem 1rem;
  box-shadow: none;
}
.page-besin .btn-light{
  background: #111827;
  color: #fff;
  border-color: #111827;
}
.page-besin .btn-light:hover{ filter: brightness(1.05); }

.page-besin .btn-outline-light{
  color: #111827;
  border-color: rgba(15,23,42,.16);
  background: #fff;
}
.page-besin .btn-outline-light:hover{ background: rgba(15,23,42,.04); }

.page-besin .btn-outline-secondary{
  color:#334155;
  border-color: rgba(15,23,42,.14);
  background:#fff;
}
.page-besin .btn-outline-secondary:hover{ background: rgba(15,23,42,.04); }

/* Badge */
.page-besin .badge-soft{
  background: rgba(15,23,42,.04);
  border: 1px solid rgba(15,23,42,.10);
  color: #334155;
  font-weight:600;
  border-radius: 999px;
  padding: .42rem .60rem;
}

/* Tablo */
.page-besin .table{
  color: var(--text);
  margin-bottom: 0;
}
.page-besin .table thead th{
  color: #0f172a;
  font-weight: 700;
  border-bottom: 1px solid rgba(15,23,42,.10);
}
.page-besin .table td,
.page-besin .table th{ border-color: rgba(15,23,42,.08); }
.page-besin .table tbody tr:hover{ background: rgba(15,23,42,.02); }

/* Sayısal kolonlar */
.num { font-variant-numeric: tabular-nums; }

/* Mobil */
@media (max-width: 575px){
  .page-besin .glass{ border-radius: 16px; }
}
</style>

<main class="page-besin">
  <div class="container py-4" style="max-width:1100px">

    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
      <div>
        <h3 class="mb-1">Besin Ekle</h3>
        <div class="subtle">Besin kütüphanesine yeni kayıt ekleyip beslenme oluştur ekranında kullan.</div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-light" href="beslenme-olustur.php">Beslenme Oluştur</a>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- FORM -->
    <div class="glass p-3 p-md-4 mb-4">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create">
        <?= csrf_field() ?>

        <div class="col-12 col-md-6">
          <label class="form-label">Yiyecek</label>
          <input
            type="text"
            name="ad"
            class="form-control"
            placeholder="Örn: Yulaf Ezmesi"
            maxlength="150"
            value="<?= htmlspecialchars((string)($_POST['ad'] ?? '')) ?>"
            required
          >
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label">Birim</label>
          <select name="birim_tip" class="form-select" required>
            <option value="">Seçiniz</option>
            <?php foreach ($BIRIM_OPTIONS as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['birim_tip'] ?? '') === $k) ? 'selected' : '' ?>>
                <?= htmlspecialchars($v) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Adet / Gram / Ml</div>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label">Baz Miktar</label>
          <input
            type="number"
            name="baz_miktar"
            class="form-control"
            placeholder="Örn: 1 / 100 / 250"
            min="0.01"
            step="0.01"
            value="<?= htmlspecialchars((string)($_POST['baz_miktar'] ?? '')) ?>"
            required
          >
          <div class="form-text">Makroların ait olduğu temel miktar</div>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Kalori</label>
          <input
            type="number"
            name="kalori"
            class="form-control"
            placeholder="0.00"
            min="0"
            step="0.01"
            value="<?= htmlspecialchars((string)($_POST['kalori'] ?? '')) ?>"
            required
          >
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Protein (g)</label>
          <input
            type="number"
            name="protein"
            class="form-control"
            placeholder="0.00"
            min="0"
            step="0.01"
            value="<?= htmlspecialchars((string)($_POST['protein'] ?? '')) ?>"
            required
          >
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Yağ (g)</label>
          <input
            type="number"
            name="yag"
            class="form-control"
            placeholder="0.00"
            min="0"
            step="0.01"
            value="<?= htmlspecialchars((string)($_POST['yag'] ?? '')) ?>"
            required
          >
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Karbonhidrat (g)</label>
          <input
            type="number"
            name="karbonhidrat"
            class="form-control"
            placeholder="0.00"
            min="0"
            step="0.01"
            value="<?= htmlspecialchars((string)($_POST['karbonhidrat'] ?? '')) ?>"
            required
          >
        </div>

        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-light px-4">Kaydet</button>
        </div>
      </form>
    </div>

    <!-- LİSTE -->
    <div class="glass p-3 p-md-4">
      <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
          <h5 class="mb-1">Besin Kütüphanesi</h5>
          <div class="subtle">Son 400 kayıt. Arama + filtre ile bul.</div>
        </div>

        <form method="get" class="d-flex flex-wrap gap-2">
          <input
            class="form-control"
            style="min-width:260px"
            name="q"
            value="<?= htmlspecialchars($q) ?>"
            placeholder="Yiyecek ara..."
          >

          <select class="form-select" name="birim_tip" style="min-width:160px">
            <option value="">Tüm birimler</option>
            <?php foreach ($BIRIM_OPTIONS as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $f_birim===$k?'selected':'' ?>>
                <?= htmlspecialchars($v) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-outline-light">Filtrele</button>
          <a class="btn btn-outline-secondary" href="besin-ekle.php">Sıfırla</a>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th style="width:70px">ID</th>
              <th>Yiyecek</th>
              <th style="width:120px">Birim</th>
              <th style="width:120px">Baz</th>
              <th style="width:110px">Kalori</th>
              <th style="width:130px">Protein</th>
              <th style="width:110px">Yağ</th>
              <th style="width:150px">Karbonhidrat</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="py-4 subtle">Kayıt bulunamadı.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="num"><?= (int)$r['id'] ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars((string)$r['ad']) ?></div>
                <div class="subtle small">
                  <?= htmlspecialchars((string)$r['baz_miktar']) ?> <?= htmlspecialchars((string)$BIRIM_OPTIONS[$r['birim_tip']]) ?>
                </div>
              </td>
              <td><span class="badge badge-soft"><?= htmlspecialchars((string)$BIRIM_OPTIONS[$r['birim_tip']]) ?></span></td>
              <td class="num"><?= number_format((float)$r['baz_miktar'], 2, '.', '') ?></td>
              <td class="num"><?= number_format((float)$r['kalori'], 2, '.', '') ?></td>
              <td class="num"><?= number_format((float)$r['protein'], 2, '.', '') ?></td>
              <td class="num"><?= number_format((float)$r['yag'], 2, '.', '') ?></td>
              <td class="num"><?= number_format((float)$r['karbonhidrat'], 2, '.', '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<?php if (!$__hdr_loaded): ?>
</body></html>
<?php endif; ?>