<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/AdminManager.php';

echo "=== Тест системы банов ===\n";

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        echo "✗ Ошибка: Не удалось подключиться к БД\n";
        exit(1);
    }

    echo "✓ Подключено к БД\n";

    $user = new User($conn);
    $adminManager = new AdminManager($conn);

    // Тест 1: Проверка метода isBanned для активного пользователя
    echo "\nТест 1: Проверка активного пользователя\n";
    $testUser = $user->getById(1); // Предполагаем, что пользователь с ID 1 существует
    if ($testUser) {
        $isBanned = $user->isBanned($testUser);
        echo "Пользователь " . $testUser['username'] . " заблокирован: " . ($isBanned ? 'Да' : 'Нет') . "\n";
    } else {
        echo "Тестовый пользователь не найден\n";
    }

    // Тест 2: Проверка получения информации о бане
    echo "\nТест 2: Получение информации о бане\n";
    $banInfo = $user->getBanInfo(1);
    echo "Информация о бане: " . json_encode($banInfo, JSON_UNESCAPED_UNICODE) . "\n";

    // Тест 3: Проверка получения пользователей с информацией о банах
    echo "\nТест 3: Получение списка пользователей\n";
    $users = $adminManager->getUsersWithBanInfo(5, 0);
    echo "Найдено пользователей: " . count($users) . "\n";
    if (!empty($users)) {
        foreach ($users as $u) {
            echo "- " . $u['username'] . ": " . $u['ban_status'] . "\n";
        }
    }

    echo "\n=== Тест завершён ===\n";
} catch (Exception $e) {
    echo "✗ Критическая ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
