<?php
require_once __DIR__ . '/inc/security.php';
configure_error_reporting();

// Oturum ve rol kontrolü: sadece egitmen veya admin erişebilir
$user     = require_session(['egitmen', 'admin']);
$selfId   = (int)($user['id'] ?? 0);
$selfRole = (string)($user['yetki'] ?? 'kullanici');

require_once __DIR__ . '/inc/db.php';

// Üye kullanıcıları çek — egitmen yalnızca kendi üyelerini görür
if ($selfRole === 'admin') {
    $sql = "SELECT id, ad, soyad FROM uye_kullanicilar WHERE yetki='kullanici' ORDER BY ad, soyad";
    $result = $conn->query($sql);
} else {
    $stmt = $conn->prepare("SELECT id, ad, soyad FROM uye_kullanicilar WHERE yetki='kullanici' AND egitmen_id=? ORDER BY ad, soyad");
    $stmt->bind_param("i", $selfId);
    $stmt->execute();
    $result = $stmt->get_result();
}

include "inc/header.php";
?>

<div class="antrenman-page">

  <!-- Üst Başlık -->
  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
    <div>
      <h1 class="mb-1">Antrenman Planı</h1>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <span class="badge rounded-pill text-bg-secondary">Sade Plan</span>
      <span class="badge rounded-pill text-bg-secondary">Haftalık tekrar yok</span>
    </div>
  </div>

  <!-- Üye Seçimi + Plan Adı -->
  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="p-3 p-lg-4 antrenman-card">

        <div class="row g-3 align-items-end">
          <div class="col-12 col-lg-6">
            <label for="user" class="form-label mb-2">Üye Kullanıcı Seçin</label>
            <select name="user" id="user" class="form-control" required>
              <option value="">Seçiniz</option>
              <?php if ($result && $result->num_rows > 0) : ?>
                <?php while($row = $result->fetch_assoc()) : ?>
                  <option value="<?php echo (int)$row['id']; ?>">
                    #<?php echo (int)$row['id']; ?> - <?php echo htmlspecialchars($row['ad'] ?? '', ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($row['soyad'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endwhile; ?>
              <?php else: ?>
                <option value="">Üye bulunamadı</option>
              <?php endif; ?>
            </select>

            <div class="mt-3">
              <label for="planName" class="form-label mb-1">Plan Adı (opsiyonel)</label>
              <input type="text" id="planName" class="form-control" placeholder="Örn: 4 Hafta Genel Kuvvet - V1" maxlength="120">
              <div class="form-text">Bu alan geçmiş plan listesinde başlık olarak gösterilir.</div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="small text-muted">
              Üye seçtiğinde daha önce kaydedilmiş son plan otomatik olarak yüklenecektir.
            </div>
            <div class="mt-3 d-flex gap-2 justify-content-lg-end">
              <button class="btn btn-outline-secondary btn-sm" type="button" id="btnClearAll">Temizle</button>
              <button class="btn btn-primary btn-sm" type="button" id="btnAddDay">+ Gün/Bölüm Ekle</button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- GÜNLER ALANI -->
  <div id="daysContainer"></div>

  <!-- NOTLAR + AKSİYONLAR -->
  <div class="notes-section mt-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-2">
      <h5 class="mb-0">Notlar</h5>
      <div class="small text-muted">Programla ilgili açıklama / uyarı / ipuçları</div>
    </div>

    <textarea class="form-control notes-textarea" id="notes" placeholder="Yazmak istediğiniz notları buraya ekleyebilirsiniz..."></textarea>

    <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
      <button class="save-btn" type="button" onclick="saveWorkoutPlan()">Kaydet</button>
      <button class="save-btn" type="button" onclick="generateJSON()">JSON İndir</button>
    </div>
  </div>

</div>

<script>
/**
 * Temaya uyum için sayfaya küçük bir CSS ekliyoruz (inc/header.php dokunmadan).
 */
(function injectWorkoutCss(){
  try {
    const href = '/css/antrenman.css?v=2';
    if (![...document.querySelectorAll('link[rel="stylesheet"]')].some(l => (l.getAttribute('href') || '').includes('/css/antrenman.css'))) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      document.head.appendChild(link);
    }

    if (!document.getElementById('wk-inline-style')) {
      const st = document.createElement('style');
      st.id = 'wk-inline-style';
      st.textContent = `
        .day-card { border-radius: 16px; }
        .day-header { gap: 12px; }
        .day-actions .btn { border-radius: 12px; }
        .wk-table { overflow-x:auto; }
        .wk-table table { min-width: 920px; }
        .wk-table th { white-space: nowrap; }
        .wk-row-actions { width: 1%; white-space: nowrap; }
        .ghost-input { background: rgba(255,255,255,.65); }
        .mini-help { font-size: 12px; color: rgba(0,0,0,.55); }
        .danger-link { color:#dc3545; cursor:pointer; text-decoration:none; }
        .danger-link:hover { text-decoration: underline; }
      `;
      document.head.appendChild(st);
    }
  } catch(e) {}
})();

