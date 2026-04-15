<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit; }

// Header include (robust)
$__hdr_loaded = false;
foreach ([__DIR__.'/header.php', __DIR__.'/inc/header.php', __DIR__.'/partials/header.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__hdr_loaded = true; break; }
}

// Eğer header yoksa, sayfa yine de çalışsın:
if (!$__hdr_loaded) {
  echo "<!doctype html><html lang='tr'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Eğitmen Takvim</title>";
}
?>
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- FullCalendar -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f6f7fb; --card:#ffffff; --text:#0f172a; --muted:#64748b;
      --border:rgba(15,23,42,.10); --shadow:0 12px 30px rgba(15,23,42,.08); --radius:18px;
    }
    body{background:var(--bg); color:var(--text);}
    .page-wrap{max-width:1200px; margin:0 auto; padding:22px 14px;}
    .topbar{background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:var(--shadow); padding:14px; display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;}
    .h-title{font-size:1.15rem; font-weight:900; letter-spacing:.2px;}
    .sub{color:var(--muted); font-size:.92rem;}
    .glass{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow);}
    .panel{padding:14px;}
    .btn-primary{border-radius:14px; padding:.62rem .95rem; font-weight:800;}
    .btn-soft{border-radius:14px; font-weight:800;}
    .form-select,.form-control{border-radius:14px; border-color:var(--border); padding:.62rem .9rem;}
    #calendar .fc{--fc-border-color: rgba(15,23,42,.12); --fc-page-bg-color: transparent; --fc-neutral-bg-color: rgba(15,23,42,.03); --fc-today-bg-color: rgba(56,189,248,.10);}
    .fc .fc-toolbar-title{font-size:1.05rem; font-weight:900;}
    .fc .fc-button{border-radius:12px; font-weight:800;}
    .fc .fc-scrollgrid{border-radius:16px; overflow:hidden;}
    .modal-content{border-radius:18px; border:1px solid var(--border); box-shadow:var(--shadow);}
    .pill{display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--border); border-radius:999px; background:#fff;}
    .day-chip{display:inline-flex; align-items:center; gap:8px; margin:4px 8px 0 0;}
    .day-chip input{width:18px; height:18px;}
    .status-badge{font-size:.78rem; padding:.25rem .55rem; border-radius:999px; border:1px solid var(--border); background:#fff; color:var(--muted); font-weight:800;}
  </style>

<?php if (!$__hdr_loaded): ?>
  </head><body class="light-theme page-egitmen-takvim">
<?php endif; ?>

<div class="page-wrap">

  <div class="topbar">
    <div>
      <div class="h-title">Takvim</div>
      <div class="sub">Seans oluştur, haftalık tekrar planla, seanslarını görüntüle ve durumlarını yönet.</div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
      <div class="pill">
        <span class="text-muted small fw-bold">Üye</span>
        <select id="uyeFilter" class="form-select form-select-sm" style="min-width:240px"></select>
      </div>
      <div id="uyeOzet" class="pill" style="display:none;">
        <span class="text-muted small fw-bold">Abonelik</span>
        <span id="uyeOzetText" class="small fw-bold"></span>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#seansModal">Seans Oluştur</button>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-lg-9">
      <div class="glass panel">
        <div id="calendar"></div>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="glass panel">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-bold">Seçili Gün</div>
          <span id="dayCount" class="status-badge">0</span>
        </div>
        <div id="selectedDay" class="text-muted small mb-2">Bir gün seç.</div>
        <div id="dayList" class="d-grid gap-2"></div>
      </div>
    </div>
  </div>

</div>

<!-- Seans Oluştur Modal -->
<div class="modal fade" id="seansModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="fw-bold" style="font-size:1.05rem;">Seans Oluştur</div>
          <div class="text-muted small">Tek seans veya haftalık tekrar (Pzt/Çar/Cuma…) planla.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Üye</label>
            <select id="uyeSelect" class="form-select"></select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Başlık</label>
            <input id="baslik" class="form-control" value="PT Seansı">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Başlangıç Tarihi</label>
            <input id="baslangic_tarihi" type="date" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Saat</label>
            <input id="saat" type="time" class="form-control" value="18:00">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Süre (dk)</label>
            <input id="sure_dk" type="number" class="form-control" value="60" min="15" max="600">
          </div>

          <div class="col-12">
            <label class="form-label fw-semibold">Tekrar</label>
            <select id="tekrar" class="form-select">
              <option value="none">Tek seans</option>
              <option value="weekly">Haftalık (Pzt/Çar/Cuma…)</option>
            </select>
          </div>

          <div class="col-12" id="weeklyBox" style="display:none;">
            <div class="mb-1 text-muted small">Günler</div>
            <div>
              <label class="day-chip"><input type="checkbox" value="1">Pzt</label>
              <label class="day-chip"><input type="checkbox" value="2">Sal</label>
              <label class="day-chip"><input type="checkbox" value="3">Çar</label>
              <label class="day-chip"><input type="checkbox" value="4">Per</label>
              <label class="day-chip"><input type="checkbox" value="5">Cum</label>
              <label class="day-chip"><input type="checkbox" value="6">Cmt</label>
              <label class="day-chip"><input type="checkbox" value="7">Paz</label>
            </div>

            <div class="row g-2 mt-2">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Bitiş Tarihi</label>
                <input id="bitis_tarihi" type="date" class="form-control">
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <div class="text-muted small">Aynı seansı yanlışlıkla iki kez kaydetmeni engeller (duplicate koruması).</div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label fw-semibold">Notlar</label>
            <textarea id="notlar" class="form-control" rows="3" placeholder="Seans hedefi, özel notlar…"></textarea>
          </div>

          <div class="col-12">
            <label class="form-label fw-semibold">Lokasyon</label>
            <input id="lokasyon" class="form-control" placeholder="Salon / Online / Adres…">
          </div>

          <div id="msg" class="col-12 small" style="display:none;"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-light btn-soft" data-bs-dismiss="modal">İptal</button>
        <button id="btnKaydet" class="btn btn-primary">Kaydet</button>
      </div>
    </div>
  </div>
</div>

<!-- Seans Detay/Durum Modal -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div id="evTitle" class="fw-bold" style="font-size:1.02rem;">Seans</div>
          <div id="evMeta" class="text-muted small"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>

      <div class="modal-body">
        <label class="form-label fw-semibold">Not</label>
        <textarea id="evNote" class="form-control" rows="3" placeholder="Bu seansa özel not…"></textarea>
        <div id="evMsg" class="small mt-2" style="display:none;"></div>
      </div>

      <div class="modal-footer d-flex flex-wrap gap-2">
        <button class="btn btn-outline-secondary btn-soft" data-status="planned">Planlandı</button>
        <button class="btn btn-outline-success btn-soft" data-status="done">Tamamlandı</button>
        <button class="btn btn-outline-warning btn-soft" data-status="no_show">Gelmedi</button>
        <button class="btn btn-outline-danger btn-soft" data-status="canceled">İptal</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<script>
  const $ = (s)=>document.querySelector(s);
  const $$ = (s)=>Array.from(document.querySelectorAll(s));

  function trStatus(s){
    const map = { planned:'Planlandı', done:'Tamamlandı', no_show:'Gelmedi', canceled:'İptal' };
    return map[s] || s;
  }

  function showMsg(text, ok=true){
    const el = $("#msg");
    el.style.display = "block";
    el.className = "col-12 small " + (ok ? "text-success" : "text-danger");
    el.textContent = text;
  }

  // ✅ modal içi mesaj: ok/warn/error
  function showEvMsg(text, type="ok"){
    const el = $("#evMsg");
    el.style.display = "block";
    el.className = "small mt-2 " + (type === "ok" ? "text-success" : (type === "warn" ? "text-warning" : "text-danger"));
    el.textContent = text;
  }

  async function loadMembers(){
    const r = await fetch("ajax/uylelerim.php");
    const j = await r.json();

    const filter = $("#uyeFilter");
    const sel = $("#uyeSelect");
    filter.innerHTML = `<option value="0">Tümü</option>`;
    sel.innerHTML = ``;

    if (!j.ok) {
      filter.innerHTML = `<option value="0">Üye yüklenemedi</option>`;
      sel.innerHTML = `<option value="0">Üye yüklenemedi</option>`;
      return;
    }

    j.items.forEach(u=>{
      const label = u.uyelik_numarasi ? `${u.ad_soyad} • ${u.uyelik_numarasi}` : u.ad_soyad;
      filter.insertAdjacentHTML("beforeend", `<option value="${u.id}">${label}</option>`);
      sel.insertAdjacentHTML("beforeend", `<option value="${u.id}">${label}</option>`);
    });
  }

  let calendar;
  let selectedDateStr = null;

  function buildCalendar(){
    const calEl = document.getElementById("calendar");

    calendar = new FullCalendar.Calendar(calEl, {
      initialView: window.innerWidth < 992 ? 'timeGridWeek' : 'dayGridMonth',
      height: "auto",
      nowIndicator: true,

      headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,timeGridWeek,timeGridDay' },

      locale: 'tr',
      firstDay: 1,

      buttonText: { today:'Bugün', month:'Ay', week:'Hafta', day:'Gün', list:'Liste' },
      allDayText: 'Tüm gün',
      noEventsText: 'Gösterilecek seans yok',
      moreLinkText: (n) => `+${n} tane daha`,

      selectable: true,

      events: (info, success, failure) => {
        const uye = $("#uyeFilter").value || 0;
        fetch(`ajax/seans_liste.php?start=${encodeURIComponent(info.startStr)}&end=${encodeURIComponent(info.endStr)}&uye_id=${encodeURIComponent(uye)}`)
          .then(r=>r.json()).then(data=>success(data)).catch(err=>failure(err));
      },

      dateClick: (info) => {
        selectedDateStr = info.dateStr;
        $("#selectedDay").textContent = new Date(info.dateStr).toLocaleDateString('tr-TR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
        renderDayList();
      },

      eventClick: (info) => {
        const ev = info.event;
        const start = ev.start;
        const end = ev.end;

        const sTxt = start ? start.toLocaleString('tr-TR',{weekday:'short',year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
        const eTxt = end ? end.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}) : '';
        const durum = ev.extendedProps?.durum ?? 'planned';

        $("#evTitle").textContent = ev.title || 'Seans';
        $("#evMeta").textContent = `${sTxt}${eTxt ? ' - ' + eTxt : ''} • Durum: ${trStatus(durum)}`;
        $("#evNote").value = ev.extendedProps?.notlar ?? '';
        $("#evMsg").style.display = "none";

        $$("#eventModal .modal-footer button[data-status]").forEach(b=>{
          b.dataset.seansId = ev.id;
        });

        if (start) {
          const iso = start.toISOString().slice(0,10);
          selectedDateStr = iso;
          $("#selectedDay").textContent = new Date(iso).toLocaleDateString('tr-TR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
          renderDayList();
        }

        new bootstrap.Modal(document.getElementById('eventModal')).show();
      }
    });

    calendar.render();
  }
    
  async function renderDayList(){
    if (!selectedDateStr) { $("#dayList").innerHTML=""; $("#dayCount").textContent="0"; return; }

    const start = selectedDateStr + "T00:00:00";
    const endDate = new Date(selectedDateStr); endDate.setDate(endDate.getDate()+1);
    const end = endDate.toISOString().slice(0,10) + "T00:00:00";

    const uye = $("#uyeFilter").value || 0;
    const r = await fetch(`ajax/seans_liste.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}&uye_id=${encodeURIComponent(uye)}`);
    const events = await r.json();

    const box = $("#dayList");
    box.innerHTML = "";

    if (!Array.isArray(events) || events.length === 0) {
      $("#dayCount").textContent = "0";
      box.innerHTML = `<div class="text-muted small">Bu günde seans yok.</div>`;
      return;
    }

    $("#dayCount").textContent = String(events.length);

    events.forEach(ev=>{
      const s = new Date(ev.start);
      const time = s.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});

      // ✅ dataset JSON güvenli encode
      const evJson = encodeURIComponent(JSON.stringify(ev));

      box.insertAdjacentHTML("beforeend", `
        <button class="text-start p-2 rounded-4 border bg-white"
                style="border-color:rgba(15,23,42,.10);"
                data-ev="${evJson}">
          <div class="fw-bold">${time} • ${ev.title}</div>
          <div class="text-muted small">Süre: ${ev.extendedProps?.sure_dk ?? 0} dk • Durum: ${trStatus(ev.extendedProps?.durum ?? 'planned')}</div>
        </button>
      `);
    });

    $$("#dayList button[data-ev]").forEach(btn=>{
      btn.addEventListener("click", ()=>{
        const ev = JSON.parse(decodeURIComponent(btn.getAttribute("data-ev")));
        $("#evTitle").textContent = ev.title || 'Seans';

        const startD = new Date(ev.start);
        const endD = new Date(ev.end);
        $("#evMeta").textContent =
          `${startD.toLocaleString('tr-TR',{weekday:'short',year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'})} - ${endD.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'})} • Durum: ${trStatus(ev.extendedProps?.durum ?? 'planned')}`;

        $("#evNote").value = ev.extendedProps?.notlar ?? '';
        $("#evMsg").style.display = "none";

        $$("#eventModal .modal-footer button[data-status]").forEach(b=> b.dataset.seansId = ev.id);
        new bootstrap.Modal(document.getElementById('eventModal')).show();
      });
    });
  }

  $("#tekrar").addEventListener("change", ()=>{
    $("#weeklyBox").style.display = ($("#tekrar").value === "weekly") ? "block" : "none";
  });

  $("#uyeFilter").addEventListener("change", ()=>{
  calendar.refetchEvents();
  renderDayList();
  loadUyeOzet($("#uyeFilter").value); // ✅ yeni
});


  $("#btnKaydet").addEventListener("click", async ()=>{
    $("#msg").style.display="none";

    const data = new FormData();
    data.append("uye_id", $("#uyeSelect").value);
    data.append("baslik", $("#baslik").value);
    data.append("baslangic_tarihi", $("#baslangic_tarihi").value);
    data.append("saat", $("#saat").value);
    data.append("sure_dk", $("#sure_dk").value);
    data.append("tekrar", $("#tekrar").value);
    data.append("bitis_tarihi", $("#bitis_tarihi").value);
    data.append("notlar", $("#notlar").value);
    data.append("lokasyon", $("#lokasyon").value);

    if ($("#tekrar").value === "weekly") {
      $$("#weeklyBox input[type=checkbox]:checked").forEach(ch => data.append("gunler[]", ch.value));
    }

    const r = await fetch("ajax/seans_kaydet.php", { method:"POST", body:data });
    const j = await r.json();

    if (!j.ok) { showMsg(j.msg || "Hata oluştu.", false); return; }

    showMsg((j.msg || "Kaydedildi.") + (j.created ? ` (${j.created} seans)` : ""), true);
    calendar.refetchEvents();
    renderDayList();
  });

  $$("#eventModal .modal-footer button[data-status]").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      $("#evMsg").style.display="none";
      const seansId = btn.dataset.seansId;
      const newDurum = btn.dataset.status;
      const notlar = $("#evNote").value;

      const fd = new FormData();
      fd.append("seans_id", seansId);
      fd.append("durum", newDurum);
      fd.append("notlar", notlar);

      const r = await fetch("ajax/seans_guncelle.php", { method:"POST", body:fd });
      const j = await r.json();

      if (!j.ok) { showEvMsg(j.msg || "Güncellenemedi.", "err"); return; }

      // ✅ backend msg + paket uyarısı
      const msg = j.msg || "Güncellendi.";
      const warn = (j.abonelik_tipi === 'ders_paketi' && (parseInt(j.kalan_seans ?? 0, 10) <= 0 || j.abonelik_durum === 'yenileme'));
      showEvMsg(msg + (warn ? ` (Kalan Hak: ${j.kalan_seans ?? 0})` : ""), warn ? "warn" : "ok");

      // ✅ modal meta’yı da güncelle (durum yazısı değişsin)
      const meta = $("#evMeta").textContent;
      $("#evMeta").textContent = meta.replace(/Durum:\s.*$/,'Durum: ' + trStatus(newDurum));

      calendar.refetchEvents();
      renderDayList();
    });
  });

  (async function(){
    await loadMembers();

    const today = new Date().toISOString().slice(0,10);
    $("#baslangic_tarihi").value = today;

    const d = new Date(); d.setDate(d.getDate() + 56);
    $("#bitis_tarihi").value = d.toISOString().slice(0,10);

    buildCalendar();
    loadUyeOzet($("#uyeFilter").value);

  })();
  
  function fmtDateTR(iso){
  if (!iso) return "";
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString('tr-TR',{day:'2-digit',month:'short',year:'numeric'});
}

