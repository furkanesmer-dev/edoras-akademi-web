<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Oturum yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$selfId = (int)$_SESSION['user']['id'];
$selfRole = (string)($_SESSION['user']['yetki'] ?? 'kullanici');

/**
 * user_id belirleme + YETKİLENDİRME:
 * - kullanici: sadece kendi planını görebilir
 * - egitmen: POST user_id ile kendi üyesinin planını görebilir
 * - admin: her planı görebilir
 */
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    $userId = $selfId;
}

// IDOR koruması: kullanici yalnızca kendi verisini görebilir
if ($selfRole === 'kullanici' && $userId !== $selfId) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Yetkisiz erişim.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// egitmen yalnızca kendi üyesinin verisini görebilir
if ($selfRole === 'egitmen' && $userId !== $selfId) {
    $chk = $conn->prepare("SELECT 1 FROM uye_kullanicilar WHERE id=? AND egitmen_id=? LIMIT 1");
    $chk->bind_param("ii", $userId, $selfId);
    $chk->execute();
    $allowed = (bool)$chk->get_result()->fetch_row();
    $chk->close();
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>'Bu üyenin verisine erişim yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'user_id bulunamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Son antrenman planını çek
 * created_at yoksa id DESC güvenlidir
 */
$sql = "SELECT plan_data 
        FROM workout_plans 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('get_workout_plan.php prepare hatası: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Sunucu hatası.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($planData);
$stmt->fetch();
$stmt->close();

/**
 * Hiç plan yoksa → boş ama geçerli v2 JSON dön
 */
if (!$planData) {
    echo json_encode([
        'version' => 2,
        'user_id' => $userId,
        'days' => [],
        'notes' => ''
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON geçerli mi kontrol et
 */
$decoded = json_decode($planData, true);
if ($decoded === null) {
    error_log(‘get_workout_plan.php: user_id=’ . $userId . ‘ için plan_data geçerli JSON değil.’);
    http_response_code(500);
    echo json_encode([‘ok’ => false, ‘msg’ => ‘Plan verisi okunamadı.’], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Planı olduğu gibi geri gönder
 * (Frontend zaten version / days yapısını biliyor)
 */
echo json_encode($decoded, JSON_UNESCAPED_UNICODE);