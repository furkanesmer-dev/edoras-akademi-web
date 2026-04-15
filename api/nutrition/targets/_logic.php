<?php
// /api/nutrition/targets/_logic.php

function recalcTargets(mysqli $conn, int $user_id): array
{
  // 1) Üye verilerini çek
  $stmt = $conn->prepare("
    SELECT
      id,
      cinsiyet,
      dogum_tarihi,
      yas,
      kilo_kg,
      boy_cm,
      yag_orani,
      aktivite_seviyesi,
      kilo_hedefi,
      hedef_tempo
    FROM uye_kullanicilar
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $u = $res->fetch_assoc();

  if (!$u) {
    throw new Exception("User not found");
  }

  $kilo = isset($u["kilo_kg"]) ? floatval($u["kilo_kg"]) : 0;
  $boy  = isset($u["boy_cm"])  ? floatval($u["boy_cm"])  : 0;
  $bf   = isset($u["yag_orani"]) ? floatval($u["yag_orani"]) : 0;

  $aktivite = $u["aktivite_seviyesi"] ?? null;
  $hedef    = $u["kilo_hedefi"] ?? null;
  $tempo    = $u["hedef_tempo"] ?? "orta";

  if ($kilo <= 0 || $boy <= 0) {
    throw new Exception("Boy ve kilo gerekli (boy_cm, kilo_kg).");
  }
  if (!$aktivite || !$hedef) {
    throw new Exception("aktivite_seviyesi ve kilo_hedefi gerekli.");
  }

  // 2) Yaş hesapla (dogum_tarihi varsa onu kullan)
  $age = null;
  if (!empty($u["dogum_tarihi"])) {
    $dob = new DateTime($u["dogum_tarihi"]);
    $now = new DateTime("now");
    $age = $dob->diff($now)->y;
  } elseif (!empty($u["yas"])) {
    $age = intval($u["yas"]);
  }
  if ($age === null || $age <= 0) {
    throw new Exception("dogum_tarihi veya yas gerekli.");
  }

  // 3) Aktivite katsayısı
  $activityMap = [
    "sedanter"   => 1.2,
    "hafif"      => 1.375,
    "orta"       => 1.55,
    "yuksek"     => 1.725,
    "cok_yuksek" => 1.9
  ];
  $mult = $activityMap[$aktivite] ?? null;
  if ($mult === null) {
    throw new Exception("aktivite_seviyesi geçersiz.");
  }

  // 4) Eğer daha önce manuel override varsa, hedefleri ezmeyelim
  $ov = $conn->prepare("SELECT is_manual_override FROM uye_beslenme_hedefleri WHERE user_id=? LIMIT 1");
  $ov->bind_param("i", $user_id);
  $ov->execute();
  $ovRes = $ov->get_result();
  $ovRow = $ovRes->fetch_assoc();
  $isOverride = $ovRow ? (intval($ovRow["is_manual_override"]) === 1) : false;

  // 5) BMR (yağ oranı varsa Katch, yoksa Mifflin)
  $formula = "mifflin";
  $bmr = 0;

  $hasBf = ($bf > 0 && $bf < 70);
  if ($hasBf) {
    $lbm = $kilo * (1 - ($bf / 100.0));
    $bmr = 370 + (21.6 * $lbm);
    $formula = "katch";
  } else {
    $gender = $u["cinsiyet"] ?? null;
    if ($gender !== "erkek" && $gender !== "kadin") {
      throw new Exception("Yağ oranı yoksa Mifflin için cinsiyet gerekli (erkek/kadin).");
    }
    $bmr = (10 * $kilo) + (6.25 * $boy) - (5 * $age) + ($gender === "erkek" ? 5 : -161);
    $formula = "mifflin";
  }
  $bmr = (int) round($bmr);

  // 6) TDEE
  $tdee = (int) round($bmr * $mult);

  // 7) Hedef kalori (tempo’ya göre)
  $deficitMap = ["yavas" => 250, "orta" => 400, "hizli" => 550];
  $surplusMap = ["yavas" => 200, "orta" => 300, "hizli" => 400];

  $delta = 0;
  if ($hedef === "kilo_ver") {
    $delta = -($deficitMap[$tempo] ?? 400);
  } elseif ($hedef === "kilo_al") {
    $delta = +($surplusMap[$tempo] ?? 300);
  } elseif ($hedef === "koru") {
    $delta = 0;
  } else {
    throw new Exception("kilo_hedefi geçersiz.");
  }

  $targetKcal = max(1200, $tdee + $delta);

  // 8) Makrolar (pratik)
  $proteinPerKg = 1.6;
  if ($hedef === "kilo_ver") $proteinPerKg = 1.9;
  if ($hedef === "kilo_al")  $proteinPerKg = 1.7;

  $proteinG = (int) round($kilo * $proteinPerKg);
  $fatG = (int) round($kilo * 0.8);

  $kcalFromProtein = $proteinG * 4;
  $kcalFromFat = $fatG * 9;
  $remaining = $targetKcal - ($kcalFromProtein + $kcalFromFat);
  $carbG = (int) floor(max(0, $remaining) / 4);

  // 9) DB’ye yaz
  // Override varsa: sadece bmr/tdee/formula güncelle, hedefleri elle dokunma
  if ($isOverride) {
    $up = $conn->prepare("
      INSERT INTO uye_beslenme_hedefleri
        (user_id, formula, bmr, tdee, target_kcal, protein_g, karb_g, yag_g, is_manual_override)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, 1)
      ON DUPLICATE KEY UPDATE
        formula = VALUES(formula),
        bmr = VALUES(bmr),
        tdee = VALUES(tdee),
        updated_at = CURRENT_TIMESTAMP
    ");

    // hedef/makrolar burada “placeholder” olarak mevcut satır korunacak; insert ilk seferse yine dolar.
    $up->bind_param("isiiiiii", $user_id, $formula, $bmr, $tdee, $targetKcal, $proteinG, $carbG, $fatG);
    $up->execute();
  } else {
    $up = $conn->prepare("
      INSERT INTO uye_beslenme_hedefleri
        (user_id, formula, bmr, tdee, target_kcal, protein_g, karb_g, yag_g, is_manual_override)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, 0)
      ON DUPLICATE KEY UPDATE
        formula = VALUES(formula),
        bmr = VALUES(bmr),
        tdee = VALUES(tdee),
        target_kcal = VALUES(target_kcal),
        protein_g = VALUES(protein_g),
        karb_g = VALUES(karb_g),
        yag_g = VALUES(yag_g),
        is_manual_override = 0,
        updated_at = CURRENT_TIMESTAMP
    ");
    $up->bind_param("isiiiiii", $user_id, $formula, $bmr, $tdee, $targetKcal, $proteinG, $carbG, $fatG);
    $up->execute();
  }

  // 10) Sonuç dön
  return [
    "user_id" => $user_id,
    "formula" => $formula,
    "bmr" => $bmr,
    "tdee" => $tdee,
    "target_kcal" => $targetKcal,
    "macros" => [
      "protein_g" => $proteinG,
      "karb_g" => $carbG,
      "yag_g" => $fatG
    ],
    "manual_override" => $isOverride
  ];
}