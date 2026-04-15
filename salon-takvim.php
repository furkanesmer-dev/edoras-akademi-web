<?php
// salon-takvim.php

if (session_status() === PHP_SESSION_NONE) session_start();

// --- DB include (robust) ---
$__db_loaded = false;
foreach ([__DIR__.'/inc/db.php', __DIR__.'/db.php', __DIR__.'/baglanti.php', __DIR__.'/config/db.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__db_loaded = true; break; }
}
if (!$__db_loaded) { http_response_code(500); exit('DB bağlantı dosyası bulunamadı.'); }
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (isset($mysqli) && ($mysqli instanceof mysqli)) $conn = $mysqli;
}
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('DB bağlantısı ($conn) bulunamadı.'); }

$user  = $_SESSION['user'] ?? [];
$yetki = $user['yetki'] ?? 'kullanici';

// Admin guard (AJAX dahil)
if ($yetki !== 'admin') {
  if (isset($_GET['ajax']) && $_GET['ajax'] === 'events') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
  }
  // normal sayfa
  include "inc/header.php";
  echo "<div class='p-4 app-card'><h4>⛔ Yetkisiz</h4><div class='app-muted'>Bu sayfa sadece admin içindir.</div></div>";
  include "inc/footer.php";
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function colorFromId($id){
  $palette = ["#2563eb","#16a34a","#dc2626","#7c3aed","#ea580c","#0ea5e9","#22c55e","#f43f5e","#a855f7","#14b8a6"];
  return $palette[((int)$id) % count($palette)];
}

// ======================================================
// AJAX: events (SADECE JSON, header/footer yok!)
// ======================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'events') {
  header('Content-Type: application/json; charset=utf-8');

  $start = $_GET['start'] ?? null; // ISO
  $end   = $_GET['end'] ?? null;   // ISO
  $egitmen_id = (isset($_GET['egitmen_id']) && $_GET['egitmen_id'] !== '') ? (int)$_GET['egitmen_id'] : null;

  if (!$start || !$end) { echo json_encode([]); exit; }

  // ISO -> "YYYY-MM-DD HH:MM:SS" (timezone kısmını at)
  $start_dt = substr(str_replace('T',' ', $start), 0, 19);
  $end_dt   = substr(str_replace('T',' ', $end), 0, 19);

  $sql = "
    SELECT
      so.id,
      so.egitmen_id,
      so.uye_id,
      so.baslik,
      so.notlar,
      so.seans_tarih_saat,
      so.sure_dk,
      so.durum,
      CONCAT(e.ad,' ',e.soyad) AS egitmen_adsoyad,
      CONCAT(u.ad,' ',u.soyad) AS uye_adsoyad
    FROM seans_ornekleri so
    LEFT JOIN uye_kullanicilar e ON e.id = so.egitmen_id
    LEFT JOIN uye_kullanicilar u ON u.id = so.uye_id
    WHERE so.seans_tarih_saat >= ?
      AND so.seans_tarih_saat < ?
  ";
  if ($egitmen_id) $sql .= " AND so.egitmen_id = ? ";
  $sql .= " ORDER BY so.seans_tarih_saat ASC ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) { echo json_encode([]); exit; }

  if ($egitmen_id) $stmt->bind_param("ssi", $start_dt, $end_dt, $egitmen_id);
  else $stmt->bind_param("ss", $start_dt, $end_dt);

  $stmt->execute();
  $res = $stmt->get_result();

  $events = [];
  while ($r = $res->fetch_assoc()) {
    $startTime = $r['seans_tarih_saat'];
    $sure = (int)($r['sure_dk'] ?? 60);
    $endTime = date('Y-m-d H:i:s', strtotime($startTime . " +{$sure} minutes"));

    $egName = $r['egitmen_adsoyad'] ?: ('Eğitmen #' . (int)$r['egitmen_id']);
    $uyeName = $r['uye_adsoyad'] ?: ('Üye #' . (int)$r['uye_id']);

    $durum = $r['durum'] ?? 'planned';
    $statusEmoji = match($durum){
      'done' => '✅',
      'canceled' => '⛔',
      'no_show' => '🚫',
      default => '📌'
    };

    $title = "{$statusEmoji} {$egName} • {$uyeName} • " . ($r['baslik'] ?? 'Seans');

    $col = colorFromId((int)$r['egitmen_id']);

    $events[] = [
      "id" => (int)$r['id'],
      "title" => $title,
      "start" => str_replace(' ', 'T', $startTime),
      "end" => str_replace(' ', 'T', $endTime),
      "backgroundColor" => $col,
      "borderColor" => $col,
      "extendedProps" => [
        "egitmen_id" => (int)$r['egitmen_id'],
        "egitmen" => $egName,
        "uye_id" => (int)$r['uye_id'],
        "uye" => $uyeName,
        "notlar" => $r['notlar'] ?? '',
        "sure_dk" => $sure,
        "durum" => $durum
      ]
    ];
  }
  $stmt->close();

  echo json_encode($events, JSON_UNESCAPED_UNICODE);
  exit;
}

