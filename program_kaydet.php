<?php
// program_kaydet.php (FINAL - single food table compatible)

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

function fetch_user_by_id(mysqli $conn, int $id): ?array {
  $st = $conn->prepare("SELECT * FROM uye_kullanicilar WHERE id=? LIMIT 1");
  $st->bind_param("i", $id);
  $st->execute();
  $u = $st->get_result()->fetch_assoc();
  $st->close();
  return $u ?: null;
}

// --- Current user normalize ---
$user = $_SESSION['user'] ?? null;
if (!$user) {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid > 0) {
    $u = fetch_user_by_id($conn, $uid);
    if ($u) { $_SESSION['user'] = $u; $user = $u; }
  }
}
if (!$user) { header("Location: login.php"); exit; }

$selfUserId = (int)($user['id'] ?? 0);
$role = $user['yetki'] ?? $user['rol'] ?? $_SESSION['yetki'] ?? $_SESSION['rol'] ?? 'kullanici';
$role = in_array($role, ['kullanici','egitmen','admin'], true) ? $role : 'kullanici';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$targetUserId = isset($_POST['kullanici']) ? (int)$_POST['kullanici'] : $selfUserId;
$hedef = trim((string)($_POST['hedef'] ?? ''));
$notlar = trim((string)($_POST['notlar'] ?? ''));
$kilo = isset($_POST['kilo']) && $_POST['kilo'] !== '' ? (float)$_POST['kilo'] : null;

$items_json = (string)($_POST['items_json'] ?? '[]');
$items = json_decode($items_json, true);
if (!is_array($items)) { http_response_code(400); exit('items_json geçersiz'); }
if (count($items) === 0) { http_response_code(400); exit('Program içeriği boş olamaz.'); }

// --- Authorization ---
if ($role === 'kullanici' && $targetUserId !== $selfUserId) {
  http_response_code(403); exit('Yetkisiz işlem.');
}
if ($role === 'egitmen' && $targetUserId !== $selfUserId) {
  $chk = $conn->prepare("SELECT 1 FROM uye_kullanicilar WHERE id=? AND egitmen_id=? LIMIT 1");
  $chk->bind_param("ii", $targetUserId, $selfUserId);
  $chk->execute();
  $ok = (bool)$chk->get_result()->fetch_row();
  $chk->close();
  if (!$ok) { http_response_code(403); exit('Bu üyeye işlem yetkiniz yok.'); }
}

// --- Column resolver ---
function pick_col(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) { if (in_array($c, $cols, true)) return $c; }
  return null;
}
function table_cols(mysqli $conn, string $table): array {
  $cols = [];
  $res = $conn->query("SHOW COLUMNS FROM `$table`");
  if (!$res) return $cols;
  while ($r = $res->fetch_assoc()) { $cols[] = $r['Field']; }
  return $cols;
}
function safe_float($value): float {
  return is_numeric($value) ? (float)$value : 0.0;
}
function safe_int_or_null($value): ?int {
  if ($value === null || $value === '') return null;
  if (!is_numeric($value)) return null;
  return (int)$value;
}
function normalize_ogun(string $value): string {
  $key = trim(mb_strtolower($value, 'UTF-8'));
  $map = [
    'sabah' => 'Sabah',

    'ara öğün 1' => 'Ara-Ogun-1',
    'ara öğün1' => 'Ara-Ogun-1',
    'ara ogun 1' => 'Ara-Ogun-1',
    'ara ogun1' => 'Ara-Ogun-1',
    'ara-ogun-1' => 'Ara-Ogun-1',
    'ara-ogun 1' => 'Ara-Ogun-1',
    'ara öğün' => 'Ara-Ogun-1',

    'öğle' => 'Oglen',
    'oglen' => 'Oglen',
    'öğlen' => 'Oglen',

    'ara öğün 2' => 'Ara-Ogun-2',
    'ara öğün2' => 'Ara-Ogun-2',
    'ara ogun 2' => 'Ara-Ogun-2',
    'ara ogun2' => 'Ara-Ogun-2',
    'ara-ogun-2' => 'Ara-Ogun-2',

    'ikindi' => 'Ara-Ogun-2',
    'i̇kindi' => 'Ara-Ogun-2',

    'akşam' => 'Aksam',
    'aksam' => 'Aksam',
  ];
  return $map[$key] ?? $value;
}

$tbl_prog = 'beslenme_programlar';
$tbl_item = 'beslenme_program_ogeler';

$prog_cols = table_cols($conn, $tbl_prog);
$item_cols = table_cols($conn, $tbl_item);
if (!$prog_cols || !$item_cols) {
  http_response_code(500);
  exit('Beslenme tabloları bulunamadı (beslenme_programlar / beslenme_program_ogeler).');
}

$col_user    = pick_col($prog_cols, ['user_id','uye_id','kullanici_id']);
$col_hedef   = pick_col($prog_cols, ['hedef','goal']);
$col_kilo    = pick_col($prog_cols, ['kilo','weight']);
$col_notlar  = pick_col($prog_cols, ['notlar','aciklama','yorum']);
$col_created = pick_col($prog_cols, ['created_at','kayit_tarihi','tarih','created']);

if (!$col_user) { http_response_code(500); exit('beslenme_programlar user_id kolonu bulunamadı.'); }

