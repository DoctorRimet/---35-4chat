<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Миграция БД ForumChat ===\n";
echo "Подключение к БД...\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        echo "✗ Ошибка: Не удалось подключиться к БД\n";
        exit(1);
    }
    
    echo "✓ Подключено к БД\n";
    
    // 1. Изменить VARCHAR(50) на VARCHAR(255) для admin_actions.action_type
    echo "\nШаг 1: Изменение длины action_type...\n";
    try {
        $sql = "ALTER TABLE admin_actions MODIFY COLUMN action_type VARCHAR(255) NOT NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        echo "✓ action_type успешно изменён на VARCHAR(255)\n";
    } catch (Exception $e) {
        echo "⚠️ Ошибка при изменении action_type: " . $e->getMessage() . "\n";
    }
    
    // 2. Добавить поле ban_until для временных банов
    echo "\nШаг 2: Добавление поля ban_until для пользователей...\n";
    try {
        $sql = "ALTER TABLE users ADD COLUMN ban_until DATETIME NULL DEFAULT NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        echo "✓ Поле ban_until добавлено в таблицу users\n";
    } catch (Exception $e) {
        echo "⚠️ Ошибка при добавлении ban_until: " . $e->getMessage() . "\n";
    }
    
    // 3. Добавить поле ban_reason для причины бана
    echo "\nШаг 3: Добавление поля ban_reason для пользователей...\n";
    try {
        $sql = "ALTER TABLE users ADD COLUMN ban_reason TEXT NULL DEFAULT NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        echo "✓ Поле ban_reason добавлено в таблицу users\n";
    } catch (Exception $e) {
        echo "⚠️ Ошибка при добавлении ban_reason: " . $e->getMessage() . "\n";
    }
    
    // 4. Создать таблицу moderation_log для истории модераций
    echo "\nШаг 4: Создание таблицы moderation_log...\n";
    try {
        $sql = "CREATE TABLE IF NOT EXISTS moderation_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            moderator_id BIGINT NOT NULL,
            action ENUM('ban', 'unban', 'post_hide', 'post_unhide', 'post_delete', 'comment_hide', 'comment_unhide', 'comment_delete', 'user_warn') NOT NULL,
            target_type ENUM('user', 'post', 'comment') NOT NULL,
            target_id BIGINT NOT NULL,
            reason TEXT,
            duration VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        echo "✓ Таблица moderation_log создана\n";
    } catch (Exception $e) {
        echo "⚠️ Ошибка при создании moderation_log: " . $e->getMessage() . "\n";
    }
    
    // 5. Добавить индекс для быстрого поиска по дате
    echo "\nШаг 5: Добавление индекса на created_at в moderation_log...\n";
    try {
        $sql = "CREATE INDEX idx_moderation_log_created_at ON moderation_log (created_at)";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        echo "✓ Индекс на created_at добавлен\n";
    } catch (Exception $e) {
        echo "⚠️ Ошибка при добавлении индекса: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== Миграция завершена ===\n";
    
} catch (Exception $e) {
    echo "✗ Критическая ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

