<?php
/**
 * register.php
 * Güvenlik: CSRF, hata mesajı gizleme, şifre min uzunluğu.
 */

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
send_security_headers();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF kontrolü
    csrf_verify();

    require_once __DIR__ . '/inc/db.php';

    $ad       = trim($_POST['ad'] ?? '');
    $soyad    = trim($_POST['soyad'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $tel_no   = trim($_POST['tel_no'] ?? '');
    $yetki    = 'kullanici'; // Kayıt ile admin/egitmen olunamaz
    $rawPassword = $_POST['password'] ?? '';

    if ($ad === '' || $soyad === '' || $email === '' || $rawPassword === '') {
        $error = 'Lütfen zorunlu alanları doldurun.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta adresi girin.';
    } elseif (strlen($rawPassword) < 8) {
        $error = 'Şifre en az 8 karakter olmalıdır.';
    } else {
        $password = password_hash($rawPassword, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("
            INSERT INTO uye_kullanicilar
            (ad, soyad, eposta_adresi, sifre, tel_no, yetki)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log('Register prepare hatası: ' . $conn->error);
            $error = 'Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.';
        } else {
            $stmt->bind_param('ssssss', $ad, $soyad, $email, $password, $tel_no, $yetki);

            if ($stmt->execute()) {
                $user_id = (int)$stmt->insert_id;
                $stmt->close();

                // Profil satırı oluştur
                $stmtProfil = $conn->prepare("
                    INSERT INTO profil_bilgileri (
                        user_id, spor_hedefi, spor_deneyimi, saglik_sorunlari,
                        yas, boy_cm, kilo_kg, boyun_cevresi, bel_cevresi,
                        basen_cevresi, baslangic_tarihi
                    ) VALUES (?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)
                ");

                if ($stmtProfil) {
                    $stmtProfil->bind_param('i', $user_id);
                    $stmtProfil->execute();
                    $stmtProfil->close();
                }

                header('Location: /login.php?registered=1');
                exit;
            } else {
                // Kullanıcıya DB iç hataları gösterme
                if (stripos($stmt->error, 'eposta_adresi') !== false || stripos($stmt->error, 'uniq_eposta') !== false) {
                    $error = 'Bu e-posta adresi zaten kayıtlı.';
                } else {
                    error_log('Register execute hatası: ' . $stmt->error);
                    $error = 'Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kayıt Ol - Edoras Akademi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css">
</head>

<body class="auth-body">

<div class="auth-wrapper">
    <div class="auth-card">

        <h2 class="auth-title">Kayıt Ol</h2>
        <p class="auth-subtitle">Edoras Akademi'ye katıl</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <input type="text" name="ad" class="form-control auth-input" placeholder="Ad" required
                           value="<?= htmlspecialchars($_POST['ad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <input type="text" name="soyad" class="form-control auth-input" placeholder="Soyad" required
                           value="<?= htmlspecialchars($_POST['soyad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="mb-3">
                <input type="email" name="email" class="form-control auth-input" placeholder="E-posta adresi" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
                <input type="text" name="tel_no" class="form-control auth-input" placeholder="Telefon numarası"
                       value="<?= htmlspecialchars($_POST['tel_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-4">
                <input type="password" name="password" class="form-control auth-input"
                       placeholder="Şifre (min. 8 karakter)" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn auth-btn w-100">Kayıt Ol</button>

            <div class="auth-footer">
                Zaten hesabın var mı? <a href="login.php">Giriş Yap</a>
            </div>
        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
