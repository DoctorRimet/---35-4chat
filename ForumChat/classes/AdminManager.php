<?php

class AdminManager
{
    private $conn;
    private $table = 'admin_actions';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Получить все действия админов
     */
    public function getAllActions($limit = 50, $offset = 0, $filters = [])
    {
        $sql = "SELECT aa.*, u.username as admin_name, p.content as post_content
                FROM {$this->table} aa
                LEFT JOIN users u ON u.id = aa.admin_id
                LEFT JOIN posts p ON p.id = aa.target_id AND aa.target_type = 'post'
                WHERE 1=1";

        $params = [];

        if (!empty($filters['admin_id'])) {
            $sql .= " AND aa.admin_id = :admin_id";
            $params[':admin_id'] = $filters['admin_id'];
        }

        if (!empty($filters['action_type'])) {
            $sql .= " AND aa.action_type = :action_type";
            $params[':action_type'] = $filters['action_type'];
        }

        if (!empty($filters['target_type'])) {
            $sql .= " AND aa.target_type = :target_type";
            $params[':target_type'] = $filters['target_type'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND aa.created_at >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND aa.created_at <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY aa.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindParam($key, $value);
        }

        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество действий
     */
    public function getActionsCount($filters = [])
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} aa WHERE 1=1";

        if (!empty($filters['admin_id'])) {
            $sql .= " AND aa.admin_id = :admin_id";
        }

        if (!empty($filters['action_type'])) {
            $sql .= " AND aa.action_type = :action_type";
        }

        $stmt = $this->conn->prepare($sql);

        if (!empty($filters['admin_id'])) {
            $stmt->bindParam(':admin_id', $filters['admin_id']);
        }

        if (!empty($filters['action_type'])) {
            $stmt->bindParam(':action_type', $filters['action_type']);
        }

        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Получить действия админа
     */
    public function getAdminActions($admin_id, $limit = 50, $offset = 0)
    {
        $sql = "SELECT aa.*, p.content, u.username as target_user 
                FROM {$this->table} aa
                LEFT JOIN posts p ON p.id = aa.target_id AND aa.target_type = 'post'
                LEFT JOIN users u ON u.id = aa.target_id AND aa.target_type = 'user'
                WHERE aa.admin_id = :admin_id
                ORDER BY aa.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить статистику по типам действий
     */
    public function getActionStats($days = 7)
    {
        $sql = "SELECT 
                    action_type,
                    COUNT(*) as count
                FROM {$this->table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY action_type
                ORDER BY count DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить статистику по админам
     */
    public function getAdminStats($days = 7)
    {
        $sql = "SELECT 
                    u.id,
                    u.username,
                    COUNT(aa.id) as action_count
                FROM users u
                LEFT JOIN {$this->table} aa ON aa.admin_id = u.id 
                    AND aa.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                WHERE u.user_role IN ('admin', 'moderator')
                GROUP BY u.id
                ORDER BY action_count DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить описание для типа действия
     */
    public function getActionDescription($action_type, $details = null)
    {
        $descriptions = [
            'post_mark_delete' => 'Пост отмечен на удаление',
            'post_delete' => 'Пост удален',
            'post_hide' => 'Пост скрыт',
            'post_unhide' => 'Пост восстановлен',
            'auto_hide_post' => 'Пост автоматически скрыт (запрещённые слова)',
            'manual_unhide_post' => 'Пост восстановлен вручную',
            'user_ban' => 'Пользователь заблокирован',
            'user_unban' => 'Пользователь разблокирован',
            'forbidden_word_add' => 'Добавлено запрещённое слово',
            'forbidden_word_delete' => 'Удалено запрещённое слово'
        ];

        $description = $descriptions[$action_type] ?? 'Неизвестное действие';

        if ($details) {
            $description .= ': ' . $details;
        }

        return $description;
    }

    /**
     * Получить сводку действий за период
     */
    public function getSummaryReport($from_date, $to_date)
    {
        $report = [
            'period' => "$from_date to $to_date",
            'total_actions' => 0,
            'actions_by_type' => [],
            'actions_by_admin' => [],
            'top_admins' => [],
            'affected_posts' => 0,
            'affected_users' => 0
        ];

        // Общее количество действий
        $countSql = "SELECT COUNT(*) as count FROM {$this->table} 
                    WHERE created_at BETWEEN :from AND :to";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bindParam(':from', $from_date);
        $countStmt->bindParam(':to', $to_date);
        $countStmt->execute();
        $result = $countStmt->fetch(PDO::FETCH_ASSOC);
        $report['total_actions'] = $result['count'] ?? 0;

        // По типам
        $typeSql = "SELECT action_type, COUNT(*) as count FROM {$this->table}
                   WHERE created_at BETWEEN :from AND :to
                   GROUP BY action_type";
        $typeStmt = $this->conn->prepare($typeSql);
        $typeStmt->bindParam(':from', $from_date);
        $typeStmt->bindParam(':to', $to_date);
        $typeStmt->execute();
        $report['actions_by_type'] = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

        // По админам
        $adminSql = "SELECT u.username, COUNT(aa.id) as count FROM {$this->table} aa
                    JOIN users u ON u.id = aa.admin_id
                    WHERE aa.created_at BETWEEN :from AND :to
                    GROUP BY aa.admin_id
                    ORDER BY count DESC";
        $adminStmt = $this->conn->prepare($adminSql);
        $adminStmt->bindParam(':from', $from_date);
        $adminStmt->bindParam(':to', $to_date);
        $adminStmt->execute();
        $report['top_admins'] = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

        // Затронутые посты
        $postsSql = "SELECT COUNT(DISTINCT target_id) as count FROM {$this->table}
                    WHERE target_type = 'post' AND created_at BETWEEN :from AND :to";
        $postsStmt = $this->conn->prepare($postsSql);
        $postsStmt->bindParam(':from', $from_date);
        $postsStmt->bindParam(':to', $to_date);
        $postsStmt->execute();
        $result = $postsStmt->fetch(PDO::FETCH_ASSOC);
        $report['affected_posts'] = $result['count'] ?? 0;

        // Затронутые пользователи
        $usersSql = "SELECT COUNT(DISTINCT target_id) as count FROM {$this->table}
                    WHERE target_type = 'user' AND created_at BETWEEN :from AND :to";
        $usersStmt = $this->conn->prepare($usersSql);
        $usersStmt->bindParam(':from', $from_date);
        $usersStmt->bindParam(':to', $to_date);
        $usersStmt->execute();
        $result = $usersStmt->fetch(PDO::FETCH_ASSOC);
        $report['affected_users'] = $result['count'] ?? 0;

        return $report;
    }

    /**
     * Получить историю модераций (последние 90 дней)
     */
    public function getModerationLog($limit = 50, $offset = 0, $filters = [])
    {
        $sql = "SELECT ml.*, 
                       m.username as moderator_name,
                       t.username as target_username,
                       tp.title as post_title,
                       c.content as comment_content
                FROM moderation_log ml
                LEFT JOIN users m ON m.id = ml.moderator_id
                LEFT JOIN users t ON t.id = ml.target_id AND ml.target_type = 'user'
                LEFT JOIN posts p ON p.id = ml.target_id AND ml.target_type = 'post'
                LEFT JOIN topics tp ON tp.id = p.topic_id AND ml.target_type = 'post'
                LEFT JOIN comments c ON c.id = ml.target_id AND ml.target_type = 'comment'
                WHERE ml.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";

        $params = [];

        if (!empty($filters['moderator_id'])) {
            $sql .= " AND ml.moderator_id = :moderator_id";
            $params[':moderator_id'] = $filters['moderator_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND ml.action = :action";
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['target_type'])) {
            $sql .= " AND ml.target_type = :target_type";
            $params[':target_type'] = $filters['target_type'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND ml.created_at >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND ml.created_at <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY ml.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество записей в логе модераций
     */
    public function getModerationLogCount($filters = [])
    {
        $sql = "SELECT COUNT(*) as count FROM moderation_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";

        $params = [];

        if (!empty($filters['moderator_id'])) {
            $sql .= " AND moderator_id = :moderator_id";
            $params[':moderator_id'] = $filters['moderator_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = :action";
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['target_type'])) {
            $sql .= " AND target_type = :target_type";
            $params[':target_type'] = $filters['target_type'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND created_at >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND created_at <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Получить пользователей с информацией о банах
     */
    public function getUsersWithBanInfo($limit = 50, $offset = 0, $filters = [])
    {
        $sql = "SELECT id, username, email, user_role, status, ban_until, ban_reason, created_at, updated_at
                FROM users
                WHERE 1=1";

        $params = [];

        if (!empty($filters['username'])) {
            $sql .= " AND username LIKE :username";
            $params[':username'] = '%' . $filters['username'] . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
