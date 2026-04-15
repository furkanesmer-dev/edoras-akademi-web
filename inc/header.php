<?php
/**
 * inc/header.php
 * Ortak başlık: güvenlik ayarları, oturum kontrolü, DB bağlantısı, navigasyon.
 */

// Güvenlik yardımcıları ve hata yapılandırması (display_errors kapalı)
require_once __DIR__ . '/security.php';
configure_error_reporting();
send_security_headers();

// DB bağlantısı (security.php session'ı zaten başlatır)
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$user  = $_SESSION['user'];
$yetki = (string)($user['yetki'] ?? 'kullanici');

// Tema: sadece izin verilen değerlere kısıtla (Path Traversal / XSS önlemi)
$allowed_themes = ['light', 'dark'];
$theme = in_array($_SESSION['theme'] ?? '', $allowed_themes, true)
    ? $_SESSION['theme']
    : 'light';

// Yetkiye göre anasayfa URL'si
if ($yetki === 'admin') {
    $homeUrl = '/admin-dashboard.php';
} elseif ($yetki === 'egitmen') {
    $homeUrl = '/egitmen-dashboard.php';
} else {
    $homeUrl = '/index.php';
}

/* Sayfa özel ayarlar */
$pageCss       = $pageCss ?? null;
$pageBodyClass = $pageBodyClass ?? '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Edoras Akademi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

    <!-- GLOBAL THEME (tema değişkeni allow-listeden geçirildi) -->
    <link rel="stylesheet" href="/css/theme-<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>.css">
    <link rel="stylesheet" href="/css/header-<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>.css">

    <!-- Sayfa özel CSS -->
    <?php if ($pageCss): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($pageCss, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body class="<?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?>-theme <?= htmlspecialchars($pageBodyClass, ENT_QUOTES, 'UTF-8') ?>">

<header class="main-header">
    <nav class="navbar navbar-expand-lg">
        <div class="container">

            <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="site-logo">
                <img src="/images/edoras-logo-yazi.png" alt="Edoras Akademi">
            </a>

            <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#mainMenu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="collapse navbar-collapse" id="mainMenu">
                <ul class="navbar-nav ms-auto gap-2">

                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Anasayfa</a></li>

                    <?php if ($yetki === 'kullanici'): ?>
                        <li class="nav-item"><a class="nav-link" href="/antrenman-programim.php">Antrenman</a></li>
                        <li class="nav-item"><a class="nav-link" href="/beslenme-programim.php">Beslenme</a></li>
                    <?php endif; ?>

                    <?php if ($yetki === 'egitmen'): ?>
                        <li class="nav-item"><a class="nav-link" href="/uye_secimi.php">Üyeler</a></li>
                        <li class="nav-item"><a class="nav-link" href="/antrenman-olustur.php">Antrenman Oluştur</a></li>
                        <li class="nav-item"><a class="nav-link" href="/beslenme-olustur.php">Beslenme Oluştur</a></li>
                        <li class="nav-item"><a class="nav-link" href="/egitmen-takvim.php">Seans Takvimi</a></li>
                    <?php endif; ?>

                    <?php if ($yetki === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="/uye-yonetimi.php">Üyeler</a></li>
                        <li class="nav-item"><a class="nav-link" href="/egzersiz-ekle.php">Egzersiz Ekle</a></li>
                        <li class="nav-item"><a class="nav-link" href="/besin-ekle.php">Besin Ekle</a></li>
                        <li class="nav-item"><a class="nav-link" href="/salon-takvim.php">Salon Takvimi</a></li>
                    <?php endif; ?>

                    <li class="nav-item"><a class="nav-link" href="/logout.php"><i class="fa-solid fa-right-from-bracket"></i></a></li>

                </ul>
            </div>
        </div>
    </nav>
</header>

<main class="container my-5">
