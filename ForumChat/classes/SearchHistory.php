<?php

class SearchHistory
{
    private $conn;
    private $table = 'search_history';

    public $id;
    public $user_id;
    public $query;
    public $search_date;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTableExists();
    }

    private function ensureTableExists()
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `search_history` (
                `id` BIGINT(20) PRIMARY KEY AUTO_INCREMENT,
                `user_id` BIGINT(20) NOT NULL,
                `query` VARCHAR(255) NOT NULL,
                `search_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                INDEX `idx_user_date` (`user_id`, `search_date` DESC),
                INDEX `idx_search_date` (`search_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
            $this->conn->exec($sql);
        } catch (Exception $e) {
            // Таблица уже существует или другая ошибка, игнорируем
        }
    }

    // Добавить поиск в историю
    public function addSearch($user_id, $query)
    {
        if (!$user_id || empty(trim($query))) {
            return false;
        }

        $query = trim($query);
        if (strlen($query) < 3) {
            return false;
        }

        $sql = "INSERT INTO {$this->table} (user_id, query, search_date) 
                VALUES (:user_id, :query, NOW())";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':query', $query);

        return $stmt->execute();
    }

    // Получить историю поисков пользователя (последние 30 дней)
    public function getHistory($user_id, $limit = 20)
    {
        $sql = "SELECT DISTINCT query, MAX(search_date) as last_search
                FROM {$this->table}
                WHERE user_id = :user_id AND search_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY query
                ORDER BY last_search DESC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Получить топ поисков (по количеству)
    public function getTopSearches($user_id, $limit = 10)
    {
        $sql = "SELECT query, COUNT(*) as search_count, MAX(search_date) as last_search
                FROM {$this->table}
                WHERE user_id = :user_id AND search_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY query
                ORDER BY search_count DESC, last_search DESC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Удалить один поиск из истории
    public function deleteSearch($user_id, $query)
    {
        $sql = "DELETE FROM {$this->table} 
                WHERE user_id = :user_id AND query = :query";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':query', $query);
        return $stmt->execute();
    }

    // Удалить всю историю поисков пользователя
    public function clearHistory($user_id)
    {
        $sql = "DELETE FROM {$this->table} WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // Очистить устаревшую историю (старше 30 дней) для всех пользователей
    public static function cleanupOldHistory($conn)
    {
        $sql = "DELETE FROM search_history WHERE search_date < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        return $conn->exec($sql);
    }
}