$col_pid        = pick_col($item_cols, ['program_id','beslenme_program_id']);
$col_ogun       = pick_col($item_cols, ['ogun','ogun_adi','meal']);
$col_yemek      = pick_col($item_cols, ['yemek','besin','urun','food']);
$col_besin_id   = pick_col($item_cols, ['besin_id']);
$col_baz_miktar = pick_col($item_cols, ['baz_miktar']);
$col_birim_tip  = pick_col($item_cols, ['birim_tip']);
$col_miktar     = pick_col($item_cols, ['miktar','adet_gram','amount']);
$col_birim      = pick_col($item_cols, ['birim','unit']);
$col_kal        = pick_col($item_cols, ['kalori','kcal']);
$col_karb       = pick_col($item_cols, ['karb','karbonhidrat','carb']);
$col_prot       = pick_col($item_cols, ['protein','prot']);
$col_yag        = pick_col($item_cols, ['yag','fat']);

if (!$col_pid || !$col_ogun || !$col_yemek || !$col_miktar) {
  http_response_code(500);
  exit('beslenme_program_ogeler kolonları eksik (program_id/ogun/yemek/miktar).');
}

$allowed = ['Sabah','Ara-Ogun-1','Oglen','Ara-Ogun-2','Aksam'];

$conn->begin_transaction();

try {
  // Insert program
  $fields = [$col_user];
  $vals = [$targetUserId];
  $types = "i";

  if ($col_hedef) { $fields[] = $col_hedef; $vals[] = $hedef; $types .= "s"; }
  if ($col_kilo) { $fields[] = $col_kilo; $vals[] = $kilo; $types .= "d"; }
  if ($col_notlar) { $fields[] = $col_notlar; $vals[] = $notlar; $types .= "s"; }

  $sql = "INSERT INTO `$tbl_prog` (`" . implode("`,`", $fields) . "`";
  if ($col_created) { $sql .= ",`$col_created`"; }
  $sql .= ") VALUES (" . implode(",", array_fill(0, count($fields), "?"));
  if ($col_created) { $sql .= ",NOW()"; }
  $sql .= ")";

  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$vals);
  $st->execute();
  $programId = (int)$conn->insert_id;
  $st->close();

  // Insert items
  foreach ($items as $it) {
    $ogun = normalize_ogun((string)($it['ogun'] ?? ''));
    if (!in_array($ogun, $allowed, true)) {
      throw new Exception("Geçersiz öğün: " . ((string)($it['ogun'] ?? '')));
    }

    $yemek = trim((string)($it['yemek'] ?? ''));
    $miktar = safe_float($it['miktar'] ?? 0);
    if ($yemek === '' || $miktar <= 0) {
      throw new Exception('Eksik veya geçersiz satır bulundu.');
    }

    $besinId   = safe_int_or_null($it['besin_id'] ?? null);
    $bazMiktar = safe_float($it['baz_miktar'] ?? 0);
    $birimTip  = trim((string)($it['birim_tip'] ?? ''));
    $birim     = trim((string)($it['birim'] ?? ''));

    $kal  = safe_float($it['kalori'] ?? 0);
    $karb = safe_float($it['karb'] ?? ($it['karbonhidrat'] ?? 0));
    $prot = safe_float($it['prot'] ?? ($it['protein'] ?? 0));
    $yag  = safe_float($it['yag'] ?? 0);

    $itemFields = [$col_pid, $col_ogun, $col_yemek, $col_miktar];
    $itemTypes  = "issd";
    $bind       = [$programId, $ogun, $yemek, $miktar];

    if ($col_besin_id) {
      $itemFields[] = $col_besin_id;
      $itemTypes .= "i";
      $bind[] = $besinId;
    }
    if ($col_baz_miktar) {
      $itemFields[] = $col_baz_miktar;
      $itemTypes .= "d";
      $bind[] = $bazMiktar;
    }
    if ($col_birim_tip) {
      $itemFields[] = $col_birim_tip;
      $itemTypes .= "s";
      $bind[] = $birimTip;
    }
    if ($col_birim) {
      $itemFields[] = $col_birim;
      $itemTypes .= "s";
      $bind[] = $birim;
    }
    if ($col_kal) {
      $itemFields[] = $col_kal;
      $itemTypes .= "d";
      $bind[] = $kal;
    }
    if ($col_karb) {
      $itemFields[] = $col_karb;
      $itemTypes .= "d";
      $bind[] = $karb;
    }
    if ($col_prot) {
      $itemFields[] = $col_prot;
      $itemTypes .= "d";
      $bind[] = $prot;
    }
    if ($col_yag) {
      $itemFields[] = $col_yag;
      $itemTypes .= "d";
      $bind[] = $yag;
    }

    $placeholders = implode(",", array_fill(0, count($itemFields), "?"));
    $sqlItem = "INSERT INTO `$tbl_item` (`" . implode("`,`", $itemFields) . "`) VALUES ($placeholders)";
    $sti = $conn->prepare($sqlItem);
    $sti->bind_param($itemTypes, ...$bind);
    $sti->execute();
    $sti->close();
  }

  $conn->commit();

  header("Location: beslenme-programim.php?user_id=" . $targetUserId . "&program_id=" . $programId);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  error_log('program_kaydet.php hata (user=' . $selfUserId . '): ' . $e->getMessage());
  http_response_code(500);
  exit('Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.');
}