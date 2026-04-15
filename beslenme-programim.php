<?php
// beslenme-programim.php (FINAL - single food table compatible, current + history)
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

function ogunLabel(string $key): string {
  return [
    'Sabah' => 'Sabah',
    'Ara-Ogun-1' => 'Ara Öğün 1',
    'Oglen' => 'Öğlen',
    'Ara-Ogun-2' => 'Ara Öğün 2',
    'Aksam' => 'Akşam',
  ][$key] ?? $key;
}

function birimLabel(?string $birimTip, ?string $fallbackBirim = null): string {
  $birimTip = trim((string)$birimTip);
  if ($birimTip === 'adet') return 'adet';
  if ($birimTip === 'ml') return 'ml';

  $fallback = trim((string)$fallbackBirim);
  if ($fallback !== '') return $fallback;

  return 'gram';
}

function n2($x): string {
  return number_format((float)$x, 2, '.', '');
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

// Target user (trainer/admin can view)
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $selfUserId;
if ($role === 'kullanici') { $targetUserId = $selfUserId; }

// Trainer authorization
if ($role === 'egitmen' && $targetUserId !== $selfUserId) {
  $chk = $conn->prepare("SELECT 1 FROM uye_kullanicilar WHERE id=? AND egitmen_id=? LIMIT 1");
  $chk->bind_param("ii", $targetUserId, $selfUserId);
  $chk->execute();
  $ok = (bool)$chk->get_result()->fetch_row();
  $chk->close();
  if (!$ok) { http_response_code(403); exit('Bu üyeyi görüntüleme yetkiniz yok.'); }
}

// kullanıcı verileri her zaman uye_kullanicilar'dan gelsin
$targetUser = ($targetUserId === $selfUserId) ? $user : fetch_user_by_id($conn, $targetUserId);
$targetKilo = $targetUser['kilo_kg'] ?? ($targetUser['kilo'] ?? null);
$targetYag  = $targetUser['yag_orani'] ?? null;

// Program list + selection
$selectedProgramId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

$stmt = $conn->prepare("SELECT * FROM beslenme_programlar WHERE user_id=? ORDER BY created_at DESC, id DESC");
$stmt->bind_param("i", $targetUserId);
$stmt->execute();
$programlar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$aktifProgramId = !empty($programlar) ? (int)$programlar[0]['id'] : 0;
if ($selectedProgramId === 0 && $aktifProgramId) { $selectedProgramId = $aktifProgramId; }

$program = null;
foreach ($programlar as $p) {
  if ((int)$p['id'] === $selectedProgramId) {
    $program = $p;
    break;
  }
}
if (!$program && !empty($programlar)) {
  $program = $programlar[0];
  $selectedProgramId = (int)$program['id'];
}

// Items
$items = [];
if ($program) {
  $st2 = $conn->prepare("
    SELECT * FROM beslenme_program_ogeler
    WHERE program_id=?
    ORDER BY FIELD(ogun,'Sabah','Ara-Ogun-1','Oglen','Ara-Ogun-2','Aksam'), id ASC
  ");
  $st2->bind_param("i", $program['id']);
  $st2->execute();
  $items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
  $st2->close();
}

// Group by meal
$ogunOrder = ['Sabah','Ara-Ogun-1','Oglen','Ara-Ogun-2','Aksam'];
$grouped = array_fill_keys($ogunOrder, []);
foreach ($items as $it) {
  $ogun = $it['ogun'] ?? '';
  if (!isset($grouped[$ogun])) $grouped[$ogun] = [];
  $grouped[$ogun][] = $it;
}

$pageBodyClass = 'page-beslenme';
$pageCss = "/css/beslenme.css?v=20260415-3";
include "inc/header.php";
?>

<div class="container page-wrap">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="page-title">🍽️ Beslenme Programı
      <?php if ($program): ?>
        <?php if ((int)$program['id'] === $aktifProgramId): ?>
          <span class="badge bg-success ms-2">Aktif</span>
        <?php else: ?>
          <span class="badge bg-secondary ms-2">Arşiv</span>
        <?php endif; ?>
      <?php endif; ?>
    </h2>
  </div>

  <?php if (!$program): ?>
    <div class="glass-card card-pad">Henüz bir program yok.</div>
  <?php else: ?>

    <!-- History tabs -->
    <div class="glass-card card-pad mb-3">
      <div class="subtle mb-2">📚 Program Geçmişi</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($programlar as $p):
          $pid = (int)$p['id'];
          $active = ($pid === $selectedProgramId);
          $isAktif = ($pid === $aktifProgramId);
          $label = date('d.m.Y', strtotime($p['created_at'] ?? 'now'));
        ?>
          <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
             href="beslenme-programim.php?user_id=<?= (int)$targetUserId ?>&program_id=<?= $pid ?>">
             <?= htmlspecialchars($label) ?><?= $isAktif ? ' • Aktif' : '' ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Program header -->
    <div class="glass-card card-pad mb-3">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="subtle">Hedef</div>
          <strong><?= htmlspecialchars($program['hedef'] ?? '-') ?></strong>
        </div>
        <div class="col-md-3">
          <div class="subtle">Kilo</div>
          <strong><?= htmlspecialchars($targetKilo !== null && $targetKilo !== '' ? (string)$targetKilo . ' kg' : '-') ?></strong>
        </div>
        <div class="col-md-3">
          <div class="subtle">Yağ Oranı</div>
          <strong><?= htmlspecialchars($targetYag !== null && $targetYag !== '' ? (string)$targetYag . ' %' : '-') ?></strong>
        </div>
        <div class="col-md-3">
          <div class="subtle">Tarih</div>
          <strong><?= htmlspecialchars($program['created_at'] ?? '') ?></strong>
        </div>
      </div>

      <div class="mt-3">
        <div class="subtle">Notlar</div>
        <div><?= nl2br(htmlspecialchars($program['notlar'] ?? '')) ?></div>
      </div>
    </div>

    <?php
      $topKal = 0.0; $topK = 0.0; $topP = 0.0; $topY = 0.0;
    ?>

    <?php foreach ($ogunOrder as $ogun):
      $rows = $grouped[$ogun] ?? [];
      if (!$rows) continue;
    ?>
      <div class="glass-card card-pad mb-3">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h5 class="m-0"><?= htmlspecialchars(ogunLabel($ogun)) ?></h5>
          <span class="badge bg-light text-dark"><?= count($rows) ?> öğe</span>
        </div>

        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>Besin</th>
                <th class="text-end">Miktar</th>
                <th class="text-end">Kalori</th>
                <th class="text-end">Karb</th>
                <th class="text-end">Protein</th>
                <th class="text-end">Yağ</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $mealKal = 0.0; $mealK = 0.0; $mealP = 0.0; $mealY = 0.0;
                foreach ($rows as $it):
                  $kal = (float)($it['kalori'] ?? 0);
                  $karb = (float)($it['karbonhidrat'] ?? ($it['karb'] ?? 0));
                  $prot = (float)($it['protein'] ?? ($it['prot'] ?? 0));
                  $yag = (float)($it['yag'] ?? 0);

                  $mealKal += $kal;
                  $mealK += $karb;
                  $mealP += $prot;
                  $mealY += $yag;

                  $topKal += $kal;
                  $topK += $karb;
                  $topP += $prot;
                  $topY += $yag;

                  $miktar = (float)($it['miktar'] ?? 0);
                  $bazMiktar = isset($it['baz_miktar']) ? (float)$it['baz_miktar'] : 0;
                  $birimTip = $it['birim_tip'] ?? null;
                  $birim = birimLabel($birimTip, $it['birim'] ?? '');

                  $subInfo = [];
                  if (!empty($it['besin_id'])) {
                    $subInfo[] = '#' . (int)$it['besin_id'];
                  }
                  if ($bazMiktar > 0) {
                    $subInfo[] = 'baz: ' . n2($bazMiktar) . ' ' . $birim;
                  }
              ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars((string)($it['yemek'] ?? '-')) ?></div>
                    <?php if (!empty($subInfo)): ?>
                      <div class="small text-muted"><?= htmlspecialchars(implode(' • ', $subInfo)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= htmlspecialchars(n2($miktar) . ' ' . $birim) ?></td>
                  <td class="text-end"><?= htmlspecialchars(n2($kal)) ?></td>
                  <td class="text-end"><?= htmlspecialchars(n2($karb)) ?></td>
                  <td class="text-end"><?= htmlspecialchars(n2($prot)) ?></td>
                  <td class="text-end"><?= htmlspecialchars(n2($yag)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th class="text-end">Öğün Toplamı</th>
                <th></th>
                <th class="text-end"><?= htmlspecialchars(n2($mealKal)) ?></th>
                <th class="text-end"><?= htmlspecialchars(n2($mealK)) ?></th>
                <th class="text-end"><?= htmlspecialchars(n2($mealP)) ?></th>
                <th class="text-end"><?= htmlspecialchars(n2($mealY)) ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- Grand total -->
    <div class="glass-card card-pad">
      <div class="row g-3 text-center">
        <div class="col-6 col-md-3">
          <div class="subtle">Toplam Kalori</div>
          <div class="fs-4 fw-bold"><?= htmlspecialchars(n2($topKal)) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subtle">Toplam Karbonhidrat</div>
          <div class="fs-4 fw-bold"><?= htmlspecialchars(n2($topK)) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subtle">Toplam Protein</div>
          <div class="fs-4 fw-bold"><?= htmlspecialchars(n2($topP)) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subtle">Toplam Yağ</div>
          <div class="fs-4 fw-bold"><?= htmlspecialchars(n2($topY)) ?></div>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php include "inc/footer.php"; ?>