<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

echo "<h1>=== Тест ограничений для заблокированных пользователей ===</h1>";
echo "<pre>";

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        echo "✗ Ошибка: Не удалось подключиться к БД\n";
        exit(1);
    }

    echo "✓ Подключено к БД\n";

    $user = new User($conn);

    // Тест 1: Проверка активного пользователя
    echo "\nТест 1: Активный пользователь\n";
    $activeUser = $user->getById(1);
    if ($activeUser) {
        $isBanned = $user->isBanned($activeUser);
        echo "Пользователь {$activeUser['username']} заблокирован: " . ($isBanned ? 'Да' : 'Нет') . "\n";
    }

    // Тест 2: Проверка заблокированного пользователя (если есть)
    echo "\nТест 2: Поиск заблокированных пользователей\n";
    $stmt = $conn->prepare("SELECT id, username, status FROM users WHERE status = 'blocked' OR ban_until > NOW() LIMIT 5");
    $stmt->execute();
    $bannedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($bannedUsers)) {
        foreach ($bannedUsers as $bannedUser) {
            $banInfo = $user->getBanInfo($bannedUser['id']);
            echo "- {$bannedUser['username']}: " . json_encode($banInfo, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } else {
        echo "Заблокированных пользователей не найдено\n";
    }

    echo "\n=== Тест завершён ===\n";
    echo "\nПоведение системы:\n";
    echo "- Заблокированные пользователи могут входить в систему\n";
    echo "- При попытке создать контент показывается предупреждение\n";
    echo "- Создание контента блокируется на уровне PHP\n";
} catch (Exception $e) {
    echo "✗ Критическая ошибка: " . $e->getMessage() . "\n";
}
echo "</pre>";
