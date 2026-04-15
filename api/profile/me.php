<?php
// api/profile/me.php

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_fail('Sadece GET.', 405);
}

try {
  $payload = require_user();          // JWT doğrula
  $uid = (int)$payload['uid'];

  $stmt = $conn->prepare("
    SELECT
      id, ad, soyad, eposta_adresi, tel_no, foto_yolu,
      kilo_kg, boy_cm, bel_cevresi, basen_cevresi, boyun_cevresi,
      yag_orani, vucut_kitle_indeksi,

      -- ✅ yeni alanlar
      cinsiyet, dogum_tarihi, aktivite_seviyesi, kilo_hedefi, hedef_tempo,

      spor_hedefi, spor_deneyimi, yas, saglik_sorunlari,
      uye_aktif, odeme_alindi, baslangic_tarihi, bitis_tarihi,
      donduruldu, dondurma_baslangic, dondurma_bitis, dondurma_notu,
      abonelik_tipi, abonelik_durum, abonelik_suresi_ay,
      paket_toplam_seans, paket_kalan_seans
    FROM uye_kullanicilar
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) json_fail('Kullanıcı bulunamadı.', 404);

  // VKI durumu
  $vki = $row['vucut_kitle_indeksi'] !== null ? (float)$row['vucut_kitle_indeksi'] : null;
  $vkiDurum = null;
  if ($vki !== null && $vki > 0) {
    if ($vki < 18.5) $vkiDurum = "Zayıf";
    else if ($vki < 25) $vkiDurum = "Normal";
    else if ($vki < 30) $vkiDurum = "Fazla Kilolu";
    else $vkiDurum = "Obez";
  }

  $profile = [
    'id' => (int)$row['id'],
    'ad' => $row['ad'],
    'soyad' => $row['soyad'],
    'eposta_adresi' => $row['eposta_adresi'],
    'tel_no' => $row['tel_no'],
    'foto_yolu' => $row['foto_yolu'],

    'kilo_kg' => $row['kilo_kg'] !== null ? (float)$row['kilo_kg'] : null,
    'boy_cm' => $row['boy_cm'] !== null ? (float)$row['boy_cm'] : null,
    'bel_cevresi' => $row['bel_cevresi'] !== null ? (float)$row['bel_cevresi'] : null,
    'basen_cevresi' => $row['basen_cevresi'] !== null ? (float)$row['basen_cevresi'] : null,
    'boyun_cevresi' => $row['boyun_cevresi'] !== null ? (float)$row['boyun_cevresi'] : null,
    'yag_orani' => $row['yag_orani'] !== null ? (float)$row['yag_orani'] : null,
    'vucut_kitle_indeksi' => $vki,
    'vki_durum' => $vkiDurum,

    // ✅ yeni alanlar
    'cinsiyet' => $row['cinsiyet'],
    'dogum_tarihi' => $row['dogum_tarihi'], // YYYY-MM-DD
    'aktivite_seviyesi' => $row['aktivite_seviyesi'],
    'kilo_hedefi' => $row['kilo_hedefi'],
    'hedef_tempo' => $row['hedef_tempo'],

    'spor_hedefi' => $row['spor_hedefi'],
    'spor_deneyimi' => $row['spor_deneyimi'],
    'yas' => $row['yas'],
    'saglik_sorunlari' => $row['saglik_sorunlari'],
  ];

  $subscription = [
    'uye_aktif' => (int)$row['uye_aktif'],
    'odeme_alindi' => (int)$row['odeme_alindi'],
    'baslangic_tarihi' => $row['baslangic_tarihi'],
    'bitis_tarihi' => $row['bitis_tarihi'],
    'donduruldu' => (int)$row['donduruldu'],
    'dondurma_baslangic' => $row['dondurma_baslangic'],
    'dondurma_bitis' => $row['dondurma_bitis'],
    'dondurma_notu' => $row['dondurma_notu'],

    'abonelik_tipi' => $row['abonelik_tipi'],
    'abonelik_durum' => $row['abonelik_durum'],
    'abonelik_suresi_ay' => $row['abonelik_suresi_ay'],
    'paket_toplam_seans' => $row['paket_toplam_seans'],
    'paket_kalan_seans' => $row['paket_kalan_seans'],
  ];

  // ✅ targets (varsa)
  $tstmt = $conn->prepare("
    SELECT user_id, formula, bmr, tdee, target_kcal, protein_g, karb_g, yag_g, is_manual_override, created_at, updated_at
    FROM uye_beslenme_hedefleri
    WHERE user_id = ?
    LIMIT 1
  ");
  if (!$tstmt) throw new Exception("Prepare failed (targets): " . $conn->error);

  $tstmt->bind_param("i", $uid);
  $tstmt->execute();
  $tres = $tstmt->get_result();
  $targets = $tres->fetch_assoc() ?: null;

  json_ok([
    'profile' => $profile,
    'subscription' => $subscription,
    'targets' => $targets,
  ]);

} catch (Throwable $e) {
  json_fail('Sunucu hatası: ' . $e->getMessage(), 500);
}