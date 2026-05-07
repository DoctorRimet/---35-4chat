<?php
/**
 * Инициализация системы модерации
 * Подключайте этот файл в начале скриптов, которые работают с постами
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/AdminManager.php';

// Создаём экземпляры
$PostModeration = new PostModeration($conn);
$ForbiddenWords = new ForbiddenWords($conn);
$AutoModerator = new AutoModerator($conn, $PostModeration, $ForbiddenWords);
$NotificationManager = new NotificationManager($conn);
$AdminManager = new AdminManager($conn);

// Если это POST запрос, выполняем запланированные удаления
if (php_sapi_name() !== 'cli') {
    // Кэшируем запланированные удаления в сессии
    if (!isset($_SESSION['last_deletion_check']) || time() - $_SESSION['last_deletion_check'] > 3600) {
        $scheduledDeletions = $PostModeration->getScheduledDeletions();
        foreach ($scheduledDeletions as $deletion) {
            if (isset($_SESSION['user_id'])) {
                $PostModeration->executeScheduledDeletion($deletion['id'], $_SESSION['user_id']);
            }
        }
        $_SESSION['last_deletion_check'] = time();
    }
}

// Функция для проверки видимости поста
function canViewPost($post, $user_id = null, $user_role = null) {
    // Если пост не скрыт, его может видеть любой
    if (!$post['hidden']) {
        return true;
    }
    
    // Если пост скрыт, его может видеть только автор или админ
    if ($user_id && $post['author_id'] == $user_id) {
        return true;
    }
    
    if ($user_role && ($user_role === 'admin' || $user_role === 'moderator')) {
        return true;
    }
    
    return false;
}

// Функция для получения индикатора скрытия
function getHiddenPostBadge($post) {
    if ($post['hidden']) {
        return '<span class="badge bg-danger" title="' . htmlspecialchars($post['hidden_reason']) . '">' . 
               '<i class="fas fa-eye-slash"></i> Скрыто' . 
               '</span>';
    }
    return '';
}

// Функция для отправки уведомления
function sendNotification($user_id, $type, $message, $title = null) {
    global $NotificationManager;
    return $NotificationManager->create($user_id, $type, $message);
}

// Функция для проверки содержимого
function checkContent($content) {
    global $ForbiddenWords;
    return $ForbiddenWords->checkContentDetailed($content);
}

// Функция для скрытия поста с логированием
function hidePostBySystem($post_id, $author_id, $reason = 'Содержит запрещённые слова') {
    global $PostModeration;
    return $PostModeration->hidePost($post_id, $reason);
}

// Функция для получения уведомлений пользователя
function getUserNotifications($user_id, $limit = 5) {
    global $NotificationManager;
    return $NotificationManager->getUnread($user_id, $limit);
}

// Функция для получения количества непрочитанных уведомлений
function getUnreadNotificationsCount($user_id) {
    global $NotificationManager;
    return $NotificationManager->getUnreadCount($user_id);
}

// Функция для проверки доступа к админ-панели
function isAdminOrModerator($user_id) {
    global $AdminManager;
    return $AdminManager->validateAdminAccess($user_id);
}

// Функция для получения строки для вывода уведомлений
function getNotificationBellHTML($user_id) {
    global $NotificationManager;
    return $NotificationManager->getNotificationBellHtml($user_id);
}
?>
