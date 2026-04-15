<?php
/**
 * inc/env.php
 * .env dosyasını yükler (basit parser; framework gerektirmez).
 * inc/db.php tarafından kullanılır.
 */

function load_env(string $path = ''): void {
    if ($path === '') {
        // Proje kökünü bul
        $path = dirname(__DIR__) . '/.env';
    }

    if (!is_file($path) || !is_readable($path)) {
        return; // .env yoksa sistem ortam değişkenlerine bak
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Tırnak varsa soy
        if (
            strlen($value) >= 2
            && (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

load_env();
