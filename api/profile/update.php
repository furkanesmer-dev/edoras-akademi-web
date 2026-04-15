<?php
// api/profile/update.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_fail('Sadece POST.', 405);
}

// inc/bootstrap.php zaten auth.php içinde geliyor, get_json_body() var.
$body = get_json_body();

function clamp_number($val, $min, $max) {
  if ($val === null) return null;
  // TR locale virgül ihtimali
  if (is_string($val)) $val = str_replace(',', '.', $val);
  if (!is_numeric($val)) return null;
  $f = (float)$val;
  if ($f < $min || $f > $max) return null;
  return $f;
}

function normalize_enum($val, array $allowed) {
  if ($val === null) return null;
  $s = trim((string)$val);
  if ($s === '') return null;
  return in_array($s, $allowed, true) ? $s : '__INVALID__';
}

function normalize_date_ymd($val) {
  if ($val === null) return null;
  $s = trim((string)$val);
  if ($s === '') return null;
  $dt = DateTime::createFromFormat('Y-m-d', $s);
  if (!$dt) return '__INVALID__';
  // 1900-01-01 gibi alt limit koymak istersen burada koyarız
  return $dt->format('Y-m-d');
}

try {
  $payload = require_user();
  $uid = (int)$payload['uid'];

  // --- Bu endpoint "sadece gönderilen alanları" günceller ---
  // Sayısal alanlar (ölçüler)
  $kilo  = array_key_exists('kilo_kg', $body) ? clamp_number($body['kilo_kg'], 20, 300) : null;
  $boy   = array_key_exists('boy_cm', $body) ? clamp_number($body['boy_cm'], 100, 250) : null;

  $bel   = array_key_exists('bel_cevresi', $body) ? clamp_number($body['bel_cevresi'], 30, 200) : null;
  $basen = array_key_exists('basen_cevresi', $body) ? clamp_number($body['basen_cevresi'], 30, 250) : null;
  $boyun = array_key_exists('boyun_cevresi', $body) ? clamp_number($body['boyun_cevresi'], 20, 80) : null;

  // ✅ yag_orani trigger ile hesaplanıyor: bu endpoint'te manuel yazmayı KAPATIYORUZ.
  // $yag = ...

  // Metin alanlar
  $spor_hedefi = array_key_exists('spor_hedefi', $body) ? trim((string)$body['spor_hedefi']) : null;

  // ✅ Yeni onboarding/profil alanları (kalori hedefi hesabı için)
  // DB enumları: cinsiyet: erkek/kadin
  $cinsiyet = array_key_exists('cinsiyet', $body)
    ? normalize_enum($body['cinsiyet'], ['erkek','kadin'])
    : null;

  // dogum_tarihi: YYYY-MM-DD
  $dogum_tarihi = array_key_exists('dogum_tarihi', $body)
    ? normalize_date_ymd($body['dogum_tarihi'])
    : null;

  // aktivite_seviyesi enum
  $aktivite = array_key_exists('aktivite_seviyesi', $body)
    ? normalize_enum($body['aktivite_seviyesi'], ['sedanter','hafif','orta','yuksek','cok_yuksek'])
    : null;

  // kilo_hedefi enum
  $kilo_hedefi = array_key_exists('kilo_hedefi', $body)
    ? normalize_enum($body['kilo_hedefi'], ['kilo_ver','koru','kilo_al'])
    : null;

  // hedef_tempo enum
  $hedef_tempo = array_key_exists('hedef_tempo', $body)
    ? normalize_enum($body['hedef_tempo'], ['yavas','orta','hizli'])
    : null;

  // Enum/date invalid kontrol
  $invalids = [];
  if ($cinsiyet === '__INVALID__') $invalids[] = 'cinsiyet';
  if ($dogum_tarihi === '__INVALID__') $invalids[] = 'dogum_tarihi';
  if ($aktivite === '__INVALID__') $invalids[] = 'aktivite_seviyesi';
  if ($kilo_hedefi === '__INVALID__') $invalids[] = 'kilo_hedefi';
  if ($hedef_tempo === '__INVALID__') $invalids[] = 'hedef_tempo';

  if (!empty($invalids)) {
    json_fail('Geçersiz alan(lar): ' . implode(', ', $invalids), 422);
  }

  // Hiç geçerli alan gelmediyse
  if (
    $kilo===null && $boy===null && $bel===null && $basen===null &&
    $boyun===null && $spor_hedefi===null &&
    $cinsiyet===null && $dogum_tarihi===null && $aktivite===null &&
    $kilo_hedefi===null && $hedef_tempo===null
  ) {
    json_fail('Güncellenecek geçerli alan bulunamadı.', 400);
  }

  // VKI hesap (sadece kilo+boy birlikte geldiyse)
  $vki = null;
  if ($kilo !== null && $boy !== null) {
    $boyM = $boy / 100.0;
    if ($boyM > 0) {
      $vki = round($kilo / ($boyM * $boyM), 2);
    }
  }

  // Dinamik update (sadece gelenleri yazar)
  $fields = [];
  $types = "";
  $params = [];

  $add = function($col, $type, $val) use (&$fields, &$types, &$params) {
    $fields[] = "{$col} = ?";
    $types .= $type;
    $params[] = $val;
  };

  if ($kilo !== null)  $add("kilo_kg", "d", $kilo);
  if ($boy !== null)   $add("boy_cm", "d", $boy);
  if ($bel !== null)   $add("bel_cevresi", "d", $bel);
  if ($basen !== null) $add("basen_cevresi", "d", $basen);
  if ($boyun !== null) $add("boyun_cevresi", "d", $boyun);

  if ($spor_hedefi !== null) $add("spor_hedefi", "s", $spor_hedefi);

  if ($cinsiyet !== null) $add("cinsiyet", "s", $cinsiyet);
  if ($dogum_tarihi !== null) $add("dogum_tarihi", "s", $dogum_tarihi);
  if ($aktivite !== null) $add("aktivite_seviyesi", "s", $aktivite);
  if ($kilo_hedefi !== null) $add("kilo_hedefi", "s", $kilo_hedefi);
  if ($hedef_tempo !== null) $add("hedef_tempo", "s", $hedef_tempo);

  // ✅ DB’deki doğru kolon adı: vucut_kitle_indeksi
  if ($vki !== null) $add("vucut_kitle_indeksi", "d", $vki);

  $sql = "UPDATE uye_kullanicilar SET " . implode(", ", $fields) . " WHERE id = ? LIMIT 1";
  $types .= "i";
  $params[] = $uid;

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

  // bind_param referans ile
  $bind = [];
  $bind[] = & $types;
  for ($i=0; $i<count($params); $i++) {
    $bind[] = & $params[$i];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);

  $stmt->execute();

  // ✅ Update sonrası otomatik hedef hesapla (eksik bilgi varsa patlatmadan geçer)
  $targets = null;
  $targets_updated = false;

  try {
    require_once __DIR__ . ‘/../nutrition/targets/_logic.php’;
    $targets = recalcTargets($conn, $uid);
    $targets_updated = true;
  } catch (Throwable $t) {
    // Profil güncellemesi başarılı olsun; hedef hesabı için eksikler olabilir.
    error_log(‘api/profile/update.php recalcTargets hatası (uid=’ . $uid . ‘): ‘ . $t->getMessage());
  }

  json_ok([
    ‘saved’ => true,
    ‘targets_updated’ => $targets_updated,
    ‘targets’ => $targets,
  ]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}