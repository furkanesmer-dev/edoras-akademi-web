<?php
session_start();

// Kullanıcı girişini kontrol et
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/inc/db.php';

$user = $_SESSION['user'];
$currentUserId = (int)($user['id'] ?? 0);
$role = $user['yetki'] ?? 'kullanici';

// Hedef üye (eğitmen/admin bakıyorsa GET ile gelebilir)
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUserId;

// Üye kendi dışında kimseyi göremez
if ($role === 'kullanici' && $targetUserId !== $currentUserId) {
    http_response_code(403);
    exit('Bu üyeyi görüntüleme yetkiniz yok.');
}

// Eğitmen sadece kendine atanmış üyeyi görebilir
if ($role === 'egitmen' && $targetUserId !== $currentUserId) {
    $chk = $conn->prepare("SELECT 1 FROM uye_kullanicilar WHERE id = ? AND egitmen_id = ? LIMIT 1");
    $chk->bind_param("ii", $targetUserId, $currentUserId);
    $chk->execute();
    $ok = $chk->get_result()->fetch_row();
    $chk->close();
    if (!$ok) {
        http_response_code(403);
        exit('Bu üyeyi görüntüleme yetkiniz yok.');
    }
}

// Bu sayfada kullanılacak user_id
$user_id = $targetUserId;

// Hedef üye adı (eğitmen/admin görüntülerken başlıkta gösterelim)
$targetUserName = '';
if ($targetUserId) {
    $st = $conn->prepare("SELECT ad, soyad FROM uye_kullanicilar WHERE id = ? LIMIT 1");
    $st->bind_param("i", $targetUserId);
    $st->execute();
    $rr = $st->get_result()->fetch_assoc();
    $st->close();
    if ($rr) $targetUserName = trim(($rr['ad'] ?? '') . ' ' . ($rr['soyad'] ?? ''));
}

$user_name = $user['ad'] ?? '';
$user_surname = $user['soyad'] ?? '';

/* SAYFA AYARLARI */
$pageBodyClass = 'page-antrenman';
$pageCss = '/css/antrenman.css';

// Kullanıcının tüm workout planlarını al (geçmiş + aktif)
$selectedPlanId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;

/**
 * created_at kolonu bazı sistemlerde olmayabiliyor.
 * Önce created_at ile dene, prepare fail olursa id DESC fallback.
 */
$allPlans = [];
$stmt = $conn->prepare("SELECT id, plan_data, created_at FROM workout_plans WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $allPlans[] = $row;
    $stmt->close();
} else {
    // fallback: created_at yoksa
    $stmt = $conn->prepare("SELECT id, plan_data FROM workout_plans WHERE user_id = ? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['created_at'] = null; // yoksa null bırak
            $allPlans[] = $row;
        }
        $stmt->close();
    }
}

// Varsayılan: en güncel plan
if ($selectedPlanId === 0 && !empty($allPlans)) {
    $selectedPlanId = (int)$allPlans[0]['id'];
}

// Seçili planı bul
$workoutPlan = null;
$activePlanId = !empty($allPlans) ? (int)$allPlans[0]['id'] : 0;
$activePlanCreatedAt = !empty($allPlans) ? ($allPlans[0]['created_at'] ?? null) : null;

foreach ($allPlans as $p) {
    if ((int)$p['id'] === $selectedPlanId) {
        $workoutPlan = $p;
        break;
    }
}
if ($workoutPlan === null && !empty($allPlans)) {
    $workoutPlan = $allPlans[0];
    $selectedPlanId = (int)$workoutPlan['id'];
}

// plan_data decode
$decoded = [];
if ($workoutPlan && !empty($workoutPlan['plan_data'])) {
    $decoded = json_decode($workoutPlan['plan_data'], true);
    if (!is_array($decoded)) $decoded = [];
}

/**
 * Egzersiz giflerini tek seferde çek
 */
$gifMap = [];
$gifRes = $conn->query("SELECT egzersiz_ismi, egzersiz_gif FROM egzersizler");
if ($gifRes) {
    while ($row = $gifRes->fetch_assoc()) {
        $gifMap[$row['egzersiz_ismi']] = $row['egzersiz_gif'];
    }
}

/**
 * Helpers
 */