const daysContainer = document.getElementById('daysContainer');

function uid(prefix='id'){
  return prefix + '_' + Math.random().toString(36).slice(2, 10);
}

function escapeHtml(str){
  return (str ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

/**
 * Gün/Bölüm kartı oluşturur
 */
function createDayCard(dayTitle = ''){
  const tbodyId = uid('tbody');

  const card = document.createElement('div');
  card.className = 'mb-4';
  card.dataset.dayId = uid('day');

  card.innerHTML = `
    <div class="p-3 p-lg-4 antrenman-card day-card">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between day-header mb-3">
        <div class="flex-grow-1">
          <label class="form-label mb-2">Gün / Bölüm Başlığı</label>
          <input type="text" class="form-control day-title-input" value="${escapeHtml(dayTitle)}"
                 placeholder="Örn: Pazartesi - Üst Vücut / Full Body / Koşu + Core" />
          <div class="mini-help mt-2">İstediğin isimlendirmeyi kullan. Push/Pull/Leg zorunluluğu yok.</div>
        </div>

        <div class="day-actions d-flex gap-2 justify-content-lg-end mt-2 mt-lg-0">
          <button class="btn btn-outline-secondary btn-sm" type="button" data-action="add-exercise" data-tbody="${tbodyId}">+ Egzersiz</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" data-action="duplicate-day">Kopyala</button>
          <button class="btn btn-outline-danger btn-sm" type="button" data-action="remove-day">Sil</button>
        </div>
      </div>

      <div class="wk-table app-table table-container">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th class="exercise-column">Egzersiz</th>
              <th>Set</th>
              <th>Hacim</th>
              <th>Tekrar</th>
              <th class="wk-row-actions">İşlem</th>
            </tr>
          </thead>
          <tbody id="${tbodyId}">
            <!-- rows -->
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-3">
        Her egzersiz için <strong>tek satır</strong> gir: Set / Hacim / Tekrar.
      </div>
    </div>
  `;

  daysContainer.appendChild(card);
  addExerciseRow(tbodyId); // başlangıçta 1 satır
}

/**
 * Egzersiz satırı ekler
 */
function addExerciseRow(tbodyId, preset = null){
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;

  const exName = preset?.exercise_name ?? '';
  const sets   = preset?.sets ?? '';
  const volume = preset?.volume ?? '';
  const reps   = preset?.reps ?? '';

  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <input type="text" class="form-control exercise-input" placeholder="Egzersiz seç / yaz"
             value="${escapeHtml(exName)}" onclick="loadExercises(this)">
    </td>
    <td><input type="text" class="form-control ghost-input" placeholder="Örn: 4" value="${escapeHtml(sets)}"></td>
    <td><input type="text" class="form-control ghost-input" placeholder="Örn: 60 kg / RPE 7 / Tempo" value="${escapeHtml(volume)}"></td>
    <td><input type="text" class="form-control ghost-input" placeholder="Örn: 8-10" value="${escapeHtml(reps)}"></td>
    <td class="wk-row-actions">
      <a class="danger-link" data-action="remove-row">Sil</a>
    </td>
  `;
  tbody.appendChild(tr);
}

/**
 * Günleri topla -> JSON
 */
function collectDays(){
  const dayCards = [...daysContainer.querySelectorAll('[data-day-id]')];
  const days = [];

  dayCards.forEach(card => {
    const title = (card.querySelector('.day-title-input')?.value || '').trim();

    const tbody = card.querySelector('tbody');
    const rows = tbody ? [...tbody.querySelectorAll('tr')] : [];
    const exercises = [];

    rows.forEach(r => {
      const tds = r.querySelectorAll('td');
      const name = tds?.[0]?.querySelector('input')?.value?.trim() || '';
      const sets = tds?.[1]?.querySelector('input')?.value?.trim() || '';
      const volume = tds?.[2]?.querySelector('input')?.value?.trim() || '';
      const reps = tds?.[3]?.querySelector('input')?.value?.trim() || '';

      if (!name && !sets && !volume && !reps) return;

      exercises.push({ exercise_name: name, sets, volume, reps });
    });

    if (!title && exercises.length === 0) return;

    days.push({ day_title: title, exercises });
  });

  return days;
}

/**
 * Eski format (push/pull/leg...) varsa yeni days formatına çevir
 */
function normalizeToDays(data){
  if (Array.isArray(data?.days)) return data.days;

  const mapping = [
    { titleKey:'push_day_title', exKey:'push_exercises', fallback:'Gün 1' },
    { titleKey:'pull_day_title', exKey:'pull_exercises', fallback:'Gün 2' },
    { titleKey:'leg_day_title', exKey:'leg_exercises', fallback:'Gün 3' },
    { titleKey:'shoulder_day_title', exKey:'shoulder_exercises', fallback:'Gün 4' },
    { titleKey:'core_day_title', exKey:'core_exercises', fallback:'Gün 5' },
  ];

  const out = [];
  mapping.forEach(m => {
    const title = (data?.[m.titleKey] || '').trim();
    const exs = Array.isArray(data?.[m.exKey]) ? data[m.exKey] : [];
    if (!title && exs.length === 0) return;

    const exercises = exs.map(e => {
      // old weeks[] -> 1. hafta
      const w1 = Array.isArray(e?.weeks) ? (e.weeks.find(x => (x?.week|0) === 1) || e.weeks[0] || {}) : {};
      return {
        exercise_name: e?.exercise_name ?? '',
        sets: w1?.sets ?? '',
        volume: w1?.volume ?? '',
        reps: w1?.reps ?? ''
      };
    });

    out.push({ day_title: title || m.fallback, exercises });
  });

  return out;
}

/**
 * UI'yı günlere göre doldur
 */
function renderDays(days){
  daysContainer.innerHTML = '';

  if (!Array.isArray(days) || days.length === 0) {
    createDayCard('');
    return;
  }

  days.forEach(d => {
    createDayCard(d.day_title || '');
    const lastCard = daysContainer.lastElementChild;
    const tbody = lastCard.querySelector('tbody');
    tbody.innerHTML = '';
    (d.exercises || []).forEach(ex => addExerciseRow(tbody.id, ex));
    if ((d.exercises || []).length === 0) addExerciseRow(tbody.id);
  });
}

/**
 * JSON indir
 */
function generateJSON() {
  const userId = document.getElementById('user').value;
  const notes = document.getElementById('notes').value;

  const data = {
    version: 2,
    plan_name: (document.getElementById('planName')?.value || '').trim(),
    user_id: userId,
    days: collectDays(),
    notes: notes
  };

  const jsonString = JSON.stringify(data, null, 2);
  const blob = new Blob([jsonString], { type: 'application/json' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'workout_plan.json';
  link.click();
}

/**
 * Kaydet
 */
function saveWorkoutPlan() {
  const userId = document.getElementById('user').value;
  if (!userId) { alert('Lütfen üye seçin.'); return; }

  const data = {
    version: 2,
    plan_name: (document.getElementById('planName')?.value || '').trim(),
    user_id: userId,
    days: collectDays(),
    notes: document.getElementById('notes').value
  };

  if (!data.days || data.days.length === 0) {
    alert('En az bir gün/bölüm ekleyin.');
    return;
  }

  const jsonString = JSON.stringify(data);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'antremanekle.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

  xhr.onload = function () {
    // ✅ antremanekle.php JSON dönüyor (ok/msg/id)
    let resp = null;
    try { resp = JSON.parse(xhr.responseText || '{}'); } catch(e) { resp = null; }

    if (xhr.status === 200 && resp && resp.ok) {
      alert('Antrenman planı başarıyla kaydedildi!');
    } else {
      const msg = resp?.msg || xhr.responseText || ('Hata (' + xhr.status + ')');
      alert('Hata: ' + msg);
      console.log('SAVE_ERR_STATUS', xhr.status);
      console.log('SAVE_ERR_BODY', xhr.responseText);
    }
  };

  xhr.onerror = function(){
    alert('İstek gönderilemedi (network/path). Console kontrol et.');
  };

  xhr.send('json_data=' + encodeURIComponent(jsonString) + '&user_id=' + encodeURIComponent(userId));
}

/**
 * Event delegation: gün/satır butonları
 */
daysContainer.addEventListener('click', function(e){
  const btn = e.target.closest('[data-action]');
  if (!btn) return;

  const action = btn.getAttribute('data-action');

  if (action === 'add-exercise') {
    const tbodyId = btn.getAttribute('data-tbody');
    addExerciseRow(tbodyId);
  }

  if (action === 'remove-day') {
    const card = btn.closest('[data-day-id]');
    if (!card) return;
    if (confirm('Bu gün/bölüm silinsin mi?')) card.remove();
    if (daysContainer.querySelectorAll('[data-day-id]').length === 0) createDayCard('');
  }

  if (action === 'duplicate-day') {
    const card = btn.closest('[data-day-id]');
    if (!card) return;

    const title = card.querySelector('.day-title-input')?.value || '';
    const tbody = card.querySelector('tbody');
    const rows = tbody ? [...tbody.querySelectorAll('tr')] : [];

    const exercises = rows.map(r => {
      const tds = r.querySelectorAll('td');
      return {
        exercise_name: tds?.[0]?.querySelector('input')?.value?.trim() || '',
        sets: tds?.[1]?.querySelector('input')?.value?.trim() || '',
        volume: tds?.[2]?.querySelector('input')?.value?.trim() || '',
        reps: tds?.[3]?.querySelector('input')?.value?.trim() || '',
      };
    }).filter(x => x.exercise_name || x.sets || x.volume || x.reps);

    createDayCard((title || 'Kopya Gün') + ' (Kopya)');
    const lastCard = daysContainer.lastElementChild;
    const newTbody = lastCard.querySelector('tbody');
    newTbody.innerHTML = '';
    exercises.forEach(ex => addExerciseRow(newTbody.id, ex));
    if (exercises.length === 0) addExerciseRow(newTbody.id);
  }

  if (action === 'remove-row') {
    const tr = btn.closest('tr');
    if (tr) tr.remove();
  }
});

/**
 * Üst butonlar
 */
document.getElementById('btnAddDay').addEventListener('click', () => createDayCard(''));
document.getElementById('btnClearAll').addEventListener('click', () => {
  if (!confirm('Tüm alanlar temizlensin mi?')) return;
  document.getElementById('planName').value = '';
  document.getElementById('notes').value = '';
  daysContainer.innerHTML = '';
  createDayCard('');
});

/**
 * Üye seçince son planı yükle
 */
document.getElementById('user').addEventListener('change', function () {
  const userId = this.value;
  if (!userId) return;

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'get_workout_plan.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

  xhr.onload = function () {
    if (xhr.status !== 200) {
      console.log('LOAD_ERR_STATUS', xhr.status, xhr.responseText);
      return;
    }

    try {
      const data = JSON.parse(xhr.responseText || '{}');

      // get_workout_plan.php hata dönebilir
      if (data && data.ok === false) {
        alert('Plan yüklenemedi: ' + (data.msg || 'Bilinmeyen hata'));
        return;
      }

      if (document.getElementById('planName')) {
        document.getElementById('planName').value = (data.plan_name || '').trim();
      }

      document.getElementById('notes').value = data.notes || '';

      const days = normalizeToDays(data);
      renderDays(days);

    } catch (e) {
      console.error('Geçersiz JSON verisi:', xhr.responseText);
    }
  };

  xhr.send('user_id=' + encodeURIComponent(userId));
});

/**
 * Egzersiz autocomplete
 */
function loadExercises(inputElement) {
  const dataListId = 'datalist-' + Math.random().toString(36).substr(2, 9);
  inputElement.setAttribute('list', dataListId);

  const dataList = document.createElement('datalist');
  dataList.id = dataListId;
  document.body.appendChild(dataList);

  const xhr = new XMLHttpRequest();
  xhr.open('GET', 'fetch_exercises.php', true);
  xhr.onload = function () {
    if (xhr.status === 200) {
      try {
        const exercises = JSON.parse(xhr.responseText);
        dataList.innerHTML = '';
        exercises.forEach(function(exercise) {
          const option = document.createElement('option');
          option.value = exercise;
          dataList.appendChild(option);
        });
      } catch(e){
        console.error('fetch_exercises.php JSON parse hatası');
      }
    } else {
      console.error('Veri çekme işlemi başarısız oldu:', xhr.statusText);
    }
  };
  xhr.send();
}

// Sayfa açılışında en az 1 gün
document.addEventListener('DOMContentLoaded', function(){
  if (daysContainer.children.length === 0) createDayCard('');
});

// Oluşturulan datalist'leri temizle
window.addEventListener('load', () => {
  document.querySelectorAll('datalist').forEach(dl => dl.remove());
});
</script>

<?php include "inc/footer.php"; ?>