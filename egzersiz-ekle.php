<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
send_security_headers();

if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

$user = $_SESSION['user'];
$user_id = (int)($user['id'] ?? 0);

// Rol kontrolü: 'yetki' alanını kullan (NOT: eski kod 'rol'/'role' kullanıyordu - HATA!)
$role = (string)($user['yetki'] ?? '');
if (!in_array($role, ['egitmen', 'admin'], true)) {
    http_response_code(403);
    exit('Bu sayfaya erişim yetkiniz yok.');
}

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

// ENUM seçenekleri (DB ile uyumlu tut)
$HEDEF_BOLGE = [
  'gogus' => 'Göğüs',
  'sirt' => 'Sırt',
  'omuz' => 'Omuz',
  'biceps' => 'Biceps',
  'triceps' => 'Triceps',
  'on_bacak' => 'Ön Bacak',
  'arka_bacak' => 'Arka Bacak',
  'kalca' => 'Kalça',
  'core' => 'Core',
  'kalf' => 'Kalf',
  'tum_vucut' => 'Tüm Vücut',
];

$HAREKET_TURU = [
  'push' => 'Push',
  'pull' => 'Pull',
  'legs' => 'Legs',
  'hinge' => 'Hinge',
  'squat' => 'Squat',
  'carry' => 'Carry',
  'rotation' => 'Rotation',
  'isolation' => 'Isolation',
  'compound' => 'Compound',
];

function clean_str(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?? '';
}

function valid_url(string $url): bool {
  if ($url === '') return true; // opsiyonel
  return (bool)filter_var($url, FILTER_VALIDATE_URL);
}

function build_return_query(array $keepKeys): string {
  $out = [];
  foreach ($keepKeys as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $out[$k] = (string)$_GET[$k];
  }
  return $out ? ('?'.http_build_query($out)) : '';
}

// --- Edit modu için (GET edit=ID) ---
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = null;
if ($edit_id > 0) {
  $stmt = $conn->prepare("SELECT id, egzersiz_ismi, egzersiz_gif, hedef_bolge, hareket_turu FROM egzersizler WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $edit_id);
  $stmt->execute();
  $edit_row = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  if (!$edit_row) {
    $error = 'Düzenlenecek kayıt bulunamadı.';
    $edit_id = 0;
  }
}

// --- POST işlemleri: create / update / delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  // Sil
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $error = 'Silme için geçersiz ID.';
    } else {
      $stmt = $conn->prepare("DELETE FROM egzersizler WHERE id = ? LIMIT 1");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $affected = $stmt->affected_rows;
      $stmt->close();

      if ($affected > 0) {
        $success = 'Egzersiz silindi.';
      } else {
        $error = 'Silinecek kayıt bulunamadı.';
      }
    }
  }

  // Ekle / Güncelle
  if ($action === 'create' || $action === 'update') {
    $id = (int)($_POST['id'] ?? 0);

    $egzersiz_ismi = clean_str((string)($_POST['egzersiz_ismi'] ?? ''));
    $hedef_bolge   = (string)($_POST['hedef_bolge'] ?? '');
    $hareket_turu  = (string)($_POST['hareket_turu'] ?? '');
    $egzersiz_gif  = trim((string)($_POST['egzersiz_gif'] ?? ''));

    if ($egzersiz_ismi === '') $error = 'Egzersiz ismi boş olamaz.';
    elseif (!array_key_exists($hedef_bolge, $HEDEF_BOLGE)) $error = 'Hedef bölge seçimi geçersiz.';
    elseif (!array_key_exists($hareket_turu, $HAREKET_TURU)) $error = 'Hareket türü seçimi geçersiz.';
    elseif (!valid_url($egzersiz_gif)) $error = 'GIF linki geçersiz bir URL.';
    else {
      if ($action === 'create') {
        // aynı isim+bölge+tür tekrar eklenmesin
        $stmt = $conn->prepare("SELECT id FROM egzersizler WHERE egzersiz_ismi = ? AND hedef_bolge = ? AND hareket_turu = ? LIMIT 1");
        $stmt->bind_param("sss", $egzersiz_ismi, $hedef_bolge, $hareket_turu);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
          $error = 'Bu egzersiz (isim + bölge + tür) zaten mevcut.';
        } else {
          $stmt = $conn->prepare("INSERT INTO egzersizler (egzersiz_ismi, egzersiz_gif, hedef_bolge, hareket_turu) VALUES (?,?,?,?)");
          $stmt->bind_param("ssss", $egzersiz_ismi, $egzersiz_gif, $hedef_bolge, $hareket_turu);
          $stmt->execute();
          $stmt->close();
          $success = 'Egzersiz başarıyla kaydedildi.';
        }
      }

      if ($action === 'update') {
        if ($id <= 0) {
          $error = 'Güncelleme için geçersiz ID.';
        } else {
          // Duplicate kontrolü (kendisi hariç)
          $stmt = $conn->prepare("SELECT id FROM egzersizler WHERE egzersiz_ismi = ? AND hedef_bolge = ? AND hareket_turu = ? AND id <> ? LIMIT 1");
          $stmt->bind_param("sssi", $egzersiz_ismi, $hedef_bolge, $hareket_turu, $id);
          $stmt->execute();
          $exists = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          if ($exists) {
            $error = 'Bu egzersiz (isim + bölge + tür) zaten mevcut (başka kayıtta).';
          } else {
            $stmt = $conn->prepare("UPDATE egzersizler SET egzersiz_ismi = ?, egzersiz_gif = ?, hedef_bolge = ?, hareket_turu = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param("ssssi", $egzersiz_ismi, $egzersiz_gif, $hedef_bolge, $hareket_turu, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            $success = 'Egzersiz güncellendi.';
            // edit modundan çık (listeye dön)
            $edit_id = 0;
            $edit_row = null;
          }
        }
      }
    }
  }

  // PRG (refresh / double submit önleme)
  // Filtreleri koru, edit'i koruma (güncelleme sonrası çıkıyoruz)
  $return = build_return_query(['q','bolge','tur']);
  header("Location: egzersiz-ekle.php{$return}");
  exit;
}

