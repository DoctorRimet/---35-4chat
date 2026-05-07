<?php

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/NotificationManager.php';

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'] ?? 1;

$notificationManager = new NotificationManager($conn);

// Проверяем логику счётчиков
echo "=== Диагностика уведомлений ===\n\n";

// 1. Проверяем общее количество уведомлений
$sql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
echo "Всего уведомлений: $total\n";

// 2. Проверяем непрочитанные
$sql = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND read_status = 0";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$unread = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];
echo "Непрочитанных: $unread\n";

// 3. Проверяем через функцию
$unreadCount = $notificationManager->getUnreadCount($user_id);
echo "Непрочитанных (через метод): $unreadCount\n";

// 4. Проверяем через getUnread
$unreadNotifs = $notificationManager->getUnread($user_id, 100);
echo "Непрочитанных (через getUnread): " . count($unreadNotifs) . "\n";

// 5. Выводим последние уведомления
echo "\n=== Последние уведомления ===\n";
$sql = "SELECT id, type, message, read_status, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notifs as $n) {
    $status = $n['read_status'] == 0 ? 'НЕПРОЧИТАНО' : 'ПРОЧИТАНО';
    echo "ID: {$n['id']}, Type: {$n['type']}, Status: $status, Created: {$n['created_at']}\n";
    echo "  Message: {$n['message']}\n";
}

echo "\n✓ Диагностика завершена\n";
