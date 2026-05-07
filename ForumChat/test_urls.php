<?php
/**
 * Быстрый тест доступа к API файлам
 */

// Проверим доступность файлов
echo "=== Проверка файлов API ===\n\n";

$files = [
    __DIR__ . '/api/test.php',
    __DIR__ . '/api/search.php',
    __DIR__ . '/pages/search.php',
    __DIR__ . '/index.php'
];

foreach ($files as $file) {
    $exists = file_exists($file);
    $readable = is_readable($file);
    $status = $exists ? ($readable ? '✓ OK' : '✗ Not readable') : '✗ Not found';
    echo basename(dirname($file)) . '/' . basename($file) . ': ' . $status . "\n";
}

echo "\n=== Попробуйте эти URL ===\n";
echo "Главная:     http://forumchat/\n";
echo "Главная:     http://forumchat/index.php\n";
echo "API Тест:    http://forumchat/api/test.php\n";
echo "Поиск:       http://forumchat/pages/search.php\n";
echo "API Поиск:   http://forumchat/api/search.php?q=тест\n";