function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function flatten_old_weeks_to_single($exercise){
    // old: {exercise_name, weeks:[{week, sets, volume, reps},...]}
    $weeks = $exercise['weeks'] ?? [];
    $w1 = null;
    if (is_array($weeks)) {
        foreach ($weeks as $w) {
            if ((int)($w['week'] ?? 0) === 1) { $w1 = $w; break; }
        }
        if ($w1 === null && isset($weeks[0]) && is_array($weeks[0])) $w1 = $weeks[0];
    }
    return [
        'exercise_name' => (string)($exercise['exercise_name'] ?? ''),
        'sets'   => (string)($w1['sets'] ?? ''),
        'volume' => (string)($w1['volume'] ?? ''),
        'reps'   => (string)($w1['reps'] ?? ''),
    ];
}

/**
 * ✅ Yeni standart görünüm veri modeli:
 * $days = [
 *   ['day_title'=>'Pazartesi - Üst', 'exercises'=> [ ['exercise_name'=>'Bench', 'sets'=>'4', 'volume'=>'60kg', 'reps'=>'8-10', 'gif'=>'...'], ... ] ],
 *   ...
 * ]
 */
$days = [];
$notes = (string)($decoded['notes'] ?? '');

/**
 * 1) Eğer yeni format ise (version 2 + days)
 */
if (isset($decoded['days']) && is_array($decoded['days'])) {
    foreach ($decoded['days'] as $d) {
        if (!is_array($d)) continue;
        $title = trim((string)($d['day_title'] ?? ''));
        $exs = is_array($d['exercises'] ?? null) ? $d['exercises'] : [];

        $outEx = [];
        foreach ($exs as $ex) {
            if (!is_array($ex)) continue;
            $name = (string)($ex['exercise_name'] ?? '');
            $outEx[] = [
                'exercise_name' => $name,
                'sets'   => (string)($ex['sets'] ?? ''),
                'volume' => (string)($ex['volume'] ?? ''),
                'reps'   => (string)($ex['reps'] ?? ''),
                'gif'    => $gifMap[$name] ?? 'noimage.gif',
            ];
        }

        // tamamen boş günü ekleme
        if ($title === '' && empty($outEx)) continue;

        $days[] = [
            'day_title' => $title !== '' ? $title : ('Gün ' . (count($days) + 1)),
            'exercises' => $outEx,
        ];
    }
} else {
    /**
     * 2) Eski format (push/pull/leg/...) varsa => günlere dönüştür
     * Not: PPL label göstermiyoruz, sadece başlıkları gün/bölüm gibi gösteriyoruz.
     */
    $mapping = [
        ['title_key'=>'push_day_title',     'ex_key'=>'push_exercises',     'fallback'=>'Gün 1'],
        ['title_key'=>'pull_day_title',     'ex_key'=>'pull_exercises',     'fallback'=>'Gün 2'],
        ['title_key'=>'leg_day_title',      'ex_key'=>'leg_exercises',      'fallback'=>'Gün 3'],
        ['title_key'=>'shoulder_day_title', 'ex_key'=>'shoulder_exercises', 'fallback'=>'Gün 4'],
        ['title_key'=>'core_day_title',     'ex_key'=>'core_exercises',     'fallback'=>'Gün 5'],
    ];

    $hasOld = false;
    foreach ($mapping as $m) {
        if (isset($decoded[$m['ex_key']]) || isset($decoded[$m['title_key']])) { $hasOld = true; break; }
    }

    if ($hasOld) {
        foreach ($mapping as $m) {
            $title = trim((string)($decoded[$m['title_key']] ?? ''));
            $exs = is_array($decoded[$m['ex_key']] ?? null) ? $decoded[$m['ex_key']] : [];
            if ($title === '' && empty($exs)) continue;

            $outEx = [];
            foreach ($exs as $ex) {
                if (!is_array($ex)) continue;
                $flat = flatten_old_weeks_to_single($ex);
                $name = $flat['exercise_name'];
                $outEx[] = [
                    'exercise_name' => $name,
                    'sets'   => $flat['sets'],
                    'volume' => $flat['volume'],
                    'reps'   => $flat['reps'],
                    'gif'    => $gifMap[$name] ?? 'noimage.gif',
                ];
            }

            $days[] = [
                'day_title' => $title !== '' ? $title : $m['fallback'],
                'exercises' => $outEx,
            ];
        }
    } else {
        // 3) Çok eski (push/pull title yok) => anahtarlar gün gibi tutulmuş olabilir
        foreach ($decoded as $k => $v) {
            if (!is_array($v)) continue;
            if ($k === 'notes') continue;

            $outEx = [];
            foreach ($v as $ex) {
                if (!is_array($ex)) continue;
                $flat = flatten_old_weeks_to_single($ex);
                $name = $flat['exercise_name'];
                $outEx[] = [
                    'exercise_name' => $name,
                    'sets'   => $flat['sets'],
                    'volume' => $flat['volume'],
                    'reps'   => $flat['reps'],
                    'gif'    => $gifMap[$name] ?? 'noimage.gif',
                ];
            }

            if (empty($outEx)) continue;

            $days[] = [
                'day_title' => $k,
                'exercises' => $outEx,
            ];
        }
    }
}