// ======================================================
// Normal sayfa render
// ======================================================
include "inc/header.php";

// Eğitmen listesi (filter)
$egitmenler = [];
$q = $conn->query("SELECT id, ad, soyad FROM uye_kullanicilar WHERE yetki='egitmen' ORDER BY ad ASC, soyad ASC");
while ($q && ($r = $q->fetch_assoc())) $egitmenler[] = $r;
?>

<!-- FullCalendar -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<!-- ✅ Locale paketini ekliyoruz -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales-all.global.min.js"></script>

<style>
  body.page-salon-takvim{
    background: var(--app-bg, #f6f7fb);
    color: var(--app-text, #111827);
  }
  body.page-salon-takvim{
    --dash-card:#ffffff;
    --dash-border: rgba(17,24,39,.10);
    --dash-muted: rgba(17,24,39,.60);
    --dash-soft: rgba(17,24,39,.04);
    --dash-shadow: 0 10px 30px rgba(17,24,39,.08);
    --dash-radius: 18px;
  }
  body.page-salon-takvim .app-card{
    background: var(--dash-card);
    border: 1px solid var(--dash-border);
    border-radius: var(--dash-radius);
    box-shadow: var(--dash-shadow);
  }
  body.page-salon-takvim .app-muted{ color: var(--dash-muted) !important; }
  body.page-salon-takvim .page-wrap{ max-width: 1180px; margin: 0 auto; }
  body.page-salon-takvim h1{ font-weight: 800; letter-spacing: -0.02em; }

  body.page-salon-takvim .toolbar{
    display:flex; gap:12px; flex-wrap: wrap;
    align-items: center; justify-content: space-between;
  }
  body.page-salon-takvim .toolbar .left{
    display:flex; gap:10px; flex-wrap: wrap; align-items: center;
  }
  body.page-salon-takvim .chip{
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

  body.page-salon-takvim .fc{
    --fc-border-color: rgba(17,24,39,.10);
    --fc-page-bg-color: transparent;
    --fc-today-bg-color: rgba(37,99,235,.08);
    color: rgba(17,24,39,.90);
  }
  body.page-salon-takvim .fc .fc-toolbar-title{
    font-size: 1.05rem;
    font-weight: 900;
    letter-spacing: -0.01em;
  }
  body.page-salon-takvim .fc .fc-button{
    border-radius: 12px;
    border: 1px solid rgba(17,24,39,.14);
    background: #fff;
    color: rgba(17,24,39,.90);
    font-weight: 800;
  }
  body.page-salon-takvim .fc .fc-button:hover{ background: rgba(17,24,39,.03); }
  body.page-salon-takvim .fc .fc-button-primary:not(:disabled).fc-button-active{
    background: rgba(17,24,39,.08);
    border-color: rgba(17,24,39,.18);
    color: rgba(17,24,39,.95);
  }
  body.page-salon-takvim .fc-event{
    border-radius: 10px;
    border-width: 1px;
    padding: 2px 6px;
    font-weight: 800;
  }

  @media (max-width: 576px){
    body.page-salon-takvim h1{ font-size: 1.35rem; }
    body.page-salon-takvim .chip{ width: 100%; justify-content:center; }
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.body.classList.add('page-salon-takvim');
});
</script>

<div class="page-wrap">

  <div class="app-card p-3 p-lg-4 mb-3">
    <div class="toolbar">
      <div>
        <h1 class="mb-1">Salon Takvimi</h1>
        <div class="app-muted small">Tüm eğitmenlerin seansları tek ekranda</div>
      </div>

      <div class="left">
        <span class="chip"><i class="fa-regular fa-calendar"></i> Görünüm: Takvim</span>

        <div class="d-flex align-items-center gap-2">
          <label class="fw-bold small mb-0">Eğitmen:</label>
          <select id="egitmenFilter" class="form-select form-select-sm" style="min-width:240px;">
            <option value="">Tümü</option>
            <?php foreach ($egitmenler as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= h($e['ad'].' '.$e['soyad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button id="btnRefresh" class="btn btn-sm btn-outline-primary">
          <i class="fa-solid fa-rotate me-1"></i> Yenile
        </button>
      </div>
    </div>
  </div>

  <div class="app-card p-3 p-lg-4">
    <div id="calendar"></div>
  </div>

</div>

<!-- Seans Detay Modal -->
<div class="modal fade" id="seansDetayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:18px; border:1px solid rgba(17,24,39,.12); box-shadow:0 18px 50px rgba(17,24,39,.18);">
      <div class="modal-header" style="border-bottom:1px solid rgba(17,24,39,.10);">
        <h5 class="modal-title fw-bold" id="seansTitle">Seans</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="fw-bold">Eğitmen</div>
            <div class="app-muted" id="seansEgitmen">-</div>
          </div>
          <div class="col-12 col-md-6">
            <div class="fw-bold">Üye</div>
            <div class="app-muted" id="seansUye">-</div>
          </div>

          <div class="col-12 col-md-6">
            <div class="fw-bold">Başlangıç</div>
            <div class="app-muted" id="seansStart">-</div>
          </div>
          <div class="col-12 col-md-6">
            <div class="fw-bold">Süre</div>
            <div class="app-muted" id="seansSure">-</div>
          </div>

          <div class="col-12">
            <div class="fw-bold">Notlar</div>
            <div class="app-muted" id="seansNotlar" style="white-space:pre-wrap;">-</div>
          </div>

          <div class="col-12">
            <div class="fw-bold">Durum</div>
            <div class="app-muted" id="seansDurum">-</div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Kapat</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

  const filterEl = document.getElementById('egitmenFilter');
  const btnRefresh = document.getElementById('btnRefresh');

  function buildEventsUrl(info) {
    const params = new URLSearchParams();
    params.set('ajax', 'events');
    params.set('start', info.startStr);
    params.set('end', info.endStr);
    if (filterEl.value) params.set('egitmen_id', filterEl.value);
    return 'salon-takvim.php?' + params.toString();
  }

  const calendarEl = document.getElementById('calendar');

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'timeGridWeek',
    height: 'auto',
    nowIndicator: true,
    firstDay: 1,
    timeZone: 'local',
    locale: 'tr',

    // ✅ İngilizce kalan butonlar için garanti Türkçe
    buttonText: {
      today: 'Bugün',
      month: 'Ay',
      week: 'Hafta',
      day: 'Gün',
      list: 'Liste'
    },

    slotMinTime: "06:00:00",
    slotMaxTime: "23:59:00",
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'timeGridWeek,dayGridMonth,listWeek'
    },

    events: function(info, successCallback, failureCallback) {
      fetch(buildEventsUrl(info), { credentials: 'same-origin' })
        .then(r => r.text())
        .then(txt => {
          // Debug: JSON değilse gör
          try {
            const data = JSON.parse(txt);
            successCallback(data);
          } catch (e) {
            console.error("Takvim JSON parse edemedi. Dönen cevap:", txt.slice(0, 500));
            failureCallback(e);
          }
        })
        .catch(err => {
          console.error("Takvim events fetch hata:", err);
          failureCallback(err);
        });
    },

    eventClick: function(arg) {
      const ev = arg.event;

      document.getElementById('seansTitle').textContent = ev.title || 'Seans';
      document.getElementById('seansEgitmen').textContent = ev.extendedProps.egitmen || '-';
      document.getElementById('seansUye').textContent = ev.extendedProps.uye || '-';

      const start = ev.start ? ev.start.toLocaleString('tr-TR') : '-';
      document.getElementById('seansStart').textContent = start;

      document.getElementById('seansSure').textContent = (ev.extendedProps.sure_dk || 60) + ' dk';
      document.getElementById('seansNotlar').textContent = ev.extendedProps.notlar || '-';
      document.getElementById('seansDurum').textContent = ev.extendedProps.durum || '-';

      const m = new bootstrap.Modal(document.getElementById('seansDetayModal'));
      m.show();
    }
  });

  calendar.render();

  filterEl.addEventListener('change', () => calendar.refetchEvents());
  btnRefresh.addEventListener('click', () => calendar.refetchEvents());

});
</script>

<?php include "inc/footer.php"; ?>
