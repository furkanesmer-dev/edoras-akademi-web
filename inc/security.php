<?php
/**
 * inc/security.php
 * Merkezi güvenlik yardımcı fonksiyonları.
 * Her sayfadan include edilmelidir (inc/header.php üzerinden otomatik gelir).
 */

// ─── Oturum güvenlik ayarları ────────────────────────────────────────────────
// Bu ayarlar session_start() ÖNCE çağrılmalı; header.php bunu halleder.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure',   '1');   // Prod HTTPS zorunlu
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime',  '3600'); // 1 saat
    session_start();
}

// ─── Güvenlik HTTP başlıkları ─────────────────────────────────────────────────
function send_security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // CSP: Bootstrap CDN + jQuery CDN + FontAwesome CDN izni
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com; " .
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "font-src 'self' https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https: blob:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none';"
    );
}

// ─── CSRF Token ──────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Form içine gizli alan olarak yazar */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** CSRF doğrular; başarısız olursa JSON veya 403 ile çıkar */
function csrf_verify(bool $json_mode = false): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if (
        $expected === ''
        || $submitted === ''
        || !hash_equals($expected, $submitted)
    ) {
        if ($json_mode) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'CSRF doğrulaması başarısız.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        exit('Güvenlik doğrulaması başarısız. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
}

// ─── Hata yönetimi: prod'da hata gizle ───────────────────────────────────────
function configure_error_reporting(): void {
    $is_dev = (bool)(getenv('APP_DEBUG') ?: false);
    if ($is_dev) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(0);
        ini_set('display_errors', '0');
        ini_set('log_errors',     '1');
    }
}

// ─── Kullanıcı oturumu doğrula (web sayfaları için) ──────────────────────────
/**
 * @param string[]|null $allowed_roles null = her role izin ver
 */
function require_session(array $allowed_roles = null): array {
    if (!isset($_SESSION['user'])) {
        header('Location: /login.php');
        exit;
    }
    $user  = $_SESSION['user'];
    $yetki = (string)($user['yetki'] ?? 'kullanici');

    if ($allowed_roles !== null && !in_array($yetki, $allowed_roles, true)) {
        http_response_code(403);
        exit('Bu sayfaya erişim yetkiniz yok.');
    }
    return $user;
}
