<?php

namespace ForumChat;

class Complaint
{
    private $conn;
    private $table = 'complaints';

    public $id;
    public $post_id;
    public $topic_id;
    public $user_id;
    public $complainant_id;
    public $reason;
    public $status;
    public $created_at;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTableStructure();
    }

    private function ensureTableStructure()
    {
        try {
            // Проверяем есть ли колонка topic_id
            $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'complaints' 
                        AND COLUMN_NAME = 'topic_id'";
            $stmt = $this->conn->prepare($checkSql);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                // Добавляем колонку topic_id если её нет
                $alterSql = "ALTER TABLE `complaints` 
                            ADD COLUMN `topic_id` bigint(20) DEFAULT NULL AFTER `post_id`,
                            ADD COLUMN `complainant_id` bigint(20) DEFAULT NULL AFTER `user_id`";
                $this->conn->exec($alterSql);
            }
        } catch (Exception $e) {
            // Таблица уже имеет необходимую структуру
        }
    }

    public function create()
    {
        $sql = "INSERT INTO {$this->table} 
                (post_id, topic_id, user_id, complainant_id, reason, status)
                VALUES (:post_id, :topic_id, :user_id, :complainant_id, :reason, :status)";
        $stmt = $this->conn->prepare($sql);

        $this->reason = htmlspecialchars(strip_tags($this->reason));
        $this->status = $this->status ?: 'pending';

        $stmt->bindParam(':post_id', $this->post_id);
        $stmt->bindParam(':topic_id', $this->topic_id);
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':complainant_id', $this->complainant_id);
        $stmt->bindParam(':reason', $this->reason);
        $stmt->bindParam(':status', $this->status);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getAll()
    {
        $sql = "SELECT c.*, 
                p.id as post_exists, 
                t.title as topic_title,
                u.username as reported_user,
                cu.username as complainant_username
                FROM {$this->table} c
                LEFT JOIN posts p ON p.id = c.post_id
                LEFT JOIN topics t ON t.id = c.topic_id
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN users cu ON cu.id = c.complainant_id
                ORDER BY c.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function getAllPending()
    {
        $sql = "SELECT c.*, 
                p.id as post_exists, 
                t.title as topic_title,
                u.username as reported_user,
                cu.username as complainant_username
                FROM {$this->table} c
                LEFT JOIN posts p ON p.id = c.post_id
                LEFT JOIN topics t ON t.id = c.topic_id
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN users cu ON cu.id = c.complainant_id
                WHERE c.status = 'pending'
                ORDER BY c.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function getByType($type)
    {
        $column = null;
        if ($type === 'post') {
            $column = 'post_id';
        } elseif ($type === 'topic') {
            $column = 'topic_id';
        } elseif ($type === 'user') {
            $column = 'user_id';
        }

        if (!$column) {
            return $this->conn->prepare("SELECT * FROM {$this->table} WHERE 1=0");
        }

        $sql = "SELECT c.*, 
                p.id as post_exists, 
                t.title as topic_title,
                u.username as reported_user,
                cu.username as complainant_username
                FROM {$this->table} c
                LEFT JOIN posts p ON p.id = c.post_id
                LEFT JOIN topics t ON t.id = c.topic_id
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN users cu ON cu.id = c.complainant_id
                WHERE c.$column IS NOT NULL AND c.status = 'pending'
                ORDER BY c.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function getById($id)
    {
        $sql = "SELECT c.*, 
                p.id as post_exists, 
                t.title as topic_title,
                u.username as reported_user,
                cu.username as complainant_username
                FROM {$this->table} c
                LEFT JOIN posts p ON p.id = c.post_id
                LEFT JOIN topics t ON t.id = c.topic_id
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN users cu ON cu.id = c.complainant_id
                WHERE c.id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status)
    {
        if (!in_array($status, ['pending', 'resolved', 'rejected'])) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function delete($id)
    {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function countPending()
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE status = 'pending'";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
    }

    public function countByType($type)
    {
        $column = null;
        if ($type === 'post') {
            $column = 'post_id';
        } elseif ($type === 'topic') {
            $column = 'topic_id';
        } elseif ($type === 'user') {
            $column = 'user_id';
        }

        if (!$column) {
            return 0;
        }

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE $column IS NOT NULL AND status = 'pending'";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
    }
}
