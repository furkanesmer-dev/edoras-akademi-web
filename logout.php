<?php
/**
 * logout.php
 * Güvenli oturum kapatma: session verisi temizlenir, cookie silinir.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session değişkenlerini temizle
$_SESSION = [];

// Session cookie'yi sil
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Session'ı yok et
session_destroy();

header('Location: /login.php');
exit;
