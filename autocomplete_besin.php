<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc/security.php';
configure_error_reporting();

// Oturum kontrolü: giriş yapmamış kullanıcılar besin araması yapamaz
if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Oturum yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- DB include (robust) ---
$__db_loaded = false;
foreach ([__DIR__ . '/inc/db.php', __DIR__ . '/db.php', __DIR__ . '/baglanti.php', __DIR__ . '/config/db.php'] as $__p) {
    if (file_exists($__p)) {
        require_once $__p;
        $__db_loaded = true;
        break;
    }
}

if (!$__db_loaded) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB bağlantı dosyası bulunamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($mysqli) && ($mysqli instanceof mysqli)) {
        $conn = $mysqli;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB bağlantısı ($conn) bulunamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';

$sql = "
    SELECT
        id,
        ad,
        birim_tip,
        baz_miktar,
        kalori,
        protein,
        yag,
        karbonhidrat
    FROM besinler
    WHERE aktif = 1
      AND ad LIKE ?
    ORDER BY ad ASC
    LIMIT 15
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $like);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

while ($row = $res->fetch_assoc()) {
    $birimTip = (string)$row['birim_tip'];
    $bazMiktar = (float)$row['baz_miktar'];

    $cins = 'gram';
    if ($birimTip === 'adet') {
        $cins = 'adet';
    } elseif ($birimTip === 'ml') {
        $cins = 'ml';
    }

    $data[] = [
        // eski frontend uyumu
        "label" => $row["ad"],
        "value" => $row["ad"],
        "Kalori" => (float)$row["kalori"],
        "Karbonhidrat" => (float)$row["karbonhidrat"],
        "Protein" => (float)$row["protein"],
        "Yag" => (float)$row["yag"],
        "Cins" => $cins,

        // yeni sistem alanları
        "besin_id" => (int)$row["id"],
        "besin_adi" => $row["ad"],
        "birim_tip" => $birimTip,
        "baz_miktar" => $bazMiktar,
        "kalori" => (float)$row["kalori"],
        "protein" => (float)$row["protein"],
        "yag" => (float)$row["yag"],
        "karbonhidrat" => (float)$row["karbonhidrat"],

        // açıklama için yardımcı alan
        "birim_label" => $birimTip === 'adet' ? 'Adet' : ($birimTip === 'ml' ? 'Ml' : 'Gram'),
    ];
}

$stmt->close();

echo json_encode($data, JSON_UNESCAPED_UNICODE);