<?php
// API tarafı DB include
require_once __DIR__ . '/../../inc/db.php';

// $conn değişkeni senin mevcut inc/db.php içinde tanımlı olmalı.
if (!isset($conn) || !$conn) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'msg'=>'DB bağlantısı yok.'], JSON_UNESCAPED_UNICODE);
  exit;
}