// --- Liste çek + filtre ---
$q = clean_str((string)($_GET['q'] ?? ''));
$f_bolge = (string)($_GET['bolge'] ?? '');
$f_tur   = (string)($_GET['tur'] ?? '');

$where = [];
$params = [];
$types = '';

if ($q !== '') {
  $where[] = "egzersiz_ismi LIKE ?";
  $params[] = "%{$q}%";
  $types .= 's';
}
if ($f_bolge !== '' && array_key_exists($f_bolge, $HEDEF_BOLGE)) {
  $where[] = "hedef_bolge = ?";
  $params[] = $f_bolge;
  $types .= 's';
}
if ($f_tur !== '' && array_key_exists($f_tur, $HAREKET_TURU)) {
  $where[] = "hareket_turu = ?";
  $params[] = $f_tur;
  $types .= 's';
}

$sql = "SELECT id, egzersiz_ismi, egzersiz_gif, hedef_bolge, hareket_turu
        FROM egzersizler ".
        (count($where) ? ("WHERE ".implode(" AND ", $where)) : "").
        " ORDER BY id DESC LIMIT 300";

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Form defaultları (edit varsa doldur)
$form_mode = ($edit_id > 0 && $edit_row) ? 'update' : 'create';
$val_id = $edit_row['id'] ?? '';
$val_ismi = $edit_row['egzersiz_ismi'] ?? '';
$val_gif = $edit_row['egzersiz_gif'] ?? '';
$val_bolge = $edit_row['hedef_bolge'] ?? '';
$val_tur = $edit_row['hareket_turu'] ?? '';

