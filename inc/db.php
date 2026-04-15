<?php
/**
 * inc/db.php
 * Veritabanı bağlantısı - kimlik bilgileri .env dosyasından okunur.
 * Asla hardcode kimlik bilgisi kullanmayın.
 */

require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: '';
$dbuser   = getenv('DB_USER') ?: '';
$dbpass   = getenv('DB_PASS') ?: '';

if ($dbname === '' || $dbuser === '') {
    // Hata ayıklama bilgisini açığa çıkarma
    error_log('DB kimlik bilgileri eksik. .env dosyasını kontrol edin.');
    http_response_code(500);
    exit('Veritabanı yapılandırması eksik.');
}

$conn = new mysqli($host, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    error_log('DB bağlantı hatası: ' . $conn->connect_error);
    http_response_code(500);
    exit('Veritabanı bağlantısı kurulamadı.');
}

$conn->set_charset('utf8mb4');
