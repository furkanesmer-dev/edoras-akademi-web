<?php
session_start();
if (!isset($_SESSION['user'])) {
    // Giriş yapılmamışsa login.php sayfasına yönlendirilir
    header("Location: login.php");
    exit;
}
?>
