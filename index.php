<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

$yetki = $_SESSION['user']['yetki'] ?? 'kullanici';

// Eğitmen/Admin kullanıcı dashboard'u görmesin
if ($yetki === 'egitmen' || $yetki === 'admin') {
    header("Location: /egitmen-dashboard.php");
    exit;
}

include "inc/header.php"; // $user değişkeni burada geliyor
?>
<link rel="stylesheet" href="/css/index-light.css">


<div class="container py-5">

    <!-- Welcome -->
    <div class="text-center mb-5">
        <h2 class="welcome-title">
            Merhaba, <?= htmlspecialchars(ucfirst($user['ad']) . ' ' . ucfirst($user['soyad']), ENT_QUOTES, 'UTF-8') ?> 👋
        </h2>
        <p class="welcome-subtitle">
            Kontrol panelinize hoş geldiniz
        </p>
    </div>

    <!-- Dashboard Cards -->
    <div class="row g-4 justify-content-center">

        <div class="col-md-4">
            <div class="dashboard-card dark-card success">
                <div class="card-body text-center">
                    <div class="card-icon">🏋️</div>
                    <h5 class="card-title">Antrenman Programım</h5>
                    <p class="card-text">
                        Size özel hazırlanan antrenman programınızı görüntüleyin.
                    </p>
                    <a href="antrenman-programim.php" class="btn btn-glow dark">
                        Görüntüle
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="dashboard-card dark-card primary">
                <div class="card-body text-center">
                    <div class="card-icon">🥗</div>
                    <h5 class="card-title">Beslenme Programım</h5>
                    <p class="card-text">
                        Günlük beslenme planınızı ve makrolarınızı görüntüleyin.
                    </p>
                    <a href="beslenme-programim.php" class="btn btn-glow dark">
                        Görüntüle
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="dashboard-card dark-card warning">
                <div class="card-body text-center">
                    <div class="card-icon">👤</div>
                    <h5 class="card-title">Profilim</h5>
                    <p class="card-text">
                        Kişisel bilgilerinizi ve ölçümlerinizi güncelleyin.
                    </p>
                    <a href="profilim.php" class="btn btn-glow dark">
                        Görüntüle
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include "inc/footer.php"; ?>
