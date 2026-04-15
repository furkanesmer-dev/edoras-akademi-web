<?php
session_start();

// Kullanıcı girişini kontrol et
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Giriş yapan kullanıcının verilerini al
$user = $_SESSION['user'];
$user_id = $user['id']; // Kullanıcı ID'sini alıyoruz
$user_name = $user['ad'];
$user_surname = $user['soyad'];

// Veritabanından kullanıcının en son workout planını al
$stmt = $conn->prepare("SELECT plan_data FROM workout_plans WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id); // user_id'yi sorguya dahil ediyoruz
$stmt->execute();
$result = $stmt->get_result();
$workoutPlan = $result->fetch_assoc();

// Veritabanından gelen veriyi kontrol et
if ($workoutPlan && !empty($workoutPlan['plan_data'])) {
    // JSON verisini çözme
    $planData = json_decode($workoutPlan['plan_data'], true); // JSON verisini çöz
    // JSON verisi hatalıysa, uyarı ver
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON verisi hatalı!";
        exit;
    }
} else {
    $planData = null;
}
?>

<?php include "inc/header.php"; ?>

<div class="container mt-5">
    <h3 class="text-center mb-4">Hoş Geldiniz, <?php echo htmlspecialchars($user_name . ' ' . $user_surname, ENT_QUOTES, 'UTF-8'); ?> (ID: <?php echo (int)$user_id; ?>)!</h3>

    <?php if ($planData): ?>
        <h4 class="text-center">Haftalık Antrenman Planınız</h4>

        <!-- PDF Export Butonu -->
        <button id="download-pdf" class="btn btn-primary mb-4">PDF Olarak İndir</button>

        <table class="table table-bordered table-striped" id="workout-table">
            <thead style="background-color: #f2f2f2; color: #333;">
                <tr>
                    <th>User ID</th>
                    <th>İsim</th>
                    <th>Soyisim</th>
                    <th>Gün</th>
                    <th>Egzersiz Adı</th>
                    <th>Hafta</th>
                    <th>Setler</th>
                    <th>Hacim</th>
                    <th>Tekrarlar</th>
                </tr>
            </thead>
            <tbody style="background-color: #ffffff; color: #333;">
                <?php
                // Plan verisini döngüye alarak tabloya yazdır
                foreach ($planData as $day => $exercises) {
                    if ($day !== "notes" && !empty($exercises)) {
                        echo "<tr style='background-color: #f9f9f9;'><td colspan='9' class='text-center'><strong>$day</strong></td></tr>"; // Gün başlığı
                        foreach ($exercises as $exercise) {
                            $exerciseName = isset($exercise['exercise_name']) ? $exercise['exercise_name'] : 'Bilinmeyen Egzersiz';
                            echo "<tr>";
                            echo "<td rowspan='" . count($exercise['weeks']) . "'>$user_id</td>"; // User ID
                            echo "<td rowspan='" . count($exercise['weeks']) . "'>$user_name</td>"; // Kullanıcı Adı
                            echo "<td rowspan='" . count($exercise['weeks']) . "'>$user_surname</td>"; // Kullanıcı Soyadı
                            echo "<td rowspan='" . count($exercise['weeks']) . "'></td>"; // Gün başlığı burada sadece görünür değil
                            echo "<td rowspan='" . count($exercise['weeks']) . "'>{$exerciseName}</td>";

                            foreach ($exercise['weeks'] as $week) {
                                echo "<td>Hafta {$week['week']}</td>";
                                echo "<td>{$week['sets']}</td>";
                                echo "<td>{$week['volume']}</td>";
                                echo "<td>{$week['reps']}</td>";
                                echo "</tr>";
                            }
                        }
                    }
                }
                ?>
            </tbody>
        </table>

        <!-- Notlar -->
        <?php if (isset($planData['notes']) && $planData['notes'] !== ""): ?>
            <h5>Notlar</h5>
            <p><?php echo nl2br($planData['notes']); ?></p>
        <?php endif; ?>

    <?php else: ?>
        <p>Henüz bir antrenman planınız yok.</p>
    <?php endif; ?>

</div>

<?php include "inc/footer.php"; ?>

<!-- jsPDF ve AutoTable eklentisini dahil et -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.19/jspdf.plugin.autotable.js"></script>

<script>
    document.getElementById('download-pdf').addEventListener('click', function () {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        // Tablonun içeriğini al
        const table = document.getElementById('workout-table');
        doc.autoTable({ html: table });

        // PDF olarak indir
        doc.save('workout_plan.pdf');
    });
</script>
