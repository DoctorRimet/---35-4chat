<?php

class NotificationManager {
    private $conn;
    private $table = 'notifications';

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Создать уведомление
     */
    public function create($user_id, $type, $message) {
        $sql = "INSERT INTO {$this->table} (user_id, type, message, read_status) 
                VALUES (:user_id, :type, :message, 0)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':message', $message);
        
        return $stmt->execute();
    }

    /**
     * Создать уведомление (расширенная версия для совместимости)
     */
    public function createNotification($user_id, $type, $message, $level = 'info') {
        // Параметр $level игнорируется, поскольку в БД нет соответствующего поля
        return $this->create($user_id, $type, $message);
    }

    /**
     * Получить все уведомления пользователя
     */
    public function getAll($user_id, $limit = 20, $offset = 0) {
        $sql = "SELECT n.id, n.user_id, n.type, n.message, n.read_status, n.created_at
                FROM {$this->table} n
                WHERE n.user_id = :user_id
                ORDER BY n.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить непрочитанные уведомления
     */
    public function getUnread($user_id, $limit = 20) {
        $sql = "SELECT n.id, n.user_id, n.type, n.message, n.read_status, n.created_at
                FROM {$this->table} n
                WHERE n.user_id = :user_id AND n.read_status = 0
                ORDER BY n.created_at DESC
                LIMIT :limit";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество непрочитанных
     */
    public function getUnreadCount($user_id) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE user_id = :user_id AND read_status = 0";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Отметить как прочитанное
     */
    public function markAsRead($notification_id) {
        $sql = "UPDATE {$this->table} 
                SET read_status = 1 
                WHERE id = :id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $notification_id);
        
        return $stmt->execute();
    }

    /**
     * Отметить все как прочитанные
     */
    public function markAllAsRead($user_id) {
        $sql = "UPDATE {$this->table} 
                SET read_status = 1
                WHERE user_id = :user_id AND read_status = 0";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }

    /**
     * Удалить уведомление
     */
    public function delete($notification_id) {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $notification_id);
        
        return $stmt->execute();
    }

    /**
     * Удалить все прочитанные уведомления
     */
    public function deleteRead($user_id) {
        $sql = "DELETE FROM {$this->table} 
                WHERE user_id = :user_id AND read_status = 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }

    /**
     * Удалить старые уведомления (старше 30 дней)
     */
    public function cleanupOld($days = 30) {
        $sql = "DELETE FROM {$this->table} 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Получить уведомление по ID
     */
    public function getById($notification_id) {
        $sql = "SELECT n.id, n.user_id, n.type, n.message, n.read_status, n.created_at
                FROM {$this->table} n
                WHERE n.id = :id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $notification_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Получить статистику по типам уведомлений
     */
    public function getStats($user_id) {
        $sql = "SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(read_status = 0) as unread
                FROM {$this->table}
                WHERE user_id = :user_id
                GROUP BY type
                ORDER BY count DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Отправить уведомление о новом ответе
     */
    public function notifyNewReply($post_id, $reply_author_id, $original_author_id) {
        if ($post_id == $reply_author_id) {
            return false; // Не отправляем самому себе
        }

        // Получаем юзернейм автора ответа
        $userSql = "SELECT username FROM users WHERE id = :id";
        $userStmt = $this->conn->prepare($userSql);
        $userStmt->bindParam(':id', $reply_author_id);
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        $username = $user['username'] ?? 'Пользователь';
        $message = $username . ' ответил на ваш пост';

        return $this->create(
            $original_author_id,
            'new_reply',
            $message
        );
    }

    /**
     * Отправить уведомление об упоминании
     */
    public function notifyMention($user_id, $mentioning_user_id, $post_id, $post_title = '') {
        // Получаем юзернейм упоминающего
        $sql = "SELECT username FROM users WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $mentioning_user_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $title = ($user['username'] ?? 'Пользователь') . ' упомянул вас';
        $message = 'Вы упомянуты в посте "' . substr($post_title, 0, 50) . '"';

        return $this->create(
            $user_id,
            'mention',
            $title,
            $message,
            $post_id,
            $mentioning_user_id
        );
    }

    /**
     * Отправить уведомление о лайке
     */
    public function notifyLike($post_author_id, $liker_id, $post_id) {
        // Получаем юзернейм лайкнувшего
        $sql = "SELECT username FROM users WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $liker_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $title = ($user['username'] ?? 'Пользователь') . ' оценил ваш пост';
        $message = 'Вашему посту понравилось!';

        return $this->create(
            $post_author_id,
            'post_liked',
            $title,
            $message,
            $post_id,
            $liker_id
        );
    }

    /**
     * Отправить системное уведомление
     */
    public function notifySystem($user_id, $title, $message) {
        return $this->create(
            $user_id,
            'system',
            $title,
            $message,
            null,
            1 // Системный аккаунт
        );
    }

    /**
     * Получить HTML для иконки с количеством
     */
    public function getNotificationBellHtml($user_id) {
        $unreadCount = $this->getUnreadCount($user_id);
        
        $html = '<a href="notifications.php" class="nav-link position-relative" title="Уведомления">';
        $html .= '<i class="fas fa-bell"></i>';
        
        if ($unreadCount > 0) {
            $html .= '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">';
            $html .= $unreadCount > 99 ? '99+' : $unreadCount;
            $html .= '</span>';
        }
        
        $html .= '</a>';
        
        return $html;
    }

    /**
     * Получить HTML уведомления для списка
     */
    public function getNotificationHtml($notification) {
        $icon = $this->getIconForType($notification['type']);
        $bgClass = $notification['read_status'] ? 'bg-light' : 'bg-info-light';
        $readClass = $notification['read_status'] ? '' : 'fw-bold';
        
        $html = '<div class="notification-item ' . $bgClass . ' p-3 mb-2 rounded border-start border-4">';
        $html .= '<div class="d-flex justify-content-between align-items-start">';
        $html .= '<div class="flex-grow-1">';
        $html .= '<div class="' . $readClass . '">';
        $html .= '<i class="' . $icon . ' me-2"></i>' . $this->getTypeLabel($notification['type']);
        $html .= '</div>';
        $html .= '<small class="text-muted">' . $notification['message'] . '</small>';
        $html .= '<div class="small text-muted mt-1">' . $this->getTimeAgo($notification['created_at']) . '</div>';
        $html .= '</div>';
        
        if (!$notification['read_status']) {
            $html .= '<button class="btn btn-sm btn-link" onclick="markNotificationAsRead(' . $notification['id'] . ')">✓</button>';
        }
        
        $html .= '</div></div>';
        
        return $html;
    }

    /**
     * Получить иконку для типа уведомления
     */
    private function getIconForType($type) {
        $icons = [
            'new_reply' => 'fas fa-reply',
            'mention' => 'fas fa-at',
            'post_liked' => 'fas fa-heart',
            'post_marked_delete' => 'fas fa-exclamation-triangle',
            'post_auto_hidden' => 'fas fa-eye-slash',
            'system' => 'fas fa-info-circle'
        ];
        
        return $icons[$type] ?? 'fas fa-bell';
    }

    /**
     * Получить текстовый ярлык для типа уведомления
     */
    private function getTypeLabel($type) {
        $labels = [
            'new_reply' => 'Новый ответ',
            'mention' => 'Упоминание',
            'post_liked' => 'Пост понравился',
            'post_marked_delete' => 'Пост отмечен на удаление',
            'post_auto_hidden' => 'Пост скрыт',
            'system' => 'Системное уведомление'
        ];
        
        return $labels[$type] ?? ucfirst($type);
    }

    /**
     * Получить время в формате "X времени назад"
     */
    private function getTimeAgo($datetime) {
        $now = new DateTime();
        $date = new DateTime($datetime);
        $interval = $now->diff($date);
        
        if ($interval->d > 0) {
            return $interval->d . ' дн. назад';
        } elseif ($interval->h > 0) {
            return $interval->h . ' ч. назад';
        } elseif ($interval->i > 0) {
            return $interval->i . ' мин. назад';
        } else {
            return 'Только что';
        }
    }
}
?>