function setUyeOzetPill(text, type="normal"){
  const wrap = $("#uyeOzet");
  const t = $("#uyeOzetText");
  if (!text) { wrap.style.display="none"; return; }

  wrap.style.display = "inline-flex";
  t.textContent = text;

  // type: normal | warn | err
  if (type === "warn") {
    wrap.style.borderColor = "rgba(245,158,11,.35)";
    wrap.style.background = "rgba(245,158,11,.10)";
    t.style.color = "#b45309";
  } else if (type === "err") {
    wrap.style.borderColor = "rgba(239,68,68,.35)";
    wrap.style.background = "rgba(239,68,68,.10)";
    t.style.color = "#b91c1c";
  } else {
    wrap.style.borderColor = "rgba(15,23,42,.10)";
    wrap.style.background = "#fff";
    t.style.color = "var(--text)";
  }
}

async function loadUyeOzet(uyeId){
  if (!uyeId || parseInt(uyeId,10) <= 0) { setUyeOzetPill(""); return; }

  try {
    const r = await fetch(`ajax/uye_abonelik_ozet.php?uye_id=${encodeURIComponent(uyeId)}`);
    const j = await r.json();
    if (!j.ok || !j.item) { setUyeOzetPill("Özet alınamadı", "err"); return; }

    const it = j.item;

    // Ders paketi
    if (it.abonelik_tipi === "ders_paketi") {
      const kalan = (it.paket_kalan_seans ?? 0);
      const toplam = (it.paket_toplam_seans ?? 0);
      const donem = `${fmtDateTR(it.baslangic_tarihi)} - ${fmtDateTR(it.bitis_tarihi)}`;
      const txt = `Paket: ${kalan}/${toplam} • ${donem} • ${it.abonelik_durum || '—'}`;

      const warn = (kalan <= 0 || it.abonelik_durum === "yenileme");
      setUyeOzetPill(txt, warn ? "warn" : "normal");
      return;
    }

    // Aylık
    if (it.abonelik_tipi === "aylik") {
      const donem = `${fmtDateTR(it.baslangic_tarihi)} - ${fmtDateTR(it.bitis_tarihi)}`;
      const expired = (it.expired === 1);
      const txt = `Aylık • ${donem}${it.abonelik_durum ? " • "+it.abonelik_durum : ""}`;
      setUyeOzetPill(txt, expired ? "warn" : "normal");
      return;
    }

    // Tip yoksa
    setUyeOzetPill("Abonelik tanımlı değil", "warn");
  } catch (e) {
    setUyeOzetPill("Özet alınamadı", "err");
  }
}

  
</script>

<?php
// Footer include (robust)
$__ftr_loaded = false;
foreach ([__DIR__.'/footer.php', __DIR__.'/inc/footer.php', __DIR__.'/partials/footer.php'] as $__p) {
  if (file_exists($__p)) { require_once $__p; $__ftr_loaded = true; break; }
}
if (!$__hdr_loaded && !$__ftr_loaded) echo "</body></html>";
?>
