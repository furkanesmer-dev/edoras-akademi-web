<?php
/**
 * login.php
 * Güvenlik: CSRF koruması, session fixation önleme, hata mesajı gizleme.
 */

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
send_security_headers();

// Zaten giriş yapmışsa yönlendir
if (isset($_SESSION['user'])) {
    $yetki = $_SESSION['user']['yetki'] ?? 'kullanici';
    if ($yetki === 'admin') {
        header('Location: /admin-dashboard.php');
    } elseif ($yetki === 'egitmen') {
        header('Location: /egitmen-dashboard.php');
    } else {
        header('Location: /index.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF kontrolü
    csrf_verify();

    require_once __DIR__ . '/inc/db.php';

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'E-posta / telefon ve şifre zorunludur.';
    } else {
        $stmt = $conn->prepare("
            SELECT id, ad, soyad, eposta_adresi, tel_no, sifre, yetki, egitmen_id
            FROM uye_kullanicilar
            WHERE eposta_adresi = ? OR tel_no = ?
            LIMIT 1
        ");
        if (!$stmt) {
            error_log('Login prepare hatası: ' . $conn->error);
            $error = 'Sistem hatası. Lütfen daha sonra tekrar deneyin.';
        } else {
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            if ($user && isset($user['sifre']) && password_verify($password, $user['sifre'])) {

                // Session Fixation önleme: yeni oturum ID'si oluştur
                session_regenerate_id(true);

                $yetki = $user['yetki'] ?: 'kullanici';

                // Şifre hash'ini oturuma kaydetme
                $safe_user = [
                    'id'             => (int)$user['id'],
                    'ad'             => $user['ad'],
                    'soyad'          => $user['soyad'],
                    'eposta_adresi'  => $user['eposta_adresi'],
                    'tel_no'         => $user['tel_no'],
                    'yetki'          => $yetki,
                    'egitmen_id'     => $user['egitmen_id'],
                ];

                $_SESSION['user']    = $safe_user;
                $_SESSION['user_id'] = (int)$user['id'];

                if ($yetki === 'admin') {
                    header('Location: /admin-dashboard.php');
                } elseif ($yetki === 'egitmen') {
                    header('Location: /egitmen-dashboard.php');
                } else {
                    header('Location: /index.php');
                }
                exit;

            } else {
                // Genel hata mesajı - hangi alanın yanlış olduğunu belirtme (kullanıcı numaralandırma önleme)
                $error = 'E-posta / telefon veya şifre hatalı.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Giriş Yap - Edoras Akademi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-wrapper">
    <div class="auth-card">
        <h3 class="auth-title">Hoş Geldin</h3>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger text-center">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <input type="text" name="login" class="form-control auth-input"
                       placeholder="Email veya Telefon" required
                       value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group mt-3">
                <input type="password" name="password" class="form-control auth-input"
                       placeholder="Parola" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn auth-btn mt-4">Giriş Yap</button>

            <div class="auth-footer">
                Hesabın yok mu? <a href="register.php">Kayıt Ol</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
