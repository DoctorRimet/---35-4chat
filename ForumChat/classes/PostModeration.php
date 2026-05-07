<?php

class PostModeration
{
    private $conn;
    private $table = 'post_deletion_marks';
    private $notifications_table = 'notifications';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Установить метку на удаление поста
     */
    public function markForDeletion($post_id, $marked_by, $reason, $hidden = false, $scheduled_delete_at = null)
    {
        $sql = "INSERT INTO {$this->table} 
                (post_id, marked_by, reason, hidden, scheduled_delete_at) 
                VALUES (:post_id, :marked_by, :reason, :hidden, :scheduled_delete_at)";

        $stmt = $this->conn->prepare($sql);
        $post_id_int = (int)$post_id;
        $marked_by_int = (int)$marked_by;
        $hidden_int = (int)$hidden;

        $stmt->bindParam(':post_id', $post_id_int, PDO::PARAM_INT);
        $stmt->bindParam(':marked_by', $marked_by_int, PDO::PARAM_INT);
        $stmt->bindParam(':reason', $reason);
        $stmt->bindParam(':hidden', $hidden_int, PDO::PARAM_INT);
        $stmt->bindParam(':scheduled_delete_at', $scheduled_delete_at);

        if ($stmt->execute()) {
            // Если пост скрыт, обновляем таблицу posts
            if ($hidden) {
                $this->hidePost($post_id, $reason);
            }

            // Логируем действие
            $this->logAdminAction($marked_by, 'post_mark_delete', 'post', $post_id, $reason);

            // Отправляем уведомление автору
            $this->notifyPostAuthor($post_id, 'post_marked_delete', $marked_by, $reason);

            return true;
        }
        return false;
    }

    /**
     * Скрыть пост и залогировать действие
     */
    public function hidePost($post_id, $reason = 'Содержит запрещённые слова', $hidden_by_id = null)
    {
        // Получаем информацию о посте для отправки уведомления
        $sql = "SELECT author_id FROM posts WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $post_id);
        $stmt->execute();
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        $sql = "UPDATE posts 
                SET hidden = 1, hidden_reason = :reason, hidden_at = NOW() 
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':reason', $reason);
        $stmt->bindParam(':id', $post_id);

        if ($stmt->execute()) {
            if ($hidden_by_id) {
                // Логируем действие
                $this->logAdminAction($hidden_by_id, 'post_hide', 'post', $post_id, $reason);
                $this->logModerationAction($hidden_by_id, 'post_hide', 'post', $post_id, $reason);
            }

            // Отправляем уведомление автору поста
            if ($post && !empty($post['author_id'])) {
                require_once __DIR__ . '/User.php';
                $userClass = new User($this->conn);
                $userClass->notifyPostHidden($post['author_id'], $reason);
            }

            return true;
        }

