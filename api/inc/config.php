<?php
/**
 * api/inc/config.php
 * JWT ayarları - gizli anahtar .env'den okunur.
 */

// Proje kökündeki .env'yi yükle
require_once __DIR__ . '/../../inc/env.php';

$jwt_secret = getenv('JWT_SECRET') ?: '';
if (strlen($jwt_secret) < 32) {
    error_log('JWT_SECRET eksik veya çok kısa. .env dosyasını kontrol edin.');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Sunucu yapılandırma hatası.'], JSON_UNESCAPED_UNICODE);
    exit;
}

define('JWT_SECRET', $jwt_secret);
define('JWT_TTL_SECONDS', 60 * 60 * 24 * 14); // 14 gün
