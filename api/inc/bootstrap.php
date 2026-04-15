<?php
/**
 * api/inc/bootstrap.php
 * API katmanı için temel bootstrap: JSON başlıkları, CORS, yardımcı fonksiyonlar.
 * CORS: Sadece tanımlı kökene izin ver (wildcard '*' güvensizdir).
 */

header('Content-Type: application/json; charset=utf-8');

// CORS - izin verilen kökenleri ortam değişkeninden al
$allowed_origin = getenv('APP_URL') ?: 'https://kocluk.edorasakademi.com';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Vary: Origin');
} else {
    // Kendi kökenden gelen istekler için (aynı site)
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}

header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Hata raporlama: prod'da gizle
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function json_ok($data = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_fail(string $msg, int $code = 400, $extra = null): void {
    http_response_code($code);
    $payload = ['ok' => false, 'msg' => $msg];
    if ($extra !== null) {
        $payload['extra'] = $extra;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$hdr) {
        return null;
    }
    if (stripos($hdr, 'Bearer ') !== 0) {
        return null;
    }
    return trim(substr($hdr, 7));
}