        return false;
    }

    /**
     * Показать скрытый пост
     */
    public function unhidePost($post_id, $moderator_id = null)
    {
        $sql = "UPDATE posts 
                SET hidden = 0, hidden_reason = NULL, hidden_at = NULL 
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $post_id);

        if ($stmt->execute()) {
            if ($moderator_id) {
                // Логируем действие
                $this->logAdminAction($moderator_id, 'post_unhide', 'post', $post_id, 'Восстановлен администратором');
                $this->logModerationAction($moderator_id, 'post_unhide', 'post', $post_id, 'Восстановлен администратором');
            }
            return true;
        }

        return false;
    }

    /**
     * Удалить пост (мягкое удаление)
     */
    public function deletePost($post_id, $deleted_by, $reason = 'Пост нарушает правила')
    {
        // Получаем информацию о посте для отправки уведомления
        $sql = "SELECT author_id FROM posts WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $post_id);
        $stmt->execute();
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        $sql = "UPDATE posts SET deleted = 1 WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $post_id);

        if ($stmt->execute()) {
            // Обновляем время удаления в метке
            $this->updateDeletionMarkTime($post_id);

            // Логируем
            $this->logAdminAction($deleted_by, 'post_delete', 'post', $post_id, $reason);
            $this->logModerationAction($deleted_by, 'post_delete', 'post', $post_id, $reason);

            // Отправляем уведомление автору поста
            if ($post && !empty($post['author_id'])) {
                require_once __DIR__ . '/User.php';
                $userClass = new User($this->conn);
                $userClass->notifyPostDeleted($post['author_id'], $reason);
            }

            return true;
        }
        return false;
    }

    /**
     * Отменить метку на удаление
     */
    public function removeDeletionMark($mark_id, $removed_by)
    {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $mark_id);

        return $stmt->execute();
    }

    /**
     * Получить метки на удаление для поста
     */
    public function getDeletionMark($post_id)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE post_id = :post_id 
                ORDER BY marked_at DESC 
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Получить все активные метки на удаление
     */
    public function getActiveDeletionMarks($limit = 20, $offset = 0)
    {
        $sql = "SELECT dm.*, p.content, p.author_id, u.username 
                FROM {$this->table} dm
                JOIN posts p ON p.id = dm.post_id
                JOIN users u ON u.id = p.author_id
                WHERE dm.deleted_at IS NULL
                ORDER BY dm.marked_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить запланированные удаления
     */
    public function getScheduledDeletions()
    {
        $sql = "SELECT dm.*, p.content, u.username 
                FROM {$this->table} dm
                JOIN posts p ON p.id = dm.post_id
                JOIN users u ON u.id = p.author_id
                WHERE dm.scheduled_delete_at <= NOW() AND dm.deleted_at IS NULL
                ORDER BY dm.scheduled_delete_at ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Выполнить запланированное удаление
     */
    public function executeScheduledDeletion($mark_id, $deleted_by)
    {
        // Получаем post_id из метки
        $sql = "SELECT post_id FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $mark_id);
        $stmt->execute();
        $mark = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mark) {
            // Удаляем пост
            $this->deletePost($mark['post_id'], $deleted_by);

            // Обновляем время удаления
            $updateSql = "UPDATE {$this->table} 
                         SET deleted_at = NOW() 
                         WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bindParam(':id', $mark_id);

            return $updateStmt->execute();
        }

        return false;
    }

    /**
     * Обновить время удаления в метке
     */
    private function updateDeletionMarkTime($post_id)
    {
        $sql = "UPDATE {$this->table} 
                SET deleted_at = NOW() 
                WHERE post_id = :post_id AND deleted_at IS NULL";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);

        return $stmt->execute();
    }

    /**
     * Логировать действие модерации
     */
    private function logModerationAction($moderator_id, $action, $target_type, $target_id, $reason)
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO moderation_log (moderator_id, action, target_type, target_id, reason)
             VALUES (:moderator_id, :action, :target_type, :target_id, :reason)"
        );
        $stmt->bindParam(':moderator_id', $moderator_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':target_type', $target_type);
        $stmt->bindParam(':target_id', $target_id);
        $stmt->bindParam(':reason', $reason);
        return $stmt->execute();
    }

    /**
     * Логировать действие админа
     */
    private function logAdminAction($admin_id, $action_type, $target_type, $target_id, $details = null)
    {
        $sql = "INSERT INTO admin_actions (admin_id, action_type, target_type, target_id, details) 
                VALUES (:admin_id, :action_type, :target_type, :target_id, :details)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->bindParam(':action_type', $action_type);
        $stmt->bindParam(':target_type', $target_type);
        $stmt->bindParam(':target_id', $target_id);
        $stmt->bindParam(':details', $details);

        return $stmt->execute();
    }

    /**
     * Отправить уведомление автору поста
     */
    private function notifyPostAuthor($post_id, $type, $admin_id, $reason)
    {
        $sql = "SELECT author_id FROM posts WHERE id = :post_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($post) {
            // Создаём сообщение уведомления
            $typeName = '';
            switch ($type) {
                case 'post_marked_delete':
                    $typeName = 'Ваш пост отмечен на удаление. Причина: ' . $reason;
                    break;
                case 'post_hidden':
                    $typeName = 'Ваш пост скрыт модератором. Причина: ' . $reason;
                    break;
                default:
                    $typeName = 'Действие с вашим постом: ' . $reason;
            }

            $sql = "INSERT INTO notifications (user_id, type, message, read_status) 
                    VALUES (:user_id, :type, :message, 0)";

            $stmt = $this->conn->prepare($sql);
            $user_id_int = (int)$post['author_id'];
            $stmt->bindParam(':user_id', $user_id_int, PDO::PARAM_INT);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':message', $typeName);
            $stmt->execute();
        }
    }

    /**
     * Получить уведомления пользователя
     */
    public function getUserNotifications($user_id, $limit = 20, $offset = 0)
    {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество непрочитанных уведомлений
     */
    public function getUnreadNotificationCount($user_id)
    {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = :user_id AND read_status = 0";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Отметить уведомление прочитанным
     */
    public function markNotificationAsRead($notification_id)
    {
        $sql = "UPDATE notifications 
                SET read_status = 1 
                WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $notification_id);

        return $stmt->execute();
    }

    /**
     * Отметить все уведомления прочитанными
     */
    public function markAllNotificationsAsRead($user_id)
    {
        $sql = "UPDATE notifications 
                SET read_status = 1 
                WHERE user_id = :user_id AND read_status = 0";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);

        return $stmt->execute();
    }
}