// --- Header include (robust) ---
$__hdr_loaded = false;
foreach ([__DIR__.'/header.php', __DIR__.'/inc/header.php', __DIR__.'/partials/header.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__hdr_loaded = true; break; }
}
if (!$__hdr_loaded) {
  echo "<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
  echo "<title>Egzersiz Ekle</title></head><body>";
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
/* SADECE bu sayfa */
.page-egzersiz{
  --bg:#f6f7fb;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#64748b;
  --border:rgba(15,23,42,.10);
  --shadow: 0 18px 45px rgba(15,23,42,.08);
  --shadow2: 0 10px 22px rgba(15,23,42,.06);
  --radius:18px;
}
.page-egzersiz{
  background: var(--bg);
  color: var(--text);
  padding-bottom: 24px;
}
.page-egzersiz h3,
.page-egzersiz h5{ color: var(--text); letter-spacing:-.2px; }
.page-egzersiz .subtle{ color: var(--muted); }

/* Kart */
.page-egzersiz .glass{
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}

/* Form */
.page-egzersiz .form-label{ color: var(--text); font-weight:600; }
.page-egzersiz .form-text{ color: var(--muted) !important; }

.page-egzersiz .form-control,
.page-egzersiz .form-select{
  background: #fff !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  border-radius: 14px !important;
  padding: .70rem .85rem;
  box-shadow: none !important;
}
.page-egzersiz .form-control::placeholder{ color: rgba(100,116,139,.85); }
.page-egzersiz .form-control:focus,
.page-egzersiz .form-select:focus{
  border-color: rgba(59,130,246,.45) !important;
  box-shadow: 0 0 0 .25rem rgba(59,130,246,.12) !important;
}

/* Butonlar */
.page-egzersiz .btn{
  border-radius: 14px;
  padding: .65rem 1rem;
  box-shadow: none;
}
.page-egzersiz .btn-light{
  background: #111827;
  color: #fff;
  border-color: #111827;
}
.page-egzersiz .btn-light:hover{ filter: brightness(1.05); }

.page-egzersiz .btn-outline-light{
  color: #111827;
  border-color: rgba(15,23,42,.16);
  background: #fff;
}
.page-egzersiz .btn-outline-light:hover{ background: rgba(15,23,42,.04); }

.page-egzersiz .btn-outline-secondary{
  color:#334155;
  border-color: rgba(15,23,42,.14);
  background:#fff;
}
.page-egzersiz .btn-outline-secondary:hover{ background: rgba(15,23,42,.04); }

.page-egzersiz .btn-outline-danger{
  border-radius: 14px;
}

/* Badge */
.page-egzersiz .badge-soft{
  background: rgba(15,23,42,.04);
  border: 1px solid rgba(15,23,42,.10);
  color: #334155;
  font-weight:600;
  border-radius: 999px;
  padding: .42rem .60rem;
}

/* Tablo */
.page-egzersiz .table{
  color: var(--text);
  margin-bottom: 0;
}
.page-egzersiz .table thead th{
  color: #0f172a;
  font-weight: 700;
  border-bottom: 1px solid rgba(15,23,42,.10);
}
.page-egzersiz .table td,
.page-egzersiz .table th{ border-color: rgba(15,23,42,.08); }
.page-egzersiz .table tbody tr:hover{ background: rgba(15,23,42,.02); }

/* GIF thumb */
.page-egzersiz .gif-thumb{
  width: 88px;
  height: 52px;
  object-fit: cover;
  border-radius: 12px;
  border: 1px solid rgba(15,23,42,.10);
  background: rgba(15,23,42,.03);
  box-shadow: var(--shadow2);
}

/* Link */
.page-egzersiz a{ color:#2563eb; text-decoration:none; font-weight:600; }
.page-egzersiz a:hover{ text-decoration:underline; }

/* Mobil */
@media (max-width: 575px){
  .page-egzersiz .glass{ border-radius: 16px; }
  .page-egzersiz .gif-thumb{ width:72px; height:46px; }
}
/* GIF önizleme */
.page-egzersiz .gif-preview-wrap{
  display:flex;
  align-items:center;
  gap:12px;
  margin-top:10px;
}
.page-egzersiz .gif-preview{
  width: 160px;
  height: 90px;
  object-fit: cover;
  border-radius: 14px;
  border: 1px solid rgba(15,23,42,.10);
  background: rgba(15,23,42,.03);
  box-shadow: var(--shadow2);
  display:none; /* boşken gizli */
}
.page-egzersiz .gif-preview-meta{
  color: var(--muted);
  font-size: .92rem;
}
.page-egzersiz .gif-preview-error{
  color:#dc2626;
  font-weight:600;
  display:none;
}
</style>

<main class="page-egzersiz">
  <div class="container py-4" style="max-width:1100px">

    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
      <div>
        <h3 class="mb-1"><?= $form_mode === 'update' ? 'Egzersiz Güncelle' : 'Egzersiz Ekle' ?></h3>
        <div class="subtle">Eğitmen paneli: yeni egzersizleri kütüphaneye ekleyip antrenman oluşturda kullan.</div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-light" href="antrenman-olustur.php">Antrenman Oluştur</a>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="glass p-3 p-md-4 mb-4">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="<?= htmlspecialchars($form_mode) ?>">
        <?= csrf_field() ?>
        <?php if ($form_mode === 'update'): ?>
          <input type="hidden" name="id" value="<?= (int)$val_id ?>">
        <?php endif; ?>

        <div class="col-12 col-md-6">
          <label class="form-label">Egzersiz İsmi</label>
          <input
            type="text"
            name="egzersiz_ismi"
            class="form-control"
            placeholder="Örn: Bench Press"
            required
            value="<?= htmlspecialchars((string)$val_ismi) ?>"
          >
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Hedef Bölge</label>
          <select name="hedef_bolge" class="form-select" required>
            <option value="" disabled <?= $val_bolge===''?'selected':'' ?>>Seçiniz</option>
            <?php foreach ($HEDEF_BOLGE as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $val_bolge===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Hareket Türü</label>
          <select name="hareket_turu" class="form-select" required>
            <option value="" disabled <?= $val_tur===''?'selected':'' ?>>Seçiniz</option>
            <?php foreach ($HAREKET_TURU as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $val_tur===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label">GIF Linki</label>
          <input
            type="url"
            name="egzersiz_gif"
            class="form-control"
            placeholder="https://... (opsiyonel)"
            value="<?= htmlspecialchars((string)$val_gif) ?>"
          >
          <div class="form-text">Link boş olabilir; sonra güncelleyebilirsin.</div>
          <div class="gif-preview-wrap">
  <img id="gifPreview" class="gif-preview" alt="GIF önizleme">
  <div>
    <div id="gifPreviewMeta" class="gif-preview-meta">Önizleme: link girince burada görünecek.</div>
    <div id="gifPreviewError" class="gif-preview-error">GIF yüklenemedi. Linki kontrol et.</div>
  </div>
</div>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2">
          <?php if ($form_mode === 'update'): ?>
            <a class="btn btn-outline-secondary" href="egzersiz-ekle.php<?= htmlspecialchars(build_return_query(['q','bolge','tur'])) ?>">Vazgeç</a>
            <button class="btn btn-light px-4">Güncelle</button>
          <?php else: ?>
            <button class="btn btn-light px-4">Kaydet</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="glass p-3 p-md-4">
      <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
          <h5 class="mb-1">Egzersiz Kütüphanesi</h5>
          <div class="subtle">Son 300 kayıt. Arama + filtre ile bul.</div>
        </div>

        <form method="get" class="d-flex flex-wrap gap-2">
          <input class="form-control" style="min-width:240px" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Egzersiz ara...">

          <select class="form-select" name="bolge" style="min-width:190px">
            <option value="">Tüm bölgeler</option>
            <?php foreach ($HEDEF_BOLGE as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $f_bolge===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>

          <select class="form-select" name="tur" style="min-width:190px">
            <option value="">Tüm türler</option>
            <?php foreach ($HAREKET_TURU as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $f_tur===$k?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-outline-light">Filtrele</button>
          <a class="btn btn-outline-secondary" href="egzersiz-ekle.php">Sıfırla</a>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th style="width:100px">GIF</th>
              <th>Egzersiz</th>
              <th style="width:180px">Bölge</th>
              <th style="width:180px">Tür</th>
              <th style="width:120px">Link</th>
              <th style="width:170px">İşlem</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="py-4 subtle">Kayıt bulunamadı.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td>
                <?php if (!empty($r['egzersiz_gif'])): ?>
                  <img class="gif-thumb" src="<?= htmlspecialchars($r['egzersiz_gif']) ?>" alt="">
                <?php else: ?>
                  <span class="badge badge-soft">Yok</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($r['egzersiz_ismi']) ?></div>
                <div class="subtle small">ID: <?= (int)$r['id'] ?></div>
              </td>
              <td><span class="badge badge-soft"><?= htmlspecialchars($HEDEF_BOLGE[$r['hedef_bolge']] ?? $r['hedef_bolge']) ?></span></td>
              <td><span class="badge badge-soft"><?= htmlspecialchars($HAREKET_TURU[$r['hareket_turu']] ?? $r['hareket_turu']) ?></span></td>
              <td>
                <?php if (!empty($r['egzersiz_gif'])): ?>
                  <a href="<?= htmlspecialchars($r['egzersiz_gif']) ?>" target="_blank" rel="noreferrer">Aç</a>
                <?php else: ?>
                  <span class="subtle">-</span>
                <?php endif; ?>
              </td>
              <td>
  <div class="d-flex align-items-center justify-content-end gap-2">
    <?php
      $keep = [];
      if ($q !== '') $keep['q'] = $q;
      if ($f_bolge !== '') $keep['bolge'] = $f_bolge;
      if ($f_tur !== '') $keep['tur'] = $f_tur;
      $keep['edit'] = (string)(int)$r['id'];
      $editUrl = 'egzersiz-ekle.php?'.http_build_query($keep);
    ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($editUrl) ?>">Düzenle</a>

    <form method="post" onsubmit="return confirm('Bu egzersizi silmek istediğinize emin misiniz?');" style="display:inline">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn-outline-danger btn-sm">Sil</button>
    </form>
  </div>
</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>
<script>
(function(){
  const input = document.querySelector('input[name="egzersiz_gif"]');
  const img = document.getElementById('gifPreview');
  const meta = document.getElementById('gifPreviewMeta');
  const err = document.getElementById('gifPreviewError');

  if (!input || !img || !meta || !err) return;

  let lastUrl = '';
  let t = null;

  function resetPreview(){
    img.style.display = 'none';
    img.removeAttribute('src');
    err.style.display = 'none';
    meta.textContent = 'Önizleme: link girince burada görünecek.';
  }

  function showLoading(url){
    err.style.display = 'none';
    meta.textContent = 'Yükleniyor...';
    // cache bust (bazı CDN’ler aynı hatayı cache’leyebiliyor)
    const bust = (url.includes('?') ? '&' : '?') + '_cb=' + Date.now();
    img.src = url + bust;
    img.style.display = 'block';
  }

  function updatePreview(){
    const url = (input.value || '').trim();
    if (!url) { lastUrl = ''; resetPreview(); return; }
    if (url === lastUrl) return;
    lastUrl = url;
    showLoading(url);
  }

  // input yazarken çok sık denemesin diye debounce
  input.addEventListener('input', function(){
    clearTimeout(t);
    t = setTimeout(updatePreview, 350);
  });

  input.addEventListener('change', updatePreview);

  img.addEventListener('load', function(){
    err.style.display = 'none';
    meta.textContent = 'Önizleme hazır.';
  });

  img.addEventListener('error', function(){
    img.style.display = 'none';
    err.style.display = 'block';
    meta.textContent = 'Önizleme alınamadı.';
  });

  // sayfa update modunda açıldıysa ilk yüklemede göster
  window.addEventListener('DOMContentLoaded', function(){
    updatePreview();
  });
})();
</script>

<?php
// Footer include (robust)
$__ftr_loaded = false;
foreach ([__DIR__.'/footer.php', __DIR__.'/inc/footer.php', __DIR__.'/partials/footer.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__ftr_loaded = true; break; }
}
if (!$__hdr_loaded) echo "</body></html>";
?>