include "inc/header.php";
?>

<div class="container page-wrap antrenman-page">

  <!-- Üst başlık -->
  <div class="glass-top mb-4">
    <div class="section-split">
      <div>
        <h2 class="page-title">
          🏋️ <?= ($role === "egitmen" || $role === "admin") && $user_id !== $currentUserId ? "Üye Antrenman Geçmişi" : "Antrenman Programım" ?>
          <?= (($role === "egitmen" || $role === "admin") && $targetUserName) ? " <span class=\"app-muted\">(" . h($targetUserName) . ")</span>" : "" ?>
          <?php if (!empty($allPlans)): ?>
            <?php if ($selectedPlanId === $activePlanId): ?>
              <span class="badge text-bg-success ms-2">Aktif Plan</span>
            <?php else: ?>
              <span class="badge text-bg-secondary ms-2">Arşiv</span>
            <?php endif; ?>
          <?php endif; ?>
        </h2>

        <?php if (!empty($allPlans) && count($allPlans) > 1): ?>
          <div class="app-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="fw-semibold">📚 Geçmiş Planlar</div>
              <div class="app-muted small">Toplam: <?= (int)count($allPlans) ?></div>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2">
              <?php foreach ($allPlans as $p): ?>
                <?php $pid = (int)$p['id']; ?>
                <?php
                  $ts = $p['created_at'] ? date('d.m.Y H:i', strtotime($p['created_at'])) : ('Plan #' . $pid);
                ?>
                <a class="btn btn-sm <?= $pid === $selectedPlanId ? 'btn-info' : 'btn-outline-info' ?>"
                   href="antrenman-programim.php?<?= ($role === 'egitmen' || $role === 'admin') ? 'user_id='.(int)$user_id.'&' : '' ?>plan_id=<?= $pid ?>">
                   <?= h($ts) ?>
                   <?php if ($pid === $activePlanId): ?>
                     <span class="badge text-bg-success ms-1">Aktif</span>
                   <?php else: ?>
                     <span class="badge text-bg-secondary ms-1">Arşiv</span>
                   <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="subtle">
          <?= h(trim($user_name . ' ' . $user_surname)) ?>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button id="download-pdf" class="btn btn-primary" type="button">
          <i class="fa-solid fa-file-arrow-down me-2"></i>PDF Olarak İndir
        </button>
      </div>
    </div>
  </div>

  <?php
    $hasAny = false;
    foreach ($days as $d) { if (!empty($d['exercises'])) { $hasAny = true; break; } }
  ?>

  <?php if (!$hasAny): ?>
    <div class="glass-card card-pad">
      <h5 class="m-0 mb-1">Henüz bir antrenman planınız yok.</h5>
      <div class="subtle m-0">Koçunuz plan tanımladığında burada görüntülenecektir.</div>
    </div>
  <?php else: ?>

    <!-- ✅ PPL yok: Gün/Bölüm kartları -->
    <div class="accordion" id="workoutAccordion">
      <?php $idx = 0; ?>
      <?php foreach ($days as $d): ?>
        <?php
          $exercises = $d['exercises'] ?? [];
          if (empty($exercises)) continue;
          $idx++;
          $title = $d['day_title'] ?? ('Gün ' . $idx);
          $collapseId = "collapse-day-" . $idx;
          $headingId  = "heading-day-" . $idx;
        ?>
        <div class="accordion-item glass-card mb-3">
          <h2 class="accordion-header" id="<?= h($headingId) ?>">
            <button
              class="accordion-button <?= ($idx === 1 ? '' : 'collapsed') ?>"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#<?= h($collapseId) ?>"
              aria-expanded="<?= ($idx === 1 ? 'true' : 'false') ?>"
              aria-controls="<?= h($collapseId) ?>"
            >
              <strong class="me-2"><?= h($title) ?></strong>
              <span class="badge rounded-pill text-bg-secondary ms-auto">
                <?= (int)count($exercises) ?> egzersiz
              </span>
            </button>
          </h2>

          <div id="<?= h($collapseId) ?>"
               class="accordion-collapse collapse <?= ($idx === 1 ? 'show' : '') ?>"
               aria-labelledby="<?= h($headingId) ?>"
               data-bs-parent="#workoutAccordion">
            <div class="accordion-body card-pad">

              <?php foreach ($exercises as $exercise): ?>
                <?php
                  $exName = $exercise['exercise_name'] ?? '';
                  $gif    = $exercise['gif'] ?? 'noimage.gif';
                  $sets   = $exercise['sets'] ?? '';
                  $vol    = $exercise['volume'] ?? '';
                  $reps   = $exercise['reps'] ?? '';
                ?>

                <div class="glass-card exercise-card mb-3">
                  <div class="card-pad">
                    <div class="row g-3 align-items-start">
                      <div class="col-lg-8">
                        <div class="exercise-title mb-2"><?= h($exName) ?></div>

                        <!-- ✅ 6 hafta yok: tek satır -->
                        <div class="table-container">
                          <table class="table table-hover align-middle mb-0">
                            <thead>
                              <tr>
                                <th style="min-width:110px;">Set</th>
                                <th>Hacim</th>
                                <th>Tekrar</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td><?= h($sets) !== '' ? h($sets) : '—' ?></td>
                                <td><?= h($vol)  !== '' ? h($vol)  : '—' ?></td>
                                <td><?= h($reps) !== '' ? h($reps) : '—' ?></td>
                              </tr>
                            </tbody>
                          </table>
                        </div>

                        <div class="totals-bar mt-3">
                          <div class="totals-item"><div class="label">Gün/Bölüm</div><div class="val"><?= h($title) ?></div></div>
                          <div class="totals-item"><div class="label">Egzersiz</div><div class="val"><?= h($exName) ?></div></div>
                        </div>
                      </div>

                      <div class="col-lg-4">
                        <div class="subtle mb-2">Egzersiz GIF</div>
                        <div class="gif-wrap">
                          <img
                            src="<?= h($gif) ?>"
                            alt="<?= h($exName) ?>"
                            onerror="this.src='noimage.gif';"
                          >
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              <?php endforeach; ?>

            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty(trim($notes))): ?>
      <div class="glass-card card-pad mt-4">
        <div class="section-split" style="margin-bottom:10px;">
          <h5 class="m-0">📝 Notlar</h5>
          <div class="subtle m-0">Koçunuzun eklediği açıklamalar</div>
        </div>
        <div><?= nl2br(h($notes)) ?></div>
      </div>
    <?php endif; ?>

    <!-- PDF için gizli tablo (✅ yeni format: tek satır) -->
    <div style="position:absolute; left:-99999px; top:-99999px;">
      <table class="table table-bordered" id="pdf-table">
        <thead>
          <tr>
            <th>Gün/Bölüm</th>
            <th>Egzersiz</th>
            <th>Set</th>
            <th>Hacim</th>
            <th>Tekrar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($days as $d): ?>
            <?php if (empty($d['exercises'])) continue; ?>
            <?php $dayTitle = $d['day_title'] ?? ''; ?>
            <?php foreach ($d['exercises'] as $exercise): ?>
              <tr>
                <td><?= h($dayTitle) ?></td>
                <td><?= h($exercise['exercise_name'] ?? '') ?></td>
                <td><?= h($exercise['sets'] ?? '') ?></td>
                <td><?= h($exercise['volume'] ?? '') ?></td>
                <td><?= h($exercise['reps'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>

<?php include "inc/footer.php"; ?>

<!-- jsPDF + autoTable -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.5.31/dist/jspdf.plugin.autotable.min.js"></script>

<script>
  document.getElementById('download-pdf')?.addEventListener('click', function(){
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });

    const table = document.getElementById('pdf-table');
    doc.autoTable({
      html: table,
      styles: { fontSize: 9 },
      headStyles: { fillColor: [40, 40, 40] },
      margin: { top: 12, left: 8, right: 8 }
    });

    doc.save('antrenman_programim.pdf');
  });
</script>