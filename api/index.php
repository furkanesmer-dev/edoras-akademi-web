

<?php

require_once __DIR__ . '/inc/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Sunucunda /api/ ile başlıyorsa kırp
// Örn: /api/auth/login => auth/login
$path = preg_replace('#^/api/?#', '', $path);
$path = trim($path, '/');

switch ($path) {
  case 'auth/login':
    require __DIR__ . '/auth/login.php';
    break;

  case 'me':
    require __DIR__ . '/me.php';
    break;
  
  case 'user/workout-plan/current':
    require __DIR__ . '/user/workout_plan_current.php';
    break;

  case 'user/nutrition-plan/current':
    require __DIR__ . '/user/nutrition_plan_current.php';
    break;

  default:
    json_fail('Endpoint bulunamadı.', 404, ['path'=>$path]);
}